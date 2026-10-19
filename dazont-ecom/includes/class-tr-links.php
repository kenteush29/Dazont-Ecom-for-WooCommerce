<?php
/**
 * THE LINKS OF AN ORIGINAL, CARRIED INTO ITS TRANSLATIONS — one paragraph at a time.
 *
 * The linking pass works in the shop's main language. Every link it adds to an
 * English text used to reach the translations one way only: the whole text was
 * sent again to translation, paid in full, over translations made by hand.
 * Since 4.517.0 a link no longer does that — and so it no longer reaches the
 * translations at all: 12,888 links missing from Kula's, 4,374 from Jute's,
 * 2,421 from Kilim's (19/10/2026).
 *
 * WPML's « translate link targets » does not fill that gap: it points a link
 * that is already in a translation at the page of that language, and never adds
 * one that is not. So this does the other half, the way the shop asked for it:
 * « modifier seulement le segment de texte impacté […] il faut faire très
 * attention à ce que le texte ait toujours du sens. »
 *
 *   - The text is cut into paragraphs. A translation keeps its original's
 *     paragraphs one for one (97 % of Kula's, 95 % of Jute's, 89 % of
 *     Kilim's); one that does not is left alone, never guessed at.
 *   - For each paragraph, the links of the English one are pointed at the
 *     pages of the translation's language (DZE_Translate::relink()). Those
 *     the translation carries nowhere are the gap.
 *   - Only the paragraphs with a gap are sent, beside their English
 *     counterpart, and the model answers with EDITS — the words to link, or
 *     one sentence reworded — never with the text. The edits go through the
 *     same guards as the English pass (DZE_Category_Content::apply_edits()):
 *     the anchor names its destination in that language, a reworded sentence
 *     keeps most of its words, a link or a bold run stays as it was. Anything
 *     else is refused and the paragraph is left exactly as it was.
 *   - Nothing outside those paragraphs is ever touched.
 */

defined( 'ABSPATH' ) || exit;

final class DZE_Tr_Links {

	/** The block elements a text is cut into. */
	private const BLOCK_TAGS = 'p|h[1-6]|li|blockquote|td|th|figcaption';

	/** How many paragraphs one call carries, at most. */
	public const PER_CALL = 4;

	/**
	 * HOW LONG A TRANSLATED PARAGRAPH MAY BE, against its English one.
	 *
	 * A reworded sentence of a translation often has to grow: the English one
	 * grew when the link was added to it — « … — pair it with military
	 * sleeping bags for night missions » — and the translation, made before,
	 * lacks that clause. Measured on twenty Kula translations (19/10/2026), the
	 * paragraphs stood at 0.6–0.8 of their English one before and 1.05–1.3
	 * after: back in line with the language. What the English does not say is
	 * what pushes a paragraph past 1.4 of it, and that is refused.
	 */
	public const RATIO_MAX = 1.4;

	/** Characters allowed over that ratio, for a short paragraph. */
	public const RATIO_MARGIN = 40;

	/** Where a translation keeps the text this pass replaced, for an undo. */
	public const PREV_META = '_dze_trl_prev';

	/** What the English text was the last time a translation was looked at, and when. */
	public const SEEN_META = '_dze_trl_seen';

	/** The shop's switch and its daily figure. */
	public const OPT = 'dze_trl';

	/** Where the pass keeps its place in the list, and today's count. */
	public const STATE = 'dze_trl_state';

	/** One pass at a time. */
	private const LOCK = 'dze_trl_lock';

	/** The originals to go through, kept for an hour. */
	private const LIST = 'dze_trl_list';

	/** Paid answers per minute, at most, and translations looked at per minute. */
	private const PER_TICK  = 2;
	private const SCAN_TICK = 40;

	/** @return array{on:bool,per_day:int} */
	public static function conf(): array {
		$c = get_option( self::OPT );
		$c = is_array( $c ) ? $c : [];
		return [
			'on'      => ! empty( $c['on'] ),
			'per_day' => max( 1, min( 2000, (int) ( $c['per_day'] ?? 200 ) ) ),
		];
	}

