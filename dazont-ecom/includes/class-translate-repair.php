<?php
/**
 * RELIER LES TRADUCTIONS DE TERMES QUE WPML A ENREGISTREES COMME DES ORIGINAUX.
 *
 * Une version anterieure de ce module creait la traduction d une valeur
 * d attribut sans jamais ecrire la correspondance WPML. WPML voyait donc cinq
 * couleurs anglaises la ou il y a une couleur en cinq langues — et le module,
 * ne voyant aucune traduction a sa source, la reproposait indefiniment. Pire :
 * il finissait par creer une SECONDE traduction, correctement liee celle-la,
 * et vide, pendant que celle qui porte reellement le produit restait orpheline.
 *
 * Le code d aujourd hui lie correctement. Ceci repare ce qui a ete casse.
 *
 * DEUX SITUATIONS, ET RIEN D AUTRE :
 *
 *  1. La place de la langue est LIBRE dans le groupe de la source — on y
 *     rattache l orpheline.
 *  2. La place est prise par une traduction VIDE pendant que l orpheline porte
 *     le produit — la vide lache sa place, l utile la prend.
 *
 * Aucun terme n est supprime, aucun produit n est retouche : seules les lignes
 * de correspondance de WPML bougent, et l etat d avant est conserve.
 *
 * @package Dazont_Ecom
 */

defined( 'ABSPATH' ) || exit;

final class DZE_Translate_Repair {

	/** La version du plugin qui a passe la reparation, ou rien. */
	public const DONE = 'dze_tr_links_repaired';

	/** L etat d avant, garde pour pouvoir revenir. */
	public const BACKUP = 'dze_tr_links_backup';

	/** Ce qui a ete fait, pour pouvoir le dire. */
	public const REPORT = 'dze_tr_links_report';

	/**
	 * UNE FOIS PAR SITE, ET JAMAIS PENDANT QU ON REGARDE UNE PAGE PUBLIQUE.
	 *
	 * Le drapeau porte la version qui l a passee : si la reparation devait
	 * etre reprise un jour, changer ce numero suffit et l historique reste.
	 */
	public static function maybe_run(): void {
		if ( self::DONE_AT === get_option( self::DONE, '' ) ) {
			return;
		}
		if ( ! class_exists( 'DZE_Wpml' ) || ! DZE_Wpml::is_active() ) {
			return; // rien a relier sans WPML.
		}
		$out = self::run( true );
		update_option( self::DONE, self::DONE_AT, false );
		update_option( self::REPORT, $out, false );
	}

	/** La passe a laquelle ce correctif appartient. */
	public const DONE_AT = '4.456.0';

	/**
	 * @param bool $apply Ecrire, ou seulement dire ce qui serait fait.
	 * @return array{linked:int,swapped:int,skipped:int,lines:array<int,string>}
	 */
	public static function run( bool $apply = false ): array {
		global $wpdb;
		$out = [ 'linked' => 0, 'swapped' => 0, 'skipped' => 0, 'lines' => [] ];
		if ( ! $wpdb ) {
			return $out;
		}
		$T = $wpdb->prefix . 'icl_translations';
		if ( ! DZE_Wpml::has_table( $T ) ) {
			return $out;
		}
		$save = [];

		foreach ( self::orphans() as $o ) {
			$type = 'tax_' . $o['taxonomy'];
			$lang = $o['lang'];
			$trid = (int) $o['src_trid'];

			// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- WPML's own table.
			$holder = $wpdb->get_var( $wpdb->prepare(
				"SELECT element_id FROM {$T} WHERE trid = %d AND element_type = %s AND language_code = %s",
				$trid, $type, $lang ) );

			// LA PLACE EST PRISE : elle ne se libere que par une traduction que
			// RIEN ne porte. Deplacer un terme qu un produit affiche, c est
			// changer ce que le client voit, et ce n est pas une reparation.
			if ( $holder ) {
				$held = (int) $wpdb->get_var( $wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->term_relationships} WHERE term_taxonomy_id = %d", (int) $holder ) );
				if ( $held > 0 || (int) $holder === (int) $o['ttid'] ) {
					$out['skipped']++;
					continue;
				}
				if ( $apply ) {
					$save[] = self::row( $T, (int) $holder, $type );
					$save[] = self::row( $T, (int) $o['ttid'], $type );
					$wpdb->delete( $T, [ 'element_id' => (int) $holder, 'element_type' => $type ], [ '%d', '%s' ] );
					self::link( $T, (int) $o['ttid'], $type, $trid, $lang );
				}
				$out['swapped']++;
				$out['lines'][] = sprintf( '%s #%d → %s (a pris la place d une traduction vide)', $o['taxonomy'], $o['term_id'], $lang );
				continue;
			}

			if ( $apply ) {
				$save[] = self::row( $T, (int) $o['ttid'], $type );
				self::link( $T, (int) $o['ttid'], $type, $trid, $lang );
			}
			$out['linked']++;
			$out['lines'][] = sprintf( '%s #%d → %s', $o['taxonomy'], $o['term_id'], $lang );
			// phpcs:enable
		}

		if ( $apply && $save ) {
			update_option( self::BACKUP, array_values( array_filter( $save ) ), false );
			if ( function_exists( 'icl_cache_clear' ) ) {
				icl_cache_clear();
			}
			do_action( 'wpml_cache_clear' );
		}
		return $out;
	}

