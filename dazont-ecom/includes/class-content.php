<?php
defined( 'ABSPATH' ) || exit;

/**
 * AI Product Content — turns the raw imported product data (title, description,
 * attributes, cost) into every published field with Claude, generates images
 * with fal.ai from prompt templates, and recalculates the price from a
 * cost-multiplier table (the current/import price is treated as COGS).
 *
 * Workflow: product imported from a platform (AliExpress, eBay, Etsy, CJ,
 * Printify…) → its COMPLETE data is the generation context → each text field
 * (each named after the WooCommerce field it writes: Product name, Description,
 * Product short description, Attributes, plus the plugin's own
 * SEO title/description, Custom blocs 1-2) is generated from its stored prompt
 * and written to its MAPPED destination (post field / SEO plugin meta / custom
 * meta / real WooCommerce attributes) → images from templates (scene,
 * additional, remake-main) → price recalculated → ready to publish.
 *
 * Single-product: a small side box opens the toolbox modal (Text/Image/Price).
 * Bulk: a "Generate AI content" bulk action on the products list opens a bulk
 * screen with large thumbnails, per-row image choice and a task runner.
 *
 * A validation gate ("prompts validated") keeps everything preview-only until
 * the prompts have been reviewed and accepted in the settings.
 *
 * Keys: text on the shared Anthropic key (DZE_Marketing_Ai::complete()), images
 * on a fal.ai key (DZE_FAL_API_KEY constant or the General-tab field). Each key
 * is only ever sent to its own provider.
 */
final class DZE_Content {

	public const OPT_SETTINGS = 'dze_content_settings';
	private const NONCE       = 'dze_content';

	private const FAL_ENDPOINT = 'https://fal.run/fal-ai/nano-banana-2/edit';
	/** Same model, nothing to edit: an image made from words alone. */
	private const FAL_CREATE = 'https://fal.run/fal-ai/nano-banana-2';

	/** How many photographs of the product travel with one generation. */
	public const MAX_SOURCES = 6;
	/** Ceiling on the encoded images in one request body, in bytes. */
	private const MAX_PAYLOAD = 9437184; // 9 MB.
	/** Ceiling on one image pulled from the web for the quick lane. */
	private const MAX_REMOTE  = 8388608; // 8 MB.

	public const BULK_SLUG   = 'dazont-content-bulk';
	private const BULK_ACTION = 'dze_ai_content';

	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_init',     [ $this, 'register_settings' ] );
		add_action( 'admin_menu',     [ $this, 'register_bulk_page' ], 20 );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );

		// Bulk action on the products list → bulk screen.
		add_filter( 'bulk_actions-edit-product',        [ $this, 'register_bulk_action' ] );
		add_filter( 'handle_bulk_actions-edit-product', [ $this, 'handle_bulk_action' ], 10, 3 );

		add_action( 'wp_ajax_dze_content_text',  [ $this, 'ajax_text' ] );
		add_action( 'wp_ajax_dze_content_text_all', [ $this, 'ajax_text_all' ] );
		add_action( 'wp_ajax_dze_content_apply', [ $this, 'ajax_apply' ] );
		add_action( 'wp_ajax_dze_content_image', [ $this, 'ajax_image' ] );
		add_action( 'wp_ajax_dze_content_image_attach', [ $this, 'ajax_image_attach' ] );
		add_action( 'wp_ajax_dze_content_save_prompt',  [ $this, 'ajax_save_prompt' ] );
		add_action( 'wp_ajax_dze_content_validate_prompt', [ $this, 'ajax_validate_prompt' ] );
		add_action( 'wp_ajax_dze_content_save_settings', [ $this, 'ajax_save_settings' ] );
		add_action( 'wp_ajax_dze_content_pending_clear', [ $this, 'ajax_pending_clear' ] );
		add_action( 'wp_ajax_dze_content_bulk_list', [ $this, 'ajax_bulk_list' ] );
		add_action( 'wp_ajax_dze_content_quick_main', [ $this, 'ajax_quick_main' ] );
		add_action( 'wp_ajax_dze_content_backdrop', [ $this, 'ajax_backdrop' ] );
		add_action( 'wp_ajax_dze_content_bg_add', [ $this, 'ajax_bg_add' ] );
		add_action( 'wp_ajax_dze_content_prompt_toggle', [ $this, 'ajax_prompt_toggle' ] );
		add_action( 'wp_ajax_dze_content_context', [ $this, 'ajax_context' ] );
		add_action( 'wp_ajax_dze_content_add_default', [ $this, 'ajax_add_default' ] );
		add_action( 'wp_ajax_dze_content_bg_make', [ $this, 'ajax_bg_make' ] );
		add_action( 'wp_ajax_dze_content_bg_strip', [ $this, 'ajax_bg_strip' ] );
		add_action( 'wp_ajax_dze_content_price_preview', [ $this, 'ajax_price_preview' ] );
		add_action( 'wp_ajax_dze_content_current', [ $this, 'ajax_current' ] );
		// The products list: one chip per row opening the toolbox on the spot.
		add_filter( 'manage_edit-product_columns', [ $this, 'list_column' ], 22 );
		add_action( 'manage_product_posts_custom_column', [ $this, 'list_cell' ], 10, 2 );
		add_action( 'wp_ajax_dze_content_reset_prompts', [ $this, 'ajax_reset_prompts' ] );
		add_action( 'wp_ajax_dze_content_price', [ $this, 'ajax_price' ] );
	}

	// =========================================================================
	// Field / template / price-table definitions
	// =========================================================================

	/**
	 * Text fields. The prompts for description/short/seo/blocs/attributes are the
	 * shop owner's own proven scripts, KEPT VERBATIM from the validated
	 * spreadsheet (GPT Instructions sheet) — including wording quirks. Only
	 * minimal framing lines, marked [Dazont], are added where the sheet relied on
	 * spreadsheet context. 'enabled' is the default activation state (title and
	 * SEO title ship disabled — they are handled manually for now).
	 *
	 * @return array<string,array{label:string,dest:string,tokens:int,enabled:bool,prompt:string}>
	 */
	private static function legacy_fields(): array {
		$p_description = <<<'EOT'
[Dazont] Rédige d'abord la description (instructions A), puis, à la suite, la liste technique (instructions B). Sortie finale : le <h2>, le paragraphe, puis la liste <ul> — rien d'autre.

=== INSTRUCTIONS A — Description ===
Interlocuteur : visiteur de la fiche produit
Objectif : Rédiger une description produit

Format : 
- Tu intégrera les balises H dans ton texte pour faciliter l'import sur mon shop, depuis un csv
- Exemple : 
<h2>sous-titre</h2>
description en dessous

Un bon sous titre h2 :
- Met en avant la caractéristique principale du produit
- S'appuye sur le titre produit optimisé pour être pertinant
- Ne pas utiliser l'expression "ultimate versatility" partout. Soit créatif.
Quelques examples à partir desquels t'inspirer: 
- 6b51 knee pads : <h2>Ratnik design knee pads perfect for an airsoft kit</h2>
- 50L molle tactical backpack: <h2>Sufficient capacity for more than 48 hours in the field</h2>
- Y strap military backpack 40L: <h2>Easy carrying of bulky items with the Y strap system</h2>

La contenu :
- Langue : anglais
- Ton : informatif et expert
- Adapter la description en fonction du produit, et des informations fournies. Cela signifie, adapter son style en fonction du client type (au final). Quand utiliser, la saison, pour quelle activité, la météo, sur quel terrain, la gamme de couleur, etc ?...
- Ne pas : citer les tailles produit, citer le fournisseur si il n'est pas connu et n'apporte pas d'autorité à notre site web (nike, adidas ok pour citation... Par exemple, art of rajasthan n'est pas connu donc ne pas le citer)
- Ecrire comme un humain: intégrer des biais cognitifs et émotions, ne jamais mettre de footprint IA de type pattern répétitif
- Longueur de description environ 50 mots
- N'inclus rien d'autre que la description dans ta réponse.

=== INSTRUCTIONS B — Liste technique ===
Interlocuteur : visiteur de fiche produit
Objectif : Rédiger une liste des caractéristiques du produit
Le contenu :
- Langue : anglais
- Ton : informatif
- Abordes les caractéristiques principales du produit. Mets en avant ses caractéristiques et spécificités, en restant très succins. Pas besoin de formuler des phrases entière, juste une caractéristique technique ou un bénéfice produit
- Ne pas : parler des tailles produits (souvent plusieurs sont disponibles), ne pas citer la marque, ne pas citer aucune information concernant le dropshipping ou wholesale (destinées aux revendeurs), citer une provenance de chine, citer des informations "délirantes" typiques d'aliexpress (noms de couleur erronés, tailles fausses, caractéristiques fakes ou incensées...)
- Mettre l'accent sur les spécificités du produits, c'est grâce à ça qu'il se différencie des autres.
- Si possible, intégrer des données chiffrées (par exemple, matériaux) pour renforcer la qualité de la description. En fonction des infos fournisseur et/ou des pratiques du marchés,
Format : 
- Tu intégrera les balises html dans ton texte pour faciliter l'import sur mon shop, depuis un csv. Ta réponse ne doit pas être au format html, il faut juste intégrer les balises i, ul.. Nécessairent pour une liste à puces.
- N'inclus rien d'autre qu'une liste à puce dans ta réponse. Pas même le nom du produit.
- Pas d'italique
- Tu peux mettre en gras avec la balise <strong> les éléments les plus importants. Sans en abuser.
- Pas de numérotation
- Ne jamais mettre de footprint IA de type pattern répétitif
- Tu es libre de modifier, d'omettre, et de créer des nouvelles caractéristiques non utilisées par le fournisseurs (qualité des descriptions fournisseurs pas toujours bonne), dans le but d'améliorer la qualité de la description
Exemple
<ul>
         <li>point x</li>
         <li>point y</li>
</ul>
EOT;

		$p_short = <<<'EOT'
Objectif : Rédiger une description courte pour fiche produit, d'environ 20 mots.
Interlocuteur : lecteur de la page produit.
Langue : anglais
Conditions concernant les descriptions :
- Te seront fournis le maximum d'information pour une meilleure compréhension du sujet.
- Echelle d'originalité sur 1 à 10 : 8
- N'inclus rien d'autre que la description dans ta réponse.
- Mets l'accent sur la différence du produit parmis les autres dans sa catégorie.
- Les formulations marketing stupides ne nous intéressent pas: parles le language du client et présentes les particularités du produit en donnant des exemples d'utilisation ou autre formulaison attraillantes, qui parlent aux prospects de cette niche.
EOT;

		$p_seo_desc = <<<'EOT'