	/**
	 * THE ORIGINALS WORTH LOOKING AT: categories and articles in the shop's
	 * main language whose text carries a link. Read from WPML's table, which
	 * answers the same in cron as anywhere (DZE_Wpml::ids_in_language()).
	 *
	 * @return array<int,array{kind:string,id:int,type:string}>
	 */
	public static function originals(): array {
		$cached = get_transient( self::LIST );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		global $wpdb;
		$def = class_exists( 'DZE_Wpml' ) ? DZE_Wpml::default_language() : '';
		if ( '' === $def ) {
			return [];
		}
		$out   = [];
		$en_tt = DZE_Wpml::ids_in_language( 'tax_product_cat', $def ) ?? [];
		foreach ( (array) $wpdb->get_results( "SELECT term_id, term_taxonomy_id FROM {$wpdb->term_taxonomy} WHERE taxonomy = 'product_cat' AND description LIKE '%<a %' ORDER BY term_id", ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( isset( $en_tt[ (int) $r['term_taxonomy_id'] ] ) ) {
				$out[] = [ 'kind' => 'term', 'id' => (int) $r['term_id'], 'type' => 'product_cat' ];
			}
		}
		$en_posts = DZE_Wpml::ids_in_language( 'post_post', $def ) ?? [];
		foreach ( (array) $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'post' AND post_status = 'publish' AND post_content LIKE '%<a %' ORDER BY ID" ) as $id ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( isset( $en_posts[ (int) $id ] ) ) {
				$out[] = [ 'kind' => 'post', 'id' => (int) $id, 'type' => 'post' ];
			}
		}
		set_transient( self::LIST, $out, HOUR_IN_SECONDS );
		return $out;
	}

	private static function seen_get( array $o, int $target ): array {
		$raw = 'term' === $o['kind'] ? get_term_meta( $target, self::SEEN_META, true ) : get_post_meta( $target, self::SEEN_META, true );
		$v   = is_string( $raw ) ? json_decode( $raw, true ) : $raw;
		return is_array( $v ) ? $v : [];
	}

	private static function seen_set( array $o, int $target, array $v ): void {
		$json = wp_slash( (string) wp_json_encode( $v ) );
		if ( 'term' === $o['kind'] ) {
			update_term_meta( $target, self::SEEN_META, $json );
		} else {
			update_post_meta( $target, self::SEEN_META, $json );
		}
	}

	/**
	 * ONE MINUTE OF WORK, from the automation's tick.
	 *
	 * A translation is looked at again only once its ORIGINAL has changed:
	 * what was refused stays refused until the English moves, so nothing is
	 * paid for twice. A new link in the English is exactly such a change —
	 * which is how the links of tomorrow reach the translations too.
	 *
	 * @return array{looked:int,asked:int,placed:int}
	 */
	public static function step(): array {
		$out  = [ 'looked' => 0, 'asked' => 0, 'placed' => 0 ];
		$conf = self::conf();
		if ( ! $conf['on'] || ! class_exists( 'DZE_Wpml' ) || ! DZE_Wpml::is_active() || get_transient( self::LOCK ) ) {
			return $out;
		}
		if ( class_exists( 'DZE_Ai_Usage' ) && DZE_Ai_Usage::over_budget() ) {
			return $out;
		}
		$state = get_option( self::STATE );
		$state = is_array( $state ) ? $state : [];
		$today = current_time( 'Y-m-d' );
		if ( ( $state['day'] ?? '' ) !== $today ) {
			$state['day']    = $today;
			$state['asked']  = 0;
			$state['placed'] = 0;
		}
		if ( (int) $state['asked'] >= $conf['per_day'] ) {
			return $out;
		}
		$list = self::originals();
		if ( ! $list ) {
			return $out;
		}
		set_transient( self::LOCK, 1, 5 * MINUTE_IN_SECONDS );
		try {
			$langs  = array_values( array_diff( DZE_Wpml::language_codes(), [ DZE_Wpml::default_language() ] ) );
			$cursor = (int) ( $state['cursor'] ?? 0 );
			$total  = count( $list ) * max( 1, count( $langs ) );
			for ( $i = 0; $i < min( self::SCAN_TICK, $total ); $i++ ) {
				if ( $out['asked'] >= self::PER_TICK || (int) $state['asked'] + $out['asked'] >= $conf['per_day'] ) {
					break;
				}
				$at     = ( $cursor + $i ) % $total;
				$o      = $list[ intdiv( $at, count( $langs ) ) ];
				$lang   = (string) $langs[ $at % count( $langs ) ];
				$target = DZE_Translate::obj_translation( $o, $lang );
				$out['looked']++;
				if ( ! $target ) {
					continue;
				}
				$sum  = md5( self::text( $o, (int) $o['id'] ) );
				$seen = self::seen_get( $o, $target );
				if ( ( $seen['src'] ?? '' ) === $sum ) {
					continue; // looked at since the English last changed.
				}
				$g = self::gaps( $o, $lang );
				if ( 'gaps' !== $g['state'] ) {
					// Nothing to ask: written only if a repointed link changed it.
					$r = self::carry( $o, $lang, true );
					self::seen_set( $o, $target, [ 'src' => $sum, 't' => time(), 'state' => $r['state'] ] );
					continue;
				}
				$r = self::carry( $o, $lang, true );
				$out['asked']++;
				$out['placed'] += (int) $r['placed'];
				self::seen_set( $o, $target, [ 'src' => $sum, 't' => time(), 'state' => $r['state'], 'placed' => (int) $r['placed'], 'refused' => count( $r['refused'] ) ] );
			}
			$state['cursor'] = ( $cursor + $out['looked'] ) % max( 1, $total );
			$state['asked']  = (int) $state['asked'] + $out['asked'];
			$state['placed'] = (int) $state['placed'] + $out['placed'];
			$state['last']   = time();
			update_option( self::STATE, $state, false );
		} finally {
			delete_transient( self::LOCK );
		}
		return $out;
	}

