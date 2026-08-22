<?php
defined( 'ABSPATH' ) || exit;

/**
 * Every AJAX endpoint of the Product Content module.
 *
 * A TRAIT and not a class: these handlers are the same object as DZE_Content —
 * they call its private helpers and its statics through self::, and a trait is
 * compiled into the using class, so moving them out of that file changes
 * nothing at all about how they run. What it changes is that the module's
 * screens and its endpoints stop sharing one 5 000-line file.
 *
 * The hooks stay where they are declared, in DZE_Content's constructor: one
 * place still answers "what does this module listen to".
 */
trait DZE_Content_Ajax {

	/** Puts one shipped prompt back into the registry, switched off. */
	public function ajax_add_default(): void {
		$this->guard();
		$id = isset( $_POST['id'] ) ? sanitize_key( wp_unslash( $_POST['id'] ) ) : '';
		if ( ! isset( self::missing_defaults()[ $id ] ) ) {
			wp_send_json_error( [ 'message' => __( 'Unknown prompt.', 'dazont-ecom' ) ] );
		}
		$row = null;
		foreach ( self::legacy_fields() as $fid => $f ) {
			if ( $fid === $id ) {
				$row = [
					'id'          => $fid,
					'name'        => (string) $f['label'],
					'type'        => 'text',
					'prompt'      => (string) $f['prompt'],
					'inputs'      => [ 'title', 'description', 'attributes', 'price' ],
					'inputs_meta' => '',
					'output'      => (string) $f['dest'],
					'meta_key'    => '_dze_' . $fid,
					'enabled'     => 0,
					'valid'       => 0,
					'tokens'      => (int) $f['tokens'],
				];
			}
		}
		if ( ! $row ) {
			$n = 1;
			foreach ( self::default_image_templates() as $t ) {
				$tid = 'img_' . ( sanitize_key( str_replace( ' ', '_', (string) ( $t['name'] ?? '' ) ) ) ?: 'image_' . $n );
				if ( $tid === $id ) {
					$row = [
						'id'          => $tid,
						'name'        => (string) $t['name'],
						'type'        => 'image',
						'prompt'      => (string) $t['prompt'],
						'inputs'      => [ 'title', 'description' ],
						'inputs_meta' => '',
						'output'      => ( ( $t['target'] ?? 'gallery' ) === 'main' ) ? 'main' : 'gallery',
						'meta_key'    => '',
						'enabled'     => 1,
						'valid'       => 0,
						'tokens'      => 0,
					];
				}
				$n++;
			}
		}
		if ( ! $row ) {
			wp_send_json_error( [ 'message' => __( 'Unknown prompt.', 'dazont-ecom' ) ] );
		}
		$rows   = self::registry();
		$rows[] = $row;
		try {
			self::write_setting( 'registry', $rows );
		} catch ( \Throwable $e ) {
			wp_send_json_error( [ 'message' => $e->getMessage() ] );
		}
		wp_send_json_success( [ 'id' => $id ] );
	}

	/**
	 * The product's own photographs, ready to travel with a text request.
	 *
	 * A prompt that ticks "the product photographs" is written in front of the
	 * product rather than from a supplier title: the model reads the material,
	 * the cut and the details off the picture instead of inventing them. Kept
	 * to a handful — the featured image first — because each one is paid for in
	 * tokens and the third angle rarely says anything the first two did not.
	 *
	 * @param int[] $skip Attachment ids already attached to this request.
	 * @return array<int,array{media:string,data:string}>
	 */
	private function look_images( int $pid, array $skip = [], int $max = 8, bool $variants = false ): array {
		$ids = self::product_source_ids( $pid );
		if ( $variants ) {
			// The other colours, after the product's own: a description that
			// has to name the colourways cannot be written from one of them.
			$ids = array_merge( $ids, array_slice( array_keys( self::variation_images( $pid ) ), 0, 4 ) );
			$max = $max + 4;
		}
		$out    = [];
		$weight = 0;
		foreach ( $ids as $aid ) {
			if ( count( $out ) >= $max ) {
				break;
			}
			if ( in_array( (int) $aid, array_map( 'intval', $skip ), true ) ) {
				continue;
			}
			try {
				$uri = $this->fal_source_data_uri( (int) $aid, 'medium_large' );
			} catch ( \Throwable $e ) {
				continue;
			}
			if ( ! preg_match( '#^data:([^;]+);base64,(.+)$#', $uri, $mm ) ) {
				continue;
			}
			// What actually limits this is the weight of the request, so that
			// is what is counted; the number above is only a backstop.
			if ( $out && ( $weight + strlen( $mm[2] ) ) > self::VISION_BUDGET ) {
				break;
			}
			$weight += strlen( $mm[2] );
			$out[]   = [ 'media' => $mm[1], 'data' => $mm[2] ];
		}
		return $out;
	}

	/**
	 * The other colours of this product, ready to travel with a generation.
	 *
	 * Capped high rather than low: the request body is what actually limits
	 * this, and it is checked image by image as the body is built.
	 *
	 * @param int[] $skip Attachment ids already in the request.
	 * @return string[] data URIs.
	 */
	private function variant_images( int $pid, array $skip = [], int $max = 6 ): array {
		$out = [];
		foreach ( array_keys( self::variation_images( $pid ) ) as $aid ) {
			if ( count( $out ) >= $max ) {
				break;
			}
			if ( in_array( (int) $aid, array_map( 'intval', $skip ), true ) ) {
				continue;
			}
			try {
				$out[] = $this->fal_source_data_uri( (int) $aid, 'medium_large' );
			} catch ( \Throwable $e ) {
				continue;
			}
		}
		return $out;
	}

	/** Does this prompt ask for the other colours as well? */
	private static function wants_variants( array $row ): bool {
		return in_array( 'variation_photos', array_map( 'strval', (array) ( $row['inputs'] ?? [] ) ), true );
	}

	/** Does this prompt ask to SEE the product? */
	private static function wants_photos( array $row ): bool {
		foreach ( (array) ( $row['inputs'] ?? [] ) as $in ) {
			if ( self::is_image_input( (string) $in ) ) {
				return true;
			}
		}
		return false;
	}

	/** The paragraph that says what those photographs are and what to do with them. */
	private static function look_instruction( int $first, int $count ): string {
		if ( $count < 1 ) {
			return '';
		}
		$which = 1 === $count
			? sprintf( 'IMAGE %d IS A PHOTOGRAPH', $first )
			: sprintf( 'IMAGES %1$d TO %2$d ARE PHOTOGRAPHS', $first, $first + $count - 1 );
		return "\n===THE PRODUCT ITSELF===\n" . $which . ' of the product this text is about. Read the material, the cut, the finish, the fastenings and the real colours off them, and write from what is actually there. Never describe the photographs themselves and never mention them ("as you can see on the picture"): the reader has the product page in front of them. Never state anything the photographs and the data above do not support.' . "\n\n";
	}

	public function ajax_text(): void {
		$this->guard();
		$field  = isset( $_POST['field'] ) ? sanitize_key( wp_unslash( $_POST['field'] ) ) : '';
		$fields = self::fields();
		if ( ! isset( $fields[ $field ] ) || ! self::field_enabled( $field ) ) {
			wp_send_json_error( [ 'message' => __( 'Unknown or disabled field.', 'dazont-ecom' ) ] );
		}
		$pid   = isset( $_POST['post'] ) ? absint( $_POST['post'] ) : 0;
		$title = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
		$desc  = isset( $_POST['desc'] ) ? sanitize_textarea_field( wp_unslash( $_POST['desc'] ) ) : '';
		$attr  = isset( $_POST['attr'] ) ? sanitize_textarea_field( wp_unslash( $_POST['attr'] ) ) : '';

		// Server-side payload from the prompt's SELECTED inputs when nothing is posted.
		$payload = '';
		if ( '' !== $title || '' !== $desc || '' !== $attr ) {
			$payload = ( $title ? "Title: {$title}\n" : '' ) . ( $desc ? "Description: {$desc}\n" : '' ) . ( $attr ? "Attributes / supplier data: {$attr}\n" : '' );
		} elseif ( $pid ) {
			$row     = self::registry_row( $field );
			$payload = self::payload_lines( $pid, (array) ( $row['inputs'] ?? [ 'title', 'description', 'attributes', 'price' ] ), (string) ( $row['inputs_meta'] ?? '' ) );
		}
		// This prompt asked to see the product: the photographs travel with it,
		// and a prompt fed on photographs ALONE is a legitimate brief — the
		// product data being empty is not a reason to refuse it.
		$one_row = self::registry_row( $field );
		$look    = ( $pid && self::wants_photos( $one_row ) )
			? $this->look_images( $pid, [], 8, self::wants_variants( $one_row ) )
			: [];
		if ( '' === trim( $payload ) && ! $look ) {
			wp_send_json_error( [ 'message' => __( 'Fill in the product data first.', 'dazont-ecom' ) ] );
		}
		$override = isset( $_POST['prompt'] ) ? sanitize_textarea_field( wp_unslash( $_POST['prompt'] ) ) : '';
		$system   = 'You are an expert e-commerce copywriter. ' . self::store_context();
		$user     = ( '' !== trim( $override ) ? $override : self::prompt_for( $field ) ) . self::language_rule()
			. "\n\n--- PRODUCT DATA ---\n" . $payload . "\n";
		if ( $look ) {
			$user .= self::look_instruction( 1, count( $look ) );
		}
		try {
			$text = $look
				? DZE_Marketing_Ai::complete_with_images( $system, $user, $look, self::model(), (int) ( $fields[ $field ]['tokens'] ?? 400 ), 240 )
				: DZE_Marketing_Ai::complete( $system, $user, self::model(), (int) ( $fields[ $field ]['tokens'] ?? 400 ) );
		} catch ( \Throwable $e ) {
			wp_send_json_error( [ 'message' => $e->getMessage() ] );
		}
		wp_send_json_success( [ 'field' => $field, 'text' => $text ] );
	}

