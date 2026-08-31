<?php
/**
 * The email, expressed in Klaviyo's own blocks.
 *
 * @package Dazont_Ecom
 */

defined( 'ABSPATH' ) || exit;

/**
 * Turns the email the shop wrote into the blocks Klaviyo edits and TRANSLATES.
 *
 * An email filed as one lump of HTML is an email Klaviyo cannot translate: its
 * per-language texts, links and pictures are read from BLOCKS, and a code
 * template has none. That is the whole reason this exists — the shop sells in
 * five markets, and a campaign that cannot be translated is a campaign for one
 * of them.
 *
 * Nothing here decides what the email says. The writing is unchanged: the same
 * prompt, the same body, the same product cards. This reads that body and says
 * what it IS — a heading, a paragraph, a picture, a button, a row of products
 * — so each piece arrives in Klaviyo as its own block, editable by hand and
 * answerable to the translations.
 *
 * The vocabulary is deliberately four blocks wide (text, image, button, and a
 * row of columns). Klaviyo has a product block of its own; it is not used,
 * because the prices in this shop's emails are the PROMOTION's, not the
 * catalogue's, and a block that fills itself from the catalogue would quietly
 * disagree with the price the customer was promised.
 */
final class DZE_Klaviyo_Blocks {

	/** The class our own product cards carry, and the one thing they are found by. */
	private const CARD = 'dze-card';

	/**
	 * The body, as rows of blocks.
	 *
	 * Consecutive ordinary pieces — headings, paragraphs, pictures, buttons —
	 * go into ONE full-width row, one block each. A run of product cards
	 * becomes a row of columns, because that is what it already is.
	 *
	 * @param string $html The email body, exactly as it is stored and previewed.
	 * @param array  $t    The shop's own type and colour, from theme_style().
	 * @return array[] Klaviyo row definitions.
	 */
	public static function rows( string $html, array $t ): array {
		$rows  = [];
		$run   = [];
		$cards = [];
		$full  = class_exists( 'DZE_Klaviyo' ) ? DZE_Klaviyo::card_width( 1 ) : 546;
		foreach ( self::top_level( $html ) as $node ) {
			self::walk( $node, $t, $full, $rows, $run, $cards );
		}
		self::flush_cards( $rows, $cards, $t );
		self::flush_run( $rows, $run );
		return $rows;
	}

	/**
	 * One node, sorted into the three piles: a product card, a container that
	 * HOLDS cards somewhere inside, or a leaf of ordinary content.
	 *
	 * The middle case is the one that was wrong. A node holding cards used to
	 * become its card rows and NOTHING ELSE — right for the tidy wrapper the
	 * shop's own product rows make, and silently destructive the day the
	 * model wrapped the whole email in one table: every heading and every
	 * paragraph inside that table vanished, and the draft Klaviyo held was an
	 * email of pictures and prices with not a written word on it. So a
	 * container is DESCENDED instead: the text before, between and after the
	 * cards survives in order, and consecutive cards still group into rows.
	 */
	private static function walk( $node, array $t, int $full, array &$rows, array &$run, array &$cards ): void {
		if ( $node instanceof DOMText ) {
			if ( '' === trim( $node->textContent ) ) {
				return;
			}
			// Loose text inside a container is still something somebody wrote.
			$wrap = $node->ownerDocument->createElement( 'div' );
			$wrap->appendChild( $node->cloneNode( true ) );
			$node = $wrap;
		}
		if ( ! $node instanceof DOMElement ) {
			return; // comments, and the mso conditionals around card rows.
		}
		if ( false !== strpos( (string) $node->getAttribute( 'class' ), self::CARD ) ) {
			self::flush_run( $rows, $run );
			$cards[] = $node;
			return;
		}
		if ( self::cards_in( $node ) ) {
			foreach ( $node->childNodes as $child ) {
				self::walk( $child, $t, $full, $rows, $run, $cards );
			}
			return;
		}
		self::flush_cards( $rows, $cards, $t );
		foreach ( self::blocks_of( $node, $t, $full ) as $block ) {
			$run[] = $block;
		}
	}

	/** Whatever text and pictures were being collected become one row. */
	private static function flush_run( array &$rows, array &$run ): void {
		if ( $run ) {
			$rows[] = self::one_column( $run );
			$run    = [];
		}
	}