	/**
	 * The paragraphs of a text, each with its place in it — the innermost
	 * ones: a paragraph inside a list item is the paragraph, not the item.
	 *
	 * An English article on Kula is full of `<li><p>…</p></li>`: read as whole
	 * blocks, 120 of its translations could not be lined up at all. Only the
	 * blocks that hold no other block are taken, on both sides, so the two
	 * lists still compare one for one, and a splice never crosses an element.
	 *
	 * @return array<int,array{tag:string,at:int,len:int,html:string}>|null
	 *         null when a block is left open — HTML that cannot be cut safely.
	 */
	public static function blocks( string $html ): ?array {
		$t  = self::BLOCK_TAGS;
		$re = '~<(' . $t . ')\b[^>]*>((?:(?!<(?:' . $t . ')\b).)*?)</\1\s*>~is';
		if ( ! preg_match_all( $re, $html, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
			return [];
		}
		$out = [];
		foreach ( $m as $one ) {
			$out[] = [
				'tag'  => strtolower( (string) $one[1][0] ),
				'at'   => (int) $one[0][1],
				'len'  => strlen( (string) $one[0][0] ),
				'html' => (string) $one[0][0],
			];
		}
		// Every block that opens must close somewhere: count the openings and
		// the closings of each kind.
		foreach ( explode( '|', 'p|li|blockquote|td|th|figcaption|h1|h2|h3|h4|h5|h6' ) as $tag ) {
			$open  = (int) preg_match_all( '~<' . $tag . '\b~i', $html );
			$close = (int) preg_match_all( '~</' . $tag . '\s*>~i', $html );
			if ( $open !== $close ) {
				return null;
			}
		}
		return $out;
	}

	/** An address, in the one spelling links are compared under. */
	public static function key( string $url ): string {
		$url = strtolower( (string) strtok( html_entity_decode( trim( $url ) ), '#?' ) );
		return untrailingslashit( (string) preg_replace( '~^https?://(www\.)?~', '', $url ) );
	}

	/** @return string[] The addresses a piece of HTML links to, as written. */
	private static function hrefs( string $html ): array {
		return preg_match_all( '~<a\b[^>]*\bhref="([^"]+)"~i', $html, $m ) ? array_map( 'strval', $m[1] ) : [];
	}

	/**
	 * The field this pass carries links into, and whether this object has one.
	 *
	 * A category's description, an article's text. A page built with Elementor
	 * keeps its words in its widgets, and its post_content is a rendered copy
	 * that is never edited (see the trap about flattened copies): left alone.
	 */
	public static function field( array $o ): string {
		if ( 'term' === ( $o['kind'] ?? '' ) ) {
			return 'product_cat' === (string) ( $o['type'] ?? '' ) ? 'description' : '';
		}
		$id = (int) ( $o['id'] ?? 0 );
		if ( 'post' !== get_post_type( $id ) ) {
			return '';
		}
		return 'builder' === (string) get_post_meta( $id, '_elementor_edit_mode', true ) ? '' : 'content';
	}

	/** The text of that field, read from the tables. */
	public static function text( array $o, int $id ): string {
		if ( 'term' === ( $o['kind'] ?? '' ) ) {
			return (string) ( DZE_Category_Content::term_row( $id, (string) $o['type'] )['description'] ?? '' );
		}
		return (string) get_post_field( 'post_content', $id );
	}

	/**
	 * The page a link points at, by its name in a language and its address
	 * there — or null when it has no page of its own in that language.
	 *
	 * @return array{url:string,label:string,kind:string}|null
	 */
	private static function target_in( string $url, string $lang ): ?array {
		static $memo = [];
		$k = $lang . '|' . self::key( $url );
		if ( array_key_exists( $k, $memo ) ) {
			return $memo[ $k ];
		}
		$memo[ $k ] = null;
		$at = DZE_Translate::object_at( $url );
		if ( ! $at ) {
			return null;
		}
		[ $kind, $id ] = $at;
		$type = 'post' === $kind ? (string) ( get_post_type( $id ) ?: 'post' ) : 'product_cat';
		$to   = DZE_Translate::obj_translation( [ 'kind' => $kind, 'id' => $id, 'type' => $type ], $lang );
		if ( ! $to ) {
			return null; // no page of its own in that language: nothing to link to.
		}
		// The address of that page, worked out in ITS language (relink()).
		$moved = DZE_Translate::relink( '<a href="' . esc_attr( $url ) . '">x</a>', $lang );
		$new   = self::hrefs( $moved )[0] ?? '';
		if ( '' === $new || self::key( $new ) === self::key( $url ) ) {
			return null;
		}
		$label = 'post' === $kind
			? (string) get_post_field( 'post_title', $to )
			: (string) ( DZE_Category_Content::term_row( $to, $type )['name'] ?? '' );
		$label = trim( html_entity_decode( wp_strip_all_tags( $label ), ENT_QUOTES, 'UTF-8' ) );
		if ( '' === $label ) {
			return null;
		}
		$memo[ $k ] = [
			'url'   => html_entity_decode( $new ),
			'label' => $label,
			'kind'  => 'post' === $kind ? ( 'post' === $type ? 'article' : $type ) : 'category',
		];
		return $memo[ $k ];
	}

	/**
	 * WHAT ONE TRANSLATION LACKS.
	 *
	 * @return array{state:string,target:int,lang:string,text:string,html:string,blocks:array<int,array{i:int,en:string,tr:string,links:array<int,array{url:string,label:string,kind:string}>,before:string,after:string}>}
	 *         state: 'complete' (nothing missing), 'gaps', or why nothing can be
	 *         done — 'none' (no translation), 'empty', 'unaligned', 'owed',
	 *         'no field'.
	 */
	public static function gaps( array $o, string $lang ): array {
		$out = [ 'state' => 'none', 'target' => 0, 'lang' => $lang, 'text' => '', 'html' => '', 'blocks' => [] ];
		$fid = self::field( $o );
		if ( '' === $fid ) {
			$out['state'] = 'no field';
			return $out;
		}
		$target = DZE_Translate::obj_translation( $o, $lang );
		if ( ! $target ) {
			return $out;
		}
		$out['target'] = $target;
		$en = self::text( $o, (int) $o['id'] );
		$tr = self::text( $o, $target );
		if ( '' === trim( wp_strip_all_tags( $tr ) ) || '' === trim( wp_strip_all_tags( $en ) ) ) {
			$out['state'] = 'empty';
			return $out;
		}
		// A TRANSLATION THAT OWES THIS FIELD IS RETRANSLATED WHOLE, and the
		// links come with it: carrying them first would be paid twice. One
		// made elsewhere has no register to say so, and is not judged owed.
		if ( DZE_Translate::src_map( $target, $o ) && isset( DZE_Translate::obj_stale( $o, $lang )[ $fid ] ) ) {
			$out['state'] = 'owed';
			return $out;
		}
		// The translation's own links pointed at its language first: free,
		// and a link to the English page is not « missing » once repointed.
		$tr_now = DZE_Translate::relink( $tr, $lang );
		$out['html'] = $tr_now;
		$out['text'] = $tr;
		$eb = self::blocks( $en );
		$tb = self::blocks( $tr_now );
		if ( null === $eb || null === $tb || ! $eb || array_column( $eb, 'tag' ) !== array_column( $tb, 'tag' ) ) {
			$out['state'] = 'unaligned';
			return $out;
		}
		$have = array_map( [ self::class, 'key' ], self::hrefs( $tr_now ) );
		$self = 'term' === $o['kind'] ? get_term_link( $target, (string) $o['type'] ) : get_permalink( $target );
		$self = is_string( $self ) ? self::key( $self ) : '';
		$seen = [];
		foreach ( $eb as $i => $block ) {
			$links = [];
			preg_match_all( '~<a\b[^>]*\bhref="([^"]+)"[^>]*>(.*?)</a>~is', $block['html'], $am, PREG_SET_ORDER );
			foreach ( $am as $a ) {
				$t = self::target_in( (string) $a[1], $lang );
				if ( ! $t ) {
					continue;
				}
				$k = self::key( $t['url'] );
				if ( $k === $self || isset( $seen[ $k ] ) || in_array( $k, $have, true ) ) {
					continue;
				}
				$seen[ $k ] = true;
				// THE WORDS THE ENGLISH LINKS, beside the destination's name:
				// the translation links the words that translate them.
				$t['en']    = trim( html_entity_decode( wp_strip_all_tags( (string) $a[2] ), ENT_QUOTES, 'UTF-8' ) );
				$links[]    = $t;
			}
			if ( ! $links ) {
				continue;
			}
			$plain = static fn( string $h ): string => trim( (string) preg_replace( '~\s+~u', ' ', html_entity_decode( wp_strip_all_tags( $h ), ENT_QUOTES, 'UTF-8' ) ) );
			$out['blocks'][] = [
				'i'      => (int) $i,
				'en'     => $block['html'],
				'tr'     => $tb[ $i ]['html'],
				'links'  => $links,
				'before' => isset( $tb[ $i - 1 ] ) ? mb_substr( $plain( $tb[ $i - 1 ]['html'] ), -300 ) : '',
				'after'  => isset( $tb[ $i + 1 ] ) ? mb_substr( $plain( $tb[ $i + 1 ]['html'] ), 0, 300 ) : '',
			];
		}
		$out['state'] = $out['blocks'] ? 'gaps' : 'complete';
		return $out;
	}

	/** An English paragraph as the model reads it: its links written out, nothing else of the markup. */
	private static function show_links( string $html ): string {
		$html = (string) preg_replace_callback(
			'~<a\b[^>]*\bhref="([^"]+)"[^>]*>(.*?)</a>~is',
			static fn( array $m ): string => '[' . wp_strip_all_tags( $m[2] ) . '](' . html_entity_decode( $m[1] ) . ')',
			$html
		);
		return trim( (string) preg_replace( '~\s+~u', ' ', html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES, 'UTF-8' ) ) );
	}

