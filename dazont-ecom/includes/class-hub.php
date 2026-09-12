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
	 * THE OBJECT'S ID, IN A COLUMN OF ITS OWN, ON EVERY LIST THAT NAMES
	 * OBJECTS.
	 *
	 * "Il manque l'ID produit sur ces pages ! Très important. Directive à
	 * suivre partout là où il y a ce genre d'écran bulk." Then: "L'id produit
	 * doit être dans une colonne dédiée !" — and he is right. Tucked beside the
	 * name it sits at a different place on every line, so finding one id means
	 * reading every title; in a column of its own the eye runs straight down
	 * it. Two products called "Tactical Backpack 45L" are told apart by nothing
	 * else, and every other tool the shop uses to talk about one — a URL, a SQL
	 * query, a supplier file, a message to somebody else — speaks in ids.
	 *
	 * The HEADING and the CELL come from here together, because a header
	 * declared in one place and cells in another is how a table ends up one
	 * column out of step, printing every value under the wrong title.
	 */
	public static function id_th(): string {
		return '<th class="dze-objid-th">' . esc_html__( 'ID', 'dazont-ecom' ) . '</th>';
	}

	/**
	 * The cell under that heading.
	 *
	 * @param int $id 0 prints an empty cell — never "#0", which reads as an id
	 *                somebody could look up.
	 */
	public static function id_td( int $id ): string {
		return '<td class="dze-objid-td">' . self::obj_id( $id ) . '</td>';
	}

	/**
	 * The id itself, for the one place that is not a table: the head of a
	 * screen showing a single object.
	 *
	 * Selectable text, never a link — the name beside it is already the way in,
	 * and a second link to the same place is a second thing to aim at.
	 */
	public static function obj_id( int $id ): string {
		return $id > 0
			? '<code class="dze-objid" title="' . esc_attr__( 'Its id — select it to copy', 'dazont-ecom' ) . '">' . (int) $id . '</code>'
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
