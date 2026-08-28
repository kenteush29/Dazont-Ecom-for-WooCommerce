<?php
defined( 'ABSPATH' ) || exit;

/**
 * Thin WPML helper (static). Used to keep the listing on the default language
 * while aggregating sales across every translation of a product/variation.
 */
final class DZE_Wpml {

	public static function is_active(): bool {
		return defined( 'ICL_SITEPRESS_VERSION' ) || function_exists( 'icl_object_id' );
	}

	/** Default WPML language code, or '' when WPML is inactive. */
	public static function default_language(): string {
		if ( ! self::is_active() ) {
			return '';
		}
		return (string) apply_filters( 'wpml_default_language', null );
	}

	/** Current request language code, or '' when WPML is inactive. */
	public static function current_language(): string {
		if ( ! self::is_active() ) {
			return '';
		}
		return (string) apply_filters( 'wpml_current_language', null );
	}

	/**
	 * Active languages as a list of
	 * [ 'code' => 'en', 'native_name' => 'English', 'english_name' => 'English' ].
	 * Empty array when WPML is inactive.
	 */
	public static function get_active_languages(): array {
		if ( ! self::is_active() ) {
			return [];
		}
		$languages = apply_filters( 'wpml_active_languages', null, [ 'skip_missing' => 0 ] );
		if ( ! is_array( $languages ) ) {
			return [];
		}
		$result = [];
		foreach ( $languages as $code => $data ) {
			$result[] = [
				'code'        => (string) $code,
				'native_name' => (string) ( $data['native_name'] ?? $code ),
				// The name a model understands without ambiguity ("French", not "fr").
				'english_name'=> (string) ( $data['english_name'] ?? $data['translated_name'] ?? '' ),
				'flag'        => (string) ( $data['country_flag_url'] ?? '' ),
			];
		}
		return $result;
	}

	/** Language code of a post, or '' when unknown / WPML inactive. */
	public static function post_language( int $post_id, string $post_type ): string {
		if ( ! self::is_active() ) {
			return '';
		}
		$lang = apply_filters( 'wpml_element_language_code', null, [
			'element_id'   => $post_id,
			'element_type' => 'post_' . $post_type,
		] );
		return is_string( $lang ) ? $lang : '';
	}

	/**
	 * The elements of one type that ARE in a language.
	 *
	 * WPML narrows an ordinary query through filters of its own, and those
	 * filters are not reliably in place where this plugin READS the shop: a
	 * cron run and an admin-ajax request are not a front-end page, and a pass
	 * that runs there sees every translation as another product — a catalogue
	 * of a thousand reported as nine thousand. Asking each post its language
	 * one at a time is a query per post, which is worse. So the question is
	 * asked once, of the table WPML keeps the answer in.
	 *
	 * @param string $element_type WPML's own name for the kind: 'post_product',
	 *                             'post_page', 'tax_product_cat'. For a
	 *                             taxonomy the ids are TERM TAXONOMY ids, not
	 *                             term ids — that is WPML's schema, not ours.
	 * @return array<int,true>|null The ids as a set, or NULL when WPML cannot
	 *                             be asked. Null means "do not narrow"; it
	 *                             never means "narrow to nothing", because a
	 *                             shop reported as empty is worse than a shop
	 *                             reported twice.
	 */
	public static function ids_in_language( string $element_type, string $language ): ?array {
		global $wpdb;
		if ( ! self::is_active() || '' === $language || ! $wpdb ) {
			return null;
		}
		$table = $wpdb->prefix . 'icl_translations';
		if ( ! self::has_table( $table ) ) {
			return null;
		}
		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- WPML's own table; there is no API that answers this in one query.
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT element_id FROM {$table} WHERE element_type = %s AND language_code = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$element_type,
				$language
			)
		);
		// phpcs:enable
		if ( ! is_array( $rows ) || ! $rows ) {
			return null;
		}
		$out = [];
		foreach ( $rows as $one ) {
			$out[ (int) $one ] = true;
		}
		return $out;
	}

	/** Whether a table is really there. Asked once a day, not once a query. */
	private static function has_table( string $table ): bool {
		global $wpdb;
		$slot = 'dze_wpml_tbl_' . md5( $table );
		$has  = get_transient( $slot );
		if ( false !== $has ) {
			return '1' === $has;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$found = (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		set_transient( $slot, $found === $table ? '1' : '0', DAY_IN_SECONDS );
		return $found === $table;
	}

	/**
	 * Canonical (default-language) id for a post. Falls back to the given id
	 * when WPML is inactive or no translation exists.
	 */
	public static function canonical_id( int $post_id, string $post_type ): int {
		$default = self::default_language();
		if ( ! $default ) {
			return $post_id;
		}
		$id = apply_filters( 'wpml_object_id', $post_id, $post_type, true, $default );
		return (int) ( $id ?: $post_id );
	}
}