	/**
	 * CARRIES THE MISSING LINKS INTO ONE TRANSLATION.
	 *
	 * @param bool $write False shows what would be written and writes nothing.
	 * @return array{state:string,target:int,placed:int,refused:string[],blocks:array<int,array{en:string,before:string,after:string,placed:int}>,html:string,written:bool}
	 */
	public static function carry( array $o, string $lang, bool $write = false ): array {
		$g   = self::gaps( $o, $lang );
		$out = [ 'state' => $g['state'], 'target' => $g['target'], 'placed' => 0, 'refused' => [], 'blocks' => [], 'html' => $g['html'], 'written' => false ];
		if ( 'gaps' !== $g['state'] ) {
			// A translation whose links only needed repointing is still worth
			// writing: that costs nothing.
			if ( $write && 'complete' === $g['state'] && '' !== $g['html'] && $g['html'] !== $g['text'] ) {
				$out['written'] = self::write( $o, (int) $g['target'], $g['text'], $g['html'] );
			}
			return $out;
		}
		$language = DZE_Category_Content::lang_name( $lang );
		$subject  = 'term' === $o['kind']
			? (string) ( DZE_Category_Content::term_row( (int) $g['target'], (string) $o['type'] )['name'] ?? '' )
			: (string) get_post_field( 'post_title', (int) $g['target'] );
		$html = $g['html'];
		$new  = [];
		foreach ( array_chunk( $g['blocks'], self::PER_CALL ) as $chunk ) {
			try {
				$picks = self::ask( $chunk, $language, $subject );
			} catch ( \Throwable $e ) {
				// One answer that did not come back costs those paragraphs, not
				// the others: they stay exactly as they were.
				$out['refused'][] = $e->getMessage();
				$picks            = [];
			}
			foreach ( $chunk as $n => $b ) {
				$mine = array_values( array_filter( $picks, static fn( $e ): bool => is_array( $e ) && (int) ( $e['block'] ?? 0 ) === $n + 1 ) );
				$allowed = [];
				$labels  = [];
				$canon   = [];
				foreach ( $b['links'] as $l ) {
					$u             = untrailingslashit( $l['url'] );
					$allowed[]     = $u;
					$labels[ $u ]  = $l['label'];
					$canon[ $u ]   = $l['url'];
				}
				$res = [ 'html' => $b['tr'], 'applied' => 0, 'refused' => [] ];
				if ( $mine ) {
					$was_lenient = DZE_Category_Content::$names_lenient;
					DZE_Category_Content::$names_lenient = true;
					try {
						$res = DZE_Category_Content::apply_edits( $b['tr'], $mine, $allowed, $labels, $canon );
					} finally {
						DZE_Category_Content::$names_lenient = $was_lenient;
					}
					// NOTHING THE ENGLISH DOES NOT SAY: a paragraph grown past its
					// English one is refused whole, and stays as it was.
					$plain = static fn( string $h ): int => mb_strlen( trim( (string) preg_replace( '~\s+~u', ' ', html_entity_decode( wp_strip_all_tags( $h ), ENT_QUOTES, 'UTF-8' ) ) ) );
					if ( (int) $res['applied'] > 0 && $plain( (string) $res['html'] ) > self::RATIO_MAX * $plain( $b['en'] ) + self::RATIO_MARGIN ) {
						$res['refused'][] = sprintf( 'the paragraph would say more than the English one (%d characters against %d)', $plain( (string) $res['html'] ), $plain( $b['en'] ) );
						$res = [ 'html' => $b['tr'], 'applied' => 0, 'refused' => $res['refused'] ];
					}
				}
				foreach ( (array) $res['refused'] as $why ) {
					$out['refused'][] = (string) $why;
				}
				$out['placed'] += (int) $res['applied'];
				$out['blocks'][] = [ 'en' => $b['en'], 'before' => $b['tr'], 'after' => (string) $res['html'], 'placed' => (int) $res['applied'] ];
				if ( (int) $res['applied'] > 0 && (string) $res['html'] !== $b['tr'] ) {
					$new[ $b['i'] ] = (string) $res['html'];
				}
			}
		}
		if ( $new ) {
			// From the last paragraph to the first, so the places of those
			// still to come do not move.
			$tb = self::blocks( $html ) ?? [];
			krsort( $new );
			foreach ( $new as $i => $block ) {
				if ( isset( $tb[ $i ] ) ) {
					$html = substr_replace( $html, $block, $tb[ $i ]['at'], $tb[ $i ]['len'] );
				}
			}
		}
		$out['html']  = $html;
		$out['state'] = $out['placed'] ? 'carried' : 'refused';
		if ( $write && $html !== $g['text'] ) {
			$out['written'] = self::write( $o, (int) $g['target'], $g['text'], $html );
		}
		return $out;
	}

