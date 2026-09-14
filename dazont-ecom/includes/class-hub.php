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
	/**
	 * THE FEATURED IMAGE, as a column of a list.
	 *
	 * "Sur la liste des diagnostics il faut l'image featured. Peu importe le
	 * type de post." Nine hundred lines of text is a list read one name at a
	 * time, and half of what these screens are about is pictures.
	 *
	 * No word over it: a column of photographs needs no heading, and "Image"
	 * above a thumbnail is a label saying what is already visible. It keeps a
	 * class so the heading and the cell can be asserted together, in position —
	 * a head declared in one file and cells built in another go a column out of
	 * step without raising anything.
	 */
	/**
	 * THE WAY TO THE PAGE AS A VISITOR SEES IT.
	 *
	 * "Aucun bouton pour voir la page côté utilisateur, il manque le petit
	 * symbole qui devrait rediriger on site." Every list in this plugin names
	 * an object and links it to its EDITOR, which is right — and after
	 * accepting a text written onto a page, the thing you actually want is to
	 * look at the page.
	 *
	 * Three rules: it opens in a NEW TAB, so nothing open here is lost; an
	 * object with no address gets nothing rather than a link to "#", which is
	 * a control that cannot act; and it is a symbol with a word on hover,
	 * because a row already carries a name and a second worded link beside it
	 * is two things to aim at.
	 */
	/**
	 * AN OBJECT'S NAME ON A ROW — and the two ways to it, which are never
	 * separated again.
	 *
	 * "To review, tu as oublié le bouton lien pour aller voir la page on site.
	 * Ça devrait être automatique de ta part toujours pour l'UX ou l'UI."
	 *
	 * It was: the symbol went onto Past work, then onto Next in line, and was
	 * forgotten on the one list where somebody is actually deciding — because
	 * each of those three built the same markup by hand. A rule that has to be
	 * remembered on every new list is a rule that will be missed on one of
	 * them. This is the only way a name is printed now, so the way to the page
	 * arrives with it and a list written next year needs to know nothing.
	 *
	 * @param string $name The object's own name, unescaped.
	 * @param string $edit Where it is CHANGED — empty prints plain text rather
	 *                     than a link to nowhere.
	 * @param string $view Where a READER sees it — empty prints no symbol,
	 *                     never one pointing at "#".
	 */
	public static function named( string $name, string $edit, string $view = '' ): string {
		$said = esc_html( $name );
		if ( '' !== trim( $edit ) ) {
			$said = '<a href="' . esc_url( $edit ) . '">' . $said . '</a>';
		}
		return $said . self::visit_link( $view );
	}

	/** The word on that symbol, in one place: the browser lists print it too. */
	public static function visit_word(): string {
		return __( 'See it on the site', 'dazont-ecom' );
	}

	public static function visit_link( string $url ): string {
		if ( '' === trim( $url ) ) {
			return '';
		}
		return sprintf(
			' <a class="dze-hub-visit" href="%1$s" target="_blank" rel="noopener" title="%2$s"'
				. ' aria-label="%2$s"><span class="dashicons dashicons-external"></span></a>',
			esc_url( $url ),
			esc_attr__( 'See it on the site', 'dazont-ecom' )
		);
	}

	public static function thumb_th(): string {
		return '<th class="dze-thumb-th"></th>';
	}

	/**
	 * The cell under it.
	 *
	 * @param string $thumb The small one, '' where the object has none.
	 * @param string $full  The one the viewer opens; falls back to the thumb.
	 * @param string $alt   The object's own name, for a reader who cannot see
	 *                      the picture.
	 */
	public static function thumb_td( string $thumb, string $full = '', string $alt = '' ): string {
		if ( '' === $thumb ) {
			// WHICH EMPTY IT IS. A blank cell reads as a reading that never
			// happened — and on a list of what the shop is short of, having no
			// featured image is itself the thing worth seeing.
			return '<td class="dze-thumb-td"><span class="dze-thumb-none" title="'
				. esc_attr__( 'No featured image', 'dazont-ecom' ) . '">&mdash;</span></td>';
		}
		// The ONE image viewer, the same `img.dze-hzoom` + `data-full` thirteen
		// screens already open. A second one is a second thing to fix, and the
		// second one never gets the fixes.
		return sprintf(
			'<td class="dze-thumb-td"><img class="dze-hzoom" src="%1$s" data-full="%2$s" alt="%3$s" /></td>',
			esc_url( $thumb ),
			esc_url( '' !== $full ? $full : $thumb ),
			esc_attr( $alt )
		);
	}

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
	 * THE "?" BESIDE A NAME, and the panel of detail behind it.
	 *
	 * "On pourrait d'ailleurs comme dans les autres modules utiliser un bouton
	 * I qui charge plus d'info pour la curiosité. Ici on met plus d'info sur le
	 * fonctionnement si besoin."
	 *
	 * The modules list has worn this pair for a long time: a short line that
	 * says what a thing is for, and the full account of how it works one press
	 * away, for whoever wants it. It is the answer to a screen that explains
	 * itself in paragraphs — so it belongs to more than one screen, and it is
	 * built HERE rather than copied, because two popups that look the same and
	 * are written twice stop looking the same on the next edit.
	 *
	 * @param string $key Whatever the screen calls the thing: a module id, a
	 *                    task id. The map handed to more_assets() is keyed by
	 *                    the same word.
	 */
	/**
	 * Has the popup itself been printed in this request?
	 *
	 * Public and named, rather than a `static` inside the function: a gate
	 * draws several screens in one process, and state it cannot put back is
	 * state that makes the second reading answer for the first.
	 */
	public static bool $more_printed = false;

	public static function more_button( string $key ): string {
		return '<button type="button" class="dze-mod-more" data-module="' . esc_attr( $key )
			. '" title="' . esc_attr__( 'Full description', 'dazont-ecom' ) . '">?</button>';
	}

	/**
	 * The popup those buttons open, and the words in it.
	 *
	 * @param array<string,array{title:string,text:string}> $texts Keyed by the
	 *        same word `more_button()` was given.
	 */
	public static function more_assets( array $texts ): void {
		if ( ! self::$more_printed ) {
			self::$more_printed = true;
			?>
			<div class="dze-mod-popup" id="dze-mod-popup">
				<div class="dze-mod-popup-box">
					<h3 id="dze-mod-popup-title"></h3>
					<p id="dze-mod-popup-text"></p>
					<p style="text-align:right;margin:14px 0 0;"><button type="button" class="button" id="dze-mod-popup-close"><?php esc_html_e( 'Close', 'dazont-ecom' ); ?></button></p>
				</div>
			</div>
			<style>
			.dze-mod-more {
				display: inline-block; width: 16px; height: 16px; line-height: 14px; text-align: center; padding: 0;
				border: 1px solid #c3c4c7; border-radius: 50%; background: #f6f7f7; color: #646970;
				font-size: 10px; font-weight: 700; cursor: pointer; vertical-align: 1px; margin-left: 4px;
			}
			.dze-mod-more:hover { border-color: #2271b1; color: #2271b1; }
			.dze-mod-popup { position: fixed; inset: 0; background: rgba(0,0,0,.5); z-index: 100001; display: none; align-items: center; justify-content: center; }
			.dze-mod-popup.is-open { display: flex; }
			.dze-mod-popup-box { background: #fff; border-radius: 8px; box-shadow: 0 10px 40px rgba(0,0,0,.3); max-width: 560px; width: 92vw; padding: 20px 24px; }
			.dze-mod-popup-box h3 { margin: 0 0 10px; }
			.dze-mod-popup-box p { margin: 0; line-height: 1.6; color: #3c434a; }
			</style>
			<script>
			jQuery( function ( $ ) {
				$( document ).on( 'click', '#dze-mod-popup-close', function () { $( '#dze-mod-popup' ).removeClass( 'is-open' ); } );
				$( document ).on( 'click', '#dze-mod-popup', function ( e ) { if ( e.target === this ) { $( this ).removeClass( 'is-open' ); } } );
			} );
			</script>
			<?php
		}
		?>
		<script>
		jQuery( function ( $ ) {
			var dzeMore = <?php echo wp_json_encode( $texts ); ?>;
			$( document ).on( 'click', '.dze-mod-more', function ( e ) {
				var m = dzeMore[ $( this ).data( 'module' ) ];
				if ( ! m ) { return; }
				// A "?" planted inside a <summary> must not fold the block
				// under the hand that pressed it.
				e.preventDefault();
				e.stopPropagation();
				$( '#dze-mod-popup-title' ).text( m.title );
				$( '#dze-mod-popup-text' ).text( m.text );
				$( '#dze-mod-popup' ).addClass( 'is-open' );
			} );
		} );
		</script>
		<?php
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