	/** Whatever cards were being collected become their product rows. */
	private static function flush_cards( array &$rows, array &$cards, array $t ): void {
		if ( $cards ) {
			foreach ( self::card_rows( $cards, $t ) as $row ) {
				$rows[] = $row;
			}
			$cards = [];
		}
	}

	/**
	 * The frame with the email in it.
	 *
	 * The owner's template is not rebuilt, copied or interpreted: it is taken
	 * as it stands and the EMPTY section in the middle of it — the one section
	 * he leaves for the content, the same one the HTML frame is cut at — is
	 * given the rows above. His header, his footer and his saved sections
	 * travel untouched, which is why a change made in Klaviyo reaches the next
	 * email without anybody reading the template again.
	 *
	 * @param array   $definition The frame template's own definition.
	 * @param array[] $rows       What to put in its empty section.
	 * @throws RuntimeException When the template has no empty section to fill.
	 */
	public static function fill( array $definition, array $rows ): array {
		$sections = array_values( (array) ( $definition['body']['sections'] ?? [] ) );
		$at       = null;
		foreach ( $sections as $i => $section ) {
			if ( self::is_slot( (array) $section ) ) {
				$at = $i;
				break;
			}
		}
		if ( null === $at ) {
			throw new RuntimeException( __( 'That Klaviyo template has no empty section for the email to go in. Open it, leave one section empty between the header and the footer, and save it.', 'dazont-ecom' ) );
		}
		$sections[ $at ]['rows'] = $rows;
		$definition['body']['sections'] = $sections;
		return $definition;
	}

	/**
	 * The keys whose value is a LIST. Everything else that is empty is a map.
	 *
	 * PHP has one array and JSON has two, and json_decode() into an
	 * associative array throws the difference away: {} and [] both come back
	 * as [], and both go back out as []. Klaviyo answers that with "an invalid
	 * field type was passed in" — a message that names no field and points at
	 * nothing, for a template that was read from Klaviyo itself moments
	 * earlier and handed straight back.
	 */
	private const LISTS = [
		'sections', 'rows', 'columns', 'blocks', 'subblocks',
		'condition_groups', 'conditions', 'variations',
		'custom_tracking_params', 'message_hierarchy', 'action_buttons',
		'cards', 'suggestions',
	];

	/**
	 * The definition with its empty maps travelling as maps.
	 *
	 * A section's properties, a block's display options, a column's data: all
	 * of them are {} in the template Klaviyo sent, all of them are [] once PHP
	 * has read it, and every one of them is refused on the way back. An empty
	 * LIST is a different thing — it says nothing, so it is left out entirely
	 * rather than turned into an object that would say something wrong.
	 *
	 * @param mixed $value
	 * @return mixed
	 */
	public static function objects( $value, string $key = '' ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		if ( ! $value ) {
			return in_array( $key, self::LISTS, true ) ? null : new stdClass();
		}
		$out = [];
		foreach ( $value as $k => $v ) {
			$one = self::objects( $v, (string) $k );
			if ( null === $one && in_array( (string) $k, self::LISTS, true ) ) {
				continue;
			}
			$out[ $k ] = $one;
		}
		return $out;
	}

	/**
	 * The frame's own ids, dropped.
	 *
	 * Every section, row, column and block of the template carries an id
	 * Klaviyo minted for THAT template. Sending them back inside a new one is
	 * asking two templates to share an identity, and the answer to that is
	 * nobody's to guess — so they are left out and Klaviyo mints its own.
	 *
	 * universal_id goes with them, and not by choice: Klaviyo refuses it
	 * outright on a new template ("universal_id is not allowed to be specified
	 * on create"). A saved section therefore arrives as a COPY of what it said
	 * the moment the frame was read — which is why the frame is read fresh for
	 * every email rather than kept, so the copy is never an old one.
	 *
	 * asset_id stays: it is how a picture in his library is a picture and not
	 * a URL, and Klaviyo takes it happily.
	 */
	public static function strip_ids( array $node ): array {
		$out = [];
		foreach ( $node as $key => $value ) {
			if ( 'id' === $key || 'data_id' === $key || 'universal_id' === $key ) {
				continue;
			}
			// A `properties` bag is CONTENT, not structure: the body's own
			// properties are the HTML id and class Klaviyo's stylesheet is
			// written against ("bodyTable" / "root-container"), and a picture's
			// are its source and its asset. Walking into it dropped the body's
			// id, Klaviyo filled the gap by shifting the class into it, and the
			// rules that draw the drop shadow under the header stopped matching
			// anything — a template that looked right in the editor and lost
			// its shadow in every inbox.
			if ( 'properties' === $key ) {
				$out[ $key ] = $value;
				continue;
			}
			$out[ $key ] = is_array( $value ) ? self::strip_ids( $value ) : $value;
		}
		return $out;
	}

