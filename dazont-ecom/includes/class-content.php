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

	// Every wp_ajax_ handler of this module, in includes/class-content-ajax.php.
	// A trait rather than a class: it is compiled into this one, so self::, the
	// private helpers and the constants keep working exactly as before.
	use DZE_Content_Ajax;

	public const OPT_SETTINGS = 'dze_content_settings';
	private const NONCE       = 'dze_content';

	private const FAL_ENDPOINT = 'https://fal.run/fal-ai/nano-banana-2/edit';

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
		add_action( 'admin_init',     [ $this, 'migrate_quick_recipe' ] );
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
		add_action( 'wp_ajax_dze_content_pending_clear', [ $this, 'ajax_pending_clear' ] );
		add_action( 'wp_ajax_dze_content_variations', [ $this, 'ajax_variations' ] );
		add_action( 'wp_ajax_dze_content_variation_assign', [ $this, 'ajax_variation_assign' ] );
		add_action( 'wp_ajax_dze_content_variation_note', [ $this, 'ajax_variation_note' ] );
		add_action( 'wp_ajax_dze_content_variation_paste', [ $this, 'ajax_variation_paste' ] );
		add_action( 'wp_ajax_dze_content_logged', [ $this, 'ajax_logged' ] );
		add_action( 'wp_ajax_dze_content_log_clear', [ $this, 'ajax_log_clear' ] );
		add_action( 'wp_ajax_dze_content_bulk_list', [ $this, 'ajax_bulk_list' ] );
		add_action( 'wp_ajax_dze_content_quick_main', [ $this, 'ajax_quick_main' ] );
		add_action( 'wp_ajax_dze_content_bg_add', [ $this, 'ajax_bg_add' ] );
		add_action( 'wp_ajax_dze_content_prompt_toggle', [ $this, 'ajax_prompt_toggle' ] );
		add_action( 'wp_ajax_dze_content_price_preview', [ $this, 'ajax_price_preview' ] );
		add_action( 'wp_ajax_dze_content_current', [ $this, 'ajax_current' ] );
		add_action( 'wp_ajax_dze_content_boxes', [ $this, 'ajax_boxes' ] );
		add_action( 'wp_ajax_dze_content_inputs', [ $this, 'ajax_inputs' ] );
		add_action( 'wp_ajax_dze_content_reframe_preview', [ $this, 'ajax_reframe_preview' ] );
		add_action( 'wp_ajax_dze_content_reframe_apply', [ $this, 'ajax_reframe_apply' ] );
		// The Variations panel is the box variation images are written into, so
		// the button that fills them is planted there — one button, opening one
		// popup, exactly like the title and the main image have.
		add_action( 'woocommerce_variable_product_before_variations', [ $this, 'variations_button' ] );
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
			// The main image is a prompt like the others: same list, same card,
			// renameable, movable, removable. It is only recognised by WHAT IT
			// WRITES — an image prompt whose destination is the main image — so
			// the Main image button offers it without anything being hard-wired
			// to a name or an id.
			[ 'name' => 'Main image', 'target' => 'main', 'prompt' => self::default_quick_prompt() ],
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
			// There is no shipped "remake the main image" template: the main
			// image has ONE recipe, the shop recipe, and it is far more precise
			// than a template could be. Two cards for the same job only made
			// the choice harder.
			[
				'name'   => 'Another angle of the same product',
				'target' => 'gallery',
				'prompt' => "Photograph the SAME product again from a different, useful angle — three-quarter view, back, or a close-up of the material and the stitching — as a clean e-commerce shot.\n"
					. "- It must read as another photograph from the same shoot: same product, same colours, same materials, same lighting, same background.\n"
					. "- Show something the source photographs do not already show. Never repeat an angle that already exists.\n"
					. "- No text, no props, no people unless the product is worn in the source.\n"
					. "- Invent nothing: if a side of the product is not visible in any source photograph, choose an angle that does not reveal it.",
			],
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
			// Not a line of text: the photographs themselves travel with the
			// request and the model LOOKS at them. A description written from
			// a supplier title alone invents the material, the cut and the
			// details; one written in front of the product does not.
			'photos'            => __( 'The product photographs (the model looks at them)', 'dazont-ecom' ),
		];
	}

	/** Inputs that are images rather than lines of text. */
	public static function is_image_input( string $key ): bool {
		return 'photos' === $key;
	}

	/** Output destinations per content type. */
	public static function output_options( string $type = 'text' ): array {
		if ( 'image' === $type ) {
			return [
				'gallery'   => __( 'Product gallery (image)', 'dazont-ecom' ),
				'main'      => __( 'Main image', 'dazont-ecom' ),
				// One image per group of variations — the three colours of a
				// product sold in three colours and five sizes, not fifteen
				// images.
				'variation' => __( 'Variation image (one per colour)', 'dazont-ecom' ),
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
				'img_rules'=> (string) ( $r['img_rules'] ?? '' ),
				'tokens'  => (int) ( $r['tokens'] ?: 400 ),
				'enabled' => ! empty( $r['enabled'] ),
				'prompt'  => (string) ( $r['prompt'] ?? '' ),
			];
		}
		return $out;
	}

	/** Assembles the product data block from a row's selected inputs. */
	public static function payload_lines( int $pid, array $inputs, string $inputs_meta = '', string $variation = '' ): string {
		$product = $pid ? wc_get_product( $pid ) : null;
		if ( ! $product instanceof WC_Product ) {
			return '';
		}
		$L = [];
		foreach ( $inputs as $k ) {
			switch ( $k ) {
				case 'photos':
					// Sent as images, not as a line: see look_images().
					break;
				case 'title':
					// Working on one variation: the title is that variation's,
					// not the parent's. "Combat shirt" and "Combat shirt —
					// Color: Multicam Tropic, Fabric: Ripstop" are not the same
					// brief. The caller passes the whole name when it has one.
					$L[] = 'Title: ' . ( '' !== $variation ? $variation : $product->get_name() );
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
			. 'lit by an even, diffuse light with no visible light source. '
			// A garment photographed without a mannequin touches nothing, so
			// "a shadow where it meets the surface" has no answer and the model
			// invents a dark smudge behind it. The shadow has to be described
			// for a product that hangs: one small ellipse, under it, and that
			// is all.
			. 'SHADOW: exactly ONE shadow, directly UNDER the product and nowhere else — '
			. 'a small soft ellipse, no wider than the product, darkest immediately beneath it and gone within a short distance. '
			. 'No shadow cast behind or to the side, no dark halo or glow around the product, no vignette, no grey smudge on the background, no second shadow. '
			. 'If the product hangs or floats (a garment photographed without a mannequin), it still gets that one discreet ellipse under its lowest point. '
			. 'No props, no text, no logo, no hands, no people, no background objects. '
			. 'Keep the product EXACTLY as it is: same shape, same materials, same colours, same stitching, same hardware, same proportions. '
			. 'Invent nothing that is not visible in the source photograph.';
	}

	/**
	 * The prompt behind the Main image lane: the first enabled image prompt
	 * that writes the main image. Nothing is reserved and nothing is fixed —
	 * rename it, rewrite it, put another one above it, and the lane follows.
	 *
	 * @return array<string,mixed>|null
	 */
	public static function main_recipe(): ?array {
		foreach ( self::registry() as $r ) {
			if ( ( $r['type'] ?? '' ) === 'image' && ( $r['output'] ?? '' ) === 'main' && ! empty( $r['enabled'] ) ) {
				return $r;
			}
		}
		return null;
	}

	/** The recipe actually sent: the row's text, or the shipped one. */
	public static function quick_prompt(): string {
		$row = self::main_recipe();
		$p   = $row ? trim( (string) ( $row['prompt'] ?? '' ) ) : '';
		if ( '' === $p ) {
			$p = trim( (string) ( self::get_settings()['quick_prompt'] ?? '' ) ); // pre-4.2 installs.
		}
		return '' !== $p ? $p : self::default_quick_prompt();
	}

	/**
	 * Pre-4.2 installs kept this prompt in a setting of its own, edited in a
	 * corner of the Backgrounds section and absent from the list of prompts.
	 * It becomes an ordinary row, once, carrying whatever text was in it.
	 */
	public function migrate_quick_recipe(): void {
		$s = self::get_settings();
		// ONCE, and remembered. Keyed on the row's presence, this put the
		// prompt back every time the admin loaded — so a prompt deleted on
		// purpose came straight back, which is not a migration, it is a
		// plugin arguing with its owner.
		if ( ! empty( $s['quick_migrated'] ) ) {
			return;
		}
		foreach ( self::registry() as $r ) {
			if ( 'img_main_image' === ( $r['id'] ?? '' ) ) {
				$s['quick_migrated'] = 1;
				$this->write_settings_direct( $s );
				return;
			}
		}
		$rows = self::registry();
		$own  = trim( (string) ( $s['quick_prompt'] ?? '' ) );
		$new  = [
			'id'          => 'img_main_image',
			'name'        => __( 'Main image', 'dazont-ecom' ),
			'type'        => 'image',
			'prompt'      => '' !== $own ? $own : self::default_quick_prompt(),
			'inputs'      => [ 'title', 'description' ],
			'inputs_meta' => '',
			'output'      => 'main',
			'meta_key'    => '',
			'enabled'     => 1,
			'valid'       => 1,
			'tokens'      => 0,
		];
		// FIRST among the image prompts: the lane runs the first one that
		// writes the main image, and this is the one it must run.
		$at = count( $rows );
		foreach ( $rows as $i => $r ) {
			if ( ( $r['type'] ?? '' ) === 'image' ) {
				$at = $i;
				break;
			}
		}
		array_splice( $rows, $at, 0, [ $new ] );
		$s['registry'] = $rows;
		$s['quick_migrated'] = 1;
		unset( $s['quick_prompt'] );
		$this->write_settings_direct( $s );
		self::$registry_cache = null;
	}

	/** The rules actually sent: the owner's, or the shipped ones. */
	/**
	 * The rules the photographs are picked by.
	 *
	 * ONE call picks the photographs for every paired block of a product, so
	 * the rules of those blocks travel together — each block states how ITS
	 * photograph should be chosen, in its own card, and a block that says
	 * nothing falls back to the shipped rules.
	 */
	public static function feature_prompt(): string {
		$rules = [];
		foreach ( self::registry() as $r ) {
			if ( ( $r['type'] ?? 'text' ) === 'image' || empty( $r['enabled'] ) ) {
				continue;
			}
			if ( '' === trim( (string) ( $r['img_meta'] ?? '' ) ) ) {
				continue;
			}
			$own = trim( (string) ( $r['img_rules'] ?? '' ) );
			$rules[] = '' !== $own ? $own : self::default_feature_prompt();
		}
		$rules = array_values( array_unique( array_filter( $rules ) ) );
		if ( ! $rules ) {
			// Pre-4.9 installs kept one rule for all of them.
			$legacy = trim( (string) ( self::get_settings()['feature_prompt'] ?? '' ) );
			return '' !== $legacy ? $legacy : self::default_feature_prompt();
		}
		return implode( ' ', $rules );
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
	/**
	 * There is ONE switch per prompt now.
	 *
	 * "Enabled" and "Validated" were two flags for one decision: the first said
	 * the prompt exists, the second said bulk may run it — and nothing on
	 * screen explained the difference, so a prompt could be on and refuse to
	 * work with no visible reason. A prompt that is switched on is usable
	 * everywhere, and switching it off is how you stop it.
	 */
	public static function field_validated( string $field ): bool {
		return self::field_enabled( $field );
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
		// Same single switch as the text prompts: image_templates() already
		// lists only the enabled ones.
		return isset( $tpls[ $idx ] );
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
	 * @param int        $avoid Photographs this prompt already made, sent after
	 *                          the product ones and before the scene, so the
	 *                          model can see what it must not do again.
	 */
	public static function sources_instruction( int $count, ?array $scene, int $avoid = 0 ): string {
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
		// The model has no memory of what it handed back a minute ago, so
		// asking a second time for "a photograph of the product in use" simply
		// returned the first one again. It is shown them instead of being told
		// about them: a sentence saying "make it different" loses to a set of
		// source images saying "make it the same".
		if ( $avoid > 0 ) {
			$out .= ' ' . (
				1 === $avoid
					? sprintf( 'IMAGE %d IS A PHOTOGRAPH ALREADY MADE', $count + 1 )
					: sprintf( 'IMAGES %1$d TO %2$d ARE PHOTOGRAPHS ALREADY MADE', $count + 1, $count + $avoid )
			);
			$out .= ' for this product with these very instructions. They are here for one reason: the photograph you are making now must be clearly different from '
				. ( 1 === $avoid ? 'it' : 'each of them' )
				. ' — another angle, another distance, another part of the product, another arrangement. Never hand back one of them again, and never read '
				. ( 1 === $avoid ? 'it' : 'them' )
				. ' as the reference for what the product looks like: that is what the product photographs above are for.';
		}
		if ( $scene ) {
			// Deliberately says WHAT it is and not what it may not be: a shelf
			// image can be a blank product to print on, and a sentence
			// forbidding a scene from looking like a product fought the prompt
			// that asked for exactly that.
			$out .= sprintf( ' THE LAST IMAGE (image %d) IS THE SCENE: the surface, the background and the lighting of the final image. Only one product in the frame.', $count + $avoid + 1 );
			if ( '' !== trim( (string) $scene['prompt'] ) ) {
				$out .= "\n" . trim( (string) $scene['prompt'] );
			}
			// The scene IS the background. Said any less firmly, the model
			// paints its own over it — and, asked for "contact shadows" on a
			// product that touches nothing, drops a large dark smear behind it.
			$out .= "\nThe result must look like ONE photograph: the same perspective and the same light in the product as in the scene.";
			$out .= "\nThe background is the scene image and only it: its colour, its gradient and its own shadow are kept as they are. Do not paint another background over it, do not darken it, do not add a vignette, and ignore any background described in words above.";
			$out .= "\nSHADOW: if the scene already shows a shadow on its surface, use that one and add no other. Otherwise, one soft ellipse directly under the product, no wider than the product, gone within a short distance. Never a large diffuse dark area behind, beside or around the product, and never two shadows.";
		}
		return $out;
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
				'target'      => in_array( (string) ( $r['output'] ?? 'gallery' ), [ 'main', 'variation' ], true )
					? (string) $r['output']
					: 'gallery',
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
			// What it was MADE for travels with it. An image generated as the
			// main image and accepted later from the bulk screen used to land
			// at the end of the gallery, because the screen that accepted it
			// had no idea what it had been made for and fell back to "gallery".
			if ( ! empty( $add['target'] ) ) {
				$where = (array) ( $cur['targets'] ?? [] );
				$where[ (string) $add['shot'] ] = (string) $add['target'];
				$cur['targets'] = $where;
			}
			// The prompt that made it travels too: it names the file when the
			// image is finally filed, wherever that happens.
			if ( ! empty( $add['recipe'] ) ) {
				$who = (array) ( $cur['recipes'] ?? [] );
				$who[ (string) $add['shot'] ] = (string) $add['recipe'];
				$cur['recipes'] = $who;
			}
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

	/**
	 * The three numbers written on the bulk screen's tabs.
	 *
	 * Read from the same place by the page that prints them and by every
	 * request that changes them, so a screen that has just deleted six products
	 * says so on its tabs instead of waiting for a reload to admit it. The
	 * first tab holds the whole list, the second the part of it still waiting.
	 *
	 * @return array{all:int,log:int}
	 */
	public static function screen_counts(): array {
		return [
			'all' => count( array_unique( array_merge( self::bulk_list(), self::pending_ids() ) ) ),
			'log' => count( self::log_entries() ),
		];
	}

	/** Attachment meta: the prompt a photograph came out of. */
	public const META_RECIPE = '_dze_prompt';

	/**
	 * What this prompt has ALREADY made for this product.
	 *
	 * Two places hold it and neither one alone is the answer: the images still
	 * waiting for a decision, and the images that were accepted and are now on
	 * the product. Counting only the waiting list is why a second click on
	 * "+ Product in use", the day after the first batch was accepted, came back
	 * with a photograph the product already had.
	 *
	 * Cheap on purpose: one indexed meta read for the waiting list, one query
	 * for the accepted ones, and only ever on the way to a generation that is
	 * about to cost a fal call anyway.
	 *
	 * @return array{urls:string[],ids:int[]} Waiting images (fal URLs, newest
	 *                                        last) and accepted ones (newest first).
	 */
	public static function made_already( int $pid, string $recipe_id ): array {
		$out = [ 'urls' => [], 'ids' => [] ];
		if ( ! $pid || '' === $recipe_id ) {
			return $out;
		}
		$p     = self::pending( $pid );
		$shots = array_map( 'strval', (array) ( $p['shots'] ?? [] ) );
		foreach ( (array) ( $p['recipes'] ?? [] ) as $url => $rid ) {
			// A refused attempt leaves the waiting list: it is not something
			// this product has, so it is not something to avoid repeating.
			if ( (string) $rid === $recipe_id && in_array( (string) $url, $shots, true ) ) {
				$out['urls'][] = (string) $url;
			}
		}
		global $wpdb;
		$ids = $wpdb->get_col( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- admin, one product, on the way to a paid generation.
			"SELECT p.ID FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s
			 WHERE p.post_type = 'attachment' AND p.post_parent = %d AND m.meta_value = %s
			 ORDER BY p.ID DESC LIMIT 20",
			self::META_RECIPE,
			$pid,
			$recipe_id
		) );
		$out['ids'] = array_map( 'intval', (array) $ids );
		return $out;
	}

	/**
	 * The few already-made photographs worth showing the model, newest first.
	 *
	 * Two of them: enough to say "not these again", little enough that the
	 * request stays light and the product photographs keep the upper hand on
	 * what the product looks like. A waiting image is a fal URL — it travels as
	 * a link and costs nothing to send; an accepted one is a file of ours and
	 * is returned as an attachment id for the caller to read.
	 *
	 * @return array<int, int|string> Attachment ids and fal URLs, mixed.
	 */
	public static function avoid_sources( int $pid, string $recipe_id, int $max = 2 ): array {
		$made = self::made_already( $pid, $recipe_id );
		$out  = [];
		foreach ( array_reverse( $made['urls'] ) as $url ) {
			if ( count( $out ) >= $max ) {
				return $out;
			}
			if ( self::is_fal_url( (string) $url ) ) {
				$out[] = (string) $url;
			}
		}
		foreach ( $made['ids'] as $id ) {
			if ( count( $out ) >= $max ) {
				return $out;
			}
			$out[] = (int) $id;
		}
		return $out;
	}

	/**
	 * "Not the same photograph again."
	 *
	 * Asking a prompt for a second gallery shot ran exactly the same
	 * instruction on exactly the same photographs, so it came back with
	 * exactly the same image — three attempts, three near-identical results,
	 * three times the bill. The model cannot see what it produced a minute
	 * ago, so the difference has to be asked for: how many shots this prompt
	 * has already made on this product is known here, and each one gets its
	 * own framing.
	 *
	 * Never on the main image: there is one right main image, not four
	 * different ones.
	 */
	public static function variation_line( int $pid, string $recipe_id, string $target, int $attempt = 0 ): string {
		if ( 'main' === $target ) {
			return '';
		}
		$already = self::made_already( $pid, $recipe_id );
		$made    = count( $already['urls'] ) + count( $already['ids'] );
		// A run that writes straight to the product stashes nothing, so the
		// count above stays at zero: the screen says which attempt this is.
		$made = max( $made, $attempt > 0 ? $attempt - 1 : 0 );
		if ( $made < 1 ) {
			return '';
		}
		$hints = [
			'Take it from a clearly different angle than a straight-on view: a three-quarter view, the product turned.',
			'Come closer: a detail of the material, the stitching or the fastening, filling most of the frame.',
			'Step back: the whole product in its setting, seen from further away and slightly above.',
			'Change the side: show the part the other photographs do not show — the back, the inside, the reverse.',
		];
		$hint = $hints[ ( $made - 1 ) % count( $hints ) ];
		return "\n\nThis is photograph " . ( $made + 1 ) . ' of a set made for the same product: it must not repeat the ones already made. '
			. $hint . ' Everything else — the product, the light and the setting — stays as described above.';
	}

	/**
	 * "This one is the olive one."
	 *
	 * The photographs sent with the request show the product in whatever
	 * colours the shop happens to have shot; the image being asked for is one
	 * precise variation. Without this the model returns the colour it saw, and
	 * the group that had no photograph still has none.
	 */
	public static function variation_instruction( string $attr, string $value, bool $has_own_shot ): string {
		$label = self::attribute_value_label( $attr, $value );
		$what  = function_exists( 'wc_attribute_label' ) ? (string) wc_attribute_label( $attr ) : $attr;
		$out   = "\n\nTHIS IMAGE IS FOR ONE VARIATION OF THE PRODUCT: " . $what . ' = ' . $label . '.';
		if ( $has_own_shot ) {
			$out .= ' Image 1 already shows that variation: keep its colours and its materials exactly as they are.';
		} else {
			$out .= ' The photographs show the product in another colourway: the product itself — its shape, its cut, its materials, its stitching, its hardware and every marking — stays exactly the same, and only the colour becomes ' . $label . '.'
				. ' Change nothing else, invent nothing, and do not alter the pattern beyond its colours.';
		}
		return $out;
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
		$ids = array_map( 'intval', (array) get_posts( [
			'post_type'      => 'product',
			'post_status'    => 'any',
			'posts_per_page' => $limit,
			'fields'         => 'ids',
			'meta_key'       => self::META_PENDING, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- indexed key, capped, admin only.
			'orderby'        => 'modified',
			'order'          => 'DESC',
			'no_found_rows'  => true,
		] ) );
		// A row holding neither a text nor an image is not a product waiting
		// for a decision — there is nothing to decide. Those rows are the trace
		// of a decision that was taken and half-written; they are swept as they
		// are met, so the list says the truth and stops saying it again
		// tomorrow.
		$out = [];
		foreach ( $ids as $pid ) {
			$p = self::pending( $pid );
			if ( empty( $p['shots'] ) && empty( $p['texts'] ) ) {
				delete_post_meta( $pid, self::META_PENDING );
				delete_transient( 'dze_pending_count' );
				continue;
			}
			$out[] = $pid;
		}
		return $out;
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

	/**
	 * Writes an attachment id into a meta key that may belong to ACF.
	 *
	 * An ACF image field is not a plain meta row: beside the value it keeps a
	 * reference row (_key) holding the field key, and ACF needs it to know the
	 * value is one of its own. Writing the id alone leaves the field empty in
	 * the editor even though the number is in the database. So when ACF knows
	 * this key on this product, ACF writes it; otherwise it is a plain meta,
	 * exactly as before.
	 */
	public static function write_image_meta( int $pid, string $key, int $att_id ): void {
		if ( function_exists( 'update_field' ) && function_exists( 'acf_get_field' ) ) {
			$field = acf_get_field( $key );
			if ( $field ) {
				update_field( $key, $att_id, $pid );
				return;
			}
		}
		update_post_meta( $pid, $key, $att_id );
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
			// What the shop holds right now, to tell "left alone" from "changed".
			$stored = [];
			foreach ( self::registry() as $r ) {
				$stored[ (string) ( $r['id'] ?? '' ) ] = (string) ( $r['prompt'] ?? '' );
			}
			foreach ( $in['pr_name'] as $i => $name ) {
				$name   = sanitize_text_field( (string) $name );
				$prompt = sanitize_textarea_field( (string) ( $in['pr_prompt'][ $i ] ?? '' ) );
				// Untouched in THIS form, but changed elsewhere since it was
				// drawn: the newer text wins. Editing a prompt from the toolbox
				// and saving this page an hour later must not undo it.
				$was    = (string) ( $in['pr_was'][ $i ] ?? '' );
				$rid    = sanitize_key( (string) ( $in['pr_id'][ $i ] ?? '' ) );
				if ( '' !== $was && isset( $stored[ $rid ] ) && md5( $prompt ) === $was && $stored[ $rid ] !== $prompt ) {
					$prompt = $stored[ $rid ];
				}
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
					// How THIS block's photograph is chosen. It used to be one
					// setting for every paired block, in a section of its own —
					// an exception, and unreadable next to the prompt it serves.
					'img_rules'   => sanitize_textarea_field( (string) ( $in['pr_imgrules'][ $i ] ?? '' ) ),
					'enabled'     => ! empty( $in['pr_on'][ $i ] ) ? 1 : 0,
					// Kept in step with the switch: one decision, one value.
					'valid'       => ! empty( $in['pr_on'][ $i ] ) ? 1 : 0,
					// How the images this prompt makes are named on disk and in
					// the library — the URL of a shop is content too.
					'file_name'   => sanitize_text_field( (string) ( $in['pr_file'][ $i ] ?? '' ) ),
					'img_title'   => sanitize_text_field( (string) ( $in['pr_imgtitle'][ $i ] ?? '' ) ),
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
			<p class="description"><?php esc_html_e( 'Prepended to every generation, e.g. "Kula Tactical > Military / tactical clothing and gear > Tone: sharp, authoritative, informational".', 'dazont-ecom' ); ?></p>

			</details>

			<details class="dze-set">
			<summary><?php esc_html_e( 'Backgrounds — the surfaces your products are shot on', 'dazont-ecom' ); ?></summary>
			<p class="description">
				<?php esc_html_e( 'The images you keep and send with a generation: a studio backdrop, a floor for rugs, a table top, a blank t-shirt to print on. Pick one when you generate an image and it travels as the last image of the request, so a catalogue shot by a dozen suppliers comes back looking like one shop. The note under each image is optional — it says what to do with that particular one ("lay the rug flat on this floor", "print the design on this shirt"), and it is only worth writing when the image alone does not say it.', 'dazont-ecom' ); ?>
			</p>
			<?php $dze_scenes = self::scenes(); ?>
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
			<script type="text/template" id="dze-sc-tpl">
				<div class="dze-bgcard">
					<div class="dze-sc-thumb"></div>
					<input type="hidden" class="dze-sc-img" name="<?php echo esc_attr( $opt ); ?>[sc_image][]" value="0" />
					<input type="text" name="<?php echo esc_attr( $opt ); ?>[sc_name][]" value="" placeholder="<?php esc_attr_e( 'Name it', 'dazont-ecom' ); ?>" />
					<input type="text" name="<?php echo esc_attr( $opt ); ?>[sc_prompt][]" value="" placeholder="<?php esc_attr_e( 'note for the model (optional)', 'dazont-ecom' ); ?>" class="dze-bgnote" />
					<p class="dze-bgfoot">
						<label><input type="radio" name="<?php echo esc_attr( $opt ); ?>[sc_default]" value="__I__" /> <?php esc_html_e( 'Default', 'dazont-ecom' ); ?></label>
						<button type="button" class="button button-small dze-sc-pick"><?php esc_html_e( 'Replace', 'dazont-ecom' ); ?></button>
						<button type="button" class="button-link dze-sc-clear" style="color:#b32d2e;"><?php esc_html_e( 'remove', 'dazont-ecom' ); ?></button>
					</p>
				</div>
			</script>
			<p>
				<button type="button" class="button" id="dze-sc-add">&#43; <?php esc_html_e( 'Add a background', 'dazont-ecom' ); ?></button>
			</p>


			<script>
			jQuery( function ( $ ) {
				var dzeScRepl = <?php echo wp_json_encode( __( 'Replace', 'dazont-ecom' ) ); ?>,
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
						// The image already has a name in the library; retyping it
						// is busywork. A name written by hand is left alone.
						var $nm = $cell.find( 'input[name$="[sc_name][]"]' );
						if ( ! $.trim( $nm.val() ) ) { $nm.val( a.title || a.filename || '' ); }
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
				// "remove" takes the background off the shelf. Emptying the card
				// and leaving it there was a row that meant nothing.
				$( document ).on( 'click', '.dze-sc-clear', function () {
					$( this ).closest( '.dze-bgcard' ).remove();
					// The Default radios are read by position: renumber them.
					$( '#dze-sc .dze-bgcard' ).each( function ( i ) {
						$( this ).find( 'input[type=radio]' ).val( String( i ) );
					} );
				} );
				// Adding a background is choosing an image: the card is built
				// once one has been chosen, so cancelling the picker leaves the
				// shelf exactly as it was. It used to add the card first, and a
				// cancelled pick left an empty row that meant nothing and was
				// silently dropped on save.
				$( '#dze-sc-add' ).on( 'click', function () {
					if ( ! window.wp || ! wp.media ) { return; }
					var frame = wp.media( {
						title: dzeScTtl,
						library: { type: 'image' },
						button: { text: dzeScUse },
						multiple: true
					} );
					frame.on( 'select', function () {
						frame.state().get( 'selection' ).each( function ( att ) {
							var a = att.toJSON();
							var url = ( a.sizes && a.sizes.thumbnail ) ? a.sizes.thumbnail.url : a.url;
							var i = $( '#dze-sc .dze-bgcard' ).length;
							var $card = $( $( '#dze-sc-tpl' ).html().replace( /__I__/g, String( i ) ) );
							$card.find( '.dze-sc-img' ).val( a.id );
							$card.find( 'input[name$="[sc_name][]"]' ).val( a.title || a.filename || '' );
							$card.find( '.dze-sc-thumb' ).html(
								$( '<img />' ).attr( 'src', url ).attr( 'alt', '' ).css( { maxWidth: '90px', height: 'auto', borderRadius: '4px' } )
							);
							$( '#dze-sc' ).append( $card );
						} );
					} );
					frame.open();
				} );
			} );
			</script>

			</details>

			<details class="dze-set" open>
			<summary><?php esc_html_e( 'Prompts — what the plugin writes, and how', 'dazont-ecom' ); ?></summary>
			<p class="description">
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
			// By name, inside each group. Insertion order means something to the
			// database and nothing to a reader: a list of twenty prompts you
			// have to scan line by line to find one is a list nobody reads. The
			// index of each row is kept as its key, so what is posted still
			// carries the row it belongs to.
			foreach ( $dze_map as $dze_g2 => $dze_rows2 ) {
				uasort(
					$dze_rows2,
					static fn( $a, $b ) => strcasecmp( (string) ( $a['name'] ?? '' ), (string) ( $b['name'] ?? '' ) )
				);
				$dze_map[ $dze_g2 ] = $dze_rows2;
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
							<label class="dze-switch dze-prb-on" title="<?php esc_attr_e( 'Use this prompt — saved the moment you switch it', 'dazont-ecom' ); ?>">
								<input type="checkbox" class="dze-prb-live" data-id="<?php echo esc_attr( (string) $r['id'] ); ?>" name="<?php echo esc_attr( $opt ); ?>[pr_on][<?php echo (int) $dze_ri; ?>]" value="1" <?php checked( ! empty( $r['enabled'] ) ); ?> />
								<span class="dze-switch-slider"></span>
							</label>
							<input type="text" class="dze-prb-name" name="<?php echo esc_attr( $opt ); ?>[pr_name][<?php echo (int) $dze_ri; ?>]" value="<?php echo esc_attr( $r['name'] ); ?>" />
							<input type="hidden" name="<?php echo esc_attr( $opt ); ?>[pr_id][<?php echo (int) $dze_ri; ?>]" value="<?php echo esc_attr( $r['id'] ); ?>" />
							<span class="dze-prb-dest"><?php echo esc_html( self::output_options( ( $r['type'] ?? 'text' ) === 'image' ? 'image' : 'text' )[ $r['output'] ?? '' ] ?? ( $r['output'] ?? '' ) ); ?>
								<?php if ( '' !== trim( (string) ( $r['img_meta'] ?? '' ) ) ) : ?>
									<span class="dze-prb-pairchip" title="<?php esc_attr_e( 'This block is illustrated with one of the product photographs, stored in this field', 'dazont-ecom' ); ?>">&#128247; <?php echo esc_html( (string) $r['img_meta'] ); ?></span>
								<?php endif; ?>
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
								<label class="dze-prb-tk dze-pr-imgonly" style="<?php echo ( 'image' === ( $r['type'] ?? 'text' ) ) ? '' : 'display:none;'; ?>"><span><?php esc_html_e( 'File name', 'dazont-ecom' ); ?></span>
									<input type="text" name="<?php echo esc_attr( $opt ); ?>[pr_file][<?php echo (int) $dze_ri; ?>]" value="<?php echo esc_attr( (string) ( $r['file_name'] ?? '' ) ); ?>" placeholder="{product}" class="dze-pr-file" title="<?php esc_attr_e( 'The file name, and so the image URL. Empty = the product name, which is what every image uses today. Tokens: {product}, {prompt}, {variation} — e.g. {product}-ugc.', 'dazont-ecom' ); ?>" />
								</label>
								<label class="dze-prb-tk dze-pr-imgonly" style="<?php echo ( 'image' === ( $r['type'] ?? 'text' ) ) ? '' : 'display:none;'; ?>"><span><?php esc_html_e( 'Image title', 'dazont-ecom' ); ?></span>
									<input type="text" name="<?php echo esc_attr( $opt ); ?>[pr_imgtitle][<?php echo (int) $dze_ri; ?>]" value="<?php echo esc_attr( (string) ( $r['img_title'] ?? '' ) ); ?>" placeholder="{product}" class="dze-pr-imgtitle" title="<?php esc_attr_e( 'The attachment title and its alt text. Same tokens. Empty = the product name.', 'dazont-ecom' ); ?>" />
								</label>
								<label class="dze-prb-tk"><span><?php esc_html_e( 'Max length', 'dazont-ecom' ); ?></span>
									<input type="number" name="<?php echo esc_attr( $opt ); ?>[pr_tokens][<?php echo (int) $dze_ri; ?>]" value="<?php echo esc_attr( (int) ( $r['tokens'] ?: 400 ) ); ?>" min="50" class="dze-pr-tokens" />
								</label>
							</p>
							<textarea name="<?php echo esc_attr( $opt ); ?>[pr_prompt][<?php echo (int) $dze_ri; ?>]" rows="8" class="large-text code dze-pr-prompt"><?php echo esc_textarea( $r['prompt'] ); ?></textarea>
							<!-- What this prompt said when the page was drawn. A form
							     carries every row, so saving one row saved all of them
							     as they stood when the tab was opened — and wrote old
							     text back over a prompt edited since, from another tab
							     or from the toolbox. A row nobody touched here is now
							     left exactly as the shop has it. -->
							<input type="hidden" name="<?php echo esc_attr( $opt ); ?>[pr_was][<?php echo (int) $dze_ri; ?>]" value="<?php echo esc_attr( md5( (string) $r['prompt'] ) ); ?>" />
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
							<?php $dze_pair_on = '' !== trim( (string) ( $r['img_meta'] ?? '' ) ); ?>
							<details class="dze-pr-inputs dze-pr-pair"<?php echo $dze_pair_on ? ' open' : ''; ?>>
								<summary>
									<?php esc_html_e( 'Illustrate this block with one of the product photographs', 'dazont-ecom' ); ?>
									<span class="dze-pr-pairstate<?php echo $dze_pair_on ? ' is-on' : ''; ?>">
										<?php
										echo $dze_pair_on
											? esc_html( sprintf( /* translators: %s: meta key */ __( 'on → %s', 'dazont-ecom' ), (string) $r['img_meta'] ) )
											: esc_html__( 'off', 'dazont-ecom' );
										?>
									</span>
								</summary>
								<p class="description">
									<?php esc_html_e( 'What it does, in order: the model LOOKS at the photographs this product already has, picks the one this block should sit next to, writes the block about what is visible in it, and stores that photograph\'s id in the key below — so a theme field like _bloc_image_1 shows the right picture beside the right text. Empty key = this block is written from the product data alone, with no photograph.', 'dazont-ecom' ); ?>
								</p>
								<p class="dze-prb-line">
									<label><span><?php esc_html_e( 'Photograph goes to', 'dazont-ecom' ); ?></span>
										<input type="text" name="<?php echo esc_attr( $opt ); ?>[pr_imgmeta][<?php echo (int) $dze_ri; ?>]" value="<?php echo esc_attr( $r['img_meta'] ?? '' ); ?>" placeholder="_bloc_image_1" list="dze-metakeys" class="dze-pr-imgmeta" />
									</label>
									<span class="description"><?php esc_html_e( 'the field that holds the image — an ACF image field is written as ACF expects', 'dazont-ecom' ); ?></span>
								</p>
								<p class="description" style="max-width:820px;margin-top:10px;">
									<?php esc_html_e( 'How the photograph for THIS block is chosen. Empty = the shipped rules, shown greyed.', 'dazont-ecom' ); ?>
								</p>
								<textarea name="<?php echo esc_attr( $opt ); ?>[pr_imgrules][<?php echo (int) $dze_ri; ?>]" rows="3" class="large-text code dze-pr-imgrules" placeholder="<?php echo esc_attr( self::default_feature_prompt() ); ?>"><?php echo esc_textarea( (string) ( $r['img_rules'] ?? '' ) ); ?></textarea>
							</details>
						</div>
					</div>
				<?php endforeach; ?>
				</div>
			<?php endforeach; ?>
			</div>
			<?php $dze_ri = count( $dze_rows ); ?>
			<!-- A prompt being written belongs to no group yet: it is filed by
			     what it writes, and it has not said that yet. It waits here,
			     apart, until the page is saved. -->
			<div class="dze-prlist dze-prlist-new" id="dze-pr-new"></div>
			<p>
				<button type="button" class="button dze-pt-add" id="dze-pr-add" data-next="<?php echo (int) $dze_ri; ?>">&#43; <?php esc_html_e( 'Add prompt', 'dazont-ecom' ); ?></button>
				<button type="button" class="button" id="dze-pr-reset" style="margin-left:8px;">&#8634; <?php esc_html_e( 'Restore default prompts', 'dazont-ecom' ); ?></button>
			</p>

			<script type="text/template" id="dze-pr-rowtpl">
				<div class="dze-prb dze-pr-row is-open">
					<div class="dze-prb-head">
						<label class="dze-switch dze-prb-on"><input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[pr_on][__I__]" value="1" checked /><span class="dze-switch-slider"></span></label>
						<input type="text" class="dze-prb-name" name="<?php echo esc_attr( $opt ); ?>[pr_name][__I__]" value="" placeholder="<?php esc_attr_e( 'New prompt…', 'dazont-ecom' ); ?>" />
						<input type="hidden" name="<?php echo esc_attr( $opt ); ?>[pr_id][__I__]" value="" />
						<span class="dze-prb-dest"></span>
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
							<label class="dze-prb-tk dze-pr-imgonly" style="display:none;"><span><?php esc_html_e( 'File name', 'dazont-ecom' ); ?></span>
								<input type="text" name="<?php echo esc_attr( $opt ); ?>[pr_file][__I__]" value="" placeholder="{product}" class="dze-pr-file" />
							</label>
							<label class="dze-prb-tk dze-pr-imgonly" style="display:none;"><span><?php esc_html_e( 'Image title', 'dazont-ecom' ); ?></span>
								<input type="text" name="<?php echo esc_attr( $opt ); ?>[pr_imgtitle][__I__]" value="" placeholder="{product}" class="dze-pr-imgtitle" />
							</label>
							<label class="dze-prb-tk"><span><?php esc_html_e( 'Max length', 'dazont-ecom' ); ?></span>
								<input type="number" name="<?php echo esc_attr( $opt ); ?>[pr_tokens][__I__]" value="400" min="50" class="dze-pr-tokens" />
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
							<textarea name="<?php echo esc_attr( $opt ); ?>[pr_imgrules][__I__]" rows="3" class="large-text code dze-pr-imgrules" placeholder="<?php echo esc_attr( self::default_feature_prompt() ); ?>"></textarea>
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
					// Naming a file only means something for a prompt that makes
					// one: it appears with the image type and leaves with it.
					$row.find( '.dze-pr-imgonly' ).toggle( type === 'image' );
					$row.find( '.dze-prb-tk' ).filter( function () {
						return $( this ).find( '.dze-pr-tokens' ).length > 0;
					} ).toggle( type !== 'image' );
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
					// Not filed under a type it has not chosen: it stands on its
					// own until the page is saved, and joins its group then.
					$( '#dze-pr-new' ).append( $row );
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
					// The registry stopped being a table when it became cards: a
					// closest('td') found nothing, so the button did nothing.
					$( this ).closest( '.dze-prb' ).find( '.dze-pr-prompt' ).val( d );
				} );
				// On or off is one flag: it is written when it is clicked, not
				// when the page happens to be saved.
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

	/**
	 * What was actually written to the shop, and when.
	 *
	 * The bulk screen used to answer "did this product get done?" with the
	 * product still sitting in the list, exactly as before the run — accepting
	 * changed nothing you could see. A run that writes to a shop has to leave
	 * a trace: accepted products leave the working list and land here, with
	 * what they received.
	 *
	 * A capped list on ONE option, never autoloaded: it is read by one screen
	 * and by nothing else, least of all the shop.
	 */
	public const OPT_LOG = 'dze_content_log';
	private const LOG_MAX = 120;

	public static function log_add( int $pid, int $texts, int $images, string $status = 'applied' ): void {
		if ( ! $pid ) {
			return;
		}
		$log = get_option( self::OPT_LOG, [] );
		$log = is_array( $log ) ? $log : [];
		// One product appears once: a product decided on twice is the same
		// product, at the date of the last decision.
		foreach ( $log as $i => $row ) {
			if ( (int) ( $row['id'] ?? 0 ) === $pid ) {
				unset( $log[ $i ] );
			}
		}
		$log = array_values( $log );
		array_unshift( $log, [
			'id'     => $pid,
			'time'   => time(),
			'texts'  => $texts,
			'images' => $images,
			// Accepted or refused, the product is done with: what Done records
			// is that the decision was taken, and which one.
			'status' => 'dropped' === $status ? 'dropped' : 'applied',
			// The name as it was at that moment: the log says what happened,
			// not what the product is called today.
			'title'  => html_entity_decode( wp_strip_all_tags( (string) get_the_title( $pid ) ), ENT_QUOTES, 'UTF-8' ),
		] );
		update_option( self::OPT_LOG, array_slice( $log, 0, self::LOG_MAX ), false );
	}

	/**
	 * A product refused, or taken out of the list: the decision was taken.
	 *
	 * Refusing is a decision like accepting, and it ends in the same place —
	 * what was waiting is thrown away, and the product is recorded under Done
	 * with nothing written. A product left on the list after being dealt with
	 * is a product you deal with again tomorrow.
	 */
	public static function drop_product( int $pid ): void {
		if ( ! $pid ) {
			return;
		}
		delete_post_meta( $pid, self::META_PENDING );
		delete_transient( 'dze_pending_count' );
		self::log_add( $pid, 0, 0, 'dropped' );
	}

	/** @return array<int,array<string,mixed>> newest first. */
	public static function log_entries(): array {
		$log = get_option( self::OPT_LOG, [] );
		return is_array( $log ) ? $log : [];
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
	 * What this screen is showing: 'selection', 'log' or 'empty'.
	 *
	 * An empty screen sitting next to a notice announcing that three products
	 * are waiting is a design failure: the screen's job is to show the work, so
	 * a product holding an undecided result is part of the selection.
	 */
	private function bulk_mode(): string {
		static $mode = null;
		if ( null !== $mode ) {
			return $mode;
		}
		if ( ! empty( $_GET['dze_log'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view switch.
			$mode = 'log';
		} elseif ( self::bulk_list() || self::pending_count() ) {
			// Two views, and one of them is the history: products are selected,
			// worked on, decided on, and then they are done. "Waiting for a
			// decision" was a third place for products that had never left the
			// first one — the same rows, under another name, with another count.
			$mode = 'selection';
		} else {
			$mode = 'empty';
		}
		return $mode;
	}

	/**
	 * The "Done" view: what was written, on which product, when.
	 *
	 * Deliberately not a report — one line per acceptance, newest first, with
	 * a link to the product. It answers one question ("has this been done?")
	 * and nothing else, and it costs one option read.
	 */
	private function render_bulk_log(): void {
		$log = self::log_entries();
		if ( ! $log ) {
			echo '<p>' . esc_html__( 'Nothing has been written from this screen yet. Accepted products are listed here with what they received.', 'dazont-ecom' ) . '</p>';
			return;
		}
		?>
		<p class="dze-cb-listbar">
			<span class="description"><?php
				printf(
					/* translators: %s: number of entries */
					esc_html( _n( '%s product written to the shop', '%s products written to the shop', count( $log ), 'dazont-ecom' ) ),
					esc_html( number_format_i18n( count( $log ) ) )
				);
			?></span>
			<button type="button" class="button-link" id="dze-cb-clearlog" style="color:#b32d2e;margin-left:auto;"><?php esc_html_e( 'Empty this log', 'dazont-ecom' ); ?></button>
		</p>
		<table class="dze-cb-table">
			<tr>
				<th style="width:70px;"></th>
				<th><?php esc_html_e( 'Product', 'dazont-ecom' ); ?></th>
				<th style="width:220px;"><?php esc_html_e( 'Written', 'dazont-ecom' ); ?></th>
				<th style="width:170px;"><?php esc_html_e( 'When', 'dazont-ecom' ); ?></th>
			</tr>
			<?php foreach ( $log as $dze_e ) :
				$dze_id  = (int) ( $dze_e['id'] ?? 0 );
				$dze_thu = $dze_id ? (string) get_the_post_thumbnail_url( $dze_id, 'thumbnail' ) : '';
				?>
				<tr>
					<td class="dze-cb-thumb"><?php if ( $dze_thu ) : ?><img src="<?php echo esc_url( $dze_thu ); ?>" alt="" /><?php endif; ?></td>
					<td><a href="<?php echo esc_url( get_edit_post_link( $dze_id ) ?: '#' ); ?>" target="_blank" rel="noopener"><strong><?php echo esc_html( (string) ( $dze_e['title'] ?? '' ) ); ?></strong></a></td>
					<td>
						<?php if ( ! empty( $dze_e['texts'] ) ) : ?>
							<span class="dze-cb-badge"><?php
								printf(
									/* translators: %s: number of texts */
									esc_html( _n( '%s text', '%s texts', (int) $dze_e['texts'], 'dazont-ecom' ) ),
									esc_html( number_format_i18n( (int) $dze_e['texts'] ) )
								);
							?></span>
						<?php endif; ?>
						<?php if ( ! empty( $dze_e['images'] ) ) : ?>
							<span class="dze-cb-badge"><?php
								printf(
									/* translators: %s: number of images */
									esc_html( _n( '%s image', '%s images', (int) $dze_e['images'], 'dazont-ecom' ) ),
									esc_html( number_format_i18n( (int) $dze_e['images'] ) )
								);
							?></span>
						<?php endif; ?>
						<?php if ( empty( $dze_e['texts'] ) && empty( $dze_e['images'] ) ) : ?>
							<!-- Refused, or taken out of the list before anything was
							     written: the decision was taken, and that is what Done
							     records. -->
							<span class="description"><?php esc_html_e( 'nothing written', 'dazont-ecom' ); ?></span>
						<?php endif; ?>
					</td>
					<td class="description"><?php echo esc_html( wp_date( 'j M Y · H:i', (int) ( $dze_e['time'] ?? 0 ) ) ); ?></td>
				</tr>
			<?php endforeach; ?>
		</table>
		<?php
	}

	private function bulk_products(): array {
		if ( null !== $this->bulk_products_cache ) {
			return $this->bulk_products_cache; // asked for twice per render.
		}
		// ONE list of products being worked on: the ones added by hand, plus
		// the ones holding content that has never been decided on — a product
		// generated from its own page belongs to the work just as much. It
		// leaves this list when a decision is taken on it, accepted or refused,
		// and lands under Done.
		$ids = array_merge( self::bulk_list(), self::pending_ids() );
		$ids = array_values( array_unique( array_filter( array_map( 'intval', (array) $ids ) ) ) );
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

	/**
	 * The section shell the toolbox popup uses, printed for the bulk screen.
	 *
	 * The two dashboards choose the same things and looked nothing alike: flat
	 * blocks with an uppercase heading on one side, collapsible sections with a
	 * caret on the other. Same markup now, so they read the same and any change
	 * to one reaches the other.
	 */
	private static function sec_open( string $id, string $title, bool $open = true ): void {
		printf(
			'<section class="dze-sec%1$s" data-sec="%2$s"><h3 class="dze-sec-head" role="button" tabindex="0" aria-expanded="%3$s"><span class="dze-sec-caret">%4$s</span>%5$s<span class="dze-sec-count"></span></h3><div class="dze-sec-body"%6$s>',
			$open ? ' is-open' : '',
			esc_attr( $id ),
			$open ? 'true' : 'false',
			$open ? '▾' : '▸',
			esc_html( $title ),
			$open ? '' : ' style="display:none;"'
		);
	}
	private static function sec_close(): void {
		echo '</div></section>';
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
			$dze_base = add_query_arg( [ 'post_type' => 'product', 'page' => self::BULK_SLUG ], admin_url( 'edit.php' ) );
			// Products are added to the list, worked on, decided on — and then
			// they are done with. Two tabs is the whole story.
			$dze_counts = self::screen_counts();
			$dze_tabs   = [
				'selection' => [ __( 'Selected products', 'dazont-ecom' ), $dze_base, $dze_counts['all'] ],
				'log'       => [ __( 'Done', 'dazont-ecom' ), add_query_arg( 'dze_log', 1, $dze_base ), $dze_counts['log'] ],
			];
			?>
			<!-- Two states, and that is the whole screen: the products being
			     worked on, and the products that are done with. -->
			<h2 class="nav-tab-wrapper dze-cb-tabs">
				<?php foreach ( $dze_tabs as $dze_key => $dze_tab ) : ?>
					<a href="<?php echo esc_url( $dze_tab[1] ); ?>" data-tab="<?php echo esc_attr( $dze_key ); ?>" class="nav-tab<?php echo ( $dze_key === $dze_mode || ( 'empty' === $dze_mode && 'selection' === $dze_key ) ) ? ' nav-tab-active' : ''; ?>">
						<?php echo esc_html( $dze_tab[0] ); ?>
						<span class="dze-cb-count"><?php echo $dze_tab[2] ? esc_html( number_format_i18n( $dze_tab[2] ) ) : ''; ?></span>
					</a>
				<?php endforeach; ?>
			</h2>
			<?php if ( 'log' === $dze_mode ) : $this->render_bulk_log(); ?>
				</div>
				<?php return; ?>
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
				<?php self::sec_open( 'text', __( 'Texts', 'dazont-ecom' ) ); ?>
					<div class="dze-cb-checks is-col">
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
				<?php self::sec_close(); ?>

				<?php if ( class_exists( 'DZE_Reviews' ) && ( ! class_exists( 'DZE_Modules' ) || DZE_Modules::enabled( 'reviews' ) ) ) : ?>
					<?php self::sec_open( 'reviews', __( 'Reviews', 'dazont-ecom' ), false ); ?>
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
					<?php self::sec_close(); ?>
				<?php endif; ?>

				<?php
				// Variation images are NOT a bulk pass. Which colour of which
				// product needs which photograph is a decision taken in front of
				// the product, one line at a time — run over a list it becomes a
				// bill nobody can predict and a review nobody can follow. It
				// lives on the product screen, in its own popup, and only there.
				?>
				<?php self::sec_open( 'price', __( 'Price', 'dazont-ecom' ), false ); ?>
					<label class="dze-cb-check">
						<input type="checkbox" id="dze-cb-price" checked />
						<span><?php esc_html_e( 'Recalculate from the cost', 'dazont-ecom' ); ?></span>
					</label>
					<?php
					// The product screen shows what the recalculation would do,
					// with the figures, and a link to the table it reads. A bulk
					// run has no single product to preview, but it reads the same
					// table — so the table itself is shown, and edited from here.
					$dze_pt  = self::price_table();
					$dze_url = class_exists( 'DZE_Marketing_Ai' )
						? add_query_arg( [ 'page' => DZE_Marketing_Ai::MENU_SLUG, 'tab' => 'content' ], admin_url( 'admin.php' ) ) . '#dze-set-price'
						: '';
					?>
					<div class="dze-cb-opts">
						<span class="description"><?php esc_html_e( 'Cost × multiplier, rounded to the shop\'s price ending:', 'dazont-ecom' ); ?></span>
						<span class="dze-pt-mini">
							<?php foreach ( $dze_pt as $dze_row ) : ?>
								<span>
									<?php
									printf(
										/* translators: 1: lower bound, 2: upper bound or ∞, 3: multiplier */
										esc_html__( '%1$s–%2$s × %3$s', 'dazont-ecom' ),
										esc_html( (string) $dze_row['min'] ),
										esc_html( $dze_row['max'] > 0 ? (string) $dze_row['max'] : '∞' ),
										esc_html( (string) $dze_row['mult'] )
									);
									?>
								</span>
							<?php endforeach; ?>
						</span>
						<?php if ( $dze_url ) : ?>
							<a class="dze-cx-priceedit" href="<?php echo esc_url( $dze_url ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Edit the price table', 'dazont-ecom' ); ?> &rarr;</a>
						<?php endif; ?>
					</div>
				<?php self::sec_close(); ?>

				<?php self::sec_open( 'img', __( 'Images', 'dazont-ecom' ), false ); ?>
					<?php if ( $valid_tpls ) : ?>
						<label class="dze-cb-check<?php echo $dze_blockers ? ' is-locked' : ''; ?>" title="<?php echo $dze_blockers ? esc_attr( $dze_blockers[0]['text'] ) : ''; ?>">
							<input type="checkbox" id="dze-cb-image" <?php disabled( ! empty( $dze_blockers ) ); ?> />
							<span><?php esc_html_e( 'Generate images', 'dazont-ecom' ); ?><?php echo $dze_blockers ? ' 🔒' : ''; ?></span>
						</label>
						<?php $dze_bscenes = self::scenes(); $dze_bdef = self::default_scene(); ?>
						<div class="dze-cb-opts">
							<!-- One prompt per row, plus a + to add a second when a
							     product needs two kinds of shot — and each row is a
							     whole order: this prompt, on that scene, so many
							     times. The scene and the count used to stand beside
							     the FIRST row as if they belonged to the run, so a
							     second prompt ran on a scene nobody had chosen for
							     it and the screen offered no way to choose one. -->
							<div class="dze-tplgrid<?php echo $dze_bscenes ? '' : ' has-noscene'; ?>">
								<span class="dze-tplhead">
									<span><?php esc_html_e( 'Prompt', 'dazont-ecom' ); ?></span>
									<span></span>
									<?php if ( $dze_bscenes ) : ?><span><?php esc_html_e( 'Scene', 'dazont-ecom' ); ?></span><?php endif; ?>
									<span><?php esc_html_e( 'Attempts', 'dazont-ecom' ); ?></span>
									<span><?php esc_html_e( 'Put it', 'dazont-ecom' ); ?></span>
									<span></span>
								</span>
								<span class="dze-tplrows" id="dze-cb-tplrows" data-name="dze-cb-tpl"></span>
							</div>
							<script type="text/template" id="dze-cb-tpltpl">
								<span class="dze-tplrow">
									<select class="dze-cb-tpl">
										<?php foreach ( $valid_tpls as $i => $t ) : ?>
											<option value="<?php echo (int) $i; ?>" data-prompt="<?php echo esc_attr( 'content_' . (string) ( $t['id'] ?? '' ) ); ?>" data-target="<?php echo esc_attr( (string) ( $t['target'] ?? 'gallery' ) ); ?>"><?php echo esc_html( $t['name'] ); ?></option>
										<?php endforeach; ?>
									</select>
									<button type="button" class="dze-prompt-peek" data-prompt="<?php echo esc_attr( 'content_' . (string) ( $valid_tpls[0]['id'] ?? '' ) ); ?>" title="<?php esc_attr_e( 'See the instructions sent to the model, and edit them', 'dazont-ecom' ); ?>">&#9998;</button>
									<?php if ( $dze_bscenes ) : ?>
										<select class="dze-tpl-scene" title="<?php esc_attr_e( 'The fixed support or background sent as a second image, so this prompt always comes back in the same setting.', 'dazont-ecom' ); ?>">
											<option value="-1" <?php selected( -1, $dze_bdef ); ?>><?php esc_html_e( 'No scene', 'dazont-ecom' ); ?></option>
											<?php foreach ( $dze_bscenes as $dze_si => $dze_sc ) : ?>
												<option value="<?php echo (int) $dze_si; ?>" <?php selected( $dze_si, $dze_bdef ); ?>><?php echo esc_html( $dze_sc['name'] ); ?></option>
											<?php endforeach; ?>
										</select>
									<?php endif; ?>
									<select class="dze-tpl-n" title="<?php esc_attr_e( 'Attempts for this prompt, on each product — you keep the good ones at review time.', 'dazont-ecom' ); ?>">
										<?php foreach ( [ 1, 2, 3, 4 ] as $dze_n ) : ?>
											<option value="<?php echo (int) $dze_n; ?>">× <?php echo (int) $dze_n; ?></option>
										<?php endforeach; ?>
									</select>
									<!-- Where these images land, decided BEFORE the run: it
									     starts on the prompt's own destination and can be
									     changed here. Correcting it image by image after a
									     run of thirty products is not a review, it is data
									     entry. -->
									<select class="dze-tpl-target" title="<?php esc_attr_e( 'Where the images made by this prompt go on each product.', 'dazont-ecom' ); ?>">
										<option value="main"><?php esc_html_e( 'Main image', 'dazont-ecom' ); ?></option>
										<option value="gallery_first"><?php esc_html_e( 'Gallery, first', 'dazont-ecom' ); ?></option>
										<option value="gallery" selected><?php esc_html_e( 'Product gallery', 'dazont-ecom' ); ?></option>
									</select>
									<span class="dze-tplbtns">
										<button type="button" class="button button-small dze-tpl-add" title="<?php esc_attr_e( 'Add another image prompt to this run', 'dazont-ecom' ); ?>">+</button>
										<button type="button" class="button button-small dze-tpl-del" title="<?php esc_attr_e( 'Remove this prompt', 'dazont-ecom' ); ?>">&minus;</button>
									</span>
								</span>
							</script>
							<!-- Decided BEFORE the run, like everything else on this
							     screen: an image taking the main slot pushes the old
							     main image into the gallery, or takes it off the
							     product. It was only asked on the review strip, one
							     product at a time, which on a list of thirty is the
							     same answer given thirty times. -->
							<label class="dze-cb-oldmain" id="dze-cb-oldwrap" style="display:none;">
								<span><?php esc_html_e( 'Today\'s main image', 'dazont-ecom' ); ?></span>
								<select id="dze-cb-oldmain">
									<option value="1"><?php esc_html_e( 'goes to the gallery', 'dazont-ecom' ); ?></option>
									<option value="0"><?php esc_html_e( 'leaves the product', 'dazont-ecom' ); ?></option>
								</select>
							</label>
						</div>
					<?php else : ?>
						<p class="description"><?php esc_html_e( 'No validated image prompt yet — validate one in Settings → Product content to enable images in bulk.', 'dazont-ecom' ); ?></p>
					<?php endif; ?>
				<?php self::sec_close(); ?>

				<div class="dze-cb-mode"><?php self::sec_open( 'mode', __( 'Before writing to the shop', 'dazont-ecom' ), false ); ?>
					<label class="dze-cb-check"><input type="radio" name="dze-cb-mode" value="review" checked />
						<span><?php esc_html_e( 'Review, then apply what I keep', 'dazont-ecom' ); ?></span></label>
					<label class="dze-cb-check"><input type="radio" name="dze-cb-mode" value="direct" />
						<span><?php esc_html_e( 'Apply immediately, no confirmation', 'dazont-ecom' ); ?></span></label>
				<?php self::sec_close(); ?></div>

				<p class="dze-cb-actions">
					<!-- Generate, on the products that are ticked — the same rule as
					     Apply and Delete below. It used to run on every line of the
					     list whatever was ticked, which made the tick boxes mean one
					     thing here and another thing three centimetres lower. -->
					<button type="button" class="button button-primary button-hero" id="dze-cb-start" title="<?php esc_attr_e( 'Generate the ticked content for the ticked products', 'dazont-ecom' ); ?>" <?php disabled( 0 === $ok_n && empty( $valid_tpls ) ); ?>><?php esc_html_e( 'Generate', 'dazont-ecom' ); ?></button>

					<button type="button" class="button" id="dze-cb-stop" style="display:none;"><?php esc_html_e( 'Stop', 'dazont-ecom' ); ?></button>
				</p>
				<p id="dze-cb-progress" class="description"></p>
			</div>

			<!-- Tick products, then act on them. Three words, the same three on
			     every tab and on every line: Generate, Apply, Delete. What was
			     here before said "Apply the ticked products", "Throw away what
			     is waiting", "Throw away everything waiting" and "Remove from
			     the list" — four sentences for two actions, each one worded
			     differently depending on the tab you were standing on. -->
			<p class="dze-cb-listbar">
				<span id="dze-cb-selcount" class="description"></span>
				<button type="button" class="button button-small" id="dze-cb-selall"><?php esc_html_e( 'Select all', 'dazont-ecom' ); ?></button>
				<button type="button" class="button button-small" id="dze-cb-selnone"><?php esc_html_e( 'Unselect all', 'dazont-ecom' ); ?></button>
				<span class="dze-cb-barsep"></span>
				<button type="button" class="button button-primary" id="dze-cb-applysel" title="<?php esc_attr_e( 'Write the generated content of the ticked products to the shop', 'dazont-ecom' ); ?>"><?php esc_html_e( 'Apply', 'dazont-ecom' ); ?></button>
				<button type="button" class="button" id="dze-cb-delete" title="<?php esc_attr_e( 'Take the ticked products out of this list and throw away what is waiting on them. The products themselves are not modified.', 'dazont-ecom' ); ?>"><?php esc_html_e( 'Delete', 'dazont-ecom' ); ?></button>
				<span class="dze-cb-barsep"></span>
				<button type="button" class="button-link" id="dze-cb-clearlist" style="color:#b32d2e;"><?php esc_html_e( 'Delete all', 'dazont-ecom' ); ?></button>
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
					<th style="width:70px;" title="<?php esc_attr_e( 'Click a thumbnail to open the product.', 'dazont-ecom' ); ?>"></th>
					<th title="<?php esc_attr_e( 'A green badge appears under the name for each piece of content produced.', 'dazont-ecom' ); ?>"><?php esc_html_e( 'Product', 'dazont-ecom' ); ?></th>
					<th style="width:80px;" title="<?php esc_attr_e( 'Cost of goods. On a variable product this is the lowest cost recorded on its variations.', 'dazont-ecom' ); ?>"><?php esc_html_e( 'Cost', 'dazont-ecom' ); ?></th>
					<th style="width:260px;" title="<?php esc_attr_e( '○ waiting, spinner while writing, ✓ ready, ✗ failed. Hover the symbol for the detail.', 'dazont-ecom' ); ?>"><?php esc_html_e( 'Status', 'dazont-ecom' ); ?></th>
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
							<!-- The same two words as the bar above, on the line they act
							     on. A tick, a cross and a bin in a row said three things
							     nobody could name. -->
							<button type="button" class="button button-small dze-cb-apply-one" style="display:none;" title="<?php esc_attr_e( 'Write the generated content of this product to the shop', 'dazont-ecom' ); ?>"><?php esc_html_e( 'Apply', 'dazont-ecom' ); ?></button>
							<button type="button" class="button button-small dze-cb-del-one" title="<?php esc_attr_e( 'Take this product out of the list and throw away what is waiting on it. The product itself is not modified.', 'dazont-ecom' ); ?>"><?php esc_html_e( 'Delete', 'dazont-ecom' ); ?></button>
						</td>
					</tr>
					<tr class="dze-cb-preview" data-id="<?php echo (int) $p['id']; ?>" style="display:none;"><td colspan="5"></td></tr>
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
		wp_enqueue_style( 'dze-zoom', DZE_URL . 'admin/css/zoom.css', [], DZE_VERSION );
		wp_enqueue_script( 'dze-hzoom', DZE_URL . 'admin/js/hzoom.js', [ 'jquery' ], DZE_VERSION, true );
		// The product's photographs, drawn by ONE renderer wherever they show:
		// the product screen and the bulk screen had one each, so every
		// improvement had to be made twice and never was.
		{
			// Enqueued on every screen this function serves, not only the two
			// that draw the block: dze-content and dze-content-bulk depend on
			// it, and a dependency that was never enqueued silently drops the
			// script that needs it.
			wp_enqueue_script( 'dze-photos', DZE_URL . 'admin/js/photos.js', [ 'jquery' ], DZE_VERSION, true );
			wp_localize_script( 'dze-photos', 'dzePhotosCfg', [
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE ),
				'ratios'  => self::ratios(),
				'i18n'    => [
					'nowImages' => __( 'Photographs already on the product', 'dazont-ecom' ),
					'nowMain'   => __( 'Main image', 'dazont-ecom' ),
					'nowGallery'=> __( 'Gallery', 'dazont-ecom' ),
					// Two buttons that say what they do, rather than a menu that
					// has to be opened to find out what the screen can even do.
					'btnAi'     => __( 'Main image with AI', 'dazont-ecom' ),
					'btnRf'     => __( 'Resize images', 'dazont-ecom' ),
					'rfStart'   => __( 'Reframe photographs', 'dazont-ecom' ),
					'rfAll'     => __( 'All / none', 'dazont-ecom' ),
					'rfShape'   => __( 'Shape', 'dazont-ecom' ),
					'rfHow'     => __( 'How', 'dazont-ecom' ),
					'rfPad'     => __( 'Extend the background (nothing is cut)', 'dazont-ecom' ),
					'rfCrop'    => __( 'Crop to shape (the sides are cut)', 'dazont-ecom' ),
					'rfRun'     => __( 'Reframe', 'dazont-ecom' ),
					'rfNone'    => __( 'Tick the photographs to reframe.', 'dazont-ecom' ),
					'rfApply'   => __( 'Save on the product', 'dazont-ecom' ),
					'rfDropOld' => __( 'and delete the originals', 'dazont-ecom' ),
					'cancel'    => __( 'Cancel', 'dazont-ecom' ),
					'discard'   => __( 'Discard', 'dazont-ecom' ),
					'qmNow'     => __( 'Before', 'dazont-ecom' ),
					'qmNew'     => __( 'After', 'dazont-ecom' ),
					'working'   => __( 'Working…', 'dazont-ecom' ),
					'applying'  => __( 'Applying…', 'dazont-ecom' ),
					'applied'   => __( 'Applied ✓', 'dazont-ecom' ),
					'error'     => __( 'error', 'dazont-ecom' ),
				],
			] );
		}
		// The zoom viewer travels with it: every grid of product images in the
		// plugin opens the same way.
		// A settings page is saved by its own Save Changes button and nothing
		// else. There used to be a background submit here, with a per-prompt
		// button that posted the WHOLE form: it hung on slow servers, fell back
		// to an ordinary submit, and — because the form carried every row as it
		// stood when the page was opened — it wrote old text back over prompts
		// edited since, from another tab or from the toolbox.
		wp_localize_script( 'dze-hzoom', 'dzeZoomI18n', [
			'zoom'  => __( 'See this image full size', 'dazont-ecom' ),
			'close' => __( 'Close', 'dazont-ecom' ),
			'prev'  => __( 'Previous image', 'dazont-ecom' ),
			'next'  => __( 'Next image', 'dazont-ecom' ),
		] );
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
			wp_enqueue_script( 'dze-content-bulk', DZE_URL . 'admin/js/content-bulk.js', [ 'jquery', 'dze-photos' ], DZE_VERSION, true );
			wp_localize_script( 'dze-content-bulk', 'dzeContentBulk', [
				'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
				'nonce'     => wp_create_nonce( self::NONCE ),
				'validated' => true, // gating is per-field via disabled checkboxes.
				// Where the screen goes back to after a paste: the selection, not
				// whatever filtered view it was opened on.
				'listUrl'   => add_query_arg( [ 'post_type' => 'product', 'page' => self::BULK_SLUG ], admin_url( 'edit.php' ) ),
				// Which list is on screen: the selection, or the products
				// holding content waiting for a decision. Taking a row out
				// means a different thing in each, and used to rewrite the
				// selection in both — which did nothing at all on the waiting
				// view, since the products there were never in it.
				'mode'      => $this->bulk_mode(),
				'fields'    => array_map( static fn( $f ) => $f['label'], self::enabled_fields() ),
			// The image recipes, so a button can say WHICH style it makes
			// instead of "one more image".
			'templates' => array_map(
				static fn( $t ) => [ 'id' => (string) ( $t['id'] ?? '' ), 'name' => $t['name'] ],
				self::image_templates()
			),
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
					/* translators: %s: the variation's name, e.g. a colour */
					'toVariation' => __( 'Variation: %s', 'dazont-ecom' ),
					'imgBadge' => __( 'Images', 'dazont-ecom' ),
					'revBadge' => __( 'Reviews', 'dazont-ecom' ),
					'revNonce' => wp_create_nonce( 'dze_reviews' ),
					'running'  => __( 'in progress', 'dazont-ecom' ),
					'partial'  => __( '%1$s of %2$s written', 'dazont-ecom' ),
					'redoOne'  => __( 'Write this one again', 'dazont-ecom' ),
					'redoAll'  => __( 'Write every text again', 'dazont-ecom' ),
					'oneMore'  => __( 'One more image', 'dazont-ecom' ),
					'shotPos'  => __( 'Click to change where this image goes', 'dazont-ecom' ),
					'shotRedo' => __( 'Make this image again', 'dazont-ecom' ),
					'shotRedoOne' => __( 'Make this image again with %s', 'dazont-ecom' ),
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
					'confirmClear' => __( 'Take every product out of this list? What is waiting on them is thrown away and they are filed under Done. The products themselves are not modified.', 'dazont-ecom' ),
					/* translators: %s: number of ticked products */
					'confirmDelete' => __( 'Take %s products out of the list? What is waiting on them is thrown away and they are filed under Done. The products themselves are not modified.', 'dazont-ecom' ),
					/* translators: %s: number of ticked products */
					'confirmSel' => __( 'Write the generated content to the %s ticked products? This modifies the shop.', 'dazont-ecom' ),
					'confirmOne' => __( 'Write this content to the product? It replaces what is there now.', 'dazont-ecom' ),
					'applyOne' => __( 'Apply this product', 'dazont-ecom' ),
					'nothingKept' => __( 'Nothing is waiting to be applied.', 'dazont-ecom' ),
					'sSkipped' => __( 'Left alone — already written and waiting for a decision', 'dazont-ecom' ),
					/* translators: %s: number of products */
					'skippedN' => __( '%s left alone (already written)', 'dazont-ecom' ),
					'allSkipped' => __( 'Every product on screen is already holding content waiting for a decision. Accept it or discard it, then run again.', 'dazont-ecom' ),
					'applying' => __( 'Applying…', 'dazont-ecom' ),
					/* translators: %s: number of ticked products */
					'applySelN' => __( 'Apply (%s)', 'dazont-ecom' ),
					/* translators: %s: number of ticked products */
					'deleteN'  => __( 'Delete (%s)', 'dazont-ecom' ),
					/* translators: %s: number of ticked products */
					'generateN' => __( 'Generate (%s)', 'dazont-ecom' ),
					'tickFirst' => __( 'Tick the products you want to work on first.', 'dazont-ecom' ),
					'tickNoContent' => __( 'None of the ticked products is holding content to write. Generate first, or tick a product that shows a Review button.', 'dazont-ecom' ),
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
					'confirmDrop' => __( 'Throw away the content generated for this product? It cannot be recovered. The product leaves the list and is filed under Done.', 'dazont-ecom' ),
					// A block that could not be read says so, and offers to try
					// again — an empty space reads as a broken screen.
					'nowFailed'   => __( 'The photographs of this product could not be read.', 'dazont-ecom' ),
					'confirmClearLog' => __( 'Empty the log of what was written? The products keep everything they received; only this list is erased.', 'dazont-ecom' ),
					'retry'       => __( 'Try again', 'dazont-ecom' ),
					// What becomes of the image holding the main slot, asked on
					// the strip that is about to replace it.
					'oldMain'     => __( 'Today\'s main image', 'dazont-ecom' ),
					'oldKeep'     => __( 'goes to the gallery', 'dazont-ecom' ),
					'oldDrop'     => __( 'leaves the product', 'dazont-ecom' ),
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
		wp_enqueue_script( 'dze-content', DZE_URL . 'admin/js/content.js', [ 'jquery', 'dze-photos' ], DZE_VERSION, true );

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
			// What each prompt is set to receive, and how it pairs with a
			// photograph — so the toolbox can show and change the same settings
			// as the settings screen instead of being a read-only cousin.
			'inputOpts'  => self::input_options(),
			'rowcfg'     => array_reduce(
				self::registry(),
				static function ( array $out, array $r ): array {
					$out[ (string) ( $r['id'] ?? '' ) ] = [
						'inputs'    => array_values( (array) ( $r['inputs'] ?? [] ) ),
						'img_meta'  => (string) ( $r['img_meta'] ?? '' ),
						'img_rules' => (string) ( $r['img_rules'] ?? '' ),
						'type'      => (string) ( $r['type'] ?? 'text' ),
					];
					return $out;
				},
				[]
			),
			'imgRulesDef' => self::default_feature_prompt(),
			// The meta keys that exist on products, so the pairing key is
			// PICKED — an ACF image field has a name you must not mistype.
			'metaKeys'   => self::product_meta_keys(),
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
			// What each prompt WRITES, so a result can be put back into the very
			// field of the page it was just saved into. Without this the page
			// kept showing the old text — and, worse, hitting Update wrote that
			// old text straight back over what had just been generated.
			'dests'      => array_map(
				static fn( $f ) => (string) ( $f['dest'] ?? '' ),
				self::enabled_fields()
			),
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
						// The Attributes PANEL, not the whole Product data box:
						// planted on the box, the button stood above every tab
						// — Variations included — saying "write this" about
						// nothing anyone could point at.
						'attributes'   => '#product_attributes',
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
			// The note this product carries: sent with every image made for it,
			// written once, here.
			'note'       => $pid ? self::variation_note( $pid, self::NOTE_PRODUCT ) : '',
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
			// Which ROW that is, so its pencil opens the right prompt. Nothing
			// is reserved: it is simply the first image prompt writing the main
			// image, and it changes the moment the list does.
			'mainRecipe' => (string) ( self::main_recipe()['id'] ?? '' ),
			// The shapes offered when reframing.
			'ratios'     => self::ratios(),
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
				// Sold in several colours? Then it has variation images to fill.
				'variable' => (int) ( $product && $product->is_type( 'variable' ) ),
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
				'attemptsHelp' => __( 'Attempts for this prompt — you keep the good ones once you have seen them.', 'dazont-ecom' ),
				'blocked'    => __( 'Images cannot be generated right now:', 'dazont-ecom' ),
				'applyOne'   => __( 'Apply to the product', 'dazont-ecom' ),
				'redoAll'    => __( 'Write every text again', 'dazont-ecom' ),
				'redoOne'    => __( 'Write this one again', 'dazont-ecom' ),
				'redoShort'  => __( 'Rewrite', 'dazont-ecom' ),
				'promptTip'  => __( 'See the instructions sent to the model, and edit them', 'dazont-ecom' ),
				// The fast lane.
				'qmTitle'    => __( 'Main image', 'dazont-ecom' ),
				'qmNow'      => __( 'Main image today', 'dazont-ecom' ),
				'qmNew'      => __( 'New', 'dazont-ecom' ),
				'qmSource'   => __( 'Worked from', 'dazont-ecom' ),
				/* translators: %s: number of attempts */
				'tryPick'    => __( '%s attempts — tick the ones to keep', 'dazont-ecom' ),
				'qmAgain'    => __( 'Try again', 'dazont-ecom' ),
				'qmBgNone'   => __( 'None (described in the prompt)', 'dazont-ecom' ),
				'qmBgPlate'  => __( 'The shop backdrop', 'dazont-ecom' ),
				'qmPaste'    => __( 'Paste an image here (Ctrl+V), drop a file, or', 'dazont-ecom' ),
				// The file is read in the browser and travels inside the
				// request: nothing is stored on the site for a source image.
				'qmBrowse'   => __( 'choose one on your computer', 'dazont-ecom' ),
				'qmPasted'   => __( 'Image ready ✓', 'dazont-ecom' ),
				'withProduct'=> __( 'Send the product\'s own photographs with it', 'dazont-ecom' ),
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
				'psData'     => __( 'Product data sent with it', 'dazont-ecom' ),
				'psPair'     => __( 'Illustrate this block with one of the product photographs', 'dazont-ecom' ),
				/* translators: %s: meta key */
				'psOn'       => __( 'on → %s', 'dazont-ecom' ),
				'psOff'      => __( 'off', 'dazont-ecom' ),
				'psPairH'    => __( 'Leave the key empty and this block is written from the product data alone. Fill it in and the plugin LOOKS at the photographs, picks the one this block should sit next to, writes the block about what is visible in it, and stores that photograph\'s id in the key so your theme can show them together.', 'dazont-ecom' ),
				'psKey'      => __( 'Photograph goes to', 'dazont-ecom' ),
				'psRules'    => __( 'How its photograph is chosen', 'dazont-ecom' ),
				'oneSaved'   => __( 'Prompt saved ✓', 'dazont-ecom' ),
				'oneBefore'  => __( 'On the product today', 'dazont-ecom' ),
				'oneAfter'   => __( 'What was just written', 'dazont-ecom' ),
				'oneGen'     => __( 'Write it', 'dazont-ecom' ),
				'oneRedo'    => __( 'Write it again', 'dazont-ecom' ),
				'oneApply'   => __( 'Save on the product', 'dazont-ecom' ),
				// What the page cannot show by itself: SEO fields, custom
				// blocks, attributes. Written, but not on screen until it is
				// read again.
				'reloadWhy'  => __( 'Saved — this page cannot show it until it is loaded again.', 'dazont-ecom' ),
				'reloadNow'  => __( 'Reload the page', 'dazont-ecom' ),
				/* translators: %s: number of images kept */
				'oneApplyN'  => __( 'Save these %s on the product', 'dazont-ecom' ),
				'oneDropAll' => __( 'Throw these attempts away', 'dazont-ecom' ),
				'dropped'    => __( 'Thrown away ✓', 'dazont-ecom' ),
				// Several images in one go, and what becomes of the image the
				// new main one replaces.
				'howMany'    => __( 'How many', 'dazont-ecom' ),
				/* translators: 1: attempt number, 2: attempts asked for */
				'tryN'       => __( 'Generating %1$s of %2$s…', 'dazont-ecom' ),
				'oldMain'    => __( 'Today\'s main image', 'dazont-ecom' ),
				// One image per colour, written to every size of that colour.
				'varTitle'   => __( 'Variation images', 'dazont-ecom' ),
				'varIntro'   => __( 'One image per colour, written to every variation of that colour.', 'dazont-ecom' ),
				'varGroupBy' => __( 'Grouped by', 'dazont-ecom' ),
				'varNone'    => __( 'This product has no variations to group.', 'dazont-ecom' ),
				'varNoPrompt'=> __( 'No prompt writes variation images yet. Add one under Settings → Product content → Prompts, with "Variation image" as its destination.', 'dazont-ecom' ),
				'varRun'     => __( 'Make the ticked images', 'dazont-ecom' ),
				'varOpen'    => __( 'Open variation images', 'dazont-ecom' ),
				'varOwn'     => __( 'This product', 'dazont-ecom' ),
				'varOwnHelp' => __( "Pick one of the photographs this product already has — main image and gallery together.", 'dazont-ecom' ),
				'varOwnPick' => __( 'Give this photograph to this variation', 'dazont-ecom' ),
				'varOwnMain' => __( 'The main image of the product — give it to this variation', 'dazont-ecom' ),
				'varOwnMainTag' => __( 'main', 'dazont-ecom' ),
				'varOwnNone' => __( 'This product has no photograph yet.', 'dazont-ecom' ),
				'varLib'     => __( 'Library', 'dazont-ecom' ),
				'varLibTitle'=> __( 'Choose the image for this variation', 'dazont-ecom' ),
				'varPaste'   => __( 'Paste / file', 'dazont-ecom' ),
				'varClear'   => __( 'Take this image off the variations', 'dazont-ecom' ),
				/* translators: %s: number of generated images waiting for a decision */
				'varSaveAll' => __( 'Save these %s on the product', 'dazont-ecom' ),
				'varNote'    => __( 'Notes', 'dazont-ecom' ),
				'noteTitle'  => __( 'Notes about this product', 'dazont-ecom' ),
				'noteHelp'   => __( 'Sent with every image made for this product — the real fabric, the finish, what no photograph shows. Saved as you leave the box.', 'dazont-ecom' ),
				'notePh'     => __( 'e.g. black ripstop fabric, matte hardware, red logo on the chest', 'dazont-ecom' ),
				'noteSaved'  => __( 'Saved ✓', 'dazont-ecom' ),
				'varNoteLabel' => __( 'What to know about this variation', 'dazont-ecom' ),
				'varNoteHelp'=> __( 'Kept with the product and sent with every image made for this variation.', 'dazont-ecom' ),
				'varNotePh'  => __( 'e.g. fabric: black, multicam tropic ripstop camo', 'dazont-ecom' ),
				'varUseAs'   => __( 'Use it as it is', 'dazont-ecom' ),
				'varFromIt'  => __( 'Make a clean image from it', 'dazont-ecom' ),
				'varMissing' => __( 'Tick the ones without an image', 'dazont-ecom' ),
				/* translators: 1: variations in the group, 2: how many have their own image */
				'varCount'   => __( '%1$s variations · %2$s with their own image', 'dazont-ecom' ),
				'varHasNone' => __( 'no image of its own', 'dazont-ecom' ),
				'toVariation'=> __( 'Variation: %s', 'dazont-ecom' ),
				'putHelp'    => __( 'Where the images made by this prompt go.', 'dazont-ecom' ),
				'putIt'      => __( 'Put it', 'dazont-ecom' ),
				'oldKeep'    => __( 'goes to the gallery', 'dazont-ecom' ),
				'oldDrop'    => __( 'leaves the product', 'dazont-ecom' ),
				'oneOthers'  => __( 'Write just one block:', 'dazont-ecom' ),
				'shotsLabel' => __( 'Generated images — tick the ones to keep, then save', 'dazont-ecom' ),
				'shotDrop'   => __( 'Throw this image away', 'dazont-ecom' ),
				'bgAdd'      => __( 'Keep a new background', 'dazont-ecom' ),
				'bgPick'     => __( 'Choose the background image', 'dazont-ecom' ),
				'bgUse'      => __( 'Keep this one', 'dazont-ecom' ),
				// The image workshop.
				'imgSource'  => __( 'Work from', 'dazont-ecom' ),
				// The three questions the image workshop asks, in order.
				'stepWhat'   => __( 'What are we making?', 'dazont-ecom' ),
				'notValidHere' => __( 'This prompt is not validated: bulk refuses it. You can still run it here — trying it on one product, with the result in front of you, is how you decide to validate it.', 'dazont-ecom' ),
				// The request has two halves: the instructions, and what the
				// model is told about this product.
				'panePrompt' => __( 'The instructions', 'dazont-ecom' ),
				'paneData'   => __( 'What it receives about this product', 'dazont-ecom' ),
				'paneDataH'  => __( 'Exactly what travels with the instructions. What goes in here is set per prompt, in its card under Settings → Product content → Prompts: tick Categories there and they appear above.', 'dazont-ecom' ),
				// Reframing: the shape of a photograph, changed here rather
				// than in an image editor.
				'nowMain'    => __( 'Main image', 'dazont-ecom' ),
				'nowGallery' => __( 'Gallery', 'dazont-ecom' ),
				'nowAi'      => __( 'Remake with AI', 'dazont-ecom' ),
				'rfStart'    => __( 'Reframe photographs', 'dazont-ecom' ),
				'rfAll'      => __( 'All / none', 'dazont-ecom' ),
				'rfShape'    => __( 'Shape', 'dazont-ecom' ),
				'rfHow'      => __( 'How', 'dazont-ecom' ),
				'rfPad'      => __( 'Extend the background (nothing is cut)', 'dazont-ecom' ),
				'rfCrop'     => __( 'Crop to shape (the sides are cut)', 'dazont-ecom' ),
				'rfRun'      => __( 'Reframe', 'dazont-ecom' ),
				'rfNone'     => __( 'Tick the photographs to reframe.', 'dazont-ecom' ),
				'rfApply'    => __( 'Save on the product', 'dazont-ecom' ),
				'rfDropOld'  => __( 'and delete the originals', 'dazont-ecom' ),
				'cancel'     => __( 'Cancel', 'dazont-ecom' ),
				'stepFrom'   => __( 'From which photograph?', 'dazont-ecom' ),
				'stepBg'     => __( 'On which background?', 'dazont-ecom' ),
				'stepElse'   => __( 'An image from elsewhere', 'dazont-ecom' ),
				'noRecipes'  => __( 'No image prompt writes here yet. Add one under Settings → Product content → Prompts.', 'dazont-ecom' ),
				'oneGallery' => __( 'Gallery images', 'dazont-ecom' ),
				'imgAll'     => __( 'Every photograph of the product', 'dazont-ecom' ),
				'imgRecipe'  => __( 'Prompt', 'dazont-ecom' ),
				'imgWhere'   => __( 'Put it', 'dazont-ecom' ),
				'imgReplace' => __( 'and delete the photograph it was made from', 'dazont-ecom' ),
				'imgRun'     => __( 'Make the image', 'dazont-ecom' ),
				'imgSaved'   => __( 'Saved on the product ✓', 'dazont-ecom' ),
				'keepHelp'   => __( 'Untick to leave this block out — the rest is still written', 'dazont-ecom' ),
				'nothingKept'=> __( 'Nothing left to write: every block was unticked.', 'dazont-ecom' ),
				'oneMore'    => __( 'One more image', 'dazont-ecom' ),
				'shotPos'    => __( 'Click to change where this image goes', 'dazont-ecom' ),
				'shotRedo'   => __( 'Make this image again', 'dazont-ecom' ),
				'shotRedoOne'=> __( 'Make this image again with %s', 'dazont-ecom' ),
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
				self::write_image_meta( $pid, $img_key, (int) $map[ $field ]['id'] );
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







	/** Only fal's own delivery hosts are accepted as remote sources (no SSRF). */
	public static function is_fal_url( string $url ): bool {
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		// fal serves results from several of its own domains and has added more
		// over time. The list stays a list of ITS hosts — never a wildcard, or
		// this guard would let the plugin download whatever an answer names.
		foreach ( [ 'fal.media', 'fal.run', 'fal.ai' ] as $own ) {
			if ( $host === $own || str_ends_with( $host, '.' . $own ) ) {
				return true;
			}
		}
		return false;
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





	/** Where a reframed file waits between being seen and being accepted. */
	private static function reframe_key( int $att_id, string $ratio, string $mode ): string {
		return 'dze_rfr_' . md5( $att_id . '|' . $ratio . '|' . $mode );
	}

	/**
	 * A bounded copy for the screen. The reframed file itself can be several
	 * megabytes, and it has no business travelling through a JSON response.
	 */
	private static function preview_uri( string $file ): string {
		$editor = wp_get_image_editor( $file );
		if ( is_wp_error( $editor ) ) {
			return '';
		}
		$editor->resize( 600, 600, false );
		$tmp   = wp_tempnam( 'dze-rfr-preview.jpg' );
		$saved = $editor->save( $tmp, 'image/jpeg' );
		$path  = is_wp_error( $saved ) ? '' : (string) ( $saved['path'] ?? '' );
		if ( '' === $path || ! file_exists( $path ) ) {
			return '';
		}
		$bytes = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		wp_delete_file( $path );
		return 'data:image/jpeg;base64,' . base64_encode( $bytes ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- data URI for one preview.
	}


	/**
	 * Puts $new exactly where $old stood: the main image slot, or the same
	 * position in the gallery. Anything else scrambles the order of a gallery
	 * that was arranged on purpose.
	 */
	public static function swap_image( int $pid, int $old, int $new ): void {
		if ( (int) get_post_thumbnail_id( $pid ) === $old ) {
			set_post_thumbnail( $pid, $new );
			return;
		}
		$ids = array_filter( array_map( 'absint', explode( ',', (string) get_post_meta( $pid, '_product_image_gallery', true ) ) ) );
		$at  = array_search( $old, $ids, true );
		if ( false === $at ) {
			$ids[] = $new;
		} else {
			$ids[ $at ] = $new;
		}
		update_post_meta( $pid, '_product_image_gallery', implode( ',', array_values( array_unique( $ids ) ) ) );
	}


	public static function ratio_label( int $w, int $h ): string {
		if ( $w < 1 || $h < 1 ) {
			return '';
		}
		$a = $w;
		$b = $h;
		while ( $b ) {
			[ $a, $b ] = [ $b, $a % $b ];
		}
		$rw = (int) ( $w / max( 1, $a ) );
		$rh = (int) ( $h / max( 1, $a ) );
		if ( $rw <= 20 && $rh <= 20 ) {
			return $rw . ':' . $rh;
		}
		return ( $w >= $h )
			? number_format_i18n( round( $w / $h, 2 ), 2 ) . ':1'
			: '1:' . number_format_i18n( round( $h / $w, 2 ), 2 );
	}

	/** The shapes offered, as width:height. */
	public static function ratios(): array {
		return [ '1:1', '4:5', '3:4', '4:3', '16:9' ];
	}

	/**
	 * Reframes a photograph to a given shape and writes the result to a temp
	 * file, returning its path.
	 *
	 * No model is involved and no call is made: a shape change is arithmetic,
	 * and asking a generative model to do it would cost money, take seconds and
	 * come back with a redrawn product.
	 *
	 * Two ways, and the difference matters on a shop:
	 *   pad  — the photograph is placed whole on a larger canvas filled with
	 *          its own border colour. NOTHING is cut off. This is the one for
	 *          a product shot on a plain background.
	 *   crop — the middle is kept and the sides are cut. Right for a photograph
	 *          with margin to spare, wrong for a product that fills its frame.
	 */
	public static function reframe_file( int $att_id, string $ratio, string $mode = 'pad' ): string {
		$file = get_attached_file( $att_id );
		if ( ! $file || ! file_exists( $file ) ) {
			throw new RuntimeException( __( 'That image file is missing from the server.', 'dazont-ecom' ) );
		}
		if ( ! in_array( $ratio, self::ratios(), true ) ) {
			throw new RuntimeException( __( 'Unknown shape.', 'dazont-ecom' ) );
		}
		[ $rw, $rh ] = array_map( 'intval', explode( ':', $ratio ) );
		$size = @getimagesize( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! $size ) {
			throw new RuntimeException( __( 'That file is not an image.', 'dazont-ecom' ) );
		}
		[ $w, $h ] = [ (int) $size[0], (int) $size[1] ];
		if ( 'crop' === $mode ) {
			// The largest rectangle of the wanted shape that fits inside.
			$cw = min( $w, (int) round( $h * $rw / $rh ) );
			$ch = min( $h, (int) round( $w * $rh / $rw ) );
			$editor = wp_get_image_editor( $file );
			if ( is_wp_error( $editor ) ) {
				throw new RuntimeException( $editor->get_error_message() );
			}
			$done = $editor->crop( (int) round( ( $w - $cw ) / 2 ), (int) round( ( $h - $ch ) / 2 ), $cw, $ch );
			if ( is_wp_error( $done ) ) {
				throw new RuntimeException( $done->get_error_message() );
			}
			$tmp = wp_tempnam( 'dze-reframe.jpg' );
			$saved = $editor->save( $tmp, 'image/jpeg' );
			if ( is_wp_error( $saved ) ) {
				throw new RuntimeException( $saved->get_error_message() );
			}
			return (string) ( $saved['path'] ?? $tmp );
		}
		if ( ! function_exists( 'imagecreatetruecolor' ) ) {
			throw new RuntimeException( __( 'This server cannot pad images (GD is missing). Use "Crop to shape" instead.', 'dazont-ecom' ) );
		}
		// The canvas: the photograph, extended on the two short sides only.
		$cw = max( $w, (int) round( $h * $rw / $rh ) );
		$ch = max( $h, (int) round( $w * $rh / $rw ) );
		$src = self::gd_read( $file, (string) ( $size['mime'] ?? '' ) );
		$dst = imagecreatetruecolor( $cw, $ch );
		// The colour to extend with is READ from the photograph's own border,
		// so a grey backdrop stays that grey instead of gaining white bands.
		[ $r, $g, $b ] = self::border_colour( $src, $w, $h );
		imagefilledrectangle( $dst, 0, 0, $cw, $ch, imagecolorallocate( $dst, $r, $g, $b ) );
		imagecopy( $dst, $src, (int) round( ( $cw - $w ) / 2 ), (int) round( ( $ch - $h ) / 2 ), 0, 0, $w, $h );
		$tmp = wp_tempnam( 'dze-reframe.jpg' );
		imagejpeg( $dst, $tmp, 90 );
		imagedestroy( $dst );
		imagedestroy( $src );
		return $tmp;
	}

	/** @return \GdImage */
	private static function gd_read( string $file, string $mime ) {
		$img = false;
		if ( 'image/png' === $mime && function_exists( 'imagecreatefrompng' ) ) {
			$img = @imagecreatefrompng( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		} elseif ( 'image/webp' === $mime && function_exists( 'imagecreatefromwebp' ) ) {
			$img = @imagecreatefromwebp( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		} elseif ( function_exists( 'imagecreatefromjpeg' ) ) {
			$img = @imagecreatefromjpeg( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		if ( ! $img ) {
			throw new RuntimeException( __( 'That image could not be read.', 'dazont-ecom' ) );
		}
		return $img;
	}

	/**
	 * The dominant colour of the photograph's outer frame — the median of the
	 * four corners and the middle of each side. A single corner is enough to be
	 * wrong the day a shadow reaches it.
	 *
	 * @param \GdImage $img
	 * @return array{0:int,1:int,2:int}
	 */
	private static function border_colour( $img, int $w, int $h ): array {
		$points = [
			[ 1, 1 ], [ $w - 2, 1 ], [ 1, $h - 2 ], [ $w - 2, $h - 2 ],
			[ (int) ( $w / 2 ), 1 ], [ (int) ( $w / 2 ), $h - 2 ],
			[ 1, (int) ( $h / 2 ) ], [ $w - 2, (int) ( $h / 2 ) ],
		];
		$rs = [];
		$gs = [];
		$bs = [];
		foreach ( $points as [ $x, $y ] ) {
			$c    = imagecolorat( $img, max( 0, $x ), max( 0, $y ) );
			$rs[] = ( $c >> 16 ) & 255;
			$gs[] = ( $c >> 8 ) & 255;
			$bs[] = $c & 255;
		}
		sort( $rs );
		sort( $gs );
		sort( $bs );
		$mid = (int) floor( count( $rs ) / 2 );
		return [ (int) $rs[ $mid ], (int) $gs[ $mid ], (int) $bs[ $mid ] ];
	}

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
		// A retired photograph may also be the image of a colour: a variation
		// still pointing at it would keep it on the shop, and the count below
		// would find it "in use" and refuse to delete the file.
		self::replace_variation_image( $pid, $att_id, (int) get_post_thumbnail_id( $pid ) );
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
		// A variation image says WHICH group of variations it is for, so the
		// destination carries the attribute and the value: one image written to
		// the five sizes of one colour.
		if ( 0 === strpos( $t, 'variation:' ) ) {
			[ $attr, $value ] = array_pad( explode( '::', substr( $t, 10 ), 2 ), 2, '' );
			$attr  = sanitize_key( $attr );
			$value = sanitize_title( $value );
			return ( '' !== $attr && '' !== $value ) ? 'variation:' . $attr . '::' . $value : 'gallery';
		}
		return in_array( $t, [ 'main', 'gallery_first' ], true ) ? $t : 'gallery';
	}

	/**
	 * The file name and the title a generated image is stored under.
	 *
	 * A shop is read by its URLs too: a UGC shot and a catalogue shot have no
	 * business landing on the same file name. Each image prompt says how its
	 * results are named, with two tokens — {product} and {prompt} — and an
	 * empty pattern keeps what the shop has always done: the product name.
	 *
	 * @return array{0:string,1:string} slug, title
	 */
	public static function image_naming( int $pid, string $recipe_id = '', string $variation = '' ): array {
		// get_the_title() comes back with WordPress's typography applied:
		// curly quotes and dashes as HTML entities. Fine inside a page, wrong
		// in an attachment title and in an alt text, where they are read as
		// literal &#8220; by everything that is not a browser.
		$title   = html_entity_decode( wp_strip_all_tags( (string) get_the_title( $pid ) ), ENT_QUOTES, 'UTF-8' );
		$row     = '' !== $recipe_id ? self::registry_row( $recipe_id ) : null;
		$recipe  = $row ? (string) ( $row['name'] ?? '' ) : '';
		$fpat    = $row ? trim( (string) ( $row['file_name'] ?? '' ) ) : '';
		$tpat    = $row ? trim( (string) ( $row['img_title'] ?? '' ) ) : '';
		// {variation} is the name of the colour this image is for, so a pattern
		// can put it where it belongs instead of always at the end.
		$fill    = static function ( string $pattern ) use ( $title, $recipe, $variation ): string {
			return str_replace( [ '{product}', '{prompt}', '{variation}' ], [ $title, $recipe, $variation ], $pattern );
		};
		$slug  = sanitize_title( '' !== $fpat ? $fill( $fpat ) : $title ) ?: 'product-image';
		$shown = '' !== $tpat ? trim( $fill( $tpat ) ) : $title;
		return [ $slug, $shown ?: $title ];
	}

	/**
	 * @param bool $keep_old What becomes of the main image being replaced:
	 *                       true moves it to the front of the gallery, false
	 *                       takes it off the product. The file itself is never
	 *                       deleted here — leaving a product and leaving the
	 *                       library are two decisions.
	 */
	public function sideload_seo( string $url, int $pid, string $target, string $recipe_id = '', bool $keep_old = true ): int {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		$tmp = download_url( $url, 120 );
		if ( is_wp_error( $tmp ) ) {
			throw new RuntimeException( $tmp->get_error_message() );
		}
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		return $this->attach_file( (string) $tmp, strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ), $pid, $target, $recipe_id, $keep_old );
	}

	/**
	 * A file on disk becomes an image of this library.
	 *
	 * The JPEG conversion, the file name, the attachment title, the slug and
	 * the alt text — the whole road from a temporary file to a library entry —
	 * live here, wherever the file came from and whatever it is going to be
	 * used for. What it is then DONE with (a main image, a group of variations,
	 * a backdrop, nothing at all) is the caller's business.
	 *
	 * @param string $tmp Temporary file; consumed either way.
	 */
	public function file_to_library( string $tmp, string $ext, string $slug, string $title, int $parent = 0 ): int {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

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
		$slug   = sanitize_title( $slug ) ?: 'image';
		$title  = '' !== trim( $title ) ? $title : $slug;
		$att_id = media_handle_sideload( [ 'name' => $slug . '.' . $ext, 'tmp_name' => $tmp ], $parent, $title );
		if ( is_wp_error( $att_id ) ) {
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
			throw new RuntimeException( $att_id->get_error_message() );
		}
		// Title, slug and alt text say the same thing (WP uniquifies the slug).
		wp_update_post( [ 'ID' => (int) $att_id, 'post_title' => $title, 'post_name' => $slug ] );
		update_post_meta( (int) $att_id, '_wp_attachment_image_alt', $title );
		return (int) $att_id;
	}

	/**
	 * A file on disk becomes a photograph of this product.
	 *
	 * The naming, the alt text, the JPEG conversion and every destination —
	 * main image, gallery, one group of variations — live HERE and nowhere
	 * else. A generated image arrives by download, one pasted from the desktop
	 * arrives in the request; past this point they are the same photograph and
	 * follow the same road, so neither can quietly stop being named the way
	 * the shop names its files.
	 *
	 * @param string $tmp Temporary file; consumed (moved or deleted) either way.
	 */
	public function attach_file( string $tmp, string $ext, int $pid, string $target, string $recipe_id = '', bool $keep_old = true ): int {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		// A shop is read by its URLs: three colours of the same product cannot
		// all be product-name-1.jpg. The colour goes where the pattern puts it,
		// and at the end when the pattern never mentions it.
		$n_label = '';
		if ( 0 === strpos( $target, 'variation:' ) ) {
			[ $n_attr, $n_value ] = array_pad( explode( '::', substr( $target, 10 ), 2 ), 2, '' );
			$n_label = self::attribute_value_label( $n_attr, $n_value );
		}
		[ $slug, $title ] = self::image_naming( $pid, $recipe_id, $n_label );
		if ( '' !== $n_label && false === stripos( $slug, sanitize_title( $n_label ) ) ) {
			$slug  = $slug . '-' . sanitize_title( $n_label );
			$title = $title . ' — ' . $n_label;
		}

		$att_id = $this->file_to_library( $tmp, $ext, $slug, $title, $pid );
		// Which prompt this photograph came out of, kept on the photograph
		// itself: it is the only thing that still knows, once the image has
		// left the waiting list, that this prompt has already been served here
		// — and asking it again for "one more" has to start from that.
		if ( '' !== $recipe_id ) {
			update_post_meta( (int) $att_id, self::META_RECIPE, $recipe_id );
		}

		// A variation image belongs to its variations and to nothing else: it
		// is written to every variation of the group and stays out of the
		// parent's gallery, which is where fifteen near-identical photographs
		// would otherwise pile up.
		if ( 0 === strpos( $target, 'variation:' ) ) {
			[ $v_attr, $v_value ] = array_pad( explode( '::', substr( $target, 10 ), 2 ), 2, '' );
			foreach ( self::variation_ids( $pid, $v_attr, $v_value ) as $vid ) {
				set_post_thumbnail( $vid, (int) $att_id );
			}
			clean_post_cache( $pid );
			if ( function_exists( 'wc_delete_product_transients' ) ) {
				wc_delete_product_transients( $pid );
			}
			return (int) $att_id;
		}

		$gallery = (string) get_post_meta( $pid, '_product_image_gallery', true );
		$ids     = array_filter( array_map( 'absint', explode( ',', $gallery ) ) );
		if ( 'main' === $target ) {
			// The replaced main image moves to the FRONT of the product gallery
			// by default, so it stays first among the secondary images — unless
			// the screen said to take it off the product. And an
			// image cannot be the main one AND a gallery one — the shop would show
			// it twice — so the newcomer leaves the gallery as it takes the top
			// spot. The row is written even when there was no main image before,
			// otherwise that de-duplication would never reach the database.
			$old = (int) get_post_thumbnail_id( $pid );
			$ids = array_values( array_diff( $ids, [ (int) $att_id ] ) );
			if ( $old && $old !== (int) $att_id ) {
				if ( $keep_old ) {
					array_unshift( $ids, $old );
				} else {
					// Asked to go: off the product, but still in the library —
					// a photograph leaving a page is not a photograph deleted.
					$ids = array_values( array_diff( $ids, [ $old ] ) );
					// And off the variations that were using it. A main image
					// is often the photograph one colour was given, so taking
					// it off the product while a variation still points at it
					// leaves the shop showing exactly the image that was
					// supposed to be gone: those variations take the new one.
					self::replace_variation_image( $pid, $old, (int) $att_id );
				}
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
	/**
	 * What YOU know about a colour that no photograph says.
	 *
	 * "Fabric: black, multicam tropic ripstop" is the difference between a
	 * remake in the right material and one in olive drab. The prompt is shared
	 * by every colour of every product; this is the line that belongs to ONE
	 * group, kept with the product so it is still there the next time that
	 * colour needs an image.
	 */
	private const META_VAR_NOTES = '_dze_variation_notes';

	public static function variation_notes( int $pid ): array {
		$n = get_post_meta( $pid, self::META_VAR_NOTES, true );
		return is_array( $n ) ? $n : [];
	}

	/**
	 * The note attached to a scope of this product.
	 *
	 * '*' is the product itself — what every image of it must know: the real
	 * fabric, the finish, the detail no photograph shows. A group key is one
	 * colour. Same store, same idea: a line YOU wrote, sent with the run,
	 * because a prompt shared by a whole catalogue cannot know it.
	 */
	public const NOTE_PRODUCT = '*';

	public static function variation_note( int $pid, string $group ): string {
		return (string) ( self::variation_notes( $pid )[ $group ] ?? '' );
	}

	/** The product-wide note, plus the group's own when there is one. */
	public static function note_lines( int $pid, string $group = '' ): string {
		$out = '';
		$all = self::variation_note( $pid, self::NOTE_PRODUCT );
		if ( '' !== trim( $all ) ) {
			$out .= "\nAbout this product: " . trim( $all );
		}
		if ( '' !== $group ) {
			$one = self::variation_note( $pid, $group );
			if ( '' !== trim( $one ) ) {
				$out .= "\nAbout this variation: " . trim( $one );
			}
		}
		return $out;
	}

	public static function set_variation_note( int $pid, string $group, string $note ): void {
		$all = self::variation_notes( $pid );
		if ( '' === trim( $note ) ) {
			unset( $all[ $group ] );
		} else {
			$all[ $group ] = $note;
		}
		if ( $all ) {
			update_post_meta( $pid, self::META_VAR_NOTES, $all );
		} else {
			delete_post_meta( $pid, self::META_VAR_NOTES );
		}
	}

	/** The button planted in WooCommerce's own Variations panel. */
	public function variations_button(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		// How much of this product is still showing the parent's photograph,
		// answered where the variations are — not after opening anything.
		$pid     = (int) get_the_ID();
		$product = $pid && function_exists( 'wc_get_product' ) ? wc_get_product( $pid ) : null;
		$all     = 0;
		$with    = 0;
		if ( $product && $product->is_type( 'variable' ) ) {
			$children = (array) $product->get_children();
			if ( $children ) {
				_prime_post_caches( $children, false, true );
			}
			foreach ( $children as $vid ) {
				$all++;
				if ( get_post_thumbnail_id( (int) $vid ) ) {
					$with++;
				}
			}
		}
		printf(
			'<div class="dze-varbar"><button type="button" class="button dze-var-open">%1$s</button>'
				. '<span class="dze-varcount%2$s">%3$s</span>'
				. '<span class="description">%4$s</span></div>',
			esc_html__( '✦ Variation images', 'dazont-ecom' ),
			esc_attr( ( $all && $with < $all ) ? ' is-short' : '' ),
			esc_html( self::variation_count_text( $with, $all ) ),
			esc_html__( 'Pick one from the library, paste one, or generate one.', 'dazont-ecom' )
		);
	}

	/** "3/18 variations with an image", said the same way wherever it is said. */
	public static function variation_count_text( int $with, int $all ): string {
		if ( ! $all ) {
			return '';
		}
		return sprintf(
			/* translators: 1: variations carrying their own image, 2: variations in total */
			_n( '%1$s/%2$s variation with an image', '%1$s/%2$s variations with an image', $all, 'dazont-ecom' ),
			number_format_i18n( $with ),
			number_format_i18n( $all )
		);
	}

	/**
	 * The variations of a product, grouped by what changes how it LOOKS.
	 *
	 * A product sold in three colours and five sizes is fifteen variations and
	 * three photographs: size changes nothing you can see, colour changes
	 * everything. So the groups are built on ONE attribute — the colour one
	 * when there is one, otherwise the first attribute that is not a size — and
	 * an image made for a group is written to every variation in it.
	 *
	 * Read on demand for one product, never for a list: it walks the
	 * variations.
	 *
	 * @return array{attr:string,label:string,groups:array<int,array<string,mixed>>}
	 */
	public static function variation_groups( int $pid, string $attr = '' ): array {
		$empty   = [ 'attr' => '', 'label' => '', 'groups' => [] ];
		$product = function_exists( 'wc_get_product' ) ? wc_get_product( $pid ) : null;
		if ( ! $product || ! $product->is_type( 'variable' ) ) {
			return $empty;
		}
		$attrs = (array) $product->get_variation_attributes();
		if ( ! $attrs ) {
			return $empty;
		}
		$names = array_keys( $attrs );
		if ( '' === $attr || ! isset( $attrs[ $attr ] ) ) {
			$attr = self::looks_attribute( $names );
		}
		$children = (array) $product->get_children();
		if ( ! $children ) {
			return $empty;
		}
		_prime_post_caches( $children, false, true );
		$groups = [];
		foreach ( $children as $vid ) {
			$variation = wc_get_product( (int) $vid );
			if ( ! $variation ) {
				continue;
			}
			$value = (string) ( $variation->get_attributes()[ sanitize_title( $attr ) ] ?? '' );
			if ( '' === $value ) {
				// "Any colour": it belongs to no group in particular, and an
				// image written to it would be written to all of them.
				continue;
			}
			$key = $attr . '::' . $value;
			if ( ! isset( $groups[ $key ] ) ) {
				$groups[ $key ] = [
					'key'   => $key,
					'attr'  => $attr,
					'value' => $value,
					'label' => self::attribute_value_label( $attr, $value ),
					'ids'   => [],
					'note'  => self::variation_note( $pid, $key ),
					'with'  => 0,
					'image' => 0,
					'thumb' => '',
					// The original file, for the zoom: a viewer that opens a
					// 150px copy shows nothing you could not already see.
					'full'  => '',
				];
			}
			$groups[ $key ]['ids'][] = (int) $vid;
			// The variation's OWN photograph, not the one WooCommerce lends it
			// from the parent: a variation showing the parent's image is
			// exactly the gap this fills.
			$img = (int) get_post_thumbnail_id( (int) $vid );
			if ( $img ) {
				$groups[ $key ]['with']++;
				if ( ! $groups[ $key ]['image'] ) {
					$groups[ $key ]['image'] = $img;
					$groups[ $key ]['thumb'] = (string) ( wp_get_attachment_image_url( $img, 'thumbnail' ) ?: '' );
					$groups[ $key ]['full']  = (string) ( wp_get_attachment_image_url( $img, 'full' ) ?: '' );
				}
			}
		}
		foreach ( $groups as $k => $g ) {
			$groups[ $k ]['total'] = count( $g['ids'] );
		}
		// Over EVERY variation, not only the ones the groups cover: a variation
		// left on "any colour" belongs to no group and still counts as a
		// variation somebody has to look at.
		$all  = 0;
		$with = 0;
		foreach ( $children as $vid ) {
			$all++;
			if ( get_post_thumbnail_id( (int) $vid ) ) {
				$with++;
			}
		}
		return [
			'attr'    => $attr,
			'label'   => (string) wc_attribute_label( $attr, $product ),
			'groups'  => array_values( $groups ),
			'all'     => $all,
			'allWith' => $with,
		];
	}

	/** The attribute that decides what the product looks like. */
	private static function looks_attribute( array $names ): string {
		foreach ( $names as $n ) {
			if ( preg_match( '/colou?r|couleur|farbe|colore/i', (string) $n ) ) {
				return (string) $n;
			}
		}
		foreach ( $names as $n ) {
			if ( ! preg_match( '/size|taille|pointure|length|longueur/i', (string) $n ) ) {
				return (string) $n;
			}
		}
		return (string) reset( $names );
	}

	/** "olive" → "Olive", through the taxonomy when there is one. */
	public static function attribute_value_label( string $attr, string $value ): string {
		if ( taxonomy_exists( $attr ) ) {
			$term = get_term_by( 'slug', $value, $attr );
			if ( $term && ! is_wp_error( $term ) ) {
				return (string) $term->name;
			}
		}
		return ucfirst( str_replace( '-', ' ', $value ) );
	}

	/**
	 * Every variation showing one photograph now shows another.
	 *
	 * Used when a photograph leaves the product: it may have been the image of
	 * a colour as well as the main image, and a variation still pointing at it
	 * would keep it on the shop.
	 *
	 * @return int how many variations were changed.
	 */
	public static function replace_variation_image( int $pid, int $old_id, int $new_id ): int {
		if ( ! $old_id || $old_id === $new_id ) {
			return 0;
		}
		$product = function_exists( 'wc_get_product' ) ? wc_get_product( $pid ) : null;
		if ( ! $product || ! $product->is_type( 'variable' ) ) {
			return 0;
		}
		$done = 0;
		foreach ( (array) $product->get_children() as $vid ) {
			if ( (int) get_post_thumbnail_id( (int) $vid ) !== $old_id ) {
				continue;
			}
			if ( $new_id ) {
				set_post_thumbnail( (int) $vid, $new_id );
			} else {
				delete_post_thumbnail( (int) $vid );
			}
			$done++;
		}
		return $done;
	}

	/**
	 * The full name of what an image is being made for.
	 *
	 * A group is one value of one attribute, but the variations in it often
	 * share more than that — the fabric, the cut, the finish. Everything the
	 * whole group has in common goes into the name, so the request says
	 * "Combat shirt — Color: Multicam Tropic, Fabric: Ripstop" and not just the
	 * colour. What differs inside the group (the sizes) is left out: one image
	 * serves all of them, and naming one size would be a lie.
	 */
	public static function variation_group_name( int $pid, string $attr, string $value ): string {
		$product = function_exists( 'wc_get_product' ) ? wc_get_product( $pid ) : null;
		if ( ! $product ) {
			return '';
		}
		$ids = self::variation_ids( $pid, $attr, $value );
		if ( ! $ids ) {
			return '';
		}
		$common = null;
		foreach ( $ids as $vid ) {
			$v = wc_get_product( $vid );
			if ( ! $v ) {
				continue;
			}
			$attrs = array_filter( (array) $v->get_attributes() );
			if ( null === $common ) {
				$common = $attrs;
				continue;
			}
			foreach ( $common as $k => $val ) {
				if ( ! isset( $attrs[ $k ] ) || $attrs[ $k ] !== $val ) {
					unset( $common[ $k ] );
				}
			}
		}
		$bits = [];
		foreach ( (array) $common as $k => $val ) {
			$bits[] = wc_attribute_label( $k, $product ) . ': ' . self::attribute_value_label( $k, (string) $val );
		}
		return $bits ? $product->get_name() . ' — ' . implode( ', ', $bits ) : '';
	}

	/** The variations of one group, for writing an image to all of them. */
	public static function variation_ids( int $pid, string $attr, string $value ): array {
		foreach ( self::variation_groups( $pid, $attr )['groups'] as $g ) {
			if ( (string) $g['value'] === $value ) {
				return array_map( 'intval', (array) $g['ids'] );
			}
		}
		return [];
	}

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
		// The subject is asked for at full size — a model cannot keep a detail
		// it was never shown, and WordPress's "large" is 1024 px on most
		// installs. A file too heavy for one request falls back to that size
		// rather than failing the generation.
		if ( 'full' === $wanted && strlen( $bytes ) > self::MAX_REMOTE ) {
			return self::instance()->fal_source_data_uri( $attachment_id, 'large' );
		}
		$mime = (string) ( get_post_mime_type( $attachment_id ) ?: 'image/jpeg' );
		return 'data:' . $mime . ';base64,' . base64_encode( $bytes ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- data URI.
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
