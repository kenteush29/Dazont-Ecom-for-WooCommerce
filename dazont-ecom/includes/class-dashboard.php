<?php
defined( 'ABSPATH' ) || exit;

/**
 * Dazont Ecom dashboard.
 *
 * A home screen for the plugin (first submenu entry) built from four blocks:
 *   - Top out-of-stock products (best sellers waiting for restock).
 *   - AI API consumption per provider, per month.
 *   - The planned marketing calendar (current + upcoming events).
 *   - Top product categories of the last 3 months, with their last "novelty
 *     search" date — a direct prompt to go source new products where money
 *     already flows.
 *
 * The same blocks are also registered as WordPress dashboard widgets, so the
 * WP home screen finally says something useful about the shop.
 */
final class DZE_Dashboard {

	public const MENU_SLUG = 'dazont-ecom-dashboard';

	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		if ( ! is_admin() ) {
			return;
		}
		// The menu's order is the catalogue's business (DZE_Screens::
		// reorder_menu), not this page's: the hack that moved this entry to the
		// top by hand is gone with it.
		add_action( 'admin_menu',         [ $this, 'register_menu' ] );
		// Nothing is added to the WordPress home screen: those widgets query the
		// shop on a page nobody opens for them, and the plugin has its own
		// Dashboard for exactly the same blocks.
	}

	public function register_menu(): void {
		add_submenu_page(
			DZE_Restock::MENU_SLUG,
			DZE_Screens::label( 'dashboard' ),
			DZE_Screens::label( 'dashboard' ),
			'manage_woocommerce',
			self::MENU_SLUG,
			[ $this, 'render_page' ]
		);
	}

	// =========================================================================
	// Page
	// =========================================================================

	public function render_page(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'dazont-ecom' ) );
		}
		// THE PAGE IS CALLED WHAT THE MENU CALLS IT. "Dazont Ecom — Dashboard"
		// over a menu entry reading "Dashboard" is the same screen named twice.
		echo '<div class="wrap"><h1>' . esc_html( DZE_Screens::label( 'dashboard' ) ) . '</h1>';
		echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(440px,1fr));gap:16px;margin-top:12px;">';
		$blocks = [
			// WHAT WAITS FOR A PERSON COMES FIRST. The home screen said what
			// sold and what was spent and nothing about the three texts waiting
			// for a yes, the translation nobody had read or the connection that
			// had been down since Monday — the questions somebody opens the
			// plugin to answer.
			__( 'The shop at a glance', 'dazont-ecom' )             => 'block_glance',
			__( 'Marketing calendar', 'dazont-ecom' )               => 'block_events',
			__( 'What it cost', 'dazont-ecom' )                     => 'block_ai_usage',
		];
		// WHAT WAITS FOR YOU COMES FIRST, AND ACROSS THE WHOLE WIDTH.
		//
		// "Style de waiting for you, pas très agréable. J'ai fait -50% de
		// taille d'écran." Five cards of equal weight, holding two lines on one
		// side and two screens of tables on the other: the block the page
		// exists for was a narrow column beside a spend report. It is the first
		// thing, full width, and the rest is a row of short cards under it.
		echo '<div style="background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:16px 18px;margin-bottom:16px;">';
		echo '<h2 style="margin:0 0 10px;font-size:14px;">' . esc_html__( 'Waiting for you', 'dazont-ecom' ) . '</h2>';
		$this->block_waiting();
		echo '</div>';
		echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:16px;">';
		foreach ( $blocks as $title => $method ) {
			echo '<div style="background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:16px 18px;">';
			echo '<h2 style="margin:0 0 10px;font-size:14px;">' . esc_html( $title ) . '</h2>';
			$this->$method();
			echo '</div>';
		}
		echo '</div></div>';
	}

	// =========================================================================
	// Blocks
	// =========================================================================

	/**
	 * Everything that waits for a decision or a hand, one line each, read
	 * from whoever owns that answer — never a store of this page's own.
	 *
	 * Each line is a figure and the screen it waits on; a line whose figure is
	 * nought is not printed, and a module switched off contributes nothing.
	 * The catalogue names the screens and gives their addresses, so a renamed
	 * page renames its line here with it.
	 *
	 * @return array<int,array{n:int,said:string,url:string,to:string}>
	 */
	public static function waiting(): array {
		$on  = static fn( string $id ): bool => ! class_exists( 'DZE_Modules' ) || DZE_Modules::enabled( $id );
		$out = [];
		// ONE LINE PER PLACE THE WORK IS ACTUALLY DONE.
		//
		// This used to add the linking passes, the category descriptions and
		// the photographs into one figure pointing at one list — so the line
		// said "14 pieces of content wait for your yes or no" and the screen
		// it opened held four unrelated kinds of work. A count you cannot act
		// on in one place is a count that sends you looking.
		//
		// Each kind of work now has the screen it belongs to, so the inbox
		// names the screen and hands over the part that is its own.
		$mesh = class_exists( 'DZE_Mesh' ) ? DZE_Mesh::KINDS : [];
		if ( class_exists( 'DZE_Queue' ) && $on( 'queue' ) && $mesh && $on( 'mesh' ) ) {
			$n = (int) ( DZE_Queue::counts_for( $mesh )['review'] ?? 0 );
			if ( $n > 0 ) {
				$out[] = [
					'n'    => $n,
					/* translators: %s: how many */
					'said' => sprintf( _n( '%s page has links waiting for your yes or no', '%s pages have links waiting for your yes or no', $n, 'dazont-ecom' ), number_format_i18n( $n ) ),
					// Into the ONE list, pre-filtered to this work: a second list
					// with its own count is a count that can disagree.
					'url'  => DZE_Queue::review_url( $mesh ),
					'to'   => DZE_Screens::label( 'linking' ),
				];
			}
		}
		// Everything else waiting for a decision: the category descriptions,
		// the photographs, and the products holding something generated.
		// Taken as a DIFFERENCE so a kind nobody thought of still turns up.
		$n = 0;
		if ( class_exists( 'DZE_Queue' ) && $on( 'queue' ) ) {
			$rest = array_values( array_diff( array_keys( DZE_Queue::kinds() ), $mesh ) );
			$n   += (int) ( DZE_Queue::counts_for( $rest )['review'] ?? 0 );
		}
		if ( class_exists( 'DZE_Content' ) && $on( 'content' ) ) {
			$n += (int) DZE_Content::pending_count();
		}
		if ( $n > 0 ) {
			$out[] = [
				'n'    => $n,
				/* translators: %s: how many */
				'said' => sprintf( _n( '%s piece of content waits for your yes or no', '%s pieces of content wait for your yes or no', $n, 'dazont-ecom' ), number_format_i18n( $n ) ),
				// LES DEUX MOITIÉS DE CETTE CARTE VIVENT SUR LE BANC : les
				// descriptions de catégorie sur son onglet Categories, les
				// produits sur son onglet Products. Depuis que l'écran central
				// a disparu, c'est là qu'on les relit — et c'est une seule
				// destination, donc une seule carte.
				'url'  => DZE_Screens::url( 'bulk' ),
				'to'   => DZE_Screens::label( 'bulk' ),
			];
		}
		if ( class_exists( 'DZE_Translate' ) && $on( 'translate' ) ) {
			$n = (int) DZE_Translate::review_count();
			if ( $n > 0 ) {
				$out[] = [
					'n'    => $n,
					/* translators: %s: how many */
					'said' => sprintf( _n( '%s translation waits to be read', '%s translations wait to be read', $n, 'dazont-ecom' ), number_format_i18n( $n ) ),
					'url'  => DZE_Screens::url( 'translations', 'review' ),
					'to'   => DZE_Screens::label( 'translations' ),
				];
			}
		}
		if ( class_exists( 'DZE_Marketing_Ai' ) && $on( 'marketing_ai' ) && $on( 'discounts' ) ) {
			$n = (int) DZE_Marketing_Ai::pending_count();
			if ( $n > 0 ) {
				$out[] = [
					'n'    => $n,
					/* translators: %s: how many */
					'said' => sprintf( _n( '%s promotion suggested by the calendar waits for an answer', '%s promotions suggested by the calendar wait for an answer', $n, 'dazont-ecom' ), number_format_i18n( $n ) ),
					'url'  => DZE_Screens::url( 'marketing', 'events' ),
					'to'   => DZE_Screens::label( 'marketing' ),
				];
			}
		}
		// A CONNECTION THAT IS DOWN, from the health module's LAST reading —
		// never asked again here, since that reaches four providers over HTTP.
		if ( class_exists( 'DZE_Health' ) && $on( 'health' ) ) {
			$down = [];
			foreach ( (array) ( DZE_Health::state()['checks'] ?? [] ) as $id => $one ) {
				if ( 'down' === ( $one['state'] ?? '' ) ) {
					$down[] = (string) ( DZE_Health::labels()[ $id ] ?? $id );
				}
			}
			if ( $down ) {
				$out[] = [
					'n'    => count( $down ),
					/* translators: %s: the connections that are down, comma-separated */
					'said' => sprintf( _n( '%s is not answering', '%s are not answering', count( $down ), 'dazont-ecom' ), implode( ', ', $down ) ),
					'url'  => DZE_Screens::url( 'logs', 'health' ),
					'to'   => DZE_Screens::label( 'logs' ),
				];
			}
		}
		// WHAT IS NOT SET UP, counted the way the Setup screen counts it: only
		// what an enabled module needs, never a suggestion.
		if ( class_exists( 'DZE_Setup' ) ) {
			$score = DZE_Setup::score();
			$n     = count( (array) ( $score['todo'] ?? [] ) );
			if ( $n > 0 ) {
				$out[] = [
					'n'    => $n,
					/* translators: 1: how many, 2: the first of them */
					'said' => sprintf( _n( '%1$s thing is still to set up, starting with %2$s', '%1$s things are still to set up, starting with %2$s', $n, 'dazont-ecom' ), number_format_i18n( $n ), (string) $score['todo'][0] ),
					'url'  => DZE_Screens::url( 'setup' ),
					'to'   => DZE_Screens::label( 'setup' ),
				];
			}
		}
		return $out;
	}

	/**
	 * THE SHOP IN THREE SENTENCES, not in three tables.
	 *
	 * "Il vaudrait mieux compacter. Juste une ligne suffit pour dire combien de
	 * produits sont à resourcer." A top-eight table of out-of-stock lines on
	 * the home screen is the Restock screen, drawn a second time, worse and
	 * smaller — and the same for the top categories and the Sourcing
	 * Assistant. A figure and the way to the screen that acts on it is the
	 * whole of what a home screen owes them.
	 */
	public function block_glance(): void {
		$said = [];
		if ( class_exists( 'DZE_Restock' ) && ( ! class_exists( 'DZE_Modules' ) || DZE_Modules::enabled( 'restock' ) ) ) {
			$n = count( (array) DZE_Restock::get_line_index() );
			$said[] = [
				$n
					/* translators: %s: how many product lines */
					? sprintf( _n( '%s product line is short of stock', '%s product lines are short of stock', $n, 'dazont-ecom' ), number_format_i18n( $n ) )
					: __( 'Nothing is out of stock. 👌', 'dazont-ecom' ),
				$n ? DZE_Screens::url( 'restock' ) : '',
				DZE_Screens::label( 'restock' ),
			];
		}
		// LE MAILLAGE EXTERNE A SA LIGNE ICI AUSSI : c est un travail qu on ne
		// pense a faire que si quelque chose le rappelle, et l accueil est
		// l endroit ou une boutique regarde ce qui vaut la peine aujourd hui.
		if ( class_exists( 'DZE_Netlinking' ) && ( ! class_exists( 'DZE_Modules' ) || DZE_Modules::enabled( 'netlinking' ) ) ) {
			$nl = count( (array) ( DZE_Netlinking::data()['rows'] ?? [] ) );
			// RIEN A DIRE QUAND RIEN N A ETE LU : une ligne « 0 page » sur un
			// module jamais connecte se lit comme un echec, pas comme une absence.
			if ( $nl > 0 ) {
				$said[] = [
					sprintf(
						/* translators: %s: how many pages */
						_n( '%s page would gain from a link pointing at it from another site', '%s pages would gain from a link pointing at them from another site', $nl, 'dazont-ecom' ),
						number_format_i18n( $nl )
					),
					DZE_Screens::url( 'netlinking' ),
					DZE_Screens::label( 'netlinking' ),
				];
			}
		}
		$cats = $this->top_categories();
		if ( $cats ) {
			$cold = 0;
			foreach ( $cats as $r ) {
				if ( ! (int) get_term_meta( (int) $r['id'], DZE_Explorer::META_RESEARCHED, true ) ) {
					$cold++;
				}
			}
			$said[] = [
				$cold
					/* translators: %s: how many categories */
					? sprintf( _n( '%s of your best-selling categories has never been searched for novelties', '%s of your best-selling categories have never been searched for novelties', $cold, 'dazont-ecom' ), number_format_i18n( $cold ) )
					: __( 'Every best-selling category has been searched at least once.', 'dazont-ecom' ),
				DZE_Screens::url( 'sourcing' ),
				DZE_Screens::label( 'sourcing' ),
			];
		}
		if ( ! $said ) {
			echo '<p class="description" style="margin:0;">' . esc_html__( 'Nothing to report.', 'dazont-ecom' ) . '</p>';
			return;
		}
		echo '<ul style="margin:0;list-style:none;">';
		foreach ( $said as $one ) {
			echo '<li style="margin:0 0 6px;">' . esc_html( (string) $one[0] );
			if ( '' !== (string) $one[1] ) {
				echo ' — <a href="' . esc_url( (string) $one[1] ) . '">' . esc_html( (string) $one[2] ) . ' →</a>';
			}
			echo '</li>';
		}
		echo '</ul>';
	}
	/** The block: one line per thing waiting, or one line saying nothing is. */
	public function block_waiting(): void {
		$lines = self::waiting();
		if ( ! $lines ) {
			echo '<p class="description" style="margin:0;">' . esc_html__( 'Nothing is waiting for you.', 'dazont-ecom' ) . '</p>';
			return;
		}
		echo '<ul class="dze-dash-waiting" style="margin:0;list-style:none;">';
		foreach ( $lines as $l ) {
			echo '<li style="margin:0 0 6px;">'
				. esc_html( $l['said'] ) . ' — '
				. '<a href="' . esc_url( $l['url'] ) . '">' . esc_html( $l['to'] ) . ' →</a>'
				. '</li>';
		}
		echo '</ul>';
	}

	/** Best-selling categories of the last 3 months + their last novelty search. */
	public function block_top_categories(): void {
		$rows = $this->top_categories();
		if ( empty( $rows ) ) {
			echo '<p class="description">' . esc_html__( 'No sales recorded in the last 3 months (or WooCommerce Analytics is still syncing).', 'dazont-ecom' ) . '</p>';
			return;
		}
		echo '<p class="description" style="margin-top:0;">' . esc_html__( 'Where the money flowed recently. Categories not searched for a while are prime candidates for your next sourcing session.', 'dazont-ecom' ) . '</p>';
		echo '<table class="widefat striped" style="border:0;"><thead><tr>';
		echo '<th>' . esc_html__( 'Category', 'dazont-ecom' ) . '</th>';
		echo '<th style="text-align:right;">' . esc_html__( 'Units', 'dazont-ecom' ) . '</th>';
		echo '<th style="text-align:right;">' . esc_html__( 'Products', 'dazont-ecom' ) . '</th>';
		echo '<th>' . esc_html__( 'Last search', 'dazont-ecom' ) . '</th>';
		echo '</tr></thead><tbody>';
		$explorer_url = add_query_arg( [ 'page' => DZE_Explorer::MENU_SLUG ], admin_url( 'admin.php' ) );
		foreach ( $rows as $r ) {
			$res = (int) get_term_meta( $r['id'], DZE_Explorer::META_RESEARCHED, true );
			echo '<tr>';
			echo '<td><a href="' . esc_url( $explorer_url ) . '">' . esc_html( $r['name'] ) . '</a></td>';
			echo '<td style="text-align:right;">' . esc_html( number_format_i18n( $r['qty'] ) ) . '</td>';
			echo '<td style="text-align:right;">' . esc_html( number_format_i18n( $r['count'] ) ) . '</td>';
			echo '<td>' . ( $res
				/* translators: %s: human time difference */
				? esc_html( sprintf( __( '%s ago', 'dazont-ecom' ), human_time_diff( $res ) ) )
				: '<span style="color:#b32d2e;">' . esc_html__( 'never', 'dazont-ecom' ) . '</span>' ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
		echo '<p style="margin-bottom:0;"><a href="' . esc_url( $explorer_url ) . '">' . esc_html__( 'Open the Sourcing Assistant →', 'dazont-ecom' ) . '</a></p>';
	}

	/** Out-of-stock products ranked by their cached all-time sales (Restock data). */
	public function block_out_of_stock(): void {
		if ( ! class_exists( 'DZE_Restock' ) || ( class_exists( 'DZE_Modules' ) && ! DZE_Modules::enabled( 'restock' ) ) ) {
			return;
		}
		$lines = DZE_Restock::get_line_index();
		$rows  = [];
		foreach ( $lines as $line ) {
			$rows[] = [
				'id'    => (int) $line['id'],
				'sales' => DZE_Restock::get_line_sales( (int) $line['id'] ),
				'oos'   => is_countable( $line['oos'] ?? null ) ? count( $line['oos'] ) : 0,
			];
		}
		if ( empty( $rows ) ) {
			echo '<p class="description">' . esc_html__( 'Nothing is out of stock. 👌', 'dazont-ecom' ) . '</p>';
			return;
		}
		usort( $rows, static fn( $a, $b ) => $b['sales'] <=> $a['sales'] );
		$rows = array_slice( $rows, 0, 8 );
		echo '<table class="widefat striped" style="border:0;"><thead><tr>';
		echo '<th>' . esc_html__( 'Product', 'dazont-ecom' ) . '</th>';
		echo '<th style="text-align:right;">' . esc_html__( 'Total sales', 'dazont-ecom' ) . '</th>';
		echo '<th style="text-align:right;">' . esc_html__( 'OOS variations', 'dazont-ecom' ) . '</th>';
		echo '</tr></thead><tbody>';
		foreach ( $rows as $r ) {
			$edit = get_edit_post_link( $r['id'] );
			$name = get_the_title( $r['id'] ) ?: ( '#' . $r['id'] );
			echo '<tr>';
			echo '<td>' . ( $edit ? '<a href="' . esc_url( $edit ) . '">' . esc_html( $name ) . '</a>' : esc_html( $name ) ) . '</td>';
			echo '<td style="text-align:right;">' . esc_html( number_format_i18n( $r['sales'] ) ) . '</td>';
			echo '<td style="text-align:right;">' . esc_html( number_format_i18n( $r['oos'] ) ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
		echo '<p style="margin-bottom:0;"><a href="' . esc_url( add_query_arg( [ 'page' => DZE_Restock::MENU_SLUG ], admin_url( 'admin.php' ) ) ) . '">' . esc_html__( 'Open Restock →', 'dazont-ecom' ) . '</a></p>';
	}

	/** Current + upcoming scheduled sales from the Marketing Events calendar. */
	public function block_events(): void {
		if ( ! class_exists( 'DZE_Discounts' ) || ( class_exists( 'DZE_Modules' ) && ! DZE_Modules::enabled( 'discounts' ) ) ) {
			return;
		}
		$today = current_time( 'Y-m-d' );
		$rows  = [];
		foreach ( DZE_Discounts::get_rules() as $rule ) {
			if ( ( $rule['type'] ?? '' ) !== 'sale' ) {
				continue;
			}
			$end = (string) ( $rule['end'] ?? '' );
			if ( $end !== '' && $end < $today ) {
				continue; // finished.
			}
			$rows[] = [
				'title'   => (string) ( $rule['title'] ?? '' ),
				'percent' => (float) ( $rule['percent'] ?? 0 ),
				'start'   => (string) ( $rule['start'] ?? '' ),
				'end'     => $end,
				'enabled' => ! empty( $rule['enabled'] ),
			];
		}
		if ( empty( $rows ) ) {
			echo '<p class="description">' . esc_html__( 'No current or upcoming marketing events.', 'dazont-ecom' ) . '</p>';
		} else {
			usort( $rows, static fn( $a, $b ) => strcmp( $a['start'], $b['start'] ) );
			$rows = array_slice( $rows, 0, 8 );
			$fmt  = get_option( 'date_format' );
			echo '<table class="widefat striped" style="border:0;"><thead><tr>';
			echo '<th>' . esc_html__( 'Event', 'dazont-ecom' ) . '</th>';
			echo '<th style="text-align:right;">%</th>';
			echo '<th>' . esc_html__( 'Dates', 'dazont-ecom' ) . '</th>';
			echo '<th>' . esc_html__( 'Status', 'dazont-ecom' ) . '</th>';
			echo '</tr></thead><tbody>';
			foreach ( $rows as $r ) {
				$dates = trim(
					( $r['start'] ? date_i18n( $fmt, strtotime( $r['start'] ) ) : '…' )
					. ' → '
					. ( $r['end'] ? date_i18n( $fmt, strtotime( $r['end'] ) ) : '…' )
				);
				echo '<tr>';
				echo '<td>' . esc_html( $r['title'] !== '' ? $r['title'] : __( '(untitled)', 'dazont-ecom' ) ) . '</td>';
				echo '<td style="text-align:right;">' . esc_html( rtrim( rtrim( number_format_i18n( $r['percent'], 1 ), '0' ), ',.' ) ) . '%</td>';
				echo '<td>' . esc_html( $dates ) . '</td>';
				echo '<td>' . ( $r['enabled']
					? '<span style="color:#0a7040;">' . esc_html__( 'enabled', 'dazont-ecom' ) . '</span>'
					: '<span style="color:#996800;">' . esc_html__( 'disabled', 'dazont-ecom' ) . '</span>' ) . '</td>';
				echo '</tr>';
			}
			echo '</tbody></table>';
		}
		// THE WAY OUT NAMES THE SCREEN AS THE MENU DOES — read from the
		// catalogue, so a renamed page renames this line with it.
		echo '<p style="margin-bottom:0;"><a href="' . esc_url( DZE_Screens::url( 'marketing', 'events' ) ) . '">'
			/* translators: %s: the Marketing screen, as the menu names it */
			. esc_html( sprintf( __( 'Open %s →', 'dazont-ecom' ), DZE_Screens::label( 'marketing' ) ) ) . '</a></p>';
	}

	public function block_ai_usage(): void {
		// THE FOUR FIGURES, NOT THE WHOLE REPORT. `render_graph()` prints the
		// cost per unit of work, the cost per model, a day-by-day chart and a
		// month-by-month one — two full screens on a page whose first block
		// holds two lines. That report is the Spend log, and it is one click
		// away, named.
		DZE_Ai_Usage::render_summary();
		// The whole account is a LOG, not a setting: this link sent the shop to
		// Settings for months after the spend moved to Dazont Ecom → Logs.
		echo '<p style="margin-bottom:0;"><a href="' . esc_url( DZE_Screens::url( 'logs', 'spend' ) ) . '">' . esc_html__( 'Open the Spend log →', 'dazont-ecom' ) . '</a></p>';
	}

	// =========================================================================
	// Data
	// =========================================================================

	/**
	 * Top categories by units sold over the last 3 months (direct product
	 * assignments), from WooCommerce Analytics. Cached 6 hours.
	 *
	 * @return array<int,array{id:int,name:string,qty:int,count:int}>
	 */
	private function top_categories(): array {
		$cached = get_transient( 'dze_dash_topcats' );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		global $wpdb;
		$table = $wpdb->prefix . 'wc_order_product_lookup';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return [];
		}
		$since = gmdate( 'Y-m-d', strtotime( '-3 months' ) );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT tt.term_id, SUM(l.product_qty) AS qty
				 FROM {$table} l
				 INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = l.product_id
				 INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
				 WHERE tt.taxonomy = 'product_cat' AND l.date_created >= %s
				 GROUP BY tt.term_id ORDER BY qty DESC LIMIT 10", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names only.
				$since
			),
			ARRAY_A
		);
		$out = [];
		foreach ( (array) $rows as $r ) {
			$term = get_term( (int) $r['term_id'], 'product_cat' );
			if ( ! $term instanceof WP_Term ) {
				continue;
			}
			$out[] = [
				'id'    => (int) $term->term_id,
				'name'  => $term->name,
				'qty'   => (int) $r['qty'],
				'count' => (int) $term->count,
			];
		}
		set_transient( 'dze_dash_topcats', $out, 6 * HOUR_IN_SECONDS );
		return $out;
	}
}