	/**
	 * The drop shadows, left for Klaviyo to colour.
	 *
	 * A shadow block carries `shadow_color`, and the API takes three words for
	 * it — light, medium, dark. Klaviyo's RENDERER turns each of those into a
	 * file name that does not exist: "light" comes out as
	 * bottom_shadow_light.png, which is a broken image under the header of
	 * every email. The files it actually serves are named after a colour —
	 * bottom_shadow_444.png, bottom_shadow_666.png — and the owner's own
	 * template renders one of those, because a SAVED section is drawn from
	 * what Klaviyo stores rather than from the copy in the definition. Ours is
	 * a plain section, so the word reaches the renderer and the image breaks.
	 *
	 * Sending nothing is what mends it: the renderer then uses its own
	 * default, which is a real file. Verified by rendering all four cases in
	 * the account itself. The shadow may be a shade off the owner's; a shadow
	 * a shade off is not a broken image.
	 */
	public static function sane_shadows( array $node ): array {
		if ( 'drop_shadow' === ( $node['type'] ?? '' ) ) {
			unset( $node['data']['styles']['shadow_color'] );
		}
		foreach ( $node as $key => $value ) {
			if ( is_array( $value ) ) {
				$node[ $key ] = self::sane_shadows( $value );
			}
		}
		return $node;
	}

	/**
	 * Whether a section is the one the email goes into.
	 *
	 * Empty means empty: no block anywhere in it. A SAVED section is never
	 * chosen even when it is empty — writing into one would write into content
	 * the owner reuses in every email of the account, and he would find this
	 * promotion in his welcome flow.
	 */
	private static function is_slot( array $section ): bool {
		if ( '' !== trim( (string) ( $section['universal_id'] ?? '' ) ) ) {
			return false;
		}
		$rows = (array) ( $section['rows'] ?? [] );
		if ( ! $rows ) {
			return false;
		}
		foreach ( $rows as $row ) {
			foreach ( (array) ( $row['columns'] ?? [] ) as $column ) {
				if ( ! empty( $column['blocks'] ) ) {
					return false;
				}
			}
		}
		return true;
	}

	// =========================================================================
	// Reading the body
	// =========================================================================

