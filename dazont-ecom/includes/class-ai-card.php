<?php
/**
 * WHAT MADE A PICTURE: its prompt, its model, its price.
 *
 * « tu vas rajouter un petit i pour info sur toutes les images générées par
 * IA. au clic, un text doit apparaitre pour dire quel prompt a été utilisé, et
 * quel modèle d'ia, et quel prix. »
 *
 * One card per picture, written where its price becomes known — fal_fetch(),
 * which every picture of every screen passes through — with the very words
 * that were sent. While the picture waits for a decision the card is an option
 * of its own, never autoloaded: a product's meta is read whole on every visit
 * to its page, and a dozen prompts have no business there. Once the picture is
 * filed (sideload_seo()) the card moves onto the attachment, the only thing
 * that still knows afterwards.
 *
 * @package Dazont_Ecom
 */

defined( 'ABSPATH' ) || exit;

final class DZE_Ai_Card {

	/** Option prefix of a card still waiting, followed by the md5 of its address. */
	private const OPT = 'dze_aic_';

	/** When each waiting card was written, so the old ones can go. */
	private const INDEX = 'dze_aic_index';

	/** A waiting card is kept 45 days: a fal address does not live longer. */
	private const KEEP = 3888000;

	/** At most this many cards wait at once; the oldest go first. */
	private const MAX_WAITING = 600;

	/** The words sent are kept up to this many characters. */
	private const PROMPT_MAX = 12000;

	public static function init(): void {
		add_action( 'wp_ajax_dze_ai_card', [ self::class, 'ajax' ] );
	}

	private static function key( string $url ): string {
		return self::OPT . md5( $url );
	}

	/**
	 * Adds what is known about a picture to its card. Called twice for most
	 * pictures: by fal_fetch() with the model, the price and the words, then
	 * by the caller with the prompt that made it and what it was made from.
	 *
	 * @param array<string,mixed> $fields
	 */
	public static function put( int $pid, string $url, array $fields ): void {
		if ( $pid < 1 || '' === $url ) {
			return;
		}
		$k   = self::key( $url );
		$was = get_option( $k, [] );
		$c   = is_array( $was ) ? $was : [];
		foreach ( $fields as $name => $value ) {
			switch ( $name ) {
				case 'cost':
					$c['cost'] = round( max( 0.0, (float) $value ), 4 );
					break;
				case 'refs':
				case 'by':
					$c[ $name ] = max( 0, (int) $value );
					break;
				case 'prompt':
					$c['prompt'] = mb_substr( (string) $value, 0, self::PROMPT_MAX );
					break;
				case 'base':
					if ( is_array( $value ) && $value ) {
						$c['base'] = self::slim( $value );
					}
					break;
				// WHAT IT WAS TOLD (made_lines()): the framings on the page and
				// already made, to avoid; the one to make again, on a ↻.
				case 'told':
					if ( is_array( $value ) && $value ) {
						$c['told'] = [
							'page'  => array_values( array_map( 'strval', (array) ( $value['page'] ?? [] ) ) ),
							'made'  => array_values( array_map( 'strval', (array) ( $value['made'] ?? [] ) ) ),
							'again' => (string) ( $value['again'] ?? '' ),
						];
					}
					break;
				// WHAT IT SHOWS THAT THE PRODUCT DOES NOT (read_picture()).
				case 'invented':
					$c['invented'] = array_values( array_map( 'strval', (array) $value ) );
					break;
				case 'model':
				case 'recipe':
				case 'tool':
					if ( '' !== (string) $value ) {
						$c[ $name ] = (string) $value;
					}
					break;
			}
		}
		// What the prompt and the model were CALLED that day: a prompt renamed
		// or deleted later must not turn an old picture's card into an id.
		if ( ! empty( $c['recipe'] ) && empty( $c['name'] ) ) {
			$c['name'] = self::recipe_name( (string) $c['recipe'] );
		}
		if ( ! empty( $c['model'] ) ) {
			$c['label'] = self::model_label( (string) $c['model'] );
		}
		$c['pid'] = $pid;
		$c['at']  = (int) ( $c['at'] ?? time() );
		update_option( $k, $c, false );
		if ( ! is_array( $was ) || ! $was ) {
			self::remember( $k );
		}
	}

	/** The card of a picture still waiting, [] when none was written. */
	public static function get( string $url ): array {
		if ( '' === $url ) {
			return [];
		}
		$c = get_option( self::key( $url ), [] );
		return is_array( $c ) ? $c : [];
	}

	/**
	 * The picture is on the product now: its card ends with the waiting. « Je
	 * veux juste l'info temporairement sur les images générées pas encore sur
	 * le produit » — nothing is written on the attachment.
	 */
	public static function file( string $url, int $att = 0, string $recipe = '' ): void {
		self::drop( [ $url ] );
	}