Objectif : Rédiger une meta description pour la serp google de 155 caractères maximum.
Interlocuteur : personne ayant fait une recherche google
Langue : anglais
Conditions concernant les descriptions :
- Te seront fournis le maximum d'information pour une meilleure compréhension du sujet.
- Echelle d'originalité sur 1 à 10 : 7
- N'inclus rien d'autre que la description dans ta réponse.
- Utilises une construction de phrase originale et captivante pour éviter les doublons (ce script est utilisé sur d'autres produits individuellement).
- Tu peux utiliser des formules prouvées fonctionelles pour t'efforcer d'augmenter le CTR.
EOT;

		$p_branding = <<<'EOT'
Interlocuteur : visiteur de fiche produit
Objectif : Rédiger une description produit supplémentaire.
Le contenu :
- Langue : anglais
- Ton : informatif
La description :
- Environ 30-40 mots
- Ne pas répéter le titre du produit. Epargnes nous ton spam.
- Inclus dans la description le titre h2 fournis
- Ton texte doit développer à propos du titre h2 fournis
Format : 
- Tu intégrera les balises H dans ton texte pour faciliter l'import sur mon shop, depuis un csv
- Exemple : 
<h2>sous-titre</h2>
description que tu rédiges
- N'inclus rien d'autre que la description dans ta réponse.
- Ne répètes pas le contenu précédement rédigé.

[Dazont] Si aucun titre h2 n'est fourni, invente un sous-titre h2 pertinent pour ce produit.
EOT;

		$p_attributes = <<<'EOT'
[Dazont] Sortie : une ligne par attribut au format Nom: valeur|valeur (ex. Color: Black|Tan). Applique les trois scripts ci-dessous.

=== Attributs ===
Objectif : extraire un/des attributs produits de la description du fournisseur pour un produit produit woocommerce clean.
Spécifications:
- N'inclus rien d'autre que la description dans ta réponse.
- Nettoyer les attributs fournisseurs si nécessaire: ils doivent correspondre avec les normes de la niche sur laquelle tu travailles.
- Séparer les attributs avec le symbole "|" sans espaces.
- Si tu n'est pas capable de récupérer l'attribut demandé, renvoies une réponse vide.
- Les attributs doivent avoir leur première lettre capitale, pas les autres.
- Exemple: cotton|jute|metal|etc...
Concernant les attributs:
- L'origine China doit être "PRC"
- Le sexe doit être male|female
- L'attribut "Specifications" concerne certaines normes ou standards de produits particuliers.

=== Couleurs ===
Objectif : Extraire l'attribut couleur du produit.
Langue: Anglais

Spécifications :

- N'inclure que le résultat dans la réponse.
- Nettoyer l'attribut si nécessaire pour qu'il corresponde aux normes de la niche.
- Tu dois renvoyer uniquement les couleurs utilisés dans le produit au format (exemple) :  Beige|Gray|Black
- Séparer les couleurs par le symbole "|" sans espaces.
- Si aucune couleur n'est spécifié, utiliser les couleurs standards les plus répandus dans cette niche.
- Si tu manque trop d'information sur le produit et sa niche, laisser renvoyer une réponse vide.
- Les attributs doivent avoir leur première lettre capitale, pas les autres.

=== Matières ===
Objectif : Extraire l'attribut matière du produit
Langue: Anglais
Spécifications :

- N'inclure que le résultat dans la réponse.
- Nettoyer l'attribut si nécessaire pour qu'il corresponde aux normes de la niche.
- Tu dois renvoyer uniquement les matériaux utilisés dans le produit au format (exemple) :  Cotton|Jute|Metal
- Séparer les matériaux par le symbole "|" sans espaces.
- Si aucun matériau n'est spécifié, utiliser les matériaux les plus répandus dans cette niche.
- Si tu manque trop d'information sur le produit et sa niche, laisser renvoyer une réponse vide.
- Les attributs doivent avoir leur première lettre capitale, pas les autres.
EOT;

		return [
			'title' => [
				'label'   => self::native_label( 'post_title' ),
				'dest'    => 'post_title',
				'tokens'  => 80,
				'enabled' => false, // titles are crafted manually for now.
				'prompt'  => "Write an SEO-optimised product title (max ~70 characters). Natural, human, no ALL CAPS, no supplier gibberish. Output only the title.",
			],
			'description' => [
				'label'   => self::native_label( 'post_content' ),
				'dest'    => 'post_content',
				'tokens'  => 900,
				'enabled' => true,
				'prompt'  => $p_description,
			],
			'short' => [
				'label'   => self::native_label( 'post_excerpt' ),
				'dest'    => 'post_excerpt',
				'tokens'  => 200,
				'enabled' => true,
				'prompt'  => $p_short,
			],
			'attributes' => [
				'label'   => self::native_label( 'attributes' ),
				'dest'    => 'attributes',
				'tokens'  => 300,
				'enabled' => true,
				'prompt'  => $p_attributes,
			],
			'seo_title' => [
				'label'   => __( 'SEO title', 'dazont-ecom' ),
				'dest'    => 'seo_title',
				'tokens'  => 60,
				'enabled' => false, // handled manually for now.
				'prompt'  => "Write an SEO meta title (max ~60 characters), compelling for Google SERP, English. Output only the title.",
			],
			'seo_description' => [
				'label'   => __( 'SEO description', 'dazont-ecom' ),
				'dest'    => 'seo_desc',
				'tokens'  => 160,
				'enabled' => true,
				'prompt'  => $p_seo_desc,
			],
			'bloc1' => [
				'label'   => __( 'Custom bloc text 1', 'dazont-ecom' ),
				'dest'    => 'meta',
				'tokens'  => 250,
				'enabled' => true,
				'prompt'  => $p_branding,
			],
			'bloc2' => [
				'label'   => __( 'Custom bloc text 2', 'dazont-ecom' ),
				'dest'    => 'meta',
				'tokens'  => 250,
				'enabled' => true,
				'prompt'  => $p_branding . "\n\n[Dazont] Ceci est le SECOND bloc branding : angle différent du premier (réassurance, usage, qualité) et sous-titre h2 différent.",
			],
		];
	}

	public static function default_image_templates(): array {
		return [
			[ 'name' => 'Scene (in use)', 'target' => 'gallery', 'prompt' => "Create a UGC-style photoshoot of this product in its favourite context of use. No text on the image. Careful with the exact product type (not everything is worn in the field). Realistic, with human imperfections." ],
			// A supplier photograph is rarely a shop photograph: it carries the
			// supplier's text, it is the wrong shape, and it is small. This is
			// the pass that makes it usable without changing the product.
			[
				'name'   => 'Remake a supplier photo',
				'target' => 'gallery',
				'prompt' => "Remake this photograph as a clean e-commerce image of the SAME product, changing nothing about the product itself.\n"
					. "- Remove every piece of text, watermark, logo, sticker, price tag and badge that is not physically printed on the product. A shop translated into several languages cannot carry words baked into its images.\n"
					. "- Square 1:1 framing, product centred, filling about 85% of the frame. Extend the background rather than cropping the product: nothing may be cut off.\n"
					. "- Restore what a small or soft source loses: sharp edges, readable material, clean stitching, no compression artefacts, no blur, no noise.\n"
					. "- Same shape, same colours, same materials, same proportions, same hardware. Invent no detail that is not visible in the source.",
			],
			// Variants split by colour often inherit ONE photograph while their
			// siblings have five. This is what fills that gap.
			[
				'name'   => 'Another angle of the same product',
				'target' => 'gallery',
				'prompt' => "Photograph the SAME product again from a different, useful angle — three-quarter view, back, or a close-up of the material and the stitching — as a clean e-commerce shot.\n"
					. "- It must read as another photograph from the same shoot: same product, same colours, same materials, same lighting, same background.\n"
					. "- Show something the source photographs do not already show. Never repeat an angle that already exists.\n"
					. "- No text, no props, no people unless the product is worn in the source.\n"
					. "- Invent nothing: if a side of the product is not visible in any source photograph, choose an angle that does not reveal it.",
			],
			[ 'name' => 'Remake main (studio)', 'target' => 'main', 'prompt' => "Recreate a clean, well-lit studio main image of this exact product on a neutral background, sharp, e-commerce ready. No text, no props. Keep the product faithful." ],
		];
	}

	/** Default cost → regular-price multiplier table (from the shop's pricing sheet). */
	public static function default_price_table(): array {
		return [
			[ 'min' => 0,   'max' => 5,   'mult' => 4 ],
			[ 'min' => 5,   'max' => 15,  'mult' => 3 ],
			[ 'min' => 15,  'max' => 50,  'mult' => 2.7 ],
			[ 'min' => 50,  'max' => 200, 'mult' => 2.5 ],
			[ 'min' => 200, 'max' => 500, 'mult' => 2.2 ],
			[ 'min' => 500, 'max' => 0,   'mult' => 2 ], // max 0 = no upper bound.
		];
	}

	// =========================================================================
	// Universal prompt registry — ACF-style: ONE standardized list of prompts,
	// each with a content type (text / image), a prompt, selectable product
	// metadata INPUTS (fed to the prompt) and an OUTPUT destination. Rows are
	// freely added/removed in Settings → Product content. Legacy per-field
	// settings are migrated into the registry once, then it is the single source.
	// =========================================================================

	/**
	 * Distinct meta keys existing on products — powers the searchable
	 * suggestions (datalist + picker) so nobody types a key blind. Cached 1h.
	 *
	 * @return string[]
	 */
	public static function product_meta_keys(): array {
		$cached = get_transient( 'dze_product_meta_keys' );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		global $wpdb;
		$keys = $wpdb->get_col(
			"SELECT DISTINCT pm.meta_key FROM {$wpdb->postmeta} pm
			 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			 WHERE p.post_type = 'product' AND pm.meta_key NOT LIKE '\\_oembed%'
			 ORDER BY pm.meta_key LIMIT 400"
		);
		$keys = array_values( array_map( 'strval', (array) $keys ) );
		set_transient( 'dze_product_meta_keys', $keys, HOUR_IN_SECONDS );
		return $keys;
	}

	/** Product metadata selectable as prompt inputs (WP All Import style). */
	public static function input_options(): array {
		return [
			'title'             => __( 'Product title', 'dazont-ecom' ),
			'description'       => __( 'Description', 'dazont-ecom' ),
			'short_description' => __( 'Short description', 'dazont-ecom' ),
			'attributes'        => __( 'Attributes', 'dazont-ecom' ),
			'price'             => __( 'Regular price', 'dazont-ecom' ),
			'cogs'              => __( 'Cost (COGS)', 'dazont-ecom' ),
			'categories'        => __( 'Categories', 'dazont-ecom' ),
			'tags'              => __( 'Tags', 'dazont-ecom' ),
			'sku'               => __( 'SKU', 'dazont-ecom' ),
		];
	}

	/** Output destinations per content type. */
	public static function output_options( string $type = 'text' ): array {
		if ( 'image' === $type ) {
			return [
				'gallery' => __( 'Product gallery (image)', 'dazont-ecom' ),
				'main'    => __( 'Main image', 'dazont-ecom' ),
			];
		}
		return [
			'post_title'   => __( 'Product title', 'dazont-ecom' ),
			'post_content' => __( 'Product description', 'dazont-ecom' ),
			'post_excerpt' => __( 'Short description', 'dazont-ecom' ),
			'seo_title'    => __( 'SEO title (SEO plugin meta)', 'dazont-ecom' ),
			'seo_desc'     => __( 'SEO description (SEO plugin meta)', 'dazont-ecom' ),
			'attributes'   => __( 'WooCommerce attributes', 'dazont-ecom' ),
			'meta'         => __( 'Custom field (meta key)', 'dazont-ecom' ),
		];
	}

	/**
	 * In-memory registry cache. NEVER auto-persist from here: update_option on our
	 * own registered option re-enters sanitize() (sanitize_option_* filter), which
	 * reads the registry again — infinite recursion, fatal error. The registry is
	 * only persisted by real saves (settings form / ajax_save_prompt).
	 *
	 * @var array[]|null
	 */
	private static ?array $registry_cache = null;

	/** The full prompt registry; falls back to a legacy-derived set in memory. */
	public static function registry(): array {
		if ( null !== self::$registry_cache ) {
			return self::$registry_cache;
		}
		$s = self::get_settings();
		if ( ! empty( $s['registry'] ) && is_array( $s['registry'] ) ) {
			self::$registry_cache = self::rename_to_native( $s['registry'] );
		} else {
			self::$registry_cache = self::registry_from_legacy();
		}
		return self::$registry_cache;
	}

	/**
	 * A field that writes a WooCommerce field carries WooCommerce's name for it.
	 *
	 * Inventing a label ("Description (+ technical bullets)") makes the reader
	 * work out which box of the product page it lands in. The name comes from
	 * WooCommerce's own translations, so it reads exactly as the product screen
	 * does, in whatever language that screen is in.
	 */
	public static function native_label( string $dest ): string {
		$map = [
			'post_title'   => 'Product name',
			'post_content' => 'Description',
			'post_excerpt' => 'Product short description',
			'attributes'   => 'Attributes',
		];
		if ( ! isset( $map[ $dest ] ) ) {
			return '';
		}
		// translate() and not __(): the string belongs to WooCommerce, we only
		// ask for its wording. phpcs:ignore WordPress.WP.I18n.LowLevelTranslationFunction, WordPress.WP.I18n.NonSingularStringLiteralText, WordPress.WP.I18n.TextDomainMismatch
		return class_exists( 'WooCommerce' ) ? translate( $map[ $dest ], 'woocommerce' ) : $map[ $dest ];
	}

	/**
	 * Rows saved before that rule still carry the old invented label. The name
	 * is only replaced when it is one WE shipped — a label the owner typed is
	 * left exactly as typed.
	 */
	private static function rename_to_native( array $rows ): array {
		$ours = [
			'Description (+ technical bullets)' => 'post_content',
			'Description'                       => 'post_content',
			'Short description'                 => 'post_excerpt',
			'Product short description'         => 'post_excerpt',
			'Title'                             => 'post_title',
			'Product name'                      => 'post_title',
			'Attributes'                        => 'attributes',
		];
		foreach ( $rows as $i => $r ) {
			$name = (string) ( $r['name'] ?? '' );
			$dest = (string) ( $r['output'] ?? '' );
			if ( ! isset( $ours[ $name ] ) || $ours[ $name ] !== $dest ) {
				continue; // not one of ours, or moved elsewhere: leave it alone.
			}
			$native = self::native_label( $dest );
			if ( '' !== $native ) {
				$rows[ $i ]['name'] = $native;
			}
		}
		return $rows;
	}

	/** One-time migration: legacy fields + overrides + image templates → registry rows. */
	private static function registry_from_legacy(): array {
		$s    = self::get_settings();
		$rows = [];
		foreach ( self::legacy_fields() as $fid => $f ) {
			$dest = (string) ( $s[ 'dest_' . $fid ] ?? 'default' );
			if ( 'default' === $dest || '' === $dest || ! array_key_exists( $dest, self::output_options( 'text' ) ) ) {
				$dest = $f['dest'];
			}
			$metakey = (string) ( $s[ 'metakey_' . $fid ] ?? ( $s[ 'map_' . $fid ] ?? '' ) );
			$rows[]  = [
				'id'          => $fid,
				'name'        => $f['label'],
				'type'        => 'text',
				'prompt'      => ! empty( $s[ 'prompt_' . $fid ] ) ? (string) $s[ 'prompt_' . $fid ] : $f['prompt'],
				'inputs'      => [ 'title', 'description', 'attributes', 'price' ],
				'inputs_meta' => '',
				'output'      => $dest,
				'meta_key'    => '' !== $metakey ? $metakey : '_dze_' . $fid,
				'enabled'     => isset( $s['fe'][ $fid ] ) ? (int) ! empty( $s['fe'][ $fid ] ) : (int) ! empty( $f['enabled'] ),
				'valid'       => (int) ! empty( $s['fv'][ $fid ] ),
				'tokens'      => (int) $f['tokens'],
			];
		}
		$tpls = ( ! empty( $s['image_templates'] ) && is_array( $s['image_templates'] ) ) ? $s['image_templates'] : self::default_image_templates();
		$n    = 1;
		foreach ( $tpls as $t ) {
			$id = sanitize_key( str_replace( ' ', '_', (string) ( $t['name'] ?? '' ) ) ) ?: 'image_' . $n;
			$rows[] = [
				'id'          => 'img_' . $id,
				'name'        => (string) ( $t['name'] ?? 'Image ' . $n ),
				'type'        => 'image',
				'prompt'      => (string) ( $t['prompt'] ?? '' ),
				'inputs'      => [ 'title', 'description' ],
				'inputs_meta' => '',
				'output'      => ( ( $t['target'] ?? 'gallery' ) === 'main' ) ? 'main' : 'gallery',
				'meta_key'    => '',
				'enabled'     => 1,
				'valid'       => (int) ! empty( $t['valid'] ),
				'tokens'      => 0,
			];
			$n++;
		}
		return $rows;
	}

	/**
	 * The SHIPPED default prompt for a registry row id, or '' when the row is a
	 * custom one (nothing to restore to). Text rows come from the verbatim
	 * spreadsheet prompts, image rows from the default templates.
	 */
	public static function default_prompt_for( string $id ): string {
		foreach ( self::legacy_fields() as $fid => $f ) {
			if ( $fid === $id ) {
				return (string) $f['prompt'];
			}
		}
		$n = 1;
		foreach ( self::default_image_templates() as $t ) {
			$tid = 'img_' . ( sanitize_key( str_replace( ' ', '_', (string) ( $t['name'] ?? '' ) ) ) ?: 'image_' . $n );
			if ( $tid === $id ) {
				return (string) ( $t['prompt'] ?? '' );
			}
			$n++;
		}
		return '';
	}

	/** id => shipped default prompt, for every row that has one. */
	public static function default_prompts(): array {
		$out = [];
		foreach ( self::registry() as $r ) {
			$d = self::default_prompt_for( (string) ( $r['id'] ?? '' ) );
			if ( '' !== $d ) {
				$out[ (string) $r['id'] ] = $d;
			}
		}
		return $out;
	}

	/**
	 * Shipped prompts this install does not have.
	 *
	 * The registry is saved the first time the settings are saved, so prompts
	 * added to the plugin afterwards would never appear. They are offered here
	 * rather than pushed back in: one deleted on purpose must stay deleted.
	 *
	 * @return array<string,string> id => name.
	 */
	public static function missing_defaults(): array {
		$have = [];
		foreach ( self::registry() as $r ) {
			$have[ (string) ( $r['id'] ?? '' ) ] = 1;
		}
		$out = [];
		foreach ( self::legacy_fields() as $fid => $f ) {
			if ( ! isset( $have[ $fid ] ) ) {
				$out[ $fid ] = (string) $f['label'];
			}
		}
		$n = 1;
		foreach ( self::default_image_templates() as $t ) {
			$id = 'img_' . ( sanitize_key( str_replace( ' ', '_', (string) ( $t['name'] ?? '' ) ) ) ?: 'image_' . $n );
			if ( ! isset( $have[ $id ] ) ) {
				$out[ $id ] = (string) $t['name'];
			}
			$n++;
		}
		return $out;
	}

	/** Puts one shipped prompt back into the registry, switched off. */
	public function ajax_add_default(): void {
		$this->guard();
		$id = isset( $_POST['id'] ) ? sanitize_key( wp_unslash( $_POST['id'] ) ) : '';
		if ( ! isset( self::missing_defaults()[ $id ] ) ) {
			wp_send_json_error( [ 'message' => __( 'Unknown prompt.', 'dazont-ecom' ) ] );
		}
		$row = null;
		foreach ( self::legacy_fields() as $fid => $f ) {
			if ( $fid === $id ) {
				$row = [
					'id'          => $fid,
					'name'        => (string) $f['label'],
					'type'        => 'text',
					'prompt'      => (string) $f['prompt'],
					'inputs'      => [ 'title', 'description', 'attributes', 'price' ],
					'inputs_meta' => '',
					'output'      => (string) $f['dest'],
					'meta_key'    => '_dze_' . $fid,
					'enabled'     => 0,
					'valid'       => 0,
					'tokens'      => (int) $f['tokens'],
				];
			}
		}
		if ( ! $row ) {
			$n = 1;
			foreach ( self::default_image_templates() as $t ) {
				$tid = 'img_' . ( sanitize_key( str_replace( ' ', '_', (string) ( $t['name'] ?? '' ) ) ) ?: 'image_' . $n );
				if ( $tid === $id ) {
					$row = [
						'id'          => $tid,
						'name'        => (string) $t['name'],
						'type'        => 'image',
						'prompt'      => (string) $t['prompt'],
						'inputs'      => [ 'title', 'description' ],
						'inputs_meta' => '',
						'output'      => ( ( $t['target'] ?? 'gallery' ) === 'main' ) ? 'main' : 'gallery',
						'meta_key'    => '',
						'enabled'     => 1,
						'valid'       => 0,
						'tokens'      => 0,
					];
				}
				$n++;
			}
		}
		if ( ! $row ) {
			wp_send_json_error( [ 'message' => __( 'Unknown prompt.', 'dazont-ecom' ) ] );
		}
		$rows   = self::registry();
		$rows[] = $row;
		try {
			self::write_setting( 'registry', $rows );
		} catch ( \Throwable $e ) {
			wp_send_json_error( [ 'message' => $e->getMessage() ] );
		}
		wp_send_json_success( [ 'id' => $id ] );
	}

	/** Writes one registry row's prompt. Used by the shared prompt editor. */
	public static function set_prompt_for( string $id, string $text ): bool {
		$rows  = self::registry();
		$found = false;
		foreach ( $rows as $k => $r ) {
			if ( (string) ( $r['id'] ?? '' ) === $id ) {
				$rows[ $k ]['prompt'] = $text;
				$found = true;
				break;
			}
		}
		if ( ! $found ) {
			return false;
		}
		self::write_setting( 'registry', $rows );
		return true;
	}

	/** Registry row by id (text or image), or null. */
	private static function registry_row( string $id ): ?array {
		foreach ( self::registry() as $r ) {
			if ( ( $r['id'] ?? '' ) === $id ) {
				return $r;
			}
		}
		return null;
	}

	/** TEXT registry rows in the legacy shape the rest of the module consumes. */
	public static function fields(): array {
		$out = [];
		foreach ( self::registry() as $r ) {
			if ( ( $r['type'] ?? 'text' ) !== 'text' ) {
				continue;
			}
			$out[ (string) $r['id'] ] = [
				'label'   => (string) ( $r['name'] ?? $r['id'] ),
				'dest'    => (string) ( $r['output'] ?? 'meta' ),
				'img_meta'=> (string) ( $r['img_meta'] ?? '' ),
				'tokens'  => (int) ( $r['tokens'] ?: 400 ),
				'enabled' => ! empty( $r['enabled'] ),
				'prompt'  => (string) ( $r['prompt'] ?? '' ),
			];
		}
		return $out;
	}

	/** Assembles the product data block from a row's selected inputs. */
	private static function payload_lines( int $pid, array $inputs, string $inputs_meta = '' ): string {
		$product = $pid ? wc_get_product( $pid ) : null;
		if ( ! $product instanceof WC_Product ) {
			return '';
		}
		$L = [];
		foreach ( $inputs as $k ) {
			switch ( $k ) {
				case 'title':
					$L[] = 'Title: ' . $product->get_name();
					break;
				case 'description':
					$d = mb_substr( wp_strip_all_tags( (string) get_post_field( 'post_content', $pid ) ), 0, 2500 );
					if ( $d ) { $L[] = 'Description: ' . $d; }
					break;
				case 'short_description':
					$d = wp_strip_all_tags( (string) get_post_field( 'post_excerpt', $pid ) );
					if ( $d ) { $L[] = 'Short description: ' . $d; }
					break;
				case 'attributes':
					$a = self::attributes_summary( $product );
					if ( $a ) { $L[] = "Attributes:\n" . $a; }
					break;
				case 'price':
					$p = (string) $product->get_regular_price();
					if ( '' !== $p ) { $L[] = 'Price: ' . $p; }
					break;
				case 'cogs':
					$c = (string) get_post_meta( $pid, '_dze_cogs', true );
					if ( '' === $c ) { $c = (string) get_post_meta( $pid, '_cogs_value', true ); }
					if ( '' !== $c ) { $L[] = 'Cost (COGS): ' . $c; }
					break;
				case 'categories':
					$t = wp_get_post_terms( $pid, 'product_cat', [ 'fields' => 'names' ] );
					if ( ! is_wp_error( $t ) && $t ) { $L[] = 'Categories: ' . implode( ', ', $t ); }
					break;
				case 'tags':
					$t = wp_get_post_terms( $pid, 'product_tag', [ 'fields' => 'names' ] );
					if ( ! is_wp_error( $t ) && $t ) { $L[] = 'Tags: ' . implode( ', ', $t ); }
					break;
				case 'sku':
					$sk = (string) $product->get_sku();
					if ( '' !== $sk ) { $L[] = 'SKU: ' . $sk; }
					break;
			}
		}
		foreach ( array_filter( array_map( 'trim', explode( ',', $inputs_meta ) ) ) as $mk ) {
			$v = get_post_meta( $pid, sanitize_key( $mk ), true );
			if ( is_scalar( $v ) && '' !== (string) $v ) {
				$L[] = $mk . ': ' . mb_substr( (string) $v, 0, 500 );
			}
		}
		return implode( "\n", $L );
	}

	/** Selectable destinations for the field mapping. */
	public static function dest_options(): array {
		return [
			'default'      => __( 'Default destination', 'dazont-ecom' ),
			'post_title'   => __( 'Product title', 'dazont-ecom' ),
			'post_content' => __( 'Product description', 'dazont-ecom' ),
			'post_excerpt' => __( 'Short description', 'dazont-ecom' ),
			'seo_title'    => __( 'SEO title (SEO plugin meta)', 'dazont-ecom' ),
			'seo_desc'     => __( 'SEO description (SEO plugin meta)', 'dazont-ecom' ),
			'attributes'   => __( 'WooCommerce attributes', 'dazont-ecom' ),
			'meta'         => __( 'Custom field (meta key)', 'dazont-ecom' ),
		];
	}

	// =========================================================================
	// Settings storage + helpers
	// =========================================================================

	public static function get_settings(): array {
		$s = get_option( self::OPT_SETTINGS, [] );
		return is_array( $s ) ? $s : [];
	}

	public static function fal_key(): string {
		if ( defined( 'DZE_FAL_API_KEY' ) && DZE_FAL_API_KEY ) {
			return (string) DZE_FAL_API_KEY;
		}
		return (string) ( self::get_settings()['fal_key'] ?? '' );
	}

	/**
	 * What stands between the owner and a working image generation, right now.
	 *
	 * These are the conditions that make EVERY generation fail, whatever the
	 * product: no key, budget reached, no image prompt. They used to be found
	 * out the hard way — you launched a run, waited, and read "0 ok, 1 errors".
	 * Screens ask for this list first and say it plainly instead.
	 *
	 * @return array<int,array{text:string,url:string,label:string}>
	 */
	public static function image_blockers(): array {
		$out = [];
		if ( '' === self::fal_key() ) {
			$out[] = [
				'text'  => __( 'No fal.ai key is saved — no image can be generated.', 'dazont-ecom' ),
				'url'   => add_query_arg( [ 'page' => DZE_Marketing_Ai::MENU_SLUG, 'tab' => 'general' ], admin_url( 'admin.php' ) ),
				'label' => __( 'Add the key', 'dazont-ecom' ),
			];
		}
		if ( class_exists( 'DZE_Ai_Usage' ) && DZE_Ai_Usage::over_budget() ) {
			$out[] = [
				'text'  => DZE_Ai_Usage::budget_message(),
				'url'   => add_query_arg( [ 'page' => DZE_Marketing_Ai::MENU_SLUG, 'tab' => 'general' ], admin_url( 'admin.php' ) ),
				'label' => __( 'See the usage', 'dazont-ecom' ),
			];
		}
		if ( ! self::image_templates() ) {
			$out[] = [
				'text'  => __( 'No image prompt is enabled in the registry.', 'dazont-ecom' ),
				'url'   => add_query_arg( [ 'page' => DZE_Marketing_Ai::MENU_SLUG, 'tab' => 'content' ], admin_url( 'admin.php' ) ),
				'label' => __( 'Open the registry', 'dazont-ecom' ),
			];
		}
		return $out;
	}

	/**
	 * fal.ai price per generated image (USD) — used only for the usage graph
	 * and the monthly budget guard, not for billing. Adjustable on the General
	 * tab next to the key; defaults to $0.04/image.
	 */
	public static function fal_image_cost(): float {
		$c = (float) ( self::get_settings()['fal_image_cost'] ?? 0 );
		return $c > 0 ? $c : 0.04;
	}

	public static function store_context(): string {
		return (string) ( self::get_settings()['store_context'] ?? '' );
	}

	/** Shipped rules for choosing which photograph illustrates a text block. */
	public static function default_feature_prompt(): string {
		return 'Pick the photographs that best show a CONCRETE particularity worth zooming in on in a sales argument — a material, a fastening, a finish, a compartment, a texture, a detail of construction, the product in use. '
			. 'Avoid the plain catalogue shot when a more telling one exists, and never pick two photographs showing the same thing.';
	}

	/**
	 * The shipped recipe for a catalogue main image.
	 *
	 * A supplier photograph on a wooden table, with flowers and a magazine next
	 * to it, is a mood shot: it does not belong at the top of a product page,
	 * where every listing has to look like the one above it. This turns any
	 * photograph into the same straight-on shot on the same grey.
	 */
	public static function default_quick_prompt(): string {
		return 'Turn this product into a clean e-commerce MAIN image. '
			. 'Show the product straight-on, centred and upright, filling about 85% of a square frame, '
			. 'on a seamless very light grey studio background (around #f2f2f2), '
			. 'with a soft contact shadow under it and even, diffuse lighting. '
			. 'No props, no text, no logo, no hands, no people, no background objects. '
			. 'Keep the product EXACTLY as it is: same shape, same materials, same colours, same stitching, same hardware, same proportions. '
			. 'Invent nothing that is not visible in the source photograph.';
	}

	/** The recipe actually sent: the owner's, or the shipped one. */
	public static function quick_prompt(): string {
		$p = trim( (string) ( self::get_settings()['quick_prompt'] ?? '' ) );
		return '' !== $p ? $p : self::default_quick_prompt();
	}

	/** The rules actually sent: the owner's, or the shipped ones. */
	public static function feature_prompt(): string {
		$p = trim( (string) ( self::get_settings()['feature_prompt'] ?? '' ) );
		return '' !== $p ? $p : self::default_feature_prompt();
	}

	/** Legacy global flag: true only when EVERY text-field prompt is validated. */
	public static function prompts_validated(): bool {
		foreach ( array_keys( self::fields() ) as $fid ) {
			if ( ! self::field_validated( $fid ) ) {
				return false;
			}
		}
		return true;
	}

	/** Per-prompt validation. Legacy installs that ticked the old global box count as validated. */
	public static function field_validated( string $field ): bool {
		$r = self::registry_row( $field );
		return $r ? ! empty( $r['valid'] ) : false;
	}

	/** Whether a field is active (settings override, else the field's shipped default). */
	public static function field_enabled( string $field ): bool {
		$r = self::registry_row( $field );
		return $r ? ! empty( $r['enabled'] ) : false;
	}

	/** Only the active fields, in declaration order. */
	public static function enabled_fields(): array {
		$out = [];
		foreach ( self::fields() as $fid => $f ) {
			if ( self::field_enabled( $fid ) ) {
				$out[ $fid ] = $f;
			}
		}
		return $out;
	}

	/** Per image-template validation (index into image_templates()). */
	public static function template_validated( int $idx ): bool {
		$tpls = self::image_templates();
		return ! empty( $tpls[ $idx ]['valid'] );
	}

	/** [ validated, total ] across ENABLED text prompts, for the side-box note. */
	public static function validated_counts(): array {
		$fields = self::enabled_fields();
		$ok     = 0;
		foreach ( array_keys( $fields ) as $fid ) {
			if ( self::field_validated( $fid ) ) {
				$ok++;
			}
		}
		return [ $ok, count( $fields ) ];
	}

	public static function prompt_for( string $field ): string {
		$r = self::registry_row( $field );
		return $r ? (string) ( $r['prompt'] ?? '' ) : '';
	}

	/**
	 * The shop's main language, as a name the model understands (WPML default
	 * language, else the WordPress locale). Product data is stored in that
	 * language, and every generation must answer in it — whatever language the
	 * prompt itself happens to be written in.
	 */
	public static function site_language(): string {
		$code = (string) apply_filters( 'wpml_default_language', '' );
		if ( '' === $code ) {
			$code = (string) get_locale();
		}
		$names = [
			'en' => 'English', 'fr' => 'French', 'de' => 'German', 'es' => 'Spanish',
			'it' => 'Italian', 'nl' => 'Dutch', 'pt' => 'Portuguese', 'pl' => 'Polish',
		];
		$short = strtolower( substr( str_replace( '-', '_', $code ), 0, 2 ) );
		if ( isset( $names[ $short ] ) ) {
			return ( 'en' === $short && false !== stripos( $code, 'US' ) ) ? 'English (US spelling)' : $names[ $short ];
		}
		return class_exists( 'Locale' ) ? (string) Locale::getDisplayLanguage( $code, 'en' ) : $code;
	}

	/** Language line appended to every text request (never edits the prompts). */
	private static function language_rule(): string {
		return "\n\nLANGUAGE: write the answer in " . self::site_language()
			. '. This overrides the language the instructions above happen to be written in.';
	}

	public static function price_table(): array {
		$t = self::get_settings()['price_table'] ?? null;
		return ( is_array( $t ) && ! empty( $t ) ) ? $t : self::default_price_table();
	}

	/** ENABLED image prompts from the registry, in the shape the image flow uses. */
	/**
	 * Scenes: a fixed image reused as a second source on every generation.
	 *
	 * The point is visual consistency. A shop whose photos come from a dozen
	 * suppliers looks like a dozen shops; giving the model the SAME support —
	 * a studio background, a table top, a garment mockup — on every run is what
	 * turns a pile of product shots into one brand.
	 *
	 * Each scene carries its own instruction, because a mockup and a backdrop
	 * are not used the same way ("print this design onto the garment" versus
	 * "place the product on this surface").
	 *
	 * @return array<int,array{name:string,image:int,prompt:string,default:bool}>
	 */
	public static function scenes(): array {
		$rows = (array) ( self::get_settings()['scenes'] ?? [] );
		$out  = [];
		foreach ( $rows as $r ) {
			$img = (int) ( $r['image'] ?? 0 );
			if ( ! $img ) {
				continue;
			}
			$out[] = [
				'name'    => (string) ( $r['name'] ?? __( 'Scene', 'dazont-ecom' ) ),
				'image'   => $img,
				'prompt'  => (string) ( $r['prompt'] ?? '' ),
				'default' => ! empty( $r['default'] ),
			];
		}
		return $out;
	}

	/**
	 * The paragraph that tells the model what each image it received IS.
	 *
	 * Without it, several photographs of one product read as several products,
	 * and a scene reads as something to blend the product into. It is appended
	 * to the prompt rather than written into it, so the owner's own prompts
	 * never have to carry this plumbing.
	 *
	 * @param int        $count Number of product photographs sent.
	 * @param array|null $scene The scene, when one is used, sent last.
	 */
	public static function sources_instruction( int $count, ?array $scene ): string {
		$out = "\n\n";
		if ( $count > 1 ) {
			$out .= sprintf(
				'IMAGES 1 TO %1$d ARE ALL PHOTOGRAPHS OF ONE SINGLE PRODUCT, taken from different angles and distances — overall views, details, close-ups of the material. Read them together to know exactly what the product looks like: its complete shape, its complete pattern, its real colours and its real texture. Image 1 is the reference for the overall look; the others fill in what image 1 does not show.',
				$count
			);
			$out .= ' NEVER invent, complete, extend or redraw any part of the product. If a part of it is not visible in any of the photographs, keep it out of the frame rather than making it up.';
		} else {
			$out .= 'IMAGE 1 IS THE PRODUCT: keep it exactly as it is — same shape, same pattern, same colours, same materials, same proportions, no redesign, nothing invented.';
		}
		if ( $scene ) {
			$out .= sprintf( ' THE LAST IMAGE (image %d) IS THE SCENE: the support, background and lighting to place the product in — it is not a product and nothing in it must end up looking like one.', $count + 1 );
			if ( '' !== trim( (string) $scene['prompt'] ) ) {
				$out .= "\n" . trim( (string) $scene['prompt'] );
			}
			$out .= "\nThe result must look like one photograph: consistent perspective, contact shadows where the product meets the surface, and the light of the scene falling on the product.";
		}
		return $out;
	}

	/**
	 * The shop's own backdrop plate, built here rather than photographed.
	 *
	 * Two products shot on "a light grey background" come back on two different
	 * greys, and a catalogue where every tile has its own grey looks exactly as
	 * unfinished as one with supplier photographs. So the background stops being
	 * a description and becomes a FILE: one plate, generated once, sent with
	 * every product as the surface to place it on.
	 *
	 * It is drawn small and scaled up: a radial gradient computed pixel by pixel
	 * at full size would be four million iterations of PHP for an image that is,
	 * by definition, smooth.
	 *
	 * @return int attachment id.
	 */
	public static function make_backdrop( int $light = 252, int $dark = 232, int $size = 2048 ): int {
		if ( ! function_exists( 'imagecreatetruecolor' ) ) {
			throw new RuntimeException( __( 'The GD image library is not available on this server.', 'dazont-ecom' ) );
		}
		$light = max( 0, min( 255, $light ) );
		$dark  = max( 0, min( 255, $dark ) );
		$small = 256;
		$im    = imagecreatetruecolor( $small, $small );
		$cx    = ( $small - 1 ) / 2;
		$max   = sqrt( 2 * ( $cx ** 2 ) ); // centre to corner.
		for ( $y = 0; $y < $small; $y++ ) {
			for ( $x = 0; $x < $small; $x++ ) {
				// Slightly flattened falloff: the middle stays open, the corners
				// close in — the studio look, not a vignette.
				$d = sqrt( ( $x - $cx ) ** 2 + ( $y - $cx ) ** 2 ) / $max;
				$t = min( 1.0, $d ** 1.35 );
				$v = (int) round( $light + ( $dark - $light ) * $t );
				imagesetpixel( $im, $x, $y, imagecolorallocate( $im, $v, $v, $v ) );
			}
		}
		$big = imagescale( $im, $size, $size, IMG_BICUBIC );
		imagedestroy( $im );
		if ( ! $big ) {
			throw new RuntimeException( __( 'The backdrop could not be scaled.', 'dazont-ecom' ) );
		}
		$tmp = wp_tempnam( 'dze-backdrop.jpg' );
		imagejpeg( $big, $tmp, 92 );
		imagedestroy( $big );

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$id = media_handle_sideload(
			[ 'name' => 'dazont-backdrop.jpg', 'tmp_name' => $tmp ],
			0,
			__( 'Studio backdrop', 'dazont-ecom' )
		);
		if ( is_wp_error( $id ) ) {
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
			throw new RuntimeException( $id->get_error_message() );
		}
		return (int) $id;
	}

	/**
	 * A plate made before backgrounds were one single list, folded into it.
	 *
	 * It was stored on its own key and shown in its own box, which was one
	 * concept too many: a generated backdrop is a background image like the
	 * ones you upload. Installs that made one keep it, in the list, once.
	 */
	public static function migrate_backdrop(): void {
		$s  = self::get_settings();
		$id = (int) ( $s['backdrop'] ?? 0 );
		if ( ! $id ) {
			return;
		}
		$rows = (array) ( $s['scenes'] ?? [] );
		foreach ( $rows as $r ) {
			if ( (int) ( $r['image'] ?? 0 ) === $id ) {
				$id = 0; // already there.
				break;
			}
		}
		if ( $id && wp_attachment_is_image( $id ) ) {
			$rows[] = [
				'name'    => __( 'Studio backdrop', 'dazont-ecom' ),
				'image'   => $id,
				'prompt'  => '',
				'default' => empty( $rows ),
			];
			$s['scenes'] = $rows;
		}
		unset( $s['backdrop'] );
		remove_filter( 'sanitize_option_' . self::OPT_SETTINGS, [ self::instance(), 'sanitize' ] );
		update_option( self::OPT_SETTINGS, $s, false );
		add_filter( 'sanitize_option_' . self::OPT_SETTINGS, [ self::instance(), 'sanitize' ] );
	}

	/**
	 * Writes ONE settings key without going through the form sanitizer, which
	 * is shaped for form input and would drop everything not posted with it.
	 */
	public static function write_setting( string $key, $value ): void {
		$s         = self::get_settings();
		$s[ $key ] = $value;
		remove_filter( 'sanitize_option_' . self::OPT_SETTINGS, [ self::instance(), 'sanitize' ] );
		update_option( self::OPT_SETTINGS, $s, false );
		add_filter( 'sanitize_option_' . self::OPT_SETTINGS, [ self::instance(), 'sanitize' ] );
		self::$registry_cache = null;
		// Read back: a save that did not happen must not report success.
		$check = self::get_settings();
		if ( ( $check[ $key ] ?? null ) != $value ) { // phpcs:ignore WordPress.PHP.StrictComparisons.LooseComparison -- ints may come back as strings.
			throw new RuntimeException( __( 'The setting could not be saved.', 'dazont-ecom' ) );
		}
	}

	/** The scene to use when none was picked: the one marked as default. */
	public static function default_scene(): int {
		foreach ( self::scenes() as $i => $sc ) {
			if ( $sc['default'] ) {
				return $i;
			}
		}
		return -1;
	}

	public static function image_templates(): array {
		$out = [];
		foreach ( self::registry() as $r ) {
			if ( ( $r['type'] ?? '' ) !== 'image' || empty( $r['enabled'] ) ) {
				continue;
			}
			$out[] = [
				'id'          => (string) $r['id'],
				'name'        => (string) ( $r['name'] ?? '' ),
				'target'      => ( ( $r['output'] ?? 'gallery' ) === 'main' ) ? 'main' : 'gallery',
				'prompt'      => (string) ( $r['prompt'] ?? '' ),
				'valid'       => (int) ! empty( $r['valid'] ),
				'inputs'      => (array) ( $r['inputs'] ?? [ 'title', 'description' ] ),
				'inputs_meta' => (string) ( $r['inputs_meta'] ?? '' ),
			];
		}
		return $out;
	}

	/**
	 * The cost to show for a product, variations included.
	 *
	 * A variable product carries nothing on the parent: its cost, like its
	 * price, lives on the variations. Reading the parent alone is why the
	 * column came up empty. The lowest recorded cost is shown, because that is
	 * the one the "from …" price is built on.
	 */
	public static function product_cost( WC_Product $product ): string {
		$own = self::cost_meta( $product->get_id() );
		if ( '' !== $own ) {
			return $own;
		}
		if ( $product->is_type( 'variable' ) ) {
			$costs = [];
			foreach ( $product->get_children() as $vid ) {
				$c = self::cost_meta( (int) $vid );
				if ( '' === $c ) {
					$v = wc_get_product( (int) $vid );
					$c = $v ? (string) $v->get_regular_price() : '';
				}
				if ( '' !== $c && (float) $c > 0 ) {
					$costs[] = (float) $c;
				}
			}
			return $costs ? (string) min( $costs ) : '';
		}
		return (string) $product->get_regular_price();
	}

	/** Our own cost meta, then WooCommerce's native Cost of Goods. */
	private static function cost_meta( int $id ): string {
		$c = (string) get_post_meta( $id, '_dze_cogs', true );
		if ( '' === $c ) {
			$c = (string) get_post_meta( $id, '_cogs_value', true );
		}
		return $c;
	}

	// =========================================================================
	// Companion images: the photograph a branding block is written against
	// =========================================================================

	/** Post meta holding the picks, so a second run does not pay for the look again. */
	private const META_SHOTS = '_dze_feature_shots';

	/**
	 * Post meta holding content generated but not yet decided on.
	 *
	 * A run of thirty products is not reviewed in one sitting. Keeping the
	 * results in the browser meant a closed tab threw away everything that had
	 * just been paid for; they live on the product now, and the screen finds
	 * them again whenever you come back.
	 */
	private const META_PENDING = '_dze_pending_review';

	/** Merges freshly generated content into what is already waiting. */
	public static function stash( int $pid, array $add ): void {
		$cur = get_post_meta( $pid, self::META_PENDING, true );
		$cur = is_array( $cur ) ? $cur : [ 'texts' => [], 'shots' => [], 'companions' => [] ];
		if ( isset( $add['texts'] ) ) {
			$cur['texts'] = array_merge( (array) ( $cur['texts'] ?? [] ), (array) $add['texts'] );
		}
		if ( isset( $add['companions'] ) ) {
			$cur['companions'] = array_merge( (array) ( $cur['companions'] ?? [] ), (array) $add['companions'] );
		}
		if ( ! empty( $add['shot'] ) ) {
			$shots   = (array) ( $cur['shots'] ?? [] );
			$shots[] = (string) $add['shot'];
			$cur['shots'] = array_values( array_unique( $shots ) );
		}
		$cur['time'] = time();
		update_post_meta( $pid, self::META_PENDING, $cur );
		delete_transient( 'dze_pending_count' );
	}

	/**
	 * How many products are waiting for a decision.
	 *
	 * Read on EVERY admin page to draw the menu bubble, so it is a single
	 * COUNT on an indexed meta key, held in a transient and dropped by the two
	 * places that can change it — never a get_posts() on every screen.
	 */
	public static function pending_count(): int {
		$n = get_transient( 'dze_pending_count' );
		if ( false !== $n ) {
			return (int) $n;
		}
		global $wpdb;
		$n = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s",
			self::META_PENDING
		) );
		set_transient( 'dze_pending_count', $n, HOUR_IN_SECONDS );
		return $n;
	}

	/** What is waiting on one product ([] when nothing is). */
	public static function pending( int $pid ): array {
		$p = get_post_meta( $pid, self::META_PENDING, true );
		return is_array( $p ) ? $p : [];
	}

	/**
	 * Products holding content that has never been accepted or discarded.
	 * Capped: this feeds a notice, not a report.
	 *
	 * @return int[]
	 */
	public static function pending_ids( int $limit = 100 ): array {
		return array_map( 'intval', (array) get_posts( [
			'post_type'      => 'product',
			'post_status'    => 'any',
			'posts_per_page' => $limit,
			'fields'         => 'ids',
			'meta_key'       => self::META_PENDING, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- indexed key, capped, admin only.
			'orderby'        => 'modified',
			'order'          => 'DESC',
			'no_found_rows'  => true,
		] ) );
	}

	/**
	 * How many branding blocks a product's photographs can honestly carry.
	 *
	 * The block is a zoom on a particularity, so it needs a photograph that
	 * actually shows one. With a single catalogue shot there is nothing to zoom
	 * on that the main image does not already say; with a real set there is a
	 * choice to make. Two is the ceiling — a third block is padding.
	 */
	public static function feature_slots( int $count ): int {
		if ( $count >= 4 ) {
			return 2;
		}
		return $count >= 2 ? 1 : 0;
	}

	/**
	 * Asks the model to LOOK at the product photographs and pick the ones worth
	 * writing a zoom about, each with the feature it shows.
	 *
	 * Cached per photograph set: re-running the texts on a product whose images
	 * have not changed reuses the same picks — same images, same answer, no
	 * reason to pay twice — and a new photo invalidates it by changing the key.
	 *
	 * @return array<int,array{id:int,feature:string}>
	 */
	public static function feature_shots( int $pid, bool $refresh = false ): array {
		$ids  = self::product_source_ids( $pid );
		$want = self::feature_slots( count( $ids ) );
		if ( ! $want ) {
			return [];
		}
		$key    = md5( implode( ',', $ids ) . '|' . $want );
		$cached = get_post_meta( $pid, self::META_SHOTS, true );
		if ( ! $refresh && is_array( $cached ) && ( $cached['key'] ?? '' ) === $key && ! empty( $cached['picks'] ) ) {
			return (array) $cached['picks'];
		}

		$images  = [];
		$mapping = [];
		foreach ( $ids as $aid ) {
			$uri = '';
			try {
				$uri = self::instance()->fal_source_data_uri( (int) $aid, 'medium_large' );
			} catch ( \Throwable $e ) {
				continue;
			}
			if ( ! preg_match( '#^data:([^;]+);base64,(.+)$#', $uri, $m ) ) {
				continue;
			}
			$images[]  = [ 'media' => $m[1], 'data' => $m[2] ];
			$mapping[] = (int) $aid;
		}
		if ( count( $images ) < 2 ) {
			return [];
		}

		$product = wc_get_product( $pid );
		$name    = $product ? $product->get_name() : '';
		$user    = "Product: {$name}

"
			. sprintf(
				/* translators: 1: number of photographs, 2: number to pick. */
				__( 'Above are the %1$d photographs of this product. Pick %2$d of them.', 'dazont-ecom' ),
				count( $images ),
				$want
			)
			. ' ' . self::feature_prompt()
			. "
Answer with STRICT JSON and nothing else: "
			. '[{"image": <number>, "feature": "<what this photograph shows, 4 to 12 words, factual, no sales language>"}]';

		try {
			DZE_Ai_Usage::unit( 'feature_pick' );
			$raw = DZE_Marketing_Ai::complete_with_images(
				'You look at product photographs and report what is visible in them. You never invent a detail you cannot see.',
				$user,
				$images,
				'',
				400
			);
		} catch ( \Throwable $e ) {
			DZE_Ai_Usage::unit();
			return [];
		}
		DZE_Ai_Usage::unit();
		DZE_Ai_Usage::finished( 'feature_pick' );
		$json = trim( (string) preg_replace( '/^```(?:json)?|```$/m', '', $raw ) );
		$rows = json_decode( $json, true );
		if ( ! is_array( $rows ) ) {
			return [];
		}
		$picks = [];
		$seen  = [];
		foreach ( $rows as $r ) {
			$n = (int) ( $r['image'] ?? 0 ) - 1; // the model counts from 1.
			if ( ! isset( $mapping[ $n ] ) || isset( $seen[ $n ] ) ) {
				continue;
			}
			$seen[ $n ] = true;
			$picks[]    = [
				'id'      => $mapping[ $n ],
				'feature' => sanitize_text_field( (string) ( $r['feature'] ?? '' ) ),
			];
			if ( count( $picks ) >= $want ) {
				break;
			}
		}
		if ( $picks ) {
			update_post_meta( $pid, self::META_SHOTS, [ 'key' => $key, 'picks' => $picks ] );
		}
		return $picks;
	}

	/**
	 * The prompts that carry a companion image, in registry order: the first
	 * one gets the first pick, the second the second. A prompt whose slot has
	 * no pick is skipped entirely rather than written against nothing.
	 *
	 * @return string[] Field ids.
	 */
	public static function companion_fields(): array {
		$out = [];
		foreach ( self::enabled_fields() as $fid => $f ) {
			$row = self::registry_row( $fid );
			if ( '' !== trim( (string) ( $row['img_meta'] ?? '' ) ) ) {
				$out[] = (string) $fid;
			}
		}
		return $out;
	}

	/** The meta key a field writes its companion attachment id to ('' = none). */
	public static function companion_meta( string $fid ): string {
		$row = self::registry_row( $fid );
		return trim( (string) ( $row['img_meta'] ?? '' ) );
	}

	/**
	 * field id => pick, for one product. Deterministic, so generation and apply
	 * agree without the browser having to carry the choice around.
	 *
	 * @return array<string,array{id:int,feature:string}>
	 */
	public static function companion_map( int $pid ): array {
		$fields = self::companion_fields();
		if ( ! $fields ) {
			return [];
		}
		$picks = self::feature_shots( $pid );
		$out   = [];
		foreach ( array_values( $fields ) as $i => $fid ) {
			if ( isset( $picks[ $i ] ) ) {
				$out[ $fid ] = $picks[ $i ];
			}
		}
		return $out;
	}

	/** Multiplier for a given cost. */
	public static function mult_for_cost( float $cost ): float {
		foreach ( self::price_table() as $row ) {
			$min = (float) ( $row['min'] ?? 0 );
			$max = (float) ( $row['max'] ?? 0 );
			if ( $cost >= $min && ( $max <= 0 || $cost < $max ) ) {
				return (float) ( $row['mult'] ?? 1 );
			}
		}
		return 1.0;
	}

	/**
	 * Where a field's output goes: the mapped destination if set, else the
	 * field's default. @return array{type:string,key?:string}
	 */
	private static function dest_for( string $field ): array {
		$r = self::registry_row( $field );
		$sel = $r ? (string) ( $r['output'] ?? 'meta' ) : 'meta';
		if ( 'meta' === $sel ) {
			$key = $r ? (string) ( $r['meta_key'] ?? '' ) : '';
			return [ 'type' => 'meta', 'key' => '' !== $key ? $key : '_dze_' . $field ];
		}
		return [ 'type' => $sel ];
	}

	/** SEO plugin meta keys, auto-detected (Yoast, Rank Math, fallback own). */
	public static function seo_keys(): array {
		if ( defined( 'WPSEO_VERSION' ) ) {
			return [ 'title' => '_yoast_wpseo_title', 'desc' => '_yoast_wpseo_metadesc' ];
		}
		if ( defined( 'RANK_MATH_VERSION' ) || class_exists( 'RankMath' ) ) {
			return [ 'title' => 'rank_math_title', 'desc' => 'rank_math_description' ];
		}
		return [ 'title' => '_dze_seo_title', 'desc' => '_dze_seo_desc' ];
	}

	public function register_settings(): void {
		register_setting( 'dze_content_options', self::OPT_SETTINGS, [
			'sanitize_callback' => [ $this, 'sanitize' ],
			'autoload'          => false,
		] );
	}

	public function sanitize( $in ): array {
		// Re-entrancy guard: update_option() on this option triggers this callback
		// again (sanitize_option_* filter) — pass nested calls through untouched.
		static $busy = false;
		if ( $busy ) {
			return is_array( $in ) ? $in : [];
		}
		$busy = true;
		$in  = is_array( $in ) ? $in : [];
		$out = self::get_settings();

		if ( ! defined( 'DZE_FAL_API_KEY' ) && isset( $in['fal_key'] ) ) {
			$k = trim( (string) $in['fal_key'] );
			$out['fal_key'] = '' !== $k ? sanitize_text_field( $k ) : (string) ( $out['fal_key'] ?? '' );
		}
		if ( isset( $in['fal_image_cost'] ) ) {
			$c = (float) $in['fal_image_cost'];
			$out['fal_image_cost'] = $c > 0 ? round( $c, 4 ) : 0.0;
		}
		// The General-tab mini form (explicit marker) only carries key + price:
		// don't touch the rest then. A key-count heuristic was used before and
		// misfired on slim installs, silently discarding programmatic saves.
		if ( isset( $in['fal_form'] ) ) {
			$busy = false;
			return $out;
		}

		if ( isset( $in['store_context'] ) ) {
			$out['store_context'] = sanitize_textarea_field( (string) $in['store_context'] );
		}
		if ( isset( $in['quick_prompt'] ) ) {
			$p = trim( sanitize_textarea_field( (string) $in['quick_prompt'] ) );
			$out['quick_prompt'] = ( $p === trim( self::default_quick_prompt() ) ) ? '' : $p;
		}
		if ( isset( $in['feature_prompt'] ) ) {
			$p = trim( sanitize_textarea_field( (string) $in['feature_prompt'] ) );
			// Empty means "the shipped rules", never an empty instruction.
			$out['feature_prompt'] = ( $p === trim( self::default_feature_prompt() ) ) ? '' : $p;
		}
		// Scene library: name + image + its own instruction, one marked default.
		if ( isset( $in['sc_name'] ) && is_array( $in['sc_name'] ) ) {
			$rows = [];
			$def  = isset( $in['sc_default'] ) ? (int) $in['sc_default'] : -1;
			foreach ( $in['sc_name'] as $i => $name ) {
				$img = (int) ( $in['sc_image'][ $i ] ?? 0 );
				if ( ! $img ) {
					continue; // a scene without its image is not a scene.
				}
				$rows[] = [
					'name'    => sanitize_text_field( (string) $name ) ?: __( 'Scene', 'dazont-ecom' ),
					'image'   => $img,
					'prompt'  => sanitize_textarea_field( (string) ( $in['sc_prompt'][ $i ] ?? '' ) ),
					'default' => ( (int) $i === $def ),
				];
			}
			$out['scenes'] = $rows;
		}
		// Price table.
		if ( isset( $in['pt_min'] ) && is_array( $in['pt_min'] ) ) {
			$rows = [];
			$mins = array_map( 'floatval', $in['pt_min'] );
			$maxs = array_map( 'floatval', (array) ( $in['pt_max'] ?? [] ) );
			$muls = array_map( 'floatval', (array) ( $in['pt_mult'] ?? [] ) );
			foreach ( $mins as $i => $mn ) {
				$ml = (float) ( $muls[ $i ] ?? 0 );
				if ( $ml <= 0 ) {
					continue;
				}
				$rows[] = [ 'min' => (float) $mn, 'max' => (float) ( $maxs[ $i ] ?? 0 ), 'mult' => $ml ];
			}
			if ( $rows ) {
				$out['price_table'] = $rows;
			}
		}
		// Universal prompt registry rows (the new editor posts pr_* arrays).
		if ( isset( $in['pr_name'] ) && is_array( $in['pr_name'] ) ) {
			$rows = [];
			$seen = [];
			foreach ( $in['pr_name'] as $i => $name ) {
				$name   = sanitize_text_field( (string) $name );
				$prompt = sanitize_textarea_field( (string) ( $in['pr_prompt'][ $i ] ?? '' ) );
				if ( '' === $name || '' === trim( $prompt ) ) {
					continue; // empty rows (e.g. the blank "add" row) are dropped.
				}
				$id = sanitize_key( (string) ( $in['pr_id'][ $i ] ?? '' ) );
				if ( '' === $id ) {
					$id = sanitize_key( str_replace( ' ', '_', $name ) ) ?: 'prompt';
				}
				while ( isset( $seen[ $id ] ) ) {
					$id .= '_2';
				}
				$seen[ $id ] = 1;
				$type   = ( ( $in['pr_type'][ $i ] ?? 'text' ) === 'image' ) ? 'image' : 'text';
				$outsel = (string) ( $in['pr_output'][ $i ] ?? '' );
				if ( ! array_key_exists( $outsel, self::output_options( $type ) ) ) {
					$outsel = 'image' === $type ? 'gallery' : 'meta';
				}
				$inputs = array_values( array_intersect(
					array_map( 'sanitize_key', (array) ( $in['pr_inputs'][ $i ] ?? [] ) ),
					array_keys( self::input_options() )
				) );
				$rows[] = [
					'id'          => $id,
					'name'        => $name,
					'type'        => $type,
					'prompt'      => $prompt,
					'inputs'      => $inputs ?: [ 'title', 'description' ],
					'inputs_meta' => sanitize_text_field( (string) ( $in['pr_inmeta'][ $i ] ?? '' ) ),
					'output'      => $outsel,
					'meta_key'    => sanitize_key( (string) ( $in['pr_metakey'][ $i ] ?? '' ) ) ?: '_dze_' . $id,
					// Optional: the meta key that receives the id of the
					// photograph this text is written against.
					'img_meta'    => sanitize_key( (string) ( $in['pr_imgmeta'][ $i ] ?? '' ) ),
					'enabled'     => ! empty( $in['pr_on'][ $i ] ) ? 1 : 0,
					'valid'       => ! empty( $in['pr_valid'][ $i ] ) ? 1 : 0,
					'tokens'      => max( 50, (int) ( $in['pr_tokens'][ $i ] ?? 400 ) ),
				];
			}
			if ( $rows ) {
				$out['registry'] = $rows;
			}
		}
		// Programmatic saves (e.g. ajax_save_prompt) call update_option with the
		// canonical settings array, and WordPress re-runs this sanitizer on it.
		// Without this branch their registry rows were silently dropped and the
		// old rows written back — accept the canonical shape too.
		if ( isset( $in['registry'] ) && is_array( $in['registry'] ) && ! isset( $in['pr_name'] ) ) {
			$rows = [];
			$seen = [];
			foreach ( $in['registry'] as $r ) {
				if ( ! is_array( $r ) ) {
					continue;
				}
				$name   = sanitize_text_field( (string) ( $r['name'] ?? '' ) );
				$prompt = sanitize_textarea_field( (string) ( $r['prompt'] ?? '' ) );
				if ( '' === $name || '' === trim( $prompt ) ) {
					continue;
				}
				$id = sanitize_key( (string) ( $r['id'] ?? '' ) );
				if ( '' === $id ) {
					$id = sanitize_key( str_replace( ' ', '_', $name ) ) ?: 'prompt';
				}
				while ( isset( $seen[ $id ] ) ) {
					$id .= '_2';
				}
				$seen[ $id ] = 1;
				$type   = ( ( $r['type'] ?? 'text' ) === 'image' ) ? 'image' : 'text';
				$outsel = (string) ( $r['output'] ?? '' );
				if ( ! array_key_exists( $outsel, self::output_options( $type ) ) ) {
					$outsel = 'image' === $type ? 'gallery' : 'meta';
				}
				$inputs = array_values( array_intersect(
					array_map( 'sanitize_key', (array) ( $r['inputs'] ?? [] ) ),
					array_keys( self::input_options() )
				) );
				$rows[] = [
					'id'          => $id,
					'name'        => $name,
					'type'        => $type,
					'prompt'      => $prompt,
					'inputs'      => $inputs ?: [ 'title', 'description' ],
					'inputs_meta' => sanitize_text_field( (string) ( $r['inputs_meta'] ?? '' ) ),
					'output'      => $outsel,
					'meta_key'    => sanitize_key( (string) ( $r['meta_key'] ?? '' ) ) ?: '_dze_' . $id,
					'enabled'     => ! empty( $r['enabled'] ) ? 1 : 0,
					'valid'       => ! empty( $r['valid'] ) ? 1 : 0,
					'tokens'      => max( 50, (int) ( $r['tokens'] ?? 400 ) ),
				];
			}
			if ( $rows ) {
				$out['registry'] = $rows;
			}
		}
		unset( $out['prompts_validated'] ); // replaced by per-prompt validation.
		self::$registry_cache = null; // saved rows take effect immediately.
		$busy = false;
		return $out;
	}

	// =========================================================================
	// Settings rendering — General tab key field + Product-content tab
	// =========================================================================

	/** fal.ai key field, shown on the General tab next to the other API keys. */
	public function render_key_field(): void {
		$fal_locked = defined( 'DZE_FAL_API_KEY' );
		$has_fal    = self::fal_key() !== '';
		?>
		<div class="dze-admin">
		<form method="post" action="options.php">
		<?php settings_fields( 'dze_content_options' ); ?>
		<input type="hidden" name="<?php echo esc_attr( self::OPT_SETTINGS ); ?>[fal_form]" value="1" />
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="dze-fal-key"><?php esc_html_e( 'fal.ai API key (images)', 'dazont-ecom' ); ?></label></th>
				<td>
					<?php echo DZE_Api_Keys::status_html( 'fal', self::fal_key(), $fal_locked ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built escaped. ?>
					<?php if ( ! $fal_locked ) : ?>
						<input type="password" id="dze-fal-key" class="regular-text" name="<?php echo esc_attr( self::OPT_SETTINGS ); ?>[fal_key]" value="" autocomplete="new-password" placeholder="<?php echo $has_fal ? esc_attr__( 'Leave blank to keep the saved key', 'dazont-ecom' ) : esc_attr__( 'Paste your fal.ai key', 'dazont-ecom' ); ?>" />
						<p class="description"><?php esc_html_e( 'Used for image generation (fal.ai nano-banana-2/edit). For production, define DZE_FAL_API_KEY in wp-config.php.', 'dazont-ecom' ); ?></p>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="dze-fal-cost"><?php esc_html_e( 'fal.ai price per image (USD)', 'dazont-ecom' ); ?></label></th>
				<td>
					<input type="number" id="dze-fal-cost" step="0.001" min="0" name="<?php echo esc_attr( self::OPT_SETTINGS ); ?>[fal_image_cost]" value="<?php echo esc_attr( self::fal_image_cost() ); ?>" style="width:110px;" />
					<p class="description"><?php esc_html_e( 'Each generated image is counted at this price in the AI usage graph and the monthly budget. Check your fal.ai model pricing and adjust.', 'dazont-ecom' ); ?></p>
				</td>
			</tr>
		</table>
		<?php submit_button( __( 'Save fal.ai settings', 'dazont-ecom' ), 'secondary', 'submit', true ); ?>
		</form>
		</div>
		<?php
	}

	/** Full "Product content" tab: prompts + field mapping, images, prices, validation. */
	public function render_settings_section(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		self::migrate_backdrop(); // one list of backgrounds, not two concepts.
		$s     = self::get_settings();
		$opt   = self::OPT_SETTINGS;
		$seo   = self::seo_keys();
		$dests = self::dest_options();
		?>
		<div class="dze-admin">
		<p class="description" style="max-width:900px;">
			<?php esc_html_e( 'Generate every product field from the imported data, generate images from templates, and recalculate the price from cost. Text uses the Anthropic key (General tab); images use the fal.ai key (General tab). Tune the prompts and the field mapping, test on real products from the toolbox (preview mode), then tick "Prompts validated" to unlock applying.', 'dazont-ecom' ); ?>
		</p>
		<?php $dze_blk = self::image_blockers(); ?>
		<?php if ( $dze_blk ) : ?>
			<div class="notice notice-error inline" style="margin:12px 0;"><p><strong><?php esc_html_e( 'Images cannot be generated right now:', 'dazont-ecom' ); ?></strong></p>
			<ul style="margin:0 0 10px 20px;list-style:disc;">
				<?php foreach ( $dze_blk as $dze_b ) : ?>
					<li><?php echo esc_html( $dze_b['text'] ); ?> <a href="<?php echo esc_url( $dze_b['url'] ); ?>"><?php echo esc_html( $dze_b['label'] ); ?></a></li>
				<?php endforeach; ?>
			</ul></div>
		<?php endif; ?>
		<form method="post" action="options.php">
			<?php settings_fields( 'dze_content_options' ); ?>

			<details class="dze-set">
			<summary><?php esc_html_e( 'Store context — one line prepended to every generation', 'dazont-ecom' ); ?></summary>
			<textarea id="dze-ct-context" name="<?php echo esc_attr( $opt ); ?>[store_context]" rows="3" class="large-text"><?php echo esc_textarea( (string) ( $s['store_context'] ?? '' ) ); ?></textarea>
			<p>
				<button type="button" class="button" id="dze-ct-context-gen">&#10022; <?php esc_html_e( 'Read it from my shop', 'dazont-ecom' ); ?></button>
				<span id="dze-ct-context-state" class="description" style="margin-left:8px;"></span>
			</p>
			<details class="dze-cx-acc" id="dze-ct-context-facts" style="display:none;">
				<summary><?php esc_html_e( 'What the shop was read as', 'dazont-ecom' ); ?></summary>
				<pre class="dze-prompt-text" id="dze-ct-context-factstext"></pre>
			</details>
			<script>
			jQuery( function ( $ ) {
				// Proposed, never imposed: it lands in the box and you fix it.
				$( '#dze-ct-context-gen' ).on( 'click', function () {
					var $b = $( this ).prop( 'disabled', true );
					var $st = $( '#dze-ct-context-state' ).text( '…' );
					$.post( window.ajaxurl, {
						action: 'dze_content_context',
						nonce: '<?php echo esc_js( wp_create_nonce( self::NONCE ) ); ?>'
					} ).done( function ( r ) {
						$b.prop( 'disabled', false );
						if ( ! r || ! r.success ) { $st.text( ( r && r.data && r.data.message ) || '' ); return; }
						$st.text( '' );
						$( '#dze-ct-context' ).val( r.data.text );
						$( '#dze-ct-context-factstext' ).text( r.data.facts );
						$( '#dze-ct-context-facts' ).show();
					} ).fail( function () { $b.prop( 'disabled', false ); $st.text( '' ); } );
				} );
			} );
			</script>
			<p class="description"><?php esc_html_e( 'Prepended to every generation, e.g. "Kula Tactical > Military / tactical clothing and gear > Tone: sharp, authoritative, informational".', 'dazont-ecom' ); ?></p>

			</details>

			<details class="dze-set">
			<summary><?php esc_html_e( 'Backgrounds — the surfaces your products are shot on', 'dazont-ecom' ); ?></summary>
			<p class="description" style="max-width:960px;">
				<?php esc_html_e( 'The images you keep to shoot your products on: a studio backdrop, a floor for rugs, a table top, a garment mockup. Pick one when you generate an image and the product is placed on it, so a catalogue shot by a dozen suppliers comes back looking like one shop. The note under each image is optional — it says how that particular background is meant to be used ("lay the rug flat on this floor"), and it is only worth writing when the image alone does not say it.', 'dazont-ecom' ); ?>
			</p>
			<?php $dze_scenes = self::scenes(); $dze_scenes[] = [ 'name' => '', 'image' => 0, 'prompt' => '', 'default' => false ]; ?>
			<div class="dze-bggrid" id="dze-sc">
			<?php foreach ( $dze_scenes as $si => $sc ) : ?>
				<div class="dze-bgcard<?php echo empty( $sc['image'] ) ? ' is-empty' : ''; ?>">
					<div class="dze-sc-thumb">
						<?php if ( ! empty( $sc['image'] ) ) : ?>
							<?php echo wp_get_attachment_image( (int) $sc['image'], 'medium', false, [ 'class' => 'dze-hzoom', 'data-full' => (string) wp_get_attachment_image_url( (int) $sc['image'], 'full' ) ] ); ?>
						<?php endif; ?>
					</div>
					<input type="hidden" class="dze-sc-img" name="<?php echo esc_attr( $opt ); ?>[sc_image][]" value="<?php echo (int) $sc['image']; ?>" />
					<input type="text" name="<?php echo esc_attr( $opt ); ?>[sc_name][]" value="<?php echo esc_attr( (string) $sc['name'] ); ?>" placeholder="<?php esc_attr_e( 'Name it', 'dazont-ecom' ); ?>" />
					<input type="text" name="<?php echo esc_attr( $opt ); ?>[sc_prompt][]" value="<?php echo esc_attr( (string) $sc['prompt'] ); ?>" placeholder="<?php esc_attr_e( 'note for the model (optional)', 'dazont-ecom' ); ?>" class="dze-bgnote" />
					<p class="dze-bgfoot">
						<label title="<?php esc_attr_e( 'Pre-selected when you generate an image', 'dazont-ecom' ); ?>">
							<input type="radio" name="<?php echo esc_attr( $opt ); ?>[sc_default]" value="<?php echo (int) $si; ?>" <?php checked( ! empty( $sc['default'] ) ); ?> />
							<?php esc_html_e( 'Default', 'dazont-ecom' ); ?>
						</label>
						<button type="button" class="button button-small dze-sc-pick"><?php echo empty( $sc['image'] ) ? esc_html__( 'Choose an image', 'dazont-ecom' ) : esc_html__( 'Replace', 'dazont-ecom' ); ?></button>
						<?php if ( ! empty( $sc['image'] ) ) : ?>
							<button type="button" class="button-link dze-sc-clear" style="color:#b32d2e;"><?php esc_html_e( 'remove', 'dazont-ecom' ); ?></button>
						<?php endif; ?>
					</p>
				</div>
			<?php endforeach; ?>
			</div>
			<p>
				<button type="button" class="button" id="dze-sc-add">&#43; <?php esc_html_e( 'Add a background', 'dazont-ecom' ); ?></button>
				<button type="button" class="button" id="dze-bd-make" style="margin-left:8px;">&#9788; <?php esc_html_e( 'Generate a plain grey one', 'dazont-ecom' ); ?></button>
				<span id="dze-bd-state" class="description" style="margin-left:8px;"></span>
			</p>

			<div class="dze-bgmake">
				<p class="description" style="margin-top:0;">
					<?php esc_html_e( 'Or describe the set you want and have it made — an empty set, with nothing in it but the surface and its light. Say the material, the colour, the angle it is seen from and how it is lit; the more precise, the more usable. It lands on the shelf like any other background.', 'dazont-ecom' ); ?>
				</p>
				<p class="dze-bgmake-bar">
					<input type="text" id="dze-bg-desc" class="large-text" placeholder="<?php esc_attr_e( 'e.g. a pale oak floor seen from straight above, soft daylight from the left, no furniture', 'dazont-ecom' ); ?>" />
					<select id="dze-bg-ratio" title="<?php esc_attr_e( 'Shape of the plate', 'dazont-ecom' ); ?>">
						<option value="1:1"><?php esc_html_e( 'Square', 'dazont-ecom' ); ?></option>
						<option value="4:3"><?php esc_html_e( 'Landscape', 'dazont-ecom' ); ?></option>
						<option value="3:4"><?php esc_html_e( 'Portrait', 'dazont-ecom' ); ?></option>
					</select>
					<button type="button" class="button button-primary" id="dze-bg-make">&#10022; <?php esc_html_e( 'Make it', 'dazont-ecom' ); ?></button>
					<span id="dze-bg-makestate" class="description"></span>
				</p>
				<p class="dze-bgmake-ideas">
					<span class="description"><?php esc_html_e( 'Starting points:', 'dazont-ecom' ); ?></span>
					<?php
					$dze_ideas = [
						__( 'seamless light grey studio backdrop, the floor curving up into the wall, even soft light, no shadow', 'dazont-ecom' ),
						__( 'a pale oak floor seen from straight above, soft daylight from the left, no furniture', 'dazont-ecom' ),
						__( 'a raw concrete wall and floor, one soft light from the right, industrial and neutral', 'dazont-ecom' ),
						__( 'a dark walnut table top seen at a slight angle, warm directional light, plain dark background behind', 'dazont-ecom' ),
						__( 'coarse natural linen laid flat, seen from above, diffuse daylight, no creases', 'dazont-ecom' ),
					];
					foreach ( $dze_ideas as $dze_idea ) :
						?>
						<button type="button" class="button-link dze-bg-idea"><?php echo esc_html( wp_trim_words( $dze_idea, 5, '…' ) ); ?><span class="screen-reader-text"><?php echo esc_html( $dze_idea ); ?></span><span class="dze-bg-full" hidden><?php echo esc_attr( $dze_idea ); ?></span></button>
					<?php endforeach; ?>
				</p>
			</div>

			<div class="dze-bgmake">
				<p class="description" style="margin-top:0;">
					<?php esc_html_e( 'Or empty a photograph you already have: hand it one of your own shots and the product is taken out of it — the object, its shadow, the hanger, the label — leaving the set alone. Materials, light and framing are kept, so what comes back is your own set with nothing standing in it. It is redrawn, not cut out: it matches closely without being the same pixels, which is all a backdrop needs since every product is re-lit onto it afterwards.', 'dazont-ecom' ); ?>
				</p>
				<p class="dze-bgmake-bar">
					<button type="button" class="button" id="dze-bg-src"><?php esc_html_e( 'Choose a photograph', 'dazont-ecom' ); ?></button>
					<input type="hidden" id="dze-bg-srcid" value="0" />
					<span id="dze-bg-srcname" class="description"></span>
					<input type="text" id="dze-bg-stripurl" class="regular-text" placeholder="<?php esc_attr_e( 'or paste the address of an image', 'dazont-ecom' ); ?>" />
					<button type="button" class="button button-primary" id="dze-bg-strip">&#9003; <?php esc_html_e( 'Empty it', 'dazont-ecom' ); ?></button>
					<span id="dze-bg-stripstate" class="description"></span>
				</p>
			</div>
			<p class="description">
				<?php esc_html_e( 'A card without an image is ignored. The generated one is a soft grey gradient, lighter in the middle, drawn here rather than photographed — so it can be made again identically whenever you want.', 'dazont-ecom' ); ?>
			</p>

			<script>
			jQuery( function ( $ ) {
				var dzeScPick = <?php echo wp_json_encode( __( 'Choose', 'dazont-ecom' ) ); ?>,
					dzeScRepl = <?php echo wp_json_encode( __( 'Replace', 'dazont-ecom' ) ); ?>,
					dzeScGone = <?php echo wp_json_encode( __( 'remove', 'dazont-ecom' ) ); ?>,
					dzeScTtl  = <?php echo wp_json_encode( __( 'Choose the scene image', 'dazont-ecom' ) ); ?>,
					dzeScUse  = <?php echo wp_json_encode( __( 'Use this image', 'dazont-ecom' ) ); ?>,
					dzeScFrm  = null;
				// The native media modal: the scene is an image already in the
				// library, never an upload path of our own.
				$( document ).on( 'click', '.dze-sc-pick', function () {
					if ( ! window.wp || ! wp.media ) { return; }
					var $cell = $( this ).closest( '.dze-bgcard' );
					dzeScFrm = wp.media( {
						title: dzeScTtl,
						library: { type: 'image' },
						button: { text: dzeScUse },
						multiple: false
					} );
					dzeScFrm.on( 'select', function () {
						var a = dzeScFrm.state().get( 'selection' ).first().toJSON();
						var url = ( a.sizes && a.sizes.thumbnail ) ? a.sizes.thumbnail.url : a.url;
						$cell.find( '.dze-sc-img' ).val( a.id );
						$cell.find( '.dze-sc-thumb' ).html(
							$( '<img />' ).attr( 'src', url ).attr( 'alt', '' ).css( { maxWidth: '90px', height: 'auto', borderRadius: '4px' } )
						);
						$cell.removeClass( 'is-empty' ).find( '.dze-sc-pick' ).text( dzeScRepl );
						if ( ! $cell.find( '.dze-sc-clear' ).length ) {
							$cell.find( '.dze-sc-pick' ).after(
								' <button type="button" class="button-link dze-sc-clear" style="color:#b32d2e;"></button>'
							);
							$cell.find( '.dze-sc-clear' ).text( dzeScGone );
						}
					} );
					dzeScFrm.open();
				} );
				$( document ).on( 'click', '.dze-sc-clear', function () {
					var $cell = $( this ).closest( '.dze-bgcard' );
					$cell.find( '.dze-sc-img' ).val( '0' );
					$cell.find( '.dze-sc-thumb' ).empty();
					$cell.find( '.dze-sc-pick' ).text( dzeScPick );
					$cell.addClass( 'is-empty' );
					$( this ).remove();
				} );
				// A fresh empty row, with the Default radio numbered like its
				// position — the server reads the rows in DOM order.
				// Describe a set, get a plate: it lands on the shelf like the rest.
				$( '.dze-bg-idea' ).on( 'click', function () {
					$( '#dze-bg-desc' ).val( $( this ).find( '.dze-bg-full' ).text() ).focus();
				} );
				$( '#dze-bg-make' ).on( 'click', function () {
					var $b = $( this ).prop( 'disabled', true );
					var $st = $( '#dze-bg-makestate' ).text( <?php echo wp_json_encode( __( 'Shooting the set…', 'dazont-ecom' ) ); ?> );
					$.post( window.ajaxurl, {
						action: 'dze_content_bg_make',
						nonce: '<?php echo esc_js( wp_create_nonce( self::NONCE ) ); ?>',
						desc: $( '#dze-bg-desc' ).val(),
						ratio: $( '#dze-bg-ratio' ).val()
					} ).done( function ( r ) {
						$b.prop( 'disabled', false );
						if ( ! r || ! r.success ) { $st.text( ( r && r.data && r.data.message ) || '' ); return; }
						$st.text( '' );
						dzeBgShelf( r.data );
					} ).fail( function () { $b.prop( 'disabled', false ); $st.text( '' ); } );
				} );
				// Whatever made the image — a description or an emptied photo —
				// it lands on the shelf the same way: the last empty card, or a
				// new one.
				function dzeBgShelf( d ) {
					var $cards = $( '#dze-sc .dze-bgcard' ), $card = $cards.last();
					if ( $card.find( '.dze-sc-img' ).val() !== '0' ) {
						$( '#dze-sc-add' ).trigger( 'click' );
						$card = $( '#dze-sc .dze-bgcard' ).last();
					}
					$card.removeClass( 'is-empty' );
					$card.find( '.dze-sc-img' ).val( d.id );
					$card.find( '.dze-sc-thumb' ).html( $( '<img />' ).attr( 'src', d.thumb ).attr( 'alt', '' ) );
					$card.find( 'input[name$="[sc_name][]"]' ).val( d.name );
					$card.find( '.dze-sc-pick' ).text( dzeScRepl );
				}
				// Empty one of my own photographs: pick it in the library, or
				// paste its address, and the product walks out of the frame.
				var dzeBgSrcFrm = null;
				$( '#dze-bg-src' ).on( 'click', function () {
					if ( ! window.wp || ! wp.media ) { return; }
					dzeBgSrcFrm = wp.media( {
						title: <?php echo wp_json_encode( __( 'Choose the photograph to empty', 'dazont-ecom' ) ); ?>,
						library: { type: 'image' },
						button: { text: dzeScUse },
						multiple: false
					} );
					dzeBgSrcFrm.on( 'select', function () {
						var a = dzeBgSrcFrm.state().get( 'selection' ).first().toJSON();
						$( '#dze-bg-srcid' ).val( a.id );
						$( '#dze-bg-srcname' ).text( a.filename || a.title || '' );
						$( '#dze-bg-stripurl' ).val( '' );
					} );
					dzeBgSrcFrm.open();
				} );
				$( '#dze-bg-stripurl' ).on( 'input', function () {
					if ( $( this ).val() ) { $( '#dze-bg-srcid' ).val( '0' ); $( '#dze-bg-srcname' ).text( '' ); }
				} );
				$( '#dze-bg-strip' ).on( 'click', function () {
					var $b = $( this ).prop( 'disabled', true );
					var $st = $( '#dze-bg-stripstate' ).text( <?php echo wp_json_encode( __( 'Clearing the set…', 'dazont-ecom' ) ); ?> );
					$.post( window.ajaxurl, {
						action: 'dze_content_bg_strip',
						nonce: '<?php echo esc_js( wp_create_nonce( self::NONCE ) ); ?>',
						att: $( '#dze-bg-srcid' ).val(),
						url: $( '#dze-bg-stripurl' ).val()
					} ).done( function ( r ) {
						$b.prop( 'disabled', false );
						if ( ! r || ! r.success ) { $st.text( ( r && r.data && r.data.message ) || '' ); return; }
						$st.text( '' );
						dzeBgShelf( r.data );
					} ).fail( function () { $b.prop( 'disabled', false ); $st.text( '' ); } );
				} );
				$( '#dze-sc-add' ).on( 'click', function () {
					var $cards = $( '#dze-sc .dze-bgcard' ), $card = $cards.last().clone();
					$card.addClass( 'is-empty' );
					$card.find( 'input[type=text]' ).val( '' );
					$card.find( '.dze-sc-img' ).val( '0' );
					$card.find( '.dze-sc-thumb' ).empty();
					$card.find( '.dze-sc-clear' ).remove();
					$card.find( '.dze-sc-pick' ).text( dzeScPick );
					$card.find( 'input[type=radio]' ).prop( 'checked', false ).val( String( $cards.length ) );
					$( '#dze-sc' ).append( $card );
					$card.find( '.dze-sc-pick' ).trigger( 'click' );
				} );
			} );
			</script>

			<?php if ( class_exists( 'DZE_Pod' ) && ( ! class_exists( 'DZE_Modules' ) || DZE_Modules::enabled( 'pod' ) ) ) : ?>
				<h3 class="dze-set-sub"><?php esc_html_e( 'Print on demand — the blank product and its recipe', 'dazont-ecom' ); ?></h3>
				<p class="description" style="max-width:960px;">
					<?php esc_html_e( 'A mockup is a background too: the photograph of your blank product, kept once, that every design is printed onto. It lives here with the rest of the static images instead of in a screen of its own.', 'dazont-ecom' ); ?>
				</p>
				<?php DZE_Pod::instance()->render_settings( false ); ?>
			<?php endif; ?>

			<h3 class="dze-set-sub"><?php esc_html_e( 'The main-image recipe', 'dazont-ecom' ); ?></h3>
			<p class="description" style="max-width:900px;">
				<?php esc_html_e( 'The recipe behind the "Main image" lane of the product toolbox: one photograph in — the product\'s own, or one pasted from a supplier page — one catalogue shot out, ready to be the main image. This is where you set the look every listing of the shop shares.', 'dazont-ecom' ); ?>
			</p>
			<textarea id="dze-ct-quick-prompt" name="<?php echo esc_attr( $opt ); ?>[quick_prompt]" rows="5" class="large-text code"><?php echo esc_textarea( self::quick_prompt() ); ?></textarea>
			<p class="description">
				<?php esc_html_e( 'Empty = shipped default (shown greyed).', 'dazont-ecom' ); ?>
				<button type="button" class="button-link" id="dze-ct-quick-restore">&#8634; <?php esc_html_e( 'Restore default', 'dazont-ecom' ); ?></button>
			</p>

			</details>

			<details class="dze-set" open>
			<summary><?php esc_html_e( 'Prompts — what the plugin writes, and how', 'dazont-ecom' ); ?></summary>
			<p class="description" style="max-width:960px;">
				<?php esc_html_e( 'ONE universal list of prompts — add as many as you want, for anything. Each prompt has a content type (Text or Image), the product metadata it receives as INPUT, and an OUTPUT destination (product fields, SEO metas, WooCommerce attributes, any custom field — or the product gallery / main image for Image prompts, fully compatible with the product image generator). Text prompts appear in the toolbox and bulk once enabled; apply is unlocked per prompt by its Validated box.', 'dazont-ecom' ); ?>
			</p>
			<?php $dze_inputs = self::input_options(); $dze_metakeys = self::product_meta_keys(); $dze_ri = 0; ?>
			<datalist id="dze-metakeys">
				<?php foreach ( $dze_metakeys as $mk ) : ?><option value="<?php echo esc_attr( $mk ); ?>"></option><?php endforeach; ?>
			</datalist>
			<?php
			// One card per prompt, shut, in two groups: what writes TEXT and what
			// makes IMAGES. A nine-column table of textareas was unreadable, and
			// every prompt was on screen at the same time whether or not it was
			// the one being worked on. The field names are unchanged, so what is
			// saved is exactly what was saved before.
			$dze_rows = self::registry();
			$dze_map  = [];
			foreach ( $dze_rows as $ri => $r ) {
				$dze_map[ ( ( $r['type'] ?? 'text' ) === 'image' ) ? 'image' : 'text' ][ $ri ] = $r;
			}
			$dze_groups = [
				'text'  => __( 'Text prompts', 'dazont-ecom' ),
				'image' => __( 'Image prompts', 'dazont-ecom' ),
			];
			?>
			<div id="dze-pr">
			<?php foreach ( $dze_groups as $dze_g => $dze_glabel ) : ?>
				<h3 class="dze-pr-grouphead"><?php echo esc_html( $dze_glabel ); ?>
					<span class="description">(<?php echo (int) count( $dze_map[ $dze_g ] ?? [] ); ?>)</span>
				</h3>
				<div class="dze-prlist" data-group="<?php echo esc_attr( $dze_g ); ?>">
				<?php foreach ( (array) ( $dze_map[ $dze_g ] ?? [] ) as $dze_ri => $r ) :
					$sel_in = (array) ( $r['inputs'] ?? [] ); ?>
					<div class="dze-prb dze-pr-row" id="dze-pr-row-<?php echo esc_attr( (string) $r['id'] ); ?>">
						<div class="dze-prb-head">
							<label class="dze-prb-on" title="<?php esc_attr_e( 'Use this prompt — saved the moment you tick it', 'dazont-ecom' ); ?>">
								<input type="checkbox" class="dze-prb-live" data-id="<?php echo esc_attr( (string) $r['id'] ); ?>" name="<?php echo esc_attr( $opt ); ?>[pr_on][<?php echo (int) $dze_ri; ?>]" value="1" <?php checked( ! empty( $r['enabled'] ) ); ?> />
							</label>
							<input type="text" class="dze-prb-name" name="<?php echo esc_attr( $opt ); ?>[pr_name][<?php echo (int) $dze_ri; ?>]" value="<?php echo esc_attr( $r['name'] ); ?>" />
							<input type="hidden" name="<?php echo esc_attr( $opt ); ?>[pr_id][<?php echo (int) $dze_ri; ?>]" value="<?php echo esc_attr( $r['id'] ); ?>" />
							<span class="dze-prb-dest"><?php echo esc_html( self::output_options( ( $r['type'] ?? 'text' ) === 'image' ? 'image' : 'text' )[ $r['output'] ?? '' ] ?? ( $r['output'] ?? '' ) ); ?></span>
							<span class="dze-prb-flags">
								<?php if ( ! empty( $r['valid'] ) ) : ?><span class="dze-prb-ok" title="<?php esc_attr_e( 'Validated: bulk may apply it', 'dazont-ecom' ); ?>">✓</span><?php endif; ?>
							</span>
							<button type="button" class="dze-prb-toggle" aria-expanded="false"><?php esc_html_e( 'Edit', 'dazont-ecom' ); ?> <span class="dze-prb-caret">▸</span></button>
							<button type="button" class="dze-pr-del" title="<?php esc_attr_e( 'Remove this prompt', 'dazont-ecom' ); ?>">&#10005;</button>
						</div>
						<div class="dze-prb-body" style="display:none;">
							<p class="dze-prb-line">
								<label><span><?php esc_html_e( 'Type', 'dazont-ecom' ); ?></span>
									<select name="<?php echo esc_attr( $opt ); ?>[pr_type][<?php echo (int) $dze_ri; ?>]" class="dze-pr-type">
										<option value="text" <?php selected( 'text', $r['type'] ?? 'text' ); ?>><?php esc_html_e( 'Text', 'dazont-ecom' ); ?></option>
										<option value="image" <?php selected( 'image', $r['type'] ?? 'text' ); ?>><?php esc_html_e( 'Image', 'dazont-ecom' ); ?></option>
									</select>
								</label>
								<label><span><?php esc_html_e( 'Writes to', 'dazont-ecom' ); ?></span>
									<select name="<?php echo esc_attr( $opt ); ?>[pr_output][<?php echo (int) $dze_ri; ?>]" class="dze-pr-output">
										<?php foreach ( self::output_options( 'text' ) as $ok => $ol ) : ?>
											<option class="dze-o-text" value="<?php echo esc_attr( $ok ); ?>" <?php selected( $ok, $r['output'] ?? '' ); ?>><?php echo esc_html( $ol ); ?></option>
										<?php endforeach; ?>
										<?php foreach ( self::output_options( 'image' ) as $ok => $ol ) : ?>
											<option class="dze-o-image" value="<?php echo esc_attr( $ok ); ?>" <?php selected( $ok, $r['output'] ?? '' ); ?>><?php echo esc_html( $ol ); ?></option>
										<?php endforeach; ?>
									</select>
								</label>
								<input type="text" name="<?php echo esc_attr( $opt ); ?>[pr_metakey][<?php echo (int) $dze_ri; ?>]" value="<?php echo esc_attr( $r['meta_key'] ?? '' ); ?>" placeholder="_meta_key" list="dze-metakeys" class="dze-pr-metakey" style="<?php echo ( 'meta' === ( $r['output'] ?? '' ) ) ? '' : 'display:none;'; ?>" />
								<label class="dze-prb-tk"><span><?php esc_html_e( 'Max length', 'dazont-ecom' ); ?></span>
									<input type="number" name="<?php echo esc_attr( $opt ); ?>[pr_tokens][<?php echo (int) $dze_ri; ?>]" value="<?php echo esc_attr( (int) ( $r['tokens'] ?: 400 ) ); ?>" min="50" class="dze-pr-tokens" />
								</label>
								<label class="dze-prb-valid"><input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[pr_valid][<?php echo (int) $dze_ri; ?>]" value="1" <?php checked( ! empty( $r['valid'] ) ); ?> />
									<span><?php esc_html_e( 'Validated', 'dazont-ecom' ); ?></span>
								</label>
							</p>
							<textarea name="<?php echo esc_attr( $opt ); ?>[pr_prompt][<?php echo (int) $dze_ri; ?>]" rows="8" class="large-text code dze-pr-prompt"><?php echo esc_textarea( $r['prompt'] ); ?></textarea>
							<p class="dze-prb-line">
								<?php if ( '' !== self::default_prompt_for( (string) $r['id'] ) ) : ?>
									<button type="button" class="button-link dze-pr-restore" data-id="<?php echo esc_attr( $r['id'] ); ?>" title="<?php esc_attr_e( 'Put the shipped default prompt back in this field (save to keep it)', 'dazont-ecom' ); ?>">&#8634; <?php esc_html_e( 'Restore default', 'dazont-ecom' ); ?></button>
								<?php endif; ?>
							</p>
							<details class="dze-pr-inputs">
								<summary><?php printf( /* translators: %d: count */ esc_html__( 'Product data sent with it (%d)', 'dazont-ecom' ), count( $sel_in ) ); ?></summary>
								<?php foreach ( $dze_inputs as $ik => $il ) : ?>
									<label><input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[pr_inputs][<?php echo (int) $dze_ri; ?>][]" value="<?php echo esc_attr( $ik ); ?>" <?php checked( in_array( $ik, $sel_in, true ) ); ?> /> <?php echo esc_html( $il ); ?></label>
								<?php endforeach; ?>
								<input type="text" name="<?php echo esc_attr( $opt ); ?>[pr_inmeta][<?php echo (int) $dze_ri; ?>]" value="<?php echo esc_attr( $r['inputs_meta'] ?? '' ); ?>" placeholder="<?php esc_attr_e( 'custom meta keys, comma separated', 'dazont-ecom' ); ?>" class="dze-pr-inmeta" />
								<span class="dze-pr-metapickrow">
									<select class="dze-pr-metapick"><option value=""><?php esc_html_e( '— browse meta keys —', 'dazont-ecom' ); ?></option><?php foreach ( $dze_metakeys as $mk ) : ?><option value="<?php echo esc_attr( $mk ); ?>"><?php echo esc_html( $mk ); ?></option><?php endforeach; ?></select>
									<button type="button" class="button button-small dze-pr-metaadd" title="<?php esc_attr_e( 'Add this key as an input', 'dazont-ecom' ); ?>">&#43;</button>
								</span>
							</details>
							<details class="dze-pr-inputs">
								<summary><?php esc_html_e( 'Pair this text with one of the product photographs', 'dazont-ecom' ); ?></summary>
								<p class="description" style="max-width:820px;">
									<?php esc_html_e( 'Leave empty and this block is written from the product data alone. Fill in a meta key and the plugin LOOKS at the photographs of the product, picks the one showing a real particularity, writes this block about what is visible there, and stores the chosen image id in that key so your theme can show the text and its photograph together.', 'dazont-ecom' ); ?>
								</p>
								<input type="text" name="<?php echo esc_attr( $opt ); ?>[pr_imgmeta][<?php echo (int) $dze_ri; ?>]" value="<?php echo esc_attr( $r['img_meta'] ?? '' ); ?>" placeholder="<?php esc_attr_e( 'e.g. _dze_bloc1_image', 'dazont-ecom' ); ?>" list="dze-metakeys" class="dze-pr-imgmeta" />
							</details>
						</div>
					</div>
				<?php endforeach; ?>
				</div>
			<?php endforeach; ?>
			</div>
			<?php $dze_ri = count( $dze_rows ); ?>
			<?php $dze_missing = self::missing_defaults(); ?>
			<?php if ( $dze_missing ) : ?>
				<p class="dze-pr-missing">
					<span class="description"><?php esc_html_e( 'Shipped prompts this install does not have:', 'dazont-ecom' ); ?></span>
					<select id="dze-pr-defpick">
						<?php foreach ( $dze_missing as $mid => $mname ) : ?>
							<option value="<?php echo esc_attr( $mid ); ?>"><?php echo esc_html( $mname ); ?></option>
						<?php endforeach; ?>
					</select>
					<button type="button" class="button" id="dze-pr-defadd">&#43; <?php esc_html_e( 'Add it', 'dazont-ecom' ); ?></button>
					<span id="dze-pr-defstate" class="description"></span>
				</p>
			<?php endif; ?>
			<p>
				<button type="button" class="button dze-pt-add" id="dze-pr-add" data-next="<?php echo (int) $dze_ri; ?>">&#43; <?php esc_html_e( 'Add prompt', 'dazont-ecom' ); ?></button>
				<button type="button" class="button" id="dze-pr-reset" style="margin-left:8px;">&#8634; <?php esc_html_e( 'Restore default prompts', 'dazont-ecom' ); ?></button>
			</p>

			<h3 class="dze-set-sub"><?php esc_html_e( 'Pairing a text block with one of the product photographs', 'dazont-ecom' ); ?></h3>
			<p class="description" style="max-width:900px;">
				<?php esc_html_e( 'This is NOT about generating images. A text prompt can be given an image meta key (in its card, under "Pair this text with one of the product photographs"): the plugin then looks at the photographs the product already has, picks the one that block should be displayed next to, and writes the block about what is visible in it. These are the rules it picks by. Nothing here runs if no prompt carries such a key.', 'dazont-ecom' ); ?>
			</p>
			<textarea id="dze-ct-feature-prompt" name="<?php echo esc_attr( $opt ); ?>[feature_prompt]" rows="4" class="large-text code"><?php echo esc_textarea( self::feature_prompt() ); ?></textarea>
			<p class="description">
				<?php esc_html_e( 'Empty = shipped default (shown greyed).', 'dazont-ecom' ); ?>
				<button type="button" class="button-link" id="dze-ct-feature-restore">&#8634; <?php esc_html_e( 'Restore default', 'dazont-ecom' ); ?></button>
			</p>
			<script type="text/template" id="dze-pr-rowtpl">
				<div class="dze-prb dze-pr-row is-open">
					<div class="dze-prb-head">
						<label class="dze-prb-on"><input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[pr_on][__I__]" value="1" checked /></label>
						<input type="text" class="dze-prb-name" name="<?php echo esc_attr( $opt ); ?>[pr_name][__I__]" value="" placeholder="<?php esc_attr_e( 'New prompt…', 'dazont-ecom' ); ?>" />
						<input type="hidden" name="<?php echo esc_attr( $opt ); ?>[pr_id][__I__]" value="" />
						<span class="dze-prb-dest"></span>
						<span class="dze-prb-flags"></span>
						<button type="button" class="dze-prb-toggle" aria-expanded="true"><?php esc_html_e( 'Edit', 'dazont-ecom' ); ?> <span class="dze-prb-caret">▾</span></button>
						<button type="button" class="dze-pr-del" title="<?php esc_attr_e( 'Remove this prompt', 'dazont-ecom' ); ?>">&#10005;</button>
					</div>
					<div class="dze-prb-body">
						<p class="dze-prb-line">
							<label><span><?php esc_html_e( 'Type', 'dazont-ecom' ); ?></span>
								<select name="<?php echo esc_attr( $opt ); ?>[pr_type][__I__]" class="dze-pr-type">
									<option value="text"><?php esc_html_e( 'Text', 'dazont-ecom' ); ?></option>
									<option value="image"><?php esc_html_e( 'Image', 'dazont-ecom' ); ?></option>
								</select>
							</label>
							<label><span><?php esc_html_e( 'Writes to', 'dazont-ecom' ); ?></span>
								<select name="<?php echo esc_attr( $opt ); ?>[pr_output][__I__]" class="dze-pr-output">
									<?php foreach ( self::output_options( 'text' ) as $ok => $ol ) : ?>
										<option class="dze-o-text" value="<?php echo esc_attr( $ok ); ?>"><?php echo esc_html( $ol ); ?></option>
									<?php endforeach; ?>
									<?php foreach ( self::output_options( 'image' ) as $ok => $ol ) : ?>
										<option class="dze-o-image" value="<?php echo esc_attr( $ok ); ?>"><?php echo esc_html( $ol ); ?></option>
									<?php endforeach; ?>
								</select>
							</label>
							<input type="text" name="<?php echo esc_attr( $opt ); ?>[pr_metakey][__I__]" value="" placeholder="_meta_key" list="dze-metakeys" class="dze-pr-metakey" style="display:none;" />
							<label class="dze-prb-tk"><span><?php esc_html_e( 'Max length', 'dazont-ecom' ); ?></span>
								<input type="number" name="<?php echo esc_attr( $opt ); ?>[pr_tokens][__I__]" value="400" min="50" class="dze-pr-tokens" />
							</label>
							<label class="dze-prb-valid"><input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[pr_valid][__I__]" value="1" />
								<span><?php esc_html_e( 'Validated', 'dazont-ecom' ); ?></span>
							</label>
						</p>
						<textarea name="<?php echo esc_attr( $opt ); ?>[pr_prompt][__I__]" rows="8" class="large-text code dze-pr-prompt"></textarea>
						<details class="dze-pr-inputs">
							<summary><?php esc_html_e( 'Product data sent with it', 'dazont-ecom' ); ?></summary>
							<?php foreach ( $dze_inputs as $ik => $il ) : ?>
								<label><input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[pr_inputs][__I__][]" value="<?php echo esc_attr( $ik ); ?>" <?php checked( in_array( $ik, [ 'title', 'description' ], true ) ); ?> /> <?php echo esc_html( $il ); ?></label>
							<?php endforeach; ?>
							<input type="text" name="<?php echo esc_attr( $opt ); ?>[pr_inmeta][__I__]" value="" placeholder="<?php esc_attr_e( 'custom meta keys, comma separated', 'dazont-ecom' ); ?>" class="dze-pr-inmeta" />
							<span class="dze-pr-metapickrow">
								<select class="dze-pr-metapick"><option value=""><?php esc_html_e( '— browse meta keys —', 'dazont-ecom' ); ?></option><?php foreach ( $dze_metakeys as $mk ) : ?><option value="<?php echo esc_attr( $mk ); ?>"><?php echo esc_html( $mk ); ?></option><?php endforeach; ?></select>
								<button type="button" class="button button-small dze-pr-metaadd">&#43;</button>
							</span>
								<details class="dze-pr-inputs">
							<summary><?php esc_html_e( 'Pair this text with one of the product photographs', 'dazont-ecom' ); ?></summary>
							<input type="text" name="<?php echo esc_attr( $opt ); ?>[pr_imgmeta][__I__]" value="" placeholder="<?php esc_attr_e( 'e.g. _dze_bloc1_image', 'dazont-ecom' ); ?>" list="dze-metakeys" class="dze-pr-imgmeta" />
						</details>
					</div>
				</div>
			</script>
			<script>
			jQuery( function ( $ ) {
				function syncRow( $row ) {
					var type = $row.find( '.dze-pr-type' ).val();
					var $out = $row.find( '.dze-pr-output' );
					$out.find( 'option' ).each( function () {
						var isImg = $( this ).hasClass( 'dze-o-image' );
						$( this ).prop( 'hidden', type === 'image' ? ! isImg : isImg );
					} );
					var cur = $out.find( 'option:selected' );
					if ( cur.prop( 'hidden' ) ) {
						$out.val( $out.find( 'option' ).not( '[hidden]' ).first().val() );
					}
					$row.find( '.dze-pr-metakey' ).toggle( $out.val() === 'meta' );
					$row.find( '.dze-pr-tokens' ).prop( 'disabled', type === 'image' );
				}
				$( '#dze-pr .dze-prb' ).each( function () { syncRow( $( this ) ); } );
				$( document ).on( 'change', '.dze-pr-type, .dze-pr-output', function () { syncRow( $( this ).closest( '.dze-prb' ) ); } );
				// One card open at a time: opening one shuts the others, because
				// the point of shut cards is to have one prompt in front of you.
				$( document ).on( 'click', '.dze-prb-toggle', function () {
					var $b = $( this ).closest( '.dze-prb' );
					var open = ! $b.hasClass( 'is-open' );
					$( '#dze-pr .dze-prb' ).removeClass( 'is-open' ).find( '.dze-prb-body' ).hide()
						.end().find( '.dze-prb-toggle' ).attr( 'aria-expanded', 'false' ).find( '.dze-prb-caret' ).text( '▸' );
					if ( open ) {
						$b.addClass( 'is-open' ).find( '.dze-prb-body' ).show();
						$b.find( '.dze-prb-toggle' ).attr( 'aria-expanded', 'true' ).find( '.dze-prb-caret' ).text( '▾' );
					}
				} );
				$( '#dze-pr-add' ).on( 'click', function () {
					var $b = $( this ), i = parseInt( $b.data( 'next' ), 10 ) || 0;
					$b.data( 'next', i + 1 );
					var html = $( '#dze-pr-rowtpl' ).html().replace( /__I__/g, String( i ) );
					var $row = $( html );
					// A new prompt is a text prompt until it says otherwise.
					$( '#dze-pr .dze-prlist[data-group="text"]' ).append( $row );
					syncRow( $row );
					$( 'html, body' ).animate( { scrollTop: $row.offset().top - 60 }, 200 );
				} );
				$( document ).on( 'click', '.dze-pr-del', function () {
					if ( ! window.confirm( '<?php echo esc_js( __( 'Remove this prompt? Nothing already written on your products is affected.', 'dazont-ecom' ) ); ?>' ) ) { return; }
					$( this ).closest( '.dze-prb' ).remove();
				} );
				$( document ).on( 'click', '.dze-pr-metaadd', function () {
					var $wrap = $( this ).closest( 'details' );
					var key = $wrap.find( '.dze-pr-metapick' ).val();
					if ( ! key ) { return; }
					var $in = $wrap.find( '.dze-pr-inmeta' );
					var cur = ( $in.val() || '' ).split( ',' ).map( function ( x ) { return x.trim(); } ).filter( Boolean );
					if ( cur.indexOf( key ) < 0 ) { cur.push( key ); }
					$in.val( cur.join( ', ' ) );
				} );
				// Per-row restore: refill THIS prompt with its shipped default.
				var dzePromptDefaults = <?php echo wp_json_encode( self::default_prompts() ); ?>;
				$( document ).on( 'click', '.dze-pr-restore', function () {
					var d = dzePromptDefaults[ $( this ).data( 'id' ) ];
					if ( ! d ) { return; }
					$( this ).closest( 'td' ).find( '.dze-pr-prompt' ).val( d );
				} );
				$( '#dze-ct-feature-restore' ).on( 'click', function () {
					$( '#dze-ct-feature-prompt' ).val( <?php echo wp_json_encode( self::default_feature_prompt() ); ?> );
				} );
				$( '#dze-ct-quick-restore' ).on( 'click', function () {
					$( '#dze-ct-quick-prompt' ).val( <?php echo wp_json_encode( self::default_quick_prompt() ); ?> );
				} );
				// On or off is one flag: it is written when it is clicked, not
				// when the page happens to be saved.
				// A prompt shipped after this install saved its registry is put
				// back on demand, switched off, ready to be read before use.
				$( '#dze-pr-defadd' ).on( 'click', function () {
					var $b = $( this ).prop( 'disabled', true );
					$.post( window.ajaxurl, {
						action: 'dze_content_add_default',
						nonce: '<?php echo esc_js( wp_create_nonce( self::NONCE ) ); ?>',
						id: $( '#dze-pr-defpick' ).val()
					} ).done( function ( r ) {
						if ( r && r.success ) { window.location.reload(); return; }
						$b.prop( 'disabled', false );
						$( '#dze-pr-defstate' ).text( ( r && r.data && r.data.message ) || '' );
					} ).fail( function () { $b.prop( 'disabled', false ); } );
				} );
				$( document ).on( 'change', '.dze-prb-live', function () {
					var $c = $( this ), $card = $c.closest( '.dze-prb' );
					$card.addClass( 'is-saving' );
					$.post( window.ajaxurl, {
						action: 'dze_content_prompt_toggle',
						nonce: '<?php echo esc_js( wp_create_nonce( self::NONCE ) ); ?>',
						id: $c.data( 'id' ),
						on: $c.is( ':checked' ) ? 1 : 0
					} ).done( function ( r ) {
						$card.removeClass( 'is-saving' );
						if ( ! r || ! r.success ) { $c.prop( 'checked', ! $c.is( ':checked' ) ); }
						else { $card.addClass( 'is-saved' ); window.setTimeout( function () { $card.removeClass( 'is-saved' ); }, 900 ); }
					} ).fail( function () {
						$card.removeClass( 'is-saving' );
						$c.prop( 'checked', ! $c.is( ':checked' ) );
					} );
				} );
				// The plain grey plate is just another background: it is drawn,
				// then it lands in the same list as the ones you upload.
				$( '#dze-bd-make' ).on( 'click', function () {
					var $b = $( this ).prop( 'disabled', true );
					var $st = $( '#dze-bd-state' ).text( '…' );
					$.post( window.ajaxurl, {
						action: 'dze_content_backdrop',
						nonce: '<?php echo esc_js( wp_create_nonce( self::NONCE ) ); ?>'
					} ).done( function ( r ) {
						$b.prop( 'disabled', false );
						if ( ! r || ! r.success ) { $st.text( ( r && r.data && r.data.message ) || '' ); return; }
						$st.text( '' );
						var $card = $( '#dze-sc .dze-bgcard' ).last();
						if ( $card.find( '.dze-sc-img' ).val() !== '0' ) {
							$( '#dze-sc-add' ).trigger( 'click' );
							$( '#dze-sc .dze-bgcard' ).last().find( '.dze-sc-pick' ).trigger( 'blur' );
							$card = $( '#dze-sc .dze-bgcard' ).last();
						}
						$card.removeClass( 'is-empty' );
						$card.find( '.dze-sc-img' ).val( r.data.id );
						$card.find( '.dze-sc-thumb' ).html( $( '<img />' ).attr( 'src', r.data.thumb ).attr( 'alt', '' ) );
						$card.find( 'input[name$="[sc_name][]"]' ).val( r.data.name );
						$card.find( '.dze-sc-pick' ).text( <?php echo wp_json_encode( __( 'Replace', 'dazont-ecom' ) ); ?> );
					} ).fail( function () { $b.prop( 'disabled', false ); $st.text( '' ); } );
				} );
				// Restore the shipped default prompts (drops customs — confirmed first).
				$( '#dze-pr-reset' ).on( 'click', function () {
					if ( ! window.confirm( '<?php echo esc_js( __( 'Restore the shipped default prompts? Custom prompt rows will be removed and validation flags reset. Your generated content is not affected.', 'dazont-ecom' ) ); ?>' ) ) { return; }
					var $b = $( this ).prop( 'disabled', true );
					$.post( window.ajaxurl, { action: 'dze_content_reset_prompts', nonce: '<?php echo esc_js( wp_create_nonce( self::NONCE ) ); ?>' } )
						.done( function ( res ) {
							if ( res && res.success ) { window.location.reload(); }
							else { $b.prop( 'disabled', false ); window.alert( ( res && res.data && res.data.message ) || '<?php echo esc_js( __( 'Something went wrong.', 'dazont-ecom' ) ); ?>' ); }
						} )
						.fail( function () { $b.prop( 'disabled', false ); window.alert( '<?php echo esc_js( __( 'Something went wrong.', 'dazont-ecom' ) ); ?>' ); } );
				} );
				// AJAX save — no page reload. The appended action parameter wins over
				// the hidden "action=update" field when admin-ajax parses the body.
				var $dzeForm = $( '#dze-pr' ).closest( 'form' );
				$dzeForm.on( 'submit', function ( e ) {
					e.preventDefault();
					var $btn = $dzeForm.find( '#submit' ).prop( 'disabled', true );
					var $ok  = $( '#dze-pr-savednote' );
					if ( ! $ok.length ) { $ok = $( '<span id="dze-pr-savednote" style="margin-left:10px;font-weight:600;"></span>' ).insertAfter( $btn ); }
					$ok.css( 'color', '#646970' ).text( '…' );
					$.post(
						window.ajaxurl,
						$dzeForm.serialize() + '&action=dze_content_save_settings&nonce=' + encodeURIComponent( '<?php echo esc_js( wp_create_nonce( self::NONCE ) ); ?>' )
					).done( function ( res ) {
						$btn.prop( 'disabled', false );
						if ( res && res.success ) {
							$ok.css( 'color', '#0a7040' ).text( '<?php echo esc_js( __( 'Saved ✓', 'dazont-ecom' ) ); ?>' );
							setTimeout( function () { $ok.text( '' ); }, 2500 );
						} else {
							$ok.css( 'color', '#b32d2e' ).text( ( res && res.data && res.data.message ) || '<?php echo esc_js( __( 'Save failed.', 'dazont-ecom' ) ); ?>' );
						}
					} ).fail( function () {
						// Network/AJAX failure: fall back to the classic full-page save.
						$btn.prop( 'disabled', false );
						$dzeForm.off( 'submit' );
						$dzeForm.trigger( 'submit' );
					} );
				} );
			} );
			</script>

			</details>

			</details>

			<details class="dze-set" id="dze-set-price">
			<summary><?php esc_html_e( 'Price table — cost × multiplier, set once', 'dazont-ecom' ); ?></summary>
			<p class="description"><?php esc_html_e( 'The current/import price is treated as the cost (COGS, also written to WooCommerce\'s Cost of Goods field); the matching multiplier sets the regular price. Use 0 as the upper bound of the last range for "no limit".', 'dazont-ecom' ); ?></p>
			<table class="widefat striped dze-price-table" id="dze-pt">
				<thead>
					<tr><th><?php esc_html_e( 'Cost from', 'dazont-ecom' ); ?></th><th><?php esc_html_e( 'Cost to (0 = ∞)', 'dazont-ecom' ); ?></th><th><?php esc_html_e( 'Multiplier', 'dazont-ecom' ); ?></th><th style="width:40px;"></th></tr>
				</thead>
				<tbody>
					<?php foreach ( self::price_table() as $row ) : ?>
						<tr class="dze-pt-row">
							<td><span class="dze-pt-cur">$</span> <input type="number" step="0.01" name="<?php echo esc_attr( $opt ); ?>[pt_min][]" value="<?php echo esc_attr( $row['min'] ); ?>" /></td>
							<td><span class="dze-pt-cur">$</span> <input type="number" step="0.01" name="<?php echo esc_attr( $opt ); ?>[pt_max][]" value="<?php echo esc_attr( $row['max'] ); ?>" /></td>
							<td><span class="dze-pt-cur">×</span> <input type="number" step="0.01" name="<?php echo esc_attr( $opt ); ?>[pt_mult][]" value="<?php echo esc_attr( $row['mult'] ); ?>" /></td>
							<td><button type="button" class="button dze-pt-del" title="<?php esc_attr_e( 'Remove this range', 'dazont-ecom' ); ?>">&#10005;</button></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<p><button type="button" class="button dze-pt-add" id="dze-pt-add">&#43; <?php esc_html_e( 'Add price range', 'dazont-ecom' ); ?></button></p>
			<script>
			jQuery( function ( $ ) {
				$( '#dze-pt-add' ).on( 'click', function () {
					var $rows = $( '#dze-pt tbody tr.dze-pt-row' );
					var $row  = $rows.last().clone();
					// New range starts where the previous one ends.
					var prevMax = $rows.last().find( 'input' ).eq( 1 ).val() || '';
					$row.find( 'input' ).val( '' );
					$row.find( 'input' ).eq( 0 ).val( prevMax );
					$( '#dze-pt tbody' ).append( $row );
				} );
				$( document ).on( 'click', '.dze-pt-del', function () {
					var $rows = $( '#dze-pt tbody tr.dze-pt-row' );
					if ( $rows.length > 1 ) { $( this ).closest( 'tr' ).remove(); }
					else { $( this ).closest( 'tr' ).find( 'input' ).val( '' ); }
				} );
			} );
			</script>

			</details>

			<?php submit_button(); ?>
		</form>
		</div>
		<?php
	}

	// =========================================================================
	// Product-page side box
	// =========================================================================

	// The product-page entry point now lives in the shared "Dazont Ecom" hub
	// box (DZE_Modules::render_hub) — this module only contributes its button.

	// =========================================================================
	// Bulk: products-list action + bulk screen
	// =========================================================================

	public function register_bulk_action( array $actions ): array {
		$actions[ self::BULK_ACTION ] = __( 'Dazont: send to Products AI bulk', 'dazont-ecom' );
		return $actions;
	}

	/**
	 * The working list of the bulk screen.
	 *
	 * User meta, not a transient. A transient expires — an hour was enough to
	 * lose a list halfway through a session — and on a shop with a persistent
	 * object cache a write can be served stale, which is why products taken out
	 * of the list kept coming back on the next page load. Meta is durable, per
	 * user, and reads what was just written.
	 */
	private const LIST_META = '_dze_content_bulk';

	/** Ceiling on one paste: past this the screen itself becomes unusable. */
	private const PASTE_MAX = 1000;

	public static function bulk_list(): array {
		$uid  = get_current_user_id();
		$list = get_user_meta( $uid, self::LIST_META, true );
		if ( ! is_array( $list ) ) {
			// A list queued before this moved out of transients.
			$list = (array) get_transient( 'dze_content_bulk_' . $uid );
		}
		return array_values( array_filter( array_map( 'intval', $list ) ) );
	}

	public static function set_bulk_list( array $ids ): void {
		$uid = get_current_user_id();
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
		if ( $ids ) {
			update_user_meta( $uid, self::LIST_META, $ids );
		} else {
			delete_user_meta( $uid, self::LIST_META );
		}
		delete_transient( 'dze_content_bulk_' . $uid );
	}

	public function handle_bulk_action( string $redirect, string $action, array $ids ): string {
		if ( self::BULK_ACTION !== $action || empty( $ids ) ) {
			return $redirect;
		}
		self::set_bulk_list( $ids );
		return add_query_arg( [ 'post_type' => 'product', 'page' => self::BULK_SLUG ], admin_url( 'edit.php' ) );
	}

	/**
	 * A "Content" column on the products list.
	 *
	 * It answers the two questions you actually have while scrolling a
	 * catalogue: how many photographs does this product have — the thing that
	 * decides whether it can carry a branding block at all — and is there
	 * content sitting here waiting for a decision. The chip opens the toolbox
	 * on the spot: no page load to write one description.
	 */
	public function list_column( array $cols ): array {
		$out = [];
		foreach ( $cols as $k => $v ) {
			$out[ $k ] = $v;
			if ( 'name' === $k ) {
				$out['dze_content'] = __( 'Content', 'dazont-ecom' );
			}
		}
		if ( ! isset( $out['dze_content'] ) ) {
			$out['dze_content'] = __( 'Content', 'dazont-ecom' );
		}
		return $out;
	}

	/** O(1) per row: two meta reads, both already primed by the list table. */
	public function list_cell( string $col, int $pid ): void {
		if ( 'dze_content' !== $col ) {
			return;
		}
		$gallery = array_filter( array_map( 'absint', explode( ',', (string) get_post_meta( $pid, '_product_image_gallery', true ) ) ) );
		$photos  = count( $gallery ) + ( get_post_thumbnail_id( $pid ) ? 1 : 0 );
		$waiting = (bool) get_post_meta( $pid, self::META_PENDING, true );

		printf(
			'<button type="button" class="dze-cc-open dze-content-open" data-id="%1$d" title="%2$s">'
				. '<span class="dze-caret">✎</span> %3$s'
				. '<span class="dze-content-photos%4$s">%5$s</span>%6$s</button>',
			(int) $pid,
			esc_attr__( 'Write this product: texts, price, images.', 'dazont-ecom' ),
			esc_html__( 'Content', 'dazont-ecom' ),
			$photos < 2 ? ' is-thin' : '',
			esc_html( sprintf( /* translators: %s: number of photographs */ _n( '%s photo', '%s photos', $photos, 'dazont-ecom' ), number_format_i18n( $photos ) ) ),
			$waiting ? '<span class="dze-content-waiting">' . esc_html__( 'to review', 'dazont-ecom' ) . '</span>' : ''
		);
	}

	public function register_bulk_page(): void {
		// The count rides on the menu label, the way WordPress shows comments
		// awaiting moderation: you should not have to open a screen to learn
		// that something is waiting on it.
		$waiting = self::pending_count();
		$label   = __( 'Products AI bulk', 'dazont-ecom' );
		$menu    = $waiting
			? $label . ' <span class="update-plugins count-' . (int) $waiting . '"><span class="plugin-count">'
				. esc_html( number_format_i18n( $waiting ) ) . '</span></span>'
			: $label;
		add_submenu_page(
			'edit.php?post_type=product',
			$label,
			$menu,
			'edit_products',
			self::BULK_SLUG,
			[ $this, 'render_bulk_page' ]
		);
	}

	/** Products queued for the bulk screen (from the last bulk action). */
	/**
	 * What this screen is showing: 'selection', 'pending' or 'empty'.
	 *
	 * An empty screen sitting next to a notice announcing that three products
	 * are waiting is a design failure: the screen's job is to show the work. So
	 * when the selection is empty and something IS waiting, the waiting is what
	 * you get, without a link to click.
	 */
	private function bulk_mode(): string {
		static $mode = null;
		if ( null !== $mode ) {
			return $mode;
		}
		if ( ! empty( $_GET['dze_pending'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view switch.
			$mode = 'pending';
		} elseif ( self::bulk_list() ) {
			$mode = 'selection';
		} elseif ( self::pending_count() ) {
			$mode = 'pending';
		} else {
			$mode = 'empty';
		}
		return $mode;
	}

	private function bulk_products(): array {
		if ( null !== $this->bulk_products_cache ) {
			return $this->bulk_products_cache; // asked for twice per render.
		}
		$ids = 'pending' === $this->bulk_mode() ? self::pending_ids() : self::bulk_list();
		$ids = array_values( array_filter( array_map( 'intval', (array) $ids ) ) );
		// Four hundred products pasted from a spreadsheet is a normal list here,
		// and one query per row is not an option: posts and their meta are read
		// in two queries, then every wc_get_product() below is served from cache.
		if ( $ids ) {
			_prime_post_caches( $ids, false, true );
		}
		// Same again for the photographs, before any image URL is asked for.
		$thumbs = [];
		foreach ( $ids as $pid ) {
			$t = (int) get_post_thumbnail_id( $pid );
			if ( $t ) {
				$thumbs[ $pid ] = $t;
			}
		}
		if ( $thumbs ) {
			_prime_post_caches( array_values( array_unique( $thumbs ) ), false, true );
		}
		$out = [];
		foreach ( $ids as $pid ) {
			$product = wc_get_product( $pid );
			if ( ! $product instanceof WC_Product ) {
				continue;
			}
			$thumb_id = (int) ( $thumbs[ $pid ] ?? 0 );
			$out[] = [
				'id'    => $pid,
				'title' => $product->get_name(),
				'edit'  => get_edit_post_link( $pid, '' ),
				'thumb' => $thumb_id ? (string) wp_get_attachment_image_url( $thumb_id, 'medium' ) : (string) wc_placeholder_img_src(),
				'full'  => $thumb_id ? (string) wp_get_attachment_image_url( $thumb_id, 'full' ) : '',
				'cost'  => self::product_cost( $product ),
			];
		}
		$this->bulk_products_cache = $out;
		return $out;
	}

	/** Built once per request: the page renders it and the payload reads it. */
	private ?array $bulk_products_cache = null;

	/**
	 * The waiting content of every product currently listed, keyed by id.
	 * Only the products on screen: the notice handles the rest.
	 */
	private function pending_payload(): array {
		$out = [];
		foreach ( $this->bulk_products() as $p ) {
			$waiting = self::pending( (int) $p['id'] );
			if ( $waiting ) {
				$out[ (int) $p['id'] ] = $waiting;
			}
		}
		return $out;
	}

	/**
	 * Paste a column of product IDs instead of ticking four hundred boxes.
	 *
	 * A list of products needing attention rarely comes from the WordPress
	 * admin: it comes from a spreadsheet — "every product with a single
	 * photograph", exported once and worked through afterwards. So the screen
	 * accepts that column as it is pasted: commas, spaces, tabs, line breaks, a
	 * leading # — anything that is not a digit is a separator.
	 */
	private function render_bulk_paste(): void {
		?>
		<details class="dze-cb-paste" id="dze-cb-paste"<?php echo self::bulk_list() ? '' : ' open'; ?>>
			<summary><?php esc_html_e( 'Add products by ID', 'dazont-ecom' ); ?></summary>
			<p class="description" style="margin:6px 0;">
				<?php esc_html_e( 'Paste a column of IDs from your spreadsheet. Separators do not matter. IDs already on the list, and anything that is not a product, are reported instead of being swallowed.', 'dazont-ecom' ); ?>
			</p>
			<textarea id="dze-cb-pasteids" rows="4" class="large-text code" placeholder="1024&#10;1025&#10;1031, 1042 1055"></textarea>
			<p>
				<button type="button" class="button button-primary" id="dze-cb-pasteadd"><?php esc_html_e( 'Add to the list', 'dazont-ecom' ); ?></button>
				<label style="margin-left:12px;"><input type="checkbox" id="dze-cb-pastereplace" /> <?php esc_html_e( 'Replace the current list', 'dazont-ecom' ); ?></label>
				<span id="dze-cb-pastestate" class="description" style="margin-left:8px;"></span>
			</p>
		</details>
		<?php
	}

	public function render_bulk_page(): void {
		if ( ! current_user_can( 'edit_products' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'dazont-ecom' ) );
		}
		$products  = $this->bulk_products();
		[ $ok_n, $tot_n ] = self::validated_counts();
		$templates  = self::image_templates();
		$valid_tpls = [];
		foreach ( $templates as $ti => $t ) {
			if ( ! empty( $t['valid'] ) ) {
				$valid_tpls[ $ti ] = $t; // keep ORIGINAL index — ajax_image resolves by it.
			}
		}
		?>
		<div class="wrap dze-wrap dze-admin">
			<h1><?php esc_html_e( 'Products AI bulk', 'dazont-ecom' ); ?></h1>

			<?php
			$dze_mode = $this->bulk_mode();
			// "Other products": the ones NOT already on this screen. Counting
			// the whole shop would announce work that is right in front of you.
			$dze_waiting = 'selection' === $dze_mode
				? count( array_diff( self::pending_ids(), self::bulk_list() ) )
				: 0;
			?>
			<?php if ( 'pending' === $dze_mode ) : ?>
				<div class="notice notice-info"><p>
					<?php esc_html_e( 'These products are holding content you have not accepted or discarded yet.', 'dazont-ecom' ); ?>
					<?php if ( self::bulk_list() ) : ?>
						<a href="<?php echo esc_url( add_query_arg( [ 'page' => self::BULK_SLUG ], admin_url( 'edit.php?post_type=product' ) ) ); ?>"><?php esc_html_e( 'Back to my selection', 'dazont-ecom' ); ?></a>
					<?php else : ?>
						<span class="description"><?php esc_html_e( 'To work on other products, select them on the Products list and use the "Dazont: send to Products AI bulk" action — or paste their IDs below.', 'dazont-ecom' ); ?></span>
					<?php endif; ?>
				</p></div>
			<?php elseif ( $dze_waiting ) : ?>
				<div class="notice notice-info"><p>
					<?php
					printf(
						/* translators: %s: number of products */
						esc_html( _n( '%s other product is holding content waiting for a decision.', '%s other products are holding content waiting for a decision.', $dze_waiting, 'dazont-ecom' ) ),
						esc_html( number_format_i18n( $dze_waiting ) )
					);
					?>
					<a href="<?php echo esc_url( add_query_arg( [ 'page' => self::BULK_SLUG, 'dze_pending' => 1 ], admin_url( 'edit.php?post_type=product' ) ) ); ?>"><?php esc_html_e( 'Show them', 'dazont-ecom' ); ?></a>
				</p></div>
			<?php endif; ?>

			<?php $dze_blockers = self::image_blockers(); ?>
			<?php if ( $dze_blockers ) : ?>
				<div class="notice notice-error"><p><strong><?php esc_html_e( 'Images cannot be generated right now:', 'dazont-ecom' ); ?></strong></p>
				<ul style="margin:0 0 10px 20px;list-style:disc;">
					<?php foreach ( $dze_blockers as $dze_b ) : ?>
						<li><?php echo esc_html( $dze_b['text'] ); ?>
							<a href="<?php echo esc_url( $dze_b['url'] ); ?>"><?php echo esc_html( $dze_b['label'] ); ?></a></li>
					<?php endforeach; ?>
				</ul>
</div>
			<?php endif; ?>

			<?php if ( $ok_n < $tot_n ) : ?>
				<div class="notice notice-warning"><p>
					<?php printf( /* translators: 1: validated, 2: total */ esc_html__( '%1$d/%2$d prompts validated — bulk applies directly, so only validated fields can be selected below.', 'dazont-ecom' ), (int) $ok_n, (int) $tot_n ); ?>
				</p></div>
			<?php endif; ?>

			<?php $this->render_bulk_paste(); ?>

			<?php if ( empty( $products ) ) : ?>
				<p><?php esc_html_e( 'No products queued. Select products on the Products list and pick "Dazont: send to Products AI bulk" in the Bulk actions menu — or paste their IDs above.', 'dazont-ecom' ); ?></p>
				<?php return; ?>
			<?php endif; ?>

			<div class="dze-cb-controls">
				<h2><?php esc_html_e( 'What to generate', 'dazont-ecom' ); ?></h2>

				<!-- Three blocks, one per kind of work, each with its own options
				     next to it: the flat list of checkboxes and floating selects
				     made it impossible to tell what belonged to what. -->
				<div class="dze-cb-block">
					<h3><?php esc_html_e( 'Texts', 'dazont-ecom' ); ?></h3>
					<div class="dze-cb-checks">
						<?php foreach ( self::enabled_fields() as $fid => $f ) : $fok = self::field_validated( $fid ); ?>
							<span class="dze-cb-checkline">
								<label class="dze-cb-check<?php echo $fok ? '' : ' is-locked'; ?>" title="<?php echo $fok ? '' : esc_attr__( 'Prompt not validated — locked for bulk.', 'dazont-ecom' ); ?>">
									<input type="checkbox" class="dze-cb-field" value="<?php echo esc_attr( $fid ); ?>" <?php checked( $fok ); disabled( ! $fok ); ?> />
									<span><?php echo esc_html( $f['label'] ); ?><?php echo $fok ? '' : ' 🔒'; ?></span>
								</label>
								<?php if ( class_exists( 'DZE_Prompts' ) ) { DZE_Prompts::the_button( 'content_' . $fid, '✎' ); } ?>
							</span>
						<?php endforeach; ?>
					</div>
				</div>

				<?php if ( class_exists( 'DZE_Reviews' ) && ( ! class_exists( 'DZE_Modules' ) || DZE_Modules::enabled( 'reviews' ) ) ) : ?>
					<div class="dze-cb-block">
						<h3><?php esc_html_e( 'Reviews', 'dazont-ecom' ); ?></h3>
						<label class="dze-cb-check">
							<input type="checkbox" id="dze-cb-reviews" />
							<span><?php esc_html_e( 'Write customer reviews', 'dazont-ecom' ); ?></span>
						</label>
						<div class="dze-cb-opts">
							<label title="<?php esc_attr_e( 'Leave on "random" to vary the number per product, which is what a real catalogue looks like.', 'dazont-ecom' ); ?>">
								<span><?php esc_html_e( 'How many', 'dazont-ecom' ); ?></span>
								<select id="dze-cb-revn">
									<option value="0"><?php esc_html_e( 'random', 'dazont-ecom' ); ?></option>
									<?php foreach ( [ 1, 2, 3, 4, 5, 6, 8, 10 ] as $dze_rn ) : ?>
										<option value="<?php echo (int) $dze_rn; ?>"><?php echo (int) $dze_rn; ?></option>
									<?php endforeach; ?>
								</select>
							</label>
							<?php if ( class_exists( 'DZE_Prompts' ) ) { DZE_Prompts::the_button( 'reviews', '✎' ); } ?>
						</div>
						<p class="description" style="margin:6px 0 0;">
							<?php esc_html_e( 'They land in the WooCommerce moderation queue, never published straight to the shop.', 'dazont-ecom' ); ?>
						</p>
					</div>
				<?php endif; ?>

				<div class="dze-cb-block">
					<h3><?php esc_html_e( 'Price', 'dazont-ecom' ); ?></h3>
					<label class="dze-cb-check">
						<input type="checkbox" id="dze-cb-price" checked />
						<span><?php esc_html_e( 'Recalculate from the cost', 'dazont-ecom' ); ?></span>
					</label>
				</div>

				<div class="dze-cb-block">
					<h3><?php esc_html_e( 'Images', 'dazont-ecom' ); ?></h3>
					<?php if ( $valid_tpls ) : ?>
						<label class="dze-cb-check<?php echo $dze_blockers ? ' is-locked' : ''; ?>" title="<?php echo $dze_blockers ? esc_attr( $dze_blockers[0]['text'] ) : ''; ?>">
							<input type="checkbox" id="dze-cb-image" <?php disabled( ! empty( $dze_blockers ) ); ?> />
							<span><?php esc_html_e( 'Generate images', 'dazont-ecom' ); ?><?php echo $dze_blockers ? ' 🔒' : ''; ?></span>
						</label>
						<div class="dze-cb-opts">
							<!-- One prompt, plus a + to add a second when a product
							     needs two kinds of shot. A checkbox list of every
							     prompt was noise: most runs use one. -->
							<label><span><?php esc_html_e( 'Prompt', 'dazont-ecom' ); ?></span>
								<span class="dze-tplrows" id="dze-cb-tplrows" data-name="dze-cb-tpl"></span>
							</label>
							<script type="text/template" id="dze-cb-tpltpl">
								<span class="dze-tplrow">
									<select class="dze-cb-tpl">
										<?php foreach ( $valid_tpls as $i => $t ) : ?>
											<option value="<?php echo (int) $i; ?>" data-prompt="<?php echo esc_attr( 'content_' . (string) ( $t['id'] ?? '' ) ); ?>"><?php echo esc_html( $t['name'] ); ?> (<?php echo esc_html( $t['target'] ?? 'gallery' ); ?>)</option>
										<?php endforeach; ?>
									</select>
									<button type="button" class="dze-prompt-peek" data-prompt="<?php echo esc_attr( 'content_' . (string) ( $valid_tpls[0]['id'] ?? '' ) ); ?>" title="<?php esc_attr_e( 'See the instructions sent to the model, and edit them', 'dazont-ecom' ); ?>">&#9998;</button>
									<button type="button" class="button button-small dze-tpl-add" title="<?php esc_attr_e( 'Add another image prompt to this run', 'dazont-ecom' ); ?>">+</button>
									<button type="button" class="button button-small dze-tpl-del" title="<?php esc_attr_e( 'Remove this prompt', 'dazont-ecom' ); ?>">&minus;</button>
								</span>
							</script>
							<?php $dze_bscenes = self::scenes(); ?>
							<?php if ( $dze_bscenes ) : $dze_bdef = self::default_scene(); ?>
								<label title="<?php esc_attr_e( 'The fixed support or background sent as a second image, so the whole run comes back in the same setting.', 'dazont-ecom' ); ?>"><span><?php esc_html_e( 'Scene', 'dazont-ecom' ); ?></span>
									<select id="dze-cb-scene">
										<option value="-1" <?php selected( -1, $dze_bdef ); ?>><?php esc_html_e( 'No scene', 'dazont-ecom' ); ?></option>
										<?php foreach ( $dze_bscenes as $dze_si => $dze_sc ) : ?>
											<option value="<?php echo (int) $dze_si; ?>" <?php selected( $dze_si, $dze_bdef ); ?>><?php echo esc_html( $dze_sc['name'] ); ?></option>
										<?php endforeach; ?>
									</select>
								</label>
							<?php endif; ?>
							<label title="<?php esc_attr_e( 'Attempts per prompt and per product — you keep the good ones at review time.', 'dazont-ecom' ); ?>"><span><?php esc_html_e( 'Attempts', 'dazont-ecom' ); ?></span>
								<select id="dze-cb-imgn">
									<?php foreach ( [ 1, 2, 3, 4 ] as $dze_n ) : ?>
										<option value="<?php echo (int) $dze_n; ?>">× <?php echo (int) $dze_n; ?></option>
									<?php endforeach; ?>
								</select>
							</label>
							<label title="<?php esc_attr_e( 'How many products are worked on at the same time. Higher is faster; too high and your own server, or the provider, starts refusing requests.', 'dazont-ecom' ); ?>"><span><?php esc_html_e( 'In parallel', 'dazont-ecom' ); ?></span>
								<select id="dze-cb-par">
									<?php foreach ( [ 1, 2, 3, 4, 6 ] as $dze_p ) : ?>
										<option value="<?php echo (int) $dze_p; ?>" <?php selected( 3, $dze_p ); ?>><?php echo (int) $dze_p; ?> <?php echo 1 === $dze_p ? esc_html__( 'product', 'dazont-ecom' ) : esc_html__( 'products', 'dazont-ecom' ); ?></option>
									<?php endforeach; ?>
								</select>
							</label>
						</div>
					<?php else : ?>
						<p class="description"><?php esc_html_e( 'No validated image prompt yet — validate one in Settings → Product content to enable images in bulk.', 'dazont-ecom' ); ?></p>
					<?php endif; ?>
				</div>

				<div class="dze-cb-block dze-cb-mode">
					<h3><?php esc_html_e( 'Before writing to the shop', 'dazont-ecom' ); ?></h3>
					<label class="dze-cb-check"><input type="radio" name="dze-cb-mode" value="review" checked />
						<span><?php esc_html_e( 'Review, then apply what I keep', 'dazont-ecom' ); ?></span></label>
					<label class="dze-cb-check"><input type="radio" name="dze-cb-mode" value="direct" />
						<span><?php esc_html_e( 'Apply immediately, no confirmation', 'dazont-ecom' ); ?></span></label>
				</div>

				<div class="dze-cb-block">
					<h3><?php esc_html_e( 'Products already written', 'dazont-ecom' ); ?></h3>
					<!-- Off by default: content already generated and not yet
					     decided on is work already paid for. A run that quietly
					     wrote over it would charge twice and destroy the first
					     result. Redoing one product is what its ↻ is for. -->
					<label class="dze-cb-check">
						<input type="checkbox" id="dze-cb-force" />
						<span><?php esc_html_e( 'Write them again too, replacing what is waiting for a decision', 'dazont-ecom' ); ?></span>
					</label>
				</div>

				<p class="dze-cb-actions">
					<button type="button" class="button button-primary button-hero" id="dze-cb-start" <?php disabled( 0 === $ok_n && empty( $valid_tpls ) ); ?>><?php esc_html_e( 'Start bulk generation', 'dazont-ecom' ); ?></button>
					<button type="button" class="button" id="dze-cb-stop" style="display:none;"><?php esc_html_e( 'Stop', 'dazont-ecom' ); ?></button>
				</p>
				<p id="dze-cb-progress" class="description"></p>
			</div>

			<p class="dze-cb-listbar">
				<span id="dze-cb-selcount" class="description"></span>
				<button type="button" class="button button-primary" id="dze-cb-applyall" style="display:none;"><?php esc_html_e( 'Apply all', 'dazont-ecom' ); ?></button>
				<button type="button" class="button" id="dze-cb-applysel" style="display:none;"><?php esc_html_e( 'Apply only selected', 'dazont-ecom' ); ?></button>
				<span class="dze-cb-barsep"></span>
				<button type="button" class="button button-small" id="dze-cb-unqueue" style="display:none;"><?php esc_html_e( 'Remove from the list', 'dazont-ecom' ); ?></button>
				<button type="button" class="button-link" id="dze-cb-clearlist" style="color:#b32d2e;"><?php esc_html_e( 'Empty the whole list', 'dazont-ecom' ); ?></button>
			</p>

			<!-- Pinned to the bottom of the window while a run is on: on a list of
			     thirty products the progress must stay in sight wherever you have
			     scrolled to. -->
			<div id="dze-cb-sticky" style="display:none;">
				<div class="dze-cb-bar"><div class="dze-cb-fill"></div></div>
				<div class="dze-cb-stickyrow">
					<strong id="dze-cb-stickypct">0%</strong>
					<span id="dze-cb-stickytext"></span>
					<button type="button" class="button button-small" id="dze-cb-stickystop"><?php esc_html_e( 'Stop', 'dazont-ecom' ); ?></button>
				</div>
			</div>

			<table class="dze-cb-table">
				<tr>
					<th style="width:28px;"><input type="checkbox" id="dze-cb-all" title="<?php esc_attr_e( 'Select every product', 'dazont-ecom' ); ?>" /></th>
					<th style="width:70px;" title="<?php esc_attr_e( 'Hover a thumbnail to see it full size.', 'dazont-ecom' ); ?>"></th>
					<th title="<?php esc_attr_e( 'A green badge appears under the name for each piece of content produced.', 'dazont-ecom' ); ?>"><?php esc_html_e( 'Product', 'dazont-ecom' ); ?></th>
					<th style="width:80px;" title="<?php esc_attr_e( 'Cost of goods. On a variable product this is the lowest cost recorded on its variations.', 'dazont-ecom' ); ?>"><?php esc_html_e( 'Cost', 'dazont-ecom' ); ?></th>
					<th style="width:210px;" title="<?php esc_attr_e( '○ waiting, spinner while writing, ✓ ready, ✗ failed. Hover the symbol for the detail.', 'dazont-ecom' ); ?>"><?php esc_html_e( 'Status', 'dazont-ecom' ); ?></th>
					<th style="width:34px;"></th>
				</tr>
				<?php foreach ( $products as $p ) : ?>
					<tr class="dze-cb-row" data-id="<?php echo (int) $p['id']; ?>">
						<td class="dze-cb-pickcell"><input type="checkbox" class="dze-cb-pick" value="<?php echo (int) $p['id']; ?>" /></td>
						<td class="dze-cb-thumb">
							<?php if ( $p['full'] ) : ?><a href="<?php echo esc_url( $p['full'] ); ?>" target="_blank" rel="noopener"><?php endif; ?>
							<img class="dze-hzoom" src="<?php echo esc_url( $p['thumb'] ); ?>" data-full="<?php echo esc_url( $p['full'] ?: $p['thumb'] ); ?>" alt="" />
							<?php if ( $p['full'] ) : ?></a><?php endif; ?>
						</td>
						<td>
							<a href="<?php echo esc_url( $p['edit'] ); ?>" target="_blank" rel="noopener"><strong><?php echo esc_html( $p['title'] ); ?></strong></a>
							<div class="dze-cb-badges"></div>
						</td>
						<td><input type="number" step="0.01" class="dze-cb-cost" value="<?php echo esc_attr( $p['cost'] ); ?>" /></td>
						<td class="dze-cb-statuscell">
							<!-- ONE symbol per product, not one per task: the whole
							     story is in its tooltip. -->
							<span class="dze-cb-state is-wait" title="<?php esc_attr_e( 'Waiting', 'dazont-ecom' ); ?>">○</span>
							<span class="dze-cb-rowbar"><i></i></span>
							<span class="dze-cb-rowpct"></span>
							<button type="button" class="button button-small dze-cb-toggle" style="display:none;" aria-expanded="false" title="<?php esc_attr_e( 'Open the generated content in the WordPress editor, and choose which images to keep.', 'dazont-ecom' ); ?>">
								<?php esc_html_e( 'Review', 'dazont-ecom' ); ?> <span class="dze-cb-caret">▾</span>
							</button>
							<!-- Accept and refuse belong on the line, next to the state
							     they act on — not folded inside a panel you have to open
							     to reach them. -->
							<button type="button" class="dze-cb-yes" style="display:none;" title="<?php esc_attr_e( 'Accept: write this content to the product', 'dazont-ecom' ); ?>">✓</button>
							<button type="button" class="dze-cb-no" style="display:none;" title="<?php esc_attr_e( 'Refuse: throw this content away', 'dazont-ecom' ); ?>">✗</button>
						</td>
						<!-- Its own column, and a bin rather than a second cross: leaving
						     the list is not refusing a text, and two crosses side by side
						     made the row unreadable. -->
						<td class="dze-cb-killcell">
							<button type="button" class="dze-cb-unqueue-one" title="<?php esc_attr_e( 'Take this product out of the list (nothing is written or deleted on the product)', 'dazont-ecom' ); ?>">
								<span class="dashicons dashicons-trash" aria-hidden="true"></span>
								<span class="screen-reader-text"><?php esc_html_e( 'Take this product out of the list', 'dazont-ecom' ); ?></span>
							</button>
						</td>
					</tr>
					<tr class="dze-cb-preview" data-id="<?php echo (int) $p['id']; ?>" style="display:none;"><td colspan="6"></td></tr>
				<?php endforeach; ?>
			</table>
		</div>
		<?php
	}

	// =========================================================================
	// Assets
	// =========================================================================

	public function enqueue( string $hook ): void {
		$screen      = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$on_product  = $screen && 'product' === $screen->post_type && in_array( $hook, [ 'post.php', 'post-new.php' ], true );
		$on_list     = 'edit.php' === $hook && $screen && 'product' === $screen->post_type;
		$on_bulk     = ( 'product_page_' . self::BULK_SLUG ) === $hook;
		$on_settings = false !== strpos( (string) $hook, 'dazont' );
		if ( ! $on_product && ! $on_list && ! $on_bulk && ! $on_settings ) {
			return;
		}
		wp_enqueue_style( 'dze-content', DZE_URL . 'admin/css/content.css', [], DZE_VERSION );
		// Dense thumbnails everywhere: the full image on hover instead of
		// screen space spent on being legible.
		wp_enqueue_script( 'dze-hzoom', DZE_URL . 'admin/js/hzoom.js', [ 'jquery' ], DZE_VERSION, true );
		// The toolbox and the bulk list draw their "see the prompt" buttons in
		// JavaScript, so the modal has to be on the page before they exist.
		if ( class_exists( 'DZE_Prompts' ) && ( $on_product || $on_bulk || $on_list ) ) {
			DZE_Prompts::print_assets();
		}
		if ( $on_settings || $on_product || $on_list || $on_bulk ) {
			// Backgrounds, POD designs and mockups are all picked in the native
			// media modal, wherever the picker is offered.
			wp_enqueue_media();
		}

		if ( $on_bulk ) {
			// Reviewed texts are edited in the real WordPress editor, not in a
			// bare textarea full of raw HTML.
			wp_enqueue_editor();
			wp_enqueue_script( 'dze-content-bulk', DZE_URL . 'admin/js/content-bulk.js', [ 'jquery' ], DZE_VERSION, true );
			wp_localize_script( 'dze-content-bulk', 'dzeContentBulk', [
				'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
				'nonce'     => wp_create_nonce( self::NONCE ),
				'validated' => true, // gating is per-field via disabled checkboxes.
				// Where the screen goes back to after a paste: the selection, not
				// whatever filtered view it was opened on.
				'listUrl'   => add_query_arg( [ 'post_type' => 'product', 'page' => self::BULK_SLUG ], admin_url( 'edit.php' ) ),
				'fields'    => array_map( static fn( $f ) => $f['label'], self::enabled_fields() ),
				// What was generated last time and never decided on, so the
				// screen finds it again after a reload.
				'pending'   => self::pending_payload(),
				// A rich editor for what is really HTML; a plain box for a title
				// or a meta description, which TinyMCE would wrap in a <p>.
				'rich'      => array_map(
					static fn( $f ) => in_array( (string) ( $f['dest'] ?? '' ), [ 'post_content', 'post_excerpt', 'meta' ], true ),
					self::enabled_fields()
				),
				'i18n'      => [
					'working'  => __( 'Working…', 'dazont-ecom' ),
					'done'     => __( 'Done', 'dazont-ecom' ),
					'stopped'  => __( 'Stopped.', 'dazont-ecom' ),
					'error'    => __( 'error', 'dazont-ecom' ),
					'progress' => __( '%1$s / %2$s tasks — %3$s', 'dazont-ecom' ),
					'finished' => __( 'Finished: %1$s ok, %2$s errors.', 'dazont-ecom' ),
					'noFields' => __( 'Select at least one thing to generate.', 'dazont-ecom' ),
					'review'   => __( 'Generated — review below, then "Apply what I kept".', 'dazont-ecom' ),
					'toReview' => __( 'to review', 'dazont-ecom' ),
					'tText'    => __( 'Texts', 'dazont-ecom' ),
					'tPrice'   => __( 'Price', 'dazont-ecom' ),
					'tImage'   => __( 'Image', 'dazont-ecom' ),
					'imgBadge' => __( 'Images', 'dazont-ecom' ),
					'revBadge' => __( 'Reviews', 'dazont-ecom' ),
					'revNonce' => wp_create_nonce( 'dze_reviews' ),
					'running'  => __( 'in progress', 'dazont-ecom' ),
					'partial'  => __( '%1$s of %2$s written', 'dazont-ecom' ),
					'redoOne'  => __( 'Write this one again', 'dazont-ecom' ),
					'redoAll'  => __( 'Write every text again', 'dazont-ecom' ),
					'oneMore'  => __( 'One more image', 'dazont-ecom' ),
					'confirmRedo' => __( 'You have edited %s of these texts. Writing again replaces your edits. Continue?', 'dazont-ecom' ),
					'sWait'    => __( 'Waiting', 'dazont-ecom' ),
					'sRun'     => __( 'Writing…', 'dazont-ecom' ),
					'sReady'   => __( 'Ready to review', 'dazont-ecom' ),
					'sDone'    => __( 'Written to the product', 'dazont-ecom' ),
					'sFail'    => __( 'Something failed', 'dazont-ecom' ),
					'gProgress'=> __( '%1$s of %2$s products', 'dazont-ecom' ),
					'empty'    => __( '(empty)', 'dazont-ecom' ),
					'fromEarlier' => __( 'Waiting since an earlier run', 'dazont-ecom' ),
					'discard'  => __( 'Discard', 'dazont-ecom' ),
					'selected' => __( '%s selected', 'dazont-ecom' ),
					'confirmClear' => __( 'Empty the whole list? The products are not modified, they simply leave this screen.', 'dazont-ecom' ),
					'confirmAll' => __( 'Write the generated content to %s products at once? This modifies the shop.', 'dazont-ecom' ),
					'confirmOne' => __( 'Write this content to the product? It replaces what is there now.', 'dazont-ecom' ),
					'applyOne' => __( 'Apply this product', 'dazont-ecom' ),
					'nothingKept' => __( 'Nothing is waiting to be applied.', 'dazont-ecom' ),
					'sSkipped' => __( 'Left alone — already written and waiting for a decision', 'dazont-ecom' ),
					/* translators: %s: number of products */
					'skippedN' => __( '%s left alone (already written)', 'dazont-ecom' ),
					'allSkipped' => __( 'Every product on screen is already holding content waiting for a decision. Decide on it, or tick "Write them again too".', 'dazont-ecom' ),
					'applying' => __( 'Applying…', 'dazont-ecom' ),
					'applyAllN' => __( 'Apply all (%s)', 'dazont-ecom' ),
					'applySelN' => __( 'Apply only selected (%s)', 'dazont-ecom' ),
					'toGalleryFirst' => __( 'Gallery, first', 'dazont-ecom' ),
					'compare'  => __( 'Current', 'dazont-ecom' ),
					'compareHelp' => __( 'Show what this field holds on the product today, above the new text.', 'dazont-ecom' ),
					'redoShort'=> __( 'Rewrite', 'dazont-ecom' ),
					'promptTip'=> __( 'See the instructions sent to the model, and edit them', 'dazont-ecom' ),
					'keepHelp' => __( 'Untick to leave this block out — the rest is still written', 'dazont-ecom' ),
					'pasteNone'    => __( 'No ID found in what you pasted.', 'dazont-ecom' ),
					'pasteReplace' => __( 'Replace the whole list with these IDs?', 'dazont-ecom' ),
					/* translators: %s: number of IDs */
					'pasteUnknown' => __( '%s of the IDs are not products (or no longer exist) and were left out:', 'dazont-ecom' ),
					'nowText'  => __( 'On the product today', 'dazont-ecom' ),
					'nowImages'=> __( 'Photographs already on the product', 'dazont-ecom' ),
					'confirmDrop' => __( 'Throw away the content generated for this product? It cannot be recovered.', 'dazont-ecom' ),
					'toGallery'=> __( 'Product gallery', 'dazont-ecom' ),
					'toMain'   => __( 'Main image (first kept)', 'dazont-ecom' ),
					'attached' => __( '%s image(s) added to the product.', 'dazont-ecom' ),
					'applied'  => __( 'applied', 'dazont-ecom' ),
					'locked'   => __( 'not validated — skipped', 'dazont-ecom' ),
				],
			] );
			return;
		}
		if ( ! $on_product && ! $on_list ) {
			return;
		}
		// The list opens the same popup for any row, so it loads the same script
		// and the same editor; the product it works on is chosen at click time.
		$pid = $on_list ? 0 : (int) get_the_ID();
		wp_enqueue_editor();
		wp_enqueue_script( 'dze-content', DZE_URL . 'admin/js/content.js', [ 'jquery' ], DZE_VERSION, true );

		$labels  = [];
		$fv      = [];
		$prompts = [];
		foreach ( self::enabled_fields() as $fid => $f ) {
			$labels[ $fid ]  = $f['label'];
			$fv[ $fid ]      = self::field_validated( $fid );
			$prompts[ $fid ] = self::prompt_for( $fid );
		}

		$product = $pid ? wc_get_product( $pid ) : null;

		// Which product data the enabled prompts consume (labels only, for the
		// discreet "data used" line — content stays collapsed).
		$dze_union = [];
		$dze_opts  = self::input_options();
		foreach ( array_keys( self::enabled_fields() ) as $ufid ) {
			$urow = self::registry_row( $ufid );
			foreach ( (array) ( $urow['inputs'] ?? [] ) as $ink ) {
				$dze_union[ (string) ( $dze_opts[ $ink ] ?? $ink ) ] = 1;
			}
			foreach ( array_filter( array_map( 'trim', explode( ',', (string) ( $urow['inputs_meta'] ?? '' ) ) ) ) as $mk ) {
				$dze_union[ $mk ] = 1;
			}
		}

		wp_localize_script( 'dze-content', 'dzeContent', [
			'inputsUsed' => array_keys( $dze_union ),
			'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
			'nonce'      => wp_create_nonce( self::NONCE ),
			'postId'     => $pid,
			'validated'  => $fv, // per-field map.
			'fields'     => $labels,
			'templates'  => array_map( static fn( $t ) => [ 'id' => (string) ( $t['id'] ?? '' ), 'name' => $t['name'], 'target' => $t['target'] ?? 'gallery', 'valid' => ! empty( $t['valid'] ), 'prompt' => (string) $t['prompt'] ], self::image_templates() ),
			// The fixed supports/backgrounds, so the whole catalogue can be shot
			// in the same setting from one screen.
			'scenes'     => array_map(
				static fn( $sc ) => [
					'name'  => (string) $sc['name'],
					'thumb' => (string) ( wp_get_attachment_image_url( (int) $sc['image'], 'thumbnail' ) ?: '' ),
				],
				self::scenes()
			),
			'sceneDef'   => self::default_scene(),
			// The native WordPress block each prompt writes into: the button to
			// write just that block is placed there, next to the field itself,
			// rather than only inside the big popup.
			'anchors'    => array_filter( array_map(
				static function ( $f ) {
					// Rank Math and Yoast each draw their own box; the gallery
					// and the featured image are WooCommerce's.
					$seo_box = defined( 'RANK_MATH_VERSION' ) || class_exists( 'RankMath' )
						? '#rank_math_metabox'
						: ( defined( 'WPSEO_VERSION' ) ? '#wpseo_meta' : '' );
					$map = [
						'post_title'   => '#titlediv',
						'post_content' => '#postdivrich',
						'post_excerpt' => '#postexcerpt',
						'attributes'   => '#woocommerce-product-data',
						'seo_title'    => $seo_box,
						'seo_desc'     => $seo_box,
					];
					return $map[ (string) ( $f['dest'] ?? '' ) ] ?? '';
				},
				self::enabled_fields()
			) ),
			// Straight to the table the recalculation reads, from where it is used.
			'priceUrl'   => class_exists( 'DZE_Marketing_Ai' )
				? add_query_arg( [ 'page' => DZE_Marketing_Ai::MENU_SLUG, 'tab' => 'content' ], admin_url( 'admin.php' ) ) . '#dze-set-price'
				: '',
			// The surfaces the Main image lane can put a product on: the shop's
			// own plate first, then any scene already configured.
			'backdrops'  => array_values( array_filter( array_map(
				static fn( $sc ) => (int) $sc['image'] ? [
					'id'    => (int) $sc['image'],
					'name'  => (string) $sc['name'],
					'thumb' => (string) wp_get_attachment_image_url( (int) $sc['image'], 'thumbnail' ),
				] : null,
				self::scenes()
			) ) ),
			// How many photographs of this product travel with a generation —
			// stated on screen, because "which image did it actually use?" is
			// the first question when a result comes back wrong.
			'sourceN'    => $pid ? count( self::product_source_ids( $pid ) ) : 0,
			// Said before the click, not after a failed generation.
			'blockers'   => self::image_blockers(),
			// A rich editor for what is really HTML; a plain box for a title or
			// a meta description, which TinyMCE would wrap in a <p>.
			'rich'       => array_map(
				static fn( $f ) => in_array( (string) ( $f['dest'] ?? '' ), [ 'post_content', 'post_excerpt', 'meta' ], true ),
				self::enabled_fields()
			),
			// Content generated here or in bulk and never decided on.
			'pending'    => $pid ? self::pending( $pid ) : [],
			'prompts'    => $prompts,
			'defaults'   => self::default_prompts(), // per-prompt "restore default".
			'quickPrompt'=> self::quick_prompt(), // the recipe the Main image lane runs.
			'product'    => [
				'title' => $product ? $product->get_name() : '',
				'desc'  => $product ? wp_strip_all_tags( (string) get_post_field( 'post_content', $pid ) ) : '',
				// Formatted like the WordPress editor for the rich context view.
				'descHtml' => $product ? wpautop( (string) get_post_field( 'post_content', $pid ) ) : '',
				// Imported supplier attributes are already stored as standard product
				// attributes — pre-fill them so nobody retypes anything.
				'attr'  => $product ? self::attributes_summary( $product ) : '',
				// Variable products keep their cost on the variations, so the
				// parent's own field is empty — ask for the real figure.
				'price' => $product ? self::product_cost( $product ) : '',
			],
			'i18n'       => [
				'toolbox'    => __( 'Content toolbox', 'dazont-ecom' ),
				'text'       => __( 'Text', 'dazont-ecom' ),
				'image'      => __( 'Image', 'dazont-ecom' ),
				'price'      => __( 'Price', 'dazont-ecom' ),
				'close'      => __( 'Close', 'dazont-ecom' ),
				'generating' => __( 'Generating…', 'dazont-ecom' ),
				'genAll'     => __( 'Generate all', 'dazont-ecom' ),
				'generate'   => __( 'Generate', 'dazont-ecom' ),
				'apply'      => __( 'Apply', 'dazont-ecom' ),
				'applied'    => __( 'Applied ✓', 'dazont-ecom' ),
				'error'      => __( 'Something went wrong.', 'dazont-ecom' ),
				'previewOnly'=> __( 'Preview only — validate the prompts to apply.', 'dazont-ecom' ),
				'productData'=> __( 'Complete product data (used as context)', 'dazont-ecom' ),
				'pTitle'     => __( 'Title', 'dazont-ecom' ),
				'pDesc'      => __( 'Supplier / current description', 'dazont-ecom' ),
				'pAttr'      => __( 'Supplier attributes / extra data', 'dazont-ecom' ),
				'template'   => __( 'Template', 'dazont-ecom' ),
				'scene'      => __( 'Scene', 'dazont-ecom' ),
				'sources1'   => __( '1 photo sent', 'dazont-ecom' ),
				'sourcesN'   => __( '%s photos sent', 'dazont-ecom' ),
				'sources0'   => __( 'No product photo to send — set a featured image first.', 'dazont-ecom' ),
				'blocked'    => __( 'Images cannot be generated right now:', 'dazont-ecom' ),
				'noScene'    => __( 'No scene', 'dazont-ecom' ),
				'sceneHelp'  => __( 'The fixed support or background added as a second image, so every product is shot in the same setting. Manage the list under Settings → Product content.', 'dazont-ecom' ),
				// The toolbox now runs the same flow as the bulk screen and needs
				// the same words for it.
				'costLabel'  => __( 'Cost', 'dazont-ecom' ),
				'attempts'   => __( 'Attempts', 'dazont-ecom' ),
				'blocked'    => __( 'Images cannot be generated right now:', 'dazont-ecom' ),
				'applyOne'   => __( 'Apply to the product', 'dazont-ecom' ),
				'redoAll'    => __( 'Write every text again', 'dazont-ecom' ),
				'redoOne'    => __( 'Write this one again', 'dazont-ecom' ),
				'redoShort'  => __( 'Rewrite', 'dazont-ecom' ),
				'promptTip'  => __( 'See the instructions sent to the model, and edit them', 'dazont-ecom' ),
				// The fast lane.
				'qmTitle'    => __( 'Main image', 'dazont-ecom' ),
				'qmHelp'     => __( 'One photograph in, one catalogue shot out: the product straight-on, on the shop\'s grey, ready to be the main image.', 'dazont-ecom' ),
				'qmUrl'      => __( 'Paste an image address (optional — otherwise the product\'s own photographs are used)', 'dazont-ecom' ),
				'qmNote'     => __( 'Anything to add? e.g. "front view, zip closed"', 'dazont-ecom' ),
				'qmRun'      => __( 'Make the main image', 'dazont-ecom' ),
				'qmWorking'  => __( 'Shooting…', 'dazont-ecom' ),
				'qmNow'      => __( 'Main image today', 'dazont-ecom' ),
				'qmNew'      => __( 'New', 'dazont-ecom' ),
				'qmUse'      => __( 'Use as main image', 'dazont-ecom' ),
				'qmAgain'    => __( 'Try again', 'dazont-ecom' ),
				'qmBg'       => __( 'Background', 'dazont-ecom' ),
				'qmBgNone'   => __( 'None (described in the prompt)', 'dazont-ecom' ),
				'qmBgPlate'  => __( 'The shop backdrop', 'dazont-ecom' ),
				'qmPaste'    => __( 'Paste an image here (Ctrl+V) or drop a file', 'dazont-ecom' ),
				'qmPasted'   => __( 'Image pasted ✓ — it will be used instead of the address', 'dazont-ecom' ),
				'qmClear'    => __( 'Remove', 'dazont-ecom' ),
				// The price preview.
				'pricePreview'=> __( 'What will change?', 'dazont-ecom' ),
				'pvFrom'     => __( 'Cost from', 'dazont-ecom' ),
				'pvTo'       => __( 'to', 'dazont-ecom' ),
				'pvMult'     => __( 'Multiplier', 'dazont-ecom' ),
				'pvWhat'     => __( 'What', 'dazont-ecom' ),
				'pvCost'     => __( 'Cost used', 'dazont-ecom' ),
				'pvNow'      => __( 'Price today', 'dazont-ecom' ),
				'pvNew'      => __( 'Would become', 'dazont-ecom' ),
				'pvEdit'     => __( 'Edit the price table', 'dazont-ecom' ),
				// The one-block popup.
				'oneWrite'   => __( 'Write this with AI', 'dazont-ecom' ),
				'oneMain'    => __( 'Make the main image', 'dazont-ecom' ),
				'oneInstr'   => __( 'Instructions sent to the model', 'dazont-ecom' ),
				'oneInstrH'  => __( 'Edited here, it is used for this run only — unless you save it as the prompt.', 'dazont-ecom' ),
				'oneSave'    => __( 'Save as the prompt', 'dazont-ecom' ),
				'oneSaved'   => __( 'Prompt saved ✓', 'dazont-ecom' ),
				'oneBefore'  => __( 'On the product today', 'dazont-ecom' ),
				'oneAfter'   => __( 'What was just written', 'dazont-ecom' ),
				'oneGen'     => __( 'Write it', 'dazont-ecom' ),
				'oneRedo'    => __( 'Write it again', 'dazont-ecom' ),
				'oneApply'   => __( 'Save on the product', 'dazont-ecom' ),
				'oneOthers'  => __( 'Write just one block:', 'dazont-ecom' ),
				'shotsLabel' => __( 'Generated images — tick the ones to keep, then save', 'dazont-ecom' ),
				'shotDrop'   => __( 'Throw this image away', 'dazont-ecom' ),
				'bgAdd'      => __( 'Keep a new background', 'dazont-ecom' ),
				'bgPick'     => __( 'Choose the background image', 'dazont-ecom' ),
				'bgUse'      => __( 'Keep this one', 'dazont-ecom' ),
				// The image workshop.
				'imgSource'  => __( 'Work from', 'dazont-ecom' ),
				'imgAll'     => __( 'Every photograph of the product', 'dazont-ecom' ),
				'imgRecipe'  => __( 'Recipe', 'dazont-ecom' ),
				'imgMainR'   => __( 'Main image (the shop recipe)', 'dazont-ecom' ),
				'imgWhere'   => __( 'Put it', 'dazont-ecom' ),
				'imgReplace' => __( 'and delete the photograph it was made from', 'dazont-ecom' ),
				'imgRun'     => __( 'Make the image', 'dazont-ecom' ),
				'imgSaved'   => __( 'Saved on the product ✓', 'dazont-ecom' ),
				'keepHelp'   => __( 'Untick to leave this block out — the rest is still written', 'dazont-ecom' ),
				'nothingKept'=> __( 'Nothing left to write: every block was unticked.', 'dazont-ecom' ),
				'oneMore'    => __( 'One more image', 'dazont-ecom' ),
				'discard'    => __( 'Discard', 'dazont-ecom' ),
				'compare'    => __( 'Current', 'dazont-ecom' ),
				'compareHelp'=> __( 'Show what this field holds on the product today, above the new text.', 'dazont-ecom' ),
				'nowText'    => __( 'On the product today', 'dazont-ecom' ),
				'nowImages'  => __( 'Photographs already on the product', 'dazont-ecom' ),
				'empty'      => __( '(empty)', 'dazont-ecom' ),
				'working'    => __( 'Working…', 'dazont-ecom' ),
				'applying'   => __( 'Applying…', 'dazont-ecom' ),
				'partial'    => __( '%1$s of %2$s written', 'dazont-ecom' ),
				'toGalleryFirst' => __( 'Gallery, first', 'dazont-ecom' ),
				'addPrompt'  => __( 'Add another image prompt', 'dazont-ecom' ),
				'delPrompt'  => __( 'Remove this prompt', 'dazont-ecom' ),
				'toReview'   => __( 'to review', 'dazont-ecom' ),
				/* translators: 1: steps done, 2: steps in total */
				'stepN'      => __( 'Step %1$s of %2$s', 'dazont-ecom' ),
				/* translators: %s: seconds elapsed */
				'elapsed'    => __( '· %ss', 'dazont-ecom' ),
				/* translators: %s: number of fields */
				'stepTexts'  => __( 'Writing %s texts', 'dazont-ecom' ),
				'stepPrice'  => __( 'Recalculating the price', 'dazont-ecom' ),
				/* translators: %s: prompt name */
				'stepImage'  => __( 'Image — %s', 'dazont-ecom' ),
				/* translators: 1: prompt name, 2: attempt number, 3: attempts in total */
				'stepImageN' => __( 'Image — %1$s (%2$s of %3$s)', 'dazont-ecom' ),
				'stepDone'   => __( 'Finished', 'dazont-ecom' ),
				'confirmRedo'=> __( 'You have edited %s of these texts. Writing again replaces your edits. Continue?', 'dazont-ecom' ),
				'confirmDrop'=> __( 'Throw away the content generated for this product? It cannot be recovered.', 'dazont-ecom' ),
				'genImage'   => __( 'Generate image', 'dazont-ecom' ),
				'imgWait'    => __( 'Rendering — up to a minute…', 'dazont-ecom' ),
				'imgAdded'   => __( 'Image added.', 'dazont-ecom' ),
				'imgPreview' => __( 'Preview only — template not validated, nothing attached. Validate it in Settings to attach results.', 'dazont-ecom' ),
				'imgReady'   => __( 'Image ready — added to the session gallery below.', 'dazont-ecom' ),
				'addSelected'=> __( 'Add selected to product', 'dazont-ecom' ),
				'added'      => __( 'Added ✓', 'dazont-ecom' ),
				'attachDone' => __( 'image(s) added to the product with SEO naming.', 'dazont-ecom' ),
				'sendTo'     => __( 'Send to:', 'dazont-ecom' ),
				'toGallery'  => __( 'Product gallery', 'dazont-ecom' ),
				'toMain'     => __( 'Main image', 'dazont-ecom' ),
				'toGalleryFirst' => __( 'Gallery, first', 'dazont-ecom' ),
				'sendToEach' => __( 'Each image goes where its own menu says.', 'dazont-ecom' ),
				'addPrompt'  => __( 'Add another image prompt', 'dazont-ecom' ),
				'delPrompt'  => __( 'Remove this prompt', 'dazont-ecom' ),
				'toReview'   => __( 'to review', 'dazont-ecom' ),
				/* translators: 1: steps done, 2: steps in total */
				'stepN'      => __( 'Step %1$s of %2$s', 'dazont-ecom' ),
				/* translators: %s: seconds elapsed */
				'elapsed'    => __( '· %ss', 'dazont-ecom' ),
				/* translators: %s: number of fields */
				'stepTexts'  => __( 'Writing %s texts', 'dazont-ecom' ),
				'stepPrice'  => __( 'Recalculating the price', 'dazont-ecom' ),
				/* translators: %s: prompt name */
				'stepImage'  => __( 'Image — %s', 'dazont-ecom' ),
				/* translators: 1: prompt name, 2: attempt number, 3: attempts in total */
				'stepImageN' => __( 'Image — %1$s (%2$s of %3$s)', 'dazont-ecom' ),
				'stepDone'   => __( 'Finished', 'dazont-ecom' ),
				'select'     => __( 'Select', 'dazont-ecom' ),
				'editImage'  => __( 'Edit with a manual prompt', 'dazont-ecom' ),
				'variantHelp'=> __( 'Describe the change to apply to THIS image (one-off prompt, not saved):', 'dazont-ecom' ),
				'genVariant' => __( 'Generate variant', 'dazont-ecom' ),
				'editPrompt' => __( 'Edit prompt', 'dazont-ecom' ),
				'savePrompt' => __( 'Save prompt', 'dazont-ecom' ),
				'savedPrompt'=> __( 'Prompt saved ✓', 'dazont-ecom' ),
				'restore'    => __( 'Restore default', 'dazont-ecom' ),
				'promptNote' => __( 'Edits here are used for THIS generation only — click 💾 to make them permanent.', 'dazont-ecom' ),
				'cancel'     => __( 'Cancel', 'dazont-ecom' ),
				'notValid'   => __( 'not validated', 'dazont-ecom' ),
				'validated'  => __( 'Validated', 'dazont-ecom' ),
				'validToggle'=> __( 'Click to toggle validation — validated prompts can be applied / attached.', 'dazont-ecom' ),
				'auto'       => __( 'Automatic edition', 'dazont-ecom' ),
				'launch'     => __( 'Launch', 'dazont-ecom' ),
				'whatToGen'  => __( 'What to generate', 'dazont-ecom' ),
				'testBox'    => __( 'Test', 'dazont-ecom' ),
				'testNote'   => __( 'Preview and tune the prompts before automating.', 'dazont-ecom' ),
				'previewText'=> __( 'Preview text', 'dazont-ecom' ),
				'previewImg' => __( 'Preview images', 'dazont-ecom' ),
				'saveSetup'  => __( 'Remember this setup', 'dazont-ecom' ),
				'dataUsed'   => __( 'Data used', 'dazont-ecom' ),
				'review'     => __( 'Review', 'dazont-ecom' ),
				'reviewNote' => __( 'Check and edit the generated content, then apply.', 'dazont-ecom' ),
				'applyAll'   => __( 'Apply all', 'dazont-ecom' ),
				'skippedLock'=> __( 'skipped (not validated)', 'dazont-ecom' ),
				'genImgOpt'  => __( 'Generate an image', 'dazont-ecom' ),
				'priceOpt'   => __( 'Recalculate price (applies immediately)', 'dazont-ecom' ),
				'nothingSel' => __( 'Tick at least one thing to generate.', 'dazont-ecom' ),
				'allDone'    => __( 'Done — everything applied.', 'dazont-ecom' ),
				'editData'   => __( 'View / edit the data sent to the AI', 'dazont-ecom' ),
				'imgCount'   => __( 'Number of images to generate — pick the best ones from the gallery.', 'dazont-ecom' ),
				'fieldLocked'=> __( 'This prompt is not validated yet (Settings → Product content).', 'dazont-ecom' ),
				'cost'       => __( 'Cost (COGS)', 'dazont-ecom' ),
				'recalc'     => __( 'Recalculate & apply', 'dazont-ecom' ),
				'newPrice'   => __( 'New regular price', 'dazont-ecom' ),
			],
		] );
	}

	// =========================================================================
	// AJAX
	// =========================================================================

	private function guard(): void {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( 'edit_products' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'dazont-ecom' ) ], 403 );
		}
	}

	private static function model(): string {
		if ( class_exists( 'DZE_Marketing_Ai' ) ) {
			$m = (string) ( DZE_Marketing_Ai::get_settings()['insights_model'] ?? '' );
			return '' !== $m ? $m : DZE_Marketing_Ai::chosen_model();
		}
		return '';
	}

	/** Plain-text summary of a product's current attributes ("Label: a | b"). */
	private static function attributes_summary( WC_Product $product ): string {
		$lines = [];
		foreach ( $product->get_attributes() as $attr ) {
			if ( ! $attr instanceof WC_Product_Attribute ) {
				continue;
			}
			if ( $attr->is_taxonomy() ) {
				$terms = wc_get_product_terms( $product->get_id(), $attr->get_name(), [ 'fields' => 'names' ] );
				$vals  = implode( ' | ', array_map( 'strval', (array) $terms ) );
			} else {
				$vals = implode( ' | ', array_map( 'strval', (array) $attr->get_options() ) );
			}
			if ( '' !== $vals ) {
				$lines[] = wc_attribute_label( $attr->get_name() ) . ': ' . $vals;
			}
		}
		return implode( "\n", $lines );
	}

	public function ajax_text(): void {
		$this->guard();
		$field  = isset( $_POST['field'] ) ? sanitize_key( wp_unslash( $_POST['field'] ) ) : '';
		$fields = self::fields();
		if ( ! isset( $fields[ $field ] ) || ! self::field_enabled( $field ) ) {
			wp_send_json_error( [ 'message' => __( 'Unknown or disabled field.', 'dazont-ecom' ) ] );
		}
		$pid   = isset( $_POST['post'] ) ? absint( $_POST['post'] ) : 0;
		$title = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
		$desc  = isset( $_POST['desc'] ) ? sanitize_textarea_field( wp_unslash( $_POST['desc'] ) ) : '';
		$attr  = isset( $_POST['attr'] ) ? sanitize_textarea_field( wp_unslash( $_POST['attr'] ) ) : '';

		// Server-side payload from the prompt's SELECTED inputs when nothing is posted.
		$payload = '';
		if ( '' !== $title || '' !== $desc || '' !== $attr ) {
			$payload = ( $title ? "Title: {$title}\n" : '' ) . ( $desc ? "Description: {$desc}\n" : '' ) . ( $attr ? "Attributes / supplier data: {$attr}\n" : '' );
		} elseif ( $pid ) {
			$row     = self::registry_row( $field );
			$payload = self::payload_lines( $pid, (array) ( $row['inputs'] ?? [ 'title', 'description', 'attributes', 'price' ] ), (string) ( $row['inputs_meta'] ?? '' ) );
		}
		if ( '' === trim( $payload ) ) {
			wp_send_json_error( [ 'message' => __( 'Fill in the product data first.', 'dazont-ecom' ) ] );
		}
		$override = isset( $_POST['prompt'] ) ? sanitize_textarea_field( wp_unslash( $_POST['prompt'] ) ) : '';
		$system   = 'You are an expert e-commerce copywriter. ' . self::store_context();
		$user     = ( '' !== trim( $override ) ? $override : self::prompt_for( $field ) ) . self::language_rule()
			. "\n\n--- PRODUCT DATA ---\n" . $payload . "\n";
		try {
			$text = DZE_Marketing_Ai::complete( $system, $user, self::model(), (int) ( $fields[ $field ]['tokens'] ?? 400 ) );
		} catch ( \Throwable $e ) {
			wp_send_json_error( [ 'message' => $e->getMessage() ] );
		}
		wp_send_json_success( [ 'field' => $field, 'text' => $text ] );
	}

	/**
	 * Generates ALL requested fields in ONE model call (each field keeps its own
	 * verbatim prompt, executed independently inside the call) — this is what
	 * makes per-product generation fast: one round-trip instead of one per field.
	 * With apply=1 (bulk) every validated field is written to its destination
	 * server-side too, so a whole product needs a single HTTP request.
	 */
	public function ajax_text_all(): void {
		$this->guard();
		$pid   = isset( $_POST['post'] ) ? absint( $_POST['post'] ) : 0;
		$apply = ! empty( $_POST['apply'] );
		$req   = isset( $_POST['fields'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['fields'] ) ) : [];

		$targets = [];
		foreach ( self::enabled_fields() as $fid => $f ) {
			if ( $req && ! in_array( $fid, $req, true ) ) {
				continue;
			}
			if ( $apply && ! self::field_validated( $fid ) ) {
				continue; // bulk applies directly: only validated prompts.
			}
			$targets[ $fid ] = $f;
		}
		if ( empty( $targets ) ) {
			wp_send_json_error( [ 'message' => __( 'No enabled field to generate.', 'dazont-ecom' ) ] );
		}

		$title = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
		$desc  = isset( $_POST['desc'] ) ? sanitize_textarea_field( wp_unslash( $_POST['desc'] ) ) : '';
		$attr  = isset( $_POST['attr'] ) ? sanitize_textarea_field( wp_unslash( $_POST['attr'] ) ) : '';
		if ( '' !== $title || '' !== $desc || '' !== $attr ) {
			$payload = ( $title ? "Title: {$title}\n" : '' ) . ( $desc ? "Description: {$desc}\n" : '' ) . ( $attr ? "Attributes / supplier data: {$attr}\n" : '' );
		} else {
			// Union of the selected inputs across every requested prompt.
			$union = [];
			$umeta = [];
			foreach ( $targets as $fid => $f ) {
				$row = self::registry_row( $fid );
				foreach ( (array) ( $row['inputs'] ?? [] ) as $ink ) { $union[ $ink ] = 1; }
				if ( ! empty( $row['inputs_meta'] ) ) { $umeta[] = (string) $row['inputs_meta']; }
			}
			$payload = self::payload_lines( $pid, array_keys( $union ) ?: [ 'title', 'description', 'attributes', 'price' ], implode( ',', $umeta ) );
		}
		if ( '' === trim( $payload ) ) {
			wp_send_json_error( [ 'message' => __( 'Fill in the product data first.', 'dazont-ecom' ) ] );
		}

		$system = 'You are an expert e-commerce copywriter writing in ' . self::site_language() . '. ' . self::store_context();
		$user   = "--- PRODUCT DATA ---\n" . $payload . "\n";
		$user .= "\nGenerate the " . count( $targets ) . " fields below. Each field has its OWN instructions, coming from separate proven scripts — follow each set EXACTLY and independently, as if it were the only task.\n";
		$user .= "OUTPUT FORMAT (strict): for each field output a line exactly ===FIELD:<field_id>=== followed by that field's content, then after the last field a line ===END===. Nothing else.\n";
		$user .= 'LANGUAGE: every field is written in ' . self::site_language() . ", whatever language the instructions below are written in.\n\n";
		// One-off prompt overrides from the live editors (never saved here).
		$overrides = [];
		if ( isset( $_POST['prompts'] ) && is_array( $_POST['prompts'] ) ) {
			foreach ( wp_unslash( $_POST['prompts'] ) as $ofid => $op ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized below.
				$overrides[ sanitize_key( $ofid ) ] = sanitize_textarea_field( (string) $op );
			}
		}
		// A block written against a photograph only exists if there IS one: with
		// a thin gallery the second block is dropped rather than written about
		// an image that was never chosen.
		$companions = self::companion_map( $pid );
		foreach ( array_keys( $targets ) as $fid ) {
			if ( '' !== self::companion_meta( (string) $fid ) && ! isset( $companions[ $fid ] ) ) {
				unset( $targets[ $fid ] );
			}
		}
		if ( empty( $targets ) ) {
			wp_send_json_error( [ 'message' => __( 'Nothing to write: these blocks need product photographs to zoom in on.', 'dazont-ecom' ) ] );
		}

		$tokens = 300;
		$shots  = [];
		foreach ( $targets as $fid => $f ) {
			$p     = ! empty( $overrides[ $fid ] ) ? $overrides[ $fid ] : self::prompt_for( $fid );
			$user .= '===INSTRUCTIONS for field "' . $fid . '" (' . $f['label'] . ")===\n" . $p . "\n\n";
			if ( isset( $companions[ $fid ] ) ) {
				$n       = count( $shots ) + 1;
				$shots[] = (int) $companions[ $fid ]['id'];
				$user   .= '===THE PHOTOGRAPH BESIDE FIELD "' . $fid . '"===' . "\n"
					. 'This block is displayed next to image ' . $n . ' above, which shows: '
					. $companions[ $fid ]['feature'] . ".\n"
					. "Its h2 must be a selling angle zooming in on THAT particularity, and the body must argue that one point, from what is actually visible in the photograph. Write about the product, never about the photograph itself — no \"as you can see on the picture\".\n\n";
			}
			$tokens += (int) ( $f['tokens'] ?? 300 );
		}

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		DZE_Ai_Usage::unit( 'product_text' );
		try {
			if ( $shots ) {
				// The model writes about a photograph it can see, not about a
				// description of one. The images referenced by the blocks travel
				// with the request, in the order the instructions name them.
				$payload_images = [];
				foreach ( $shots as $aid ) {
					try {
						$uri = $this->fal_source_data_uri( (int) $aid, 'medium_large' );
					} catch ( \Throwable $e ) {
						continue;
					}
					if ( preg_match( '#^data:([^;]+);base64,(.+)$#', $uri, $mm ) ) {
						$payload_images[] = [ 'media' => $mm[1], 'data' => $mm[2] ];
					}
				}
				$text = $payload_images
					? DZE_Marketing_Ai::complete_with_images( $system, $user, $payload_images, self::model(), $tokens, 240 )
					: DZE_Marketing_Ai::complete( $system, $user, self::model(), $tokens, 240 );
			} else {
				$text = DZE_Marketing_Ai::complete( $system, $user, self::model(), $tokens, 240 );
			}
		} catch ( \Throwable $e ) {
			DZE_Ai_Usage::unit();
			wp_send_json_error( [ 'message' => $e->getMessage() ] );
		}
		DZE_Ai_Usage::unit();
		DZE_Ai_Usage::finished( 'product_text' );

		// What each block was written against, so the screens can show it next
		// to the text instead of leaving the choice invisible.
		$companion_out = [];
		foreach ( $companions as $cfid => $c ) {
			if ( ! isset( $targets[ $cfid ] ) ) {
				continue;
			}
			$companion_out[ $cfid ] = [
				'thumb'   => (string) ( wp_get_attachment_image_url( (int) $c['id'], 'thumbnail' ) ?: '' ),
				'full'    => (string) ( wp_get_attachment_image_url( (int) $c['id'], 'large' ) ?: '' ),
				'feature' => (string) $c['feature'],
			];
		}

		$texts = [];
		if ( preg_match_all( '/===FIELD:([a-z0-9_]+)===\s*(.*?)(?=\s*===FIELD:|\s*===END===)/s', $text . "\n===END===", $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $hit ) {
				if ( ! isset( $texts[ $hit[1] ] ) ) {
					$texts[ $hit[1] ] = trim( $hit[2] );
				}
			}
		}
		if ( empty( $texts ) ) {
			wp_send_json_error( [ 'message' => __( 'The AI returned an unreadable multi-field response. Try again.', 'dazont-ecom' ) ] );
		}

		if ( $apply ) {
			$results = [];
			foreach ( $targets as $fid => $f ) {
				if ( empty( $texts[ $fid ] ) ) {
					$results[ $fid ] = 'missing';
					continue;
				}
				try {
					$this->apply_value( $pid, $fid, wp_kses_post( $texts[ $fid ] ) );
					$results[ $fid ] = 'applied';
				} catch ( \Throwable $e ) {
					$results[ $fid ] = 'error';
				}
			}
			wp_send_json_success( [ 'results' => $results, 'texts' => $texts, 'companions' => $companion_out ] );
		}
		if ( ! empty( $_POST['stash'] ) ) {
			self::stash( $pid, [ 'texts' => $texts, 'companions' => $companion_out ] );
		}
		wp_send_json_success( [ 'texts' => $texts, 'companions' => $companion_out ] );
	}

	public function ajax_apply(): void {
		$this->guard();
		$pid    = isset( $_POST['post'] ) ? absint( $_POST['post'] ) : 0;
		$field  = isset( $_POST['field'] ) ? sanitize_key( wp_unslash( $_POST['field'] ) ) : '';
		if ( '' !== $field && ! self::field_validated( $field ) ) {
			wp_send_json_error( [ 'message' => __( 'This prompt is not validated yet — tick its "Prompt validated" box in Settings → Product content.', 'dazont-ecom' ) ] );
		}
		$value  = isset( $_POST['value'] ) ? wp_kses_post( wp_unslash( $_POST['value'] ) ) : '';
		$fields = self::fields();
		if ( ! $pid || ! isset( $fields[ $field ] ) ) {
			wp_send_json_error( [ 'message' => __( 'Invalid request.', 'dazont-ecom' ) ] );
		}
		try {
			$note = $this->apply_value( $pid, $field, $value );
		} catch ( \Throwable $e ) {
			wp_send_json_error( [ 'message' => $e->getMessage() ] );
		}
		wp_send_json_success( $note ? [ 'note' => $note ] : [] );
	}

	/** Writes a generated value to its mapped destination. Returns an optional note. */
	private function apply_value( int $pid, string $field, string $value ): string {
		$dest = self::dest_for( $field );
		$seo  = self::seo_keys();
		switch ( $dest['type'] ) {
			case 'post_title':
				wp_update_post( [ 'ID' => $pid, 'post_title' => wp_strip_all_tags( $value ) ] );
				break;
			case 'post_content':
				wp_update_post( [ 'ID' => $pid, 'post_content' => $value ] );
				break;
			case 'post_excerpt':
				wp_update_post( [ 'ID' => $pid, 'post_excerpt' => $value ] );
				break;
			case 'seo_title':
				update_post_meta( $pid, $seo['title'], sanitize_text_field( wp_strip_all_tags( $value ) ) );
				break;
			case 'seo_desc':
				update_post_meta( $pid, $seo['desc'], sanitize_text_field( wp_strip_all_tags( $value ) ) );
				break;
			case 'attributes':
				return $this->apply_attributes( $pid, $value );
			case 'meta':
			default:
				update_post_meta( $pid, (string) ( $dest['key'] ?? '_dze_' . $field ), $value );
		}
		// A block written against a photograph carries it: the text and the
		// image it argues about are one piece of content, and saving one
		// without the other would put a zoom next to the wrong picture.
		$img_key = self::companion_meta( $field );
		if ( '' !== $img_key ) {
			$map = self::companion_map( $pid );
			if ( isset( $map[ $field ]['id'] ) ) {
				update_post_meta( $pid, $img_key, (int) $map[ $field ]['id'] );
			}
		}
		return '';
	}

	/**
	 * Parses "Name: value|value" lines into REAL WooCommerce attributes.
	 * Global attributes (a matching pa_* taxonomy exists) get their terms
	 * created/assigned so layered nav and filters work; the rest become local
	 * product attributes. Existing attributes are merged, and attributes used
	 * for variations are never touched (that would break the variations).
	 */
	private function apply_attributes( int $pid, string $text ): string {
		$product = wc_get_product( $pid );
		if ( ! $product instanceof WC_Product ) {
			throw new RuntimeException( __( 'Product not found.', 'dazont-ecom' ) );
		}
		$existing = $product->get_attributes();
		$position = count( $existing );
		$applied  = 0;
		$skipped  = 0;

		foreach ( preg_split( '/\r?\n/', $text ) as $line ) {
			$line = trim( wp_strip_all_tags( (string) $line ), "- \t" );
			if ( '' === $line || false === strpos( $line, ':' ) ) {
				continue;
			}
			[ $name, $vals ] = array_map( 'trim', explode( ':', $line, 2 ) );
			$values          = array_values( array_filter( array_map( 'trim', explode( '|', $vals ) ) ) );
			if ( '' === $name || empty( $values ) ) {
				continue;
			}

			$tax    = 'pa_' . wc_sanitize_taxonomy_name( $name );
			$is_tax = taxonomy_exists( $tax );
			$key    = $is_tax ? $tax : sanitize_title( $name );

			// Never overwrite an attribute that drives variations.
			if ( isset( $existing[ $key ] ) && $existing[ $key ] instanceof WC_Product_Attribute && $existing[ $key ]->get_variation() ) {
				$skipped++;
				continue;
			}

			$attr = new WC_Product_Attribute();
			if ( $is_tax ) {
				$ids = [];
				foreach ( $values as $v ) {
					$term = get_term_by( 'name', $v, $tax );
					if ( ! $term ) {
						$r = wp_insert_term( $v, $tax );
						if ( ! is_wp_error( $r ) ) {
							$term = get_term( (int) $r['term_id'], $tax );
						}
					}
					if ( $term instanceof WP_Term ) {
						$ids[] = (int) $term->term_id;
					}
				}
				if ( empty( $ids ) ) {
					continue;
				}
				wp_set_object_terms( $pid, $ids, $tax, false );
				$attr->set_id( (int) wc_attribute_taxonomy_id_by_name( $tax ) );
				$attr->set_name( $tax );
				$attr->set_options( $ids );
			} else {
				$attr->set_name( $name );
				$attr->set_options( $values );
			}
			$attr->set_position( isset( $existing[ $key ] ) ? $existing[ $key ]->get_position() : $position++ );
			$attr->set_visible( true );
			$attr->set_variation( false );
			$existing[ $key ] = $attr;
			$applied++;
		}

		if ( $applied > 0 ) {
			$product->set_attributes( $existing );
			$product->save();
		}
		/* translators: 1: applied count, 2: skipped count */
		return sprintf( __( '%1$d attribute(s) applied%2$s.', 'dazont-ecom' ), $applied, $skipped ? ' ' . sprintf( /* translators: %d: skipped count */ __( '(%d variation attribute(s) left untouched)', 'dazont-ecom' ), $skipped ) : '' );
	}

	/**
	 * What the price recalculation WOULD do, without doing any of it.
	 *
	 * "Recalculate from the cost" answers none of the questions somebody about
	 * to click it is asking: which cost, read from where, multiplied by what,
	 * and — on a variable product — applied to which variation. So the whole
	 * thing is laid out first: the table with the row that matches highlighted,
	 * then one line per variation with its cost, its price today and the price
	 * it would get.
	 */
	/**
	 * A price as PLAIN TEXT.
	 *
	 * wc_price() returns markup, and markup handed to a screen that escapes
	 * what it prints — as it should — comes out as a wall of span tags. The
	 * preview needs the figure, not the formatting.
	 */
	private static function price_text( float $value ): string {
		return trim( html_entity_decode( wp_strip_all_tags( wc_price( $value ) ), ENT_QUOTES, 'UTF-8' ) );
	}

	public function ajax_price_preview(): void {
		$this->guard();
		$pid  = isset( $_POST['post'] ) ? absint( $_POST['post'] ) : 0;
		$cost = isset( $_POST['cost'] ) ? (float) wp_unslash( $_POST['cost'] ) : 0;
		$product = $pid ? wc_get_product( $pid ) : null;
		if ( ! $product instanceof WC_Product ) {
			wp_send_json_error( [ 'message' => __( 'Product not found.', 'dazont-ecom' ) ] );
		}
		if ( $cost <= 0 ) {
			$cost = (float) self::product_cost( $product );
		}
		if ( $cost <= 0 ) {
			wp_send_json_error( [ 'message' => __( 'No cost recorded on this product, and none typed in the box: there is nothing to calculate from.', 'dazont-ecom' ) ] );
		}

		// The table, with the row this cost falls into marked.
		$table = [];
		foreach ( self::price_table() as $row ) {
			$min = (float) ( $row['min'] ?? 0 );
			$max = (float) ( $row['max'] ?? 0 );
			$table[] = [
				'min'  => self::price_text( $min ),
				'max'  => $max > 0 ? self::price_text( $max ) : '∞',
				'mult' => (float) ( $row['mult'] ?? 1 ),
				'hit'  => ( $cost >= $min && ( $max <= 0 || $cost <= $max ) ),
			];
		}

		$rows = [];
		if ( $product->is_type( 'variable' ) ) {
			foreach ( $product->get_children() as $vid ) {
				$variation = wc_get_product( (int) $vid );
				if ( ! $variation instanceof WC_Product ) {
					continue;
				}
				// Each variation is priced from ITS OWN recorded cost when it has
				// one — that is exactly what the run does, so that is what the
				// preview must show.
				$vcost = (float) ( self::cost_meta( (int) $vid ) ?: $cost );
				if ( $vcost <= 0 ) {
					continue;
				}
				$rows[] = [
					'name' => $variation->get_name(),
					'cost' => self::price_text( $vcost ),
					'now'  => '' !== $variation->get_regular_price() ? self::price_text( (float) $variation->get_regular_price() ) : '—',
					'next' => self::price_text( DZE_Price::charm( $vcost * self::mult_for_cost( $vcost ), 'up' ) ),
				];
			}
		} else {
			$rows[] = [
				'name' => $product->get_name(),
				'cost' => self::price_text( $cost ),
				'now'  => '' !== $product->get_regular_price() ? self::price_text( (float) $product->get_regular_price() ) : '—',
				'next' => self::price_text( DZE_Price::charm( $cost * self::mult_for_cost( $cost ), 'up' ) ),
			];
		}

		$explain = $product->is_type( 'variable' )
			? __( 'Each variation is priced from its own recorded cost when it has one, and from the cost in the box when it has none. The cost is also written to the WooCommerce Cost of Goods field. Prices are rounded up to the ending set under Settings → General.', 'dazont-ecom' )
			: __( 'The cost × the multiplier of the matching range gives the regular price. The cost is also written to the WooCommerce Cost of Goods field, and the price is rounded up to the ending set under Settings → General.', 'dazont-ecom' );

		wp_send_json_success( [
			'explain' => $explain,
			'table'   => $table,
			'rows'    => array_slice( $rows, 0, 60 ), // a preview, not a report.
		] );
	}

	public function ajax_price(): void {
		$this->guard();
		$pid  = isset( $_POST['post'] ) ? absint( $_POST['post'] ) : 0;
		$cost = isset( $_POST['cost'] ) ? (float) wp_unslash( $_POST['cost'] ) : 0;
		if ( ! $pid || $cost <= 0 ) {
			wp_send_json_error( [ 'message' => __( 'Enter a valid cost.', 'dazont-ecom' ) ] );
		}
		$mult    = self::mult_for_cost( $cost );
		// Rounded UP when charm rounding is on: a selling price built from a
		// cost must never lose margin to the presentation.
		$regular = DZE_Price::charm( $cost * $mult, 'up' );
		// Deterministic math on an explicit action — no prompt involved, applies directly.
		$product = wc_get_product( $pid );
		if ( ! $product instanceof WC_Product ) {
			wp_send_json_error( [ 'message' => __( 'Product not found.', 'dazont-ecom' ) ] );
		}
		update_post_meta( $pid, '_dze_cogs', $cost );
		update_post_meta( $pid, '_cogs_value', $cost ); // WooCommerce native Cost of Goods.

		if ( $product->is_type( 'variable' ) ) {
			// A regular price on a variable parent is meta WooCommerce never
			// displays — the shop reads the variations. Writing it there was a
			// silent no-op. Each variation is recomputed from ITS OWN recorded
			// cost when it has one, so a run does not flatten a range of
			// different costs onto the single figure typed in the box.
			$prices = [];
			$done   = 0;
			foreach ( $product->get_children() as $vid ) {
				$variation = wc_get_product( (int) $vid );
				if ( ! $variation instanceof WC_Product ) {
					continue;
				}
				$vcost = (float) ( self::cost_meta( (int) $vid ) ?: $cost );
				if ( $vcost <= 0 ) {
					continue;
				}
				$vmult = self::mult_for_cost( $vcost );
				$vreg  = DZE_Price::charm( $vcost * $vmult, 'up' );
				update_post_meta( (int) $vid, '_dze_cogs', $vcost );
				update_post_meta( (int) $vid, '_cogs_value', $vcost );
				$variation->set_regular_price( (string) $vreg );
				$variation->save();
				$prices[] = $vreg;
				$done++;
			}
			if ( ! $done ) {
				wp_send_json_error( [ 'message' => __( 'This variable product has no variation to price.', 'dazont-ecom' ) ] );
			}
			// Without this the parent keeps serving the old price range from
			// its own cached meta and transients.
			if ( class_exists( 'WC_Product_Variable' ) ) {
				WC_Product_Variable::sync( $pid );
			}
			$lo    = min( $prices );
			$hi    = max( $prices );
			$label = $lo === $hi ? (string) $lo : $lo . '–' . $hi;
			wp_send_json_success( [
				'mult'       => $mult,
				'regular'    => $label,
				'variations' => $done,
				'applied'    => true,
			] );
		}

		$product->set_regular_price( (string) $regular );
		$product->save();
		wp_send_json_success( [ 'mult' => $mult, 'regular' => $regular, 'applied' => true ] );
	}

	/**
	 * Reads an image from the web and returns it as a data URI.
	 *
	 * Used by the quick main-image lane: the photograph often lives on a
	 * supplier page and is not worth importing into the media library just to
	 * be reshot. Nothing about that URL is stored — only the bytes travel, to
	 * fal, once.
	 *
	 * wp_safe_remote_get is the point: it refuses loopback and private
	 * addresses, so a URL pasted into this box cannot be used to make the shop
	 * fetch something on its own network.
	 */
	public function fetch_remote_image( string $url ): string {
		$url = trim( $url );
		if ( ! preg_match( '#^https?://#i', $url ) || ! wp_http_validate_url( $url ) ) {
			throw new RuntimeException( __( 'That is not a valid image address.', 'dazont-ecom' ) );
		}
		$res = wp_safe_remote_get( $url, [
			'timeout'     => 20,
			'redirection' => 2,
			'headers'     => [ 'Accept' => 'image/*' ],
		] );
		if ( is_wp_error( $res ) ) {
			throw new RuntimeException( $res->get_error_message() );
		}
		if ( 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
			throw new RuntimeException( __( 'That address did not return an image.', 'dazont-ecom' ) );
		}
		$type = strtolower( (string) wp_remote_retrieve_header( $res, 'content-type' ) );
		$body = (string) wp_remote_retrieve_body( $res );
		if ( '' === $body || strlen( $body ) > self::MAX_REMOTE ) {
			throw new RuntimeException( __( 'That image is empty, or too heavy to send.', 'dazont-ecom' ) );
		}
		// The header is a claim; the bytes are the proof.
		$info = @getimagesizefromstring( $body ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a corrupt file answers false, which is the test.
		if ( ! $info || empty( $info['mime'] ) || 0 !== strpos( (string) $info['mime'], 'image/' ) ) {
			throw new RuntimeException( __( 'That address did not return an image.', 'dazont-ecom' ) );
		}
		if ( '' !== $type && 0 !== strpos( $type, 'image/' ) ) {
			throw new RuntimeException( __( 'That address did not return an image.', 'dazont-ecom' ) );
		}
		return 'data:' . $info['mime'] . ';base64,' . base64_encode( $body );
	}

	/**
	 * Validates an image that arrived as a data URI and hands it back clean.
	 *
	 * Same checks as an image read from the web — the bytes have to BE an
	 * image, and stay under the ceiling — minus the fetch, since the browser
	 * already had the file.
	 */
	public static function read_data_uri( string $uri ): string {
		if ( ! preg_match( '#^data:(image/[a-z0-9.+-]+);base64,(.+)$#i', trim( $uri ), $m ) ) {
			throw new RuntimeException( __( 'That is not an image.', 'dazont-ecom' ) );
		}
		$bytes = base64_decode( $m[2], true );
		if ( false === $bytes || '' === $bytes || strlen( $bytes ) > self::MAX_REMOTE ) {
			throw new RuntimeException( __( 'That image is empty, or too heavy to send.', 'dazont-ecom' ) );
		}
		$info = @getimagesizefromstring( $bytes ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a corrupt file answers false, which is the test.
		if ( ! $info || empty( $info['mime'] ) || 0 !== strpos( (string) $info['mime'], 'image/' ) ) {
			throw new RuntimeException( __( 'That is not an image.', 'dazont-ecom' ) );
		}
		return 'data:' . $info['mime'] . ';base64,' . base64_encode( $bytes );
	}

	/**
	 * The fast lane: one photograph in, one catalogue main image out.
	 *
	 * The full toolbox asks which prompts, which scene, how many attempts, and
	 * then holds the result for review — right for a batch, far too slow for
	 * the one thing done constantly: a supplier photograph that cannot be the
	 * main image of a listing. Here there is one recipe, one source, one image,
	 * and the next click puts it in place.
	 */
	/**
	 * Proposes the store context by reading the shop.
	 *
	 * This line is prepended to every generation, so it decides the voice of
	 * the whole catalogue — and it is the hardest thing to write about your own
	 * shop, because you know it too well. The model does not: it is given the
	 * shop's name, its tagline, its best-selling categories and products and
	 * its price range, and asked for the three things that actually steer a
	 * copywriter — what this shop sells, who buys it, how to speak to them.
	 *
	 * Short on purpose. A paragraph here is a paragraph in front of every
	 * prompt, on every call, for every product.
	 */
	public function ajax_context(): void {
		$this->guard();
		if ( ! class_exists( 'DZE_Marketing_Ai' ) ) {
			wp_send_json_error( [ 'message' => __( 'The Marketing Assistant module holds the Anthropic key — switch it back on.', 'dazont-ecom' ) ] );
		}
		$facts = DZE_Marketing_Ai::instance()->shop_context_text();
		if ( '' === trim( $facts ) ) {
			wp_send_json_error( [ 'message' => __( 'There is not enough in this shop yet to read anything from it.', 'dazont-ecom' ) ] );
		}
		$system = 'You read a shop and describe it to the copywriter who will write its product pages.';
		$user   = "Here is what the shop is:\n\n" . $facts . "\n\n"
			. "Write its context line for that copywriter, in " . self::site_language() . ", in THREE short segments separated by \" > \":\n"
			. "1. the shop name and what it sells, in a few words;\n"
			. "2. who buys there — the real buyer, not a marketing persona;\n"
			. "3. the tone to write in — three adjectives at most.\n\n"
			. "Example of the shape expected: \"Kula Tactical > Military and tactical clothing and gear > Buyers: airsoft players, hunters, security staff who want kit that holds > Tone: sharp, factual, no hype\".\n"
			. "Answer with that single line and nothing else. No quotes, no preamble.";
		try {
			DZE_Ai_Usage::unit( 'store_context' );
			$out = DZE_Marketing_Ai::complete( $system, $user, self::model(), 300 );
		} catch ( \Throwable $e ) {
			DZE_Ai_Usage::unit();
			wp_send_json_error( [ 'message' => $e->getMessage() ] );
		}
		DZE_Ai_Usage::unit();
		DZE_Ai_Usage::finished( 'store_context' );
		wp_send_json_success( [
			'text'  => trim( wp_strip_all_tags( $out ) ),
			'facts' => $facts,
		] );
	}

	/**
	 * Switches one prompt on or off, there and then.
	 *
	 * A tick that only counts once the whole page has been saved is a tick you
	 * cannot trust: you leave the screen sure a field is off when it is still
	 * on. This writes that one flag and answers.
	 */
	public function ajax_prompt_toggle(): void {
		$this->guard();
		$id = isset( $_POST['id'] ) ? sanitize_key( wp_unslash( $_POST['id'] ) ) : '';
		$on = ! empty( $_POST['on'] ) ? 1 : 0;
		$rows  = self::registry();
		$found = false;
		foreach ( $rows as $k => $r ) {
			if ( (string) ( $r['id'] ?? '' ) === $id ) {
				$rows[ $k ]['enabled'] = $on;
				$found = true;
				break;
			}
		}
		if ( ! $found ) {
			wp_send_json_error( [ 'message' => __( 'Unknown prompt.', 'dazont-ecom' ) ] );
		}
		try {
			self::write_setting( 'registry', $rows );
		} catch ( \Throwable $e ) {
			wp_send_json_error( [ 'message' => $e->getMessage() ] );
		}
		wp_send_json_success( [ 'id' => $id, 'on' => $on ] );
	}

	/**
	 * Keeps an image as a background, from wherever it was picked.
	 *
	 * A background prepared outside WordPress — a studio floor for rugs, a
	 * table top — is chosen on the product screen, at the moment it is needed,
	 * and joins the same list the settings show. There is no second place to
	 * store one.
	 */
	public function ajax_bg_add(): void {
		$this->guard();
		$id   = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$name = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		if ( ! $id || ! wp_attachment_is_image( $id ) ) {
			wp_send_json_error( [ 'message' => __( 'That is not an image.', 'dazont-ecom' ) ] );
		}
		$settings = self::get_settings();
		$rows     = (array) ( $settings['scenes'] ?? [] );
		foreach ( $rows as $r ) {
			if ( (int) ( $r['image'] ?? 0 ) === $id ) {
				wp_send_json_success( [ 'id' => $id, 'already' => true ] ); // already kept.
			}
		}
		$rows[] = [
			'name'    => '' !== $name ? $name : __( 'Background', 'dazont-ecom' ),
			'image'   => $id,
			'prompt'  => '',
			'default' => empty( $rows ),
		];
		try {
			self::write_setting( 'scenes', $rows );
		} catch ( \Throwable $e ) {
			wp_send_json_error( [ 'message' => $e->getMessage() ] );
		}
		wp_send_json_success( [
			'id'    => $id,
			'name'  => (string) $rows[ count( $rows ) - 1 ]['name'],
			'thumb' => (string) wp_get_attachment_image_url( $id, 'thumbnail' ),
		] );
	}

	/** Builds the backdrop plate on demand and reports where it landed. */
	public function ajax_backdrop(): void {
		$this->guard();
		$light = isset( $_POST['light'] ) ? absint( $_POST['light'] ) : 252;
		$dark  = isset( $_POST['dark'] ) ? absint( $_POST['dark'] ) : 232;
		try {
			$id = self::make_backdrop( $light, $dark );
		} catch ( \Throwable $e ) {
			wp_send_json_error( [ 'message' => $e->getMessage() ] );
		}
		wp_send_json_success( [
			'id'    => $id,
			'name'  => __( 'Studio backdrop', 'dazont-ecom' ),
			'thumb' => (string) wp_get_attachment_image_url( $id, 'medium' ),
		] );
	}

	public function ajax_quick_main(): void {
		$this->guard();
		$pid  = isset( $_POST['post'] ) ? absint( $_POST['post'] ) : 0;
		$web  = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '';
		$note = isset( $_POST['note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['note'] ) ) : '';
		// A recipe typed for this run only, never saved unless asked.
		$override = isset( $_POST['prompt'] ) ? sanitize_textarea_field( wp_unslash( $_POST['prompt'] ) ) : '';
		// An image pasted straight into the lane (Ctrl+V or dropped): it arrives
		// as a data URI, never as a URL, so nothing is fetched from anywhere.
		$paste = isset( $_POST['paste'] ) ? (string) wp_unslash( $_POST['paste'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated as an image below.
		// The surface to put the product on: a background, or none.
		$bg = isset( $_POST['bg'] ) ? absint( $_POST['bg'] ) : 0;
		// ONE photograph of the product as the source — remaking a supplier
		// shot is work done on that shot, not on the product in general.
		$src_id = isset( $_POST['src_id'] ) ? absint( $_POST['src_id'] ) : 0;
		// Which recipe: a registry image prompt, or the main-image one.
		$recipe = isset( $_POST['recipe'] ) ? sanitize_key( wp_unslash( $_POST['recipe'] ) ) : '';
		if ( ! $pid ) {
			wp_send_json_error( [ 'message' => __( 'Save the product first.', 'dazont-ecom' ) ] );
		}
		if ( '' === self::fal_key() ) {
			wp_send_json_error( [ 'message' => __( 'Add your fal.ai key under Settings → General first.', 'dazont-ecom' ) ] );
		}
		if ( class_exists( 'DZE_Ai_Usage' ) && DZE_Ai_Usage::over_budget() ) {
			wp_send_json_error( [ 'message' => DZE_Ai_Usage::budget_message() ] );
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 180 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		try {
			$sources = [];
			if ( $src_id && wp_attachment_is_image( $src_id ) ) {
				$sources[] = $this->fal_source_data_uri( $src_id, 'full' );
			} elseif ( '' !== $paste ) {
				// The fastest path there is: the photograph is already in the
				// request, straight from the clipboard.
				$sources[] = self::read_data_uri( $paste );
			} elseif ( '' !== $web ) {
				// A pasted photograph is THE subject: sending the product's other
				// images beside it would only invite the model to blend them.
				$sources[] = $this->fetch_remote_image( $web );
			} else {
				// The product's own photographs, main first. Two are enough here:
				// this lane is about speed, and the shape of a product is settled
				// by the first shot plus one more angle.
				foreach ( array_slice( self::product_source_ids( $pid ), 0, 2 ) as $i => $aid ) {
					try {
						$sources[] = $this->fal_source_data_uri( (int) $aid, $i > 0 ? 'medium_large' : 'large' );
					} catch ( \Throwable $e ) {
						continue;
					}
				}
			}
			if ( ! $sources ) {
				throw new RuntimeException( __( 'No image to work from: set a featured image, or paste the address of one.', 'dazont-ecom' ) );
			}
			// The background travels as the LAST image, exactly like a scene: a
			// surface the model can see beats a colour it has to imagine, and it
			// is the same file for every product — which is the whole point.
			$plate = $bg && wp_attachment_is_image( $bg ) ? $bg : 0;
			$count = count( $sources );
			if ( $plate ) {
				$sources[] = $this->fal_source_data_uri( $plate );
			}
			$base = '' !== trim( $override ) ? $override : self::quick_prompt();
			if ( '' === trim( $override ) && '' !== $recipe ) {
				$row = self::registry_row( $recipe );
				if ( $row && '' !== trim( (string) ( $row['prompt'] ?? '' ) ) ) {
					$base = (string) $row['prompt'];
				}
			}
			$prompt = $base
				. ( '' !== $note ? "\n\nAlso: " . $note : '' )
				. self::sources_instruction(
					$count,
					$plate ? [ 'prompt' => 'This is the shop\'s backdrop: reproduce its exact tone and its gradient, and place the product on it with a soft contact shadow. Do not add anything else to it.' ] : null
				);

			DZE_Ai_Usage::unit( 'product_img' );
			$image_url = $this->fal_generate( $prompt, $sources );
			DZE_Ai_Usage::unit();
			DZE_Ai_Usage::finished( 'product_img' );
		} catch ( \Throwable $e ) {
			DZE_Ai_Usage::unit();
			wp_send_json_error( [ 'message' => $e->getMessage() ] );
		}

		// Kept with the product, like any other pending result: a closed tab
		// does not lose the image that was just paid for.
		self::stash( $pid, [ 'shot' => $image_url ] );
		$main = (int) get_post_thumbnail_id( $pid );
		wp_send_json_success( [
			'url'  => $image_url,
			'main' => $main ? (string) wp_get_attachment_image_url( $main, 'large' ) : '',
		] );
	}

	public function ajax_image(): void {
		$this->guard();
		$pid    = isset( $_POST['post'] ) ? absint( $_POST['post'] ) : 0;
		$idx    = isset( $_POST['template'] ) ? absint( $_POST['template'] ) : 0;
		$mode   = isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : '';
		$custom = isset( $_POST['custom_prompt'] ) ? sanitize_textarea_field( wp_unslash( $_POST['custom_prompt'] ) ) : '';
		$src    = isset( $_POST['src_url'] ) ? esc_url_raw( wp_unslash( $_POST['src_url'] ) ) : '';
		if ( ! $pid ) {
			wp_send_json_error( [ 'message' => __( 'Save the product first.', 'dazont-ecom' ) ] );
		}
		if ( '' === self::fal_key() ) {
			wp_send_json_error( [ 'message' => __( 'Add your fal.ai key under Settings → General first.', 'dazont-ecom' ) ] );
		}
		if ( class_exists( 'DZE_Ai_Usage' ) && DZE_Ai_Usage::over_budget() ) {
			wp_send_json_error( [ 'message' => DZE_Ai_Usage::budget_message() ] );
		}
		$templates = self::image_templates();
		$tpl       = $templates[ $idx ] ?? $templates[0] ?? null;
		if ( ! $tpl && '' === $custom ) {
			wp_send_json_error( [ 'message' => __( 'No image template configured.', 'dazont-ecom' ) ] );
		}

		// Source image: an earlier AI result (live edit) or the featured image.
		if ( '' !== $src && ! self::is_fal_url( $src ) ) {
			wp_send_json_error( [ 'message' => __( 'Invalid source image.', 'dazont-ecom' ) ] );
		}
		// The scene: the fixed support or background this shop always shoots on.
		// A one-off edit of an image that already exists ("make the strap red")
		// keeps that image's own setting, so the scene only comes back if it was
		// explicitly asked for.
		$scenes = self::scenes();
		$sidx   = isset( $_POST['scene'] ) ? (int) $_POST['scene'] : ( '' !== $src ? -1 : self::default_scene() );
		$scene  = ( $sidx >= 0 && isset( $scenes[ $sidx ] ) ) ? $scenes[ $sidx ] : null;
		if ( $scene && ! wp_attachment_is_image( (int) $scene['image'] ) ) {
			// Deleted from the media library: say so instead of failing on the
			// product image, which is what the generic reader error would blame.
			wp_send_json_error( [ 'message' => __( 'The scene image is missing from the media library — pick it again under Settings → Product content.', 'dazont-ecom' ) ] );
		}
		// Every photograph of the product goes out, not just the featured one:
		// a single cropped shot is what makes the model invent the rest.
		$product_ids = self::product_source_ids( $pid );
		if ( '' === $src && ! $product_ids ) {
			wp_send_json_error( [ 'message' => __( 'Set a featured image on this product first.', 'dazont-ecom' ) ] );
		}

		$pl   = $tpl ? self::payload_lines( $pid, (array) ( $tpl['inputs'] ?? [ 'title', 'description' ] ), (string) ( $tpl['inputs_meta'] ?? '' ) ) : self::payload_lines( $pid, [ 'title', 'description' ] );
		$pl   = mb_substr( trim( (string) preg_replace( '/\s+/', ' ', $pl ) ), 0, 800 );
		$ctx  = trim( self::store_context() . ' ' . $pl );
		$base = '' !== $custom ? $custom : (string) $tpl['prompt'];
		$prompt = ( $ctx ? "Product context: {$ctx}\n\n" : '' ) . $base;

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 180 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		$validated = self::template_validated( $idx );
		try {
			// Sources: fal's own CDN URLs pass through; local files go as data URIs
			// (fal cannot always fetch staging/hotlink-protected site URLs).
			$sources = [];
			$weight  = 0;
			if ( '' !== $src ) {
				// Editing one precise image: that image is the subject, on its own.
				$sources[] = $src;
			} else {
				// Everything we have of this product. The featured image comes
				// first and is never dropped; the rest joins while the request
				// body stays a sane size, and a broken file is skipped instead
				// of taking the whole generation down with it.
				foreach ( $product_ids as $i => $aid ) {
					try {
						// The featured image is the one the result is built on,
						// so it goes at full working size; the others are read
						// for information only and travel smaller — a lighter
						// request body and a faster answer, same understanding.
						$uri = $this->fal_source_data_uri( (int) $aid, $i > 0 ? 'medium_large' : 'large' );
					} catch ( \Throwable $e ) {
						continue;
					}
					if ( $i > 0 && ( $weight + strlen( $uri ) ) > self::MAX_PAYLOAD ) {
						break;
					}
					$weight   += strlen( $uri );
					$sources[] = $uri;
				}
			}
			if ( ! $sources ) {
				throw new RuntimeException( __( 'Could not read the product image file.', 'dazont-ecom' ) );
			}
			$product_count = count( $sources );
			if ( $scene ) {
				$sources[] = $this->fal_source_data_uri( (int) $scene['image'] );
			}
			$prompt   .= self::sources_instruction( $product_count, $scene );
			DZE_Ai_Usage::unit( 'product_img' );
			$image_url = $this->fal_generate( $prompt, $sources );
			DZE_Ai_Usage::unit();
			DZE_Ai_Usage::finished( 'product_img' );

			if ( 'defer' === $mode ) {
				// Toolbox flow: never auto-attach — the result joins the session
				// gallery; a human selects what gets pushed to the product.
				if ( ! empty( $_POST['stash'] ) ) {
					self::stash( $pid, [ 'shot' => $image_url ] );
				}
				wp_send_json_success( [ 'url' => $image_url, 'target' => $tpl['target'] ?? 'gallery' ] );
			}
			if ( ! $validated ) {
				wp_send_json_success( [ 'preview' => true, 'url' => $image_url, 'target' => $tpl['target'] ?? 'gallery' ] );
			}
			$att_id = $this->sideload_seo( $image_url, $pid, (string) ( $tpl['target'] ?? 'gallery' ) );
		} catch ( \Throwable $e ) {
			wp_send_json_error( [ 'message' => $e->getMessage() ] );
		}
		wp_send_json_success( [
			'attachment' => (int) $att_id,
			'target'     => $tpl['target'] ?? 'gallery',
			'url'        => wp_get_attachment_image_url( (int) $att_id, 'medium' ),
		] );
	}

	/** Only fal's own delivery hosts are accepted as remote sources (no SSRF). */
	public static function is_fal_url( string $url ): bool {
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );
		return 'fal.media' === $host || str_ends_with( $host, '.fal.media' ) || str_ends_with( $host, '.fal.run' ) || 'fal.run' === $host;
	}

	/**
	 * Pushes selected session-gallery images onto the product. Standard SEO
	 * procedure on the way in: the attachment file name, title, slug and alt all
	 * take the product title (WordPress natively de-duplicates with -1/-2/-3).
	 */
	/**
	 * The bulk list is the owner's working set, so it has to be editable: take
	 * one product out, take the ticked ones out, or empty it. Before this the
	 * only way to change your mind was to go back to the products list and
	 * queue a new selection from scratch.
	 */
	public function ajax_bulk_list(): void {
		$this->guard();
		$do  = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';
		$ids = isset( $_POST['ids'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['ids'] ) ) : [];

		if ( 'clear' === $do ) {
			self::set_bulk_list( [] );
			wp_send_json_success( [ 'left' => [] ] );
		}
		if ( 'remove' === $do && $ids ) {
			$list = array_values( array_diff( self::bulk_list(), $ids ) );
			self::set_bulk_list( $list );
			// The list as it now stands, read back: the screen shows what the
			// server holds, not what it hoped the server would hold.
			wp_send_json_success( [ 'left' => self::bulk_list() ] );
		}
		if ( 'add' === $do ) {
			$this->bulk_add_ids( $ids, ! empty( $_POST['replace'] ) );
		}
		wp_send_json_error( [ 'message' => __( 'Invalid request.', 'dazont-ecom' ) ] );
	}

	/**
	 * Adds pasted IDs to the working list and says exactly what happened to
	 * each one.
	 *
	 * ONE query for the whole paste: four hundred `get_post()` calls would be
	 * four hundred queries. Anything that is not a published or draft product
	 * comes back named, so a wrong column in the spreadsheet is visible instead
	 * of being silently dropped.
	 */
	private function bulk_add_ids( array $ids, bool $replace ): void {
		global $wpdb;
		$ids = array_values( array_unique( array_filter( $ids ) ) );
		if ( ! $ids ) {
			wp_send_json_error( [ 'message' => __( 'No ID found in what you pasted.', 'dazont-ecom' ) ] );
		}
		if ( count( $ids ) > self::PASTE_MAX ) {
			wp_send_json_error( [
				'message' => sprintf(
					/* translators: %s: maximum number of products */
					__( 'That is more than %s products at once. Split the list.', 'dazont-ecom' ),
					number_format_i18n( self::PASTE_MAX )
				),
			] );
		}
		$holes = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $holes is a placeholder list built from the count.
		$found = $wpdb->get_col( $wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts}
			 WHERE ID IN ({$holes}) AND post_type = 'product' AND post_status NOT IN ( 'trash', 'auto-draft' )",
			...$ids
		) );
		$found   = array_map( 'intval', (array) $found );
		$unknown = array_values( array_diff( $ids, $found ) );

		$before = $replace ? [] : self::bulk_list();
		$dupes  = array_values( array_intersect( $found, $before ) );
		self::set_bulk_list( array_merge( $before, $found ) );

		wp_send_json_success( [
			'added'   => count( $found ) - count( $dupes ),
			'already' => count( $dupes ),
			'unknown' => array_slice( $unknown, 0, 30 ), // enough to fix the spreadsheet.
			'unknownN'=> count( $unknown ),
			'total'   => count( self::bulk_list() ),
		] );
	}

	/**
	 * What the product says TODAY: its texts and its photographs.
	 *
	 * Fetched only when a review panel is opened, never with the list — it is
	 * one product's worth of data, asked for at the moment somebody wants to
	 * compare the new text with the old one, or check that a generated image
	 * adds something the gallery does not already have.
	 */
	public function ajax_current(): void {
		$this->guard();
		$pid     = isset( $_POST['post'] ) ? absint( $_POST['post'] ) : 0;
		$product = $pid ? wc_get_product( $pid ) : null;
		if ( ! $product instanceof WC_Product ) {
			wp_send_json_error( [ 'message' => __( 'Product not found.', 'dazont-ecom' ) ] );
		}
		$seo   = self::seo_keys();
		$texts = [];
		foreach ( self::enabled_fields() as $fid => $f ) {
			$dest  = self::dest_for( (string) $fid );
			$value = '';
			switch ( $dest['type'] ) {
				case 'post_title':
					$value = get_the_title( $pid );
					break;
				case 'post_content':
					$value = (string) get_post_field( 'post_content', $pid );
					break;
				case 'post_excerpt':
					$value = (string) get_post_field( 'post_excerpt', $pid );
					break;
				case 'seo_title':
					$value = (string) get_post_meta( $pid, $seo['title'], true );
					break;
				case 'seo_desc':
					$value = (string) get_post_meta( $pid, $seo['desc'], true );
					break;
				case 'attributes':
					$value = self::attributes_summary( $product );
					break;
				default:
					$value = (string) get_post_meta( $pid, (string) ( $dest['key'] ?? '_dze_' . $fid ), true );
			}
			$texts[ $fid ] = $value;
		}
		$images = [];
		foreach ( self::product_source_ids( $pid ) as $aid ) {
			$images[] = [
				// The id travels too: the image workshop works ON one of these.
				'id'    => (int) $aid,
				'thumb' => (string) ( wp_get_attachment_image_url( (int) $aid, 'thumbnail' ) ?: '' ),
				'full'  => (string) ( wp_get_attachment_image_url( (int) $aid, 'large' ) ?: '' ),
				'main'  => (int) $aid === (int) get_post_thumbnail_id( $pid ),
			];
		}
		wp_send_json_success( [
			'texts'   => $texts,
			'images'  => $images,
			// Everything the popup needs to work on a product it was not opened
			// from: its name, its cost, and whatever is already waiting on it.
			'title'   => $product->get_name(),
			'cost'    => self::product_cost( $product ),
			'pending' => self::pending( $pid ),
		] );
	}

	/** Accepted or discarded: either way the product stops waiting. */
	/**
	 * Forgets what is waiting on a product — all of it, or only the pieces that
	 * have just been dealt with.
	 *
	 * Applying one image used to throw away everything else that was waiting,
	 * which is how a generation you had not decided on yet disappeared while
	 * you were saving another one. What was applied is dropped; what was not is
	 * still there when you come back.
	 */
	public function ajax_pending_clear(): void {
		$this->guard();
		$pid    = isset( $_POST['post'] ) ? absint( $_POST['post'] ) : 0;
		$shots  = isset( $_POST['shots'] ) ? array_map( 'esc_url_raw', (array) wp_unslash( $_POST['shots'] ) ) : [];
		$fields = isset( $_POST['fields'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['fields'] ) ) : [];
		if ( ! $pid ) {
			wp_send_json_error( [ 'message' => __( 'Product not found.', 'dazont-ecom' ) ] );
		}
		if ( ! $shots && ! $fields ) {
			delete_post_meta( $pid, self::META_PENDING );
			delete_transient( 'dze_pending_count' );
			wp_send_json_success( [ 'cleared' => $pid, 'left' => [], 'waiting' => self::pending_count() ] );
		}
		$waiting = self::pending( $pid );
		if ( $shots ) {
			$waiting['shots'] = array_values( array_diff( (array) ( $waiting['shots'] ?? [] ), $shots ) );
		}
		foreach ( $fields as $fid ) {
			unset( $waiting['texts'][ $fid ], $waiting['companions'][ $fid ] );
		}
		if ( empty( $waiting['shots'] ) && empty( $waiting['texts'] ) ) {
			delete_post_meta( $pid, self::META_PENDING );
		} else {
			update_post_meta( $pid, self::META_PENDING, $waiting );
		}
		delete_transient( 'dze_pending_count' );
		wp_send_json_success( [ 'left' => self::pending( $pid ), 'waiting' => self::pending_count() ] );
	}

	public function ajax_image_attach(): void {
		$this->guard();
		$pid = isset( $_POST['post'] ) ? absint( $_POST['post'] ) : 0;

		// Each image says where IT goes. A single destination for the batch made
		// "one of these is the main image, that one goes second" impossible to
		// express, which is exactly the decision being made at that moment.
		$items = [];
		if ( isset( $_POST['items'] ) && is_array( $_POST['items'] ) ) {
			foreach ( wp_unslash( $_POST['items'] ) as $it ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized below.
				$items[] = [
					'url'    => esc_url_raw( (string) ( $it['url'] ?? '' ) ),
					'target' => self::attach_target( (string) ( $it['target'] ?? '' ) ),
				];
			}
		} else {
			// Older callers: a list of urls and one destination for all of them.
			$target = self::attach_target( isset( $_POST['target'] ) ? (string) wp_unslash( $_POST['target'] ) : '' );
		// The supplier shot a remake replaces is of no further use: taking it out
		// of the product and out of the library is what leaves a clean page and
		// a clean media folder behind. Only ever an image of THIS product.
		$replace = isset( $_POST['replace'] ) ? absint( $_POST['replace'] ) : 0;
			foreach ( (array) ( $_POST['urls'] ?? [] ) as $i => $u ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized below.
				$items[] = [
					'url'    => esc_url_raw( (string) wp_unslash( $u ) ),
					// Only the first of a batch could ever be the main image.
					'target' => ( 'main' === $target && 0 !== $i ) ? 'gallery' : $target,
				];
			}
		}
		if ( ! $pid || empty( $items ) ) {
			wp_send_json_error( [ 'message' => __( 'Nothing selected.', 'dazont-ecom' ) ] );
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		$ids     = [];
		$errors  = 0;
		$main_up = false;
		foreach ( $items as $item ) {
			$u = (string) $item['url'];
			if ( '' === $u || ! self::is_fal_url( $u ) ) {
				$errors++;
				continue;
			}
			$t = (string) $item['target'];
			// Two main images cannot both win: the first one asked for it.
			if ( 'main' === $t ) {
				if ( $main_up ) {
					$t = 'gallery';
				} else {
					$main_up = true;
				}
			}
			try {
				$ids[] = $this->sideload_seo( $u, $pid, $t );
			} catch ( \Throwable $e ) {
				$errors++;
			}
		}
		if ( empty( $ids ) ) {
			wp_send_json_error( [ 'message' => __( 'Could not attach the selected image(s).', 'dazont-ecom' ) ] );
		}
		$removed = 0;
		if ( $replace && in_array( $replace, self::product_source_ids( $pid ), true ) ) {
			$removed = (int) self::retire_image( $pid, $replace );
		}
		wp_send_json_success( [
			'attached' => count( $ids ),
			'errors'   => $errors,
			'ids'      => $ids,
			'removed'  => $removed,
		] );
	}

	/**
	 * Sideloads a generated image with SEO naming: file name = product slug
	 * (WordPress appends -1/-2/-3 natively on collision), attachment title/slug =
	 * product title, alt text set. Attaches as main image or appends to the
	 * product gallery.
	 */
	/**
	 * Takes one photograph off a product and out of the library.
	 *
	 * Used when a remake replaces a supplier shot: the shot is removed from the
	 * gallery, from the featured slot if it held it, and the file is deleted —
	 * a catalogue rebuilt with this plugin should not leave the supplier's
	 * originals behind, on the page or on the disk.
	 *
	 * @return bool whether the file was deleted.
	 */
	public static function retire_image( int $pid, int $att_id ): bool {
		$gallery = (string) get_post_meta( $pid, '_product_image_gallery', true );
		$ids     = array_filter( array_map( 'absint', explode( ',', $gallery ) ) );
		$ids     = array_values( array_diff( $ids, [ $att_id ] ) );
		update_post_meta( $pid, '_product_image_gallery', implode( ',', $ids ) );
		if ( (int) get_post_thumbnail_id( $pid ) === $att_id ) {
			// The product must not be left without a main image: the first
			// gallery photograph takes the slot, and leaves the gallery.
			$next = (int) ( $ids[0] ?? 0 );
			if ( $next ) {
				set_post_thumbnail( $pid, $next );
				update_post_meta( $pid, '_product_image_gallery', implode( ',', array_slice( $ids, 1 ) ) );
			} else {
				delete_post_thumbnail( $pid );
			}
		}
		clean_post_cache( $pid );
		if ( function_exists( 'wc_delete_product_transients' ) ) {
			wc_delete_product_transients( $pid );
		}
		// Attached to another product as well? Then it is not ours to delete.
		global $wpdb;
		$others = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_thumbnail_id' AND meta_value = %d",
			$att_id
		) );
		if ( $others > 0 ) {
			return false;
		}
		return (bool) wp_delete_attachment( $att_id, true );
	}

	/** gallery (default) | gallery_first (second photo of the product) | main. */
	public static function attach_target( string $t ): string {
		return in_array( $t, [ 'main', 'gallery_first' ], true ) ? $t : 'gallery';
	}

	public function sideload_seo( string $url, int $pid, string $target ): int {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$title = get_the_title( $pid );
		$slug  = sanitize_title( $title ) ?: 'product-image';

		$tmp = download_url( $url, 120 );
		if ( is_wp_error( $tmp ) ) {
			throw new RuntimeException( $tmp->get_error_message() );
		}
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		$ext  = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, [ 'png', 'jpg', 'jpeg', 'webp' ], true ) ) {
			$ext = 'jpg';
		}
		// The provider is asked for JPEG; if it hands back a PNG anyway, it is
		// converted here rather than shipped to the shop — these are opaque
		// photographs, and a PNG of one weighs several times as much.
		if ( 'png' === $ext ) {
			$editor = wp_get_image_editor( $tmp );
			if ( ! is_wp_error( $editor ) ) {
				$editor->set_quality( 85 );
				$saved = $editor->save( $tmp . '.jpg', 'image/jpeg' );
				if ( ! is_wp_error( $saved ) && ! empty( $saved['path'] ) && file_exists( $saved['path'] ) ) {
					@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
					$tmp = (string) $saved['path'];
					$ext = 'jpg';
				}
			}
		}
		$att_id = media_handle_sideload( [ 'name' => $slug . '.' . $ext, 'tmp_name' => $tmp ], $pid, $title );
		if ( is_wp_error( $att_id ) ) {
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
			throw new RuntimeException( $att_id->get_error_message() );
		}
		// Attachment title + slug match the product (WP uniquifies the slug natively).
		wp_update_post( [ 'ID' => (int) $att_id, 'post_title' => $title, 'post_name' => $slug ] );
		update_post_meta( (int) $att_id, '_wp_attachment_image_alt', $title );

		$gallery = (string) get_post_meta( $pid, '_product_image_gallery', true );
		$ids     = array_filter( array_map( 'absint', explode( ',', $gallery ) ) );
		if ( 'main' === $target ) {
			// The replaced main image is never lost: it moves to the FRONT of the
			// product gallery so it stays first among the secondary images. And an
			// image cannot be the main one AND a gallery one — the shop would show
			// it twice — so the newcomer leaves the gallery as it takes the top
			// spot. The row is written even when there was no main image before,
			// otherwise that de-duplication would never reach the database.
			$old = (int) get_post_thumbnail_id( $pid );
			$ids = array_values( array_diff( $ids, [ (int) $att_id ] ) );
			if ( $old && $old !== (int) $att_id ) {
				array_unshift( $ids, $old );
			}
			update_post_meta( $pid, '_product_image_gallery', implode( ',', array_unique( $ids ) ) );
			set_post_thumbnail( $pid, (int) $att_id );
		} elseif ( 'gallery_first' === $target ) {
			// Second photograph of the product: the one a visitor sees right
			// after the main image, and the one most likely to sell the detail.
			array_unshift( $ids, (int) $att_id );
			update_post_meta( $pid, '_product_image_gallery', implode( ',', array_unique( $ids ) ) );
		} else {
			$ids[] = (int) $att_id;
			update_post_meta( $pid, '_product_image_gallery', implode( ',', array_unique( $ids ) ) );
		}
		// WooCommerce reads the gallery from its own cached product object: a raw
		// meta write is invisible to the shop, and to this very screen, until that
		// copy is dropped. Skipping this is how a correct move looks like nothing
		// happened at all.
		clean_post_cache( $pid );
		if ( function_exists( 'wc_delete_product_transients' ) ) {
			wc_delete_product_transients( $pid );
		}
		return (int) $att_id;
	}

	/**
	 * Live prompt save from the product toolbox: fixes a prompt for good the
	 * moment an anomaly is spotted, without a trip to the settings screen.
	 */
	public function ajax_save_prompt(): void {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'dazont-ecom' ) ], 403 );
		}
		$type   = isset( $_POST['ptype'] ) ? sanitize_key( wp_unslash( $_POST['ptype'] ) ) : '';
		$prompt = isset( $_POST['prompt'] ) ? sanitize_textarea_field( wp_unslash( $_POST['prompt'] ) ) : '';
		if ( '' === trim( $prompt ) ) {
			wp_send_json_error( [ 'message' => __( 'Empty prompt.', 'dazont-ecom' ) ] );
		}
		// Resolve the registry row id to update.
		$row_id = '';
		if ( 'field' === $type ) {
			$row_id = isset( $_POST['field'] ) ? sanitize_key( wp_unslash( $_POST['field'] ) ) : '';
		} elseif ( 'template' === $type ) {
			$idx  = isset( $_POST['index'] ) ? absint( $_POST['index'] ) : 0;
			$tpls = self::image_templates();
			$row_id = (string) ( $tpls[ $idx ]['id'] ?? '' );
		}
		// The main-image recipe is not a registry row: it has a setting of its own.
		if ( 'quick' === $type ) {
			try {
				self::write_setting( 'quick_prompt', ( trim( $prompt ) === trim( self::default_quick_prompt() ) ) ? '' : $prompt );
			} catch ( \Throwable $e ) {
				wp_send_json_error( [ 'message' => $e->getMessage() ] );
			}
			wp_send_json_success( [ 'saved' => true ] );
		}
		if ( '' === $row_id ) {
			wp_send_json_error( [ 'message' => __( 'Invalid request.', 'dazont-ecom' ) ] );
		}
		$settings = self::get_settings();
		$rows     = self::registry();
		$found    = false;
		foreach ( $rows as $k => $r ) {
			if ( ( $r['id'] ?? '' ) === $row_id ) {
				$rows[ $k ]['prompt'] = $prompt;
				$found = true;
				break;
			}
		}
		if ( ! $found ) {
			wp_send_json_error( [ 'message' => __( 'Unknown prompt.', 'dazont-ecom' ) ] );
		}
		$settings['registry'] = $rows;
		$this->write_settings_direct( $settings );
		self::$registry_cache = null;
		// Read back — report a real failure instead of a fake ✓.
		$check = self::registry_row( $row_id );
		if ( ! $check || (string) ( $check['prompt'] ?? '' ) !== $prompt ) {
			wp_send_json_error( [ 'message' => __( 'The prompt was not persisted — please save it from Settings instead.', 'dazont-ecom' ) ] );
		}
		wp_send_json_success( [ 'saved' => true ] );
	}

	/**
	 * Writes ALREADY-CANONICAL settings without WordPress re-running our
	 * sanitizer on them (update_option triggers the sanitize_option filter,
	 * which is shaped for form input and has historically eaten programmatic
	 * saves). The callers only pass arrays built from stored settings.
	 */
	private function write_settings_direct( array $settings ): void {
		$tag = 'sanitize_option_' . self::OPT_SETTINGS;
		remove_filter( $tag, [ $this, 'sanitize' ] );
		update_option( self::OPT_SETTINGS, $settings, false );
		add_filter( $tag, [ $this, 'sanitize' ] );
	}

	/**
	 * Restores the SHIPPED default prompts: drops the stored registry and every
	 * legacy prompt override so registry() falls back to the built-in defaults
	 * (the original spreadsheet prompts + default image templates). Custom
	 * prompt rows are removed and validation flags reset — hence the explicit
	 * confirmation in the UI before calling this.
	 */
	public function ajax_reset_prompts(): void {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'dazont-ecom' ) ], 403 );
		}
		$s = self::get_settings();
		unset( $s['registry'], $s['image_templates'], $s['fv'], $s['fe'], $s['prompts_validated'] );
		foreach ( array_keys( $s ) as $k ) {
			if ( preg_match( '/^(prompt|dest|metakey|map)_/', (string) $k ) ) {
				unset( $s[ $k ] );
			}
		}
		$this->write_settings_direct( $s );
		self::$registry_cache = null;
		wp_send_json_success( [ 'reset' => true ] );
	}

	/**
	 * AJAX save of the Product-content settings form — same data, same
	 * sanitizer (it runs inside update_option), no page reload.
	 */
	public function ajax_save_settings(): void {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'dazont-ecom' ) ], 403 );
		}
		$in = isset( $_POST[ self::OPT_SETTINGS ] ) && is_array( $_POST[ self::OPT_SETTINGS ] )
			? (array) wp_unslash( $_POST[ self::OPT_SETTINGS ] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- runs through the registered sanitizer below.
			: [];
		if ( empty( $in ) ) {
			wp_send_json_error( [ 'message' => __( 'Nothing to save.', 'dazont-ecom' ) ] );
		}
		update_option( self::OPT_SETTINGS, $in, false );
		self::$registry_cache = null;
		wp_send_json_success( [ 'saved' => true ] );
	}

	/**
	 * Toggle a prompt's Validated flag straight from the toolbox — no round trip
	 * to Settings. Same capability as the settings page.
	 */
	public function ajax_validate_prompt(): void {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'dazont-ecom' ) ], 403 );
		}
		$type = isset( $_POST['ptype'] ) ? sanitize_key( wp_unslash( $_POST['ptype'] ) ) : '';
		$on   = ! empty( $_POST['on'] ) ? 1 : 0;
		$row_id = '';
		if ( 'field' === $type ) {
			$row_id = isset( $_POST['field'] ) ? sanitize_key( wp_unslash( $_POST['field'] ) ) : '';
		} elseif ( 'template' === $type ) {
			$idx    = isset( $_POST['index'] ) ? absint( $_POST['index'] ) : 0;
			$tpls   = self::image_templates();
			$row_id = (string) ( $tpls[ $idx ]['id'] ?? '' );
		}
		if ( '' === $row_id ) {
			wp_send_json_error( [ 'message' => __( 'Invalid request.', 'dazont-ecom' ) ] );
		}
		$settings = self::get_settings();
		$rows     = self::registry();
		$found    = false;
		foreach ( $rows as $k => $r ) {
			if ( ( $r['id'] ?? '' ) === $row_id ) {
				$rows[ $k ]['valid'] = $on;
				$found = true;
				break;
			}
		}
		if ( ! $found ) {
			wp_send_json_error( [ 'message' => __( 'Unknown prompt.', 'dazont-ecom' ) ] );
		}
		$settings['registry'] = $rows;
		$this->write_settings_direct( $settings );
		self::$registry_cache = null;
		$check = self::registry_row( $row_id );
		if ( ! $check || (int) ! empty( $check['valid'] ) !== $on ) {
			wp_send_json_error( [ 'message' => __( 'The change was not persisted — please use Settings instead.', 'dazont-ecom' ) ] );
		}
		wp_send_json_success( [ 'valid' => (bool) $on ] );
	}

	/**
	 * Reads an attachment (preferring the 'large' size to bound the payload) and
	 * returns it as a base64 data URI, which fal decodes directly — removing every
	 * "fal cannot reach the source URL" failure (private staging, hotlink rules).
	 */
	/**
	 * Every photograph of the product, featured image first, then the gallery.
	 *
	 * One photo is rarely enough to describe a product: a cropped in-situ shot
	 * hides half the pattern, a macro hides the shape. Given the whole set, the
	 * model has no gap left to fill in with an invention of its own — which is
	 * exactly how a rug comes back with a design that was never woven.
	 *
	 * Capped, because each image travels as base64 in the request body and a
	 * 20-photo gallery would turn one generation into a 30 MB upload.
	 *
	 * @return int[] Attachment ids.
	 */
	public static function product_source_ids( int $pid ): array {
		$ids     = [];
		$product = function_exists( 'wc_get_product' ) ? wc_get_product( $pid ) : null;
		$thumb   = (int) get_post_thumbnail_id( $pid );
		if ( $thumb ) {
			$ids[] = $thumb;
		}
		if ( $product ) {
			foreach ( (array) $product->get_gallery_image_ids() as $gid ) {
				$ids[] = (int) $gid;
			}
		}
		$ids = array_values( array_unique( array_filter( $ids ) ) );
		$ids = array_values( array_filter( $ids, static fn( $id ) => wp_attachment_is_image( (int) $id ) ) );
		return array_slice( $ids, 0, self::MAX_SOURCES );
	}

	public function fal_source_data_uri( int $attachment_id, string $wanted = 'large' ): string {
		$path = '';
		$size = image_get_intermediate_size( $attachment_id, $wanted );
		if ( is_array( $size ) && ! empty( $size['path'] ) ) {
			$uploads = wp_get_upload_dir();
			$try     = trailingslashit( (string) $uploads['basedir'] ) . $size['path'];
			if ( file_exists( $try ) ) {
				$path = $try;
			}
		}
		if ( '' === $path ) {
			$try = (string) get_attached_file( $attachment_id );
			if ( $try && file_exists( $try ) ) {
				$path = $try;
			}
		}
		$bytes = '' !== $path ? file_get_contents( $path ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file.
		if ( false === $bytes || '' === $bytes ) {
			$url  = (string) wp_get_attachment_image_url( $attachment_id, $wanted );
			$resp = $url ? wp_remote_get( $url, [ 'timeout' => 30 ] ) : null;
			if ( $resp && ! is_wp_error( $resp ) && 200 === (int) wp_remote_retrieve_response_code( $resp ) ) {
				$bytes = wp_remote_retrieve_body( $resp );
			}
		}
		if ( false === $bytes || '' === $bytes ) {
			throw new RuntimeException( __( 'Could not read the product image file.', 'dazont-ecom' ) );
		}
		$mime = (string) ( get_post_mime_type( $attachment_id ) ?: 'image/jpeg' );
		return 'data:' . $mime . ';base64,' . base64_encode( $bytes ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- data URI.
	}

	/**
	 * An image made from words alone — no source photograph.
	 *
	 * Used to make a background: a studio backdrop or a floor to lay rugs on is
	 * described, not photographed. The edit endpoint refuses a call without a
	 * source image, hence a second one.
	 */
	public function fal_create( string $prompt, string $ratio = '1:1' ): string {
		$resp = wp_remote_post( self::FAL_CREATE, [
			'timeout' => 120,
			'headers' => [ 'Authorization' => 'Key ' . self::fal_key(), 'content-type' => 'application/json' ],
			'body'    => wp_json_encode( [
				'prompt'        => $prompt,
				'num_images'    => 1,
				'aspect_ratio'  => $ratio,
				'output_format' => 'jpeg',
			] ),
		] );
		if ( is_wp_error( $resp ) ) {
			throw new RuntimeException( $resp->get_error_message() );
		}
		$code = wp_remote_retrieve_response_code( $resp );
		$body = json_decode( wp_remote_retrieve_body( $resp ), true );
		if ( $code < 200 || $code >= 300 ) {
			$msg = is_array( $body ) && is_string( $body['detail'] ?? null ) ? $body['detail'] : 'HTTP ' . $code;
			/* translators: %s: the error returned by fal.ai */
			throw new RuntimeException( sprintf( __( 'fal.ai error: %s', 'dazont-ecom' ), mb_substr( $msg, 0, 300 ) ) );
		}
		$url = (string) ( $body['images'][0]['url'] ?? '' );
		if ( '' === $url ) {
			throw new RuntimeException( __( 'fal.ai returned no image.', 'dazont-ecom' ) );
		}
		if ( class_exists( 'DZE_Ai_Usage' ) ) {
			DZE_Ai_Usage::record( 'fal', 0, 0, 'nano-banana-2', self::fal_image_cost() );
		}
		return $url;
	}

	/**
	 * Puts a generated image in the media library, attached to nothing.
	 *
	 * A background belongs to the shop, not to a product: sideload_seo() names
	 * files after the product they illustrate, which is exactly wrong here.
	 */
	public function sideload_library( string $url, string $title ): int {
		if ( ! self::is_fal_url( $url ) ) {
			throw new RuntimeException( __( 'Invalid image source.', 'dazont-ecom' ) );
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$tmp = download_url( $url, 60 );
		if ( is_wp_error( $tmp ) ) {
			throw new RuntimeException( $tmp->get_error_message() );
		}
		$id = media_handle_sideload(
			[ 'name' => sanitize_file_name( $title ?: 'background' ) . '.jpg', 'tmp_name' => $tmp ],
			0,
			$title
		);
		if ( is_wp_error( $id ) ) {
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
			throw new RuntimeException( $id->get_error_message() );
		}
		return (int) $id;
	}

	/** Describes a background, makes it, and puts it on the shelf. */
	public function ajax_bg_make(): void {
		$this->guard();
		$desc  = isset( $_POST['desc'] ) ? sanitize_textarea_field( wp_unslash( $_POST['desc'] ) ) : '';
		$ratio = isset( $_POST['ratio'] ) ? sanitize_text_field( wp_unslash( $_POST['ratio'] ) ) : '1:1';
		$ratio = in_array( $ratio, [ '1:1', '4:3', '3:4', '16:9' ], true ) ? $ratio : '1:1';
		if ( '' === trim( $desc ) ) {
			wp_send_json_error( [ 'message' => __( 'Describe the background you want.', 'dazont-ecom' ) ] );
		}
		if ( '' === self::fal_key() ) {
			wp_send_json_error( [ 'message' => __( 'Add your fal.ai key under Settings → General first.', 'dazont-ecom' ) ] );
		}
		if ( class_exists( 'DZE_Ai_Usage' ) && DZE_Ai_Usage::over_budget() ) {
			wp_send_json_error( [ 'message' => DZE_Ai_Usage::budget_message() ] );
		}
		// An EMPTY set: whatever is described, no product may appear in it, or
		// the model will happily put one there and it will end up behind every
		// product of the shop.
		$prompt = trim( $desc )
			. "

This image is a BACKGROUND PLATE, an empty set: a surface and its lighting, nothing else. "
			. 'No product, no object, no person, no animal, no text, no logo, no watermark, no border. '
			. 'Even lighting, no strong shadow of its own, plenty of clear space in the middle where a product will be placed later. '
			. 'Photographic, not illustrated.';
		try {
			DZE_Ai_Usage::unit( 'background' );
			$url = $this->fal_create( $prompt, $ratio );
			$id  = $this->sideload_library( $url, mb_substr( trim( $desc ), 0, 60 ) );
		} catch ( \Throwable $e ) {
			DZE_Ai_Usage::unit();
			wp_send_json_error( [ 'message' => $e->getMessage() ] );
		}
		DZE_Ai_Usage::unit();
		DZE_Ai_Usage::finished( 'background' );
		wp_send_json_success( [
			'id'    => $id,
			'name'  => mb_substr( trim( $desc ), 0, 40 ),
			'thumb' => (string) wp_get_attachment_image_url( $id, 'medium' ),
		] );
	}

	/**
	 * Empties a photograph: the product leaves, its set stays.
	 *
	 * The other way round from the block above — here the background already
	 * exists, in a photograph that happens to have a product standing in it.
	 * The model is asked to take the product out and rebuild the surface
	 * underneath, touching nothing else. It is a redraw, not a cutout: what
	 * comes back matches the set closely, it is not the same pixels. Which is
	 * enough for a backdrop, since every product will be re-lit onto it
	 * anyway.
	 *
	 * The source is one of: an image already in the library, a URL, or bytes
	 * pasted from the clipboard.
	 */
	public function ajax_bg_strip(): void {
		$this->guard();
		$att   = isset( $_POST['att'] ) ? absint( wp_unslash( $_POST['att'] ) ) : 0;
		$url   = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '';
		$paste = isset( $_POST['paste'] ) ? trim( (string) wp_unslash( $_POST['paste'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- read_data_uri() validates the bytes.
		$note  = isset( $_POST['note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['note'] ) ) : '';
		if ( '' === self::fal_key() ) {
			wp_send_json_error( [ 'message' => __( 'Add your fal.ai key under Settings → General first.', 'dazont-ecom' ) ] );
		}
		if ( class_exists( 'DZE_Ai_Usage' ) && DZE_Ai_Usage::over_budget() ) {
			wp_send_json_error( [ 'message' => DZE_Ai_Usage::budget_message() ] );
		}
		try {
			if ( $att > 0 ) {
				$source = $this->fal_source_data_uri( $att );
			} elseif ( '' !== $paste ) {
				$source = self::read_data_uri( $paste );
			} elseif ( '' !== $url ) {
				$source = $this->fetch_remote_image( $url );
			} else {
				throw new RuntimeException( __( 'Give me a photograph to empty.', 'dazont-ecom' ) );
			}
		} catch ( \Throwable $e ) {
			wp_send_json_error( [ 'message' => $e->getMessage() ] );
		}
		// Everything here pulls in the same direction: REMOVE, and CHANGE
		// NOTHING ELSE. An edit model asked in loose terms redraws the whole
		// frame — the very thing that makes a background unusable.
		$prompt = 'Remove the product from this photograph, and everything that came with it: the object itself, its shadow, its reflection, any hand, mannequin, hanger, stand, price tag, label or text. '
			. 'Rebuild the surface behind and beneath it so the set reads as an empty set that was photographed with nothing in it. '
			. 'KEEP EVERYTHING ELSE EXACTLY AS IT IS: the same materials, the same colours, the same grain, the same lighting, the same direction and softness of light, the same perspective, the same framing, the same white balance. '
			. 'Do not restyle, do not brighten, do not clean up, do not add anything. The result is a background plate: an empty set with clear space in the middle where another product will be placed later.';
		if ( '' !== $note ) {
			$prompt .= ' ' . $note;
		}
		try {
			DZE_Ai_Usage::unit( 'background' );
			$made = $this->fal_generate( $prompt, [ $source ] );
			$name = $att > 0 ? (string) get_the_title( $att ) : __( 'Background from a photo', 'dazont-ecom' );
			$id   = $this->sideload_library( $made, mb_substr( trim( $name ) ?: 'background', 0, 60 ) );
		} catch ( \Throwable $e ) {
			DZE_Ai_Usage::unit();
			wp_send_json_error( [ 'message' => $e->getMessage() ] );
		}
		DZE_Ai_Usage::unit();
		DZE_Ai_Usage::finished( 'background' );
		wp_send_json_success( [
			'id'    => $id,
			'name'  => mb_substr( __( 'Set from a photo', 'dazont-ecom' ), 0, 40 ),
			'thumb' => (string) wp_get_attachment_image_url( $id, 'medium' ),
		] );
	}

	public function fal_generate( string $prompt, array $image_urls ): string {
		$resp = wp_remote_post( self::FAL_ENDPOINT, [
			'timeout' => 120,
			'headers' => [ 'Authorization' => 'Key ' . self::fal_key(), 'content-type' => 'application/json' ],
			'body'    => wp_json_encode( [
				'prompt'        => $prompt,
				'image_urls'    => array_values( $image_urls ),
				'num_images'    => 1,
				'aspect_ratio'  => 'auto',
				// Photographs, on a shop: JPEG. A PNG product image is three to
				// five times the weight for no visible gain and slows the page
				// down for every visitor.
				'output_format' => 'jpeg',
			] ),
		] );
		if ( is_wp_error( $resp ) ) {
			throw new RuntimeException( $resp->get_error_message() );
		}
		$code = wp_remote_retrieve_response_code( $resp );
		$body = json_decode( wp_remote_retrieve_body( $resp ), true );
		if ( $code < 200 || $code >= 300 ) {
			$msg = 'HTTP ' . $code;
			if ( is_array( $body ) && isset( $body['detail'] ) ) {
				if ( is_string( $body['detail'] ) ) {
					$msg = $body['detail'];
				} elseif ( is_array( $body['detail'] ) ) {
					$parts = [];
					foreach ( $body['detail'] as $d ) {
						if ( is_array( $d ) && ! empty( $d['msg'] ) ) {
							$parts[] = (string) $d['msg'] . ( ! empty( $d['loc'] ) ? ' (' . implode( '.', array_map( 'strval', (array) $d['loc'] ) ) . ')' : '' );
						}
					}
					if ( $parts ) {
						$msg = 'HTTP ' . $code . ' — ' . implode( '; ', $parts );
					}
				}
			}
			throw new RuntimeException( sprintf( __( 'fal.ai error: %s', 'dazont-ecom' ), mb_substr( $msg, 0, 300 ) ) );
		}
		$url = $body['images'][0]['url'] ?? '';
		if ( $url && class_exists( 'DZE_Ai_Usage' ) ) {
			DZE_Ai_Usage::record( 'fal', 0, 0, 'nano-banana-2', self::fal_image_cost() );
		}
		if ( ! $url ) {
			throw new RuntimeException( __( 'fal.ai returned no image.', 'dazont-ecom' ) );
		}
		return (string) $url;
	}
}
