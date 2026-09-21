<?php
/**
 * NETLINKING — QUELLES PAGES MERITENT UN LIEN, D APRES SEARCH CONSOLE.
 *
 * Le maillage interne s occupe des liens que ce site se donne a lui-meme.
 * Celui-ci s occupe de l autre moitie : les liens qui viennent du dehors. Il
 * n en obtient aucun et n en ecrit aucun — il dit OU les mettre, et avec quels
 * mots, ce qui est la seule partie qu une machine puisse faire honnetement.
 *
 * CE QUE SEARCH CONSOLE DONNE, ET CE QU ELLE NE DONNE PAS.
 *
 * L API expose Search Analytics, Sitemaps, Sites et URL Inspection. Elle
 * n expose AUCUN backlink : le rapport « Liens » existe a l ecran et nulle
 * part ailleurs. Un module qui pretendrait lister les liens entrants par API
 * mentirait. Celui-ci ne le pretend pas.
 *
 * Ce qu elle donne vaut mieux pour ce travail : pour chaque page, les
 * impressions, le taux de clic et la position moyenne. Une page a 4 000
 * impressions en position 14 est une page qui interesse du monde et que
 * personne ne voit — c est exactement celle qu un lien fait basculer. Et les
 * requetes qu elle travaille deja sont les ancres a viser : de la donnee, pas
 * une intuition.
 *
 * PASSIF. Il lit Google, il ne touche ni a la boutique ni au modele : aucun
 * appel paye, aucune ecriture, rien a relire. Il se rafraichit seul une fois
 * par jour et attend qu on le consulte.
 *
 * @package Dazont_Ecom
 */

defined( 'ABSPATH' ) || exit;

final class DZE_Netlinking {

	public const MENU_SLUG = 'dazont-ecom-netlinking';

	/** Le compte connecte : jeton de rafraichissement, adresse, propriete. */
	public const OPT_CONN = 'dze_nl_connection';

	/** Les reglages de la boutique : propriete choisie, fenetre, seuils. */
	public const OPT_SET = 'dze_nl_settings';

	/** Ce que la derniere lecture a trouve. */
	public const OPT_DATA = 'dze_nl_targets';

	public const HOOK  = 'dze_nl_refresh';
	private const NONCE = 'dze_nl';

	/** Lecture seule : ce module ne changera jamais rien chez Google. */
	private const SCOPE = 'https://www.googleapis.com/auth/webmasters.readonly';

	private const AUTH_URL  = 'https://accounts.google.com/o/oauth2/v2/auth';
	private const TOKEN_URL = 'https://oauth2.googleapis.com/token';
	private const API       = 'https://searchconsole.googleapis.com/webmasters/v3';

