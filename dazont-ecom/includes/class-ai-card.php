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

	/** Attachment meta: the card of a picture filed on the shop. */
	public const META = '_dze_ai_card';

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
		// The media library and its modal: the same answer, written in the
		// attachment's details instead of behind an « i ».
		add_filter( 'attachment_fields_to_edit', [ self::class, 'media_field' ], 10, 2 );
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
	 * The picture became an attachment: its card goes with it. Meta is
	 * unslashed on the way in, so the words are slashed first — a prompt
	 * quoting a path would otherwise lose its backslashes.
	 */
	public static function file( string $url, int $att, string $recipe = '' ): void {
		$c = self::get( $url );
		if ( ! $c || $att < 1 ) {
			return;
		}
		if ( empty( $c['recipe'] ) && '' !== $recipe ) {
			$c['recipe'] = $recipe;
			$c['name']   = self::recipe_name( $recipe );
		}
		unset( $c['pid'] );
		update_post_meta( $att, self::META, wp_slash( $c ) );
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
			$c = get_post_meta( $att, self::META, true );
			if ( is_array( $c ) && $c ) {
				return self::slim( [ 'kind' => 'ai' ] + $c );
			}
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
	 * @return array<string,mixed>
	 */
	public static function view( array $c, array $old, string $framing ): array {
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
		];
	}

	/** The card of a filed picture, or what it kept without one. @return array<string,mixed> */
	public static function of_attachment( int $att ): array {
		$c = get_post_meta( $att, self::META, true );
		$r = class_exists( 'DZE_Content' ) ? (string) get_post_meta( $att, DZE_Content::META_RECIPE, true ) : '';
		$v = class_exists( 'DZE_Content' ) ? (string) get_post_meta( $att, DZE_Content::META_VIEW, true ) : '';
		return self::view( is_array( $c ) ? $c : [], [ 'recipe' => $r ], $v );
	}

	/** True when the attachment was made or reworked by a model. */
	public static function is_ai( int $att ): bool {
		if ( $att < 1 ) {
			return false;
		}
		if ( class_exists( 'DZE_Content' ) && '' !== (string) get_post_meta( $att, DZE_Content::META_RECIPE, true ) ) {
			return true;
		}
		$c = get_post_meta( $att, self::META, true );
		return is_array( $c ) && ! empty( $c );
	}

	/** The « i »: one picture's card, by its address (waiting) or its id (filed). */
	public static function ajax(): void {
		// The content module's nonce (DZE_Content::NONCE), which photos.js carries.
		check_ajax_referer( 'dze_content', 'nonce' );
		if ( ! current_user_can( 'edit_products' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'dazont-ecom' ) ], 403 );
		}
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked above.
		$pid = isset( $_POST['post'] ) ? absint( $_POST['post'] ) : 0;
		$att = isset( $_POST['att'] ) ? absint( $_POST['att'] ) : 0;
		$url = isset( $_POST['url'] ) ? esc_url_raw( (string) wp_unslash( $_POST['url'] ) ) : '';
		// phpcs:enable
		if ( $att > 0 ) {
			if ( 'attachment' !== get_post_type( $att ) ) {
				wp_send_json_error( [ 'message' => __( 'This picture no longer exists.', 'dazont-ecom' ) ] );
			}
			wp_send_json_success( self::of_attachment( $att ) );
		}
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
			(string) ( $w['views'][ $url ] ?? '' )
		) );
	}

	/**
	 * The card in the media library's details, for a picture made by a model.
	 *
	 * @param array<string,array<string,mixed>> $fields
	 * @param WP_Post                            $post
	 * @return array<string,array<string,mixed>>
	 */
	public static function media_field( $fields, $post ) {
		if ( ! is_array( $fields ) || ! is_object( $post ) || ! self::is_ai( (int) $post->ID ) ) {
			return $fields;
		}
		$v    = self::of_attachment( (int) $post->ID );
		$rows = [
			__( 'Prompt', 'dazont-ecom' ) => (string) $v['name'],
			__( 'Model', 'dazont-ecom' )  => (string) $v['model'],
			__( 'Price', 'dazont-ecom' )  => (string) $v['cost'],
			__( 'Made', 'dazont-ecom' )   => (string) $v['when'],
			__( 'From', 'dazont-ecom' )   => (string) $v['from'],
		];
		$html = '';
		foreach ( $rows as $label => $value ) {
			if ( '' !== $value ) {
				$html .= '<div><strong>' . esc_html( $label ) . '</strong> ' . esc_html( $value ) . '</div>';
			}
		}
		if ( ! $v['known'] ) {
			$html .= '<div><em>' . esc_html__( 'Made before Dazont Ecom 4.507.0: only the prompt\'s name was kept with the picture.', 'dazont-ecom' ) . '</em></div>';
		}
		if ( '' !== (string) $v['prompt'] ) {
			$html .= '<details><summary>' . esc_html__( 'Full prompt sent', 'dazont-ecom' ) . '</summary><pre style="white-space:pre-wrap;max-height:240px;overflow:auto;font-size:11px;">' . esc_html( (string) $v['prompt'] ) . '</pre></details>';
		}
		$fields['dze_ai_card'] = [
			'label' => __( 'AI picture', 'dazont-ecom' ),
			'input' => 'html',
			'html'  => '<div class="dze-ai-media">' . $html . '</div>',
		];
		return $fields;
	}
}
