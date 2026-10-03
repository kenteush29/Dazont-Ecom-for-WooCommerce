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
		// Same rule as the whole-product run: asked again, it is told what the
		// field says today and asked for another way in — otherwise the same
		// instructions on the same product answer the same thing, and the
		// button looks broken.
		$already = trim( wp_strip_all_tags( self::current_value( $pid, $field ) ) );
		if ( '' !== $already && 'attributes' !== ( self::dest_for( $field )['type'] ?? '' ) ) {
			$user .= "\n--- WHAT THIS FIELD SAYS TODAY ---\n"
				. mb_substr( (string) preg_replace( '/\s+/u', ' ', $already ), 0, 900 ) . "\n"
				. "Write a DIFFERENT text: another opening, another order, another angle on the same product. Same facts, none of the same sentences. Do not comment on the text above and do not refer to it.\n";
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
			// What this field already holds, and the one thing to do about it.
			// The same instructions on the same product give the same text —
			// the model cannot see what it wrote last time, so asking again
			// returned what looked like the same answer, and it looked like a
			// button that did nothing. It is told, and asked for another way in.
			// Never on the attributes, where a different answer would mean
			// different facts.
			$already = trim( wp_strip_all_tags( self::current_value( $pid, (string) $fid ) ) );
			if ( '' !== $already && 'attributes' !== ( self::dest_for( (string) $fid )['type'] ?? '' ) ) {
				$user .= '===WHAT FIELD "' . $fid . '" SAYS TODAY===' . "\n"
					. mb_substr( (string) preg_replace( '/\s+/u', ' ', $already ), 0, 900 ) . "\n"
					. "Write a DIFFERENT text: another opening, another order, another angle on the same product. Same facts, none of the same sentences. Do not comment on the text above and do not refer to it.\n\n";
				$tokens += 40;
			}
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
		// WHICH PRODUCT this run is about, so its calls are on its own record:
		// "j'aimerais débuger ce produit, les images sont bizarres."
		DZE_Ai_Usage::about( $pid );
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
			DZE_Ai_Usage::about();
			wp_send_json_error( [ 'message' => $e->getMessage() ] );
		}
		DZE_Ai_Usage::unit();
		DZE_Ai_Usage::about();
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

	/**
	 * THE PRODUCT POPUP'S BUTTON — the same order as every other screen.
	 *
	 * It used to build its own: its own photographs, its own background
	 * sentence, its own brief. Two builders of one order drift apart, and they
	 * had — the popup never sent a picture already made, the bulk screen did;
	 * the popup said « Also: » before the owner's note, the bulk screen did
	 * not. It now hands `shoot()` what it was asked, in `shoot()`'s own words,
	 * and there is ONE order whichever button is pressed.
	 *
	 * `dry` asks for the order without sending it: what the model would read,
	 * and which pictures it would see, in which order — before anything is
	 * paid for.
	 */
	public function ajax_quick_main(): void {
		$this->guard();
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- guard() checks the nonce; shoot() sanitises every field it reads, exactly as the other screens post them.
		$pid    = isset( $_POST['post'] ) ? absint( $_POST['post'] ) : 0;
		$recipe = isset( $_POST['recipe'] ) ? sanitize_key( wp_unslash( $_POST['recipe'] ) ) : '';
		$typed  = isset( $_POST['prompt'] ) ? (string) $_POST['prompt'] : '';
		$bg     = isset( $_POST['bg'] ) ? absint( $_POST['bg'] ) : 0;
		// Which prompt: the one picked, or the one that makes the main image.
		$want = '' !== $recipe ? $recipe : (string) ( self::main_recipe()['id'] ?? '' );
		$idx  = null;
		$base = '';
		foreach ( self::image_templates() as $i => $t ) {
			if ( (string) $t['id'] === $want ) {
				$idx  = (int) $i;
				$base = (string) $t['prompt'];
				break;
			}
		}
		// The background, as the scene it is — the same list, one picture.
		$scene = -1;
		if ( $bg ) {
			foreach ( self::scenes() as $si => $sc ) {
				if ( (int) $sc['image'] === $bg ) {
					$scene = (int) $si;
					break;
				}
			}
		}
		$in = [
			'post'     => $pid,
			'template' => null === $idx ? 0 : $idx,
			'pastes'   => isset( $_POST['pastes'] ) ? (array) $_POST['pastes'] : [],
			'src_ids'  => isset( $_POST['src_ids'] ) ? array_map( 'absint', (array) $_POST['src_ids'] ) : [],
			'src_id'   => isset( $_POST['src_id'] ) ? absint( $_POST['src_id'] ) : 0,
			// « Only the photographs from elsewhere », when that tile is the one picked.
			'only_pasted' => ! empty( $_POST['only_pasted'] ) ? 1 : 0,
			'note'     => isset( $_POST['note'] ) ? (string) $_POST['note'] : '',
			'mode'     => 'defer',
			'stash'    => 1,
			'dry'      => ! empty( $_POST['dry'] ) ? 1 : 0,
		];
		// THE BACKGROUND, when the popup said one — « None » included. Said
		// nothing, the prompt's own background is used, exactly as the bulk
		// screen, the queue and the automation use it.
		if ( isset( $_POST['bg'] ) ) {
			$in['scene'] = $scene;
		}
		// WHAT WAS TYPED FOR THIS RUN, when it is not the prompt as saved: the
		// box opens on the saved words, and sending those back as « typed »
		// would hide which prompt made the picture.
		if ( '' !== trim( wp_unslash( $typed ) ) && trim( wp_unslash( $typed ) ) !== trim( $base ) ) {
			$in['custom_prompt'] = $typed;
		}
		// NO PROMPT MAKES THE MAIN IMAGE on this shop: the lane still works,
		// on the shipped main-image prompt, and its picture still goes to the
		// main image — never to the first gallery prompt that happens to exist.
		if ( null === $idx ) {
			if ( ! isset( $in['custom_prompt'] ) ) {
				$in['custom_prompt'] = wp_slash( self::quick_prompt() );
			}
			$in['target'] = 'main';
			// And NOTHING of that gallery prompt either: its inputs, its
			// other colours and its name on the result were the first
			// gallery prompt's, while its words were not.
			$in['no_template'] = 1;
		}
		// THE FRAMINGS ALREADY MADE, sent as words (made_lines()): the product
		// page makes its photographs one after another and asks for this.
		$in['aware'] = ! empty( $_POST['aware'] ) ? 1 : 0;
		// ONE PRESS'S MODEL, when the page named one of the catalogue. Compared
		// with the catalogue's keys as typed: sanitize_key() would drop the dot
		// of « gpt-image-2.5-sunburst » and name a model that does not exist.
		$dze_model = isset( $_POST['model'] ) ? trim( (string) wp_unslash( $_POST['model'] ) ) : '';
		self::$model_override = isset( self::image_models()[ $dze_model ] ) ? $dze_model : '';
		// ORDER AND COME BACK: the page asks after its picture in short calls
		// (ajax_job) instead of holding this one open past the proxy's 60 s.
		self::$submit_only = ! empty( $_POST['async'] ) && empty( $in['dry'] );
		// phpcs:enable
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 180 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		try {
			$made = $this->shoot( $in );
		} catch ( \Throwable $e ) {
			self::$submit_only    = false;
			self::$model_override = '';
			wp_send_json_error( [ 'message' => $e->getMessage() ] );
		}
		self::$submit_only    = false;
		self::$model_override = '';
		if ( ! empty( $made['dry'] ) ) {
			wp_send_json_success( $made );
		}
		if ( ! empty( $made['job'] ) ) {
			wp_send_json_success( $made + [ 'spend' => self::product_spend( $pid ) ] );
		}
		$main = (int) get_post_thumbnail_id( $pid );
		wp_send_json_success( [
			'url'   => (string) ( $made['url'] ?? '' ),
			// Shown next to the new image and opened by its zoom: the original.
			'main'  => $main ? (string) wp_get_attachment_image_url( $main, 'full' ) : '',
			// What this product has cost in images, counted after this one.
			'spend' => $made['spend'] ?? self::product_spend( $pid ),
		] );
	}

	/**
	 * ASKS AFTER ONE PICTURE the product page ordered, in a call that lasts a
	 * few seconds whatever fal is doing. Three answers: still running; done —
	 * filed in the waiting list with its cost and its framing, exactly where a
	 * picture made while waiting would have gone; or given up, said on the
	 * tile and written to the log the error links to.
	 */
	public function ajax_job(): void {
		$this->guard();
		global $wpdb;
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- guard() checks the nonce.
		$pid = isset( $_POST['post'] ) ? absint( $_POST['post'] ) : 0;
		$id  = isset( $_POST['job'] ) ? sanitize_text_field( wp_unslash( $_POST['job'] ) ) : '';
		// phpcs:enable
		if ( ! $pid || '' === $id || empty( self::jobs( $pid )[ $id ] ) ) {
			wp_send_json_error( [
				'gone'    => 1,
				'message' => __( 'This picture is no longer being made: it was already collected, or given up.', 'dazont-ecom' ),
			] );
		}
		// ONE COLLECTOR PER PICTURE. Two tabs asking after the same job in the
		// same second must not file it twice nor bill it twice: the second is
		// told « still running » and finds it filed on its next look.
		$lock = 'dze_job_' . md5( $id );
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $lock ) ) ) {
			wp_send_json_success( [ 'running' => 1, 'secs' => 0 ] );
		}
		$out = $this->job_look( $pid, $id );
		$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		if ( ! empty( $out['error'] ) ) {
			wp_send_json_error( $out );
		}
		wp_send_json_success( $out );
	}

	/**
	 * One look at one job, under its lock.
	 *
	 * @return array<string,mixed>
	 */
	private function job_look( int $pid, string $id ): array {
		$job = self::jobs( $pid )[ $id ] ?? null;
		if ( ! $job ) {
			// Collected by another tab while this one waited for the lock.
			return [ 'gone' => 1 ];
		}
		$secs  = max( 0, time() - (int) ( $job['t'] ?? time() ) );
		$state = self::fal_status( $job );
		if ( 'done' !== $state ) {
			if ( 'failed' !== $state && $secs < self::JOB_GIVE_UP ) {
				return [ 'running' => 1, 'secs' => $secs ];
			}
			self::job_remove( $pid, $id );
			$why = 'failed' === $state
				? __( 'fal no longer knows this job.', 'dazont-ecom' )
				/* translators: %d: minutes */
				: sprintf( __( 'fal has not finished it in %d minutes.', 'dazont-ecom' ), (int) round( self::JOB_GIVE_UP / 60 ) );
			if ( class_exists( 'DZE_Health' ) ) {
				DZE_Health::log( 'fal', 'job ' . $id . ' (product ' . $pid . ')', 'given up — ' . $why );
			}
			/* translators: %s: why the picture was given up */
			return [ 'error' => 1, 'gone' => 1, 'message' => sprintf( __( 'Given up: %s', 'dazont-ecom' ), $why ) ];
		}
		DZE_Ai_Usage::unit( 'product_img' );
		DZE_Ai_Usage::about( $pid );
		try {
			$url = self::fal_fetch( $job, $pid, (string) ( $job['asked'] ?? '' ), (float) ( $job['t0'] ?? microtime( true ) ) );
		} catch ( \Throwable $e ) {
			DZE_Ai_Usage::unit();
			DZE_Ai_Usage::about();
			self::job_remove( $pid, $id );
			if ( class_exists( 'DZE_Health' ) ) {
				DZE_Health::log( 'content', 'image generation (product ' . $pid . ')', $e->getMessage() );
			}
			return [ 'error' => 1, 'gone' => 1, 'message' => $e->getMessage() ];
		}
		if ( '' === $url ) {
			// Finished and not fetched this time: fetched on the next look.
			DZE_Ai_Usage::unit();
			DZE_Ai_Usage::about();
			return [ 'running' => 1, 'secs' => $secs ];
		}
		DZE_Ai_Usage::finished( 'product_img' );
		self::charge_product( $pid, self::last_image_cost() );
		// READ, for the next order and for the shop (read_picture()): its
		// framing in the reader's fixed words, and what it shows that the
		// product does not. An enlargement keeps its original's framing: there
		// is nothing new to describe.
		DZE_Ai_Usage::unit( 'img_view' );
		$read = 'enlarge' === (string) ( $job['tool'] ?? '' ) ? null : self::read_picture( $url, $pid, self::recipe_prompt( (string) ( $job['recipe'] ?? '' ) ) );
		DZE_Ai_Usage::unit();
		DZE_Ai_Usage::about();
		$model = (string) ( $job['model'] ?? '' );
		// THE PHOTOGRAPH IT WAS MADE FROM, when it is one of the product's: a
		// ✦ or an HD of it is headed for its place (replace_in_place()).
		$target = ! empty( $job['replaces'] ) ? 'replace:' . (int) $job['replaces'] : (string) ( $job['target'] ?? 'gallery' );
		if ( class_exists( 'DZE_Ai_Card' ) ) {
			DZE_Ai_Card::put( $pid, $url, [
				'recipe'   => (string) ( $job['recipe'] ?? '' ),
				'tool'     => '' !== (string) ( $job['tool'] ?? '' ) ? (string) $job['tool'] : 'generate',
				'base'     => (array) ( $job['base'] ?? [] ),
				'told'     => (array) ( $job['told'] ?? [] ),
				'invented' => $read ? $read['invented'] : [],
			] );
		}
		if ( ! empty( $job['stash'] ) ) {
			$dze_add = [
				'shot'   => $url,
				'target' => $target,
				'recipe' => (string) ( $job['recipe'] ?? '' ),
				'model'  => $model,
			];
			if ( $read && '' !== $read['frame'] ) {
				$dze_add['frame'] = $read['frame'];
				$dze_add['flags'] = $read['invented'];
			}
			self::stash( $pid, $dze_add );
		}
		self::job_remove( $pid, $id );
		return [
			'done'     => 1,
			'url'      => $url,
			'target'   => $target,
			'invented' => $read ? $read['invented'] : [],
			'recipe' => (string) ( $job['recipe'] ?? '' ),
			'model'  => (string) ( self::image_models()[ $model ]['label'] ?? ( self::UPSCALER === $model ? self::upscaler_label() : $model ) ),
			'key'    => $model,
			'view'   => $read ? $read['frame'] : '',
			'secs'   => $secs,
			'spend'  => self::product_spend( $pid ),
		];
	}

	public function ajax_image(): void {
		$this->guard();
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- guard() checks the nonce.
		// ORDER AND COME BACK, as the product page's press does: the toolbox,
		// the bulk screen and the ✦ on a picture ask for it, and the picture
		// is then collected in short calls (ajax_job) — never a request held
		// open past the proxy's 60 seconds. Only for a picture headed for the
		// waiting list: one filed straight onto the product is still made
		// while the request waits, as before.
		$dze_model = isset( $_POST['model'] ) ? trim( (string) wp_unslash( $_POST['model'] ) ) : '';
		self::$model_override = isset( self::image_models()[ $dze_model ] ) ? $dze_model : '';
		self::$submit_only    = ! empty( $_POST['async'] ) && empty( $_POST['dry'] )
			&& 'defer' === sanitize_key( (string) wp_unslash( $_POST['mode'] ?? '' ) ) && ! empty( $_POST['stash'] );
		// phpcs:enable
		try {
			$made = $this->shoot( (array) $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- guard() checks the nonce; every field is sanitised where it is read, inside shoot().
		} catch ( \Throwable $e ) {
			self::$submit_only    = false;
			self::$model_override = '';
			wp_send_json_error( [ 'message' => $e->getMessage() ] );
		}
		self::$submit_only    = false;
		self::$model_override = '';
		if ( ! empty( $made['job'] ) ) {
			$made['spend'] = self::product_spend( isset( $_POST['post'] ) ? absint( $_POST['post'] ) : 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		}
		wp_send_json_success( $made );
	}

	/**
	 * HD: ONE PICTURE, MORE PIXELS, NOTHING ELSE CHANGED.
	 *
	 * « Certaines images en pièce jointe pourraient facilement être ajoutées
	 * sur la fiche produit avec une retouche, un agrandissement… » — supplier
	 * detail shots 800 pixels wide. SeedVR2 at fal (UPSCALE_PER_MP a
	 * megapixel) brings the long side to UPSCALE_LONG. Ordered and collected
	 * like any picture (ajax_job): the enlargement joins the waiting list beside
	 * its original, which is left exactly as it was.
	 *
	 * The picture is one of three things — a generated one waiting (src_url), a
	 * photograph of this product (src_att), one pasted in (src_paste) — and an
	 * enlargement keeps what its original was: a real photograph stays one
	 * (a source for later pictures), a generated one stays generated.
	 */
	public function ajax_enlarge(): void {
		$this->guard();
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- guard() checks the nonce.
		$pid   = isset( $_POST['post'] ) ? absint( $_POST['post'] ) : 0;
		$url   = isset( $_POST['src_url'] ) ? esc_url_raw( (string) wp_unslash( $_POST['src_url'] ) ) : '';
		$att   = isset( $_POST['src_att'] ) ? absint( $_POST['src_att'] ) : 0;
		$paste = isset( $_POST['src_paste'] ) ? (string) wp_unslash( $_POST['src_paste'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- a data URI, read by read_data_uris().
		// phpcs:enable
		if ( ! $pid ) {
			wp_send_json_error( [ 'message' => __( 'Save the product first.', 'dazont-ecom' ) ] );
		}
		if ( '' === self::fal_key() ) {
			wp_send_json_error( [ 'message' => __( 'Add your fal.ai key under Settings → General first.', 'dazont-ecom' ) ] );
		}
		if ( class_exists( 'DZE_Ai_Usage' ) && DZE_Ai_Usage::over_budget() ) {
			wp_send_json_error( [ 'message' => DZE_Ai_Usage::budget_message() ] );
		}
		$recipe = '';
		$bytes  = '';
		$image  = '';
		try {
			if ( '' !== $url ) {
				if ( ! self::is_fal_url( $url ) ) {
					throw new RuntimeException( __( 'Invalid source image.', 'dazont-ecom' ) );
				}
				$got   = wp_remote_get( $url, [ 'timeout' => 20 ] );
				$bytes = is_wp_error( $got ) ? '' : (string) wp_remote_retrieve_body( $got );
				$image = $url;
				// A generated picture's enlargement is still a generated picture:
				// never an empty prompt id, which would make it a real photograph
				// — a source for every later picture — once it is accepted.
				$dze_r  = (string) ( self::pending( $pid )['recipes'][ $url ] ?? '' );
				$recipe = '' !== $dze_r ? $dze_r : 'img_enlarged';
			} elseif ( $att ) {
				if ( ! in_array( $att, array_map( 'intval', self::product_image_ids( $pid ) ), true ) ) {
					throw new RuntimeException( __( 'That photograph is not one of this product\'s.', 'dazont-ecom' ) );
				}
				$file  = (string) get_attached_file( $att );
				$bytes = ( '' !== $file && file_exists( $file ) ) ? (string) file_get_contents( $file ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file.
				$image = $this->fal_source_data_uri( $att, 'full' );
				$recipe = (string) get_post_meta( $att, self::META_RECIPE, true );
			} elseif ( '' !== $paste ) {
				$dze_got = self::read_data_uris( [ $paste ], 1, self::MAX_PAYLOAD );
				if ( ! $dze_got ) {
					throw new RuntimeException( __( 'That is not an image.', 'dazont-ecom' ) );
				}
				$image = (string) $dze_got[0];
				$bytes = (string) base64_decode( (string) substr( $image, (int) strpos( $image, ',' ) + 1 ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- reading a pasted image's size.
			} else {
				throw new RuntimeException( __( 'Pick the picture to enlarge.', 'dazont-ecom' ) );
			}
			$size = '' !== $bytes ? @getimagesizefromstring( $bytes ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$w    = (int) ( $size[0] ?? 0 );
			$h    = (int) ( $size[1] ?? 0 );
			if ( ! $w || ! $h ) {
				throw new RuntimeException( __( 'That picture could not be read.', 'dazont-ecom' ) );
			}
			$factor = self::enlarge_factor( $w, $h );
			if ( $factor <= 0 ) {
				/* translators: 1: width, 2: height */
				throw new RuntimeException( sprintf( __( 'It is already %1$d × %2$d pixels: enlarging it gains nothing.', 'dazont-ecom' ), $w, $h ) );
			}
			if ( class_exists( 'DZE_Ai_Usage' ) ) {
				$stop = DZE_Ai_Usage::fal_blocked( $pid );
				if ( '' !== $stop ) {
					throw new RuntimeException( $stop );
				}
				DZE_Ai_Usage::fal_attempt( $pid );
			}
			$resp = wp_remote_post( self::FAL_QUEUE . self::UPSCALE_ENDPOINT, [
				'timeout' => 30,
				'headers' => [ 'Authorization' => 'Key ' . self::fal_key(), 'content-type' => 'application/json' ],
				'body'    => wp_json_encode( [
					'image_url'      => $image,
					'upscale_mode'   => 'factor',
					'upscale_factor' => $factor,
					'output_format'  => 'jpg',
				] ),
			] );
			if ( is_wp_error( $resp ) ) {
				DZE_Health::log( 'fal', 'POST ' . self::FAL_QUEUE . self::UPSCALE_ENDPOINT, 'network — ' . $resp->get_error_message() );
				throw new RuntimeException( $resp->get_error_message() );
			}
			$code = (int) wp_remote_retrieve_response_code( $resp );
			$body = json_decode( wp_remote_retrieve_body( $resp ), true );
			if ( $code < 200 || $code >= 300 || empty( $body['request_id'] ) || empty( $body['status_url'] ) ) {
				$msg = self::fal_said( $code, $body );
				DZE_Health::log( 'fal', 'POST ' . self::FAL_QUEUE . self::UPSCALE_ENDPOINT, 'refused — ' . $msg );
				/* translators: %s: what fal said */
				throw new RuntimeException( sprintf( __( 'fal.ai error: %s', 'dazont-ecom' ), mb_substr( $msg, 0, 300 ) ) );
			}
		} catch ( \Throwable $e ) {
			wp_send_json_error( [ 'message' => $e->getMessage() ] );
		}
		$ow  = (int) round( $w * $factor );
		$oh  = (int) round( $h * $factor );
		$job = [
			'id'       => (string) $body['request_id'],
			'status'   => (string) $body['status_url'],
			'response' => (string) ( $body['response_url'] ?? '' ),
			'model'    => self::UPSCALER,
			'refs'     => 0,
			'asked'    => sprintf( '[enlarged ×%s — %d × %d → %d × %d, nothing else changed]', $factor, $w, $h, $ow, $oh ),
			't0'       => microtime( true ),
			'target'   => 'gallery',
			'recipe'   => $recipe,
			'stash'    => 1,
			'tool'     => 'enlarge',
			// One of the product's photographs: its HD takes its place.
			'replaces' => ( $att && in_array( $att, array_map( 'intval', self::product_own_image_ids( $pid ) ), true ) ) ? $att : 0,
			// What it enlarges, for its card: a picture made here, a photograph
			// of the product or one pasted in.
			'base'     => class_exists( 'DZE_Ai_Card' ) ? DZE_Ai_Card::source_of( $pid, $url, $att, '' !== $paste ) : [],
			'mp'       => round( $ow * $oh / 1000000, 2 ),
			'by'       => get_current_user_id(),
		];
		self::job_add( $pid, $job );
		wp_send_json_success( [
			'job'   => $job['id'],
			'model' => self::upscaler_label(),
			'key'   => self::UPSCALER,
			'size'  => [ $ow, $oh ],
			'spend' => self::product_spend( $pid ),
		] );
	}

	/**
	 * ONE product image, made — the function behind the button.
	 *
	 * It used to be the AJAX handler itself: three hundred lines reading
	 * $_POST and ending in wp_send_json_*, which meant nothing could call it.
	 * An automatic pass therefore had a choice between a second copy of this
	 * — the sources, the scene, the variation
	 * instructions, the shop's notes, the ratio — and doing without them. Two
	 * copies of a prompt this careful drift apart, and the day they do it is
	 * the catalogue that pays.
	 *
	 * So: one function. The click passes $_POST, a queued job passes the same
	 * names, and both get the same picture from the same words.
	 *
	 * The inputs arrive exactly as the screen posts them — slashed — because
	 * every wp_unslash() inside is left where it stands. A caller with plain
	 * values loses nothing: unslashing a product id is a no-op.
	 *
	 * @param array $in The run's inputs, named as the screen names them.
	 * @return array What the screen is sent: a finished attachment, or a
	 *               preview to accept, or a stashed shot.
	 * @throws RuntimeException with the sentence the screen must show.
	 */
	/**
	 * What travelled with the order, said in words.
	 *
	 * "Ou voir le log pour la génération d'images ? Toutes mes images ont le
	 * style scalloped depuis 2 minutes." The trace said "6 reference
	 * photograph(s) attached" — a COUNT, which cannot answer the only question
	 * being asked: which of them, and where did that one come from. The lanes
	 * are already counted where they are filled, so the same figures say it.
	 *
	 * @param array<int,array{0:string,1:int}> $parts label => how many.
	 */
	private static function sources_said( array $parts ): string {
		$out = [];
		foreach ( $parts as $one ) {
			$n = (int) ( $one[1] ?? 0 );
			if ( $n > 0 ) {
				$out[] = $n . ' ' . (string) $one[0];
			}
		}
		return implode( ' · ', $out );
	}

	public function shoot( array $in ): array {
		// No guard here: a nonce and a capability belong to a CLICK, and this
		// is also called from a background job that has neither. ajax_image()
		// checks them before it gets this far.
		$pid    = isset( $in['post'] ) ? absint( $in['post'] ) : 0;
		$idx    = isset( $in['template'] ) ? absint( $in['template'] ) : 0;
		$mode   = isset( $in['mode'] ) ? sanitize_key( wp_unslash( $in['mode'] ) ) : '';
		$custom = isset( $in['custom_prompt'] ) ? sanitize_textarea_field( wp_unslash( $in['custom_prompt'] ) ) : '';
		$src    = isset( $in['src_url'] ) ? esc_url_raw( wp_unslash( $in['src_url'] ) ) : '';
		// THE PICTURE BEING REMADE when it is not a generated one: a photograph
		// of this product (src_att) or one pasted in (src_paste) — « l'option de
		// remake image doit être dispo aussi sur les images copié collées
		// externes ». Read as an image, never as an address.
		$src_att   = isset( $in['src_att'] ) ? absint( $in['src_att'] ) : 0;
		$src_paste = isset( $in['src_paste'] ) ? (string) wp_unslash( $in['src_paste'] ) : '';
		// ✦ REMAKE BETTER: the shop's remake prompt, on that one picture.
		$remake    = ! empty( $in['remake'] );
		// WHAT IT IS REMADE FROM, for the new picture's card (the « i »).
		$dze_base  = ( $remake && class_exists( 'DZE_Ai_Card' ) )
			? DZE_Ai_Card::source_of( $pid, self::is_fal_url( $src ) ? $src : '', $src_att, '' !== $src_paste )
			: [];
		// ↻ ON A PICTURE STILL WAITING: the address of the one it replaces. Its
		// framing is asked for again instead of being forbidden (made_lines()).
		$dze_redo  = isset( $in['redo'] ) ? esc_url_raw( (string) wp_unslash( $in['redo'] ) ) : '';
		if ( '' !== $dze_redo && ! self::is_fal_url( $dze_redo ) ) {
			$dze_redo = '';
		}
		// ONE PHOTOGRAPH OF THE PRODUCT, PICKED ON THE SCREEN. It says two
		// things at once, which is why it replaced a checkbox: this one is
		// image 1, and the product is the subject. It never reached this
		// function at all — the toolbox posted it and only the main-image lane
		// read it — so the picker was a control that did nothing here, and its
		// default, which reads "Main photograph", answered nothing either.
		$src_id = isset( $in['src_id'] ) ? absint( $in['src_id'] ) : 0;
		// SEVERAL, PICKED IN ORDER. « J'aimerais re-générer des images basées
		// sur l'image en pièce jointe » — a supplier's « Detailed
		// introduction » sheet, one gallery picture among eight. Picking
		// photographs is picking what the model works from: the first one
		// picked is image 1, and nothing that was not picked travels.
		$src_ids = [];
		foreach ( (array) ( $in['src_ids'] ?? [] ) as $dze_one ) {
			$dze_one = absint( $dze_one );
			if ( $dze_one && ! in_array( $dze_one, $src_ids, true ) ) {
				$src_ids[] = $dze_one;
			}
		}
		if ( $src_id && ! $src_ids ) {
			$src_ids = [ $src_id ];
		}
		// WHAT THE PERSON TYPED FOR THIS RUN. It used to be saved on the
		// product and sent with every image made for it afterwards, which is a
		// hidden instruction by any other name.
		$note   = isset( $in['note'] ) ? sanitize_textarea_field( (string) wp_unslash( $in['note'] ) ) : '';
		// A photograph that is not on the product yet — a supplier shot pasted
		// from a browser tab, its watermark and its play button included. It
		// travels as bytes inside the request and is never stored: it is the
		// subject of the generation, not a file the shop keeps.
		$paste  = isset( $in['paste'] ) ? (string) wp_unslash( $in['paste'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated as an image below.
		// Several photographs from outside the shop, the first one the subject.
		$pastes = isset( $in['pastes'] ) ? (array) wp_unslash( $in['pastes'] ) : []; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated as images below.
		// WHAT THE HANDED-IN PHOTOGRAPHS ARE FOR — a setting to put the product
		// in, or a design to rework onto it. The plugin used to answer it on
		// its own, in capitals, and answered it one way only. Anything but the
		// owner's own word means the setting: a default that means something
		// else is how a supplier shot became the product.

		if ( ! $pastes && '' !== $paste ) {
			$pastes = [ $paste ];
		}
		$paste = $pastes ? (string) $pastes[0] : '';
		if ( ! $pid ) {
			throw new RuntimeException( __( 'Save the product first.', 'dazont-ecom' ) );
		}
		if ( '' === self::fal_key() ) {
			throw new RuntimeException( __( 'Add your fal.ai key under Settings → General first.', 'dazont-ecom' ) );
		}
		if ( class_exists( 'DZE_Ai_Usage' ) && DZE_Ai_Usage::over_budget() ) {
			throw new RuntimeException( DZE_Ai_Usage::budget_message() );
		}
		$templates = self::image_templates();
		$tpl       = $templates[ $idx ] ?? $templates[0] ?? null;
		if ( ! empty( $in['no_template'] ) && '' !== $custom ) {
			$tpl = null;
		}
		if ( $remake ) {
			$tpl = self::remake_template();
		}
		if ( ! $tpl && '' === $custom ) {
			throw new RuntimeException( __( 'No image template configured.', 'dazont-ecom' ) );
		}
		// Where this image is meant to land. The prompt says it, and the screen
		// that ordered the run may have said otherwise — a decision taken once
		// before the run rather than corrected on thirty images afterwards.
		$target = (string) ( $tpl['target'] ?? 'gallery' );
		if ( isset( $in['target'] ) ) {
			$target = self::attach_target( (string) wp_unslash( $in['target'] ) );
		}
		// One group of variations — "the olive ones" — asked for by key. It
		// decides the destination on its own: an image made for a colour goes
		// to that colour's variations and nowhere else.
		$variation = isset( $in['variation'] ) ? (string) wp_unslash( $in['variation'] ) : '';
		$v_attr    = '';
		$v_value   = '';
		if ( '' !== $variation ) {
			$target = self::attach_target( 'variation:' . $variation );
			if ( 0 !== strpos( $target, 'variation:' ) ) {
				throw new RuntimeException( __( 'Unknown variation group.', 'dazont-ecom' ) );
			}
			[ $v_attr, $v_value ] = array_pad( explode( '::', substr( $target, 10 ), 2 ), 2, '' );
		}

		// Source image: an earlier AI result (live edit) or the featured image.
		if ( '' !== $src && ! self::is_fal_url( $src ) ) {
			throw new RuntimeException( __( 'Invalid source image.', 'dazont-ecom' ) );
		}
		// What the screen shows of it: a data URI is no thumbnail.
		$src_thumb = $src;
		if ( '' === $src && $src_att ) {
			// Only ever a photograph of THIS product.
			if ( ! in_array( $src_att, array_map( 'intval', self::product_image_ids( $pid ) ), true ) ) {
				throw new RuntimeException( __( 'That photograph is not one of this product\'s.', 'dazont-ecom' ) );
			}
			$src       = $this->fal_source_data_uri( $src_att, 'full' );
			$src_thumb = (string) wp_get_attachment_image_url( $src_att, 'thumbnail' );
		} elseif ( '' === $src && '' !== $src_paste ) {
			$dze_got = self::read_data_uris( [ $src_paste ], 1, self::MAX_PAYLOAD );
			if ( ! $dze_got ) {
				throw new RuntimeException( __( 'That is not an image.', 'dazont-ecom' ) );
			}
			$src       = (string) $dze_got[0];
			$src_thumb = '';
		}
		if ( $remake && '' === $src ) {
			throw new RuntimeException( __( 'Pick the picture to remake.', 'dazont-ecom' ) );
		}
		// The scene: the fixed support or background this shop always shoots on.
		// A one-off edit of an image that already exists ("make the strap red")
		// keeps that image's own setting, so the scene only comes back if it was
		// explicitly asked for.
		// Nothing asked for: the PROMPT's own scene, never one answer for the
		// whole shop. default_scene() sat here and gave a studio backdrop to a
		// prompt asking for a customer's snapshot, which the sources block then
		// declares to be the background of the final photograph.
		$scenes = self::scenes();
		$sidx   = isset( $in['scene'] )
			? (int) $in['scene']
			: ( '' !== $src ? -1 : (int) ( $tpl['scene_i'] ?? -1 ) );
		$scene  = ( $sidx >= 0 && isset( $scenes[ $sidx ] ) ) ? $scenes[ $sidx ] : null;
		if ( $scene && ! wp_attachment_is_image( (int) $scene['image'] ) ) {
			// Deleted from the media library: say so instead of failing on the
			// product image, which is what the generic reader error would blame.
			throw new RuntimeException( __( 'The scene image is missing from the media library — pick it again under Settings → Product content.', 'dazont-ecom' ) );
		}
		// The product's photographs, as many as the shop asks for. Not all of
		// them: an edit model handed six angles of one bag reconciles them into
		// a seventh bag. Not one either — a single cropped shot is what makes
		// it invent the rest. The number is a setting, beside the fal.ai key.
		$product_ids = self::product_source_ids( $pid );
		// « J'UTILISE LE BULK CONTENT POUR METTRE À JOUR DES CENTAINES DE
		// PRODUITS. »
		//
		// Choisir une photo produit par produit n'a aucun sens sur trois cents
		// fiches : c'est exactement ce que le bulk sert à éviter. Mais c'est là
		// que le mélange de vues arrive, puisque chaque produit envoie tout ce
		// qu'il a — et l'écran en masse n'a jamais eu de sélecteur du tout.
		//
		// Une seule décision pour toute la série, donc : « la photo principale
		// seulement ». Le modèle ne voit qu'une face, il ne peut plus en
		// fabriquer une moyenne, et personne n'a rien coché trois cents fois.
		if ( ! empty( $in['only_main'] ) ) {
			$thumb       = (int) get_post_thumbnail_id( $pid );
			$product_ids = ( $thumb && wp_attachment_is_image( $thumb ) ) ? [ $thumb ] : array_slice( $product_ids, 0, 1 );
			$src_id      = 0;
			$src_ids     = [];
		}
		// SEULEMENT LES PHOTOS VENUES D'AILLEURS. « Il est toujours impossible
		// d'utiliser les images externes comme unique image à retravailler. »
		// Ce qui était collé partait TOUJOURS derrière les photos du produit :
		// aucune réponse du sélecteur ne disait « celles-ci, et rien d'autre ».
		// C'est un choix explicite — une case de plus sur le même sélecteur —
		// et jamais un défaut : la photo d'un fournisseur ajoutée pour le décor
		// est déjà devenue le produit, dans SA couleur, le jour où coller
		// suffisait à la mettre en tête.
		$only_pasted = ! empty( $in['only_pasted'] ) && $pastes;
		if ( $only_pasted ) {
			$product_ids = [];
			$src_id      = 0;
			$src_ids     = [];
		}
		// The one that was picked leads them: it is what the model works from,
		// and the others are the angles it does not show. An id that answers
		// for nothing is dropped rather than sent.
		// CHOISIR UNE PHOTO, C'EST LA CHOISIR — pas la mettre devant les autres.
		//
		// « J'aurais aimé refaire l'image qui m'était à disposition. Juste
		// cette image. Les images fournisseur sont souvent bonnes et
		// demandent un agrandissement ou un meilleur angle, et c'est
		// justement quand il y en a beaucoup que le modèle a du mal. »
		//
		// L'intention était écrite trois cents lignes plus haut — « remaking a
		// supplier shot is work done on that shot, not on the product in
		// general » — mais le code se contentait de RÉORDONNER : la photo
		// choisie passait en tête et les cinq autres partaient quand même.
		// Sur un 6b23-1, cinq faces contradictoires sans arbitre donnent un
		// arrière avec des pièces de l'avant. Une seule photo ne peut pas se
		// mélanger à une autre.
		// ONLY THIS PRODUCT'S, and any of them — not only the first ones the
		// shop's figure keeps for an ordinary run: the sheet worth working
		// from is often the last picture of the gallery. A colour's own
		// photograph counts: the picker shows it, with its colour written on
		// the tile, and a pick that is dropped in silence sends everything.
		$dze_asked = count( $src_ids );
		if ( $src_ids ) {
			$dze_own = self::product_image_ids( $pid );
			$src_ids = array_values( array_filter( $src_ids, static fn( $one ) => in_array( (int) $one, $dze_own, true ) && wp_attachment_is_image( (int) $one ) ) );
		}
		// A PICK THAT IS NO LONGER ON THE PRODUCT IS REFUSED, not replaced:
		// falling back to every photograph is paying for an order nobody
		// gave. Said before anything is sent — the preview says it too.
		if ( $dze_asked && count( $src_ids ) < $dze_asked ) {
			throw new RuntimeException( 1 === $dze_asked
				? __( 'The photograph you picked is no longer on this product — pick again.', 'dazont-ecom' )
				/* translators: %s: how many of the picked photographs are gone */
				: sprintf( __( '%s of the photographs you picked are no longer on this product — pick again.', 'dazont-ecom' ), $dze_asked - count( $src_ids ) ) );
		}
		if ( $src_ids ) {
			$product_ids = array_slice( $src_ids, 0, self::MAX_SOURCES );
			$src_id      = (int) $product_ids[0];
		} else {
			$src_id = 0;
		}

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
			throw new RuntimeException( __( 'Set a featured image on this product first.', 'dazont-ecom' ) );
		}

		// On a variation run every product line is read for THAT variation:
		// "Product title" is the variation's full name — the product plus
		// everything the whole group has in common, not the colour alone.
		$v_label = '' !== $v_value ? self::attribute_value_label( $v_attr, $v_value ) : '';
		$v_name  = '' !== $v_value ? self::variation_group_name( $pid, $v_attr, $v_value ) : '';
		// ONE ANSWER TO "WHAT IS THIS PROMPT SENT". The default used to be
		// written inline here and inline again on the screen that describes
		// it, so a row with no answer of its own was SENT the description and
		// DESCRIBED as carrying only the name. DZE_Content owns it now, and
		// the popup's tick boxes, this line and the list under them are three
		// readings of one value.
		$dze_keys = $tpl && array_key_exists( 'inputs', $tpl )
			? (array) $tpl['inputs']
			: DZE_Content::default_inputs( 'image' );
		$pl   = self::payload_lines( $pid, $dze_keys, (string) ( $tpl['inputs_meta'] ?? '' ), $v_name );
		// 3,000 characters (4.508.0, « la description produit est coupée dans le
		// prompt pour l'image »): 800 cut a 1,134-character description short.
		$pl   = mb_substr( trim( (string) preg_replace( '/\s+/', ' ', $pl ) ), 0, 3000 );
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
			$sources     = [];
			// WHAT EACH PICTURE IS, in the order it travels — for the screen
			// that shows what will be sent before anything is paid for.
			$labels      = [];
			$dze_thumb   = static fn( int $aid ): string => (string) wp_get_attachment_image_url( $aid, 'thumbnail' );
			$dze_main    = (int) get_post_thumbnail_id( $pid );
			$weight      = 0;
			// Counted WHERE THE LANE IS FILLED: read back from the request
			// afterwards it would be a second answer to one question, and the
			// two disagree.
			$dze_paste_n = 0;
			// Whether ✦ sends the product's main photograph with the picture, and
			// whether ahead of it; the shape the remake is asked for.
			$dze_remake_ref   = false;
			$dze_ref_first    = false;
			$dze_remake_ratio = '';
			if ( '' !== $src ) {
				// Editing one precise image: that image is the subject, on its
				// own. This is the ↻ on a tile — "make this one again" — and it
				// is the only lane where the answer is allowed to look like its
				// source, which is why it stands apart from everything below.
				//
				// ✦ REMAKE: THE PRODUCT ITSELF LEADS. « Sur ce produit on est sur
				// un camo kryptek mandrake, or je n'ai que des images d'autres
				// camo sous la main pour les détails. Il faut je pense envoyer
				// dans tous les cas l'image 1 principale comme référence. » A
				// detail pasted from another camouflage came back in that
				// camouflage: nothing in the request showed the product of this
				// page. Its main photograph now travels first — what the product
				// looks like — and the picture to remake second — its framing and
				// its construction (photo note « remake »). The main photograph
				// remade itself travels alone, as before.
				$dze_ref_uri = '';
				if ( $remake && $dze_main > 0 && $dze_main !== $src_att ) {
					try {
						$dze_ref_uri    = $this->fal_source_data_uri( $dze_main, 'large' );
						$dze_remake_ref = true;
					} catch ( \Throwable $e ) {
						// No file to read: the picture goes alone, as it did.
						$dze_remake_ref = false;
					}
				}
				$dze_ref_label = [
					'what'  => __( 'The main photograph — this product as it is', 'dazont-ecom' ),
					'thumb' => $dze_thumb( $dze_main ),
					'full'  => (string) wp_get_attachment_image_url( $dze_main, 'large' ),
					'id'    => $dze_main,
				];
				// WHICH ONE FIRST IS THE MODEL'S: Nano edits the first image it
				// is given, GPT Image takes the first as the subject
				// (image_models(): remake_ref_first).
				$dze_ref_first = $dze_remake_ref && ! empty( self::image_models()[ self::image_model_key() ]['remake_ref_first'] );
				if ( $dze_ref_first ) {
					$sources[] = $dze_ref_uri;
					$labels[]  = $dze_ref_label;
				}
				$sources[] = $src;
				$labels[]  = [ 'what' => __( 'The picture being retouched', 'dazont-ecom' ), 'thumb' => $src_thumb ];
				if ( $dze_remake_ref && ! $dze_ref_first ) {
					$sources[] = $dze_ref_uri;
					$labels[]  = $dze_ref_label;
				}
				// ITS OWN SHAPE: a 3:2 detail shot is remade as a 3:2 detail shot.
				if ( $remake ) {
					$dze_bytes = 0 === strpos( $src, 'data:' )
						? (string) base64_decode( (string) substr( $src, (int) strpos( $src, ',' ) + 1 ) ) // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- reading the picture's size.
						: (string) wp_remote_retrieve_body( wp_remote_get( $src, [ 'timeout' => 15 ] ) );
					$dze_dim          = '' !== $dze_bytes ? @getimagesizefromstring( $dze_bytes ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
					$dze_remake_ratio = is_array( $dze_dim ) ? self::nearest_ratio( (int) $dze_dim[0], (int) $dze_dim[1] ) : '';
				}
			} else {
				// EVERYTHING THAT TRAVELS IS A PHOTOGRAPH OF THIS PRODUCT.
				//
				// "Ces 2 fonctions n'ont rien à faire ici. Le 1, c'est évident,
				// on travaille toujours à partir de l'image principale. Le 2,
				// c'est évident, on envoie des images supplémentaires qui
				// apportent plus de détail sur le produit, et jamais rien
				// d'autre."
				//
				// This was three lanes and two questions on three screens — is
				// the product the subject, and is what you added a setting or
				// something to copy — and both questions had one answer all
				// along. The product's own photographs lead, main first;
				// whatever was handed in follows as more views of the same
				// product. That is also the best defence there is against
				// invented hardware: a part the model has been shown is a part
				// it does not have to make up.
				// How many of them: the shop's own figure caps the list
				// already (product_source_ids), and the DESTINATION narrows it
				// further, because the two jobs are not the same. Remaking the
				// MAIN photograph sends the featured image plus two — six angles
				// sent for that is how a remake came back built on a gallery
				// shot, in a setting of its own. A gallery shot takes the lot.
				// Photographs picked by hand are never cut: the screen says
				// « these N are sent », and it is the owner's order.
				$ids_out = ( ! $src_ids && ( 'main' === $target || 0 === strpos( $target, 'variation:' ) ) )
					? array_slice( $product_ids, 0, self::MAIN_SOURCES )
					: $product_ids;
				foreach ( $ids_out as $i => $aid ) {
					try {
						$uri = $this->fal_source_data_uri( (int) $aid, $i > 0 ? 'large' : 'full' );
					} catch ( \Throwable $e ) {
						continue;
					}
					if ( $i > 0 && ( array_sum( array_map( 'strlen', $sources ) ) + strlen( $uri ) ) > self::MAX_PAYLOAD ) {
						break;
					}
					$sources[] = $uri;
					$labels[]  = [
						'what'  => $src_ids
							? __( 'A photograph you picked', 'dazont-ecom' )
							: ( (int) $aid === $dze_main ? __( 'The main photograph', 'dazont-ecom' ) : __( 'A gallery photograph', 'dazont-ecom' ) ),
						'thumb' => $dze_thumb( (int) $aid ),
						'full'  => (string) wp_get_attachment_image_url( (int) $aid, 'large' ),
						'id'    => (int) $aid,
					];
				}
				if ( $pastes ) {
					$outside = self::read_data_uris( $pastes, self::MAX_PASTED, self::MAX_PAYLOAD );
					if ( ! $outside ) {
						throw new RuntimeException( __( 'That is not an image.', 'dazont-ecom' ) );
					}
					foreach ( $outside as $uri ) {
						if ( array_sum( array_map( 'strlen', $sources ) ) + strlen( $uri ) > self::MAX_PAYLOAD ) {
							break;
						}
						$sources[] = $uri;
						$labels[]  = [ 'what' => __( 'A photograph added from elsewhere', 'dazont-ecom' ), 'thumb' => '' ];
						$dze_paste_n++;
					}
				}
				if ( ! $sources ) {
					throw new RuntimeException( __( 'This product has no photograph to start from: set a featured image first.', 'dazont-ecom' ) );
				}
			}
			// Everything above IS the product, however it got here — its own
			// photographs and whatever was handed in for this run. Counted
			// once, here, because the paragraph appended below and the trace
			// printed afterwards must be told the same thing.
			$product_count = count( $sources );
			// The other colours of the same product, when the prompt asked for
			// them: never on a variation run — there, the colour being made is
			// the whole subject and its neighbours are exactly the confusion to
			// keep out.
			$variants = 0;
			// PICKED PHOTOGRAPHS TRAVEL ALONE: the screen says « only these »,
			// and other colours added behind them were a second answer the
			// owner never saw.
			// Nor behind the ONE picture being retouched (✦ on a picture): it
			// travels alone. And a prompt with no row of its own — the shipped
			// remake words, when the shop has none — asks for no colours.
			$dze_row = $tpl ? self::registry_row( (string) ( $tpl['id'] ?? '' ) ) : null;
			if ( '' === $src && ! $src_ids && ! $only_pasted && '' === $v_value && is_array( $dze_row ) && self::wants_variants( $dze_row ) ) {
				foreach ( $this->variant_images( $pid, $product_ids ) as $uri ) {
					if ( array_sum( array_map( 'strlen', $sources ) ) + strlen( $uri ) > self::MAX_PAYLOAD ) {
						break;
					}
					$sources[] = $uri;
					$labels[]  = [ 'what' => __( 'The same product in another colour', 'dazont-ecom' ), 'thumb' => '' ];
					$variants++;
				}
			}
			// NO PICTURE THE MODEL MADE GOES BACK IN AS « NOT LIKE THIS ».
			//
			// « Le slop commence à partir de la 2e image générée. La première est
			// mieux en général. » It did, and this is why: every gallery image after
			// the first was sent the one made just before it, told to be « clearly
			// different — another angle, another distance, another part ». An edit
			// model conditions on every picture it is handed, so image 3 was built
			// on image 2, which was built on image 1 — its smoothed camouflage and
			// its guessed geometry compounding — and « another part » of a product
			// shown in two photographs is a part the model has never seen, so it
			// invented one. The lane is gone: every image is made from the product's
			// photographs alone, exactly like the first one.
			$avoid = 0;
			// The scene is always last: it is the only image in the request
			// that is not the product.
			if ( $scene ) {
				$sources[] = $this->fal_source_data_uri( (int) $scene['image'] );
				$labels[]  = [
					/* translators: %s: the background's name */
					'what'  => sprintf( __( 'The background « %s »', 'dazont-ecom' ), (string) ( $scene['name'] ?? '' ) ),
					'thumb' => $dze_thumb( (int) $scene['image'] ),
					'full'  => (string) wp_get_attachment_image_url( (int) $scene['image'], 'large' ),
				];
			}
			// ONE PRODUCT, SEVERAL PHOTOGRAPHS OF IT. Editing one image handed
			// in is the only lane with a subject of its own — there the answer
			// is allowed to look like its source, and everywhere else it is the
			// product that is being photographed afresh.
			$prompt   .= $dze_remake_ref
				// What each of the two images IS: the product, the picture to remake.
				? "\n\n" . ( $dze_ref_first ? self::remake_note( 1, 2 ) : self::remake_note( 2, 1 ) )
				: self::sources_instruction( $product_count, $scene, $avoid, $variants, '' !== $src, 1 === $product_count && ( ! empty( $in['only_main'] ) || $src_id > 0 ) );
			if ( '' !== $v_value ) {
				// A pasted photograph IS that variation: it is shown as it is,
				// and only the picture around it has to be redone.
				// WHICH IMAGE shows that variation: its own photograph leads
				// the product's; a pasted one travels after them.
				$dze_v_at = $v_own ? 1 : ( $dze_paste_n > 0 ? $product_count - $dze_paste_n + 1 : 0 );
				$prompt  .= self::variation_instruction( $v_attr, $v_value, $dze_v_at );
			}
			// What the owner knows and no photograph shows — about the product,
			// and about this variation when there is one.
			$prompt .= self::note_lines( $pid, '' !== $v_value ? $v_attr . '::' . $v_value : '', $note );
			// WHAT THIS PROMPT HAS ALREADY MADE FOR THIS PRODUCT, IN WORDS —
			// for the product page, where photographs are made one after
			// another and each must not repeat the last. Never the pictures
			// themselves (made_lines() says why), and nothing that chooses
			// the subject: only framings not to make again.
			$dze_told = [];
			// ONLY A PROMPT THAT VARIES THE VIEW (prompt_varies()): told
			// « none of them … come closer to a part », a prompt for the
			// product in use drew a cuff close-up.
			if ( ! empty( $in['aware'] ) && ! empty( $tpl['vary'] ) ) {
				$prompt .= self::made_lines( $pid, (string) ( $tpl['id'] ?? '' ), $dze_redo );
				// What it was told — for its card (« avait pour consigne d'éviter »).
				$dze_told = self::$made_said;
			}
			$dze_replaces = ( $remake && $src_att && in_array( $src_att, array_map( 'intval', self::product_own_image_ids( $pid ) ), true ) ) ? $src_att : 0;
			// NOTHING APPENDED CHOOSES WHAT THE PHOTOGRAPH SHOWS. A hint that
			// asked the second attempt for "a detail of the material, the
			// stitching or the fastening" is the plugin choosing the subject of
			// the shot, on a product whose fastenings may never have been
			// photographed — which is where invented hardware comes from. What
			// two attempts of one prompt differ by is what the owner's prompt
			// says, and nothing else: no picture already made travels with it.
			DZE_Ai_Usage::unit( 'product_img' );
			DZE_Ai_Usage::about( $pid );
			// WHAT TRAVELLED, NAMED — read from the very counts the paragraph
			// above was built from, so the trace and the model were told the
			// same thing. A count alone cannot say which picture put a
			// scalloped border on every rug of the shop.
			$dze_made = self::sources_said( [
				[ __( 'of the product', 'dazont-ecom' ), $product_count - $dze_paste_n ],
				[ __( 'pasted in', 'dazont-ecom' ), $dze_paste_n ],
				[ __( 'of its other colours', 'dazont-ecom' ), $variants ],
			] );
			if ( $scene ) {
				$dze_made .= ( '' !== $dze_made ? ' · ' : '' ) . sprintf(
					/* translators: %s: the scene's name */
					__( 'the scene "%s"', 'dazont-ecom' ),
					(string) ( $scene['name'] ?? '' )
				);
			}
			// WHAT WILL BE SENT, WITHOUT SENDING IT. « Je ne comprends toujours
			// pas comment fonctionne la re-génération d'image. » The same order,
			// built by the same lines, stopped one step before the provider is
			// asked: the words the model reads and the pictures it sees, in their
			// order — and nothing is paid for.
			if ( ! empty( $in['dry'] ) ) {
				DZE_Ai_Usage::unit();
				DZE_Ai_Usage::about();
				foreach ( $labels as $dze_n => $dze_l ) {
					$labels[ $dze_n ]['n'] = $dze_n + 1;
				}
				return [
					'dry'    => true,
					'prompt' => $prompt,
					'images' => $labels,
					'target' => $target,
					'made'   => $dze_made,
				];
			}
			$image_url = $this->fal_generate( $prompt, $sources, '' !== $dze_remake_ratio ? $dze_remake_ratio : ( DZE_Content::clean_ratio( (string) ( $tpl['ratio'] ?? '' ) ) ?: 'auto' ), $pid, $dze_made );
			// empty(): the screens that host shoot() without being DZE_Content
			// (the gate's DZE_Shoot_Host) have no such property, and ordering is
			// never theirs to ask.
			if ( ! empty( self::$submit_only ) && ! empty( self::$submitted ) ) {
				// ORDERED, NOT MADE: the page asks after it (ajax_job), which
				// files the picture, its cost and its framing when it is done.
				$dze_job = self::$submitted + [
					'target' => $target,
					'recipe' => (string) ( $tpl['id'] ?? '' ),
					'tool'   => $remake ? 'remake' : 'generate',
					'base'   => $dze_base,
					'told'   => $dze_told,
					'replaces' => $dze_replaces,
					'stash'  => ! empty( $in['stash'] ) ? 1 : 0,
					'by'     => get_current_user_id(),
				];
				self::$submitted = [];
				self::job_add( $pid, $dze_job );
				DZE_Ai_Usage::unit();
				DZE_Ai_Usage::about();
				return [
					'job'    => (string) $dze_job['id'],
					'target' => $target,
					'recipe' => (string) ( $tpl['id'] ?? '' ),
					'model'  => (string) ( self::image_models()[ (string) $dze_job['model'] ]['label'] ?? $dze_job['model'] ),
				];
			}
			DZE_Ai_Usage::unit();
			DZE_Ai_Usage::about();
			DZE_Ai_Usage::finished( 'product_img' );
			self::charge_product( $pid, self::last_image_cost() );
			// Its card: fal_fetch() wrote the model, the price and the words;
			// the prompt that made it and its source are known only here.
			if ( class_exists( 'DZE_Ai_Card' ) && '' !== (string) $image_url ) {
				DZE_Ai_Card::put( $pid, (string) $image_url, [
					'recipe' => (string) ( $tpl['id'] ?? '' ),
					'tool'   => $remake ? 'remake' : 'generate',
					'base'   => $dze_base,
					'told'   => $dze_told,
				] );
			}

			if ( 'defer' === $mode ) {
				// Toolbox flow: never auto-attach — the result joins the session
				// gallery; a human selects what gets pushed to the product.
				if ( ! empty( $in['stash'] ) ) {
					self::stash( $pid, [
						'shot'   => $image_url,
						'target' => $dze_replaces ? 'replace:' . $dze_replaces : $target,
						'recipe' => (string) ( $tpl['id'] ?? '' ),
					] );
				}
				// The recipe travels with it: whoever files this picture later
				// names it and writes its alt text from the recipe that made
				// it, and working that out a second time is a second answer.
				return [
					'url'    => $image_url,
					'target' => $target,
					'recipe' => (string) ( $tpl['id'] ?? '' ),
					'spend'  => self::product_spend( $pid ),
				];
			}
			if ( ! $validated ) {
				return [ 'preview' => true, 'url' => $image_url, 'target' => $target, 'spend' => self::product_spend( $pid ) ];
			}
			$att_id = $this->sideload_seo( $image_url, $pid, $target, (string) ( $tpl['id'] ?? '' ) );
		} catch ( \Throwable $e ) {
			// Never "something went wrong". An exception with no message of its
			// own — a type error, a library throwing bare — used to reach the
			// screen as an empty string, and the screen said the one thing it
			// could say, which told nobody anything. What it was, and where,
			// goes to the screen AND to the weekly checkup.
			$why = trim( $e->getMessage() );
			if ( '' === $why ) {
				$why = get_class( $e ) . ' — ' . basename( $e->getFile() ) . ':' . $e->getLine();
			}
			if ( class_exists( 'DZE_Health' ) ) {
				DZE_Health::log( 'content', 'image generation (product ' . $pid . ')', $why );
			}
			throw new RuntimeException( $why );
		}
		return [
			'attachment' => (int) $att_id,
			'target'     => $target,
			'url'        => wp_get_attachment_image_url( (int) $att_id, 'medium' ),
			'spend'      => self::product_spend( $pid ),
		];
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
		// Refusing is not removing. Discard throws away what was generated and
		// files the refusal under Done — and leaves the product ON the list,
		// back where it started, because "I do not want this text" and "I am
		// done with this product" are two decisions and each has its own
		// button. It used to do both at once, so refusing one bad photograph
		// took the product off the screen it was being worked on.
		if ( 'discard' === $do && $ids ) {
			wp_send_json_success( [ 'left' => self::discard_products( $ids ), 'counts' => self::screen_counts() ] );
		}
		if ( 'add' === $do ) {
			// A pasted column travels as ONE field, never as one field per id:
			// PHP stops reading at `max_input_vars` and says nothing.
			$raw = isset( $_POST['paste'] ) ? (string) wp_unslash( $_POST['paste'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- read as digits only.
			$this->bulk_add_ids( self::paste_ids( $raw ), ! empty( $_POST['replace'] ) );
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
	/**
	 * What one field holds on the product right now.
	 *
	 * Read in one place, because two readers of the same thing drift: the
	 * panel that shows "what the product says today" and the generation that
	 * has to avoid repeating it are looking at the same value.
	 */
	public static function current_value( int $pid, string $fid ): string {
		$product = $pid ? wc_get_product( $pid ) : null;
		if ( ! $product instanceof WC_Product ) {
			return '';
		}
		$seo  = self::seo_keys();
		$dest = self::dest_for( $fid );
		switch ( $dest['type'] ) {
			case 'post_title':
				return (string) get_the_title( $pid );
			case 'post_content':
				return (string) get_post_field( 'post_content', $pid );
			case 'post_excerpt':
				return (string) get_post_field( 'post_excerpt', $pid );
			case 'seo_title':
				return (string) get_post_meta( $pid, $seo['title'], true );
			case 'seo_desc':
				return (string) get_post_meta( $pid, $seo['desc'], true );
			case 'attributes':
				return (string) self::attributes_summary( $product );
		}
		return (string) get_post_meta( $pid, (string) ( $dest['key'] ?? '_dze_' . $fid ), true );
	}

	public function ajax_current(): void {
		$this->guard();
		$pid     = isset( $_POST['post'] ) ? absint( $_POST['post'] ) : 0;
		$product = $pid ? wc_get_product( $pid ) : null;
		if ( ! $product instanceof WC_Product ) {
			wp_send_json_error( [ 'message' => __( 'Product not found.', 'dazont-ecom' ) ] );
		}
		$texts = [];
		foreach ( self::enabled_fields() as $fid => $f ) {
			$texts[ $fid ] = self::current_value( $pid, (string) $fid );
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
			// AND THE WAY TO IT. The toolbox is opened from three screens now,
			// and from two of them the product itself is nowhere: "j'ai ouvert
			// la toolbox, et j'aimerais ajouter des images externes pour
			// améliorer le contexte" — which is done on the product, in the
			// media library, not here.
			'edit'    => (string) ( get_edit_post_link( $pid, 'raw' ) ?: '' ),
			'view'    => (string) ( $product->get_permalink() ?: '' ),
			'cost'    => self::product_cost( $product ),
			// THE PRODUCT'S OWN PHOTOGRAPHS THAT TRAVEL WITH EACH IMAGE, so the
			// bill before the press counts what this product really sends.
			'sources' => count( self::product_source_ids( $pid ) ),
			'pending' => self::pending( $pid ),
			// THE PICTURES STILL BEING MADE: a page reopened finds its tiles
			// « being made » and goes on asking after them.
			'jobs'    => self::jobs_public( $pid ),
			// What this product has already cost in images.
			'spend'   => self::product_spend( $pid ),
			// THE BOX OPENS EMPTY. A note is for the run in front of you: read
			// back from the product it would be sent again, for ever, which is
			// the very thing that was wrong with it.
			'note'    => '',
		] );
	}

	/**
	 * WHAT WAS ASKED FOR THIS PRODUCT, read at the moment somebody looks.
	 *
	 * It used to travel inside the answer above — the bundle that says what the
	 * product HOLDS — and that bundle is cached in the browser for as long as
	 * the panel is open, because what a product holds only changes when the
	 * screen changes it. The log is the other kind of thing entirely: every
	 * run adds to it. So a product generated three times over went on showing
	 * the calls it had made before the panel was opened — "ne se met pas à jour
	 * non plus" — and nothing on the screen said the list was old.
	 *
	 * A reading that changes on its own is never cached beside one that does
	 * not. This one is asked for when the fold is OPENED, which also means the
	 * rows are not built at all for the people who never open it.
	 */
	public function ajax_object_log(): void {
		$this->guard();
		$pid = isset( $_POST['post'] ) ? absint( wp_unslash( $_POST['post'] ) ) : 0;
		if ( ! $pid || ! current_user_can( 'edit_post', $pid ) ) {
			wp_send_json_error( [ 'message' => __( 'Save the product first.', 'dazont-ecom' ) ] );
		}
		wp_send_json_success( [ 'log' => self::object_log_html( $pid ) ] );
	}

	/**
	 * One object's own calls, drawn.
	 *
	 * Split from the answer so it can be exercised, and rendered on the server
	 * because the rows are `DZE_Ai_Usage`'s markup: built again in JavaScript
	 * they would be a second renderer, and the two drift.
	 */
	public static function object_log_html( int $pid ): string {
		if ( ! class_exists( 'DZE_Ai_Usage' ) ) {
			return '';
		}
		$rows = DZE_Ai_Usage::object_log( $pid );
		ob_start();
		DZE_Ai_Usage::render_rows(
			$rows,
			__( 'Nothing has been asked for this product yet.', 'dazont-ecom' )
		);
		return (string) ob_get_clean();
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
		// '*' USED TO BE THE PRODUCT ITSELF — a note every image of it was
		// given, for ever, typed into a box that said so in small print and
		// was read by nobody: "la note est ponctuelle et n'a pas à être
		// enregistrée pour plus tard." It travels with the run now, so there
		// is nothing here to save. Anything already stored under it is no
		// longer sent; the key is declared in DZE_Cleanup and can be wiped.
		if ( self::NOTE_PRODUCT === $group ) {
			wp_send_json_success( [ 'saved' => false ] );
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

	/**
	 * Take photographs OUT of a product's waiting list, and nothing else.
	 *
	 * The one place that shrinks the store, so the ✗ on a tile, the accept that
	 * writes a picture onto the product and the refusal of what was not ticked
	 * all leave it in the same state. What each image was made for goes with
	 * it: a settled image leaves nothing of itself behind in the row.
	 *
	 * @param string[] $urls The photographs that are no longer waiting.
	 * @return int How many were actually taken out.
	 */
	public static function settle_shots( int $pid, array $urls ): int {
		$urls = array_values( array_filter( array_map( 'strval', $urls ) ) );
		if ( ! $pid || ! $urls ) {
			return 0;
		}
		// THEIR CARDS GO WITH THEM. A picture filed has already handed its card
		// to its attachment (sideload_seo()), so what is left is only ever the
		// card of a picture thrown away.
		if ( class_exists( 'DZE_Ai_Card' ) ) {
			DZE_Ai_Card::drop( $urls );
		}
		$waiting = self::pending( $pid );
		$had     = (array) ( $waiting['shots'] ?? [] );
		$waiting['shots'] = array_values( array_diff( $had, $urls ) );
		foreach ( $urls as $gone ) {
			unset( $waiting['targets'][ $gone ], $waiting['recipes'][ $gone ], $waiting['models'][ $gone ], $waiting['views'][ $gone ], $waiting['frames'][ $gone ], $waiting['flags'][ $gone ] );
		}
		if ( empty( $waiting['shots'] ) && empty( $waiting['texts'] ) ) {
			delete_post_meta( $pid, self::META_PENDING );
		} else {
			update_post_meta( $pid, self::META_PENDING, $waiting );
		}
		delete_transient( 'dze_pending_count' );
		return count( $had ) - count( (array) $waiting['shots'] );
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
		// The photographs go through the ONE function that owns that store, the
		// same one an accepted picture passes through: two ways of taking an
		// image out of the waiting list is how two screens start disagreeing
		// about what is waiting.
		self::settle_shots( $pid, $shots );
		if ( $fields ) {
			$waiting = self::pending( $pid );
			foreach ( $fields as $fid ) {
				unset( $waiting['texts'][ $fid ], $waiting['companions'][ $fid ] );
			}
			if ( empty( $waiting['shots'] ) && empty( $waiting['texts'] ) ) {
				delete_post_meta( $pid, self::META_PENDING );
			} else {
				update_post_meta( $pid, self::META_PENDING, $waiting );
			}
			delete_transient( 'dze_pending_count' );
		}
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
			// The gallery's rows, built here rather than copied from a row
			// already on the screen. The browser used to clone the first
			// existing one — which works beautifully until the gallery is
			// EMPTY, and then there is nothing to clone and nothing appears:
			// the first picture added to a product only showed up after a
			// reload, and it looked like a save that had half worked.
			'gallery_html' => self::gallery_rows_html( $gallery ),
			'gallery_ids'=> implode( ',', $gallery ),
		] );
	}

	/**
	 * WooCommerce's own gallery rows, as its meta box draws them.
	 *
	 * Kept beside the call that sends them and written the way WooCommerce
	 * writes them (html-product-images.php), so a gallery redrawn without a
	 * page reload is the same list the page would have shown — same classes,
	 * same delete action, same thumbnail size.
	 */
	private static function gallery_rows_html( array $ids ): string {
		$out = '';
		foreach ( $ids as $aid ) {
			$aid = (int) $aid;
			if ( ! $aid ) {
				continue;
			}
			$img = wp_get_attachment_image( $aid, 'thumbnail' );
			if ( '' === $img ) {
				continue; // deleted from the library: not a row, a hole.
			}
			$out .= '<li class="image" data-attachment_id="' . esc_attr( (string) $aid ) . '">'
				. $img
				. '<ul class="actions"><li><a href="#" class="delete tips" data-tip="'
				// The word is WooCommerce's, in whatever language this admin
				// is in — ours would read as a second plugin's button.
				// phpcs:ignore WordPress.WP.I18n.TextDomainMismatch, WordPress.WP.I18n.NonSingularStringLiteralText
				. esc_attr( function_exists( 'WC' ) ? __( 'Delete image', 'woocommerce' ) : 'Delete image' ) . '">'
				// phpcs:ignore WordPress.WP.I18n.TextDomainMismatch, WordPress.WP.I18n.NonSingularStringLiteralText
				. esc_html( function_exists( 'WC' ) ? __( 'Delete', 'woocommerce' ) : 'Delete' )
				. '</a></li></ul></li>';
		}
		return $out;
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
		// Which file each picture became: its framing follows it.
		$dze_attached = [];
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
			// IN THE PLACE OF ONE OF THE PRODUCT'S PHOTOGRAPHS: filed in the
			// gallery, then put where that photograph stood. One that is no
			// longer on the product leaves nothing to replace.
			$dze_old = 0;
			if ( 0 === strpos( $t, 'replace:' ) ) {
				$dze_old = (int) substr( $t, 8 );
				$t       = 'gallery';
				if ( ! in_array( $dze_old, array_map( 'intval', self::product_own_image_ids( $pid ) ), true ) ) {
					$dze_old = 0;
				}
			}
			// Two main images cannot both win: the first one asked for it.
			if ( 'main' === $t ) {
				if ( $main_up ) {
					$t = 'gallery';
				} else {
					$main_up = true;
				}
			}
			try {
				$dze_aid           = $this->sideload_seo( $u, $pid, $t, $recipe, $keep_old );
				$ids[]             = $dze_aid;
				$dze_attached[ $u ] = (int) $dze_aid;
				if ( $dze_old > 0 && $dze_aid > 0 ) {
					self::replace_in_place( $pid, $dze_old, (int) $dze_aid );
				}
			} catch ( \Throwable $e ) {
				$errors++;
				$why[] = $e->getMessage();
			}
		}
		// ITS FRAMING STAYS WITH IT. What the photographs made after it are
		// told not to repeat (made_views()) is read from the attachment once
		// the waiting list has let the picture go.
		$dze_wait  = self::pending( $pid );
		$dze_views = (array) ( $dze_wait['views'] ?? [] );
		foreach ( $dze_attached as $dze_u => $dze_aid ) {
			if ( ! empty( $dze_views[ $dze_u ] ) && $dze_aid > 0 ) {
				update_post_meta( $dze_aid, self::META_VIEW, (string) $dze_views[ $dze_u ] );
			}
			// The reader's framing stays with it: the next orders steer by it.
			if ( ! empty( $dze_wait['frames'][ $dze_u ] ) && $dze_aid > 0 ) {
				update_post_meta( $dze_aid, self::META_FRAME, (string) $dze_wait['frames'][ $dze_u ] );
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
		// A PHOTOGRAPH THAT IS ON THE PRODUCT IS NOT WAITING FOR A DECISION.
		// Nothing here ever told the waiting list that, so an accepted image
		// stayed in it for ever: the product went on being counted as "waiting
		// for your yes or no", and the "not like this" lane went on handing
		// that same picture back to the model on every later run of the same
		// slot. The browser cleared its own copy and the server kept its one.
		self::settle_shots( $pid, array_map( static fn( array $i ): string => (string) $i['url'], $items ) );
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
		$store = trim( self::store_context() );
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
			// The background THIS prompt sends — read from the prompt, the way
			// the request reads it. The shop-wide default used to be printed
			// here whatever the prompt was set to.
			$dze_scn = self::prompt_scene( $r );
			$parts[] = __( 'Background sent', 'dazont-ecom' ) . ': '
				. ( self::scene_index( $dze_scn ) >= 0 ? $dze_scn : __( '(none)', 'dazont-ecom' ) );
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