	/**
	 * The question, for a few paragraphs of one translation.
	 *
	 * @param array<int,array> $chunk
	 * @return array<int,array<string,mixed>> The edits, each naming its paragraph.
	 */
	private static function ask( array $chunk, string $language, string $subject ): array {
		if ( ! class_exists( 'DZE_Marketing_Ai' ) ) {
			return [];
		}
		$parts = [];
		foreach ( $chunk as $n => $b ) {
			$lines = [];
			foreach ( $b['links'] as $l ) {
				$lines[] = '- in English, the words «' . (string) ( $l['en'] ?? '' ) . '» link to: ' . $l['label'] . ' [' . $l['kind'] . '] → ' . $l['url'];
			}
			$parts[] = '[' . ( $n + 1 ) . "]\n"
				. ( '' !== $b['before'] ? '(paragraph before, for reading only: ' . $b['before'] . ")\n" : '' )
				. 'ENGLISH: ' . self::show_links( $b['en'] ) . "\n"
				. strtoupper( $language ) . ' (HTML — read it, do NOT return it): ' . $b['tr'] . "\n"
				. ( '' !== $b['after'] ? '(paragraph after, for reading only: ' . $b['after'] . ")\n" : '' )
				. "LINKS TO ADD TO THIS PARAGRAPH (use these addresses ONLY):\n" . implode( "\n", $lines );
		}
		$system = 'You keep the ' . $language . ' translation of a shop page in step with its English original. '
			. 'Each English paragraph below carries internal links that its translation lacks. You add the same links to the translated paragraph, in the sentence that matches the English one, changing as little as possible. '
			. 'The translation must still read naturally in ' . $language . ' and say exactly what the English says: you never add information and never drop any.';
		$user = '--- PAGE ---' . "\n" . $subject . "\n\n--- PARAGRAPHS ---\n" . implode( "\n\n", $parts ) . "\n\n"
			. "--- INSTRUCTIONS ---\n"
			. '- THE ANCHOR is the words of the ' . $language . ' paragraph that translate the English linked words — in the sentence that translates the English sentence carrying the link. It must name the destination: at least two words of its name in ' . $language . ' (one for a one-word name). At most 9 words, never cut in the middle of a phrase, never a whole sentence.' . "\n"
			. '- If those words are already in the paragraph, exactly once: {"block": n, "anchor": "…", "url": "…"} — "anchor" copied exactly as the paragraph writes it. This is the best answer: nothing is reworded.' . "\n"
			. '- Only if they are not: update that ONE sentence so it carries them: {"block": n, "find": "the sentence, copied word for word from the paragraph", "replace": "the same sentence, carrying the anchor", "anchor": "…", "url": "…"}.' . "\n"
			. '- THE TRANSLATION MAY BE OLDER THAN THE ENGLISH: the English sentence may have been reworded when its link was added. Then "replace" says what the English sentence says NOW — and nothing more — keeping the existing ' . $language . ' wording wherever the meaning has not changed. Never add anything the English sentence does not say: a paragraph that comes back saying more than its English one is refused.' . "\n"
			. '- "find" and "replace" are plain text: no tags, no entities, no markdown. Words of that sentence that are already a link or in bold stay word for word in "replace", and the anchor never goes inside them.' . "\n"
			. '- If a link has no natural place in the paragraph, leave it out.' . "\n"
			. '- "block" is the number of the paragraph, "url" one address from its list, copied verbatim.' . "\n"
			. 'OUTPUT: a JSON array and nothing else. If no link belongs anywhere, answer []';
		// ROOM FOR THE ANSWER, sized on the text: a sentence comes back twice
		// (find, replace), and Cyrillic or Polish costs more tokens a word —
		// four Russian answers were cut off with a budget per paragraph.
		$size = 0;
		foreach ( $chunk as $b ) {
			$size += mb_strlen( wp_strip_all_tags( (string) $b['tr'] ) );
		}
		$room = (int) min( 8000, 600 + 1.5 * $size );
		DZE_Ai_Usage::unit( 'tr_links' );
		try {
			$raw = (string) DZE_Marketing_Ai::complete( $system, $user, '', $room, 120 );
		} finally {
			DZE_Ai_Usage::unit();
		}
		$picks = DZE_Category_Content::read_picks( trim( (string) preg_replace( '/^```(?:json)?|```$/m', '', $raw ) ) );
		return is_array( $picks ) ? $picks : [];
	}

