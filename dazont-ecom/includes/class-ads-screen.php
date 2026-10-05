<?php
defined( 'ABSPATH' ) || exit;

/**
 * The Google Ads screen: products, categories, countries, quarantine, Merchant
 * Center, connection.
 *
 * Built on the WPML Translations screen: the title from the catalogue, the
 * tabs from DZE_Screens, one filter row, the explanation under the table.
 * Every figure is read by DZE_Ads; this class only lays it out.
 */
final class DZE_Ads_Screen {

	private const PER_PAGE = 50;

	public static function menu(): void {
		add_submenu_page(
			DZE_Screens::PARENT,
			DZE_Screens::label( 'ads' ),
			DZE_Screens::label( 'ads' ),
			'manage_woocommerce',
			DZE_Ads::MENU_SLUG,
			[ __CLASS__, 'render' ]
		);
	}

	public static function assets( string $hook ): void {
		if ( false === strpos( $hook, DZE_Ads::MENU_SLUG ) ) {
			return;
		}
		DZE_Assets::admin_css();
		DZE_Assets::admin_js( 'dze-ads', 'admin/js/ads.js' );
		wp_localize_script( 'dze-ads', 'dzeAds', [
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( DZE_Ads::NONCE ),
			'i18n'    => [
				'working'  => __( 'Working…', 'dazont-ecom' ),
				'held'     => __( 'In quarantine', 'dazont-ecom' ),
				'back'     => __( 'Back in the ads', 'dazont-ecom' ),
				'failed'   => __( 'Not saved. Reload the page and try again.', 'dazont-ecom' ),
				'copied'   => __( 'Copied', 'dazont-ecom' ),
				'copyFail' => __( 'Select the text and copy it yourself.', 'dazont-ecom' ),
				'sureKey'  => __( 'A new key stops the script already pasted in Google Ads. Make a new key?', 'dazont-ecom' ),
				'sureSwitch' => __( 'Switch this Merchant Center account to the Dazont listing? Its WP All Export file stops being fetched, and every offer is sent from the shop.', 'dazont-ecom' ),
				'sureCat'  => __( 'Take every product of this category, and of the categories under it, out of the ads?', 'dazont-ecom' ),
			],
		] );
	}