	/** Combien de pages on classe, et combien de requetes on garde par page. */
	private const MAX_PAGES   = 5000;
	private const MAX_ROWS    = 25000;
	private const KEEP        = 60;
	private const KEEP_ANCHOR = 6;

	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// LA LECTURE TOURNE EN CRON, ou rien n est un ecran d administration.
		add_action( self::HOOK, [ __CLASS__, 'refresh' ] );
		add_filter( 'cron_schedules', [ __CLASS__, 'cron_schedules' ] );
		if ( ! is_admin() ) {
			return;
		}
		add_action( 'admin_menu', [ __CLASS__, 'menu' ], 13 );
		add_action( 'admin_init', [ $this, 'schedule' ] );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'admin_post_dze_nl_oauth', [ $this, 'handle_oauth_callback' ] );
		add_action( 'admin_post_dze_nl_disconnect', [ $this, 'handle_disconnect' ] );
		add_action( 'wp_ajax_dze_nl_refresh', [ __CLASS__, 'ajax_refresh' ] );
	}

	/** Une fois par jour suffit : Search Console ne bouge pas plus vite. */
	public static function cron_schedules( $schedules ) {
		$schedules = is_array( $schedules ) ? $schedules : [];
		if ( ! isset( $schedules['dze_daily'] ) ) {
			$schedules['dze_daily'] = [ 'interval' => DAY_IN_SECONDS, 'display' => __( 'Once a day (Dazont)', 'dazont-ecom' ) ];
		}
		return $schedules;
	}

	public function schedule(): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'dze_daily', self::HOOK );
		}
	}

	// =========================================================================
	// Le compte Google
	// =========================================================================

	/**
	 * LE CLIENT OAuth EST CELUI DU MERCHANT CENTER, quand il est la.
	 *
	 * Faire creer une seconde application Google pour lire un second service du
	 * meme compte est une demarche de plus pour rien. Les identifiants sont
	 * partages ; l autorisation, elle, est propre a ce module — une portee de
	 * lecture seule, qui ne peut rien changer chez Google.
	 */
	public static function client(): array {
		$own = (array) ( self::settings()['oauth'] ?? [] );
		if ( ! empty( $own['client_id'] ) && ! empty( $own['client_secret'] ) ) {
			return $own;
		}
		return class_exists( 'DZE_Gmc' ) ? (array) DZE_Gmc::get_oauth() : [];
	}

	public static function settings(): array {
		$s = get_option( self::OPT_SET, [] );
		return is_array( $s ) ? $s : [];
	}

	public static function connection(): array {
		// Ecrit par une redirection et relu par une requete AJAX : les deux
		// peuvent tomber sur des processus differents avec un cache d objets
		// persistant en retard d un coup.
		wp_cache_delete( self::OPT_CONN, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		$c = get_option( self::OPT_CONN, [] );
		return is_array( $c ) ? $c : [];
	}

	public static function connected(): bool {
		$c = self::connection();
		return ! empty( $c['refresh_token'] ) && empty( $c['broken'] );
	}

	public function redirect_uri(): string {
		return admin_url( 'admin-post.php?action=dze_nl_oauth' );
	}

	public function authorize_url(): string {
		$o = self::client();
		return self::AUTH_URL . '?' . http_build_query( [
			'client_id'     => $o['client_id'] ?? '',
			'redirect_uri'  => $this->redirect_uri(),
			'response_type' => 'code',
			'scope'         => self::SCOPE,
			'access_type'   => 'offline',
			'prompt'        => 'consent',
			'state'         => wp_create_nonce( 'dze_nl_oauth' ),
		] );
	}

	public function handle_oauth_callback(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'dazont-ecom' ) );
		}
		$back  = self::page_url();
		$state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
		if ( ! wp_verify_nonce( $state, 'dze_nl_oauth' ) ) {
			$this->back( $back, __( 'Security check failed.', 'dazont-ecom' ) );
		}
		if ( ! empty( $_GET['error'] ) ) {
			$this->back( $back, sanitize_text_field( wp_unslash( $_GET['error'] ) ) );
		}
		$code = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
		$o    = self::client();
		if ( '' === $code || empty( $o['client_id'] ) || empty( $o['client_secret'] ) ) {
			$this->back( $back, __( 'Missing code or client credentials.', 'dazont-ecom' ) );
		}
		$res = wp_remote_post( self::TOKEN_URL, [
			'timeout' => 25,
			'body'    => [
				'code'          => $code,
				'client_id'     => $o['client_id'],
				'client_secret' => $o['client_secret'],
				'redirect_uri'  => $this->redirect_uri(),
				'grant_type'    => 'authorization_code',
			],
		] );
		if ( is_wp_error( $res ) ) {
			$this->back( $back, $res->get_error_message() );
		}
		$data = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( ! empty( $data['error'] ) ) {
			$this->back( $back, (string) ( $data['error_description'] ?? $data['error'] ) );
		}
		$conn    = self::connection();
		$refresh = ! empty( $data['refresh_token'] ) ? (string) $data['refresh_token'] : (string) ( $conn['refresh_token'] ?? '' );
		if ( '' === $refresh ) {
			$this->back( $back, __( 'Google did not return a refresh token. Revoke this app at myaccount.google.com/permissions, then connect again.', 'dazont-ecom' ) );
		}
		$conn['refresh_token'] = $refresh;
		$conn['connected']     = time();
		unset( $conn['broken'], $conn['broken_why'] );
		update_option( self::OPT_CONN, $conn, false );

		// LA PROPRIETE SE CHOISIT TOUTE SEULE quand elle ne fait pas de doute :
		// un module qui doit etre passif ne commence pas par une question dont
		// il connait la reponse.
		try {
			self::pick_property();
		} catch ( Throwable $e ) {
			$this->back( $back, $e->getMessage() );
		}
		wp_safe_redirect( add_query_arg( 'dze_nl', 'connected', $back ) );
		exit;
	}

	public function handle_disconnect(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! check_admin_referer( 'dze_nl_disconnect' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'dazont-ecom' ) );
		}
		delete_option( self::OPT_CONN );
		delete_option( self::OPT_DATA );
		delete_transient( 'dze_nl_token' );
		wp_safe_redirect( self::page_url() );
		exit;
	}

	private function back( string $url, string $why ): void {
		wp_safe_redirect( add_query_arg( 'dze_nl_error', rawurlencode( $why ), $url ) );
		exit;
	}

	/** Un jeton d acces, garde le temps qu il vaut. */
	public static function token(): string {
		$cached = get_transient( 'dze_nl_token' );
		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}
		$o    = self::client();
		$conn = self::connection();
		if ( empty( $conn['refresh_token'] ) || empty( $o['client_id'] ) || empty( $o['client_secret'] ) ) {
			throw new RuntimeException( __( 'Search Console is not connected.', 'dazont-ecom' ) );
		}
		$res = wp_remote_post( self::TOKEN_URL, [
			'timeout' => 20,
			'body'    => [
				'client_id'     => $o['client_id'],
				'client_secret' => $o['client_secret'],
				'refresh_token' => $conn['refresh_token'],
				'grant_type'    => 'refresh_token',
			],
		] );
		if ( is_wp_error( $res ) ) {
			throw new RuntimeException( $res->get_error_message() );
		}
		$data = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( empty( $data['access_token'] ) ) {
			$msg  = (string) ( $data['error_description'] ?? ( $data['error'] ?? 'refresh failed' ) );
			$code = (string) ( $data['error'] ?? '' );
			// UNE AUTORISATION RETIREE NE SE REESSAIE PAS. Google repond
			// « invalid_grant » quand le consentement est tombe — et il tombe
			// au bout de SEPT JOURS tant que l application Google est en mode
			// « Testing », ce qui se regle chez Google et se lit nulle part
			// ici. On l ecrit une fois, et l ecran dit quoi faire.
			if ( 'invalid_grant' === $code || false !== stripos( $msg, 'expired or revoked' ) ) {
				$conn['broken']     = time();
				$conn['broken_why'] = $msg;
				update_option( self::OPT_CONN, $conn, false );
			}
			throw new RuntimeException( $msg );
		}
		set_transient( 'dze_nl_token', (string) $data['access_token'], max( 60, (int) ( $data['expires_in'] ?? 3600 ) - 60 ) );
		return (string) $data['access_token'];
	}

	/** Un appel a l API, avec sa reponse decodee ou une exception. */
	private static function call( string $path, array $body = [] ): array {
		$args = [
			'timeout' => 45,
			'headers' => [
				'Authorization' => 'Bearer ' . self::token(),
				'Content-Type'  => 'application/json',
			],
		];
		if ( $body ) {
			$args['body']   = (string) wp_json_encode( $body );
			$args['method'] = 'POST';
			$res = wp_remote_post( self::API . $path, $args );
		} else {
			$res = wp_remote_get( self::API . $path, $args );
		}
		if ( is_wp_error( $res ) ) {
			throw new RuntimeException( $res->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$data = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( $code < 200 || $code > 299 ) {
			throw new RuntimeException( (string) ( $data['error']['message'] ?? sprintf( 'HTTP %d', $code ) ) );
		}
		return is_array( $data ) ? $data : [];
	}

	/** Les proprietes que ce compte peut lire. */
	public static function properties(): array {
		$out = [];
		foreach ( (array) ( self::call( '/sites' )['siteEntry'] ?? [] ) as $one ) {
			$url = (string) ( $one['siteUrl'] ?? '' );
			if ( '' !== $url && 'siteUnverifiedUser' !== (string) ( $one['permissionLevel'] ?? '' ) ) {
				$out[] = $url;
			}
		}
		return $out;
	}

	/**
	 * LA PROPRIETE DE CETTE BOUTIQUE, choisie seule quand c est evident.
	 *
	 * Une propriete de domaine (`sc-domain:kula-tactical.com`) couvre toutes
	 * les langues de la boutique ; une propriete d URL n en couvre qu une. On
	 * prefere donc le domaine, et on ne demande que si rien ne correspond.
	 */
	public static function pick_property(): string {
		$set = self::settings();
		if ( ! empty( $set['property'] ) ) {
			return (string) $set['property'];
		}
		$host  = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$host  = preg_replace( '/^www\./', '', (string) $host );
		$all   = self::properties();
		$found = '';
		foreach ( $all as $one ) {
			if ( 'sc-domain:' . $host === $one ) {
				$found = $one;
				break;
			}
		}
		if ( '' === $found ) {
			foreach ( $all as $one ) {
				if ( false !== strpos( $one, (string) $host ) ) {
					$found = $one;
					break;
				}
			}
		}
		if ( '' !== $found ) {
			$set['property'] = $found;
			update_option( self::OPT_SET, $set, false );
		}
		return $found;
	}

	// =========================================================================
	// La lecture
	// =========================================================================

	/** Sur combien de jours on regarde. Vingt-huit : le defaut de Google. */
	public static function window(): int {
		$d = (int) ( self::settings()['days'] ?? 28 );
		return max( 7, min( 90, $d ) );
	}

	/**
	 * UNE COURBE DE TAUX DE CLIC PAR POSITION — et elle est annoncee comme une
	 * ESTIMATION, parce qu elle en est une.
	 *
	 * Le gain possible n est pas invente de bout en bout : les impressions sont
	 * reelles et le taux de clic actuel est celui que Google mesure. Seule la
	 * cible est estimee — ce qu une page fait, en moyenne, une fois arrivee en
	 * cinquieme position. C est la seule inconnue, elle est dite, et elle ne
	 * sert qu a CLASSER : deux pages jugees avec la meme regle se comparent
	 * meme si la regle est approximative.
	 */
	private static function ctr_at( int $pos ): float {
		$curve = [ 1 => 0.27, 2 => 0.15, 3 => 0.10, 4 => 0.07, 5 => 0.05, 6 => 0.04, 7 => 0.03, 8 => 0.026, 9 => 0.022, 10 => 0.020 ];
		if ( isset( $curve[ $pos ] ) ) {
			return $curve[ $pos ];
		}
		return $pos < 1 ? 0.27 : 0.008;
	}

	/**
	 * CE QU UN LIEN RAPPORTERAIT, page par page.
	 *
	 * On ne retient que les pages « a portee » : au-dela de la premiere place
	 * et en deca de la trentieme. Plus haut, un lien ne change presque rien —
	 * la page y est deja. Plus bas, un lien seul ne suffira pas, et promettre
	 * le contraire serait le genre de conseil qui fait perdre un mois.
	 */
	/**
	 * QUI MERITE UN LIEN, ET DANS QUEL ORDRE — sans reseau, donc eprouvable.
	 *
	 * « A portee » veut dire : au-dela de la premiere place et en deca de la
	 * trentieme. Plus haut, un lien ne change presque rien, la page y est deja.
	 * Plus bas, un lien seul ne suffira pas, et promettre le contraire est le
	 * genre de conseil qui fait perdre un mois.
	 *
	 * Le plancher d impressions ecarte les pages que trois personnes ont vues :
	 * un gain calcule sur cinq impressions est un chiffre, pas une information.
	 *
	 * @param array<string,array<string,mixed>> $pages
	 * @return array<int,array<string,mixed>>
	 */
	public static function rank( array $pages ): array {
		$out = [];
		foreach ( $pages as $p ) {
			$pos = (float) ( $p['pos'] ?? 0 );
			if ( $pos < 4.0 || $pos > 30.0 || (float) ( $p['impr'] ?? 0 ) < 20 ) {
				continue;
			}
			$gain = (float) $p['impr'] * max( 0.0, self::ctr_at( 5 ) - (float) ( $p['ctr'] ?? 0 ) );
			if ( $gain < 1 ) {
				continue; // deja au plafond de ce que la place permet.
			}
			$terms = (array) ( $p['terms'] ?? [] );
			usort( $terms, static fn( $a, $b ) => (float) $b['impr'] <=> (float) $a['impr'] );
			$p['terms'] = array_slice( $terms, 0, self::KEEP_ANCHOR );
			$p['gain']  = $gain;
			$out[]      = $p;
		}
		usort( $out, static fn( $a, $b ) => (float) $b['gain'] <=> (float) $a['gain'] );
		return array_slice( $out, 0, self::KEEP );
	}

	public static function refresh(): array {
		$set = self::settings();
		$prop = (string) ( $set['property'] ?? '' );
		if ( '' === $prop ) {
			$prop = self::pick_property();
		}
		if ( '' === $prop ) {
			throw new RuntimeException( __( 'No Search Console property matches this site.', 'dazont-ecom' ) );
		}
		$days  = self::window();
		$end   = gmdate( 'Y-m-d', time() - 2 * DAY_IN_SECONDS ); // Google a deux jours de retard.
		$start = gmdate( 'Y-m-d', time() - ( $days + 2 ) * DAY_IN_SECONDS );
		$base  = '/sites/' . rawurlencode( $prop ) . '/searchAnalytics/query';

		// 1. Chaque page, ce qu elle fait.
		$pages = [];
		foreach ( (array) ( self::call( $base, [
			'startDate'  => $start,
			'endDate'    => $end,
			'dimensions' => [ 'page' ],
			'rowLimit'   => self::MAX_PAGES,
			'type'       => 'web',
		] )['rows'] ?? [] ) as $r ) {
			$url = (string) ( $r['keys'][0] ?? '' );
			if ( '' === $url ) {
				continue;
			}
			$pages[ $url ] = [
				'url'   => $url,
				'clicks' => (float) ( $r['clicks'] ?? 0 ),
				'impr'  => (float) ( $r['impressions'] ?? 0 ),
				'ctr'   => (float) ( $r['ctr'] ?? 0 ),
				'pos'   => (float) ( $r['position'] ?? 0 ),
				'terms' => [],
			];
		}

		// 2. Les requetes, pour savoir avec quels mots lier.
		foreach ( (array) ( self::call( $base, [
			'startDate'  => $start,
			'endDate'    => $end,
			'dimensions' => [ 'page', 'query' ],
			'rowLimit'   => self::MAX_ROWS,
			'type'       => 'web',
		] )['rows'] ?? [] ) as $r ) {
			$url = (string) ( $r['keys'][0] ?? '' );
			$q   = (string) ( $r['keys'][1] ?? '' );
			if ( '' === $url || '' === $q || ! isset( $pages[ $url ] ) ) {
				continue;
			}
			$pages[ $url ]['terms'][] = [
				'q'    => $q,
				'impr' => (float) ( $r['impressions'] ?? 0 ),
				'pos'  => (float) ( $r['position'] ?? 0 ),
			];
		}

		// 3. Le classement — separe de la lecture, parce qu une regle enfouie
		// dans un appel reseau ne s eprouve pas.
		$out = self::rank( $pages );

		update_option( self::OPT_DATA, [
			'at'       => time(),
			'property' => $prop,
			'days'     => $days,
			'from'     => $start,
			'to'       => $end,
			'rows'     => $out,
			'seen'     => count( $pages ),
		], false );
		return [ 'targets' => count( $out ), 'pages' => count( $pages ) ];
	}

	public static function data(): array {
		$d = get_option( self::OPT_DATA, [] );
		return is_array( $d ) ? $d : [];
	}

	public static function ajax_refresh(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'dazont-ecom' ) ] );
		}
		check_ajax_referer( self::NONCE, 'nonce' );
		try {
			wp_send_json_success( self::refresh() );
		} catch ( Throwable $e ) {
			wp_send_json_error( [ 'message' => $e->getMessage() ] );
		}
	}

	// =========================================================================
	// L ecran
	// =========================================================================

	public static function page_url(): string {
		return add_query_arg( [ 'page' => self::MENU_SLUG ], admin_url( 'admin.php' ) );
	}

	public function register_settings(): void {
		register_setting( 'dze_nl_options', self::OPT_SET, [ 'sanitize_callback' => [ $this, 'sanitize' ], 'autoload' => false ] );
	}

	public function sanitize( $in ): array {
		if ( null === $in ) {
			return self::settings();
		}
		$in  = is_array( $in ) ? $in : [];
		$out = self::settings();
		if ( isset( $in['property'] ) ) {
			$out['property'] = sanitize_text_field( (string) $in['property'] );
		}
		if ( isset( $in['days'] ) ) {
			$out['days'] = max( 7, min( 90, (int) $in['days'] ) );
		}
		return $out;
	}

	public static function menu(): void {
		if ( ! class_exists( 'DZE_Screens' ) ) {
			return;
		}
		add_submenu_page(
			DZE_Screens::PARENT,
			DZE_Screens::label( 'netlinking' ),
			DZE_Screens::label( 'netlinking' ),
			'manage_woocommerce',
			self::MENU_SLUG,
			[ __CLASS__, 'render_page' ]
		);
	}

	public static function render_page(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		echo '<div class="wrap dze-admin"><h1>' . esc_html( DZE_Screens::label( 'netlinking' ) ) . '</h1>';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		if ( ! empty( $_GET['dze_nl_error'] ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html( sanitize_text_field( wp_unslash( (string) $_GET['dze_nl_error'] ) ) ) . '</p></div>'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		self::render_intro();
		if ( ! self::connected() ) {
			self::render_connect();
			echo '</div>';
			return;
		}
		self::render_targets();
		echo '</div>';
	}

	private static function render_intro(): void {
		echo '<p class="description" style="max-width:900px;">';
		esc_html_e( 'Which of your pages would gain the most from a link pointing at it from another site, and which words that link should be made of. Read from Search Console once a day. Nothing here is written to the shop, nothing is sent to a model, and nothing is paid for.', 'dazont-ecom' );
		echo '</p>';
		echo '<p class="description" style="max-width:900px;">';
		echo '<strong>' . esc_html__( 'What Search Console does not give.', 'dazont-ecom' ) . '</strong> ';
		esc_html_e( 'Its API has no backlinks: the Links report exists on screen and nowhere else. So this screen never claims to list the links you already have — it says where a new one would pay.', 'dazont-ecom' );
		echo '</p>';
	}

	private static function render_connect(): void {
		$me  = self::instance();
		$o   = self::client();
		$has = ! empty( $o['client_id'] ) && ! empty( $o['client_secret'] );
		$c   = self::connection();
		echo '<div class="dze-set" style="max-width:900px;padding:14px 16px;border:1px solid #dcdcde;background:#fff;border-radius:8px;">';
		if ( ! empty( $c['broken'] ) ) {
			echo '<p><strong>' . esc_html__( 'The connection to Google has come apart.', 'dazont-ecom' ) . '</strong> ';
			esc_html_e( 'While the Google app is still in "Testing" mode, Google drops the authorisation after seven days. Publishing the app stops that happening; connecting again brings it back until then.', 'dazont-ecom' );
			echo '</p>';
		}
		if ( ! $has ) {
			echo '<p>' . esc_html__( 'This needs the same Google app the Merchant Center uses. Set that up first, or enter a client id and secret of its own under Settings.', 'dazont-ecom' ) . '</p>';
			echo '</div>';
			return;
		}
		echo '<p>' . esc_html__( 'Read-only access: this can see your Search Console figures and can never change anything there.', 'dazont-ecom' ) . '</p>';
		echo '<p><strong>' . esc_html__( 'Before connecting', 'dazont-ecom' ) . '</strong> — ' . esc_html__( 'add this address to the Google app, under Authorised redirect URIs:', 'dazont-ecom' ) . '</p>';
		echo '<p><code style="user-select:all;">' . esc_html( $me->redirect_uri() ) . '</code></p>';
		echo '<p><a class="button button-primary" href="' . esc_url( $me->authorize_url() ) . '">' . esc_html__( 'Connect Search Console', 'dazont-ecom' ) . '</a></p>';
		echo '</div>';
	}

	private static function render_targets(): void {
		$d    = self::data();
		$rows = (array) ( $d['rows'] ?? [] );
		$me   = self::instance();

		echo '<p class="dze-cb-actions" style="max-width:980px;">';
		echo '<button type="button" class="button" id="dze-nl-refresh">' . esc_html__( 'Read it again now', 'dazont-ecom' ) . '</button>';
		echo '<span class="description" id="dze-nl-state">';
		if ( ! empty( $d['at'] ) ) {
			printf(
				/* translators: 1: how long ago, 2: the property, 3: how many days */
				esc_html__( 'Read %1$s ago from %2$s, over %3$s days.', 'dazont-ecom' ),
				esc_html( human_time_diff( (int) $d['at'], time() ) ),
				esc_html( (string) ( $d['property'] ?? '' ) ),
				esc_html( number_format_i18n( (int) ( $d['days'] ?? 0 ) ) )
			);
		} else {
			esc_html_e( 'Not read yet — it reads itself once a day, or press the button.', 'dazont-ecom' );
		}
		echo '</span>';
		echo '<a class="button" style="margin-left:auto;" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=dze_nl_disconnect' ), 'dze_nl_disconnect' ) ) . '">' . esc_html__( 'Disconnect', 'dazont-ecom' ) . '</a>';
		echo '</p>';

		if ( ! $rows ) {
			echo '<p class="description">' . esc_html__( 'Nothing is within reach right now: no page sits between the fourth and the thirtieth place with enough impressions behind it. That is an answer, not a fault.', 'dazont-ecom' ) . '</p>';
			return;
		}
		echo '<table class="widefat striped" style="max-width:1100px;"><thead><tr>';
		echo '<th>' . esc_html__( 'Page', 'dazont-ecom' ) . '</th>';
		echo '<th style="width:110px;">' . esc_html__( 'Position', 'dazont-ecom' ) . '</th>';
		echo '<th style="width:110px;">' . esc_html__( 'Impressions', 'dazont-ecom' ) . '</th>';
		echo '<th style="width:110px;">' . esc_html__( 'Clicks', 'dazont-ecom' ) . '</th>';
		echo '<th style="width:150px;">' . esc_html__( 'Clicks to gain', 'dazont-ecom' ) . '</th>';
		echo '<th>' . esc_html__( 'Words to link it with', 'dazont-ecom' ) . '</th>';
		echo '</tr></thead><tbody>';
		foreach ( $rows as $r ) {
			$url = (string) ( $r['url'] ?? '' );
			echo '<tr>';
			echo '<td><a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . esc_html( self::short( $url ) ) . '</a></td>';
			echo '<td>' . esc_html( number_format_i18n( (float) ( $r['pos'] ?? 0 ), 1 ) ) . '</td>';
			echo '<td>' . esc_html( number_format_i18n( (int) ( $r['impr'] ?? 0 ) ) ) . '</td>';
			echo '<td>' . esc_html( number_format_i18n( (int) ( $r['clicks'] ?? 0 ) ) ) . '</td>';
			echo '<td><strong>+' . esc_html( number_format_i18n( (int) round( (float) ( $r['gain'] ?? 0 ) ) ) ) . '</strong> <span class="description">' . esc_html__( 'est.', 'dazont-ecom' ) . '</span></td>';
			echo '<td>';
			foreach ( (array) ( $r['terms'] ?? [] ) as $t ) {
				echo '<span class="dze-mesh-chip" style="display:inline-block;margin:0 4px 4px 0;padding:1px 7px;border:1px solid #dcdcde;border-radius:10px;font-size:12px;">' . esc_html( (string) ( $t['q'] ?? '' ) ) . '</span>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
		echo '<p class="description" style="max-width:980px;margin-top:10px;">';
		esc_html_e( 'The impressions and the clicks are what Google measured. "Clicks to gain" is an estimate — what the page would do at about fifth place, against what it does now — and it is here to RANK the pages against one another, not to promise a figure.', 'dazont-ecom' );
		echo '</p>';
		?>
		<script>
		jQuery( function ( $ ) {
			$( '#dze-nl-refresh' ).on( 'click', function () {
				var $b = $( this ).prop( 'disabled', true );
				var $s = $( '#dze-nl-state' ).text( <?php echo wp_json_encode( __( 'Reading Search Console…', 'dazont-ecom' ) ); ?> );
				$.post( ajaxurl, { action: 'dze_nl_refresh', nonce: <?php echo wp_json_encode( wp_create_nonce( self::NONCE ) ); ?> } )
					.done( function ( r ) {
						if ( !r || !r.success ) {
							$b.prop( 'disabled', false );
							$s.text( ( r && r.data && r.data.message ) ? r.data.message : 'Error' );
							return;
						}
						window.location.reload();
					} )
					.fail( function () { $b.prop( 'disabled', false ); $s.text( 'Error' ); } );
			} );
		} );
		</script>
		<?php
	}

	/** Une adresse lisible : le chemin, pas le domaine repete cinquante fois. */
	private static function short( string $url ): string {
		$p = (string) wp_parse_url( $url, PHP_URL_PATH );
		return '' === $p || '/' === $p ? $url : trim( $p, '/' );
	}
}