	/**
	 * Writes the translation's field — in its column, with no save hooks.
	 *
	 * A save of a translation is when WPML copies onto it what it is set to
	 * copy from the original (see the trap about WPML copying on save); a
	 * category goes column by column for the same reason (write_description()).
	 * What was there is kept for an undo.
	 */
	private static function write( array $o, int $target, string $was, string $html ): bool {
		global $wpdb;
		if ( 'term' === $o['kind'] ) {
			$ok = class_exists( 'DZE_Queue' ) && DZE_Queue::write_description( $target, $html );
			if ( $ok ) {
				update_term_meta( $target, self::PREV_META, wp_slash( (string) wp_json_encode( [ 't' => time(), 'html' => $was ] ) ) );
				$link = get_term_link( $target, (string) $o['type'] );
				if ( is_string( $link ) ) {
					do_action( 'litespeed_purge_url', $link );
				}
			}
			return $ok;
		}
		$ok = false !== $wpdb->update( $wpdb->posts, [ 'post_content' => $html ], [ 'ID' => $target ] );
		if ( $ok ) {
			clean_post_cache( $target );
			update_post_meta( $target, self::PREV_META, wp_slash( (string) wp_json_encode( [ 't' => time(), 'html' => $was ] ) ) );
			do_action( 'litespeed_purge_post', $target );
		}
		return $ok;
	}
}
