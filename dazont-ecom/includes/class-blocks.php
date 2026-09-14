<?php
/**
 * THE WORDPRESS BLOCK DOCUMENT CONTRACT — what a pass may not do to an article.
 *
 * Three articles on kula-tactical.com lost content in one day, and only the
 * first of them was the model's doing: "3 725 octets tronqués, coupure en plein
 * paragraphe". The other two were damaged AFTER every production guard had
 * passed the text — on the way back from the review popup's own visual editor,
 * which runs `wpautop` over whatever it is handed and wraps each `<!-- wp: -->`
 * delimiter in a paragraph. "Blocs image invalides, images absentes du corps de
 * l'article."
 *
 * It lives in a file of its own, with no dependency on anything, for two
 * reasons: the rule is about WordPress and not about categories or links, and
 * every gate that exercises a writer can load it whole rather than stub the one
 * answer it is there to test.
 *
 * @package Dazont_Ecom
 */

defined( 'ABSPATH' ) || exit;

final class DZE_Blocks {

	/**
	 * WOULD THIS WRITE DAMAGE A WORDPRESS BLOCK DOCUMENT? Asked where the text
	 * is produced, and asked AGAIN where it is written.
	 *
	 * Three articles lost content on this shop in one day, and only the first
	 * of them was the model's doing. The other two were damaged AFTER every
	 * production guard had passed them — on the way back from the review
	 * popup's own visual editor, which runs `wpautop` over whatever it is
	 * handed and wraps every block delimiter in a paragraph. A guard that lives
	 * only where the text is made protects the automatic pass and nothing else,
	 * so this is the last thing the write does.
	 *
	 * Two kinds of damage, and they are the only two a linking pass can do:
	 *
	 * - a delimiter GONE, by name and position — which is what a truncated
	 *   answer looks like, the last quarter of an article and its blocks with
	 *   it;
	 * - a delimiter WRAPPED — `<p><!-- wp:paragraph --></p>`, which takes
	 *   nothing away and breaks everything.
	 *
	 * A text carrying no delimiters at all is not a block document and is not
	 * held to any of this: a category description is a paragraph or two of
	 * plain HTML. And a document that ALREADY carries wrapped delimiters is
	 * asked only whether this write makes it worse — the question is never
	 * "is this document perfect", which nothing here could mend anyway.
	 *
	 * @return string '' when the write is safe, else what is wrong with it, in
	 *                words a person reads on the row.
	 */
	public static function damage( string $before, string $after ): string {
		$was = self::names_in( $before );
		if ( ! $was ) {
			return '';
		}
		$now = self::names_in( $after );
		foreach ( $was as $key => $ignored ) {
			if ( isset( $now[ $key ] ) ) {
				continue;
			}
			// The key is "<position> <block name>", so this catches a block
			// removed, a block reordered and a document cut short alike.
			$name = trim( (string) preg_replace( '/^\d+\s*/', '', (string) $key ) );
			return sprintf(
				/* translators: %s: the name of the WordPress block that went missing */
				__( 'its WordPress blocks broken (%s is gone)', 'dazont-ecom' ),
				$name
			);
		}
		if ( self::blocks_wrapped( $after ) > self::blocks_wrapped( $before ) ) {
			return __( 'its WordPress block delimiters wrapped in paragraph tags, which breaks every block in the editor', 'dazont-ecom' );
		}
		return '';
	}

	/**
	 * How many block delimiters are sitting INSIDE a paragraph.
	 *
	 * In a document WordPress itself wrote this is nought: a paragraph block is
	 * `<!-- wp:paragraph -->` and THEN its `<p>`, never the other way round.
	 * `wpautop` — which every visual editor runs on what it is handed — puts
	 * one on each side, and a dissociated pass leaves the halves orphaned, an
	 * opening tag before the delimiter and a closing one after it. Both shapes
	 * are counted, because both arrived on this shop in the same night.
	 */
	private static function blocks_wrapped( string $html ): int {
		$n  = (int) preg_match_all( '#<p\b[^>]*>\s*<!--\s*/?wp:#i', $html );
		$n += (int) preg_match_all( '#<!--\s*/?wp:.*?-->\s*</p>#is', $html );
		return $n;
	}


	/**
	 * The block delimiters of a document, by name AND position.
	 *
	 * Keyed "<position> <name>", so a block removed, a block reordered and a
	 * document cut short are all one question. The JSON a delimiter carries is
	 * deliberately not part of it: `wp_kses_post()` collapses runs of dashes
	 * inside a comment, so `{"className":"card--wide"}` comes back `card-wide`
	 * through the shop's own sanitiser, and refusing that would be the plugin
	 * refusing its own work. A block NAME never carries a dash pair.
	 */
	private static function names_in( string $html ): array {
		$out = [];
		if ( preg_match_all( '/<!--\s*(\/?wp:[a-z0-9\/-]+)/i', $html, $m ) ) {
			foreach ( (array) $m[1] as $i => $name ) {
				$out[ $i . ' ' . strtolower( (string) $name ) ] = 1;
			}
		}
		return $out;
	}
}