	/**
	 * Generates ALL requested fields in ONE model call (each field keeps its own
	 * verbatim prompt, executed independently inside the call) — this is what
	 * makes per-product generation fast: one round-trip instead of one per field.
	 * With apply=1 (bulk) every validated field is written to its destination
	 * server-side too, so a whole product needs a single HTTP request.
	 */
	public function ajax_text_all(): void {
		$this->guard();
		$pid   = isset( $_POST['post'] ) ? absint( $_POST['post'] ) : 0;
		$apply = ! empty( $_POST['apply'] );
		$req   = isset( $_POST['fields'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['fields'] ) ) : [];

		$targets = [];
		foreach ( self::enabled_fields() as $fid => $f ) {
			if ( $req && ! in_array( $fid, $req, true ) ) {
				continue;
			}
			if ( $apply && ! self::field_validated( $fid ) ) {
				continue; // bulk applies directly: only validated prompts.
			}
			$targets[ $fid ] = $f;
		}
		if ( empty( $targets ) ) {
			wp_send_json_error( [ 'message' => __( 'No enabled field to generate.', 'dazont-ecom' ) ] );
		}

		$title = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
		$desc  = isset( $_POST['desc'] ) ? sanitize_textarea_field( wp_unslash( $_POST['desc'] ) ) : '';
		$attr  = isset( $_POST['attr'] ) ? sanitize_textarea_field( wp_unslash( $_POST['attr'] ) ) : '';
		if ( '' !== $title || '' !== $desc || '' !== $attr ) {
			$payload = ( $title ? "Title: {$title}\n" : '' ) . ( $desc ? "Description: {$desc}\n" : '' ) . ( $attr ? "Attributes / supplier data: {$attr}\n" : '' );
		} else {
			// Union of the selected inputs across every requested prompt.
			$union = [];
			$umeta = [];
			foreach ( $targets as $fid => $f ) {
				$row = self::registry_row( $fid );
				foreach ( (array) ( $row['inputs'] ?? [] ) as $ink ) { $union[ $ink ] = 1; }
				if ( ! empty( $row['inputs_meta'] ) ) { $umeta[] = (string) $row['inputs_meta']; }
			}
			$payload = self::payload_lines( $pid, array_keys( $union ) ?: [ 'title', 'description', 'attributes', 'price' ], implode( ',', $umeta ) );
		}
		// A block that asked to SEE the product is briefed by its photographs:
		// they are sent once for the whole request, after the ones a block was
		// written against, and empty product data is not a reason to refuse it.
		$look_n        = 0;
		$look_variants = false;
		foreach ( array_keys( $targets ) as $fid ) {
			$row_look = self::registry_row( (string) $fid );
			if ( self::wants_photos( $row_look ) ) {
				$look_n = 1;
				if ( self::wants_variants( $row_look ) ) {
					$look_variants = true;
				}
			}
		}
		if ( '' === trim( $payload ) && ! ( $look_n && $pid ) ) {
			wp_send_json_error( [ 'message' => __( 'Fill in the product data first.', 'dazont-ecom' ) ] );
		}

		$system = 'You are an expert e-commerce copywriter writing in ' . self::site_language() . '. ' . self::store_context();
		$user   = "--- PRODUCT DATA ---\n" . $payload . "\n";
		$user .= "\nGenerate the " . count( $targets ) . " fields below. Each field has its OWN instructions, coming from separate proven scripts — follow each set EXACTLY and independently, as if it were the only task.\n";
		$user .= "OUTPUT FORMAT (strict): for each field output a line exactly ===FIELD:<field_id>=== followed by that field's content, then after the last field a line ===END===. Nothing else.\n";
		$user .= 'LANGUAGE: every field is written in ' . self::site_language() . ", whatever language the instructions below are written in.\n\n";
		// One-off prompt overrides from the live editors (never saved here).
		$overrides = [];
		if ( isset( $_POST['prompts'] ) && is_array( $_POST['prompts'] ) ) {
			foreach ( wp_unslash( $_POST['prompts'] ) as $ofid => $op ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized below.
				$overrides[ sanitize_key( $ofid ) ] = sanitize_textarea_field( (string) $op );
			}
		}
		// A block written against a photograph only exists if there IS one: with
		// a thin gallery the second block is dropped rather than written about
		// an image that was never chosen.
		$companions = self::companion_map( $pid );
		foreach ( array_keys( $targets ) as $fid ) {
			if ( '' !== self::companion_meta( (string) $fid ) && ! isset( $companions[ $fid ] ) ) {
				unset( $targets[ $fid ] );
			}
		}
		if ( empty( $targets ) ) {
			wp_send_json_error( [ 'message' => __( 'Nothing to write: these blocks need product photographs to zoom in on.', 'dazont-ecom' ) ] );
		}

		$tokens = 300;
		$shots  = [];
		foreach ( $targets as $fid => $f ) {
			$p     = ! empty( $overrides[ $fid ] ) ? $overrides[ $fid ] : self::prompt_for( $fid );
			$user .= '===INSTRUCTIONS for field "' . $fid . '" (' . $f['label'] . ")===\n" . $p . "\n\n";
			if ( isset( $companions[ $fid ] ) ) {
				$n       = count( $shots ) + 1;
				$shots[] = (int) $companions[ $fid ]['id'];
				$user   .= '===THE PHOTOGRAPH BESIDE FIELD "' . $fid . '"===' . "\n"
					. 'This block is displayed next to image ' . $n . ' above, which shows: '
					. $companions[ $fid ]['feature'] . ".\n"
					. "Its h2 must be a selling angle zooming in on THAT particularity, and the body must argue that one point, from what is actually visible in the photograph. Write about the product, never about the photograph itself — no \"as you can see on the picture\".\n\n";
			}
			$tokens += (int) ( $f['tokens'] ?? 300 );
		}

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		DZE_Ai_Usage::unit( 'product_text' );
		try {
			if ( $shots || $look_n ) {
				// The model writes about a photograph it can see, not about a
				// description of one. The images referenced by the blocks travel
				// with the request, in the order the instructions name them.
				$payload_images = [];
				foreach ( $shots as $aid ) {
					try {
						$uri = $this->fal_source_data_uri( (int) $aid, 'medium_large' );
					} catch ( \Throwable $e ) {
						continue;
					}
					if ( preg_match( '#^data:([^;]+);base64,(.+)$#', $uri, $mm ) ) {
						$payload_images[] = [ 'media' => $mm[1], 'data' => $mm[2] ];
					}
				}
				if ( $look_n ) {
					$look = $this->look_images( $pid, $shots, 8, $look_variants );
					if ( $look ) {
						$user          .= self::look_instruction( count( $payload_images ) + 1, count( $look ) );
						$payload_images = array_merge( $payload_images, $look );
					}
				}
				$text = $payload_images
					? DZE_Marketing_Ai::complete_with_images( $system, $user, $payload_images, self::model(), $tokens, 240 )
					: DZE_Marketing_Ai::complete( $system, $user, self::model(), $tokens, 240 );
			} else {
				$text = DZE_Marketing_Ai::complete( $system, $user, self::model(), $tokens, 240 );
			}
		} catch ( \Throwable $e ) {
			DZE_Ai_Usage::unit();
			wp_send_json_error( [ 'message' => $e->getMessage() ] );
		}
		DZE_Ai_Usage::unit();
		DZE_Ai_Usage::finished( 'product_text' );

		// What each block was written against, so the screens can show it next
		// to the text instead of leaving the choice invisible.
		$companion_out = [];
		foreach ( $companions as $cfid => $c ) {
			if ( ! isset( $targets[ $cfid ] ) ) {
				continue;
			}
			$companion_out[ $cfid ] = [
				'thumb'   => (string) ( wp_get_attachment_image_url( (int) $c['id'], 'thumbnail' ) ?: '' ),
				// The ORIGINAL file: a zoom that opens a resized copy is a zoom
				// that cannot answer the question it was clicked for.
				'full'    => (string) ( wp_get_attachment_image_url( (int) $c['id'], 'full' ) ?: '' ),
				'feature' => (string) $c['feature'],
			];
		}

		$texts = [];
		if ( preg_match_all( '/===FIELD:([a-z0-9_]+)===\s*(.*?)(?=\s*===FIELD:|\s*===END===)/s', $text . "\n===END===", $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $hit ) {
				if ( ! isset( $texts[ $hit[1] ] ) ) {
					$texts[ $hit[1] ] = trim( $hit[2] );
				}
			}
		}
		if ( empty( $texts ) ) {
			wp_send_json_error( [ 'message' => __( 'The AI returned an unreadable multi-field response. Try again.', 'dazont-ecom' ) ] );
		}

		if ( $apply ) {
			$results = [];
			foreach ( $targets as $fid => $f ) {
				if ( empty( $texts[ $fid ] ) ) {
					$results[ $fid ] = 'missing';
					continue;
				}
				try {
					$this->apply_value( $pid, $fid, wp_kses_post( $texts[ $fid ] ) );
					$results[ $fid ] = 'applied';
				} catch ( \Throwable $e ) {
					$results[ $fid ] = 'error';
				}
			}
			wp_send_json_success( [ 'results' => $results, 'texts' => $texts, 'companions' => $companion_out ] );
		}
		if ( ! empty( $_POST['stash'] ) ) {
			self::stash( $pid, [ 'texts' => $texts, 'companions' => $companion_out ] );
		}
		wp_send_json_success( [ 'texts' => $texts, 'companions' => $companion_out ] );
	}

	public function ajax_apply(): void {
		$this->guard();
		$pid    = isset( $_POST['post'] ) ? absint( $_POST['post'] ) : 0;
		$field  = isset( $_POST['field'] ) ? sanitize_key( wp_unslash( $_POST['field'] ) ) : '';
		if ( '' !== $field && ! self::field_validated( $field ) ) {
			wp_send_json_error( [ 'message' => __( 'This prompt is not validated yet — tick its "Prompt validated" box in Settings → Product content.', 'dazont-ecom' ) ] );
		}
		$value  = isset( $_POST['value'] ) ? wp_kses_post( wp_unslash( $_POST['value'] ) ) : '';
		$fields = self::fields();
		if ( ! $pid || ! isset( $fields[ $field ] ) ) {
			wp_send_json_error( [ 'message' => __( 'Invalid request.', 'dazont-ecom' ) ] );
		}
		try {
			$note = $this->apply_value( $pid, $field, $value );
		} catch ( \Throwable $e ) {
			wp_send_json_error( [ 'message' => $e->getMessage() ] );
		}
		wp_send_json_success( $note ? [ 'note' => $note ] : [] );
	}

	public function ajax_price_preview(): void {
		$this->guard();
		$pid  = isset( $_POST['post'] ) ? absint( $_POST['post'] ) : 0;
		$cost = isset( $_POST['cost'] ) ? (float) wp_unslash( $_POST['cost'] ) : 0;
		$product = $pid ? wc_get_product( $pid ) : null;
		if ( ! $product instanceof WC_Product ) {
			wp_send_json_error( [ 'message' => __( 'Product not found.', 'dazont-ecom' ) ] );
		}
		if ( $cost <= 0 ) {
			$cost = (float) self::product_cost( $product );
		}
		if ( $cost <= 0 ) {
			wp_send_json_error( [ 'message' => __( 'No cost recorded on this product, and none typed in the box: there is nothing to calculate from.', 'dazont-ecom' ) ] );
		}

		// The table, with the row this cost falls into marked.
		$table = [];
		foreach ( self::price_table() as $row ) {
			$min = (float) ( $row['min'] ?? 0 );
			$max = (float) ( $row['max'] ?? 0 );
			$table[] = [
				'min'  => self::price_text( $min ),
				'max'  => $max > 0 ? self::price_text( $max ) : '∞',
				'mult' => (float) ( $row['mult'] ?? 1 ),
				'hit'  => ( $cost >= $min && ( $max <= 0 || $cost <= $max ) ),
			];
		}

		$rows = [];
		if ( $product->is_type( 'variable' ) ) {
			foreach ( $product->get_children() as $vid ) {
				$variation = wc_get_product( (int) $vid );
				if ( ! $variation instanceof WC_Product ) {
					continue;
				}
				// Each variation is priced from ITS OWN recorded cost when it has
				// one — that is exactly what the run does, so that is what the
				// preview must show.
				$vcost = (float) ( self::cost_meta( (int) $vid ) ?: $cost );
				if ( $vcost <= 0 ) {
					continue;
				}
				$rows[] = [
					'name' => $variation->get_name(),
					'cost' => self::price_text( $vcost ),
					'now'  => '' !== $variation->get_regular_price() ? self::price_text( (float) $variation->get_regular_price() ) : '—',
					'next' => self::price_text( DZE_Price::charm( $vcost * self::mult_for_cost( $vcost ), 'up' ) ),
				];
			}
		} else {
			$rows[] = [
				'name' => $product->get_name(),
				'cost' => self::price_text( $cost ),
				'now'  => '' !== $product->get_regular_price() ? self::price_text( (float) $product->get_regular_price() ) : '—',
				'next' => self::price_text( DZE_Price::charm( $cost * self::mult_for_cost( $cost ), 'up' ) ),
			];
		}

		$explain = $product->is_type( 'variable' )
			? __( 'Each variation is priced from its own recorded cost when it has one, and from the cost in the box when it has none. The cost is also written to the WooCommerce Cost of Goods field. Prices are rounded up to the ending set under Settings → General.', 'dazont-ecom' )
			: __( 'The cost × the multiplier of the matching range gives the regular price. The cost is also written to the WooCommerce Cost of Goods field, and the price is rounded up to the ending set under Settings → General.', 'dazont-ecom' );

		wp_send_json_success( [
			'explain' => $explain,
			'table'   => $table,
			'rows'    => array_slice( $rows, 0, 60 ), // a preview, not a report.
		] );
	}

