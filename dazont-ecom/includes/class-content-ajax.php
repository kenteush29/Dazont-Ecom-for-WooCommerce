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
		if ( '' === trim( $payload ) ) {
			wp_send_json_error( [ 'message' => __( 'Fill in the product data first.', 'dazont-ecom' ) ] );
		}
		$override = isset( $_POST['prompt'] ) ? sanitize_textarea_field( wp_unslash( $_POST['prompt'] ) ) : '';
		$system   = 'You are an expert e-commerce copywriter. ' . self::store_context();
		$user     = ( '' !== trim( $override ) ? $override : self::prompt_for( $field ) ) . self::language_rule()
			. "\n\n--- PRODUCT DATA ---\n" . $payload . "\n";
		try {
			$text = DZE_Marketing_Ai::complete( $system, $user, self::model(), (int) ( $fields[ $field ]['tokens'] ?? 400 ) );
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
		if ( '' === trim( $payload ) ) {
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
			if ( $shots ) {
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
				'full'    => (string) ( wp_get_attachment_image_url( (int) $c['id'], 'large' ) ?: '' ),
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
	 * Proposes the store context by reading the shop.
	 *
	 * This line is prepended to every generation, so it decides the voice of
	 * the whole catalogue — and it is the hardest thing to write about your own
	 * shop, because you know it too well. The model does not: it is given the
	 * shop's name, its tagline, its best-selling categories and products and
	 * its price range, and asked for the three things that actually steer a
	 * copywriter — what this shop sells, who buys it, how to speak to them.
	 *
	 * Short on purpose. A paragraph here is a paragraph in front of every
	 * prompt, on every call, for every product.
	 */
	public function ajax_context(): void {
		$this->guard();
		if ( ! class_exists( 'DZE_Marketing_Ai' ) ) {
			wp_send_json_error( [ 'message' => __( 'The Marketing Assistant module holds the Anthropic key — switch it back on.', 'dazont-ecom' ) ] );
		}
		$facts = DZE_Marketing_Ai::instance()->shop_context_text();
		if ( '' === trim( $facts ) ) {
			wp_send_json_error( [ 'message' => __( 'There is not enough in this shop yet to read anything from it.', 'dazont-ecom' ) ] );
		}
		$system = 'You read a shop and describe it to the copywriter who will write its product pages.';
		$user   = "Here is what the shop is:\n\n" . $facts . "\n\n"
			. "Write its context line for that copywriter, in " . self::site_language() . ", in THREE short segments separated by \" > \":\n"
			. "1. the shop name and what it sells, in a few words;\n"
			. "2. who buys there — the real buyer, not a marketing persona;\n"
			. "3. the tone to write in — three adjectives at most.\n\n"
			. "Example of the shape expected: \"Kula Tactical > Military and tactical clothing and gear > Buyers: airsoft players, hunters, security staff who want kit that holds > Tone: sharp, factual, no hype\".\n"
			. "Answer with that single line and nothing else. No quotes, no preamble.";
		try {
			DZE_Ai_Usage::unit( 'store_context' );
			$out = DZE_Marketing_Ai::complete( $system, $user, self::model(), 300 );
		} catch ( \Throwable $e ) {
			DZE_Ai_Usage::unit();
			wp_send_json_error( [ 'message' => $e->getMessage() ] );
		}
		DZE_Ai_Usage::unit();
		DZE_Ai_Usage::finished( 'store_context' );
		wp_send_json_success( [
			'text'  => trim( wp_strip_all_tags( $out ) ),
			'facts' => $facts,
		] );
	}

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

	/** Builds the backdrop plate on demand and reports where it landed. */
	public function ajax_backdrop(): void {
		$this->guard();
		$light = isset( $_POST['light'] ) ? absint( $_POST['light'] ) : 252;
		$dark  = isset( $_POST['dark'] ) ? absint( $_POST['dark'] ) : 232;
		try {
			$id = self::make_backdrop( $light, $dark );
		} catch ( \Throwable $e ) {
			wp_send_json_error( [ 'message' => $e->getMessage() ] );
		}
		wp_send_json_success( [
			'id'    => $id,
			'name'  => __( 'Studio backdrop', 'dazont-ecom' ),
			'thumb' => (string) wp_get_attachment_image_url( $id, 'medium' ),
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
		$paste = isset( $_POST['paste'] ) ? (string) wp_unslash( $_POST['paste'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated as an image below.
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
			if ( $src_id && wp_attachment_is_image( $src_id ) ) {
				$sources[] = $this->fal_source_data_uri( $src_id, 'full' );
			} elseif ( '' !== $paste ) {
				// The photograph is already in the request, straight from the
				// clipboard or dropped from the desktop — and it is THE subject:
				// sending the product's other images beside it would only invite
				// the model to blend them.
				$sources[] = self::read_data_uri( $paste );
			} else {
				// The product's own photographs, main first. Two are enough here:
				// this lane is about speed, and the shape of a product is settled
				// by the first shot plus one more angle.
				foreach ( array_slice( self::product_source_ids( $pid ), 0, 2 ) as $i => $aid ) {
					try {
						$sources[] = $this->fal_source_data_uri( (int) $aid, $i > 0 ? 'medium_large' : 'large' );
					} catch ( \Throwable $e ) {
						continue;
					}
				}
			}
			if ( ! $sources ) {
				throw new RuntimeException( __( 'No image to work from: set a featured image, or paste the address of one.', 'dazont-ecom' ) );
			}
			// The background travels as the LAST image, exactly like a scene: a
			// surface the model can see beats a colour it has to imagine, and it
			// is the same file for every product — which is the whole point.
			$plate = $bg && wp_attachment_is_image( $bg ) ? $bg : 0;
			$count = count( $sources );
			if ( $plate ) {
				$sources[] = $this->fal_source_data_uri( $plate );
			}
			$base = '' !== trim( $override ) ? $override : self::quick_prompt();
			if ( '' === trim( $override ) && '' !== $recipe ) {
				$row = self::registry_row( $recipe );
				if ( $row && '' !== trim( (string) ( $row['prompt'] ?? '' ) ) ) {
					$base = (string) $row['prompt'];
				}
			}
			$prompt = $base
				. ( '' !== $note ? "\n\nAlso: " . $note : '' )
				. self::sources_instruction(
					$count,
					$plate ? [ 'prompt' => 'This is the shop\'s backdrop: reproduce its exact tone and its gradient, and place the product on it. Do not add anything else to it, and do not darken it.' ] : null
				);

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
		self::stash( $pid, [ 'shot' => $image_url ] );
		$main = (int) get_post_thumbnail_id( $pid );
		wp_send_json_success( [
			'url'  => $image_url,
			'main' => $main ? (string) wp_get_attachment_image_url( $main, 'large' ) : '',
		] );
	}

	public function ajax_image(): void {
		$this->guard();
		$pid    = isset( $_POST['post'] ) ? absint( $_POST['post'] ) : 0;
		$idx    = isset( $_POST['template'] ) ? absint( $_POST['template'] ) : 0;
		$mode   = isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : '';
		$custom = isset( $_POST['custom_prompt'] ) ? sanitize_textarea_field( wp_unslash( $_POST['custom_prompt'] ) ) : '';
		$src    = isset( $_POST['src_url'] ) ? esc_url_raw( wp_unslash( $_POST['src_url'] ) ) : '';
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
		if ( '' === $src && ! $product_ids ) {
			wp_send_json_error( [ 'message' => __( 'Set a featured image on this product first.', 'dazont-ecom' ) ] );
		}

		$pl   = $tpl ? self::payload_lines( $pid, (array) ( $tpl['inputs'] ?? [ 'title', 'description' ] ), (string) ( $tpl['inputs_meta'] ?? '' ) ) : self::payload_lines( $pid, [ 'title', 'description' ] );
		$pl   = mb_substr( trim( (string) preg_replace( '/\s+/', ' ', $pl ) ), 0, 800 );
		$ctx  = trim( self::store_context() . ' ' . $pl );
		$base = '' !== $custom ? $custom : (string) $tpl['prompt'];
		$prompt = ( $ctx ? "Product context: {$ctx}\n\n" : '' ) . $base;

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
			} else {
				// Everything we have of this product. The featured image comes
				// first and is never dropped; the rest joins while the request
				// body stays a sane size, and a broken file is skipped instead
				// of taking the whole generation down with it.
				foreach ( $product_ids as $i => $aid ) {
					try {
						// The featured image is the one the result is built on,
						// so it goes at full working size; the others are read
						// for information only and travel smaller — a lighter
						// request body and a faster answer, same understanding.
						$uri = $this->fal_source_data_uri( (int) $aid, $i > 0 ? 'medium_large' : 'large' );
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
			if ( $scene ) {
				$sources[] = $this->fal_source_data_uri( (int) $scene['image'] );
			}
			$prompt   .= self::sources_instruction( $product_count, $scene );
			DZE_Ai_Usage::unit( 'product_img' );
			$image_url = $this->fal_generate( $prompt, $sources );
			DZE_Ai_Usage::unit();
			DZE_Ai_Usage::finished( 'product_img' );

			if ( 'defer' === $mode ) {
				// Toolbox flow: never auto-attach — the result joins the session
				// gallery; a human selects what gets pushed to the product.
				if ( ! empty( $_POST['stash'] ) ) {
					self::stash( $pid, [ 'shot' => $image_url ] );
				}
				wp_send_json_success( [ 'url' => $image_url, 'target' => $tpl['target'] ?? 'gallery' ] );
			}
			if ( ! $validated ) {
				wp_send_json_success( [ 'preview' => true, 'url' => $image_url, 'target' => $tpl['target'] ?? 'gallery' ] );
			}
			$att_id = $this->sideload_seo( $image_url, $pid, (string) ( $tpl['target'] ?? 'gallery' ), (string) ( $tpl['id'] ?? '' ) );
		} catch ( \Throwable $e ) {
			wp_send_json_error( [ 'message' => $e->getMessage() ] );
		}
		wp_send_json_success( [
			'attachment' => (int) $att_id,
			'target'     => $tpl['target'] ?? 'gallery',
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

		if ( 'clear' === $do ) {
			self::set_bulk_list( [] );
			wp_send_json_success( [ 'left' => [] ] );
		}
		if ( 'remove' === $do && $ids ) {
			$list = array_values( array_diff( self::bulk_list(), $ids ) );
			self::set_bulk_list( $list );
			// The list as it now stands, read back: the screen shows what the
			// server holds, not what it hoped the server would hold.
			wp_send_json_success( [ 'left' => self::bulk_list() ] );
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
		foreach ( self::product_source_ids( $pid ) as $aid ) {
			$meta = wp_get_attachment_metadata( (int) $aid );
			$w    = (int) ( $meta['width'] ?? 0 );
			$h    = (int) ( $meta['height'] ?? 0 );
			$images[] = [
				// The id travels too: the image workshop works ON one of these.
				'id'    => (int) $aid,
				'thumb' => (string) ( wp_get_attachment_image_url( (int) $aid, 'thumbnail' ) ?: '' ),
				'full'  => (string) ( wp_get_attachment_image_url( (int) $aid, 'large' ) ?: '' ),
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
	public function ajax_pending_clear(): void {
		$this->guard();
		$pid    = isset( $_POST['post'] ) ? absint( $_POST['post'] ) : 0;
		$shots  = isset( $_POST['shots'] ) ? array_map( 'esc_url_raw', (array) wp_unslash( $_POST['shots'] ) ) : [];
		$fields = isset( $_POST['fields'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['fields'] ) ) : [];
		if ( ! $pid ) {
			wp_send_json_error( [ 'message' => __( 'Product not found.', 'dazont-ecom' ) ] );
		}
		if ( ! $shots && ! $fields ) {
			delete_post_meta( $pid, self::META_PENDING );
			delete_transient( 'dze_pending_count' );
			wp_send_json_success( [ 'cleared' => $pid, 'left' => [], 'waiting' => self::pending_count() ] );
		}
		$waiting = self::pending( $pid );
		if ( $shots ) {
			$waiting['shots'] = array_values( array_diff( (array) ( $waiting['shots'] ?? [] ), $shots ) );
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
		wp_send_json_success( [ 'left' => self::pending( $pid ), 'waiting' => self::pending_count() ] );
	}

	public function ajax_image_attach(): void {
		$this->guard();
		$pid = isset( $_POST['post'] ) ? absint( $_POST['post'] ) : 0;

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
		// The supplier shot a remake replaces is of no further use: taking it out
		// of the product and out of the library is what leaves a clean page and
		// a clean media folder behind. Only ever an image of THIS product.
		$replace = isset( $_POST['replace'] ) ? absint( $_POST['replace'] ) : 0;
		// Which recipe made these: it decides how the files are named, and a
		// name is not something to guess after the fact.
		$recipe = isset( $_POST['recipe'] ) ? sanitize_key( wp_unslash( $_POST['recipe'] ) ) : '';
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
				$ids[] = $this->sideload_seo( $u, $pid, $t, $recipe );
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
		if ( $replace && in_array( $replace, self::product_source_ids( $pid ), true ) ) {
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
