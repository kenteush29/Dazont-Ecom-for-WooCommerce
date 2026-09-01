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

	/**
	 * A language as WPML itself draws it: its flag, and its name.
	 *
	 * The plugin printed "FR, DE, PL, ES" in a dozen places while the rest of
	 * this admin — the product list, the translation screens — shows a flag
	 * per language and a tick under it. Two vocabularies for one thing, and
	 * the shop has to learn ours. This is WPML's own flag, from WPML's own
	 * data, so a language reads the same wherever it appears.
	 *
	 * $state: 'done' draws a tick, 'todo' a hollow circle, '' nothing.
	 */
	public static function flag_html( string $code, string $state = '', string $title = '' ): string {
		$code = strtolower( trim( $code ) );
		if ( '' === $code ) {
			return '';
		}
		static $all = null;
		if ( null === $all ) {
			$all = [];
			foreach ( self::get_active_languages() as $one ) {
				$all[ strtolower( (string) $one['code'] ) ] = $one;
			}
		}
		$one  = $all[ $code ] ?? [];
		$name = (string) ( $one['native_name'] ?? strtoupper( $code ) );
		$flag = (string) ( $one['flag'] ?? '' );
		// Three states, one badge: written, owed, refused. The mark lives
		// INSIDE the block — a tick, a hollow circle, a cross — because a dot
		// printed after the block was a second way of saying the same thing,
		// and two marks per language is a row nobody reads.
		$mark = 'done' === $state ? '&#10003;' : ( 'todo' === $state ? '&#9675;' : ( 'ko' === $state ? '&#10007;' : '' ) );
		$tint = 'done' === $state ? '#00794b' : ( 'todo' === $state ? '#b26a00' : ( 'ko' === $state ? '#b32d2e' : '#646970' ) );
		return sprintf(
			'<span class="dze-lang%1$s" title="%2$s">%3$s<span class="dze-lang-code">%4$s</span>%5$s</span>',
			'' !== $state ? ' is-' . esc_attr( $state ) : '',
			esc_attr( '' !== $title ? $title : $name ),
			'' !== $flag ? '<img src="' . esc_url( $flag ) . '" alt="" width="18" height="12" />' : '',
			esc_html( strtoupper( $code ) ),
			'' !== $mark ? '<b style="color:' . esc_attr( $tint ) . ';">' . $mark . '</b>' : ''
		);
	}

	/**
	 * A row of languages, drawn the same way: the ones that are done with a
	 * tick, the ones still owed hollow.
	 *
	 * @param string[] $done
	 * @param string[] $todo
	 */
	public static function flags_html( array $done, array $todo = [] ): string {
		$done = array_map( 'strtolower', array_map( 'strval', $done ) );
		$todo = array_map( 'strtolower', array_map( 'strval', $todo ) );
		// WPML'S OWN ORDER, everywhere the shop meets a row of flags — the
		// order it has already put its languages in, on the products list and
		// in its own switcher. Drawing the written ones first and the owed
		// ones after was an order of ours: the same five languages then read
		// differently on two rows of the same screen, and the eye has to
		// start again on each.
		$order = [];
		foreach ( self::get_active_languages() as $one ) {
			$code = strtolower( (string) ( $one['code'] ?? '' ) );
			if ( '' !== $code ) {
				$order[] = $code;
			}
		}
		// A language the shop no longer has is still a fact about the email
		// that was written in it, so it goes last rather than vanishing.
		foreach ( array_merge( $done, $todo ) as $code ) {
			if ( '' !== $code && ! in_array( $code, $order, true ) ) {
				$order[] = $code;
			}
		}
		$out = '';
		foreach ( $order as $code ) {
			if ( in_array( $code, $done, true ) ) {
				$out .= self::flag_html( $code, 'done' );
			} elseif ( in_array( $code, $todo, true ) ) {
				$out .= self::flag_html( $code, 'todo' );
			}
		}
		return '' !== $out ? '<span class="dze-langs">' . $out . '</span>' : '';
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
	 * The same page of this shop, in another language.
	 *
	 * A translated email that keeps the English links is an email translated
	 * for nobody: the reader is sent to a page in a language he did not ask
	 * for, and the shop's own German pages are never seen. WPML holds the
	 * answer twice — the TRANSLATION of the post, which has its own slug, and
	 * the language's URL rule (a directory, a subdomain, a parameter). The
	 * translation is the better answer and is asked for first; the URL rule is
	 * what answers for everything that is not a post — the home page, a shop
	 * page, a category.
	 *
	 * An address that is not this shop's — a Klaviyo variable, a photograph on
	 * their CDN, another site — comes back exactly as it went in. Falling back
	 * to the English page is a page; a mangled URL is a dead link.
	 */
	/**
	 * How THIS shop writes its language addresses, read from WPML's settings.
	 *
	 * Not from a filter: on the front WPML rewrites every link as the page is
	 * built, and in admin-ajax — where the emails are written — those filters
	 * are not the ones running. get_permalink() then hands back the German
	 * product with the English domain still on it, which is exactly what this
	 * shop saw: kula-tactical.com where kula-tactical.de was expected. The
	 * settings say it plainly and say it in every context.
	 *
	 * @return array{type:int,domains:array<string,string>,default:string}
	 *         type 1 = a directory (/de/), 2 = a domain of its own, 3 = ?lang=
	 */
	public static function url_shape(): array {
		$s = get_option( 'icl_sitepress_settings', [] );
		$s = is_array( $s ) ? $s : [];
		$domains = [];
		foreach ( (array) ( $s['language_domains'] ?? [] ) as $code => $host ) {
			$host = (string) preg_replace( '#^https?://#i', '', (string) $host );
			$host = trim( rtrim( $host, '/' ) );
			if ( '' !== $host ) {
				$domains[ strtolower( (string) $code ) ] = $host;
			}
		}
		return [
			'type'    => (int) ( $s['language_negotiation_type'] ?? 0 ),
			'domains' => $domains,
			'default' => (string) ( $s['default_language'] ?? '' ),
		];
	}

	/** Every host this shop answers on — one per language, when it has one. */
	public static function hosts(): array {
		$out = [];
		$mine = wp_parse_url( home_url(), PHP_URL_HOST );
		if ( $mine ) {
			$out[] = strtolower( (string) $mine );
		}
		foreach ( self::url_shape()['domains'] as $host ) {
			$out[] = strtolower( $host );
		}
		return array_values( array_unique( array_filter( $out ) ) );
	}

	/**
	 * The same address, written the way this shop writes that language.
	 *
	 * Only the ADDRESS is changed — the path is left exactly as it is. It is
	 * used to put a translated page on its own domain, never to invent a page.
	 *
	 * @return string '' when this shop has no shape for that language.
	 */
	public static function in_shape( string $url, string $lang ): string {
		$shape = self::url_shape();
		$lang  = strtolower( trim( $lang ) );
		$parts = wp_parse_url( $url );
		if ( ! $parts || empty( $parts['host'] ) || '' === $lang ) {
			return '';
		}
		if ( 2 === $shape['type'] ) {
			$host = (string) ( $shape['domains'][ $lang ] ?? '' );
			if ( '' === $host || strtolower( (string) $parts['host'] ) === $host ) {
				return '' === $host ? '' : $url;
			}
			return (string) preg_replace( '#^(https?://)[^/]+#i', '${1}' . $host, $url );
		}
		if ( 1 === $shape['type'] ) {
			if ( $lang === strtolower( $shape['default'] ) ) {
				return $url;
			}
			$path = (string) ( $parts['path'] ?? '/' );
			if ( 0 === strpos( ltrim( $path, '/' ) . '/', $lang . '/' ) ) {
				return $url; // already in that directory.
			}
			// Rebuilt from the parts, never str_replace()d: the home page's
			// path is a single slash, and replacing THAT in an address turns
			// https:// into https:/de//de/.
			return sprintf(
				'%s://%s%s%s%s',
				(string) ( $parts['scheme'] ?? 'https' ),
				(string) $parts['host'],
				'/' . $lang . $path,
				isset( $parts['query'] ) ? '?' . $parts['query'] : '',
				isset( $parts['fragment'] ) ? '#' . $parts['fragment'] : ''
			);
		}
		if ( 3 === $shape['type'] ) {
			if ( $lang === strtolower( $shape['default'] ) ) {
				return $url;
			}
			return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . 'lang=' . $lang;
		}
		return '';
	}

	/**
	 * Does this address already CARRY that language, in any of WPML's ways?
	 *
	 * Its own domain, its own directory, its own parameter — whichever this
	 * shop uses, and whichever the permalink happens to have been built with.
	 * Asked before WPML's converter is called on a URL, because a converter
	 * called on an address that already says "de" is how a link became
	 * kula.de/de/de/sturmhaube.
	 */
	public static function has_marker( string $url, string $lang ): bool {
		$lang  = strtolower( trim( $lang ) );
		$parts = wp_parse_url( $url );
		if ( ! $parts || '' === $lang ) {
			return false;
		}
		$host = strtolower( (string) ( $parts['host'] ?? '' ) );
		$want = strtolower( (string) ( self::url_shape()['domains'][ $lang ] ?? '' ) );
		if ( '' !== $want && $host === $want ) {
			return true;
		}
		if ( 0 === strpos( $host, $lang . '.' ) ) {
			return true; // a subdomain per language.
		}
		$path = ltrim( (string) ( $parts['path'] ?? '/' ), '/' );
		if ( 0 === strpos( $path . '/', $lang . '/' ) ) {
			return true;
		}
		parse_str( (string) ( $parts['query'] ?? '' ), $q );
		return isset( $q['lang'] ) && strtolower( (string) $q['lang'] ) === $lang;
	}

	/**
	 * ONE post, as that language's readers reach it — asked of WPML.
	 *
	 * "Toujours le problème des urls fake: kula-tactical.fr/hooded-combat-shirt
	 * devrait être kula-tactical.fr/chemise-tactique-a-capuche-yz. Devrait
	 * utiliser l\'url réel de wpml."
	 *
	 * Two halves, and the plugin used to get one of them from WPML and build
	 * the other itself, which is why the answer moved about: the translation's
	 * own SLUG comes from its permalink, and WHERE that language lives — a
	 * domain, a directory, a parameter, and the translated product base with
	 * it — is WPML's answer through its own filter. get_permalink() alone
	 * gives the right slug on whatever host the current request is on, and in
	 * admin-ajax that is the default one; a host swap of ours gives the right
	 * host with the ENGLISH slug when the lookup that finds the translation
	 * fails. Neither half is invented here any more.
	 *
	 * @return string '' when that post is not translated into that language —
	 *                which is a real answer, not a failure: the caller then
	 *                leaves the original address alone.
	 */
	public static function post_url_in_language( int $post_id, string $type, string $lang, ?string &$why = null ): string {
		$why  = 'not-translated';
		$lang = strtolower( trim( $lang ) );
		if ( $post_id <= 0 || '' === $lang || ! self::is_active() ) {
			$why = 'no-wpml';
			return '';
		}
		if ( $lang === strtolower( self::default_language() ) ) {
			$why = 'no-language';
			return '';
		}
		// No fallback: asked with one, WPML hands back the post it was given
		// and an untranslated product reads as a translated one.
		$tid = (int) apply_filters( 'wpml_object_id', $post_id, ( '' !== $type ? $type : 'post' ), false, $lang );
		if ( ! $tid || $tid === $post_id ) {
			return '';
		}
		$link = (string) get_permalink( $tid );
		if ( '' === $link ) {
			$why = 'no-page';
			return '';
		}
		// WPML'S OWN answer for "this address, in that language" — asked only
		// when the permalink does not already carry the language. On many
		// shops WPML has already filtered get_permalink() by the time we see
		// it, and converting a converted address doubles the language.
		if ( ! self::has_marker( $link, $lang ) ) {
			$abs = apply_filters( 'wpml_permalink', $link, $lang, true );
			if ( is_string( $abs ) && '' !== $abs ) {
				$link = $abs;
			}
		}
		// And the half WPML's filter cannot answer for on a request that is
		// not a front-end one: the HOST this shop keeps that language on.
		// in_shape() leaves an address that is already right exactly as it is.
		$moved = self::in_shape( $link, $lang );
		if ( '' !== $moved ) {
			$link = $moved;
		}
		$why = 'translation';
		return $link;
	}

	public static function url_in_language( string $url, string $lang, ?string &$why = null ): string {
		$why  = 'unchanged';
		$url  = trim( $url );
		$lang = trim( $lang );
		if ( ! self::is_active() ) {
			$why = 'no-wpml';
			return $url;
		}
		if ( '' === $url || '' === $lang || $lang === self::default_language() ) {
			$why = 'no-language';
			return $url;
		}
		// Ours, on ANY of the shop's hosts: a shop with a domain per language
		// answers on five, and a link already written on one of them is still
		// this shop's link.
		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( ! $host || ! in_array( strtolower( (string) $host ), self::hosts(), true ) ) {
			$why = 'not-ours';
			return $url;
		}
		// 1. WHICH page is this? By its slug, read straight from the posts
		//    table. NOT url_to_postid(): that one walks the rewrite rules and
		//    does not reliably answer for a WooCommerce product — it returned
		//    nothing on this shop, so every lookup after it was dead and every
		//    German email linked to the English page.
		$id = self::post_of( $url );
		if ( $id > 0 ) {
			// 2. Its translation, the same one the product edit screen lists
			//    and the language switcher links to. Only when it is genuinely
			//    another post: asked with the original as fallback, WPML hands
			//    back the post it was given when nothing is translated.
			$type = (string) ( get_post_type( $id ) ?: 'post' );
			$link = self::post_url_in_language( $id, $type, $lang, $why );
			if ( '' !== $link ) {
				return $link;
			}
			// A product nobody has translated: the page that EXISTS. Inventing
			// an address in a language it was never written in is a 404, and
			// the shop was explicit — a missing translation falls back to the
			// original page.
			$why = 'not-translated';
			return $url;
		}
		// 3. Not a page of ours at all — the home page, a category, a listing.
		//    Those have the same address in every language, so the language's
		//    own shape is the whole answer.
		$moved = self::in_shape( $url, $lang );
		if ( '' !== $moved && $moved !== $url ) {
			$why = 'url-rule';
			return $moved;
		}
		global $sitepress;
		if ( is_object( $sitepress ) && method_exists( $sitepress, 'convert_url' ) ) {
			$conv = $sitepress->convert_url( $url, $lang );
			if ( is_string( $conv ) && '' !== $conv && $conv !== $url ) {
				$why = 'url-rule';
				return $conv;
			}
		}
		// 4. The documented filter, for the versions with no $sitepress.
		$conv = apply_filters( 'wpml_permalink', $url, $lang, true );
		if ( is_string( $conv ) && '' !== $conv && $conv !== $url ) {
			$why = 'filter';
			return $conv;
		}
		$why = $id > 0 ? 'not-translated' : 'no-page';
		return $url;
	}

	/**
	 * The post one of this shop's addresses points at, by SLUG.
	 *
	 * get_page_by_path() reads post_name in the posts table. It does not care
	 * how the permalinks are built, which rewrite rules are loaded, or whether
	 * this is a front-end request — and admin-ajax, where the emails are
	 * written, is not a front-end request. url_to_postid() cares about all
	 * three, which is why it answered 0 here.
	 *
	 * Products first: an email links to products. Then a page (its whole path,
	 * because pages nest), then a post.
	 */
	public static function post_of( string $url ): int {
		static $seen = [];
		$key = md5( $url );
		if ( array_key_exists( $key, $seen ) ) {
			return $seen[ $key ];
		}
		$path  = trim( (string) wp_parse_url( $url, PHP_URL_PATH ), '/' );
		$seen[ $key ] = 0;
		if ( '' === $path || ! function_exists( 'get_page_by_path' ) ) {
			return 0;
		}
		$parts = explode( '/', $path );
		$slug  = urldecode( (string) end( $parts ) );
		// Products first, then a page by its whole path (pages nest), then a
		// post — and finally EVERY public type the shop has, by that same
		// slug. The last one is what keeps a link out of the invention branch
		// below: a page this lookup does not recognise is treated as a listing
		// and gets the language's URL rule, which is how the right domain
		// ended up carrying the English slug. One extra query, asked once per
		// address and remembered.
		$tries = [ [ $slug, 'product' ], [ $path, 'page' ], [ $slug, 'post' ] ];
		if ( function_exists( 'get_post_types' ) ) {
			$all = array_values( (array) get_post_types( [ 'public' => true ], 'names' ) );
			if ( $all ) {
				$tries[] = [ $slug, $all ];
			}
		}
		foreach ( $tries as [ $what, $type ] ) {
			if ( '' === $what ) {
				continue;
			}
			$found = get_page_by_path( $what, OBJECT, $type );
			if ( $found && ! empty( $found->ID ) ) {
				$seen[ $key ] = (int) $found->ID;
				return $seen[ $key ];
			}
		}
		return 0;
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