	/** Pictures thrown away or replaced: their cards with them. @param string[] $urls */
	public static function drop( array $urls ): void {
		$idx  = get_option( self::INDEX, [] );
		$idx  = is_array( $idx ) ? $idx : [];
		$gone = false;
		foreach ( $urls as $u ) {
			if ( '' === (string) $u ) {
				continue;
			}
			$k = self::key( (string) $u );
			delete_option( $k );
			if ( isset( $idx[ $k ] ) ) {
				unset( $idx[ $k ] );
				$gone = true;
			}
		}
		if ( $gone ) {
			update_option( self::INDEX, $idx, false );
		}
	}

	/** Notes a new waiting card, and lets the old ones go. */
	private static function remember( string $k ): void {
		$idx = get_option( self::INDEX, [] );
		$idx = is_array( $idx ) ? $idx : [];
		$idx[ $k ] = time();
		asort( $idx );
		$cut = time() - self::KEEP;
		foreach ( $idx as $old => $when ) {
			if ( (int) $when >= $cut && count( $idx ) <= self::MAX_WAITING ) {
				break;
			}
			delete_option( (string) $old );
			unset( $idx[ $old ] );
		}
		update_option( self::INDEX, $idx, false );
	}

	/**
	 * What a picture made FROM another one says about it: the other one's
	 * name, model and price — never its words, which live on its own card.
	 *
	 * @param array<string,mixed> $c
	 * @return array<string,mixed>
	 */
	public static function slim( array $c ): array {
		$out = [];
		foreach ( [ 'kind', 'recipe', 'name', 'model', 'label', 'cost', 'at' ] as $k ) {
			if ( isset( $c[ $k ] ) && '' !== $c[ $k ] ) {
				$out[ $k ] = is_float( $c[ $k ] ) || is_int( $c[ $k ] ) ? $c[ $k ] : (string) $c[ $k ];
			}
		}
		return $out;
	}

	/**
	 * What a picture a ✦ or an HD starts from was: a picture made here (its
	 * card), a photograph of the product, or one pasted in.
	 *
	 * @return array<string,mixed>
	 */
	public static function source_of( int $pid, string $url, int $att, bool $pasted ): array {
		if ( '' !== $url ) {
			$c = self::get( $url );
			if ( ! $c && class_exists( 'DZE_Content' ) ) {
				// Made before cards were written: the waiting list still knows
				// which prompt and which model.
				$w = DZE_Content::pending( $pid );
				$c = array_filter( [
					'recipe' => (string) ( $w['recipes'][ $url ] ?? '' ),
					'model'  => (string) ( $w['models'][ $url ] ?? '' ),
				] );
			}
			return self::slim( [ 'kind' => 'ai' ] + self::named( $c ) );
		}
		if ( $att > 0 ) {
			$r = class_exists( 'DZE_Content' ) ? (string) get_post_meta( $att, DZE_Content::META_RECIPE, true ) : '';
			return '' !== $r ? self::slim( self::named( [ 'kind' => 'ai', 'recipe' => $r ] ) ) : [ 'kind' => 'photo' ];
		}
		return $pasted ? [ 'kind' => 'pasted' ] : [];
	}

	/** Fills the names a card is read by. @param array<string,mixed> $c */
	private static function named( array $c ): array {
		if ( ! empty( $c['recipe'] ) && empty( $c['name'] ) ) {
			$c['name'] = self::recipe_name( (string) $c['recipe'] );
		}
		if ( ! empty( $c['model'] ) && empty( $c['label'] ) ) {
			$c['label'] = self::model_label( (string) $c['model'] );
		}
		return $c;
	}

	public static function recipe_name( string $recipe ): string {
		if ( '' === $recipe ) {
			return '';
		}
		if ( 'img_enlarged' === $recipe ) {
			return __( 'HD enlargement', 'dazont-ecom' );
		}
		$name = class_exists( 'DZE_Content' ) ? DZE_Content::recipe_name( $recipe ) : '';
		return '' !== $name ? $name : $recipe;
	}

	public static function model_label( string $key ): string {
		if ( ! class_exists( 'DZE_Content' ) ) {
			return $key;
		}
		if ( DZE_Content::UPSCALER === $key ) {
			return DZE_Content::upscaler_label();
		}
		return (string) ( DZE_Content::image_models()[ $key ]['label'] ?? $key );
	}

	/** « $0.08 », « $0.013 »: a cent is not rounded away. */
	public static function money( float $cost ): string {
		$s = number_format( $cost, 3, '.', '' );
		if ( '0' === substr( $s, -1 ) ) {
			$s = substr( $s, 0, -1 );
		}
		return '$' . $s;
	}