	public function ajax_price(): void {
		$this->guard();
		$pid  = isset( $_POST['post'] ) ? absint( $_POST['post'] ) : 0;
		$cost = isset( $_POST['cost'] ) ? (float) wp_unslash( $_POST['cost'] ) : 0;
		if ( ! $pid || $cost <= 0 ) {
			wp_send_json_error( [ 'message' => __( 'Enter a valid cost.', 'dazont-ecom' ) ] );
		}
		$mult    = self::mult_for_cost( $cost );
		// Rounded UP when charm rounding is on: a selling price built from a
		// cost must never lose margin to the presentation.
		$regular = DZE_Price::charm( $cost * $mult, 'up' );
		// Deterministic math on an explicit action — no prompt involved, applies directly.
		$product = wc_get_product( $pid );
		if ( ! $product instanceof WC_Product ) {
			wp_send_json_error( [ 'message' => __( 'Product not found.', 'dazont-ecom' ) ] );
		}
		update_post_meta( $pid, '_dze_cogs', $cost );
		update_post_meta( $pid, '_cogs_value', $cost ); // WooCommerce native Cost of Goods.

		if ( $product->is_type( 'variable' ) ) {
			// A regular price on a variable parent is meta WooCommerce never
			// displays — the shop reads the variations. Writing it there was a
			// silent no-op. Each variation is recomputed from ITS OWN recorded
			// cost when it has one, so a run does not flatten a range of
			// different costs onto the single figure typed in the box.
			$prices = [];
			$done   = 0;
			foreach ( $product->get_children() as $vid ) {
				$variation = wc_get_product( (int) $vid );
				if ( ! $variation instanceof WC_Product ) {
					continue;
				}
				$vcost = (float) ( self::cost_meta( (int) $vid ) ?: $cost );
				if ( $vcost <= 0 ) {
					continue;
				}
				$vmult = self::mult_for_cost( $vcost );
				$vreg  = DZE_Price::charm( $vcost * $vmult, 'up' );
				update_post_meta( (int) $vid, '_dze_cogs', $vcost );
				update_post_meta( (int) $vid, '_cogs_value', $vcost );
				$variation->set_regular_price( (string) $vreg );
				$variation->save();
				$prices[] = $vreg;
				$done++;
			}
			if ( ! $done ) {
				wp_send_json_error( [ 'message' => __( 'This variable product has no variation to price.', 'dazont-ecom' ) ] );
			}
			// Without this the parent keeps serving the old price range from
			// its own cached meta and transients.
			if ( class_exists( 'WC_Product_Variable' ) ) {
				WC_Product_Variable::sync( $pid );
			}
			$lo    = min( $prices );
			$hi    = max( $prices );
			$label = $lo === $hi ? (string) $lo : $lo . '–' . $hi;
			wp_send_json_success( [
				'mult'       => $mult,
				'regular'    => $label,
				'variations' => $done,
				'applied'    => true,
			] );
		}

		$product->set_regular_price( (string) $regular );
		$product->save();
		wp_send_json_success( [ 'mult' => $mult, 'regular' => $regular, 'applied' => true ] );
	}

	/**
	 * The fast lane: one photograph in, one catalogue main image out.
	 *
	 * The full toolbox asks which prompts, which scene, how many attempts, and
	 * then holds the result for review — right for a batch, far too slow for
	 * the one thing done constantly: a supplier photograph that cannot be the
	 * main image of a listing. Here there is one recipe, one source, one image,
	 * and the next click puts it in place.
	 */
	
	/**
	 * Switches one prompt on or off, there and then.
	 *
	 * A tick that only counts once the whole page has been saved is a tick you
	 * cannot trust: you leave the screen sure a field is off when it is still
	 * on. This writes that one flag and answers.
	 */
	public function ajax_prompt_toggle(): void {
		$this->guard();
		$id = isset( $_POST['id'] ) ? sanitize_key( wp_unslash( $_POST['id'] ) ) : '';
		$on = ! empty( $_POST['on'] ) ? 1 : 0;
		$rows  = self::registry();
		$found = false;
		foreach ( $rows as $k => $r ) {
			if ( (string) ( $r['id'] ?? '' ) === $id ) {
				$rows[ $k ]['enabled'] = $on;
				$found = true;
				break;
			}
		}
		if ( ! $found ) {
			wp_send_json_error( [ 'message' => __( 'Unknown prompt.', 'dazont-ecom' ) ] );
		}
		try {
			self::write_setting( 'registry', $rows );
		} catch ( \Throwable $e ) {
			wp_send_json_error( [ 'message' => $e->getMessage() ] );
		}
		wp_send_json_success( [ 'id' => $id, 'on' => $on ] );
	}

	/**
	 * Keeps an image as a background, from wherever it was picked.
	 *
	 * A background prepared outside WordPress — a studio floor for rugs, a
	 * table top — is chosen on the product screen, at the moment it is needed,
	 * and joins the same list the settings show. There is no second place to
	 * store one.
	 */
	public function ajax_bg_add(): void {
		$this->guard();
		$id   = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$name = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		if ( ! $id || ! wp_attachment_is_image( $id ) ) {
			wp_send_json_error( [ 'message' => __( 'That is not an image.', 'dazont-ecom' ) ] );
		}
		$settings = self::get_settings();
		$rows     = (array) ( $settings['scenes'] ?? [] );
		foreach ( $rows as $r ) {
			if ( (int) ( $r['image'] ?? 0 ) === $id ) {
				wp_send_json_success( [ 'id' => $id, 'already' => true ] ); // already kept.
			}
		}
		$rows[] = [
			'name'    => '' !== $name ? $name : __( 'Background', 'dazont-ecom' ),
			'image'   => $id,
			'prompt'  => '',
			'default' => empty( $rows ),
		];
		try {
			self::write_setting( 'scenes', $rows );
		} catch ( \Throwable $e ) {
			wp_send_json_error( [ 'message' => $e->getMessage() ] );
		}
		wp_send_json_success( [
			'id'    => $id,
			'name'  => (string) $rows[ count( $rows ) - 1 ]['name'],
			'thumb' => (string) wp_get_attachment_image_url( $id, 'thumbnail' ),
		] );
	}