	/**
	 * The body's top-level elements, in order.
	 *
	 * @return DOMElement[]
	 */
	private static function top_level( string $html ): array {
		// Outlook's conditional comments have no business in Klaviyo blocks —
		// Klaviyo writes its own Outlook handling — and they arrive in two
		// states: as real comments, and as the LITERAL TEXT WordPress's kses
		// leaves of them. The second is what the shop saw in an inbox:
		// "<!--[if mso]>" printed between two product cards, and every such
		// scrap of text breaking the cards apart into one-per-row. Both forms
		// go, before the parser ever sees them; the ghost markup they carried
		// is harmless — the cards inside are found wherever they sit.
		$html = (string) preg_replace(
			'/<!--\[if [^\]]*\]>|<!\[endif\]-->|<!--\[endif\]-->|&lt;!--\[if [^&]*?\]&gt;|&lt;!\[endif\]--&gt;|&lt;!--\[endif\]--&gt;/i',
			'',
			$html
		);
		if ( '' === trim( $html ) || ! class_exists( 'DOMDocument' ) ) {
			return [];
		}
		$doc = new DOMDocument();
		// The body is a fragment, and libxml would otherwise read it as Latin-1
		// and turn every accented character into mojibake on the way in.
		$prev = libxml_use_internal_errors( true );
		$doc->loadHTML(
			'<?xml encoding="utf-8" ?><div id="dze-root">' . $html . '</div>',
			LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
		);
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );
		$root = $doc->getElementById( 'dze-root' );
		if ( ! $root ) {
			return [];
		}
		$out = [];
		foreach ( $root->childNodes as $node ) {
			if ( $node instanceof DOMElement ) {
				$out[] = $node;
				continue;
			}
			// Loose text between two blocks is still something somebody wrote.
			if ( $node instanceof DOMText && '' !== trim( $node->textContent ) ) {
				$wrap = $doc->createElement( 'div' );
				$wrap->appendChild( $node->cloneNode( true ) );
				$out[] = $wrap;
			}
		}
		return $out;
	}

	/**
	 * The product cards inside one node, if it is one of our product rows.
	 *
	 * @return DOMElement[]
	 */
	private static function cards_in( DOMElement $node ): array {
		$out = [];
		foreach ( $node->getElementsByTagName( 'div' ) as $div ) {
			if ( false !== strpos( (string) $div->getAttribute( 'class' ), self::CARD ) ) {
				$out[] = $div;
			}
		}
		return $out;
	}

	/**
	 * One node, as one or more blocks.
	 *
	 * A picture and a button are recognised because they are the two things a
	 * reader acts on, and Klaviyo has a block for each: left inside a text
	 * block they would be a paragraph with a picture in it, which is a
	 * paragraph nobody can translate the link of.
	 *
	 * @return array[]
	 */
	private static function blocks_of( DOMElement $node, array $t, int $width ): array {
		$img = self::only_image( $node );
		if ( $img ) {
			return [ self::image_block(
				(string) $img->getAttribute( 'src' ),
				self::link_around( $img ),
				(string) $img->getAttribute( 'alt' ),
				$width
			) ];
		}
		$button = self::only_button( $node );
		if ( $button ) {
			return [ self::button_block(
				trim( $button->textContent ),
				(string) $button->getAttribute( 'href' ),
				$t
			) ];
		}
		$html = self::inner_html( $node );
		return '' === trim( wp_strip_all_tags( $html ) ) && false === stripos( $html, '<img' )
			? []
			: [ self::text_block( $html ) ];
	}

	/** The one image a node holds, when the node holds nothing else. */
	private static function only_image( DOMElement $node ): ?DOMElement {
		$imgs = $node->getElementsByTagName( 'img' );
		if ( 'img' === strtolower( $node->nodeName ) ) {
			$img = $node;
		} elseif ( 1 === $imgs->length ) {
			$img = $imgs->item( 0 );
		} else {
			return null;
		}
		return ( '' === trim( $node->textContent ) && $img instanceof DOMElement ) ? $img : null;
	}

	/** The link a picture sits in, so the picture keeps sending people there. */
	private static function link_around( DOMElement $img ): string {
		for ( $up = $img->parentNode; $up instanceof DOMElement; $up = $up->parentNode ) {
			if ( 'a' === strtolower( $up->nodeName ) ) {
				return (string) $up->getAttribute( 'href' );
			}
		}
		return '';
	}

	/**
	 * The one link a node holds, when the node is nothing but that link.
	 *
	 * A sentence with a word linked in it is a paragraph; a link on its own,
	 * with a background or a border, is a button and is treated as one.
	 */
	private static function only_button( DOMElement $node ): ?DOMElement {
		$links = $node->getElementsByTagName( 'a' );
		$link  = ( 'a' === strtolower( $node->nodeName ) ) ? $node : ( 1 === $links->length ? $links->item( 0 ) : null );
		if ( ! $link instanceof DOMElement ) {
			return null;
		}
		if ( trim( $node->textContent ) !== trim( $link->textContent ) || '' === trim( $link->textContent ) ) {
			return null;
		}
		$style = strtolower( (string) $link->getAttribute( 'style' ) );
		$looks = false !== strpos( $style, 'background' )
			|| false !== strpos( $style, 'border' )
			|| false !== strpos( $style, 'inline-block' );
		return $looks ? $link : null;
	}

	/** What is inside an element, markup and all. */
	private static function inner_html( DOMElement $node ): string {
		$out = '';
		foreach ( $node->childNodes as $child ) {
			$out .= (string) $node->ownerDocument->saveHTML( $child );
		}
		// A bare text node, or a <div> the writing wrapped a sentence in, is
		// kept as it stands; anything with its own tag keeps that tag.
		return in_array( strtolower( $node->nodeName ), [ 'div', 'span' ], true ) && '' === trim( (string) $node->getAttribute( 'style' ) )
			? $out
			: (string) $node->ownerDocument->saveHTML( $node );
	}

	// =========================================================================
	// The blocks themselves
	// =========================================================================

	/** @param array[] $blocks */
	private static function one_column( array $blocks ): array {
		return [
			'data'    => [ 'styles' => [ 'column_layout' => '1-column-full-width' ] ],
			'columns' => [ [ 'data' => [], 'blocks' => array_values( $blocks ) ] ],
		];
	}

	/**
	 * A run of product cards, one column each.
	 *
	 * Klaviyo lays two or three columns side by side and stacks them on a
	 * phone by itself — which is the whole of what the HTML card had to do
	 * with an inline-block, a media query and a table for Outlook.
	 *
	 * @param DOMElement[] $cards
	 * @return array[] One row per line of cards — a run longer than a row is
	 *                 what the writing asked for, not a mistake.
	 */
	private static function card_rows( array $cards, array $t ): array {
		$per   = class_exists( 'DZE_Klaviyo' ) ? DZE_Klaviyo::per_row() : 3;
		$per   = max( 1, min( 4, $per ) );
		// A card's picture is sent at the width the card is drawn for — the
		// very figure the HTML card uses — because a Klaviyo image with no
		// width of its own is laid out from `max_width` alone, and 100 there
		// means one hundred PIXELS. That is the thumbnail the shop was sent.
		$width   = class_exists( 'DZE_Klaviyo' ) ? DZE_Klaviyo::card_width( $per ) : 178;
		$columns = [];
		foreach ( $cards as $card ) {
			$columns[] = [ 'data' => [], 'blocks' => self::card_blocks( $card, $t, $width ) ];
		}
		$out = [];
		foreach ( array_chunk( $columns, $per ) as $chunk ) {
			$out[] = [
				'data'    => [ 'styles' => [ 'column_layout' => self::layout_for( count( $chunk ) ) ] ],
				'columns' => array_values( $chunk ),
			];
		}
		return $out;
	}

	/** Klaviyo's name for a row of N equal columns. */
	private static function layout_for( int $n ): string {
		$map = [
			1 => '1-column-full-width',
			2 => '2-columns-equal-width',
			3 => '3-columns-equal-width',
			4 => '4-columns-equal-width',
		];
		return $map[ max( 1, min( 4, $n ) ) ];
	}

	/**
	 * One product card, as the blocks it is made of.
	 *
	 * Read from the card the shop already built rather than from the product,
	 * so the picture, the name and the two prices are the very ones the owner
	 * saw in the preview — and a card edited by hand before the draft is the
	 * card that goes out.
	 */
	private static function card_blocks( DOMElement $card, array $t, int $width ): array {
		$img   = $card->getElementsByTagName( 'img' )->item( 0 );
		$links = $card->getElementsByTagName( 'a' );
		$href  = ( $links->length && $links->item( 0 ) instanceof DOMElement )
			? (string) $links->item( 0 )->getAttribute( 'href' )
			: '';
		$out   = [];
		if ( $img instanceof DOMElement ) {
			$out[] = self::image_block(
				(string) $img->getAttribute( 'src' ),
				$href,
				(string) $img->getAttribute( 'alt' ),
				$width,
				(string) ( $t['card'] ?? '' )
			);
		}
		// The name and the prices, exactly as the card prints them: the divs
		// inside the picture's link, minus the link itself, which becomes the
		// button below rather than a link on every line.
		$said = '';
		foreach ( $card->getElementsByTagName( 'div' ) as $div ) {
			if ( '' === trim( $div->textContent ) || $div->getElementsByTagName( 'div' )->length ) {
				continue;
			}
			// Its markup, not its words: the old price is struck through with a
			// span, and reading the text alone quietly un-struck it.
			$inside = '';
			foreach ( $div->childNodes as $child ) {
				$inside .= (string) $div->ownerDocument->saveHTML( $child );
			}
			$said .= '<div style="text-align:center;">' . $inside . '</div>';
		}
		if ( '' !== $said ) {
			$out[] = self::text_block( $said, 6, 6, 8, (string) ( $t['card'] ?? '' ) );
		}
		$label = '';
		for ( $i = $links->length - 1; $i >= 0; $i-- ) {
			$one = $links->item( $i );
			if ( $one instanceof DOMElement && ! $one->getElementsByTagName( 'img' )->length ) {
				$label = trim( $one->textContent );
				break;
			}
		}
		if ( '' !== $label && '' !== $href ) {
			$out[] = self::button_block( $label, $href, $t, true );
		}
		return $out;
	}

	private static function text_block( string $html, int $top = 10, int $bottom = 10, int $side = 18, string $behind = '' ): array {
		$styles = [
			'inner_padding_top'    => $top,
			'inner_padding_bottom' => $bottom,
			'inner_padding_left'   => $side,
			'inner_padding_right'  => $side,
		];
		if ( '' !== trim( $behind ) ) {
			$styles['block_background_color'] = $behind;
		}
		return [
			'content_type' => 'block',
			'type'         => 'text',
			'data'         => [
				'content'         => $html,
				'display_options' => [],
				'styles'          => $styles,
			],
		];
	}

	/**
	 * A picture, pointed at where it already pointed.
	 *
	 * Marked dynamic on purpose: a STATIC image block is refused by Klaviyo
	 * unless the file is in its own library, and the shop's product
	 * photographs are the shop's — uploading nine of them per email would fill
	 * the account's library with copies nobody chose and nobody can weed.
	 */
	private static function image_block( string $src, string $href, string $alt, int $width, string $behind = '' ): array {
		$props = [ 'dynamic' => true, 'src' => $src ];
		if ( '' !== trim( $href ) ) {
			$props['href'] = $href;
		}
		if ( '' !== trim( $alt ) ) {
			$props['alt_text'] = $alt;
		}
		// `max_width` is a number of PIXELS, not a percentage — read back off
		// Klaviyo's own renderer, which turned max_width:100 into a 100px
		// cell and a 100px photograph, which is the thumbnail the shop was
		// sent. So it is given the width the picture is meant to occupy: the
		// body's own width for a picture on its own line, the card's width in
		// a row of products. full_width_mobile hands the whole screen back on
		// a phone, where a 178px card is the entire column.
		$styles = [
			'align'             => 'center',
			'max_width'         => max( 40, $width ),
			'full_width_mobile' => true,
		];
		if ( '' !== trim( $behind ) ) {
			$styles['block_background_color'] = $behind;
		}
		return [
			'content_type' => 'block',
			'type'         => 'image',
			'data'         => [
				'properties'      => $props,
				'display_options' => [],
				'styles'          => $styles,
			],
		];
	}

	/**
	 * A button, in the shop's own colour and at the shop's own size.
	 *
	 * The padding INSIDE the button is what makes it a button: left to
	 * Klaviyo's own default it comes out as a coloured word. The figures are
	 * the ones the HTML card already uses — 11px by 20px on a card, roomier
	 * on the call to action that stands on its own line — so the two versions
	 * of the same email are the same email.
	 */
	private static function button_block( string $label, string $href, array $t, bool $in_card = false ): array {
		$pad_y = $in_card ? 11 : 14;
		$pad_x = $in_card ? 20 : 30;
		return [
			'content_type' => 'block',
			'type'         => 'button',
			'data'         => [
				'content'         => $label,
				'properties'      => [ 'href' => $href ],
				'display_options' => [],
				'styles'          => [
					'background_color'     => (string) ( $t['btn_bg'] ?: '#5B594E' ),
					'color'                => (string) ( $t['btn_ink'] ?: '#FFFFFF' ),
					'border_radius'        => (int) ( $t['radius'] ?? 4 ),
					'font_family'          => (string) ( $t['body'] ?? '' ),
					'font_size'            => $in_card ? 14 : 16,
					'font_weight'          => '400',
					'text_align'           => 'center',
					'inner_padding_top'    => $pad_y,
					'inner_padding_bottom' => $pad_y,
					'inner_padding_left'   => $pad_x,
					'inner_padding_right'  => $pad_x,
					'block_padding_top'    => $in_card ? 4 : 10,
					'block_padding_bottom' => $in_card ? 14 : 22,
					'block_padding_left'   => 18,
					'block_padding_right'  => 18,
				]
				+ ( ( $in_card && '' !== trim( (string) ( $t['card'] ?? '' ) ) )
					? [ 'block_background_color' => (string) $t['card'] ]
					: [] ),
			],
		];
	}
}
