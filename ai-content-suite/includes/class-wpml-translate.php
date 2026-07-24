<?php
defined( 'ABSPATH' ) || exit;

/**
 * WPML helper (static only). Provides language discovery and post-ID resolution.
 * The actual translation actions live in AICS_Product_Actions.
 */
final class AICS_Wpml_Translate {

	public static function is_wpml_active(): bool {
		return defined( 'ICL_SITEPRESS_VERSION' ) || function_exists( 'icl_object_id' );
	}

	/**
	 * The site's default WPML language code, or '' when WPML is inactive.
	 */
	public static function default_language(): string {
		if ( ! self::is_wpml_active() ) {
			return '';
		}
		return (string) apply_filters( 'wpml_default_language', null );
	}

	/**
	 * The language code of a given post (product / product_variation).
	 * Returns '' when WPML is inactive or unknown.
	 */
	public static function post_language( int $post_id, string $post_type ): string {
		if ( ! self::is_wpml_active() ) {
			return '';
		}
		$lang = apply_filters( 'wpml_element_language_code', null, [
			'element_id'   => $post_id,
			'element_type' => 'post_' . $post_type,
		] );
		return is_string( $lang ) ? $lang : '';
	}

	/**
	 * Canonical (default-language) id for a post. Falls back to the given id
	 * when WPML is inactive or no translation is registered.
	 */
	public static function canonical_id( int $post_id, string $post_type ): int {
		$default = self::default_language();
		if ( ! $default ) {
			return $post_id;
		}
		$id = apply_filters( 'wpml_object_id', $post_id, $post_type, true, $default );
		return (int) ( $id ?: $post_id );
	}

	/**
	 * Returns active languages as [ [ 'code' => 'en', 'native_name' => 'English' ], ... ].
	 */
	public static function get_active_languages(): array {
		if ( ! self::is_wpml_active() ) {
			return [];
		}
		$languages = apply_filters( 'wpml_active_languages', null, [ 'skip_missing' => 0 ] );
		if ( ! is_array( $languages ) ) {
			return [];
		}
		$result = [];
		foreach ( $languages as $code => $data ) {
			$result[] = [
				'code'        => $code,
				'native_name' => $data['native_name'] ?? $code,
			];
		}
		return $result;
	}

	/**
	 * Resolves the product ID in a given language.
	 *
	 * @param int    $source_id       Any known ID of the product (in any language).
	 * @param string $language_code   Target language code.
	 * @param bool   $return_original When true, returns the original ID if no translation exists.
	 * @return int   The resolved post ID, or 0 when none and $return_original is false.
	 */
	public static function get_translated_post_id( int $source_id, string $language_code, bool $return_original = false ): int {
		if ( ! self::is_wpml_active() ) {
			return $return_original ? $source_id : 0;
		}
		$translated_id = apply_filters( 'wpml_object_id', $source_id, 'product', $return_original, $language_code );
		return (int) ( $translated_id ?? 0 );
	}
}