	public function ajax_quick_main(): void {
		$this->guard();
		$pid  = isset( $_POST['post'] ) ? absint( $_POST['post'] ) : 0;
		$note = isset( $_POST['note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['note'] ) ) : '';
		// A recipe typed for this run only, never saved unless asked.
		$override = isset( $_POST['prompt'] ) ? sanitize_textarea_field( wp_unslash( $_POST['prompt'] ) ) : '';
		// An image pasted straight into the lane (Ctrl+V or dropped): it arrives
		// as a data URI, never as a URL, so nothing is fetched from anywhere.
		// Several of them, in fact: three supplier shots of the same jacket, none
		// of them usable as it stands, tell the model far more together than the
		// best of them alone. The first is the subject; the others are context.
		$pastes = isset( $_POST['pastes'] ) ? (array) wp_unslash( $_POST['pastes'] ) : []; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated as images below.
		$paste  = isset( $_POST['paste'] ) ? (string) wp_unslash( $_POST['paste'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated as an image below.
		if ( ! $pastes && '' !== $paste ) {
			$pastes = [ $paste ];
		}
		// The surface to put the product on: a background, or none.
		$bg = isset( $_POST['bg'] ) ? absint( $_POST['bg'] ) : 0;
		// ONE photograph of the product as the source — remaking a supplier
		// shot is work done on that shot, not on the product in general.
		$src_id = isset( $_POST['src_id'] ) ? absint( $_POST['src_id'] ) : 0;
		// Which recipe: a registry image prompt, or the main-image one.
		$recipe = isset( $_POST['recipe'] ) ? sanitize_key( wp_unslash( $_POST['recipe'] ) ) : '';
		if ( ! $pid ) {
			wp_send_json_error( [ 'message' => __( 'Save the product first.', 'dazont-ecom' ) ] );
		}
		if ( '' === self::fal_key() ) {
			wp_send_json_error( [ 'message' => __( 'Add your fal.ai key under Settings → General first.', 'dazont-ecom' ) ] );
		}
		if ( class_exists( 'DZE_Ai_Usage' ) && DZE_Ai_Usage::over_budget() ) {
			wp_send_json_error( [ 'message' => DZE_Ai_Usage::budget_message() ] );
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 180 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		try {
			$sources = [];
			// The photographs that come AFTER the subject: the product's own,
			// as context. They are not there to be redrawn — the instruction
			// sent with them says image 1 is the reference and the others fill
			// in what it does not show — but without them a pasted photograph
			// was all the model ever saw of the product, and it had to guess
			// the back, the lining, the fastenings and the material.
			$context = [];
			if ( $src_id && wp_attachment_is_image( $src_id ) ) {
				$sources[] = $this->fal_source_data_uri( $src_id, 'full' );
				$context   = array_values( array_diff( self::product_source_ids( $pid ), [ $src_id ] ) );
			} elseif ( $pastes ) {
				// The photographs are already in the request, straight from the
				// clipboard or picked on the computer, and the first is THE
				// subject: it stays image 1, the other pasted ones follow it,
				// and the product's own photographs come after them unless the
				// screen says to work from the pasted ones alone.
				$outside = self::read_data_uris( $pastes, self::MAX_PASTED, self::MAX_PAYLOAD );
				if ( ! $outside ) {
					throw new RuntimeException( __( 'That is not an image.', 'dazont-ecom' ) );
				}
				foreach ( $outside as $uri ) {
					$sources[] = $uri;
				}
				$context = ( ! isset( $_POST['with_product'] ) || ! empty( $_POST['with_product'] ) )
					? self::product_source_ids( $pid )
					: [];
			} else {
				// The product's own photographs, main first. Two are enough here:
				// this lane is about speed, and the shape of a product is settled
				// by the first shot plus one more angle.
				foreach ( array_slice( self::product_source_ids( $pid ), 0, 2 ) as $i => $aid ) {
					try {
						$sources[] = $this->fal_source_data_uri( (int) $aid, $i > 0 ? 'medium_large' : 'full' );
					} catch ( \Throwable $e ) {
						continue;
					}
				}
			}
			// The product's other angles, as many as the body can carry: the
			// weight is checked image by image just below, which is the only
			// limit that means anything here.
			foreach ( array_slice( $context, 0, self::MAX_SOURCES ) as $aid ) {
				try {
					$uri = $this->fal_source_data_uri( (int) $aid, 'medium_large' );
				} catch ( \Throwable $e ) {
					continue;
				}
				if ( array_sum( array_map( 'strlen', $sources ) ) + strlen( $uri ) > self::MAX_PAYLOAD ) {
					break;
				}
				$sources[] = $uri;
			}
			if ( ! $sources ) {
				throw new RuntimeException( __( 'No image to work from: set a featured image, or paste the address of one.', 'dazont-ecom' ) );
			}
			// The other colours of the same product, when the prompt asked for
			// them: they say what the construction is, and the paragraph that
			// names them says they say nothing about the colour.
			// The prompt row behind this run: it says where the image is meant
			// to go, how its file is named and what travels with it, and all
			// three have to survive until the image is accepted — possibly on
			// another screen.
			$recipe_row = '' !== $recipe ? self::registry_row( $recipe ) : self::main_recipe();
			$count      = count( $sources );
			$variants   = 0;
			if ( is_array( $recipe_row ) && self::wants_variants( $recipe_row ) ) {
				foreach ( $this->variant_images( $pid, self::product_source_ids( $pid ) ) as $uri ) {
					if ( array_sum( array_map( 'strlen', $sources ) ) + strlen( $uri ) > self::MAX_PAYLOAD ) {
						break;
					}
					$sources[] = $uri;
					$variants++;
				}
			}
			// The background travels as the LAST image, exactly like a scene: a
			// surface the model can see beats a colour it has to imagine, and it
			// is the same file for every product — which is the whole point.
			$plate = $bg && wp_attachment_is_image( $bg ) ? $bg : 0;
			if ( $plate ) {
				$sources[] = $this->fal_source_data_uri( $plate );
			}
			$base = '' !== trim( $override ) ? $override : self::quick_prompt();
			if ( '' === trim( $override ) && $recipe_row && '' !== trim( (string) ( $recipe_row['prompt'] ?? '' ) ) ) {
				$base = (string) $recipe_row['prompt'];
			}
			// What the surface IS decides how it is described: the shop's own
			// backdrop, one of the scenes, or a blank product to print on.
			$plate_row = null;
			if ( $plate ) {
				$plate_row = [ 'prompt' => 'This is the shop\'s backdrop: reproduce its exact tone and its gradient, and place the product on it. Do not add anything else to it, and do not darken it.' ];
				foreach ( self::scenes() as $sc ) {
					if ( (int) $sc['image'] === $plate ) {
						$plate_row = $sc;
						break;
					}
				}
			}
			$prompt = $base
				. ( '' !== $note ? "\n\nAlso: " . $note : '' )
				. self::sources_instruction( $count, $plate_row, 0, $variants )
				. self::note_lines( $pid );

			DZE_Ai_Usage::unit( 'product_img' );
			$image_url = $this->fal_generate( $prompt, $sources );
			DZE_Ai_Usage::unit();
			DZE_Ai_Usage::finished( 'product_img' );
		} catch ( \Throwable $e ) {
			DZE_Ai_Usage::unit();
			wp_send_json_error( [ 'message' => $e->getMessage() ] );
		}

		// Kept with the product, like any other pending result: a closed tab
		// does not lose the image that was just paid for.
		self::stash( $pid, [
			'shot'   => $image_url,
			'target' => $recipe_row ? ( ( ( $recipe_row['output'] ?? '' ) === 'main' ) ? 'main' : 'gallery' ) : 'main',
			'recipe' => $recipe_row ? (string) ( $recipe_row['id'] ?? '' ) : '',
		] );
		$main = (int) get_post_thumbnail_id( $pid );
		wp_send_json_success( [
			'url'  => $image_url,
			// Shown next to the new image and opened by its zoom: the original.
			'main' => $main ? (string) wp_get_attachment_image_url( $main, 'full' ) : '',
		] );
	}

	public function ajax_image(): void {
		$this->guard();
		$pid    = isset( $_POST['post'] ) ? absint( $_POST['post'] ) : 0;
		$idx    = isset( $_POST['template'] ) ? absint( $_POST['template'] ) : 0;
		$mode   = isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : '';
		$custom = isset( $_POST['custom_prompt'] ) ? sanitize_textarea_field( wp_unslash( $_POST['custom_prompt'] ) ) : '';
		$src    = isset( $_POST['src_url'] ) ? esc_url_raw( wp_unslash( $_POST['src_url'] ) ) : '';
		// A photograph that is not on the product yet — a supplier shot pasted
		// from a browser tab, its watermark and its play button included. It
		// travels as bytes inside the request and is never stored: it is the
		// subject of the generation, not a file the shop keeps.
		$paste  = isset( $_POST['paste'] ) ? (string) wp_unslash( $_POST['paste'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated as an image below.
		// Several photographs from outside the shop, the first one the subject.
		$pastes = isset( $_POST['pastes'] ) ? (array) wp_unslash( $_POST['pastes'] ) : []; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated as images below.
		if ( ! $pastes && '' !== $paste ) {
			$pastes = [ $paste ];
		}
		$paste = $pastes ? (string) $pastes[0] : '';
		if ( ! $pid ) {
			wp_send_json_error( [ 'message' => __( 'Save the product first.', 'dazont-ecom' ) ] );
		}
		if ( '' === self::fal_key() ) {
			wp_send_json_error( [ 'message' => __( 'Add your fal.ai key under Settings → General first.', 'dazont-ecom' ) ] );
		}
		if ( class_exists( 'DZE_Ai_Usage' ) && DZE_Ai_Usage::over_budget() ) {
			wp_send_json_error( [ 'message' => DZE_Ai_Usage::budget_message() ] );
		}
		$templates = self::image_templates();
		$tpl       = $templates[ $idx ] ?? $templates[0] ?? null;
		if ( ! $tpl && '' === $custom ) {
			wp_send_json_error( [ 'message' => __( 'No image template configured.', 'dazont-ecom' ) ] );
		}
		// Where this image is meant to land. The prompt says it, and the screen
		// that ordered the run may have said otherwise — a decision taken once
		// before the run rather than corrected on thirty images afterwards.
		$target = (string) ( $tpl['target'] ?? 'gallery' );
		if ( isset( $_POST['target'] ) ) {
			$target = self::attach_target( (string) wp_unslash( $_POST['target'] ) );
		}
		// One group of variations — "the olive ones" — asked for by key. It
		// decides the destination on its own: an image made for a colour goes
		// to that colour's variations and nowhere else.
		$variation = isset( $_POST['variation'] ) ? (string) wp_unslash( $_POST['variation'] ) : '';
		$v_attr    = '';
		$v_value   = '';
		if ( '' !== $variation ) {
			$target = self::attach_target( 'variation:' . $variation );
			if ( 0 !== strpos( $target, 'variation:' ) ) {
				wp_send_json_error( [ 'message' => __( 'Unknown variation group.', 'dazont-ecom' ) ] );
			}
			[ $v_attr, $v_value ] = array_pad( explode( '::', substr( $target, 10 ), 2 ), 2, '' );
		}

		// Source image: an earlier AI result (live edit) or the featured image.
		if ( '' !== $src && ! self::is_fal_url( $src ) ) {
			wp_send_json_error( [ 'message' => __( 'Invalid source image.', 'dazont-ecom' ) ] );
		}
		// The scene: the fixed support or background this shop always shoots on.
		// A one-off edit of an image that already exists ("make the strap red")
		// keeps that image's own setting, so the scene only comes back if it was
		// explicitly asked for.
		$scenes = self::scenes();
		$sidx   = isset( $_POST['scene'] ) ? (int) $_POST['scene'] : ( '' !== $src ? -1 : self::default_scene() );
		$scene  = ( $sidx >= 0 && isset( $scenes[ $sidx ] ) ) ? $scenes[ $sidx ] : null;
		if ( $scene && ! wp_attachment_is_image( (int) $scene['image'] ) ) {
			// Deleted from the media library: say so instead of failing on the
			// product image, which is what the generic reader error would blame.
			wp_send_json_error( [ 'message' => __( 'The scene image is missing from the media library — pick it again under Settings → Product content.', 'dazont-ecom' ) ] );
		}
		// Every photograph of the product goes out, not just the featured one:
		// a single cropped shot is what makes the model invent the rest.
		$product_ids = self::product_source_ids( $pid );
		// Working on one colour: the photograph that colour already has is the
		// subject, and it goes first. When it has none — the case this whole
		// function exists for — the product's own photographs are what the
		// colour is built from.
		$v_own = 0;
		if ( '' !== $v_value ) {
			foreach ( self::variation_ids( $pid, $v_attr, $v_value ) as $vid ) {
				$shot = (int) get_post_thumbnail_id( $vid );
				if ( $shot ) {
					$v_own = $shot;
					break;
				}
			}
			if ( $v_own ) {
				$product_ids = array_values( array_unique( array_merge( [ $v_own ], $product_ids ) ) );
			}
		}
		if ( '' === $src && '' === $paste && ! $product_ids ) {
			wp_send_json_error( [ 'message' => __( 'Set a featured image on this product first.', 'dazont-ecom' ) ] );
		}

		// On a variation run every product line is read for THAT variation:
		// "Product title" is the variation's full name — the product plus
		// everything the whole group has in common, not the colour alone.
		$v_label = '' !== $v_value ? self::attribute_value_label( $v_attr, $v_value ) : '';
		$v_name  = '' !== $v_value ? self::variation_group_name( $pid, $v_attr, $v_value ) : '';
		$pl   = $tpl
			? self::payload_lines( $pid, (array) ( $tpl['inputs'] ?? [ 'title', 'description' ] ), (string) ( $tpl['inputs_meta'] ?? '' ), $v_name )
			: self::payload_lines( $pid, [ 'title', 'description' ], '', $v_name );
		$pl   = mb_substr( trim( (string) preg_replace( '/\s+/', ' ', $pl ) ), 0, 800 );
		$ctx  = trim( self::store_context() . ' ' . $pl );
		$base = '' !== $custom ? $custom : (string) $tpl['prompt'];
		$prompt = ( $ctx ? "Product context: {$ctx}\n\n" : '' ) . $base;
		// {variation} is the name of the group being made — "Multicam Black" —
		// so the prompt can say it in its own words instead of relying on the
		// line the plugin appends. Outside a variation run it resolves to
		// nothing rather than staying on screen as a token.
		$prompt = str_replace(
			[ '{variation}', '{variation_attribute}' ],
			[
				$v_label,
				'' !== $v_attr ? (string) wc_attribute_label( $v_attr ) : '',
			],
			$prompt
		);

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 180 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		$validated = self::template_validated( $idx );
		try {
			// Sources: fal's own CDN URLs pass through; local files go as data URIs
			// (fal cannot always fetch staging/hotlink-protected site URLs).
			$sources = [];
			$weight  = 0;
			if ( '' !== $src ) {
				// Editing one precise image: that image is the subject, on its own.
				$sources[] = $src;
			} elseif ( $pastes ) {
				// The pasted photographs come first — the first of them is the
				// subject, the others say what it does not show — and the
				// product's own photographs follow as context, so the model
				// knows the product beyond the shots it was handed.
				$outside = self::read_data_uris( $pastes, self::MAX_PASTED, self::MAX_PAYLOAD );
				if ( ! $outside ) {
					throw new RuntimeException( __( 'That is not an image.', 'dazont-ecom' ) );
				}
				foreach ( $outside as $uri ) {
					$sources[] = $uri;
				}
				// The product's own photographs come after them, as CONTEXT,
				// and few: the pasted set is the subject, and a subject sent
				// with six photographs of the product in another colour is a
				// subject the model stops looking at. Two on a variation run,
				// where the other colours are precisely what must not bleed in;
				// four otherwise.
				$ctx_max = '' !== $v_value ? 2 : 4;
				foreach ( array_slice( $product_ids, 0, $ctx_max ) as $aid ) {
					try {
						$uri = $this->fal_source_data_uri( (int) $aid, 'medium_large' );
					} catch ( \Throwable $e ) {
						continue;
					}
					if ( array_sum( array_map( 'strlen', $sources ) ) + strlen( $uri ) > self::MAX_PAYLOAD ) {
						break;
					}
					$sources[] = $uri;
				}
			} else {
				// Everything we have of this product. The featured image comes
				// first and is never dropped; the rest joins while the request
				// body stays a sane size, and a broken file is skipped instead
				// of taking the whole generation down with it.
				//
				// Remaking the MAIN image is a different job: there is one
				// subject, the photograph that holds the slot, and the others
				// are there to say what the product looks like from behind. Six
				// photographs sent for that is how a remake came back built on a
				// gallery shot, in a setting of its own — so the main lane sends
				// the featured image plus two, exactly like the toolbox that
				// does this well.
				$ids_out = ( 'main' === $target || 0 === strpos( $target, 'variation:' ) )
					? array_slice( $product_ids, 0, 3 )
					: $product_ids;
				foreach ( $ids_out as $i => $aid ) {
					try {
						// The featured image is the one the result is built on,
						// so it goes at full working size; the others are read
						// for information only and travel smaller — a lighter
						// request body and a faster answer, same understanding.
						$uri = $this->fal_source_data_uri( (int) $aid, $i > 0 ? 'medium_large' : 'full' );
					} catch ( \Throwable $e ) {
						continue;
					}
					if ( $i > 0 && ( $weight + strlen( $uri ) ) > self::MAX_PAYLOAD ) {
						break;
					}
					$weight   += strlen( $uri );
					$sources[] = $uri;
				}
			}
			if ( ! $sources ) {
				throw new RuntimeException( __( 'Could not read the product image file.', 'dazont-ecom' ) );
			}
			$product_count = count( $sources );
			// The other colours of the same product, when the prompt asked for
			// them: never on a variation run — there, the colour being made is
			// the whole subject and its neighbours are exactly the confusion to
			// keep out.
			$variants = 0;
			if ( '' === $v_value && $tpl && self::wants_variants( self::registry_row( (string) ( $tpl['id'] ?? '' ) ) ) ) {
				foreach ( $this->variant_images( $pid, $product_ids ) as $uri ) {
					if ( array_sum( array_map( 'strlen', $sources ) ) + strlen( $uri ) > self::MAX_PAYLOAD ) {
						break;
					}
					$sources[] = $uri;
					$variants++;
				}
			}
			// What this prompt has already produced here goes out WITH the
			// order, right after the product photographs: told in words that
			// the last one must not come back, the model handed it back anyway.
			// Never on a main image — there is one right main image, not four
			// different ones — and never on a variation, where the whole point
			// is one image per colour.
			$avoid = 0;
			if ( '' === $src && 'main' !== $target && '' === $v_value ) {
				foreach ( self::avoid_sources( $pid, (string) ( $tpl['id'] ?? '' ), 4 ) as $ref ) {
					if ( is_int( $ref ) ) {
						// One already on the product: read from disk, and only
						// while the request body stays a sane size.
						try {
							$uri = $this->fal_source_data_uri( $ref, 'medium_large' );
						} catch ( \Throwable $e ) {
							continue;
						}
						if ( array_sum( array_map( 'strlen', $sources ) ) + strlen( $uri ) > self::MAX_PAYLOAD ) {
							break;
						}
						$sources[] = $uri;
					} else {
						// One still waiting: it lives on fal's own CDN, so it
						// travels as a URL and weighs nothing.
						$sources[] = $ref;
					}
					$avoid++;
				}
			}
			if ( $scene ) {
				$sources[] = $this->fal_source_data_uri( (int) $scene['image'] );
			}
			$prompt   .= self::sources_instruction( $product_count, $scene, $avoid, $variants );
			if ( '' !== $v_value ) {
				// A pasted photograph IS that variation: it is shown as it is,
				// and only the picture around it has to be redone.
				$prompt .= self::variation_instruction( $v_attr, $v_value, $v_own || '' !== $paste );
			}
			// What the owner knows and no photograph shows — about the product,
			// and about this variation when there is one.
			$prompt .= self::note_lines( $pid, '' !== $v_value ? $v_attr . '::' . $v_value : '' );
			// A second shot from the same prompt is asked for a different
			// framing, otherwise it comes back as the first one again.
			$prompt   .= self::variation_line(
				$pid,
				(string) ( $tpl['id'] ?? '' ),
				'' !== $v_value ? 'main' : $target,
				isset( $_POST['attempt'] ) ? absint( $_POST['attempt'] ) : 0
			);
			DZE_Ai_Usage::unit( 'product_img' );
			$image_url = $this->fal_generate( $prompt, $sources );
			DZE_Ai_Usage::unit();
			DZE_Ai_Usage::finished( 'product_img' );

			if ( 'defer' === $mode ) {
				// Toolbox flow: never auto-attach — the result joins the session
				// gallery; a human selects what gets pushed to the product.
				if ( ! empty( $_POST['stash'] ) ) {
					self::stash( $pid, [
						'shot'   => $image_url,
						'target' => $target,
						'recipe' => (string) ( $tpl['id'] ?? '' ),
					] );
				}
				wp_send_json_success( [ 'url' => $image_url, 'target' => $target ] );
			}
			if ( ! $validated ) {
				wp_send_json_success( [ 'preview' => true, 'url' => $image_url, 'target' => $target ] );
			}
			$att_id = $this->sideload_seo( $image_url, $pid, $target, (string) ( $tpl['id'] ?? '' ) );
		} catch ( \Throwable $e ) {
			wp_send_json_error( [ 'message' => $e->getMessage() ] );
		}
		wp_send_json_success( [
			'attachment' => (int) $att_id,
			'target'     => $target,
			'url'        => wp_get_attachment_image_url( (int) $att_id, 'medium' ),
		] );
	}

	/**
	 * Pushes selected session-gallery images onto the product. Standard SEO
	 * procedure on the way in: the attachment file name, title, slug and alt all
	 * take the product title (WordPress natively de-duplicates with -1/-2/-3).
	 */
	/**
	 * The bulk list is the owner's working set, so it has to be editable: take
	 * one product out, take the ticked ones out, or empty it. Before this the
	 * only way to change your mind was to go back to the products list and
	 * queue a new selection from scratch.
	 */
	public function ajax_bulk_list(): void {
		$this->guard();
		$do  = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';
		$ids = isset( $_POST['ids'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['ids'] ) ) : [];

		// Delete means one thing on this screen: the product leaves the list,
		// and whatever was waiting on it is thrown away. Two half-actions worded
		// differently on each tab — "Remove from the list" here, "Throw away
		// what is waiting" there — is how a product came back on the other tab
		// after being taken out of this one.
		$drop = ! empty( $_POST['drop_pending'] );
		if ( 'counts' === $do ) {
			// Read-only: the screen asking what the tabs should say, after a run
			// that has just put content on a dozen products.
			wp_send_json_success( [ 'counts' => self::screen_counts() ] );
		}
		if ( 'clear' === $do ) {
			if ( $drop ) {
				foreach ( $ids ?: self::pending_ids() as $one ) {
					self::drop_product( (int) $one );
				}
			}
			self::set_bulk_list( [] );
			wp_send_json_success( [ 'left' => [], 'counts' => self::screen_counts() ] );
		}
		if ( 'remove' === $do && $ids ) {
			if ( $drop ) {
				foreach ( $ids as $one ) {
					self::drop_product( (int) $one );
				}
			}
			$list = array_values( array_diff( self::bulk_list(), $ids ) );
			self::set_bulk_list( $list );
			// The list as it now stands, read back: the screen shows what the
			// server holds, not what it hoped the server would hold.
			wp_send_json_success( [ 'left' => self::bulk_list(), 'counts' => self::screen_counts() ] );
		}
		if ( 'add' === $do ) {
			$this->bulk_add_ids( $ids, ! empty( $_POST['replace'] ) );
		}
		wp_send_json_error( [ 'message' => __( 'Invalid request.', 'dazont-ecom' ) ] );
	}

	/**
	 * What the product says TODAY: its texts and its photographs.
	 *
	 * Fetched only when a review panel is opened, never with the list — it is
	 * one product's worth of data, asked for at the moment somebody wants to
	 * compare the new text with the old one, or check that a generated image
	 * adds something the gallery does not already have.
	 */
	public function ajax_current(): void {
		$this->guard();
		$pid     = isset( $_POST['post'] ) ? absint( $_POST['post'] ) : 0;
		$product = $pid ? wc_get_product( $pid ) : null;
		if ( ! $product instanceof WC_Product ) {
			wp_send_json_error( [ 'message' => __( 'Product not found.', 'dazont-ecom' ) ] );
		}
		$seo   = self::seo_keys();
		$texts = [];
		foreach ( self::enabled_fields() as $fid => $f ) {
			$dest  = self::dest_for( (string) $fid );
			$value = '';
			switch ( $dest['type'] ) {
				case 'post_title':
					$value = get_the_title( $pid );
					break;
				case 'post_content':
					$value = (string) get_post_field( 'post_content', $pid );
					break;
				case 'post_excerpt':
					$value = (string) get_post_field( 'post_excerpt', $pid );
					break;
				case 'seo_title':
					$value = (string) get_post_meta( $pid, $seo['title'], true );
					break;
				case 'seo_desc':
					$value = (string) get_post_meta( $pid, $seo['desc'], true );
					break;
				case 'attributes':
					$value = self::attributes_summary( $product );
					break;
				default:
					$value = (string) get_post_meta( $pid, (string) ( $dest['key'] ?? '_dze_' . $fid ), true );
			}
			$texts[ $fid ] = $value;
		}
		$images = [];
		// EVERY photograph, not the ones that would travel with a generation:
		// this list is what you pick from and what you compare against.
		$shot_ids = self::product_image_ids( $pid );
		if ( $shot_ids ) {
			// Read in one go: a gallery of twenty is twenty attachments, and
			// one query per thumbnail for a panel is one query too many.
			_prime_post_caches( $shot_ids, false, true );
		}
		// Which of them belong to a colour rather than to the product: the strip
		// says so instead of showing eight photographs of the same shoe with no
		// clue why three of them are blue.
		$of_colour = self::variation_images( $pid );
		foreach ( $shot_ids as $aid ) {
			$meta = wp_get_attachment_metadata( (int) $aid );
			$w    = (int) ( $meta['width'] ?? 0 );
			$h    = (int) ( $meta['height'] ?? 0 );
			$images[] = [
				// The colour this photograph belongs to, when it belongs to one.
				'variation' => (string) ( $of_colour[ (int) $aid ] ?? '' ),
				// The id travels too: the image workshop works ON one of these.
				'id'    => (int) $aid,
				'thumb' => (string) ( wp_get_attachment_image_url( (int) $aid, 'thumbnail' ) ?: '' ),
				// The original file, always: this is what the zoom opens.
				'full'  => (string) ( wp_get_attachment_image_url( (int) $aid, 'full' ) ?: '' ),
				'main'  => (int) $aid === (int) get_post_thumbnail_id( $pid ),
				// A catalogue is square or it is not; the shape has to be
				// readable without opening anything.
				'w'     => $w,
				'h'     => $h,
				'ratio' => self::ratio_label( $w, $h ),
			];
		}
		wp_send_json_success( [
			'texts'   => $texts,
			'images'  => $images,
			// Everything the popup needs to work on a product it was not opened
			// from: its name, its cost, and whatever is already waiting on it.
			'title'   => $product->get_name(),
			'cost'    => self::product_cost( $product ),
			'pending' => self::pending( $pid ),
		] );
	}

	/** Accepted or discarded: either way the product stops waiting. */
	/**
	 * Forgets what is waiting on a product — all of it, or only the pieces that
	 * have just been dealt with.
	 *
	 * Applying one image used to throw away everything else that was waiting,
	 * which is how a generation you had not decided on yet disappeared while
	 * you were saving another one. What was applied is dropped; what was not is
	 * still there when you come back.
	 */
	/**
	 * "This product is done."
	 *
	 * Called once per product when its content has been written, by the screen
	 * that wrote it: the product leaves the working list and joins the Done
	 * view. One request per product accepted, never one per field.
	 */
	/**
	 * The variation groups of one product: which colours it is sold in, how
	 * many variations each one covers, and which of them already have their
	 * own photograph.
	 *
	 * Asked for when a panel is opened, never with a list: it walks the
	 * variations of one product.
	 */
	public function ajax_variations(): void {
		$this->guard();
		$pid  = isset( $_POST['post'] ) ? absint( $_POST['post'] ) : 0;
		$attr = isset( $_POST['attr'] ) ? sanitize_key( (string) wp_unslash( $_POST['attr'] ) ) : '';
		if ( ! $pid ) {
			wp_send_json_error( [ 'message' => __( 'Product not found.', 'dazont-ecom' ) ] );
		}
		$data = self::variation_groups( $pid, $attr );
		// Which attributes this product could be grouped by, so the screen can
		// offer the choice when the guess is wrong.
		$choices = [];
		$product = wc_get_product( $pid );
		if ( $product && $product->is_type( 'variable' ) ) {
			foreach ( array_keys( (array) $product->get_variation_attributes() ) as $name ) {
				$choices[] = [ 'key' => (string) $name, 'label' => (string) wc_attribute_label( (string) $name, $product ) ];
			}
		}
		wp_send_json_success( [
			'attr'    => $data['attr'],
			'label'   => $data['label'],
			'choices' => $choices,
			// The same sentence the Variations panel shows, kept in step from
			// one place so the two can never disagree.
			'count'   => self::variation_count_text( (int) ( $data['allWith'] ?? 0 ), (int) ( $data['all'] ?? 0 ) ),
			'short'   => (int) ( ( $data['allWith'] ?? 0 ) < ( $data['all'] ?? 0 ) ),
			'groups'  => array_map(
				static fn( $g ) => [
					'key'   => $g['key'],
					'label' => $g['label'],
					'total' => (int) $g['total'],
					'with'  => (int) $g['with'],
					'thumb' => (string) $g['thumb'],
					'full'  => (string) ( $g['full'] ?? '' ),
					'note'  => (string) ( $g['note'] ?? '' ),
				],
				$data['groups']
			),
		] );
	}

	/**
	 * An image the shop already has, given to a group of variations.
	 *
	 * Nothing is downloaded, nothing is renamed: it is a photograph of this
	 * library being pointed at, and rewriting the owner's own media on the way
	 * would be a surprise nobody asked for. Pass 0 to take the image off the
	 * group instead.
	 */
	/** What you know about one colour, kept with the product. */
	public function ajax_variation_note(): void {
		$this->guard();
		$pid   = isset( $_POST['post'] ) ? absint( $_POST['post'] ) : 0;
		$group = isset( $_POST['group'] ) ? (string) wp_unslash( $_POST['group'] ) : '';
		$note  = isset( $_POST['note'] ) ? sanitize_textarea_field( (string) wp_unslash( $_POST['note'] ) ) : '';
		// '*' is the product itself: a note every image of it is given.
		if ( self::NOTE_PRODUCT === $group ) {
			if ( ! $pid ) {
				wp_send_json_error( [ 'message' => __( 'Product not found.', 'dazont-ecom' ) ] );
			}
			self::set_variation_note( $pid, self::NOTE_PRODUCT, $note );
			wp_send_json_success( [ 'saved' => true ] );
		}
		$target = self::attach_target( 'variation:' . $group );
		if ( ! $pid || 0 !== strpos( $target, 'variation:' ) ) {
			wp_send_json_error( [ 'message' => __( 'Unknown variation group.', 'dazont-ecom' ) ] );
		}
		self::set_variation_note( $pid, substr( $target, 10 ), $note );
		wp_send_json_success( [ 'saved' => true ] );
	}

	public function ajax_variation_assign(): void {
		$this->guard();
		$pid   = isset( $_POST['post'] ) ? absint( $_POST['post'] ) : 0;
		$group = isset( $_POST['group'] ) ? (string) wp_unslash( $_POST['group'] ) : '';
		$att   = isset( $_POST['attachment'] ) ? absint( $_POST['attachment'] ) : 0;
		$target = self::attach_target( 'variation:' . $group );
		if ( ! $pid || 0 !== strpos( $target, 'variation:' ) ) {
			wp_send_json_error( [ 'message' => __( 'Unknown variation group.', 'dazont-ecom' ) ] );
		}
		if ( $att && ! wp_attachment_is_image( $att ) ) {
			wp_send_json_error( [ 'message' => __( 'That is not an image.', 'dazont-ecom' ) ] );
		}
		[ $attr, $value ] = array_pad( explode( '::', substr( $target, 10 ), 2 ), 2, '' );
		$ids = self::variation_ids( $pid, $attr, $value );
		foreach ( $ids as $vid ) {
			if ( $att ) {
				set_post_thumbnail( $vid, $att );
			} else {
				delete_post_thumbnail( $vid );
			}
		}
		clean_post_cache( $pid );
		if ( function_exists( 'wc_delete_product_transients' ) ) {
			wc_delete_product_transients( $pid );
		}
		wp_send_json_success( [
			'done'  => count( $ids ),
			'thumb' => $att ? (string) ( wp_get_attachment_image_url( $att, 'thumbnail' ) ?: '' ) : '',
			'full'  => $att ? (string) ( wp_get_attachment_image_url( $att, 'full' ) ?: '' ) : '',
		] );
	}

	/**
	 * An image from the desktop — pasted, dropped or picked from a folder —
	 * given to a group of variations.
	 *
	 * It travels inside the request as bytes and joins the library through the
	 * same road as a generated one: the shop's file name, the shop's title, the
	 * alt text, the JPEG conversion. A photograph that arrives by another door
	 * must not end up named DSC_0421.jpg.
	 */
	public function ajax_variation_paste(): void {
		$this->guard();
		$pid   = isset( $_POST['post'] ) ? absint( $_POST['post'] ) : 0;
		$group = isset( $_POST['group'] ) ? (string) wp_unslash( $_POST['group'] ) : '';
		$data  = isset( $_POST['data'] ) ? (string) wp_unslash( $_POST['data'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated as an image below.
		$recipe = isset( $_POST['recipe'] ) ? sanitize_key( (string) wp_unslash( $_POST['recipe'] ) ) : '';
		$target = self::attach_target( 'variation:' . $group );
		if ( ! $pid || 0 !== strpos( $target, 'variation:' ) ) {
			wp_send_json_error( [ 'message' => __( 'Unknown variation group.', 'dazont-ecom' ) ] );
		}
		try {
			$uri   = self::read_data_uri( $data );
			$mime  = (string) substr( $uri, 5, strpos( $uri, ';' ) - 5 );
			$bytes = base64_decode( (string) substr( $uri, strpos( $uri, ',' ) + 1 ), true );
			if ( false === $bytes || '' === $bytes ) {
				throw new RuntimeException( __( 'That is not an image.', 'dazont-ecom' ) );
			}
			$ext = [ 'image/png' => 'png', 'image/webp' => 'webp' ][ $mime ] ?? 'jpg';
			$tmp = wp_tempnam( 'dze-variation.' . $ext );
			if ( ! $tmp || false === file_put_contents( $tmp, $bytes ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- a temporary file, not the filesystem API's business.
				throw new RuntimeException( __( 'The image could not be written on the server.', 'dazont-ecom' ) );
			}
			$att = $this->attach_file( $tmp, $ext, $pid, $target, $recipe );
		} catch ( \Throwable $e ) {
			wp_send_json_error( [ 'message' => $e->getMessage() ] );
		}
		wp_send_json_success( [
			'attachment' => (int) $att,
			'thumb'      => (string) ( wp_get_attachment_image_url( (int) $att, 'thumbnail' ) ?: '' ),
		] );
	}

	public function ajax_logged(): void {
		$this->guard();
		$pid    = isset( $_POST['post'] ) ? absint( $_POST['post'] ) : 0;
		$texts  = isset( $_POST['texts'] ) ? absint( $_POST['texts'] ) : 0;
		$images = isset( $_POST['images'] ) ? absint( $_POST['images'] ) : 0;
		if ( ! $pid ) {
			wp_send_json_error( [ 'message' => __( 'Product not found.', 'dazont-ecom' ) ] );
		}
		// A product is finished in ONE request: it stops waiting, it leaves the
		// list it was queued in, and it is recorded as done. Three screens read
		// those three facts, and they used to be written by two separate calls
		// fired for every product at once — so a product could end up recorded
		// as done, still queued and still waiting for a decision, all three at
		// the same time. One request, one product, in this order.
		if ( ! empty( $_POST['clear'] ) ) {
			delete_post_meta( $pid, self::META_PENDING );
			delete_transient( 'dze_pending_count' );
		}
		if ( ! empty( $_POST['unqueue'] ) ) {
			self::set_bulk_list( array_values( array_diff( self::bulk_list(), [ $pid ] ) ) );
		}
		self::log_add( $pid, $texts, $images );
		// The screen redraws its tabs from what the server holds, not from what
		// it thinks it just did: a count that only tells the truth after a
		// reload is a count nobody trusts.
		wp_send_json_success( [ 'left' => count( self::bulk_list() ), 'counts' => self::screen_counts() ] );
	}

	public function ajax_log_clear(): void {
		$this->guard();
		delete_option( self::OPT_LOG );
		wp_send_json_success( [] );
	}

	public function ajax_pending_clear(): void {
		$this->guard();
		$pid    = isset( $_POST['post'] ) ? absint( $_POST['post'] ) : 0;
		$shots  = isset( $_POST['shots'] ) ? array_map( 'esc_url_raw', (array) wp_unslash( $_POST['shots'] ) ) : [];
		$fields = isset( $_POST['fields'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['fields'] ) ) : [];
		// Several products at once, for the screen that lists what is waiting:
		// emptying that list is refusing each row, and one request per row on a
		// list of a hundred is a hundred round trips for one decision.
		$posts = isset( $_POST['posts'] )
			? array_values( array_filter( array_map( 'absint', (array) wp_unslash( $_POST['posts'] ) ) ) )
			: [];
		// Refusing everything a product holds is a decision: it is recorded
		// under Done and the product leaves the working list, exactly like a
		// product whose content was accepted.
		if ( $posts ) {
			foreach ( $posts as $one ) {
				self::drop_product( (int) $one );
			}
			self::set_bulk_list( array_values( array_diff( self::bulk_list(), $posts ) ) );
			wp_send_json_success( [ 'cleared' => count( $posts ), 'counts' => self::screen_counts() ] );
		}
		if ( ! $pid ) {
			wp_send_json_error( [ 'message' => __( 'Product not found.', 'dazont-ecom' ) ] );
		}
		if ( ! $shots && ! $fields ) {
			self::drop_product( $pid );
			self::set_bulk_list( array_values( array_diff( self::bulk_list(), [ $pid ] ) ) );
			wp_send_json_success( [ 'cleared' => $pid, 'left' => [], 'counts' => self::screen_counts() ] );
		}
		$waiting = self::pending( $pid );
		if ( $shots ) {
			$waiting['shots'] = array_values( array_diff( (array) ( $waiting['shots'] ?? [] ), $shots ) );
			// What each image was made for and by which prompt goes with it:
			// a settled image leaves nothing of itself behind in the row.
			foreach ( $shots as $gone ) {
				unset( $waiting['targets'][ $gone ], $waiting['recipes'][ $gone ] );
			}
		}
		foreach ( $fields as $fid ) {
			unset( $waiting['texts'][ $fid ], $waiting['companions'][ $fid ] );
		}
		if ( empty( $waiting['shots'] ) && empty( $waiting['texts'] ) ) {
			delete_post_meta( $pid, self::META_PENDING );
		} else {
			update_post_meta( $pid, self::META_PENDING, $waiting );
		}
		delete_transient( 'dze_pending_count' );
		wp_send_json_success( [ 'left' => self::pending( $pid ), 'counts' => self::screen_counts() ] );
	}

	/**
	 * The state of the two WordPress image boxes, after we changed them.
	 *
	 * Saving an image used to reload the whole product page, because the
	 * featured-image box and the gallery behind the popup still showed the
	 * previous state. Reloading to refresh two boxes costs the editor its
	 * scroll position, its open panels and any unsaved text — for a picture.
	 *
	 * The featured box comes back as WordPress's own markup, built by
	 * WordPress's own function, so the box stays the box.
	 */
	public function ajax_boxes(): void {
		$this->guard();
		$pid = isset( $_POST['post'] ) ? absint( $_POST['post'] ) : 0;
		if ( ! $pid ) {
			wp_send_json_error( [ 'message' => __( 'Save the product first.', 'dazont-ecom' ) ] );
		}
		require_once ABSPATH . 'wp-admin/includes/post.php';
		$thumb   = (int) get_post_thumbnail_id( $pid );
		$gallery = array_values( array_filter( array_map(
			'absint',
			explode( ',', (string) get_post_meta( $pid, '_product_image_gallery', true ) )
		) ) );
		$shots = [];
		foreach ( $gallery as $aid ) {
			$shots[] = [
				'id'    => $aid,
				'thumb' => (string) ( wp_get_attachment_image_url( $aid, 'thumbnail' ) ?: '' ),
			];
		}
		wp_send_json_success( [
			'thumb_html' => function_exists( '_wp_post_thumbnail_html' ) ? _wp_post_thumbnail_html( $thumb ?: null, $pid ) : '',
			'gallery'    => $shots,
			'gallery_ids'=> implode( ',', $gallery ),
		] );
	}

	public function ajax_image_attach(): void {
		$this->guard();
		$pid = isset( $_POST['post'] ) ? absint( $_POST['post'] ) : 0;
		// Read BEFORE the two ways of listing the images below, not inside one
		// of them: buried in the legacy branch, these were simply not defined
		// when the toolbox posted its items — which is how "and delete the
		// photograph it was made from" quietly never happened, and how the
		// prompt id later became a fatal.
		// The supplier shot a remake replaces is of no further use: taking it
		// out of the product and out of the library is what leaves a clean page
		// and a clean media folder behind. Only ever an image of THIS product.
		$replace = isset( $_POST['replace'] ) ? absint( $_POST['replace'] ) : 0;
		// Which prompt made these: it decides how the files are named.
		$recipe = isset( $_POST['recipe'] ) ? sanitize_key( (string) wp_unslash( $_POST['recipe'] ) ) : '';
		// What becomes of the main image this one replaces: kept at the front of
		// the gallery (the default) or taken off the product.
		$keep_old = ! isset( $_POST['keep_old'] ) || ! empty( $_POST['keep_old'] );

		// Each image says where IT goes. A single destination for the batch made
		// "one of these is the main image, that one goes second" impossible to
		// express, which is exactly the decision being made at that moment.
		$items = [];
		if ( isset( $_POST['items'] ) && is_array( $_POST['items'] ) ) {
			foreach ( wp_unslash( $_POST['items'] ) as $it ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized below.
				$items[] = [
					'url'    => esc_url_raw( (string) ( $it['url'] ?? '' ) ),
					'target' => self::attach_target( (string) ( $it['target'] ?? '' ) ),
				];
			}
		} else {
			// Older callers: a list of urls and one destination for all of them.
			$target = self::attach_target( isset( $_POST['target'] ) ? (string) wp_unslash( $_POST['target'] ) : '' );
			foreach ( (array) ( $_POST['urls'] ?? [] ) as $i => $u ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized below.
				$items[] = [
					'url'    => esc_url_raw( (string) wp_unslash( $u ) ),
					// Only the first of a batch could ever be the main image.
					'target' => ( 'main' === $target && 0 !== $i ) ? 'gallery' : $target,
				];
			}
		}
		if ( ! $pid || empty( $items ) ) {
			wp_send_json_error( [ 'message' => __( 'Nothing selected.', 'dazont-ecom' ) ] );
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		$ids     = [];
		$errors  = 0;
		$why     = [];
		$main_up = false;
		foreach ( $items as $item ) {
			$u = (string) $item['url'];
			if ( '' === $u || ! self::is_fal_url( $u ) ) {
				$errors++;
				$host  = (string) wp_parse_url( $u, PHP_URL_HOST );
				$why[] = sprintf(
					/* translators: %s: the host the image was served from */
					__( 'the image is served from %s, which is not one of fal\'s own hosts — it was not downloaded', 'dazont-ecom' ),
					$host ?: '(no address)'
				);
				continue;
			}
			$t = (string) $item['target'];
			// Two main images cannot both win: the first one asked for it.
			if ( 'main' === $t ) {
				if ( $main_up ) {
					$t = 'gallery';
				} else {
					$main_up = true;
				}
			}
			try {
				$ids[] = $this->sideload_seo( $u, $pid, $t, $recipe, $keep_old );
			} catch ( \Throwable $e ) {
				$errors++;
				$why[] = $e->getMessage();
			}
		}
		if ( empty( $ids ) ) {
			// The reason, not just the failure: "could not attach" sent nobody
			// anywhere. A fal URL has no guaranteed lifetime, the file may be
			// too big for the server, the folder may not be writable — each of
			// those has a different answer.
			wp_send_json_error( [
				'message' => __( 'Could not attach the selected image(s).', 'dazont-ecom' )
					. ( $why ? ' ' . implode( ' · ', array_unique( $why ) ) : '' ),
			] );
		}
		$removed = 0;
		if ( $replace && in_array( $replace, self::product_image_ids( $pid ), true ) ) {
			$removed = (int) self::retire_image( $pid, $replace );
		}
		wp_send_json_success( [
			'attached' => count( $ids ),
			'errors'   => $errors,
			'ids'      => $ids,
			'removed'  => $removed,
		] );
	}

	/**
	 * Sideloads a generated image with SEO naming: file name = product slug
	 * (WordPress appends -1/-2/-3 natively on collision), attachment title/slug =
	 * product title, alt text set. Attaches as main image or appends to the
	 * product gallery.
	 */
	/**
	 * Takes one photograph off a product and out of the library.
	 *
	 * Used when a remake replaces a supplier shot: the shot is removed from the
	 * gallery, from the featured slot if it held it, and the file is deleted —
	 * a catalogue rebuilt with this plugin should not leave the supplier's
	 * originals behind, on the page or on the disk.
	 *
	 * @return bool whether the file was deleted.
	 */
	/**
	 * "3:4", "1:1", or "1.62:1" when the sides do not reduce to anything neat.
	 */
	/**
	 * Reframes the chosen photographs and shows the result — nothing is written
	 * to the product and nothing is added to the library until it is accepted.
	 *
	 * The reframed file is kept for an hour under a key made of the image, the
	 * shape and the mode, so accepting does not redo the work.
	 */
	public function ajax_reframe_preview(): void {
		$this->guard();
		$ids   = isset( $_POST['ids'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['ids'] ) ) : [];
		$ratio = isset( $_POST['ratio'] ) ? sanitize_text_field( wp_unslash( $_POST['ratio'] ) ) : '1:1';
		$mode  = isset( $_POST['mode'] ) && 'crop' === $_POST['mode'] ? 'crop' : 'pad';
		$ids   = array_values( array_filter( array_unique( $ids ) ) );
		if ( ! $ids ) {
			wp_send_json_error( [ 'message' => __( 'Pick at least one photograph.', 'dazont-ecom' ) ] );
		}
		if ( count( $ids ) > 20 ) {
			$ids = array_slice( $ids, 0, 20 );
		}
		$out = [];
		foreach ( $ids as $aid ) {
			if ( ! wp_attachment_is_image( $aid ) ) {
				continue;
			}
			$meta = wp_get_attachment_metadata( $aid );
			try {
				$file = self::reframe_file( $aid, $ratio, $mode );
			} catch ( \Throwable $e ) {
				$out[] = [ 'id' => $aid, 'error' => $e->getMessage() ];
				continue;
			}
			set_transient( self::reframe_key( $aid, $ratio, $mode ), $file, HOUR_IN_SECONDS );
			$size = @getimagesize( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$out[] = [
				'id'      => $aid,
				'before'  => (string) ( wp_get_attachment_image_url( $aid, 'medium' ) ?: '' ),
				'beforeD' => self::ratio_label( (int) ( $meta['width'] ?? 0 ), (int) ( $meta['height'] ?? 0 ) ),
				'after'   => self::preview_uri( $file ),
				'afterD'  => $size ? self::ratio_label( (int) $size[0], (int) $size[1] ) : '',
				'w'       => $size ? (int) $size[0] : 0,
				'h'       => $size ? (int) $size[1] : 0,
			];
		}
		wp_send_json_success( [ 'items' => $out, 'ratio' => $ratio, 'mode' => $mode ] );
	}

	/**
	 * Accepts the reframed photographs: each one enters the library as a new
	 * file and takes the exact place of the one it replaces — the main image
	 * stays the main image, a gallery photograph keeps its position.
	 *
	 * The original is left alone unless asked for: a shape change is not a
	 * reason to lose the file it was made from.
	 */
	public function ajax_reframe_apply(): void {
		$this->guard();
		$pid   = isset( $_POST['post'] ) ? absint( $_POST['post'] ) : 0;
		$ids   = isset( $_POST['ids'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['ids'] ) ) : [];
		$ratio = isset( $_POST['ratio'] ) ? sanitize_text_field( wp_unslash( $_POST['ratio'] ) ) : '1:1';
		$mode  = isset( $_POST['mode'] ) && 'crop' === $_POST['mode'] ? 'crop' : 'pad';
		$drop  = ! empty( $_POST['drop_original'] );
		if ( ! $pid ) {
			wp_send_json_error( [ 'message' => __( 'Save the product first.', 'dazont-ecom' ) ] );
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$done = 0;
		$errs = [];
		foreach ( array_values( array_filter( array_unique( $ids ) ) ) as $aid ) {
			if ( ! wp_attachment_is_image( $aid ) ) {
				continue;
			}
			$key  = self::reframe_key( $aid, $ratio, $mode );
			$file = (string) get_transient( $key );
			try {
				if ( '' === $file || ! file_exists( $file ) ) {
					$file = self::reframe_file( $aid, $ratio, $mode ); // the wait expired.
				}
				$name = pathinfo( (string) get_attached_file( $aid ), PATHINFO_FILENAME );
				$new  = media_handle_sideload(
					[ 'name' => sanitize_file_name( $name . '-' . str_replace( ':', 'x', $ratio ) ) . '.jpg', 'tmp_name' => $file ],
					$pid,
					get_the_title( $aid )
				);
				if ( is_wp_error( $new ) ) {
					throw new RuntimeException( $new->get_error_message() );
				}
				self::swap_image( $pid, $aid, (int) $new );
				if ( $drop ) {
					self::retire_image( $pid, $aid );
				}
				delete_transient( $key );
				$done++;
			} catch ( \Throwable $e ) {
				$errs[] = $e->getMessage();
			}
		}
		clean_post_cache( $pid );
		if ( function_exists( 'wc_delete_product_transients' ) ) {
			wc_delete_product_transients( $pid );
		}
		if ( ! $done ) {
			wp_send_json_error( [ 'message' => $errs ? implode( ' · ', array_unique( $errs ) ) : __( 'Nothing was reframed.', 'dazont-ecom' ) ] );
		}
		wp_send_json_success( [ 'done' => $done, 'errors' => array_values( array_unique( $errs ) ) ] );
	}

	/**
	 * What this prompt actually receives about THIS product.
	 *
	 * The instructions were readable, the data was not: whether the categories
	 * travel with the prompt or not could only be found out by reading the
	 * code. It is the half of the request that changes from one product to the
	 * next, so it is the half worth looking at before blaming the prompt.
	 */
	public function ajax_inputs(): void {
		$this->guard();
		$pid = isset( $_POST['post'] ) ? absint( $_POST['post'] ) : 0;
		$row = isset( $_POST['row'] ) ? sanitize_key( wp_unslash( $_POST['row'] ) ) : '';
		if ( ! $pid ) {
			wp_send_json_error( [ 'message' => __( 'Save the product first.', 'dazont-ecom' ) ] );
		}
		$r = '' !== $row ? self::registry_row( $row ) : null;
		if ( ! $r ) {
			wp_send_json_error( [ 'message' => __( 'Unknown prompt.', 'dazont-ecom' ) ] );
		}
		$parts = [];
		$store = trim( (string) ( self::get_settings()['store_context'] ?? '' ) );
		if ( '' !== $store ) {
			$parts[] = __( 'Store context', 'dazont-ecom' ) . ":\n" . $store;
		}
		if ( ( $r['type'] ?? 'text' ) === 'image' ) {
			// An image prompt is fed photographs, not sentences.
			$names = [];
			foreach ( self::product_source_ids( $pid ) as $i => $aid ) {
				$names[] = sprintf( '%d. %s', $i + 1, get_the_title( $aid ) ?: ( '#' . $aid ) );
			}
			$parts[] = __( 'Photographs sent', 'dazont-ecom' ) . ":\n" . ( $names ? implode( "\n", $names ) : __( '(none — this product has no photograph)', 'dazont-ecom' ) );
			$sc = self::scenes();
			$def = self::default_scene();
			$parts[] = __( 'Background sent', 'dazont-ecom' ) . ': ' . ( isset( $sc[ $def ] ) ? $sc[ $def ]['name'] : __( '(none)', 'dazont-ecom' ) );
		}
		// A text prompt that asked to SEE the product: the panel says which
		// photographs go with it, the way the image prompts already do — an
		// input that is invisible in "the data sent" is an input nobody trusts.
		if ( ( $r['type'] ?? 'text' ) !== 'image' && self::wants_photos( $r ) ) {
			$names = [];
			foreach ( array_slice( self::product_source_ids( $pid ), 0, 3 ) as $i => $aid ) {
				$names[] = sprintf( '%d. %s', $i + 1, get_the_title( $aid ) ?: ( '#' . $aid ) );
			}
			$parts[] = __( 'Photographs the model looks at', 'dazont-ecom' ) . ":\n"
				. ( $names ? implode( "\n", $names ) : __( '(none — this product has no photograph)', 'dazont-ecom' ) );
		}
		$facts = self::payload_lines( $pid, (array) ( $r['inputs'] ?? [] ), (string) ( $r['inputs_meta'] ?? '' ) );
		$parts[] = __( 'Product data', 'dazont-ecom' ) . ":\n" . ( '' !== trim( $facts ) ? $facts : __( '(nothing — no input is ticked on this prompt)', 'dazont-ecom' ) );
		$parts[] = __( 'Answer in', 'dazont-ecom' ) . ': ' . self::site_language();
		wp_send_json_success( [
			'text'   => implode( "\n\n", $parts ),
			'inputs' => array_values( (array) ( $r['inputs'] ?? [] ) ),
			'all'    => self::input_options(),
		] );
	}

	/**
	 * Live prompt save from the product toolbox: fixes a prompt for good the
	 * moment an anomaly is spotted, without a trip to the settings screen.
	 */
	public function ajax_save_prompt(): void {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'dazont-ecom' ) ], 403 );
		}
		$type   = isset( $_POST['ptype'] ) ? sanitize_key( wp_unslash( $_POST['ptype'] ) ) : '';
		$prompt = isset( $_POST['prompt'] ) ? sanitize_textarea_field( wp_unslash( $_POST['prompt'] ) ) : '';
		if ( '' === trim( $prompt ) ) {
			wp_send_json_error( [ 'message' => __( 'Empty prompt.', 'dazont-ecom' ) ] );
		}
		// Resolve the registry row id to update.
		$row_id = '';
		if ( 'field' === $type ) {
			$row_id = isset( $_POST['field'] ) ? sanitize_key( wp_unslash( $_POST['field'] ) ) : '';
		} elseif ( 'template' === $type ) {
			$idx  = isset( $_POST['index'] ) ? absint( $_POST['index'] ) : 0;
			$tpls = self::image_templates();
			$row_id = (string) ( $tpls[ $idx ]['id'] ?? '' );
		}
		if ( '' === $row_id ) {
			wp_send_json_error( [ 'message' => __( 'Invalid request.', 'dazont-ecom' ) ] );
		}
		$settings = self::get_settings();
		$rows     = self::registry();
		$found    = false;
		// The settings of a prompt travel with its text: the toolbox edits the
		// same row the settings screen does, so what it can change there it can
		// change here. Anything not sent is left as it is.
		$inputs = isset( $_POST['inputs'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['inputs'] ) ) : null;
		$imgmeta = isset( $_POST['img_meta'] ) ? sanitize_key( wp_unslash( $_POST['img_meta'] ) ) : null;
		$imgrules = isset( $_POST['img_rules'] ) ? sanitize_textarea_field( wp_unslash( $_POST['img_rules'] ) ) : null;
		foreach ( $rows as $k => $r ) {
			if ( ( $r['id'] ?? '' ) === $row_id ) {
				$rows[ $k ]['prompt'] = $prompt;
				if ( null !== $inputs ) {
					$rows[ $k ]['inputs'] = array_values( array_intersect( $inputs, array_keys( self::input_options() ) ) );
				}
				if ( null !== $imgmeta ) {
					$rows[ $k ]['img_meta'] = $imgmeta;
				}
				if ( null !== $imgrules ) {
					$rows[ $k ]['img_rules'] = $imgrules;
				}
				$found = true;
				break;
			}
		}
		if ( ! $found ) {
			wp_send_json_error( [ 'message' => __( 'Unknown prompt.', 'dazont-ecom' ) ] );
		}
		$settings['registry'] = $rows;
		$this->write_settings_direct( $settings );
		self::$registry_cache = null;
		// Read back — report a real failure instead of a fake ✓.
		$check = self::registry_row( $row_id );
		if ( ! $check || (string) ( $check['prompt'] ?? '' ) !== $prompt ) {
			wp_send_json_error( [ 'message' => __( 'The prompt was not persisted — please save it from Settings instead.', 'dazont-ecom' ) ] );
		}
		wp_send_json_success( [ 'saved' => true ] );
	}

	/**
	 * Restores the SHIPPED default prompts: drops the stored registry and every
	 * legacy prompt override so registry() falls back to the built-in defaults
	 * (the original spreadsheet prompts + default image templates). Custom
	 * prompt rows are removed and validation flags reset — hence the explicit
	 * confirmation in the UI before calling this.
	 */
	public function ajax_reset_prompts(): void {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'dazont-ecom' ) ], 403 );
		}
		$s = self::get_settings();
		unset( $s['registry'], $s['image_templates'], $s['fv'], $s['fe'], $s['prompts_validated'] );
		foreach ( array_keys( $s ) as $k ) {
			if ( preg_match( '/^(prompt|dest|metakey|map)_/', (string) $k ) ) {
				unset( $s[ $k ] );
			}
		}
		$this->write_settings_direct( $s );
		self::$registry_cache = null;
		wp_send_json_success( [ 'reset' => true ] );
	}

	/**
	 * AJAX save of the Product-content settings form — same data, same
	 * sanitizer (it runs inside update_option), no page reload.
	 */
	/**
	 * Toggle a prompt's Validated flag straight from the toolbox — no round trip
	 * to Settings. Same capability as the settings page.
	 */
	public function ajax_validate_prompt(): void {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'dazont-ecom' ) ], 403 );
		}
		$type = isset( $_POST['ptype'] ) ? sanitize_key( wp_unslash( $_POST['ptype'] ) ) : '';
		$on   = ! empty( $_POST['on'] ) ? 1 : 0;
		$row_id = '';
		if ( 'field' === $type ) {
			$row_id = isset( $_POST['field'] ) ? sanitize_key( wp_unslash( $_POST['field'] ) ) : '';
		} elseif ( 'template' === $type ) {
			$idx    = isset( $_POST['index'] ) ? absint( $_POST['index'] ) : 0;
			$tpls   = self::image_templates();
			$row_id = (string) ( $tpls[ $idx ]['id'] ?? '' );
		}
		if ( '' === $row_id ) {
			wp_send_json_error( [ 'message' => __( 'Invalid request.', 'dazont-ecom' ) ] );
		}
		$settings = self::get_settings();
		$rows     = self::registry();
		$found    = false;
		foreach ( $rows as $k => $r ) {
			if ( ( $r['id'] ?? '' ) === $row_id ) {
				$rows[ $k ]['valid'] = $on;
				$found = true;
				break;
			}
		}
		if ( ! $found ) {
			wp_send_json_error( [ 'message' => __( 'Unknown prompt.', 'dazont-ecom' ) ] );
		}
		$settings['registry'] = $rows;
		$this->write_settings_direct( $settings );
		self::$registry_cache = null;
		$check = self::registry_row( $row_id );
		if ( ! $check || (int) ! empty( $check['valid'] ) !== $on ) {
			wp_send_json_error( [ 'message' => __( 'The change was not persisted — please use Settings instead.', 'dazont-ecom' ) ] );
		}
		wp_send_json_success( [ 'valid' => (bool) $on ] );
	}
}
