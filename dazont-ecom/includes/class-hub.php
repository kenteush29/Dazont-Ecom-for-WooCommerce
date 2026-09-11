<?php
defined( 'ABSPATH' ) || exit;

/**
 * The shell every Dazont screen is built from — the server half.
 *
 * "Je veux que cette méthode soit la seule méthode standardisée sur tout le
 * shop : une façon de faire, avec différentes fonctions en fonction du type de
 * post... la mise à jour doit être popularisée aussi sur les autres types de
 * post."
 *
 * A BLOCK IS ONE SHAPE, AND IT IS BUILT IN ONE PLACE. `admin/js/hub.js` builds
 * it for the screens that draw themselves in the browser; this builds the same
 * markup — same classes, same order, same switch — for the screens the server
 * prints. The behaviour behind both is the one machinery in hub.js, so a fix
 * to a block is a fix on every screen that has blocks.
 *
 * This class owns no data and hooks nothing: it is a shape, not a module.
 */
final class DZE_Hub {

	/**
	 * Open a block.
	 *
	 * @param string $id    What the block is, for the screen that remembers
	 *                      which ones were left open.
	 * @param string $title Its name, which is what the reader looks for.
	 * @param bool   $open  Open on arrival.
	 * @param array  $tick  The block's switch, or none:
	 *                      - [ 'all' => true ]  a take-all over the boxes below
	 *                      - [ 'id' => …, 'on' => bool, 'disabled' => bool, 'tip' => … ]
	 *                        the block's own switch.
	 *                      It lives in the HEADING: `countSec()` reads it there
	 *                      to say how many of the block's rows will run, and a
	 *                      switch left in the body made a block that was on
	 *                      read "0 / 2".
	 */
	public static function sec_open( string $id, string $title, bool $open = true, array $tick = [] ): void {
		$box = '';
		if ( $tick ) {
			$box = sprintf(
				'<label class="dze-sec-tick" title="%1$s"><input type="checkbox"%2$s%3$s%4$s%5$s /></label>',
				esc_attr( (string) ( $tick['tip'] ?? '' ) ),
				isset( $tick['id'] ) ? ' id="' . esc_attr( (string) $tick['id'] ) . '"' : '',
				! empty( $tick['all'] ) ? ' class="dze-sec-all"' : '',
				! empty( $tick['on'] ) ? ' checked' : '',
				! empty( $tick['disabled'] ) ? ' disabled' : ''
			);
		}
		printf(
			'<section class="dze-sec%1$s" data-sec="%2$s"><h3 class="dze-sec-head" role="button" tabindex="0" aria-expanded="%3$s"><span class="dze-sec-caret">%4$s</span>%7$s%5$s<span class="dze-sec-count"></span></h3><div class="dze-sec-body"%6$s>',
			$open ? ' is-open' : '',
			esc_attr( $id ),
			$open ? 'true' : 'false',
			$open ? '▾' : '▸',
			esc_html( $title ),
			$open ? '' : ' style="display:none;"',
			$box // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built escaped above.
		);
	}

	public static function sec_close(): void {
		echo '</div></section>';
	}

	/**
	 * THE OBJECT'S ID, ON EVERY LIST THAT NAMES OBJECTS.
	 *
	 * "Il manque l'ID produit sur ces pages ! Très important. Directive à
	 * suivre partout là où il y a ce genre d'écran bulk." A list of forty
	 * products with two called "Tactical Backpack 45L" is a list where the only
	 * way to tell them apart is to open both — and every other tool the shop
	 * uses to talk about a product (a URL, a SQL query, a supplier file, a
	 * message to somebody else) speaks in ids.
	 *
	 * It lives HERE, with the rest of the shape, because it goes on six screens
	 * and a badge written six times is six badges that drift. It is selectable
	 * text, never a link: the name beside it is already the way in, and a
	 * second link to the same place is a second thing to aim at.
	 *
	 * @param int $id 0 prints nothing — a row with no object behind it must not
	 *                claim "#0", which reads as an id somebody could look up.
	 */
	public static function obj_id( int $id ): string {
		return $id > 0
			? '<code class="dze-objid" title="' . esc_attr__( 'Its id — select it to copy', 'dazont-ecom' ) . '">#' . (int) $id . '</code>'
			: '';
	}

	/**
	 * The one script every screen with blocks is built on.
	 *
	 * Called by whoever draws them, so a screen that starts drawing blocks
	 * next year has nothing to remember — and a dependency that was never
	 * enqueued silently drops the script that needs it.
	 */
	public static function assets(): void {
		wp_enqueue_script( 'dze-hub', DZE_URL . 'admin/js/hub.js', [ 'jquery' ], DZE_VERSION, true );
	}
}