	private static function tab(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- navigation only.
		$want = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( (string) $_GET['tab'] ) ) : '';
		$tabs = DZE_Screens::tabs_of( 'ads' );
		return isset( $tabs[ $want ] ) ? $want : 'products';
	}

	/** One request parameter, kept to the values it may have. */
	private static function arg( string $key, array $allowed, string $default ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- filters only.
		$v = isset( $_GET[ $key ] ) ? sanitize_key( wp_unslash( (string) $_GET[ $key ] ) ) : '';
		return in_array( $v, $allowed, true ) ? $v : $default;
	}

	private static function url( string $tab, array $args = [] ): string {
		return add_query_arg( array_merge( [ 'page' => DZE_Ads::MENU_SLUG, 'tab' => $tab ], $args ), admin_url( 'admin.php' ) );
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$tab   = self::tab();
		$holds = DZE_Ads::holds();
		echo '<div class="wrap dze-wrap dze-admin dze-ads"><h1>' . esc_html( DZE_Screens::label( 'ads' ) ) . '</h1>';
		$strip = [];
		foreach ( DZE_Screens::tabs_of( 'ads' ) as $id => $label ) {
			$strip[ $id ] = [
				'label' => $label,
				'url'   => self::url( $id ),
				// A BADGE MEANS « ACT ON ME »: what waits in quarantine for a look.
				'n'     => 'quarantine' === $id ? count( $holds['product'] ) + count( $holds['category'] ) : null,
			];
		}
		echo wp_kses_post( DZE_Screens::strip( $strip, $tab ) );
		switch ( $tab ) {
			case 'categories':
				self::render_categories();
				break;
			case 'countries':
				self::render_countries();
				break;
			case 'quarantine':
				self::render_quarantine( $holds );
				break;
			case 'merchant':
				self::render_merchant( $holds );
				break;
			case 'connection':
				self::render_connection();
				break;
			default:
				self::render_products();
		}
		echo '</div>';
	}

	// =========================================================================
	// Pieces
	// =========================================================================

	private static function money( float $v ): string {
		$symbol = html_entity_decode( (string) get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' );
		return $symbol . number_format_i18n( $v, abs( $v ) >= 100 ? 0 : 2 );
	}

	private static function dash(): string {
		return '<span class="dze-ads-nil">—</span>';
	}

	/** The period and the view, the one filter row of each work tab. */
	private static function filters( string $tab, int $span, string $show = '', array $shows = [] ): void {
		echo '<form method="get" class="dze-trd-global" action="' . esc_url( admin_url( 'admin.php' ) ) . '">';
		echo '<input type="hidden" name="page" value="' . esc_attr( DZE_Ads::MENU_SLUG ) . '" />';
		echo '<input type="hidden" name="tab" value="' . esc_attr( $tab ) . '" />';
		echo '<label for="dze-ads-span">' . esc_html__( 'Over', 'dazont-ecom' ) . '</label>';
		echo '<select name="span" id="dze-ads-span">';
		foreach ( DZE_Ads::SPANS as $n ) {
			/* translators: %d: a number of days */
			echo '<option value="' . (int) $n . '"' . selected( $span, $n, false ) . '>' . esc_html( sprintf( __( 'the last %d days', 'dazont-ecom' ), $n ) ) . '</option>';
		}
		echo '</select>';
		if ( $shows ) {
			echo '<select name="show" aria-label="' . esc_attr__( 'Show', 'dazont-ecom' ) . '">';
			foreach ( $shows as $k => $label ) {
				echo '<option value="' . esc_attr( $k ) . '"' . selected( $show, $k, false ) . '>' . esc_html( $label ) . '</option>';
			}
			echo '</select>';
		}
		echo '<button type="submit" class="button">' . esc_html__( 'Filter', 'dazont-ecom' ) . '</button>';
		if ( 30 !== $span || ( '' !== $show && 'all' !== $show ) ) {
			echo '<a class="dze-trd-clear" href="' . esc_url( self::url( $tab ) ) . '"><span class="dashicons dashicons-no-alt" aria-hidden="true"></span>' . esc_html__( 'Clear filters', 'dazont-ecom' ) . '</a>';
		}
		echo '</form>';
	}

	private static function span(): int {
		return (int) self::arg( 'span', [ '7', '30', '90' ], '30' );
	}

	/** Said once, above the figures, while Google Ads has never reported. */
	private static function no_report_notice(): void {
		if ( DZE_Ads::accounts() ) {
			return;
		}
		echo '<div class="notice notice-info inline dze-ads-notice"><p>'
			. esc_html__( 'What the ads cost is not here yet: Google Ads has not sent its first report. The sales are the shop\'s own and are already right.', 'dazont-ecom' )
			. ' <a href="' . esc_url( self::url( 'connection' ) ) . '">' . esc_html( DZE_Screens::label( 'ads', 'connection' ) ) . ' →</a></p></div>';
	}

	/** The header and figures shared by products and categories. */
	private static function head_cells(): string {
		return '<th class="num">' . esc_html__( 'Ad spend', 'dazont-ecom' ) . '</th>'
			. '<th class="num">' . esc_html__( 'Clicks', 'dazont-ecom' ) . '</th>'
			. '<th class="num" title="' . esc_attr__( 'Conversions counted by Google Ads, per click', 'dazont-ecom' ) . '">' . esc_html__( 'Conv. rate', 'dazont-ecom' ) . '</th>'
			. '<th class="num" title="' . esc_attr__( 'Conversion value counted by Google Ads, divided by the spend', 'dazont-ecom' ) . '">ROAS</th>'
			. '<th class="num" title="' . esc_attr__( 'The shop\'s own orders that came through an ad', 'dazont-ecom' ) . '">' . esc_html__( 'Sales from ads', 'dazont-ecom' ) . '</th>'
			. '<th class="num" title="' . esc_attr__( 'Every other order: search, direct, email, free listings…', 'dazont-ecom' ) . '">' . esc_html__( 'Other sales', 'dazont-ecom' ) . '</th>'
			. '<th>' . esc_html__( 'Signal', 'dazont-ecom' ) . '</th>';
	}

	private static function figure_cells( array $x, string $signal, bool $held ): string {
		$cost   = (float) $x['cost'];
		$clicks = (int) $x['clicks'];
		$cells  = '<td class="num">' . ( $cost > 0 ? esc_html( self::money( $cost ) ) : self::dash() ) . '</td>';
		$cells .= '<td class="num">' . ( $clicks ? esc_html( number_format_i18n( $clicks ) ) : self::dash() ) . '</td>';
		$cells .= '<td class="num">' . ( $clicks ? esc_html( number_format_i18n( 100 * (float) $x['conversions'] / $clicks, 1 ) . ' %' ) : self::dash() ) . '</td>';
		$cells .= '<td class="num">' . ( $cost > 0 ? esc_html( number_format_i18n( (float) $x['value'] / $cost, 2 ) ) : self::dash() ) . '</td>';
		foreach ( [ 'ads', 'other' ] as $side ) {
			$rev    = (float) $x[ $side . '_revenue' ];
			$orders = (int) $x[ $side . '_orders' ];
			$cells .= '<td class="num">' . ( $orders ? esc_html( self::money( $rev ) ) . '<span class="dze-ads-sub">'
				/* translators: %s: number of orders */
				. esc_html( sprintf( _n( '%s order', '%s orders', $orders, 'dazont-ecom' ), number_format_i18n( $orders ) ) ) . '</span>' : self::dash() ) . '</td>';
		}
		$chip = '';
		if ( $held ) {
			$chip = '<span class="dze-ads-chip is-held">' . esc_html__( 'In quarantine', 'dazont-ecom' ) . '</span>';
		} elseif ( 'over' === $signal ) {
			$chip = '<span class="dze-ads-chip is-over">' . esc_html__( 'Past the threshold', 'dazont-ecom' ) . '</span>';
		} elseif ( 'unfunded' === $signal ) {
			$chip = '<span class="dze-ads-chip is-unfunded">' . esc_html__( 'Sells without ads', 'dazont-ecom' ) . '</span>';
		}
		return $cells . '<td>' . $chip . '</td>';
	}

	private static function pager( int $total, int $page, string $tab, array $args ): void {
		$pages = (int) ceil( $total / self::PER_PAGE );
		if ( $pages < 2 ) {
			return;
		}
		echo '<p class="dze-ads-pager">';
		for ( $i = 1; $i <= $pages; $i++ ) {
			echo $i === $page
				? '<strong>' . (int) $i . '</strong> '
				: '<a href="' . esc_url( self::url( $tab, array_merge( $args, [ 'paged' => $i ] ) ) ) . '">' . (int) $i . '</a> ';
		}
		echo '</p>';
	}

	/** Category names straight from the tables: WPML filters get_terms() by the admin bar's language. */
	private static function category_rows( array $ids ): array {
		global $wpdb;
		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( ! $ids ) {
			return [];
		}
		$in = implode( ',', $ids );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integers only.
		$rows = (array) $wpdb->get_results( "SELECT t.term_id AS id, t.name AS name, tt.parent AS parent FROM {$wpdb->terms} t INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id AND tt.taxonomy = 'product_cat' WHERE t.term_id IN ( {$in} )", ARRAY_A );
		$out = [];
		foreach ( $rows as $r ) {
			$out[ (int) $r['id'] ] = [ 'name' => html_entity_decode( (string) $r['name'], ENT_QUOTES, 'UTF-8' ), 'parent' => (int) $r['parent'] ];
		}
		return $out;
	}

	// =========================================================================
	// Products
	// =========================================================================

	private static function render_products(): void {
		$span  = self::span();
		$show  = self::arg( 'show', [ 'all', 'over', 'unfunded', 'held' ], 'all' );
		$shows = [
			'all'      => __( 'Every product with figures', 'dazont-ecom' ),
			'over'     => __( 'Past the threshold', 'dazont-ecom' ),
			'unfunded' => __( 'Selling without ads', 'dazont-ecom' ),
			'held'     => __( 'In quarantine', 'dazont-ecom' ),
		];
		self::filters( 'products', $span, $show, $shows );
		self::no_report_notice();
		$fig  = DZE_Ads::figures( $span );
		$rule = DZE_Ads::rule();
		$held = DZE_Ads::held_products();
		$rows = [];
		foreach ( $fig['products'] as $pid => $x ) {
			$signal = DZE_Ads::signal( $x, $rule );
			$is     = isset( $held[ (int) $pid ] );
			if ( ( 'over' === $show && 'over' !== $signal ) || ( 'unfunded' === $show && 'unfunded' !== $signal ) || ( 'held' === $show && ! $is ) ) {
				continue;
			}
			$rows[ (int) $pid ] = [ 'x' => $x, 'signal' => $signal, 'held' => $is ];
		}
		uasort( $rows, 'unfunded' === $show
			? static fn( $a, $b ) => $b['x']['other_revenue'] <=> $a['x']['other_revenue']
			: static fn( $a, $b ) => [ $b['x']['cost'], $b['x']['ads_revenue'] + $b['x']['other_revenue'] ] <=> [ $a['x']['cost'], $a['x']['ads_revenue'] + $a['x']['other_revenue'] ] );
		$total = count( $rows );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- paging only.
		$page = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
		$rows = array_slice( $rows, ( $page - 1 ) * self::PER_PAGE, self::PER_PAGE, true );
		_prime_post_caches( array_keys( $rows ), false, false );

		self::totals( $fig['products'] );
		if ( ! $rows ) {
			echo '<p class="dze-ads-empty">' . esc_html( 'all' === $show ? __( 'No product spent or sold over this period.', 'dazont-ecom' ) : __( 'No product in this view over this period.', 'dazont-ecom' ) ) . '</p>';
			return;
		}
		echo '<div class="dze-ads-frame"><table class="widefat striped dze-ads-table"><thead><tr><th>' . esc_html__( 'Product', 'dazont-ecom' ) . '</th>' . self::head_cells() . '<th></th></tr></thead><tbody>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built escaped.
		foreach ( $rows as $pid => $r ) {
			$thumb = (string) get_the_post_thumbnail_url( $pid, 'thumbnail' );
			$full  = (string) get_the_post_thumbnail_url( $pid, 'large' );
			echo '<tr><td class="dze-ads-name">';
			if ( '' !== $thumb ) {
				echo '<img class="dze-hzoom" src="' . esc_url( $thumb ) . '" data-full="' . esc_url( $full ) . '" alt="" width="36" height="36" loading="lazy" />';
			}
			echo '<a href="' . esc_url( (string) get_edit_post_link( $pid ) ) . '">' . esc_html( get_the_title( $pid ) ) . '</a>';
			echo ' <a class="dze-ads-view" href="' . esc_url( (string) get_permalink( $pid ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'View', 'dazont-ecom' ) . ' ↗</a></td>';
			echo self::figure_cells( $r['x'], $r['signal'], $r['held'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built escaped.
			echo '<td class="dze-ads-act">';
			if ( $r['held'] ) {
				echo '<a href="' . esc_url( self::url( 'quarantine' ) ) . '">' . esc_html__( 'See the quarantine', 'dazont-ecom' ) . '</a>';
			} else {
				echo '<button type="button" class="button dze-ads-hold" data-kind="product" data-id="' . (int) $pid . '" data-spent="' . esc_attr( (string) round( (float) $r['x']['cost'], 2 ) ) . '">' . esc_html__( 'Take out of the ads', 'dazont-ecom' ) . '</button>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table></div>';
		self::pager( $total, $page, 'products', [ 'span' => $span, 'show' => $show ] );
		self::explain_products( $rule );
	}

	/** The whole of the period, above the list. */
	private static function totals( array $set ): void {
		$t = [ 'cost' => 0.0, 'ads' => 0.0, 'other' => 0.0, 'value' => 0.0 ];
		foreach ( $set as $x ) {
			$t['cost']  += (float) $x['cost'];
			$t['ads']   += (float) $x['ads_revenue'];
			$t['other'] += (float) $x['other_revenue'];
			$t['value'] += (float) $x['value'];
		}
		$all = $t['ads'] + $t['other'];
		echo '<div class="dze-ads-totals">';
		echo '<div><b>' . esc_html( $t['cost'] > 0 ? self::money( $t['cost'] ) : '—' ) . '</b><span>' . esc_html__( 'spent on product ads', 'dazont-ecom' ) . '</span></div>';
		echo '<div><b>' . esc_html( self::money( $t['ads'] ) ) . '</b><span>' . esc_html__( 'sold through an ad', 'dazont-ecom' ) . '</span></div>';
		echo '<div><b>' . esc_html( self::money( $t['other'] ) ) . '</b><span>' . esc_html__( 'sold otherwise', 'dazont-ecom' ) . '</span></div>';
		/* translators: %s: a percentage */
		echo '<div><b>' . esc_html( $all > 0 ? number_format_i18n( 100 * $t['ads'] / $all, 0 ) . ' %' : '—' ) . '</b><span>' . esc_html__( 'of sales came through an ad', 'dazont-ecom' ) . '</span></div>';
		echo '<div><b>' . esc_html( $t['cost'] > 0 ? number_format_i18n( $t['ads'] / $t['cost'], 2 ) : '—' ) . '</b><span>' . esc_html__( 'sales from ads per unit spent', 'dazont-ecom' ) . '</span></div>';
		echo '</div>';
	}

	private static function explain_products( array $rule ): void {
		echo '<details class="dze-ads-explain"><summary>' . esc_html__( 'How these figures are read', 'dazont-ecom' ) . '</summary>';
		echo '<p>' . esc_html__( 'A product shows the figures of all its variations and of all its translations. Ad spend, clicks, conversion rate and ROAS are Google Ads\' own. Sales are the shop\'s own orders: those that came through an ad (WooCommerce saw a Google click or a paid Google tag) and all the others.', 'dazont-ecom' ) . '</p>';
		echo '<p>' . esc_html( sprintf(
			/* translators: 1: an amount, 2: a ratio */
			__( 'Past the threshold: at least %1$s spent, and less than %2$s back for each unit spent, counting the better of the shop\'s ad sales and Google\'s conversion value. Sells without ads: two orders or more that came otherwise, while the ads spent less than a tenth of what they brought.', 'dazont-ecom' ),
			self::money( (float) $rule['spend'] ),
			number_format_i18n( (float) $rule['roas'], 2 )
		) ) . '</p>';
		echo '</details>';
	}

	// =========================================================================
	// Categories
	// =========================================================================

	private static function render_categories(): void {
		$span  = self::span();
		$show  = self::arg( 'show', [ 'all', 'over', 'unfunded' ], 'all' );
		$shows = [
			'all'      => __( 'Every category with figures', 'dazont-ecom' ),
			'over'     => __( 'Past the threshold', 'dazont-ecom' ),
			'unfunded' => __( 'Selling without ads', 'dazont-ecom' ),
		];
		self::filters( 'categories', $span, $show, $shows );
		self::no_report_notice();
		$fig   = DZE_Ads::figures( $span );
		$rule  = DZE_Ads::rule();
		$holds = DZE_Ads::holds()['category'];
		$cats  = $fig['categories'];
		$info  = self::category_rows( array_keys( $cats ) );
		// A held parent holds its children.
		$held = [];
		foreach ( array_keys( $cats ) as $tid ) {
			foreach ( array_merge( [ (int) $tid ], DZE_Ads::ancestors( (int) $tid ) ) as $up ) {
				if ( isset( $holds[ $up ] ) ) {
					$held[ (int) $tid ] = true;
				}
			}
		}
		// The tree, each parent before its children, by name.
		$kids = [];
		foreach ( $info as $tid => $c ) {
			$kids[ isset( $info[ $c['parent'] ] ) ? $c['parent'] : 0 ][] = $tid;
		}
		$order = [];
		$walk  = static function ( int $parent, int $depth ) use ( &$walk, &$order, $kids, $info ): void {
			$list = $kids[ $parent ] ?? [];
			usort( $list, static fn( $a, $b ) => strcasecmp( $info[ $a ]['name'], $info[ $b ]['name'] ) );
			foreach ( $list as $tid ) {
				$order[ $tid ] = $depth;
				$walk( $tid, $depth + 1 );
			}
		};
		$walk( 0, 0 );
		echo '<div class="dze-ads-frame"><table class="widefat dze-ads-table dze-ads-tree"><thead><tr><th>' . esc_html__( 'Category', 'dazont-ecom' ) . '</th><th class="num">' . esc_html__( 'Products', 'dazont-ecom' ) . '</th>' . self::head_cells() . '<th></th></tr></thead><tbody>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built escaped.
		$shown = 0;
		foreach ( $order as $tid => $depth ) {
			$x      = $cats[ $tid ];
			$signal = DZE_Ads::signal( $x, $rule );
			if ( ( 'over' === $show && 'over' !== $signal ) || ( 'unfunded' === $show && 'unfunded' !== $signal ) ) {
				continue;
			}
			$shown++;
			$is = isset( $held[ $tid ] );
			echo '<tr class="dze-ads-depth-' . (int) min( $depth, 4 ) . '"><td class="dze-ads-name" style="padding-left:' . (int) ( 10 + 18 * $depth ) . 'px">';
			echo esc_html( $info[ $tid ]['name'] );
			$link = get_term_link( (int) $tid, 'product_cat' );
			if ( is_string( $link ) ) {
				echo ' <a class="dze-ads-view" href="' . esc_url( $link ) . '" target="_blank" rel="noopener">' . esc_html__( 'View', 'dazont-ecom' ) . ' ↗</a>';
			}
			echo '</td><td class="num">' . esc_html( number_format_i18n( (int) $x['products'] ) ) . '</td>';
			echo self::figure_cells( $x, $signal, $is ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built escaped.
			echo '<td class="dze-ads-act">';
			if ( isset( $holds[ $tid ] ) ) {
				echo '<a href="' . esc_url( self::url( 'quarantine' ) ) . '">' . esc_html__( 'See the quarantine', 'dazont-ecom' ) . '</a>';
			} elseif ( ! $is ) {
				echo '<button type="button" class="button dze-ads-hold" data-kind="category" data-id="' . (int) $tid . '" data-spent="' . esc_attr( (string) round( (float) $x['cost'], 2 ) ) . '">' . esc_html__( 'Take out of the ads', 'dazont-ecom' ) . '</button>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table></div>';
		if ( ! $shown ) {
			echo '<p class="dze-ads-empty">' . esc_html__( 'No category in this view over this period.', 'dazont-ecom' ) . '</p>';
		}
		echo '<details class="dze-ads-explain"><summary>' . esc_html__( 'How these figures are read', 'dazont-ecom' ) . '</summary><p>'
			. esc_html__( 'A category shows the work of the products filed in it and in every category under it. A product filed in two categories counts in each, and once in the category above both. Taking a category out of the ads takes out every product in it and under it, including those added later.', 'dazont-ecom' )
			. '</p></details>';
	}

	// =========================================================================
	// Countries
	// =========================================================================

	private static function render_countries(): void {
		$span = self::span();
		self::filters( 'countries', $span );
		self::no_report_notice();
		$fig   = DZE_Ads::figures( $span );
		$set   = $fig['countries'];
		$names = function_exists( 'WC' ) && WC()->countries ? (array) WC()->countries->get_countries() : [];
		uasort( $set, static fn( $a, $b ) => ( $b['ads_revenue'] + $b['other_revenue'] ) <=> ( $a['ads_revenue'] + $a['other_revenue'] ) );
		$top = 0.0;
		foreach ( $set as $x ) {
			$top = max( $top, (float) $x['ads_revenue'] + (float) $x['other_revenue'], (float) $x['cost'] );
		}
		if ( ! $set ) {
			echo '<p class="dze-ads-empty">' . esc_html__( 'No sale and no ad spend over this period.', 'dazont-ecom' ) . '</p>';
			return;
		}
		echo '<div class="dze-ads-legend"><span><i class="is-ads"></i>' . esc_html__( 'Sales from ads', 'dazont-ecom' ) . '</span><span><i class="is-other"></i>' . esc_html__( 'Other sales', 'dazont-ecom' ) . '</span><span><i class="is-cost"></i>' . esc_html__( 'Ad spend', 'dazont-ecom' ) . '</span></div>';
		echo '<div class="dze-ads-frame"><table class="widefat dze-ads-table dze-ads-countries"><thead><tr>'
			. '<th>' . esc_html__( 'Country', 'dazont-ecom' ) . '</th>'
			. '<th class="dze-ads-barcol">' . esc_html__( 'Sales and spend', 'dazont-ecom' ) . '</th>'
			. '<th class="num">' . esc_html__( 'Sales from ads', 'dazont-ecom' ) . '</th>'
			. '<th class="num">' . esc_html__( 'Other sales', 'dazont-ecom' ) . '</th>'
			. '<th class="num">' . esc_html__( 'Ad spend', 'dazont-ecom' ) . '</th>'
			. '<th class="num" title="' . esc_attr__( 'Sales from ads divided by the spend', 'dazont-ecom' ) . '">' . esc_html__( 'Return', 'dazont-ecom' ) . '</th>'
			. '</tr></thead><tbody>';
		foreach ( $set as $cc => $x ) {
			$name = '??' === $cc ? __( 'Unknown', 'dazont-ecom' ) : html_entity_decode( (string) ( $names[ $cc ] ?? $cc ), ENT_QUOTES, 'UTF-8' );
			$ads  = (float) $x['ads_revenue'];
			$oth  = (float) $x['other_revenue'];
			$cost = (float) $x['cost'];
			$w    = static fn( float $v ) => $top > 0 ? round( 100 * $v / $top, 2 ) : 0;
			echo '<tr><td class="dze-ads-name">' . esc_html( $name ) . '</td>';
			echo '<td class="dze-ads-barcol"><div class="dze-ads-bar" role="img" aria-label="' . esc_attr( $name ) . '">'
				. '<span class="is-ads" style="width:' . esc_attr( (string) $w( $ads ) ) . '%"></span>'
				. '<span class="is-other" style="width:' . esc_attr( (string) $w( $oth ) ) . '%"></span></div>'
				. ( $cost > 0 ? '<div class="dze-ads-bar is-thin"><span class="is-cost" style="width:' . esc_attr( (string) $w( $cost ) ) . '%"></span></div>' : '' )
				. '</td>';
			foreach ( [ 'ads', 'other' ] as $side ) {
				$orders = (int) $x[ $side . '_orders' ];
				echo '<td class="num">' . ( $orders ? esc_html( self::money( (float) $x[ $side . '_revenue' ] ) ) . '<span class="dze-ads-sub">'
					/* translators: %s: number of orders */
					. esc_html( sprintf( _n( '%s order', '%s orders', $orders, 'dazont-ecom' ), number_format_i18n( $orders ) ) ) . '</span>' : self::dash() ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built escaped.
			}
			echo '<td class="num">' . ( $cost > 0 ? esc_html( self::money( $cost ) ) : self::dash() ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built escaped.
			echo '<td class="num">' . ( $cost > 0 ? esc_html( number_format_i18n( $ads / $cost, 2 ) ) : self::dash() ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built escaped.
		}
		echo '</tbody></table></div>';
		echo '<details class="dze-ads-explain"><summary>' . esc_html__( 'How these figures are read', 'dazont-ecom' ) . '</summary><p>'
			. esc_html__( 'Sales are placed in the country the order was sent to. Ad spend is placed in the country Google Ads served the product in. Every bar is drawn to the same scale.', 'dazont-ecom' )
			. '</p></details>';
	}

	// =========================================================================
	// Quarantine
	// =========================================================================

	private static function render_quarantine( array $holds ): void {
		$rule = DZE_Ads::rule();
		// THE RULE FIRST: it is what puts products here by itself.
		echo '<form method="post" action="options.php" class="dze-ads-rule">';
		settings_fields( 'dze_ads_rule' );
		echo '<label class="dze-ads-switch"><input type="checkbox" name="' . esc_attr( DZE_Ads::OPT_RULE ) . '[auto]" value="1"' . checked( $rule['auto'], true, false ) . ' /> <strong>' . esc_html__( 'Take products out of the ads by themselves', 'dazont-ecom' ) . '</strong></label>';
		echo '<p>';
		printf(
			/* translators: 1: amount field, 2: ratio field, 3: period select */
			esc_html__( 'When a product has spent %1$s or more and brought back less than %2$s for each unit spent, over %3$s.', 'dazont-ecom' ),
			'<input type="number" min="1" step="1" name="' . esc_attr( DZE_Ads::OPT_RULE ) . '[spend]" value="' . esc_attr( (string) $rule['spend'] ) . '" class="small-text" /> ' . esc_html( (string) get_woocommerce_currency() ),
			'<input type="number" min="0" step="0.1" name="' . esc_attr( DZE_Ads::OPT_RULE ) . '[roas]" value="' . esc_attr( (string) $rule['roas'] ) . '" class="small-text" />',
			'<select name="' . esc_attr( DZE_Ads::OPT_RULE ) . '[span]"><option value="30"' . selected( $rule['span'], 30, false ) . '>' . esc_html__( '30 days', 'dazont-ecom' ) . '</option><option value="90"' . selected( $rule['span'], 90, false ) . '>' . esc_html__( '90 days', 'dazont-ecom' ) . '</option></select>'
		); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the fields are built escaped.
		echo '</p><p class="description">' . esc_html__( 'Checked after each Google Ads report. Off, the products past this threshold are only pointed out in Products. A category is never taken out by itself: it holds too many products for that.', 'dazont-ecom' ) . '</p>';
		submit_button( __( 'Save', 'dazont-ecom' ), 'secondary', 'submit', false );
		echo '</form>';

		$list = [];
		foreach ( $holds['product'] as $pid => $v ) {
			$list[] = [ 'kind' => 'product', 'id' => (int) $pid, 'v' => $v, 'name' => get_the_title( (int) $pid ), 'page' => (string) get_permalink( (int) $pid ), 'edit' => (string) get_edit_post_link( (int) $pid ) ];
		}
		$info = self::category_rows( array_keys( $holds['category'] ) );
		foreach ( $holds['category'] as $tid => $v ) {
			$link   = get_term_link( (int) $tid, 'product_cat' );
			$list[] = [ 'kind' => 'category', 'id' => (int) $tid, 'v' => $v, 'name' => (string) ( $info[ (int) $tid ]['name'] ?? '#' . $tid ), 'page' => is_string( $link ) ? $link : '', 'edit' => (string) get_edit_term_link( (int) $tid, 'product_cat' ) ];
		}
		if ( ! $list ) {
			echo '<p class="dze-ads-empty">' . esc_html__( 'Nothing in quarantine. A product or a category taken out of the ads waits here until its page has been looked at again.', 'dazont-ecom' ) . '</p>';
			return;
		}
		usort( $list, static fn( $a, $b ) => (int) $b['v']['since'] <=> (int) $a['v']['since'] );
		echo '<div class="dze-ads-frame"><table class="widefat striped dze-ads-table"><thead><tr><th>' . esc_html__( 'Taken out of the ads', 'dazont-ecom' ) . '</th><th>' . esc_html__( 'Since', 'dazont-ecom' ) . '</th><th>' . esc_html__( 'Why', 'dazont-ecom' ) . '</th><th>' . esc_html__( 'Before it goes back', 'dazont-ecom' ) . '</th></tr></thead><tbody>';
		foreach ( $list as $q ) {
			$v      = $q['v'];
			$opened = ! empty( $v['opened'] );
			echo '<tr><td class="dze-ads-name">' . ( 'category' === $q['kind'] ? '<span class="dze-ads-chip">' . esc_html__( 'Category', 'dazont-ecom' ) . '</span> ' : '' )
				. '<a href="' . esc_url( $q['edit'] ) . '">' . esc_html( $q['name'] ) . '</a></td>';
			echo '<td>' . esc_html( wp_date( get_option( 'date_format' ), (int) $v['since'] ) ) . '</td>';
			echo '<td>' . esc_html( 'rule' === $v['why']
				/* translators: %s: an amount */
				? sprintf( __( 'Past the threshold, %s spent', 'dazont-ecom' ), self::money( (float) $v['spent'] ) )
				: __( 'Taken out by hand', 'dazont-ecom' ) ) . '</td>';
			echo '<td class="dze-ads-act">';
			if ( '' !== $q['page'] ) {
				echo '<a class="button dze-ads-open" href="' . esc_url( $q['page'] ) . '" target="_blank" rel="noopener" data-kind="' . esc_attr( $q['kind'] ) . '" data-id="' . (int) $q['id'] . '">'
					. esc_html( 'category' === $q['kind'] ? __( 'Look at the category page', 'dazont-ecom' ) : __( 'Look at the product page', 'dazont-ecom' ) ) . ' ↗</a> ';
			}
			echo '<button type="button" class="button button-primary dze-ads-release" data-kind="' . esc_attr( $q['kind'] ) . '" data-id="' . (int) $q['id'] . '"' . disabled( $opened, false, false )
				. ' title="' . esc_attr__( 'Open its page first', 'dazont-ecom' ) . '">' . esc_html__( 'Put back in the ads', 'dazont-ecom' ) . '</button>';
			echo '<span class="dze-ads-said" aria-live="polite">' . ( $opened ? esc_html__( 'Page looked at.', 'dazont-ecom' ) : '' ) . '</span>';
			echo '</td></tr>';
		}
		echo '</tbody></table></div>';
		echo '<details class="dze-ads-explain"><summary>' . esc_html__( 'How the quarantine works', 'dazont-ecom' ) . '</summary><p>'
			. esc_html__( 'A product in quarantine keeps its page, its price and its free listings on Google. Only the ads leave it out: its offers carry « excluded_destination » in the Merchant Center listing, which every campaign obeys, Performance Max included. It goes back once its page has been opened from here: photos, price, description and reviews are what make a click buy.', 'dazont-ecom' )
			. '</p><p>' . esc_html__( 'Merchant Center hears of it when the Dazont listing is sent to Google, which is not switched on yet.', 'dazont-ecom' ) . '</p></details>';
	}

	// =========================================================================
	// Merchant Center
	// =========================================================================

	private static function render_merchant( array $holds ): void {
		$langs = DZE_Gmc_Feed::languages();
		if ( ! $langs ) {
			echo '<p class="dze-ads-empty">' . esc_html__( 'No Merchant Center account is set up.', 'dazont-ecom' ) . ' ';
			$where = DZE_Screens::url( 'marketing', 'gmc' );
			if ( '' !== $where ) {
				echo '<a href="' . esc_url( $where ) . '">' . esc_html( DZE_Screens::name( 'marketing', 'gmc' ) ) . ' →</a>';
			}
			echo '</p>';
			return;
		}
		$access = self::google_access();
		if ( ! $access['ok'] ) {
			echo '<div class="notice notice-error inline dze-ads-notice"><p><strong>' . esc_html__( 'Merchant Center cannot be reached.', 'dazont-ecom' ) . '</strong> '
				. esc_html( $access['said'] ) . ' <a href="' . esc_url( self::url( 'connection' ) ) . '">' . esc_html( DZE_Screens::label( 'ads', 'connection' ) ) . ' →</a></p></div>';
		}
		$held = DZE_Ads::held_products();
		echo '<div class="dze-ads-frame"><table class="widefat dze-ads-table dze-ads-gmc"><thead><tr><th>' . esc_html__( 'Account', 'dazont-ecom' ) . '</th><th class="num">' . esc_html__( 'Offers in the listing', 'dazont-ecom' ) . '</th><th class="num">' . esc_html__( 'Out of the ads', 'dazont-ecom' ) . '</th><th>' . esc_html__( 'Merchant Center reads', 'dazont-ecom' ) . '</th><th></th></tr></thead><tbody>';
		foreach ( $langs as $lang ) {
			$all  = DZE_Gmc_Feed::count( $lang );
			$out  = $held ? DZE_Gmc_Feed::count( $lang, array_keys( $held ) ) : 0;
			$flag = DZE_Wpml::is_active() && 'default' !== $lang ? DZE_Wpml::flag_html( $lang ) : '';
			$name = '';
			foreach ( DZE_Gmc::get_accounts() as $acc ) {
				if ( (string) ( $acc['language'] ?? '' ) === $lang ) {
					$name = '' !== (string) ( $acc['name'] ?? '' ) ? (string) $acc['name'] : (string) ( $acc['merchant_id'] ?? '' );
				}
			}
			$st = DZE_Gmc_Feed::state( $lang );
			echo '<tr><td>' . $flag . ' ' . esc_html( '' !== $name ? $name : __( 'The shop', 'dazont-ecom' ) ) . '</td>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- flag_html() escapes.
				. '<td class="num">' . esc_html( number_format_i18n( $all ) ) . '</td><td class="num">' . ( $out ? esc_html( number_format_i18n( $out ) ) : self::dash() ) . '</td><td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built escaped.
			if ( DZE_Gmc_Feed::switched( $lang ) ) {
				/* translators: %s: a date */
				echo '<strong>' . esc_html( sprintf( __( 'The Dazont listing, since %s', 'dazont-ecom' ), wp_date( get_option( 'date_format' ), (int) $st['switched'] ) ) ) . '</strong>';
				if ( ! empty( $st['running'] ) ) {
					echo '<span class="dze-ads-sub">' . esc_html( sprintf(
						/* translators: 1: offers looked at, 2: offers in all, 3: sent, 4: refused */
						__( 'Sending: %1$s of %2$s looked at, %3$s sent, %4$s refused by Google', 'dazont-ecom' ),
						number_format_i18n( (int) ( $st['seen'] ?? 0 ) ),
						number_format_i18n( $all ),
						number_format_i18n( (int) ( $st['sent'] ?? 0 ) ),
						number_format_i18n( (int) ( $st['refused'] ?? 0 ) )
					) ) . '</span>';
				} elseif ( ! empty( $st['done'] ) ) {
					echo '<span class="dze-ads-sub">' . esc_html( sprintf(
						/* translators: 1: how long ago, 2: sent, 3: refused */
						__( 'Up to date %1$s ago: %2$s sent, %3$s refused by Google', 'dazont-ecom' ),
						human_time_diff( (int) $st['done'] ),
						number_format_i18n( (int) ( $st['sent'] ?? 0 ) ),
						number_format_i18n( (int) ( $st['refused'] ?? 0 ) )
					) ) . '</span>';
				}
				if ( ! empty( $st['refusals'] ) ) {
					echo '<details class="dze-ads-sub"><summary>' . esc_html__( 'Why Google refused some', 'dazont-ecom' ) . '</summary><ul>';
					foreach ( array_slice( (array) $st['refusals'], 0, 5 ) as $r ) {
						echo '<li><a href="' . esc_url( (string) get_edit_post_link( (int) wp_get_post_parent_id( (int) $r['offer'] ) ?: (int) $r['offer'] ) ) . '">#' . (int) $r['offer'] . '</a> ' . esc_html( (string) $r['said'] ) . '</li>';
					}
					echo '</ul></details>';
				}
			} else {
				echo esc_html__( 'The file it was given before (WP All Export)', 'dazont-ecom' );
			}
			echo '</td><td class="dze-ads-act">';
			if ( DZE_Gmc_Feed::switched( $lang ) ) {
				echo '<button type="button" class="button dze-ads-do" data-action="dze_ads_gmc_start" data-lang="' . esc_attr( $lang ) . '">' . esc_html__( 'Send everything now', 'dazont-ecom' ) . '</button> ';
				echo '<button type="button" class="button dze-ads-do" data-action="dze_ads_gmc_issues" data-lang="' . esc_attr( $lang ) . '" data-into="dze-ads-issues-' . esc_attr( $lang ) . '">' . esc_html__( 'What Google says', 'dazont-ecom' ) . '</button>';
			} else {
				echo '<button type="button" class="button button-primary dze-ads-do" data-action="dze_ads_gmc_switch" data-lang="' . esc_attr( $lang ) . '" data-sure="1"' . disabled( $access['ok'], false, false ) . '>' . esc_html__( 'Switch to the Dazont listing', 'dazont-ecom' ) . '</button> ';
				echo '<button type="button" class="button dze-ads-do" data-action="dze_ads_gmc_issues" data-lang="' . esc_attr( $lang ) . '" data-into="dze-ads-issues-' . esc_attr( $lang ) . '"' . disabled( $access['ok'], false, false ) . '>' . esc_html__( 'What Google says today', 'dazont-ecom' ) . '</button>';
			}
			echo '<span class="dze-ads-said" aria-live="polite"></span></td></tr>';
			echo '<tr class="dze-ads-issues-row"><td colspan="5" id="dze-ads-issues-' . esc_attr( $lang ) . '" hidden></td></tr>';
		}
		echo '</tbody></table></div>';
		echo '<details class="dze-ads-explain"><summary>' . esc_html__( 'What switching does', 'dazont-ecom' ) . '</summary><p>'
			. esc_html__( 'Dazont adds a data source of its own to that Merchant Center account, « Dazont Ecom », in the same language, under the same feed label and for the same countries as the file Google reads now. It switches off the daily fetch of that file — fetched again, it would put back August\'s prices every morning — and sends every offer of the listing. Each offer keeps its id, so it is the same product to Google, with its history. From then on, a product saved, a stock change or a discount rule changed is sent within minutes, and everything is compared again every night; only what changed is sent. An offer that leaves the listing leaves Merchant Center.', 'dazont-ecom' )
			. '</p><p>' . esc_html__( 'One offer per published simple product and per enabled variation chosen for Merchant Center (GMC product activation). A variation is grouped under its product; prices are the ones the page shows; no brand is sent; an offer without a price is left out. A product in quarantine stays listed and leaves the ads.', 'dazont-ecom' )
			. '</p></details>';
	}

	/**
	 * Can Merchant Center be reached? Read from what is stored, never asked of
	 * Google while the page loads.
	 *
	 * @return array{ok:bool,said:string,email:string}
	 */
	private static function google_access(): array {
		$g     = DZE_Gmc::instance();
		$email = $g->service_email();
		if ( '' !== $email ) {
			return [ 'ok' => true, 'said' => '', 'email' => $email ];
		}
		$c = DZE_Gmc::get_connection();
		if ( ! empty( $c['refresh_token'] ) && empty( $c['broken'] ) ) {
			return [ 'ok' => true, 'said' => '', 'email' => '' ];
		}
		return [
			'ok'    => false,
			'said'  => ! empty( $c['refresh_token'] ) ? DZE_Gmc::broken_message() : __( 'No Google access is set up yet.', 'dazont-ecom' ),
			'email' => '',
		];
	}

	// =========================================================================
	// Connection
	// =========================================================================

	private static function render_connection(): void {
		$accounts = DZE_Ads::accounts();
		$access   = self::google_access();
		$api      = DZE_Ads::api_settings();
		$where    = DZE_Screens::url( 'marketing', 'gmc' );

		// 1. ONE GOOGLE KEY FOR MERCHANT CENTER AND GOOGLE ADS.
		echo '<h2>' . esc_html__( 'Google access', 'dazont-ecom' ) . '</h2><div class="dze-ads-steps">';
		if ( '' !== $access['email'] ) {
			echo '<p>' . esc_html__( 'Merchant Center and Google Ads are read with this service account:', 'dazont-ecom' ) . '</p>';
			echo '<p><code class="dze-ads-email" id="dze-ads-email">' . esc_html( $access['email'] ) . '</code> <button type="button" class="button" id="dze-ads-copy-email">' . esc_html__( 'Copy the address', 'dazont-ecom' ) . '</button></p>';
			echo '<p>' . esc_html__( 'It must be a user of each Merchant Center account (Settings → People and access, role Standard) and of the Google Ads account (Admin → Access and security; read only is enough).', 'dazont-ecom' ) . '</p>';
		} else {
			echo '<p>' . esc_html__( 'Google is reached with a service account: one key for Merchant Center and Google Ads, which never expires. A Google account connected through an app left in « Testing » is disconnected by Google after seven days.', 'dazont-ecom' ) . '</p><ol>';
			echo '<li>' . wp_kses_post( sprintf(
				/* translators: %s: link to Google Cloud service accounts */
				__( 'In %s, in the project of your Google app, create a service account, then « Keys » → « Add key » → JSON.', 'dazont-ecom' ),
				'<a href="https://console.cloud.google.com/iam-admin/serviceaccounts" target="_blank" rel="noopener">' . esc_html__( 'Google Cloud → Service accounts', 'dazont-ecom' ) . ' ↗</a>'
			) ) . '</li>';
			echo '<li>' . esc_html__( 'In that project, enable the Merchant API and the Google Ads API (APIs & Services → Library).', 'dazont-ecom' ) . '</li>';
			echo '<li>' . ( '' !== $where
				? wp_kses_post( sprintf(
					/* translators: %s: link to the Merchant Center settings */
					__( 'Paste the whole JSON file under %s → « Advanced: service account ».', 'dazont-ecom' ),
					'<a href="' . esc_url( $where ) . '">' . esc_html( DZE_Screens::name( 'marketing', 'gmc' ) ) . '</a>'
				) )
				: esc_html__( 'Switch the Google Merchant Center module on, and paste the JSON file under its « Advanced: service account ».', 'dazont-ecom' ) ) . '</li>';
			echo '<li>' . esc_html__( 'Add the service account\'s address as a user of each Merchant Center account and of Google Ads: it is shown here once the key is in.', 'dazont-ecom' ) . '</li></ol>';
			if ( ! $access['ok'] && '' !== $access['said'] ) {
				echo '<p class="dze-ads-warn">' . esc_html( $access['said'] ) . '</p>';
			}
		}
		echo '</div>';

		// 2. THE GOOGLE ADS ACCOUNT, READ THROUGH ITS API.
		echo '<h2>' . esc_html__( 'Google Ads account', 'dazont-ecom' ) . '</h2><div class="dze-ads-steps">';
		if ( '' !== $api['customer'] ) {
			echo '<p><strong>' . esc_html( ( '' !== $api['name'] ? $api['name'] . ' · ' : '' ) . self::account_id( $api['customer'] ) ) . '</strong>'
				. ( '' !== $api['login'] ? ' <span class="dze-ads-sub">' . esc_html( sprintf(
					/* translators: %s: a manager account id */
					__( 'through the manager account %s', 'dazont-ecom' ),
					self::account_id( $api['login'] )
				) ) . '</span>' : '' ) . '</p>';
			if ( $api['read_at'] ) {
				/* translators: %s: how long ago */
				echo '<p>' . esc_html( sprintf( __( 'Last read %s ago, every morning from then on.', 'dazont-ecom' ), human_time_diff( $api['read_at'] ) ) ) . '</p>';
			}
			if ( '' !== $api['error'] ) {
				echo '<p class="dze-ads-warn"><strong>' . esc_html__( 'Google Ads refused the last reading:', 'dazont-ecom' ) . '</strong> ' . esc_html( $api['error'] ) . '</p>';
			}
		}
		echo '<p><button type="button" class="button dze-ads-do" data-action="dze_ads_find" data-into="dze-ads-found"' . disabled( '' === $access['email'], true, false ) . '>' . esc_html__( 'Find the Google Ads accounts I can read', 'dazont-ecom' ) . '</button>';
		if ( '' !== $api['customer'] ) {
			echo ' <button type="button" class="button button-primary dze-ads-do" data-action="dze_ads_fetch">' . esc_html__( 'Read now', 'dazont-ecom' ) . '</button>';
		}
		echo '<span class="dze-ads-said" aria-live="polite"></span></p>';
		echo '<div id="dze-ads-found" hidden></div>';
		echo '<details class="dze-ads-sub"><summary>' . esc_html__( 'Type the account id instead', 'dazont-ecom' ) . '</summary><p>'
			. '<label>' . esc_html__( 'Google Ads account id', 'dazont-ecom' ) . ' <input type="text" id="dze-ads-cid" value="' . esc_attr( $api['customer'] ) . '" placeholder="123-456-7890" class="regular-text" /></label></p><p>'
			. '<label>' . esc_html__( 'Manager account id, when the access comes through one', 'dazont-ecom' ) . ' <input type="text" id="dze-ads-login" value="' . esc_attr( $api['login'] ) . '" class="regular-text" /></label></p><p>'
			. '<button type="button" class="button dze-ads-do" data-action="dze_ads_account" data-typed="1">' . esc_html__( 'Use this account', 'dazont-ecom' ) . '</button><span class="dze-ads-said" aria-live="polite"></span></p></details>';
		echo '</div>';

		// 3. THE SCRIPT, while Google has not granted the API to the project.
		echo '<details class="dze-ads-explain"' . ( '' === $api['customer'] && $accounts ? ' open' : '' ) . '><summary>' . esc_html__( 'Without access to the Google Ads API yet: the script', 'dazont-ecom' ) . '</summary><div class="dze-ads-steps">';
		echo '<p>' . esc_html__( 'While Google keeps the project at « Test » access, a short script pasted into Google Ads sends the same figures every day. It only reads: it changes nothing in the account.', 'dazont-ecom' ) . '</p><ol>';
		echo '<li>' . wp_kses_post( sprintf(
			/* translators: %s: link to Google Ads scripts */
			__( 'In Google Ads, open %s and add a new script (the « + » button).', 'dazont-ecom' ),
			'<a href="https://ads.google.com/aw/bulk/scripts" target="_blank" rel="noopener">' . esc_html__( 'Tools → Bulk actions → Scripts', 'dazont-ecom' ) . ' ↗</a>'
		) ) . '</li>';
		echo '<li>' . esc_html__( 'Replace everything in the editor with the script below.', 'dazont-ecom' ) . '</li>';
		echo '<li>' . esc_html__( 'Press « Authorise », then « Run » once: its first report reaches this page within a minute.', 'dazont-ecom' ) . '</li>';
		echo '<li>' . esc_html__( 'Set its frequency to « Daily ». Once the API reads the account, delete the script.', 'dazont-ecom' ) . '</li></ol>';
		echo '<p><button type="button" class="button" id="dze-ads-copy">' . esc_html__( 'Copy the script', 'dazont-ecom' ) . '</button> <span class="dze-ads-said" id="dze-ads-copied" aria-live="polite"></span></p>';
		echo '<textarea id="dze-ads-script" class="large-text code" rows="12" readonly="readonly">' . esc_textarea( DZE_Ads::script() ) . '</textarea>';
		echo '<p class="dze-ads-sub">' . esc_html__( 'The shop accepts only reports signed with the key written in the script.', 'dazont-ecom' ) . ' <button type="button" class="button-link" id="dze-ads-newkey">' . esc_html__( 'Make a new key', 'dazont-ecom' ) . '</button></p>';
		echo '</div></details>';

		// 4. WHAT HAS COME IN.
		echo '<h2>' . esc_html__( 'Figures received', 'dazont-ecom' ) . '</h2>';
		if ( ! $accounts ) {
			echo '<p class="dze-ads-empty">' . esc_html__( 'None yet.', 'dazont-ecom' ) . '</p>';
			return;
		}
		echo '<div class="dze-ads-frame"><table class="widefat striped dze-ads-table"><thead><tr><th>' . esc_html__( 'Google Ads account', 'dazont-ecom' ) . '</th><th>' . esc_html__( 'Last received', 'dazont-ecom' ) . '</th><th>' . esc_html__( 'Up to', 'dazont-ecom' ) . '</th><th class="num">' . esc_html__( 'Rows', 'dazont-ecom' ) . '</th><th>' . esc_html__( 'Currency', 'dazont-ecom' ) . '</th></tr></thead><tbody>';
		foreach ( $accounts as $id => $a ) {
			$late = time() - (int) $a['received'] > 2 * DAY_IN_SECONDS;
			echo '<tr><td>' . esc_html( ( '' !== (string) $a['name'] ? $a['name'] . ' · ' : '' ) . self::account_id( (string) $id ) ) . '</td>'
				. '<td>' . esc_html( sprintf(
					/* translators: %s: how long ago */
					__( '%s ago', 'dazont-ecom' ),
					human_time_diff( (int) $a['received'] )
				) ) . ( $late ? ' <span class="dze-ads-chip is-over">' . esc_html__( 'Late', 'dazont-ecom' ) . '</span>' : '' ) . '</td>'
				. '<td>' . esc_html( (string) $a['until'] ) . '</td>'
				. '<td class="num">' . esc_html( number_format_i18n( (int) $a['rows'] ) ) . '</td>'
				. '<td>' . esc_html( (string) $a['currency'] ) . '</td></tr>';
		}
		echo '</tbody></table></div>';
	}
	private static function account_id( string $id ): string {
		return 10 === strlen( $id ) ? substr( $id, 0, 3 ) . '-' . substr( $id, 3, 3 ) . '-' . substr( $id, 6 ) : $id;
	}

	// =========================================================================
	// Actions
	// =========================================================================

	private static function guard(): void {
		check_ajax_referer( DZE_Ads::NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'dazont-ecom' ) ], 403 );
		}
	}

	/** Takes a product or category out of the ads, or puts it back once its page was opened. */
	public static function ajax_hold(): void {
		self::guard();
		$kind = 'category' === ( $_POST['kind'] ?? '' ) ? 'category' : 'product'; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() checked it.
		$id   = absint( $_POST['id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$on   = ! empty( $_POST['on'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! $id ) {
			wp_send_json_error( [ 'message' => __( 'Nothing to change.', 'dazont-ecom' ) ] );
		}
		if ( $on ) {
			DZE_Ads::hold( $kind, $id, 'hand', (float) ( $_POST['spent'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			wp_send_json_success( [ 'message' => __( 'In quarantine: out of the ads until its page has been looked at again.', 'dazont-ecom' ) ] );
		}
		if ( ! DZE_Ads::release( $kind, $id ) ) {
			wp_send_json_error( [ 'message' => __( 'Open its page first, then put it back.', 'dazont-ecom' ) ] );
		}
		wp_send_json_success( [ 'message' => __( 'Back in the ads.', 'dazont-ecom' ) ] );
	}

	/** Records that the page was opened from the quarantine. */
	public static function ajax_opened(): void {
		self::guard();
		$kind = 'category' === ( $_POST['kind'] ?? '' ) ? 'category' : 'product'; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() checked it.
		$ok   = DZE_Ads::opened( $kind, absint( $_POST['id'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$ok ? wp_send_json_success( [ 'message' => __( 'Page looked at.', 'dazont-ecom' ) ] ) : wp_send_json_error();
	}

	public static function ajax_new_key(): void {
		self::guard();
		DZE_Ads::new_secret();
		wp_send_json_success( [ 'script' => DZE_Ads::script() ] );
	}

	/** The Google Ads accounts the service account can read, offered as a list to pick from. */
	public static function ajax_find(): void {
		self::guard();
		try {
			$list = DZE_Ads::accessible();
		} catch ( \Throwable $e ) {
			wp_send_json_error( [ 'message' => $e->getMessage() ] );
		}
		if ( ! $list ) {
			wp_send_json_error( [ 'message' => __( 'The service account cannot read any Google Ads account yet. Add its address under Google Ads → Admin → Access and security.', 'dazont-ecom' ) ] );
		}
		$html = '<p>' . esc_html__( 'Pick the account whose campaigns show your products:', 'dazont-ecom' ) . '</p><ul class="dze-ads-pick">';
		foreach ( $list as $c ) {
			$html .= '<li><button type="button" class="button dze-ads-do" data-action="dze_ads_account" data-cid="' . esc_attr( $c['id'] ) . '" data-name="' . esc_attr( $c['name'] ) . '"' . disabled( $c['manager'], true, false ) . '>'
				. esc_html( ( '' !== $c['name'] ? $c['name'] . ' · ' : '' ) . self::account_id( $c['id'] ) . ( '' !== $c['currency'] ? ' · ' . $c['currency'] : '' ) ) . '</button>'
				. ( $c['manager'] ? ' <span class="dze-ads-sub">' . esc_html__( 'A manager account: type the id of the account under it, with this one as manager.', 'dazont-ecom' ) . '</span>' : '' )
				. '<span class="dze-ads-said" aria-live="polite"></span></li>';
		}
		wp_send_json_success( [ 'html' => $html . '</ul>' ] );
	}

	/** The account to read, picked or typed. */
	public static function ajax_account(): void {
		self::guard();
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- guard() checked it.
		$cid   = preg_replace( '/[^0-9]/', '', (string) wp_unslash( $_POST['cid'] ?? '' ) );
		$login = preg_replace( '/[^0-9]/', '', (string) wp_unslash( $_POST['login'] ?? '' ) );
		$name  = sanitize_text_field( (string) wp_unslash( $_POST['name'] ?? '' ) );
		// phpcs:enable
		if ( 10 !== strlen( $cid ) ) {
			wp_send_json_error( [ 'message' => __( 'A Google Ads account id has ten digits, as in 123-456-7890.', 'dazont-ecom' ) ] );
		}
		DZE_Ads::save_api( [ 'customer' => $cid, 'login' => 10 === strlen( $login ) ? $login : '', 'name' => $name, 'error' => '' ] );
		wp_send_json_success( [ 'message' => __( 'Saved. Press « Read now » to read it.', 'dazont-ecom' ), 'reload' => 1 ] );
	}

	/** Reads the chosen account now, and says what came in. */
	public static function ajax_fetch(): void {
		self::guard();
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 180 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- the host may refuse.
		}
		try {
			$n = DZE_Ads::fetch_report();
		} catch ( \Throwable $e ) {
			wp_send_json_error( [ 'message' => $e->getMessage() ] );
		}
		/* translators: %s: how many rows */
		wp_send_json_success( [ 'message' => sprintf( __( 'Read: %s rows of figures. The other tabs show them now.', 'dazont-ecom' ), number_format_i18n( $n ) ) ] );
	}

	/** One Merchant Center account switched to the Dazont listing. */
	public static function ajax_gmc_switch(): void {
		self::guard();
		$lang = sanitize_key( (string) wp_unslash( $_POST['lang'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() checked it.
		try {
			DZE_Gmc_Feed::switch_account( $lang );
		} catch ( \Throwable $e ) {
			wp_send_json_error( [ 'message' => $e->getMessage() ] );
		}
		wp_send_json_success( [ 'message' => __( 'Switched. The listing is being sent in the background; this page shows where it stands.', 'dazont-ecom' ), 'reload' => 1 ] );
	}

	/** Everything compared and sent again for one account. */
	public static function ajax_gmc_start(): void {
		self::guard();
		$lang = sanitize_key( (string) wp_unslash( $_POST['lang'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() checked it.
		DZE_Gmc_Feed::start( $lang );
		wp_send_json_success( [ 'message' => __( 'Started: every offer is compared, and what changed is sent.', 'dazont-ecom' ), 'reload' => 1 ] );
	}

	/** What Google says about the products of one account. */
	public static function ajax_gmc_issues(): void {
		self::guard();
		$lang = sanitize_key( (string) wp_unslash( $_POST['lang'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() checked it.
		try {
			$r = DZE_Gmc_Feed::issues( $lang, true );
		} catch ( \Throwable $e ) {
			wp_send_json_error( [ 'message' => $e->getMessage() ] );
		}
		$html = '<p>' . esc_html( sprintf(
			/* translators: 1: products read, 2: products with a remark */
			__( '%1$s products read in Merchant Center, %2$s with a remark from Google.', 'dazont-ecom' ),
			number_format_i18n( (int) $r['products'] ),
			number_format_i18n( (int) $r['with'] )
		) ) . '</p>';
		if ( $r['issues'] ) {
			$html .= '<ul class="dze-ads-issues">';
			foreach ( $r['issues'] as $i ) {
				$html .= '<li><strong>' . esc_html( number_format_i18n( (int) $i['n'] ) ) . '</strong> × ' . esc_html( $i['said'] ) . ( '' !== $i['severity'] ? ' <span class="dze-ads-sub">' . esc_html( strtolower( str_replace( '_', ' ', $i['severity'] ) ) ) . '</span>' : '' ) . '</li>';
			}
			$html .= '</ul>';
		}
		wp_send_json_success( [ 'html' => $html ] );
	}
}