	/** Une ligne de correspondance, telle qu elle est, avant d y toucher. */
	private static function row( string $table, int $element_id, string $type ): ?array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- WPML's own table.
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE element_id = %d AND element_type = %s", $element_id, $type ), ARRAY_A );
	}

	/** Ecrire la correspondance, et vider le cache du terme. */
	private static function link( string $table, int $ttid, string $type, int $trid, string $lang ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- WPML's own table.
		$wpdb->update(
			$table,
			[ 'trid' => $trid, 'language_code' => $lang, 'source_language_code' => 'en' ],
			[ 'element_id' => $ttid, 'element_type' => $type ],
			[ '%d', '%s', '%s' ],
			[ '%d', '%s' ]
		);
	}

	/**
	 * LES TRADUCTIONS QUE WPML PREND POUR DES ORIGINAUX.
	 *
	 * Reconnues sans jamais se fier au slug seul : une valeur declaree anglaise
	 * qu AUCUN produit anglais ne porte est une traduction mal enregistree, quel
	 * que soit son nom. La langue est celle des produits qui la portent, et ils
	 * doivent tous s accorder — une valeur portee par deux langues n est pas une
	 * traduction, c est autre chose, et on n y touche pas.
	 *
	 * La source est le terme de meme taxonomie porte par le produit ANGLAIS du
	 * meme groupe. Quand le slug garde encore son suffixe de langue il sert de
	 * controle, jamais de preuve a lui seul.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function orphans(): array {
		global $wpdb;
		$T = $wpdb->prefix . 'icl_translations';
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- WPML's own table.
		$rows = (array) $wpdb->get_results(
			"SELECT tt.term_taxonomy_id ttid, tt.taxonomy, t.term_id, t.slug, ic.trid
			   FROM {$wpdb->term_taxonomy} tt
			   JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
			   JOIN {$T} ic ON ic.element_id = tt.term_taxonomy_id AND ic.element_type = CONCAT( 'tax_', tt.taxonomy )
			  WHERE tt.taxonomy LIKE 'pa\\_%'
			    AND ic.language_code = 'en' AND ic.source_language_code IS NULL",
			ARRAY_A
		);
		$out = [];
		foreach ( $rows as $r ) {
			$ttid = (int) $r['ttid'];
			$tax  = (string) $r['taxonomy'];

			$langs = (array) $wpdb->get_col( $wpdb->prepare(
				"SELECT DISTINCT ic.language_code
				   FROM {$wpdb->term_relationships} tr
				   JOIN {$T} ic ON ic.element_id = tr.object_id AND ic.element_type = 'post_product'
				  WHERE tr.term_taxonomy_id = %d", $ttid ) );
			if ( count( $langs ) !== 1 || 'en' === $langs[0] ) {
				continue; // sans produit, anglaise pour de vrai, ou partagee : hors sujet.
			}
			$lang = (string) $langs[0];

			// Les sources possibles : ce que porte le produit anglais du groupe.
			$srcs = [];
			$prods = (array) $wpdb->get_col( $wpdb->prepare(
				"SELECT object_id FROM {$wpdb->term_relationships} WHERE term_taxonomy_id = %d", $ttid ) );
			foreach ( $prods as $pid ) {
				$trid = $wpdb->get_var( $wpdb->prepare( "SELECT trid FROM {$T} WHERE element_id = %d AND element_type = 'post_product'", (int) $pid ) );
				if ( ! $trid ) {
					continue;
				}
				$pen = $wpdb->get_var( $wpdb->prepare( "SELECT element_id FROM {$T} WHERE trid = %d AND element_type = 'post_product' AND language_code = 'en'", (int) $trid ) );
				if ( ! $pen ) {
					continue;
				}
				foreach ( (array) $wpdb->get_col( $wpdb->prepare(
					"SELECT tt2.term_taxonomy_id FROM {$wpdb->term_relationships} tr2
					   JOIN {$wpdb->term_taxonomy} tt2 ON tt2.term_taxonomy_id = tr2.term_taxonomy_id
					  WHERE tr2.object_id = %d AND tt2.taxonomy = %s", (int) $pen, $tax ) ) as $i ) {
					$srcs[ (int) $i ] = true;
				}
			}
			$srcs = array_keys( $srcs );

			// Le suffixe de langue, quand il est encore la, departage.
			$base = preg_replace( '/-' . preg_quote( $lang, '/' ) . '(-\d+)?$/', '', (string) $r['slug'] );
			$by_slug = 0;
			if ( $base !== $r['slug'] ) {
				$by_slug = (int) $wpdb->get_var( $wpdb->prepare(
					"SELECT tt3.term_taxonomy_id FROM {$wpdb->terms} t3
					   JOIN {$wpdb->term_taxonomy} tt3 ON tt3.term_id = t3.term_id
					  WHERE t3.slug = %s AND tt3.taxonomy = %s LIMIT 1", $base, $tax ) );
			}
			$pick = 0;
			if ( $by_slug && in_array( $by_slug, $srcs, true ) ) {
				$pick = $by_slug;
			} elseif ( 1 === count( $srcs ) ) {
				$pick = (int) $srcs[0];
			}
			if ( ! $pick ) {
				continue; // rien de certain : on laisse.
			}
			$s = $wpdb->get_row( $wpdb->prepare( "SELECT trid, language_code, source_language_code FROM {$T} WHERE element_id = %d AND element_type = %s", $pick, 'tax_' . $tax ), ARRAY_A );
			if ( ! $s || 'en' !== $s['language_code'] || null !== $s['source_language_code'] || (int) $s['trid'] === (int) $r['trid'] ) {
				continue;
			}
			$out[] = [
				'ttid' => $ttid, 'term_id' => (int) $r['term_id'], 'taxonomy' => $tax,
				'lang' => $lang, 'src_trid' => (int) $s['trid'],
			];
			// phpcs:enable
		}
		return $out;
	}
}
