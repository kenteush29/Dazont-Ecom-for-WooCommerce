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
 * (Title, Description incl. technical bullets, Short description, Attributes,
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
		add_action( 'add_meta_boxes', [ $this, 'add_meta_box' ] );
		add_action( 'admin_menu',     [ $this, 'register_bulk_page' ] );
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
				'label'   => __( 'Title', 'dazont-ecom' ),
				'dest'    => 'post_title',
				'tokens'  => 80,
				'enabled' => false, // titles are crafted manually for now.
				'prompt'  => "Write an SEO-optimised product title (max ~70 characters). Natural, human, no ALL CAPS, no supplier gibberish. Output only the title.",
			],
			'description' => [
				'label'   => __( 'Description (+ technical bullets)', 'dazont-ecom' ),
				'dest'    => 'post_content',
				'tokens'  => 900,
				'enabled' => true,
				'prompt'  => $p_description,
			],
			'short' => [
				'label'   => __( 'Short description', 'dazont-ecom' ),
				'dest'    => 'post_excerpt',
				'tokens'  => 200,
				'enabled' => true,
				'prompt'  => $p_short,
			],
			'attributes' => [
				'label'   => __( 'Attributes', 'dazont-ecom' ),
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
			[ 'name' => 'Additional angle', 'target' => 'gallery', 'prompt' => "Create an additional clean product shot from a different angle, neutral background, e-commerce quality. No text. Keep the product identical." ],
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
	// freely added/removed in AI Settings → Product content. Legacy per-field
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
			self::$registry_cache = $s['registry'];
		} else {
			self::$registry_cache = self::registry_from_legacy();
		}
		return self::$registry_cache;
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

	public static function store_context(): string {
		return (string) ( self::get_settings()['store_context'] ?? '' );
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

	public static function price_table(): array {
		$t = self::get_settings()['price_table'] ?? null;
		return ( is_array( $t ) && ! empty( $t ) ) ? $t : self::default_price_table();
	}

	/** ENABLED image prompts from the registry, in the shape the image flow uses. */
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
	private static function seo_keys(): array {
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
		// The General-tab mini form only posts fal_key: don't touch the rest then.
		if ( isset( $in['fal_key'] ) && count( $in ) <= 2 ) {
			$busy = false;
			return $out;
		}

		if ( isset( $in['store_context'] ) ) {
			$out['store_context'] = sanitize_textarea_field( (string) $in['store_context'] );
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
					'enabled'     => ! empty( $in['pr_on'][ $i ] ) ? 1 : 0,
					'valid'       => ! empty( $in['pr_valid'][ $i ] ) ? 1 : 0,
					'tokens'      => max( 50, (int) ( $in['pr_tokens'][ $i ] ?? 400 ) ),
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
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="dze-fal-key"><?php esc_html_e( 'fal.ai API key (images)', 'dazont-ecom' ); ?></label></th>
				<td>
					<?php echo DZE_Api_Keys::status_html( 'fal', self::fal_key(), $fal_locked ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built escaped. ?>
					<?php if ( ! $fal_locked ) : ?>
						<form method="post" action="options.php" style="display:inline;">
							<?php settings_fields( 'dze_content_options' ); ?>
							<input type="password" id="dze-fal-key" class="regular-text" name="<?php echo esc_attr( self::OPT_SETTINGS ); ?>[fal_key]" value="" autocomplete="new-password" placeholder="<?php echo $has_fal ? esc_attr__( 'Leave blank to keep the saved key', 'dazont-ecom' ) : esc_attr__( 'Paste your fal.ai key', 'dazont-ecom' ); ?>" />
							<?php submit_button( __( 'Save fal.ai key', 'dazont-ecom' ), 'secondary', 'submit', false ); ?>
							<p class="description"><?php esc_html_e( 'Used for image generation (fal.ai nano-banana-2/edit). For production, define DZE_FAL_API_KEY in wp-config.php.', 'dazont-ecom' ); ?></p>
						</form>
					<?php endif; ?>
				</td>
			</tr>
		</table>
		</div>
		<?php
	}

	/** Full "Product content" tab: prompts + field mapping, images, prices, validation. */
	public function render_settings_section(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$s     = self::get_settings();
		$opt   = self::OPT_SETTINGS;
		$seo   = self::seo_keys();
		$dests = self::dest_options();
		?>
		<div class="dze-admin">
		<p class="description" style="max-width:900px;">
			<?php esc_html_e( 'Generate every product field from the imported data, generate images from templates, and recalculate the price from cost. Text uses the Anthropic key (General tab); images use the fal.ai key (General tab). Tune the prompts and the field mapping, test on real products from the toolbox (preview mode), then tick "Prompts validated" to unlock applying.', 'dazont-ecom' ); ?>
		</p>
		<form method="post" action="options.php">
			<?php settings_fields( 'dze_content_options' ); ?>

			<h2 class="title"><?php esc_html_e( 'Store context', 'dazont-ecom' ); ?></h2>
			<textarea name="<?php echo esc_attr( $opt ); ?>[store_context]" rows="2" class="large-text"><?php echo esc_textarea( (string) ( $s['store_context'] ?? '' ) ); ?></textarea>
			<p class="description"><?php esc_html_e( 'Prepended to every generation, e.g. "Kula Tactical > Military / tactical clothing and gear > Tone: sharp, authoritative, informational".', 'dazont-ecom' ); ?></p>

			<h2 class="title"><?php esc_html_e( 'Prompt registry', 'dazont-ecom' ); ?></h2>
			<p class="description" style="max-width:960px;">
				<?php esc_html_e( 'ONE universal list of prompts — add as many as you want, for anything. Each prompt has a content type (Text or Image), the product metadata it receives as INPUT, and an OUTPUT destination (product fields, SEO metas, WooCommerce attributes, any custom field — or the product gallery / main image for Image prompts, fully compatible with the product image generator). Text prompts appear in the toolbox and bulk once enabled; apply is unlocked per prompt by its Validated box.', 'dazont-ecom' ); ?>
			</p>
			<?php $dze_inputs = self::input_options(); $dze_metakeys = self::product_meta_keys(); $dze_ri = 0; ?>
			<datalist id="dze-metakeys">
				<?php foreach ( $dze_metakeys as $mk ) : ?><option value="<?php echo esc_attr( $mk ); ?>"></option><?php endforeach; ?>
			</datalist>
			<table class="widefat dze-pr-table" id="dze-pr">
				<thead>
					<tr>
						<th style="width:36px;"><?php esc_html_e( 'On', 'dazont-ecom' ); ?></th>
						<th style="width:150px;"><?php esc_html_e( 'Name', 'dazont-ecom' ); ?></th>
						<th style="width:90px;"><?php esc_html_e( 'Type', 'dazont-ecom' ); ?></th>
						<th style="width:190px;"><?php esc_html_e( 'Output', 'dazont-ecom' ); ?></th>
						<th style="width:170px;"><?php esc_html_e( 'Inputs', 'dazont-ecom' ); ?></th>
						<th><?php esc_html_e( 'Prompt', 'dazont-ecom' ); ?></th>
						<th style="width:70px;"><?php esc_html_e( 'Max tk', 'dazont-ecom' ); ?></th>
						<th style="width:70px;"><?php esc_html_e( 'Valid', 'dazont-ecom' ); ?></th>
						<th style="width:40px;"></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( self::registry() as $r ) : $sel_in = (array) ( $r['inputs'] ?? [] ); ?>
					<tr class="dze-pr-row">
						<td><input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[pr_on][<?php echo (int) $dze_ri; ?>]" value="1" <?php checked( ! empty( $r['enabled'] ) ); ?> /></td>
						<td>
							<input type="text" name="<?php echo esc_attr( $opt ); ?>[pr_name][<?php echo (int) $dze_ri; ?>]" value="<?php echo esc_attr( $r['name'] ); ?>" />
							<input type="hidden" name="<?php echo esc_attr( $opt ); ?>[pr_id][<?php echo (int) $dze_ri; ?>]" value="<?php echo esc_attr( $r['id'] ); ?>" />
							<code class="dze-pr-slug"><?php echo esc_html( $r['id'] ); ?></code>
						</td>
						<td>
							<select name="<?php echo esc_attr( $opt ); ?>[pr_type][<?php echo (int) $dze_ri; ?>]" class="dze-pr-type">
								<option value="text" <?php selected( 'text', $r['type'] ?? 'text' ); ?>><?php esc_html_e( 'Text', 'dazont-ecom' ); ?></option>
								<option value="image" <?php selected( 'image', $r['type'] ?? 'text' ); ?>><?php esc_html_e( 'Image', 'dazont-ecom' ); ?></option>
							</select>
						</td>
						<td>
							<select name="<?php echo esc_attr( $opt ); ?>[pr_output][<?php echo (int) $dze_ri; ?>]" class="dze-pr-output">
								<?php foreach ( self::output_options( 'text' ) as $ok => $ol ) : ?>
									<option class="dze-o-text" value="<?php echo esc_attr( $ok ); ?>" <?php selected( $ok, $r['output'] ?? '' ); ?>><?php echo esc_html( $ol ); ?></option>
								<?php endforeach; ?>
								<?php foreach ( self::output_options( 'image' ) as $ok => $ol ) : ?>
									<option class="dze-o-image" value="<?php echo esc_attr( $ok ); ?>" <?php selected( $ok, $r['output'] ?? '' ); ?>><?php echo esc_html( $ol ); ?></option>
								<?php endforeach; ?>
							</select>
							<input type="text" name="<?php echo esc_attr( $opt ); ?>[pr_metakey][<?php echo (int) $dze_ri; ?>]" value="<?php echo esc_attr( $r['meta_key'] ?? '' ); ?>" placeholder="_meta_key" list="dze-metakeys" class="dze-pr-metakey" style="<?php echo ( 'meta' === ( $r['output'] ?? '' ) ) ? '' : 'display:none;'; ?>" />
						</td>
						<td>
							<details class="dze-pr-inputs">
								<summary><?php printf( /* translators: %d: count */ esc_html__( 'Inputs (%d)', 'dazont-ecom' ), count( $sel_in ) ); ?></summary>
								<?php foreach ( $dze_inputs as $ik => $il ) : ?>
									<label><input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[pr_inputs][<?php echo (int) $dze_ri; ?>][]" value="<?php echo esc_attr( $ik ); ?>" <?php checked( in_array( $ik, $sel_in, true ) ); ?> /> <?php echo esc_html( $il ); ?></label>
								<?php endforeach; ?>
								<input type="text" name="<?php echo esc_attr( $opt ); ?>[pr_inmeta][<?php echo (int) $dze_ri; ?>]" value="<?php echo esc_attr( $r['inputs_meta'] ?? '' ); ?>" placeholder="<?php esc_attr_e( 'custom meta keys, comma separated', 'dazont-ecom' ); ?>" class="dze-pr-inmeta" />
								<span class="dze-pr-metapickrow">
									<select class="dze-pr-metapick"><option value=""><?php esc_html_e( '— browse meta keys —', 'dazont-ecom' ); ?></option><?php foreach ( $dze_metakeys as $mk ) : ?><option value="<?php echo esc_attr( $mk ); ?>"><?php echo esc_html( $mk ); ?></option><?php endforeach; ?></select>
									<button type="button" class="button button-small dze-pr-metaadd" title="<?php esc_attr_e( 'Add this key as an input', 'dazont-ecom' ); ?>">&#43;</button>
								</span>
							</details>
						</td>
						<td><textarea name="<?php echo esc_attr( $opt ); ?>[pr_prompt][<?php echo (int) $dze_ri; ?>]" rows="4" class="large-text code"><?php echo esc_textarea( $r['prompt'] ); ?></textarea></td>
						<td><input type="number" name="<?php echo esc_attr( $opt ); ?>[pr_tokens][<?php echo (int) $dze_ri; ?>]" value="<?php echo esc_attr( (int) ( $r['tokens'] ?: 400 ) ); ?>" min="50" class="dze-pr-tokens" /></td>
						<td><input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[pr_valid][<?php echo (int) $dze_ri; ?>]" value="1" <?php checked( ! empty( $r['valid'] ) ); ?> /></td>
						<td><button type="button" class="button dze-pr-del" title="<?php esc_attr_e( 'Remove this prompt', 'dazont-ecom' ); ?>">&#10005;</button></td>
					</tr>
					<?php $dze_ri++; endforeach; ?>
				</tbody>
			</table>
			<p><button type="button" class="button dze-pt-add" id="dze-pr-add" data-next="<?php echo (int) $dze_ri; ?>">&#43; <?php esc_html_e( 'Add prompt', 'dazont-ecom' ); ?></button></p>
			<script type="text/template" id="dze-pr-rowtpl">
				<tr class="dze-pr-row">
					<td><input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[pr_on][__I__]" value="1" checked /></td>
					<td><input type="text" name="<?php echo esc_attr( $opt ); ?>[pr_name][__I__]" value="" placeholder="<?php esc_attr_e( 'New prompt…', 'dazont-ecom' ); ?>" /><input type="hidden" name="<?php echo esc_attr( $opt ); ?>[pr_id][__I__]" value="" /></td>
					<td>
						<select name="<?php echo esc_attr( $opt ); ?>[pr_type][__I__]" class="dze-pr-type">
							<option value="text"><?php esc_html_e( 'Text', 'dazont-ecom' ); ?></option>
							<option value="image"><?php esc_html_e( 'Image', 'dazont-ecom' ); ?></option>
						</select>
					</td>
					<td>
						<select name="<?php echo esc_attr( $opt ); ?>[pr_output][__I__]" class="dze-pr-output">
							<?php foreach ( self::output_options( 'text' ) as $ok => $ol ) : ?>
								<option class="dze-o-text" value="<?php echo esc_attr( $ok ); ?>"><?php echo esc_html( $ol ); ?></option>
							<?php endforeach; ?>
							<?php foreach ( self::output_options( 'image' ) as $ok => $ol ) : ?>
								<option class="dze-o-image" value="<?php echo esc_attr( $ok ); ?>"><?php echo esc_html( $ol ); ?></option>
							<?php endforeach; ?>
						</select>
						<input type="text" name="<?php echo esc_attr( $opt ); ?>[pr_metakey][__I__]" value="" placeholder="_meta_key" list="dze-metakeys" class="dze-pr-metakey" style="display:none;" />
					</td>
					<td>
						<details class="dze-pr-inputs">
							<summary><?php esc_html_e( 'Inputs', 'dazont-ecom' ); ?></summary>
							<?php foreach ( $dze_inputs as $ik => $il ) : ?>
								<label><input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[pr_inputs][__I__][]" value="<?php echo esc_attr( $ik ); ?>" <?php checked( in_array( $ik, [ 'title', 'description' ], true ) ); ?> /> <?php echo esc_html( $il ); ?></label>
							<?php endforeach; ?>
							<input type="text" name="<?php echo esc_attr( $opt ); ?>[pr_inmeta][__I__]" value="" placeholder="<?php esc_attr_e( 'custom meta keys, comma separated', 'dazont-ecom' ); ?>" class="dze-pr-inmeta" />
						<span class="dze-pr-metapickrow">
							<select class="dze-pr-metapick"><option value=""><?php esc_html_e( '— browse meta keys —', 'dazont-ecom' ); ?></option><?php foreach ( $dze_metakeys as $mk ) : ?><option value="<?php echo esc_attr( $mk ); ?>"><?php echo esc_html( $mk ); ?></option><?php endforeach; ?></select>
							<button type="button" class="button button-small dze-pr-metaadd">&#43;</button>
						</span>
						</details>
					</td>
					<td><textarea name="<?php echo esc_attr( $opt ); ?>[pr_prompt][__I__]" rows="4" class="large-text code"></textarea></td>
					<td><input type="number" name="<?php echo esc_attr( $opt ); ?>[pr_tokens][__I__]" value="400" min="50" class="dze-pr-tokens" /></td>
					<td><input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[pr_valid][__I__]" value="1" /></td>
					<td><button type="button" class="button dze-pr-del">&#10005;</button></td>
				</tr>
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
				$( '#dze-pr tbody tr' ).each( function () { syncRow( $( this ) ); } );
				$( document ).on( 'change', '.dze-pr-type, .dze-pr-output', function () { syncRow( $( this ).closest( 'tr' ) ); } );
				$( '#dze-pr-add' ).on( 'click', function () {
					var $b = $( this ), i = parseInt( $b.data( 'next' ), 10 ) || 0;
					$b.data( 'next', i + 1 );
					var html = $( '#dze-pr-rowtpl' ).html().replace( /__I__/g, String( i ) );
					var $row = $( html );
					$( '#dze-pr tbody' ).append( $row );
					syncRow( $row );
				} );
				$( document ).on( 'click', '.dze-pr-del', function () { $( this ).closest( 'tr' ).remove(); } );
				$( document ).on( 'click', '.dze-pr-metaadd', function () {
					var $wrap = $( this ).closest( 'details' );
					var key = $wrap.find( '.dze-pr-metapick' ).val();
					if ( ! key ) { return; }
					var $in = $wrap.find( '.dze-pr-inmeta' );
					var cur = ( $in.val() || '' ).split( ',' ).map( function ( x ) { return x.trim(); } ).filter( Boolean );
					if ( cur.indexOf( key ) < 0 ) { cur.push( key ); }
					$in.val( cur.join( ', ' ) );
				} );
			} );
			</script>

			<h2 class="title"><?php esc_html_e( 'Price table (cost × multiplier → regular price)', 'dazont-ecom' ); ?></h2>
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

			<?php submit_button(); ?>
		</form>
		</div>
		<?php
	}

	// =========================================================================
	// Product-page side box
	// =========================================================================

	public function add_meta_box(): void {
		add_meta_box( 'dze-content-side', __( 'AI Content (Dazont)', 'dazont-ecom' ), [ $this, 'render_side_box' ], 'product', 'side', 'high' );
	}

	public function render_side_box( $post ): void {
		?>
		<div class="dze-content-side dze-admin">
			<p class="description"><?php esc_html_e( 'Open the AI toolbox to generate the product content and images.', 'dazont-ecom' ); ?></p>
			<button type="button" class="button button-primary" id="dze-cx-open-text" data-tab="text"><?php esc_html_e( 'Generate text', 'dazont-ecom' ); ?></button>
			<button type="button" class="button" id="dze-cx-open-image" data-tab="image"><?php esc_html_e( 'Generate image', 'dazont-ecom' ); ?></button>
			<?php [ $dze_ok, $dze_tot ] = self::validated_counts(); ?>
			<?php if ( $dze_ok < $dze_tot ) : ?>
				<p class="dze-cx-note"><?php printf( /* translators: 1: validated count, 2: total */ esc_html__( '%1$d/%2$d prompts validated — unvalidated fields stay in preview.', 'dazont-ecom' ), (int) $dze_ok, (int) $dze_tot ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	// =========================================================================
	// Bulk: products-list action + bulk screen
	// =========================================================================

	public function register_bulk_action( array $actions ): array {
		$actions[ self::BULK_ACTION ] = __( 'Generate AI content (Dazont)', 'dazont-ecom' );
		return $actions;
	}

	public function handle_bulk_action( string $redirect, string $action, array $ids ): string {
		if ( self::BULK_ACTION !== $action || empty( $ids ) ) {
			return $redirect;
		}
		set_transient( 'dze_content_bulk_' . get_current_user_id(), array_map( 'intval', $ids ), HOUR_IN_SECONDS );
		return add_query_arg( [ 'post_type' => 'product', 'page' => self::BULK_SLUG ], admin_url( 'edit.php' ) );
	}

	public function register_bulk_page(): void {
		add_submenu_page(
			'edit.php?post_type=product',
			__( 'AI Content bulk', 'dazont-ecom' ),
			__( 'AI Content bulk', 'dazont-ecom' ),
			'edit_products',
			self::BULK_SLUG,
			[ $this, 'render_bulk_page' ]
		);
	}

	/** Products queued for the bulk screen (from the last bulk action). */
	private function bulk_products(): array {
		$ids = get_transient( 'dze_content_bulk_' . get_current_user_id() );
		$out = [];
		foreach ( array_map( 'intval', (array) $ids ) as $pid ) {
			$product = wc_get_product( $pid );
			if ( ! $product instanceof WC_Product ) {
				continue;
			}
			$thumb_id = get_post_thumbnail_id( $pid );
			$cost     = (string) get_post_meta( $pid, '_dze_cogs', true );
			if ( '' === $cost ) {
				$cost = (string) get_post_meta( $pid, '_cogs_value', true );
			}
			if ( '' === $cost ) {
				$cost = (string) $product->get_regular_price();
			}
			$out[] = [
				'id'    => $pid,
				'title' => $product->get_name(),
				'edit'  => get_edit_post_link( $pid, '' ),
				'thumb' => $thumb_id ? (string) wp_get_attachment_image_url( $thumb_id, 'medium' ) : (string) wc_placeholder_img_src(),
				'full'  => $thumb_id ? (string) wp_get_attachment_image_url( $thumb_id, 'full' ) : '',
				'cost'  => $cost,
			];
		}
		return $out;
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
			<h1><?php esc_html_e( 'AI Content — bulk generation', 'dazont-ecom' ); ?></h1>

			<?php if ( $ok_n < $tot_n ) : ?>
				<div class="notice notice-warning"><p>
					<?php printf( /* translators: 1: validated, 2: total */ esc_html__( '%1$d/%2$d prompts validated — bulk applies directly, so only validated fields can be selected below.', 'dazont-ecom' ), (int) $ok_n, (int) $tot_n ); ?>
				</p></div>
			<?php endif; ?>

			<?php if ( empty( $products ) ) : ?>
				<p><?php esc_html_e( 'No products queued. Select products on the Products list and pick "Generate AI content (Dazont)" in the Bulk actions menu.', 'dazont-ecom' ); ?></p>
				<?php return; ?>
			<?php endif; ?>

			<div class="dze-cb-controls">
				<h2 style="margin-top:0;"><?php esc_html_e( 'What to generate', 'dazont-ecom' ); ?></h2>
				<p>
					<?php foreach ( self::enabled_fields() as $fid => $f ) : $fok = self::field_validated( $fid ); ?>
						<label title="<?php echo $fok ? '' : esc_attr__( 'Prompt not validated — locked for bulk.', 'dazont-ecom' ); ?>">
							<input type="checkbox" class="dze-cb-field" value="<?php echo esc_attr( $fid ); ?>" <?php checked( $fok ); disabled( ! $fok ); ?> />
							<?php echo esc_html( $f['label'] ); ?><?php echo $fok ? '' : ' 🔒'; ?>
						</label>
					<?php endforeach; ?>
				</p>
				<p>
					<label><input type="checkbox" id="dze-cb-price" checked /> <strong><?php esc_html_e( 'Recalculate price', 'dazont-ecom' ); ?></strong> <span class="description"><?php esc_html_e( '(cost below × price table → regular price; cost saved as COGS)', 'dazont-ecom' ); ?></span></label>
				</p>
				<p>
					<?php if ( $valid_tpls ) : ?>
						<label><input type="checkbox" id="dze-cb-image" /> <strong><?php esc_html_e( 'Generate an image per product', 'dazont-ecom' ); ?></strong></label>
						<label style="margin-left:10px;"><?php esc_html_e( 'Default template:', 'dazont-ecom' ); ?>
							<select id="dze-cb-tpl">
								<?php foreach ( $valid_tpls as $i => $t ) : ?>
									<option value="<?php echo (int) $i; ?>"><?php echo esc_html( $t['name'] ); ?> (<?php echo esc_html( $t['target'] ?? 'gallery' ); ?>)</option>
								<?php endforeach; ?>
							</select>
						</label>
						<span class="description"><?php esc_html_e( 'Tick/untick and change the template per product in the table — this is the judgement call.', 'dazont-ecom' ); ?></span>
					<?php else : ?>
						<span class="description"><?php esc_html_e( 'No validated image template yet — validate one in AI Settings → Product content to enable images in bulk.', 'dazont-ecom' ); ?></span>
					<?php endif; ?>
				</p>
				<p>
					<button type="button" class="button button-primary button-hero" id="dze-cb-start" <?php disabled( 0 === $ok_n && empty( $valid_tpls ) ); ?>><?php esc_html_e( 'Start bulk generation', 'dazont-ecom' ); ?></button>
					<button type="button" class="button" id="dze-cb-stop" style="display:none;"><?php esc_html_e( 'Stop', 'dazont-ecom' ); ?></button>
				</p>
				<div class="dze-cb-bar" style="display:none;"><div class="dze-cb-fill"></div></div>
				<p id="dze-cb-progress" class="description"></p>
			</div>

			<table class="dze-cb-table">
				<tr>
					<th style="width:130px;"><?php esc_html_e( 'Image', 'dazont-ecom' ); ?></th>
					<th><?php esc_html_e( 'Product', 'dazont-ecom' ); ?></th>
					<th style="width:110px;"><?php esc_html_e( 'Cost (COGS)', 'dazont-ecom' ); ?></th>
					<th style="width:220px;"><?php esc_html_e( 'Image generation', 'dazont-ecom' ); ?></th>
					<th style="width:240px;"><?php esc_html_e( 'Status', 'dazont-ecom' ); ?></th>
				</tr>
				<?php foreach ( $products as $p ) : ?>
					<tr class="dze-cb-row" data-id="<?php echo (int) $p['id']; ?>">
						<td class="dze-cb-thumb">
							<?php if ( $p['full'] ) : ?><a href="<?php echo esc_url( $p['full'] ); ?>" target="_blank" rel="noopener"><?php endif; ?>
							<img src="<?php echo esc_url( $p['thumb'] ); ?>" alt="" />
							<?php if ( $p['full'] ) : ?></a><?php endif; ?>
						</td>
						<td><a href="<?php echo esc_url( $p['edit'] ); ?>" target="_blank" rel="noopener"><strong><?php echo esc_html( $p['title'] ); ?></strong></a></td>
						<td><input type="number" step="0.01" class="dze-cb-cost" value="<?php echo esc_attr( $p['cost'] ); ?>" style="width:90px !important;" /></td>
						<td>
							<label><input type="checkbox" class="dze-cb-row-img" /> <?php esc_html_e( 'Image', 'dazont-ecom' ); ?></label>
							<select class="dze-cb-row-tpl" style="max-width:150px;">
								<?php foreach ( $valid_tpls as $i => $t ) : ?>
									<option value="<?php echo (int) $i; ?>"><?php echo esc_html( $t['name'] ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
						<td class="dze-cb-status">—</td>
					</tr>
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
		$on_bulk     = ( 'product_page_' . self::BULK_SLUG ) === $hook;
		$on_settings = false !== strpos( (string) $hook, 'dazont' );
		if ( ! $on_product && ! $on_bulk && ! $on_settings ) {
			return;
		}
		wp_enqueue_style( 'dze-content', DZE_URL . 'admin/css/content.css', [], DZE_VERSION );

		if ( $on_bulk ) {
			wp_enqueue_script( 'dze-content-bulk', DZE_URL . 'admin/js/content-bulk.js', [ 'jquery' ], DZE_VERSION, true );
			wp_localize_script( 'dze-content-bulk', 'dzeContentBulk', [
				'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
				'nonce'     => wp_create_nonce( self::NONCE ),
				'validated' => true, // gating is per-field via disabled checkboxes.
				'fields'    => array_map( static fn( $f ) => $f['label'], self::enabled_fields() ),
				'i18n'      => [
					'working'  => __( 'Working…', 'dazont-ecom' ),
					'done'     => __( 'Done', 'dazont-ecom' ),
					'stopped'  => __( 'Stopped.', 'dazont-ecom' ),
					'error'    => __( 'error', 'dazont-ecom' ),
					'progress' => __( '%1$s / %2$s tasks — %3$s', 'dazont-ecom' ),
					'finished' => __( 'Finished: %1$s ok, %2$s errors.', 'dazont-ecom' ),
					'noFields' => __( 'Select at least one thing to generate.', 'dazont-ecom' ),
				],
			] );
			return;
		}
		if ( ! $on_product ) {
			return;
		}
		$pid = (int) get_the_ID();
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
		wp_localize_script( 'dze-content', 'dzeContent', [
			'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
			'nonce'      => wp_create_nonce( self::NONCE ),
			'postId'     => $pid,
			'validated'  => $fv, // per-field map.
			'fields'     => $labels,
			'templates'  => array_map( static fn( $t ) => [ 'name' => $t['name'], 'target' => $t['target'] ?? 'gallery', 'valid' => ! empty( $t['valid'] ), 'prompt' => (string) $t['prompt'] ], self::image_templates() ),
			'prompts'    => $prompts,
			'product'    => [
				'title' => $product ? $product->get_name() : '',
				'desc'  => $product ? wp_strip_all_tags( (string) get_post_field( 'post_content', $pid ) ) : '',
				// Imported supplier attributes are already stored as standard product
				// attributes — pre-fill them so nobody retypes anything.
				'attr'  => $product ? self::attributes_summary( $product ) : '',
				'price' => $product ? (string) $product->get_regular_price() : '',
			],
			'i18n'       => [
				'toolbox'    => __( 'AI Content toolbox', 'dazont-ecom' ),
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
				'genImage'   => __( 'Generate image', 'dazont-ecom' ),
				'imgWait'    => __( 'Rendering — up to a minute…', 'dazont-ecom' ),
				'imgAdded'   => __( 'Image added.', 'dazont-ecom' ),
				'imgPreview' => __( 'Preview only — template not validated, nothing attached. Validate it in AI Settings to attach results.', 'dazont-ecom' ),
				'imgReady'   => __( 'Image ready — added to the session gallery below.', 'dazont-ecom' ),
				'addSelected'=> __( 'Add selected to product', 'dazont-ecom' ),
				'added'      => __( 'Added ✓', 'dazont-ecom' ),
				'attachDone' => __( 'image(s) added to the product with SEO naming.', 'dazont-ecom' ),
				'sendTo'     => __( 'Send to:', 'dazont-ecom' ),
				'toGallery'  => __( 'Product gallery', 'dazont-ecom' ),
				'toMain'     => __( 'Main image (first selected)', 'dazont-ecom' ),
				'select'     => __( 'Select', 'dazont-ecom' ),
				'editImage'  => __( 'Edit with a manual prompt', 'dazont-ecom' ),
				'variantHelp'=> __( 'Describe the change to apply to THIS image (one-off prompt, not saved):', 'dazont-ecom' ),
				'genVariant' => __( 'Generate variant', 'dazont-ecom' ),
				'editPrompt' => __( 'Edit prompt', 'dazont-ecom' ),
				'savePrompt' => __( 'Save prompt', 'dazont-ecom' ),
				'savedPrompt'=> __( 'Prompt saved ✓', 'dazont-ecom' ),
				'promptNote' => __( 'Edits here are used for THIS generation only — click 💾 to make them permanent.', 'dazont-ecom' ),
				'cancel'     => __( 'Cancel', 'dazont-ecom' ),
				'notValid'   => __( 'not validated', 'dazont-ecom' ),
				'fieldLocked'=> __( 'This prompt is not validated yet (AI Settings → Product content).', 'dazont-ecom' ),
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
		$user     = ( '' !== trim( $override ) ? $override : self::prompt_for( $field ) ) . "\n\n--- PRODUCT DATA ---\n" . $payload . "\n";
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

		$system = 'You are an expert e-commerce copywriter. ' . self::store_context();
		$user   = "--- PRODUCT DATA ---\n" . $payload . "\n";
		$user .= "\nGenerate the " . count( $targets ) . " fields below. Each field has its OWN instructions, coming from separate proven scripts — follow each set EXACTLY and independently, as if it were the only task.\n";
		$user .= "OUTPUT FORMAT (strict): for each field output a line exactly ===FIELD:<field_id>=== followed by that field's content, then after the last field a line ===END===. Nothing else.\n\n";
		// One-off prompt overrides from the live editors (never saved here).
		$overrides = [];
		if ( isset( $_POST['prompts'] ) && is_array( $_POST['prompts'] ) ) {
			foreach ( wp_unslash( $_POST['prompts'] ) as $ofid => $op ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized below.
				$overrides[ sanitize_key( $ofid ) ] = sanitize_textarea_field( (string) $op );
			}
		}
		$tokens = 300;
		foreach ( $targets as $fid => $f ) {
			$p       = ! empty( $overrides[ $fid ] ) ? $overrides[ $fid ] : self::prompt_for( $fid );
			$user   .= '===INSTRUCTIONS for field "' . $fid . '" (' . $f['label'] . ")===\n" . $p . "\n\n";
			$tokens += (int) ( $f['tokens'] ?? 300 );
		}

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		try {
			$text = DZE_Marketing_Ai::complete( $system, $user, self::model(), $tokens, 240 );
		} catch ( \Throwable $e ) {
			wp_send_json_error( [ 'message' => $e->getMessage() ] );
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
			wp_send_json_success( [ 'results' => $results, 'texts' => $texts ] );
		}
		wp_send_json_success( [ 'texts' => $texts ] );
	}

	public function ajax_apply(): void {
		$this->guard();
		$pid    = isset( $_POST['post'] ) ? absint( $_POST['post'] ) : 0;
		$field  = isset( $_POST['field'] ) ? sanitize_key( wp_unslash( $_POST['field'] ) ) : '';
		if ( '' !== $field && ! self::field_validated( $field ) ) {
			wp_send_json_error( [ 'message' => __( 'This prompt is not validated yet — tick its "Prompt validated" box in AI Settings → Product content.', 'dazont-ecom' ) ] );
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

	public function ajax_price(): void {
		$this->guard();
		$pid  = isset( $_POST['post'] ) ? absint( $_POST['post'] ) : 0;
		$cost = isset( $_POST['cost'] ) ? (float) wp_unslash( $_POST['cost'] ) : 0;
		if ( ! $pid || $cost <= 0 ) {
			wp_send_json_error( [ 'message' => __( 'Enter a valid cost.', 'dazont-ecom' ) ] );
		}
		$mult    = self::mult_for_cost( $cost );
		$regular = round( $cost * $mult, (int) ( function_exists( 'wc_get_price_decimals' ) ? wc_get_price_decimals() : 2 ) );
		// Deterministic math on an explicit action — no prompt involved, applies directly.
		$product = wc_get_product( $pid );
		if ( $product instanceof WC_Product ) {
			update_post_meta( $pid, '_dze_cogs', $cost );
			update_post_meta( $pid, '_cogs_value', $cost ); // WooCommerce native Cost of Goods.
			$product->set_regular_price( (string) $regular );
			$product->save();
		}
		wp_send_json_success( [ 'mult' => $mult, 'regular' => $regular, 'applied' => true ] );
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
			wp_send_json_error( [ 'message' => __( 'Add your fal.ai key under AI Settings → General first.', 'dazont-ecom' ) ] );
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
		$thumb_id = get_post_thumbnail_id( $pid );
		if ( '' === $src && ! $thumb_id ) {
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
			$source    = '' !== $src ? $src : $this->fal_source_data_uri( (int) $thumb_id );
			$image_url = $this->fal_generate( $prompt, [ $source ] );

			if ( 'defer' === $mode ) {
				// Toolbox flow: never auto-attach — the result joins the session
				// gallery; a human selects what gets pushed to the product.
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
	private static function is_fal_url( string $url ): bool {
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );
		return 'fal.media' === $host || str_ends_with( $host, '.fal.media' ) || str_ends_with( $host, '.fal.run' ) || 'fal.run' === $host;
	}

	/**
	 * Pushes selected session-gallery images onto the product. Standard SEO
	 * procedure on the way in: the attachment file name, title, slug and alt all
	 * take the product title (WordPress natively de-duplicates with -1/-2/-3).
	 */
	public function ajax_image_attach(): void {
		$this->guard();
		$pid    = isset( $_POST['post'] ) ? absint( $_POST['post'] ) : 0;
		$target = ( isset( $_POST['target'] ) && 'main' === $_POST['target'] ) ? 'main' : 'gallery';
		$urls   = isset( $_POST['urls'] ) ? array_map( 'esc_url_raw', (array) wp_unslash( $_POST['urls'] ) ) : [];
		if ( ! $pid || empty( $urls ) ) {
			wp_send_json_error( [ 'message' => __( 'Nothing selected.', 'dazont-ecom' ) ] );
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		$ids    = [];
		$errors = 0;
		foreach ( $urls as $u ) {
			if ( ! self::is_fal_url( $u ) ) {
				$errors++;
				continue;
			}
			try {
				// First selected image becomes the main image when requested.
				$this_target = ( 'main' === $target && empty( $ids ) ) ? 'main' : 'gallery';
				$ids[]       = $this->sideload_seo( $u, $pid, $this_target );
			} catch ( \Throwable $e ) {
				$errors++;
			}
		}
		if ( empty( $ids ) ) {
			wp_send_json_error( [ 'message' => __( 'Could not attach the selected image(s).', 'dazont-ecom' ) ] );
		}
		wp_send_json_success( [ 'attached' => count( $ids ), 'errors' => $errors, 'ids' => $ids ] );
	}

	/**
	 * Sideloads a generated image with SEO naming: file name = product slug
	 * (WordPress appends -1/-2/-3 natively on collision), attachment title/slug =
	 * product title, alt text set. Attaches as main image or appends to the
	 * product gallery.
	 */
	private function sideload_seo( string $url, int $pid, string $target ): int {
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
			$ext = 'png';
		}
		$att_id = media_handle_sideload( [ 'name' => $slug . '.' . $ext, 'tmp_name' => $tmp ], $pid, $title );
		if ( is_wp_error( $att_id ) ) {
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
			throw new RuntimeException( $att_id->get_error_message() );
		}
		// Attachment title + slug match the product (WP uniquifies the slug natively).
		wp_update_post( [ 'ID' => (int) $att_id, 'post_title' => $title, 'post_name' => $slug ] );
		update_post_meta( (int) $att_id, '_wp_attachment_image_alt', $title );

		if ( 'main' === $target ) {
			set_post_thumbnail( $pid, (int) $att_id );
		} else {
			$gallery = (string) get_post_meta( $pid, '_product_image_gallery', true );
			$ids     = array_filter( array_map( 'absint', explode( ',', $gallery ) ) );
			$ids[]   = (int) $att_id;
			update_post_meta( $pid, '_product_image_gallery', implode( ',', array_unique( $ids ) ) );
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
		update_option( self::OPT_SETTINGS, $settings, false );
		self::$registry_cache = null;
		wp_send_json_success( [ 'saved' => true ] );
	}

	/**
	 * Reads an attachment (preferring the 'large' size to bound the payload) and
	 * returns it as a base64 data URI, which fal decodes directly — removing every
	 * "fal cannot reach the source URL" failure (private staging, hotlink rules).
	 */
	private function fal_source_data_uri( int $attachment_id ): string {
		$path = '';
		$size = image_get_intermediate_size( $attachment_id, 'large' );
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
			$url  = (string) wp_get_attachment_image_url( $attachment_id, 'large' );
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

	private function fal_generate( string $prompt, array $image_urls ): string {
		$resp = wp_remote_post( self::FAL_ENDPOINT, [
			'timeout' => 120,
			'headers' => [ 'Authorization' => 'Key ' . self::fal_key(), 'content-type' => 'application/json' ],
			'body'    => wp_json_encode( [
				'prompt'        => $prompt,
				'image_urls'    => array_values( $image_urls ),
				'num_images'    => 1,
				'aspect_ratio'  => 'auto',
				'output_format' => 'png',
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
		if ( ! $url ) {
			throw new RuntimeException( __( 'fal.ai returned no image.', 'dazont-ecom' ) );
		}
		return (string) $url;
	}
}