	/**
	 * The card as the screens show it. A picture made before cards were
	 * written shows what was kept with it, and says so.
	 *
	 * @param array<string,mixed> $c    The card, or [].
	 * @param array<string,string> $old What the picture kept without a card: recipe, model.
	 * @param string[]              $invented What the reader found invented, when the card does not say.
	 * @return array<string,mixed>
	 */
	public static function view( array $c, array $old, string $framing, array $invented = [] ): array {
		$known = ! empty( $c );
		$c     = self::named( $known ? $c : array_filter( $old ) );
		$tool  = (string) ( $c['tool'] ?? '' );
		$name  = (string) ( $c['name'] ?? '' );
		if ( 'enlarge' === $tool ) {
			$name = __( 'HD enlargement', 'dazont-ecom' );
		}
		$base = (array) ( $c['base'] ?? [] );
		$from = '';
		if ( 'photo' === ( $base['kind'] ?? '' ) ) {
			$from = __( 'a photograph of the product', 'dazont-ecom' );
		} elseif ( 'pasted' === ( $base['kind'] ?? '' ) ) {
			$from = __( 'a photograph pasted in', 'dazont-ecom' );
		} elseif ( $base ) {
			$base = self::named( $base );
			$from = implode( ' · ', array_filter( [
				(string) ( $base['name'] ?? '' ),
				(string) ( $base['label'] ?? '' ),
				isset( $base['cost'] ) ? self::money( (float) $base['cost'] ) : '',
			] ) );
		}
		$at = (int) ( $c['at'] ?? 0 );
		// WHAT IT WAS TOLD TO AVOID, and what to make again: kept by the card
		// since 4.508.0, read back from the words sent before that.
		$told  = (array) ( $c['told'] ?? [] );
		$avoid = array_merge( (array) ( $told['page'] ?? [] ), (array) ( $told['made'] ?? [] ) );
		$again = (string) ( $told['again'] ?? '' );
		if ( ! $told && '' !== (string) ( $c['prompt'] ?? '' ) ) {
			[ $avoid, $again ] = self::told_in( (string) $c['prompt'] );
		}
		return [
			'known'   => $known,
			'tool'    => $tool,
			'name'    => $name,
			'model'   => (string) ( $c['label'] ?? '' ),
			'cost'    => isset( $c['cost'] ) ? self::money( (float) $c['cost'] ) : '',
			'when'    => $at ? (string) wp_date( (string) get_option( 'date_format' ) . ' ' . (string) get_option( 'time_format' ), $at ) : '',
			'refs'    => (int) ( $c['refs'] ?? 0 ),
			'prompt'  => (string) ( $c['prompt'] ?? '' ),
			'framing' => $framing,
			'from'    => $from,
			'avoid'   => array_values( array_unique( array_filter( array_map( 'strval', $avoid ) ) ) ),
			'again'   => $again,
			'invented'=> array_values( array_map( 'strval', isset( $c['invented'] ) ? (array) $c['invented'] : $invented ) ),
		];
	}

	/**
	 * The framings an order was told to avoid, and the one to make again,
	 * read from the words it sent — for the cards written before they were
	 * kept apart.
	 *
	 * @return array{0:string[],1:string}
	 */
	public static function told_in( string $prompt ): array {
		$avoid = [];
		foreach ( [ 'ON THE PRODUCT PAGE ALREADY', 'ALREADY MADE FOR THIS PRODUCT' ] as $head ) {
			$at = strpos( $prompt, $head );
			if ( false === $at ) {
				continue;
			}
			foreach ( array_slice( explode( "\n", substr( $prompt, $at ) ), 1 ) as $line ) {
				if ( 0 !== strpos( $line, '- ' ) ) {
					break;
				}
				$avoid[] = trim( substr( $line, 2 ) );
			}
		}
		$again = preg_match( '/THE PHOTOGRAPH THIS ONE REPLACES was framed: (.+?)\. Make that framing again/s', $prompt, $m ) ? trim( $m[1] ) : '';
		return [ $avoid, $again ];
	}

	/** The « i »: one waiting picture's card, by its address. */
	public static function ajax(): void {
		// The content module's nonce (DZE_Content::NONCE), which photos.js carries.
		check_ajax_referer( 'dze_content', 'nonce' );
		if ( ! current_user_can( 'edit_products' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'dazont-ecom' ) ], 403 );
		}
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked above.
		$pid = isset( $_POST['post'] ) ? absint( $_POST['post'] ) : 0;
		$url = isset( $_POST['url'] ) ? esc_url_raw( (string) wp_unslash( $_POST['url'] ) ) : '';
		// phpcs:enable
		if ( '' === $url ) {
			wp_send_json_error( [ 'message' => __( 'Which picture?', 'dazont-ecom' ) ] );
		}
		$c = self::get( $url );
		// A card belongs to one product: another product's address is not read
		// through this one.
		if ( $c && $pid > 0 && (int) ( $c['pid'] ?? 0 ) !== $pid ) {
			$c = [];
		}
		$w = ( $pid > 0 && class_exists( 'DZE_Content' ) ) ? DZE_Content::pending( $pid ) : [];
		wp_send_json_success( self::view(
			$c,
			[
				'recipe' => (string) ( $w['recipes'][ $url ] ?? '' ),
				'model'  => (string) ( $w['models'][ $url ] ?? '' ),
			],
			(string) ( $w['frames'][ $url ] ?? ( $w['views'][ $url ] ?? '' ) ),
			(array) ( $w['flags'][ $url ] ?? [] )
		) );
	}
}
