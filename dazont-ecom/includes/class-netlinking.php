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

	/** Ce que la derniere lecture a refuse de faire, et quand. */
	public const OPT_LAST_ERROR = 'dze_nl_last_error';

	/**
	 * LA LECTURE AUTOMATIQUE, ET CE QU ELLE FAIT DE SES ECHECS.
	 *
	 * Elle en garde la raison et l heure, pour que l ecran puisse dire « la
	 * derniere lecture a echoue, voici pourquoi » au lieu de « pas encore lu ».
	 * Et elle le dit au journal de sante, qui est l endroit ou une boutique va
	 * voir ce qui ne repond plus.
	 */
	public static function cron_refresh(): void {
		try {
			self::refresh();
			delete_option( self::OPT_LAST_ERROR );
		} catch ( Throwable $e ) {
			update_option( self::OPT_LAST_ERROR, [ 'at' => time(), 'why' => $e->getMessage() ], false );
			if ( class_exists( 'DZE_Health' ) ) {
				DZE_Health::log( 'searchconsole', __( 'Reading Search Console', 'dazont-ecom' ), $e->getMessage() );
			}
		}
	}

	/** Ce qui a empeche la derniere lecture, ou [] quand tout va bien. */
	public static function last_error(): array {
		$e = get_option( self::OPT_LAST_ERROR, [] );
		return is_array( $e ) ? $e : [];
	}

	/**
	 * L ETAT DE LA CONNEXION, en un mot, pour le journal de sante.
	 *
	 * @return array{state:string,message:string}
	 */
	public static function health(): array {
		if ( ! self::connected() ) {
			$c = self::connection();
			if ( ! empty( $c['broken'] ) ) {
				return [ 'state' => 'down', 'message' => __( 'Google revoked the authorisation. While the Google app is in "Testing" it drops every seven days; publishing the app stops that.', 'dazont-ecom' ) ];
			}
			return [ 'state' => 'off', 'message' => __( 'Not connected.', 'dazont-ecom' ) ];
		}
		$bad = self::last_error();
		if ( ! empty( $bad['why'] ) ) {
			return [ 'state' => 'down', 'message' => (string) $bad['why'] ];
		}
		$d = self::data();
		if ( empty( $d['at'] ) ) {
			return [ 'state' => 'warn', 'message' => __( 'Connected, but nothing read yet.', 'dazont-ecom' ) ];
		}
		// UNE LECTURE QUI DATE EST UNE LECTURE QUI NE SE FAIT PLUS. Elle tourne
		// une fois par jour : trois jours sans rien, c est le cron qui ne passe
		// plus, pas un calme du catalogue.
		if ( time() - (int) $d['at'] > 3 * DAY_IN_SECONDS ) {
			return [
				'state'   => 'warn',
				/* translators: %s: how long ago */
				'message' => sprintf( __( 'Last read %s ago — it should read itself once a day.', 'dazont-ecom' ), human_time_diff( (int) $d['at'], time() ) ),
			];
		}
		return [
			'state'   => 'ok',
			/* translators: 1: how many targets, 2: how long ago */
			'message' => sprintf( __( '%1$s pages worth a link, read %2$s ago.', 'dazont-ecom' ), number_format_i18n( count( (array) ( $d['rows'] ?? [] ) ) ), human_time_diff( (int) $d['at'], time() ) ),
		];
	}
	/** Ce que la derniere lecture a trouve. */
	public const OPT_DATA = 'dze_nl_targets';

	public const HOOK  = 'dze_nl_refresh';
	private const NONCE = 'dze_nl';

	/** Lecture seule : ce module ne changera jamais rien chez Google. */
	private const SCOPE = 'https://www.googleapis.com/auth/webmasters.readonly';

	private const AUTH_URL  = 'https://accounts.google.com/o/oauth2/v2/auth';
	private const TOKEN_URL = 'https://oauth2.googleapis.com/token';
	private const API       = 'https://searchconsole.googleapis.com/webmasters/v3';

	/** Les adresses exactes, chez Google, ou chaque etape se fait. */
	private const GOOGLE_CREDENTIALS = 'https://console.cloud.google.com/apis/credentials';
	private const GOOGLE_CONSENT     = 'https://console.cloud.google.com/apis/credentials/consent';
	private const GOOGLE_API         = 'https://console.cloud.google.com/apis/library/searchconsole.googleapis.com';

	/** Combien de pages on classe, et combien de requetes on garde par page. */
	private const MAX_PAGES   = 5000;
	private const MAX_ROWS    = 25000;
	private const KEEP        = 60;
	/** Le plancher par langue : en dessous, on ne travaille pas un catalogue. */
	private const MIN_PER_LANG = 10;
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
		// LE CRON NE PASSE PAS PAR refresh() DIRECTEMENT : une lecture qui
		// echoue en cron jetait son exception dans le vide, et l ecran disait
		// « pas encore lu » — la meme phrase que le premier jour. Un echec
		// silencieux qui ressemble a un debut est le pire des etats.
		add_action( self::HOOK, [ __CLASS__, 'cron_refresh' ] );
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

	/** L adresse commune a tout le plugin — voir DZE_Oauth. */
	public function redirect_uri(): string {
		return class_exists( 'DZE_Oauth' ) ? DZE_Oauth::redirect_uri() : admin_url( 'admin-post.php?action=dze_nl_oauth' );
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
			// Qui demande, et son jeton : le routeur commun lit le premier et
			// rend le second a ce module, qui le verifie comme avant.
			'state'         => class_exists( 'DZE_Oauth' ) ? DZE_Oauth::state( 'nl', 'dze_nl_oauth' ) : wp_create_nonce( 'dze_nl_oauth' ),
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
			self::pick_properties();
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
			throw new RuntimeException( self::said( (string) ( $data['error']['message'] ?? sprintf( 'HTTP %d', $code ) ) ) );
		}
		return is_array( $data ) ? $data : [];
	}

	/**
	 * CE QUE GOOGLE REFUSE, DIT EN CLAIR ET AVEC LE LIEN QUI LE REGLE.
	 *
	 * « Google Search Console API has not been used in project 549418223064
	 * before or it is disabled. » Le message est juste, et il est illisible :
	 * il arrive APRES une connexion reussie, ce qui donne l impression que tout
	 * est fait et que rien ne marche. Et il faut y trouver un numero de projet
	 * pour construire soi-meme l adresse a visiter.
	 *
	 * Ce cas-la est le plus probable de tous — c est l etape qu on oublie — donc
	 * il est reconnu, et le numero de projet que Google donne sert a fabriquer
	 * le lien exact du bon projet.
	 */
	public static function said( string $raw ): string {
		if ( false !== stripos( $raw, 'has not been used in project' ) || false !== stripos( $raw, 'is disabled' ) ) {
			$project = '';
			if ( preg_match( '/project\s+(\d{6,})/i', $raw, $m ) ) {
				$project = (string) $m[1];
			}
			$url = self::GOOGLE_API . ( '' !== $project ? '?project=' . rawurlencode( $project ) : '' );
			return sprintf(
				/* translators: %s: the address of the API page in the shop's own Google project */
				__( 'The Search Console API is not switched on in your Google project, so Google refuses to answer. Turn it on here, wait two or three minutes for Google to propagate it, then read again: %s', 'dazont-ecom' ),
				$url
			);
		}
		if ( false !== stripos( $raw, 'insufficient' ) || false !== stripos( $raw, 'permission' ) || false !== stripos( $raw, 'forbidden' ) ) {
			return sprintf(
				/* translators: %s: Google's own wording */
				__( 'Google refused: the connected account cannot read one of this shop\'s Search Console properties. Add it in Search Console under Settings → Users and permissions, then read again. (%s)', 'dazont-ecom' ),
				$raw
			);
		}
		return $raw;
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
	/**
	 * AUTANT DE PROPRIETES QUE WPML A DE DOMAINES.
	 *
	 * « Un multidomaine = une search console par domaine. » C est le reglage de
	 * WPML qui commande : en mode domaine, Search Console tient une propriete
	 * par langue et il faut les lire toutes — n en lire qu une laissait quatre
	 * catalogues invisibles sans rien dire. Dans les deux autres modes, toutes
	 * les langues vivent sous le meme domaine, donc une seule propriete les
	 * contient deja et en chercher cinq n aurait aucun sens.
	 *
	 * @return array<int,string>
	 */
	public static function pick_properties(): array {
		$set = self::settings();
		if ( ! empty( $set['properties'] ) && is_array( $set['properties'] ) ) {
			return array_map( 'strval', $set['properties'] );
		}
		// En mode domaine, tous les domaines de WPML ; sinon, celui du site — et
		// domains() rend deja l un ou l autre selon le reglage.
		$hosts = array_values( self::domains() );
		$found = [];
		foreach ( self::properties() as $one ) {
			foreach ( $hosts as $h ) {
				if ( 'sc-domain:' . $h === $one || false !== strpos( $one, '://' . $h ) || false !== strpos( $one, '://www.' . $h ) ) {
					$found[ $one ] = true;
				}
			}
		}
		$found = array_keys( $found );
		if ( $found ) {
			$set['properties'] = $found;
			update_option( self::OPT_SET, $set, false );
		}
		return $found;
	}

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
	// =========================================================================
	// Les ventes, croisees avec ce que Google mesure
	// =========================================================================

	/**
	 * CE QUE CHAQUE CATEGORIE A VENDU, toutes langues confondues.
	 *
	 * « La data GSC doit être recroisée avec les ventes au niveau des
	 * catégories produits. » Sans cela le module classe une categorie a 4 000
	 * impressions qui ne vend rien au-dessus d une a 800 qui vend : du trafic
	 * pour du trafic, ce qui n est pas le metier de cette boutique.
	 *
	 * CHAQUE LANGUE COMPTE SES PROPRES VENTES. « Les ventes sont comptabilisées
	 * seulement sur la langue concernée. »
	 *
	 * Une version precedente reportait les ventes sur tout le groupe de
	 * traduction, au motif que la boutique vend presque tout en anglais et que
	 * les pages traduites se seraient retrouvees a zero. C etait decider a la
	 * place de la boutique : un zero sur la page allemande EST l information —
	 * ce catalogue ne vend pas encore — et la masquer derriere un chiffre
	 * anglais empechait de le voir.
	 *
	 * La table de WooCommerce Analytics est la source, comme pour le bloc des
	 * meilleures ventes ; absente ou vide, on rend un tableau vide et le module
	 * continue sans les ventes plutot que de tomber.
	 *
	 * @return array<int,array{units:int}> par term_id
	 */
	public static function sales_by_term( int $days ): array {
		global $wpdb;
		if ( ! $wpdb ) {
			return [];
		}
		$lookup = $wpdb->prefix . 'wc_order_product_lookup';
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- WooCommerce's and WPML's own tables.
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $lookup ) ) !== $lookup ) {
			return [];
		}
		$icl  = $wpdb->prefix . 'icl_translations';
		$wpml = class_exists( 'DZE_Wpml' ) && DZE_Wpml::is_active() && DZE_Wpml::has_table( $icl );

		// SANS WPML, une categorie est une categorie et la question ne se pose pas.
		if ( ! $wpml ) {
			$rows = (array) $wpdb->get_results( $wpdb->prepare(
				"SELECT tt.term_id AS tid, SUM( l.product_qty ) AS units
				   FROM {$lookup} l
				   INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = l.product_id
				   INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
				  WHERE tt.taxonomy = 'product_cat'
				    AND l.date_created > DATE_SUB( NOW(), INTERVAL %d DAY )
				  GROUP BY tt.term_id",
				max( 1, $days )
			), ARRAY_A );
			$out = [];
			foreach ( $rows as $r ) {
				$out[ (int) $r['tid'] ] = [ 'units' => (int) $r['units'] ];
			}
			return $out;
			// phpcs:enable
		}

		// LA LANGUE EST CELLE DU PRODUIT VENDU, jamais celle de sa categorie.
		//
		// Sur ce catalogue, les produits traduits sont ranges dans les
		// categories ANGLAISES : le produit francais 988039969 est dans la
		// categorie 7240, qui est anglaise. Compter par la langue de la
		// categorie rendrait donc zero pour quatre langues sur cinq — un
		// artefact du rangement, pas un fait sur les ventes, et le genre de
		// zero qu on prend pour une reponse.
		//
		// Ce qui a ete vendu, c est le produit ; sa langue est la sienne. On
		// prend donc sa categorie, et on la ramene dans SA langue par le groupe
		// de traduction. Une vente francaise compte pour la categorie
		// francaise, et pour elle seule.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- WooCommerce's and WPML's own tables.
		$rows = (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT ct.trid AS trid, pl.language_code AS lang, SUM( l.product_qty ) AS units
			   FROM {$lookup} l
			   INNER JOIN {$icl} pl ON pl.element_id = l.product_id AND pl.element_type = 'post_product'
			   INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = l.product_id
			   INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
			   INNER JOIN {$icl} ct ON ct.element_id = tt.term_taxonomy_id AND ct.element_type = 'tax_product_cat'
			  WHERE tt.taxonomy = 'product_cat'
			    AND l.date_created > DATE_SUB( NOW(), INTERVAL %d DAY )
			  GROUP BY ct.trid, pl.language_code",
			max( 1, $days )
		), ARRAY_A );

		// Le terme de chaque groupe, langue par langue.
		$members = [];
		foreach ( (array) $wpdb->get_results(
			"SELECT ic.trid, ic.language_code AS lang, tt.term_id AS tid
			   FROM {$icl} ic
			   INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = ic.element_id
			  WHERE ic.element_type = 'tax_product_cat'",
			ARRAY_A
		) as $m ) {
			$members[ (int) $m['trid'] ][ (string) $m['lang'] ] = (int) $m['tid'];
		}
		// phpcs:enable

		$out = [];
		foreach ( $rows as $r ) {
			$trid = (int) $r['trid'];
			$lang = (string) $r['lang'];
			// LA CATEGORIE DE CETTE LANGUE, ou rien. Quand elle n existe pas
			// encore, la vente n est attribuee a personne plutot qu a la page
			// anglaise : inventer une attribution est pire que de se taire.
			$tid = (int) ( $members[ $trid ][ $lang ] ?? 0 );
			if ( ! $tid ) {
				continue;
			}
			$out[ $tid ]['units'] = ( $out[ $tid ]['units'] ?? 0 ) + (int) $r['units'];
		}
		return $out;
	}
	/**
	 * COMMENT WPML SEPARE SES LANGUES — et c est lui qui decide, pas nous.
	 *
	 * « C'est WPML et ses réglages qui doivent dicter la façon de fonctionner. »
	 * Trois façons, et elles ne se lisent pas pareil :
	 *
	 *   2  un domaine par langue   kula-tactical.fr/bottes
	 *   1  un repertoire par langue  exemple.com/fr/bottes
	 *   3  un parametre             exemple.com/bottes?lang=fr
	 *
	 * Rien ici n est devine : le reglage est lu, et tout le reste en decoule —
	 * combien de proprietes Search Console lire, et comment retrouver la langue
	 * d une adresse que Google rend.
	 */
	public static function negotiation(): int {
		$s = get_option( 'icl_sitepress_settings', [] );
		return (int) ( is_array( $s ) ? ( $s['language_negotiation_type'] ?? 0 ) : 0 );
	}

	/** Le code de la langue par defaut, tel que WPML le connait. */
	public static function default_lang(): string {
		if ( class_exists( 'DZE_Category_Content' ) ) {
			$l = (string) DZE_Category_Content::default_lang();
			if ( '' !== $l ) {
				return $l;
			}
		}
		$s = get_option( 'icl_sitepress_settings', [] );
		return (string) ( is_array( $s ) ? ( $s['default_language'] ?? 'en' ) : 'en' );
	}

	/**
	 * Un domaine par langue — seulement quand WPML travaille ainsi.
	 *
	 * Dans les deux autres modes, toutes les langues partagent le domaine de la
	 * boutique, et rendre une liste de domaines ferait croire le contraire.
	 */
	public static function domains(): array {
		$home = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$home = (string) preg_replace( '/^www\./', '', (string) $home );
		$def  = self::default_lang();
		if ( 2 !== self::negotiation() ) {
			return '' === $home ? [] : [ $def => $home ];
		}
		$s   = get_option( 'icl_sitepress_settings', [] );
		$out = [];
		foreach ( (array) ( is_array( $s ) ? ( $s['language_domains'] ?? [] ) : [] ) as $code => $d ) {
			$d = (string) preg_replace( '~^https?://~', '', (string) $d );
			$d = (string) preg_replace( '/^www\./', '', rtrim( $d, '/' ) );
			if ( '' !== $d ) {
				$out[ (string) $code ] = $d;
			}
		}
		// Le domaine principal n est pas dans cette liste : WPML n y met que les
		// langues traduites, la langue par defaut vivant sur le domaine du site.
		if ( '' !== $home && ! in_array( $home, $out, true ) ) {
			$out[ $def ] = $home;
		}
		return $out;
	}

	/** La langue d un domaine, quand les langues sont sur des domaines. */
	public static function lang_of_host( string $host ): string {
		$host = (string) preg_replace( '/^www\./', '', strtolower( $host ) );
		foreach ( self::domains() as $code => $one ) {
			if ( $one === $host ) {
				return (string) $code;
			}
		}
		return '';
	}

	/**
	 * LA LANGUE D UNE ADRESSE, selon le mode que WPML a reglé.
	 *
	 * Rend '' quand l adresse ne dit rien — et '' n est pas la langue par
	 * defaut : ne pas savoir et savoir sont deux etats differents, et les
	 * confondre attribuerait des ventes anglaises a une page qu on n a pas su
	 * reconnaitre.
	 */
	public static function lang_of_url( string $url ): string {
		switch ( self::negotiation() ) {
			case 2: // un domaine par langue.
				return self::lang_of_host( (string) wp_parse_url( $url, PHP_URL_HOST ) );

			case 1: // un repertoire par langue.
				$path = trim( (string) wp_parse_url( $url, PHP_URL_PATH ), '/' );
				if ( '' === $path ) {
					return self::default_lang();
				}
				$first = (string) strtok( $path, '/' );
				// Un premier morceau qui EST un code de langue actif, et pas une
				// categorie qui lui ressemble.
				return in_array( $first, self::active_codes(), true ) ? $first : self::default_lang();

			case 3: // un parametre.
				$q = (string) wp_parse_url( $url, PHP_URL_QUERY );
				$a = [];
				parse_str( $q, $a );
				$code = (string) ( $a['lang'] ?? '' );
				return in_array( $code, self::active_codes(), true ) ? $code : self::default_lang();
		}
		return '';
	}

	/** Les codes que WPML dit actifs, la langue par defaut comprise. */
	public static function active_codes(): array {
		$out = [];
		foreach ( (array) apply_filters( 'wpml_active_languages', null, [] ) as $code => $one ) {
			$out[] = (string) $code;
		}
		if ( ! $out ) {
			$out = array_keys( self::domains() );
		}
		return array_values( array_unique( array_filter( $out ) ) );
	}

	/**
	 * QUELLE CATEGORIE EST DERRIERE CETTE ADRESSE.
	 *
	 * La langue vient de WPML, le dernier morceau du chemin donne le slug — en
	 * mode repertoire, le code de langue est un morceau parmi d autres et ne
	 * gene pas, puisqu on prend le dernier.
	 *
	 * Rend 0 quand l adresse n est pas une categorie — un article, une page, un
	 * produit — et c est une reponse, pas un echec.
	 */
	public static function term_of_url( string $url, array $slug_map ): int {
		$path = trim( (string) wp_parse_url( $url, PHP_URL_PATH ), '/' );
		if ( '' === $path ) {
			return 0;
		}
		$bits = explode( '/', $path );
		$slug = (string) end( $bits );
		if ( '' === $slug ) {
			return 0;
		}
		$lang = self::lang_of_url( $url );
		if ( '' !== $lang && isset( $slug_map[ $lang . '|' . $slug ] ) ) {
			return (int) $slug_map[ $lang . '|' . $slug ];
		}
		return (int) ( $slug_map[ '|' . $slug ] ?? 0 );
	}
	/**
	 * « langue|slug » et « |slug » vers le term_id, en une requete.
	 *
	 * LA LANGUE EST LUE EN TABLE, JAMAIS PAR UN FILTRE.
	 *
	 * `DZE_Category_Content::lang_code()` passe par le filtre
	 * `wpml_element_language_details`, et un filtre ne repond que la ou les
	 * hooks de son extension sont charges. Hors de ce cas il rend null, le code
	 * retombe sur « la langue par defaut », et TOUTE categorie passe pour
	 * anglaise sans que rien ne le signale : mesure sur cette boutique, la
	 * categorie 7240 est francaise et le filtre repondait « en ». Cette
	 * lecture-ci tourne aussi en cron, ou rien ne garantit ces hooks.
	 *
	 * La seconde clef, « |slug », est le filet : un slug dont on ne sait pas la
	 * langue vaut mieux que pas de categorie du tout, et la premiere lui passe
	 * devant.
	 */
	public static function slug_map(): array {
		global $wpdb;
		if ( ! $wpdb ) {
			return [];
		}
		$icl  = $wpdb->prefix . 'icl_translations';
		$wpml = class_exists( 'DZE_Wpml' ) && DZE_Wpml::is_active() && DZE_Wpml::has_table( $icl );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own read of WPML's table.
		$rows = (array) $wpdb->get_results(
			$wpml
				? "SELECT t.term_id AS tid, t.slug AS slug, ic.language_code AS lang
				     FROM {$wpdb->terms} t
				     INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id
				     LEFT JOIN {$icl} ic ON ic.element_id = tt.term_taxonomy_id AND ic.element_type = 'tax_product_cat'
				    WHERE tt.taxonomy = 'product_cat'"
				: "SELECT t.term_id AS tid, t.slug AS slug, '' AS lang
				     FROM {$wpdb->terms} t
				     INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id
				    WHERE tt.taxonomy = 'product_cat'",
			ARRAY_A
		);
		// phpcs:enable
		$out = [];
		foreach ( $rows as $r ) {
			$tid  = (int) $r['tid'];
			$slug = (string) $r['slug'];
			$lang = (string) ( $r['lang'] ?? '' );
			if ( '' !== $lang ) {
				$out[ $lang . '|' . $slug ] = $tid;
			}
			if ( ! isset( $out[ '|' . $slug ] ) ) {
				$out[ '|' . $slug ] = $tid;
			}
		}
		return $out;
	}
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
	public static function rank( array $pages, array $sales = [], array $slug_map = [] ): array {
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

			// CE QUE CETTE PAGE VEND, quand c est une categorie produit.
			//
			// « La data GSC doit être recroisée avec les ventes au niveau des
			// catégories produits. » Le trafic seul classait une categorie qui
			// ne vend rien au-dessus d une qui vend : du trafic pour du trafic.
			$tid = $slug_map ? self::term_of_url( (string) ( $p['url'] ?? '' ), $slug_map ) : 0;
			$p['tid']     = $tid;
			$p['units'] = $tid && isset( $sales[ $tid ] ) ? (int) $sales[ $tid ]['units'] : 0;
			// UNE PRIORITE, ET SURTOUT PAS UNE PREVISION D UNITES.
			//
			// Le premier calcul multipliait les clics a gagner par « unites
			// vendues / clics Google ». Mesure sur la boutique :
			// /military-balaclava sortait a +171 unites pour +17 clics, parce
			// qu elle a vendu 50 unites sur cinq clics organiques — dix unites
			// par clic. Le rapport est structurellement faux : les ventes
			// viennent de PARTOUT (direct, publicite, e-mail) et le
			// denominateur ne compte que Google.
			//
			// On ne peut donc pas annoncer des unites. Ce qu on peut faire
			// honnetement, c est CLASSER : le trafic a gagner, pondere par le
			// fait que cette categorie vende — beaucoup, un peu, ou pas. Le
			// logarithme sert exactement a ca, ecraser l ecart entre 50 et 500
			// sans effacer l ecart entre 0 et 50.
			//
			// Le resultat n a pas d unite et ne pretend rien : c est un rang.
			$p['worth'] = $p['units'] > 0 ? $gain * ( 1.0 + log10( 1.0 + (float) $p['units'] ) ) : 0.0;
			$out[]          = $p;
		}
		// L ARGENT D ABORD, LE TRAFIC ENSUITE. Une page qui ne vend pas — un
		// article, une page d information — n est pas jetee : elle se classe
		// derriere celles qui vendent, entre elles sur les clics a gagner.
		usort( $out, static function ( $a, $b ) {
			$wa = (float) ( $a['worth'] ?? 0 );
			$wb = (float) ( $b['worth'] ?? 0 );
			if ( $wa !== $wb ) {
				return $wb <=> $wa;
			}
			return (float) $b['gain'] <=> (float) $a['gain'];
		} );
		return self::share_out( $out );
	}

	/**
	 * CHAQUE LANGUE GARDE UNE PLACE DANS LA LISTE.
	 *
	 * Mesure sur la boutique : 58 cibles anglaises, une francaise, une
	 * polonaise. Le plafond etant global, l anglais l absorbait et les quatre
	 * catalogues traduits disparaissaient — alors qu on repare le maillage
	 * externe d une langue, pas de la boutique entiere, et qu une langue dont
	 * on ne voit jamais une page est une langue sur laquelle on ne travaille
	 * jamais.
	 *
	 * Chacune emporte d abord sa part, dans SON ordre ; ce qui reste va aux
	 * meilleures, quelle que soit la langue. L ordre general est ensuite rendu
	 * tel quel, pour que la tete de liste reste la tete de liste.
	 *
	 * @param array<int,array<string,mixed>> $ranked Deja classees.
	 * @return array<int,array<string,mixed>>
	 */
	public static function share_out( array $ranked ): array {
		if ( count( $ranked ) <= self::KEEP ) {
			return $ranked;
		}
		$by = [];
		foreach ( $ranked as $r ) {
			$by[ (string) ( $r['lang'] ?? '' ) ][] = $r;
		}
		if ( count( $by ) < 2 ) {
			return array_slice( $ranked, 0, self::KEEP );
		}
		// UN PLANCHER, PAS UN PARTAGE EGAL. Diviser le plafond par le nombre de
		// langues donnait autant de place a un catalogue qui vend presque rien
		// qu a celui qui porte la boutique — ce qui repousse hors de l ecran du
		// vrai travail anglais au profit de lignes francaises marginales.
		//
		// Chacune est donc SEULEMENT assuree d etre la ; tout le reste se donne
		// au merite, et l anglais garde naturellement la plus grosse part.
		$share = self::MIN_PER_LANG;
		$keep  = [];
		foreach ( $by as $lang_rows ) {
			foreach ( array_slice( $lang_rows, 0, $share ) as $one ) {
				$keep[ (string) $one['url'] ] = true;
			}
		}
		// Ce qui reste va aux meilleures, sans regarder la langue.
		foreach ( $ranked as $r ) {
			if ( count( $keep ) >= self::KEEP ) {
				break;
			}
			$keep[ (string) $r['url'] ] = true;
		}
		$out = [];
		foreach ( $ranked as $r ) {
			if ( isset( $keep[ (string) $r['url'] ] ) ) {
				$out[] = $r;
			}
		}
		return $out;
	}

	public static function refresh(): array {
		$props = self::pick_properties();
		if ( ! $props ) {
			throw new RuntimeException( __( 'No Search Console property matches this site.', 'dazont-ecom' ) );
		}
		$days  = self::window();
		$end   = gmdate( 'Y-m-d', time() - 2 * DAY_IN_SECONDS ); // Google a deux jours de retard.
		$start = gmdate( 'Y-m-d', time() - ( $days + 2 ) * DAY_IN_SECONDS );

		// UNE PROPRIETE PAR LANGUE, et toutes dans le meme panier : le classement
		// se fait ensuite entre elles, parce que l effort de netlinking se decide
		// sur toute la boutique et pas langue par langue.
		$pages = [];
		foreach ( $props as $prop ) {
			$base = '/sites/' . rawurlencode( $prop ) . '/searchAnalytics/query';
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
					'url'    => $url,
					'clicks' => (float) ( $r['clicks'] ?? 0 ),
					'impr'   => (float) ( $r['impressions'] ?? 0 ),
					'ctr'    => (float) ( $r['ctr'] ?? 0 ),
					'pos'    => (float) ( $r['position'] ?? 0 ),
					// D OU ELLE VIENT : la propriete permet de renvoyer vers la
					// source dans Search Console, la langue de filtrer un catalogue
					// a la fois — cinq domaines veut dire cinq catalogues, et on
					// repare le maillage externe d une langue, pas des cinq.
					'prop'   => $prop,
					'lang'   => self::lang_of_url( $url ),
					'terms'  => [],
				];
			}
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
		}

		// 3. Le classement — separe de la lecture, parce qu une regle enfouie
		// dans un appel reseau ne s eprouve pas.
		$out = self::rank( $pages, self::sales_by_term( $days ), self::slug_map() );

		update_option( self::OPT_DATA, [
			'at'       => time(),
			'property' => implode( ', ', $props ),
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
		// LA FENETRE SE CHANGE ET SE RELIT DANS LE MEME GESTE : un reglage
		// enregistre qui ne prend effet qu a la lecture suivante est un reglage
		// dont on croit qu il n a rien fait.
		$days = isset( $_POST['days'] ) ? (int) $_POST['days'] : 0;
		if ( $days > 0 ) {
			$set = self::settings();
			$set['days'] = max( 7, min( 90, $days ) );
			update_option( self::OPT_SET, $set, false );
		}
		try {
			$done = self::refresh();
			delete_option( self::OPT_LAST_ERROR );
			wp_send_json_success( $done );
		} catch ( Throwable $e ) {
			// L ADRESSE EST SORTIE DU TEXTE pour pouvoir etre cliquee : une
			// consigne qui contient un lien qu il faut recopier a la main est une
			// consigne a moitie donnee.
			$msg = $e->getMessage();
			$url = '';
			if ( preg_match( '~https?://\S+~', $msg, $m ) ) {
				$url = rtrim( (string) $m[0], '.,);' );
				$msg = trim( str_replace( (string) $m[0], '', $msg ), " :\t\n" );
			}
			update_option( self::OPT_LAST_ERROR, [ 'at' => time(), 'why' => $e->getMessage() ], false );
			if ( class_exists( 'DZE_Health' ) ) {
				DZE_Health::log( 'searchconsole', __( 'Reading Search Console', 'dazont-ecom' ), $e->getMessage() );
			}
			wp_send_json_error( [ 'message' => $msg, 'url' => $url ] );
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

	/**
	 * CE QUE CET ECRAN EST, EN DEUX LIGNES ET PAS DIX.
	 *
	 * « Si un module est bien fait, il n'est pas nécessaire d'ajouter du texte
	 * partout. » Ce qui doit etre su pour s en servir tient au-dessus ; ce qui
	 * explique COMMENT les chiffres sont faits est replie sous le tableau, pour
	 * qui veut le verifier.
	 */
	private static function render_intro(): void {
		echo '<p class="description" style="max-width:900px;">';
		esc_html_e( 'Which of your pages a link from another site would lift, and which words that link should be made of. Read from Search Console once a day; nothing is written to the shop and nothing is paid for.', 'dazont-ecom' );
		echo '</p>';
	}
	private static function render_connect(): void {
		$me  = self::instance();
		$o   = self::client();
		$has = ! empty( $o['client_id'] ) && ! empty( $o['client_secret'] );
		$c   = self::connection();
		echo '<div class="dze-set" style="max-width:900px;padding:14px 18px;border:1px solid #dcdcde;background:#fff;border-radius:8px;">';

		if ( ! empty( $c['broken'] ) ) {
			echo '<div class="notice notice-warning inline" style="margin:0 0 12px;"><p><strong>'
				. esc_html__( 'The connection to Google came apart.', 'dazont-ecom' ) . '</strong> '
				. esc_html__( 'While the Google app is still in "Testing", Google drops the authorisation after seven days. Publishing the app stops that; connecting again brings it back until then.', 'dazont-ecom' )
				. ' <a href="' . esc_url( self::GOOGLE_CONSENT ) . '" target="_blank" rel="noopener">'
				. esc_html__( 'Publish the app', 'dazont-ecom' ) . ' ↗</a></p></div>';
		}

		if ( ! $has ) {
			echo '<p>' . esc_html__( 'This uses the same Google app as the Merchant Center, and that app is not set up yet. Create an OAuth client of type "Web application" in Google Cloud, then come back here.', 'dazont-ecom' ) . '</p>';
			echo '<p><a class="button" href="' . esc_url( self::GOOGLE_CREDENTIALS ) . '" target="_blank" rel="noopener">'
				. esc_html__( 'Open Google Cloud credentials', 'dazont-ecom' ) . ' ↗</a></p>';
			echo '</div>';
			return;
		}

		echo '<p>' . esc_html__( 'Read-only access: this can see your Search Console figures and can never change anything there.', 'dazont-ecom' ) . '</p>';

		echo '<p><strong>' . esc_html__( 'Two things to do at Google first, once.', 'dazont-ecom' ) . '</strong></p>';
		echo '<ol style="margin:0 0 14px 18px;">';

		// 1 — l adresse de retour, dans le bon client.
		echo '<li style="margin-bottom:10px;">';
		printf(
			/* translators: %s: link to the Google Cloud credentials page */
			esc_html__( 'Open %s, click the OAuth client below, and paste this address into "Authorised redirect URIs":', 'dazont-ecom' ),
			'<a href="' . esc_url( self::GOOGLE_CREDENTIALS ) . '" target="_blank" rel="noopener">'
				. esc_html__( 'Google Cloud → Credentials', 'dazont-ecom' ) . ' ↗</a>'
		);
		echo '<br /><code style="user-select:all;display:inline-block;margin:6px 0;padding:4px 8px;background:#f6f7f7;border:1px solid #dcdcde;border-radius:4px;">'
			. esc_html( $me->redirect_uri() ) . '</code>';
		// QUEL CLIENT : il y en a souvent plusieurs dans un projet, et se
		// tromper de ligne donne une erreur qui ne dit pas laquelle.
		echo '<br /><span class="description">' . esc_html__( 'The client to open is the one whose ID starts with:', 'dazont-ecom' ) . ' <code>'
			. esc_html( mb_substr( (string) $o['client_id'], 0, 24 ) ) . '…</code></span>';
		echo '</li>';

		// 2 — l API, qu il faut activer dans le projet.
		echo '<li style="margin-bottom:10px;">';
		printf(
			/* translators: %s: link to the API library page */
			esc_html__( 'Turn the Search Console API on in that same project: %s. Without it the connection succeeds and the first reading is refused.', 'dazont-ecom' ),
			'<a href="' . esc_url( self::GOOGLE_API ) . '" target="_blank" rel="noopener">'
				. esc_html__( 'Enable the Search Console API', 'dazont-ecom' ) . ' ↗</a>'
		);
		echo '</li>';
		echo '</ol>';

		echo '<p><a class="button button-primary" href="' . esc_url( $me->authorize_url() ) . '">'
			. esc_html__( 'Connect Search Console', 'dazont-ecom' ) . '</a></p>';

		// CE QUI SERA LU, dit avant de connecter : cinq domaines, cinq
		// proprietes, et c est le reglage de WPML qui l a decide.
		$doms = self::domains();
		if ( count( $doms ) > 1 ) {
			echo '<p class="description">';
			printf(
				/* translators: 1: how many domains, 2: the list of domains */
				esc_html__( 'WPML keeps this shop on %1$s domains, so Search Console holds one property for each and all %1$s are read: %2$s. Your Google account has to be able to see them.', 'dazont-ecom' ),
				esc_html( number_format_i18n( count( $doms ) ) ),
				esc_html( implode( ', ', $doms ) )
			);
			echo '</p>';
		}
		echo '</div>';
	}
	/**
	 * LE BOUTON ET SON ECOUTEUR NE SE SEPARENT PAS.
	 *
	 * « Il ne se passe rien. » Le script vivait a la FIN de la liste, apres le
	 * retour anticipe qui sert quand il n y a rien a montrer — donc le jour ou
	 * la liste etait vide, le bouton etait dessine et plus personne ne
	 * l ecoutait. Un clic sans effet et sans message, c est-a-dire le pire des
	 * deux mondes : celui ou l on croit que c est Google qui ne repond pas.
	 *
	 * Il est imprime avec le bouton, une fois, quoi qu il y ait dessous.
	 */
	private static function render_script(): void {
		?>
		<script>
		jQuery( function ( $ ) {
			function read( days ) {
				var $b = $( '#dze-nl-refresh' ).prop( 'disabled', true );
				var $s = $( '#dze-nl-state' ).text( <?php echo wp_json_encode( __( 'Reading Search Console…', 'dazont-ecom' ) ); ?> );
				var data = { action: 'dze_nl_refresh', nonce: <?php echo wp_json_encode( wp_create_nonce( self::NONCE ) ); ?> };
				if ( days ) { data.days = days; }
				$.post( ajaxurl, data )
					.done( function ( r ) {
						if ( !r || !r.success ) {
							$b.prop( 'disabled', false );
							var e = ( r && r.data ) ? r.data : {};
							$s.text( e.message || 'Error' );
							if ( e.url ) {
								$s.append( ' ' ).append( $( '<a/>', { href: e.url, text: e.url, target: '_blank', rel: 'noopener' } ) );
							}
							return;
						}
						window.location.reload();
					} )
					.fail( function () { $b.prop( 'disabled', false ); $s.text( 'Error' ); } );
			}
			$( '#dze-nl-refresh' ).on( 'click', function () { read( 0 ); } );
			// CHANGER LA FENETRE RELIT DANS LA FOULEE : un reglage enregistre qui
			// ne prend effet qu a la lecture suivante est un reglage dont on
			// croit qu il n a rien fait.
			$( '#dze-nl-days' ).on( 'change', function () { read( $( this ).val() ); } );
		} );
		</script>
		<?php
	}

	/** Une pastille, du meme dessin que celles des autres ecrans. */
	private static function chip( string $icon, string $text, string $title = '' ): string {
		return sprintf(
			'<span class="dze-setup-chip" title="%3$s" style="display:inline-flex;align-items:center;gap:5px;margin-right:6px;padding:2px 9px;border:1px solid #dcdcde;border-radius:11px;font-size:12px;background:#fff;"><span class="dashicons dashicons-%1$s" style="font-size:14px;width:14px;height:14px;"></span>%2$s</span>',
			esc_attr( $icon ),
			esc_html( $text ),
			esc_attr( $title )
		);
	}

	/**
	 * LES CHIFFRES DE TETE : ce qu il y a, sur quoi, et depuis quand.
	 *
	 * Une pastille se tait quand elle n a rien a dire — « 0 » se lit comme un
	 * probleme la ou il n y a qu une absence.
	 */
	private static function render_chips( array $d, array $rows ): void {
		$out = '';
		if ( $rows ) {
			$out .= self::chip( 'admin-links', sprintf(
				/* translators: %s: how many pages */
				_n( '%s page worth a link', '%s pages worth a link', count( $rows ), 'dazont-ecom' ),
				number_format_i18n( count( $rows ) )
			) );
		}
		if ( ! empty( $d['seen'] ) ) {
			$out .= self::chip( 'visibility', sprintf(
				/* translators: %s: how many pages were read */
				__( '%s pages read', 'dazont-ecom' ),
				number_format_i18n( (int) $d['seen'] )
			), __( 'Every page Search Console knows about, across every property.', 'dazont-ecom' ) );
		}
		$props = array_filter( array_map( 'trim', explode( ',', (string) ( $d['property'] ?? '' ) ) ) );
		if ( $props ) {
			$out .= self::chip( 'admin-site', sprintf(
				/* translators: %s: how many Search Console properties */
				_n( '%s property', '%s properties', count( $props ), 'dazont-ecom' ),
				number_format_i18n( count( $props ) )
			), implode( "\n", $props ) );
		}
		if ( ! empty( $d['at'] ) ) {
			$out .= self::chip( 'clock', sprintf(
				/* translators: %s: how long ago */
				__( 'read %s ago', 'dazont-ecom' ),
				human_time_diff( (int) $d['at'], time() )
			), sprintf(
				/* translators: 1: first day read, 2: last day read */
				__( 'Covering %1$s to %2$s.', 'dazont-ecom' ),
				(string) ( $d['from'] ?? '' ),
				(string) ( $d['to'] ?? '' )
			) );
		}
		// LA PROCHAINE, parce qu un module qui tourne seul doit dire quand.
		$next = wp_next_scheduled( self::HOOK );
		if ( $next ) {
			$out .= self::chip( 'update', sprintf(
				/* translators: %s: how long until the next reading */
				__( 'next in %s', 'dazont-ecom' ),
				human_time_diff( time(), (int) $next )
			) );
		}
		if ( '' !== $out ) {
			echo '<p style="margin:10px 0 4px;">' . wp_kses_post( $out ) . '</p>';
		}
	}

	/**
	 * CE QUI A RATE, DIT AVANT LE RESTE.
	 *
	 * Une lecture automatique qui echoue laissait l ecran dire « pas encore
	 * lu » — la meme phrase que le premier jour, donc un echec qui ressemble a
	 * un debut.
	 */
	private static function render_error(): void {
		$bad = self::last_error();
		if ( empty( $bad['why'] ) ) {
			return;
		}
		$why = (string) $bad['why'];
		$url = '';
		if ( preg_match( '~https?://\S+~', $why, $m ) ) {
			$url = rtrim( (string) $m[0], '.,);' );
			$why = trim( str_replace( (string) $m[0], '', $why ), " :\t\n" );
		}
		echo '<div class="notice notice-error inline" style="margin:12px 0;"><p><strong>';
		printf(
			/* translators: %s: how long ago */
			esc_html__( 'The last reading failed, %s ago.', 'dazont-ecom' ),
			esc_html( human_time_diff( (int) ( $bad['at'] ?? time() ), time() ) )
		);
		echo '</strong> ' . esc_html( $why );
		if ( '' !== $url ) {
			echo ' <a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . esc_html__( 'Fix it at Google', 'dazont-ecom' ) . ' ↗</a>';
		}
		echo '</p></div>';
	}

	/**
	 * UNE LANGUE A LA FOIS, QUAND IL Y EN A PLUSIEURS.
	 *
	 * Cinq domaines veut dire cinq catalogues, et un classement qui les melange
	 * ne se travaille pas : on repare le maillage externe d une langue, pas de
	 * la boutique entiere. Le filtre est DANS L ADRESSE, donc une vue filtree
	 * est un signet — la barre des Commentaires de WordPress, en plus petit.
	 *
	 * @param array<int,array<string,mixed>> $all
	 */
	private static function render_rail( array $all, string $now ): void {
		$counts = [];
		foreach ( $all as $r ) {
			$code = (string) ( $r['lang'] ?? '' );
			if ( '' !== $code ) {
				$counts[ $code ] = ( $counts[ $code ] ?? 0 ) + 1;
			}
		}
		if ( count( $counts ) < 2 ) {
			return; // une langue n est pas un choix.
		}
		arsort( $counts );
		$base = self::page_url();
		$out  = [ sprintf(
			'<a href="%1$s"%2$s>%3$s <span class="count">(%4$s)</span></a>',
			esc_url( $base ),
			'' === $now ? ' class="current"' : '',
			esc_html__( 'All', 'dazont-ecom' ),
			esc_html( number_format_i18n( count( $all ) ) )
		) ];
		foreach ( $counts as $code => $n ) {
			$out[] = sprintf(
				'<a href="%1$s"%2$s>%3$s <span class="count">(%4$s)</span></a>',
				esc_url( add_query_arg( 'lang', $code, $base ) ),
				$now === $code ? ' class="current"' : '',
				esc_html( strtoupper( (string) $code ) ),
				esc_html( number_format_i18n( $n ) )
			);
		}
		echo '<ul class="subsubsub" style="float:none;margin:0 0 10px;"><li>' . wp_kses_post( implode( ' |</li><li>', $out ) ) . '</li></ul>';
	}

	/** Le lien vers cette page DANS Search Console, pour aller voir la source. */
	private static function gsc_url( string $page_url, string $property ): string {
		if ( '' === $property ) {
			return '';
		}
		return add_query_arg( [
			'resource_id' => $property,
			'page'        => '!' . $page_url,
		], 'https://search.google.com/search-console/performance/search-analytics' );
	}

	private static function render_targets(): void {
		$d    = self::data();
		$all  = (array) ( $d['rows'] ?? [] );
		$me   = self::instance();

		self::render_error();
		self::render_chips( $d, $all );

		// LA BARRE D ACTIONS : relire, sur quelle duree, et s en aller.
		echo '<p class="dze-cb-actions" style="max-width:1100px;">';
		echo '<button type="button" class="button" id="dze-nl-refresh">' . esc_html__( 'Read it again now', 'dazont-ecom' ) . '</button>';
		echo '<label style="display:inline-flex;align-items:center;gap:6px;"><span class="description">' . esc_html__( 'over', 'dazont-ecom' ) . '</span>';
		echo '<select id="dze-nl-days">';
		foreach ( [ 7, 28, 90 ] as $dze_days ) {
			printf(
				'<option value="%1$d"%2$s>%3$s</option>',
				(int) $dze_days,
				selected( $dze_days, self::window(), false ),
				esc_html( sprintf(
					/* translators: %s: a number of days */
					_n( '%s day', '%s days', $dze_days, 'dazont-ecom' ),
					number_format_i18n( $dze_days )
				) )
			);
		}
		echo '</select></label>';
		echo '<span class="description" id="dze-nl-state">';
		if ( empty( $d['at'] ) ) {
			esc_html_e( 'Not read yet — it reads itself once a day, or press the button.', 'dazont-ecom' );
		}
		echo '</span>';
		echo '<a class="button" style="margin-left:auto;" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=dze_nl_disconnect' ), 'dze_nl_disconnect' ) ) . '">' . esc_html__( 'Disconnect', 'dazont-ecom' ) . '</a>';
		echo '</p>';
		// AVANT TOUT RETOUR ANTICIPE : le bouton vient d etre dessine.
		self::render_script();

		if ( ! $all ) {
			echo '<p class="description">' . ( empty( $d['at'] )
				? esc_html__( 'Nothing read yet.', 'dazont-ecom' )
				: esc_html__( 'Nothing is within reach right now: no page sits between the fourth and the thirtieth place with enough impressions behind it. That is an answer, not a fault.', 'dazont-ecom' ) ) . '</p>';
			self::render_notes();
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- navigation only.
		$want = isset( $_GET['lang'] ) ? sanitize_key( wp_unslash( (string) $_GET['lang'] ) ) : '';
		self::render_rail( $all, $want );
		$rows = '' === $want
			? $all
			: array_values( array_filter( $all, static fn( $r ) => (string) ( $r['lang'] ?? '' ) === $want ) );

		$props = array_filter( array_map( 'trim', explode( ',', (string) ( $d['property'] ?? '' ) ) ) );
		$multi = count( $props ) > 1;

		echo '<table class="widefat striped" style="max-width:1200px;"><thead><tr>';
		echo '<th>' . esc_html__( 'Page', 'dazont-ecom' ) . '</th>';
		if ( $multi ) {
			echo '<th style="width:70px;">' . esc_html__( 'Lang', 'dazont-ecom' ) . '</th>';
		}
		echo '<th style="width:80px;">' . esc_html__( 'Position', 'dazont-ecom' ) . '</th>';
		echo '<th style="width:95px;">' . esc_html__( 'Impressions', 'dazont-ecom' ) . '</th>';
		echo '<th style="width:70px;">' . esc_html__( 'Clicks', 'dazont-ecom' ) . '</th>';
		echo '<th style="width:85px;" title="' . esc_attr__( 'What this category sold over the same window, in this language.', 'dazont-ecom' ) . '">' . esc_html__( 'Units sold', 'dazont-ecom' ) . '</th>';
		echo '<th style="width:105px;" title="' . esc_attr__( 'Estimated: what the page would do at about fifth place, against what it does now.', 'dazont-ecom' ) . '">' . esc_html__( 'Clicks to gain', 'dazont-ecom' ) . '</th>';
		echo '<th style="width:80px;" title="' . esc_attr__( 'No unit, and no prediction: the traffic to gain, weighted by whether the category sells.', 'dazont-ecom' ) . '">' . esc_html__( 'Priority', 'dazont-ecom' ) . '</th>';
		echo '<th>' . esc_html__( 'Words to link it with', 'dazont-ecom' ) . '</th>';
		echo '</tr></thead><tbody>';
		foreach ( $rows as $r ) {
			$url  = (string) ( $r['url'] ?? '' );
			$lang = (string) ( $r['lang'] ?? '' );
			echo '<tr>';
			echo '<td><a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . esc_html( self::short( $url ) ) . '</a>';
			// LA SOURCE DU CHIFFRE, A UN CLIC : ce tableau resume Search Console,
			// il ne la remplace pas, et une page qui surprend se va se verifier.
			$gsc = self::gsc_url( $url, (string) ( $r['prop'] ?? '' ) );
			if ( '' !== $gsc ) {
				echo ' <a href="' . esc_url( $gsc ) . '" target="_blank" rel="noopener" class="description" title="'
					. esc_attr__( 'Open this page in Search Console', 'dazont-ecom' ) . '">↗</a>';
			}
			echo '</td>';
			if ( $multi ) {
				echo '<td>' . ( '' !== $lang
					? ( class_exists( 'DZE_Wpml' ) && method_exists( 'DZE_Wpml', 'flag_html' )
						? wp_kses_post( DZE_Wpml::flag_html( $lang ) )
						: esc_html( strtoupper( $lang ) ) )
					: '<span class="description">—</span>' ) . '</td>';
			}
			echo '<td>' . esc_html( number_format_i18n( (float) ( $r['pos'] ?? 0 ), 1 ) ) . '</td>';
			echo '<td>' . esc_html( number_format_i18n( (int) ( $r['impr'] ?? 0 ) ) ) . '</td>';
			echo '<td>' . esc_html( number_format_i18n( (int) ( $r['clicks'] ?? 0 ) ) ) . '</td>';
			// CE QUE LA CATEGORIE A VENDU. Un tiret, et non un zero, quand la page
			// n est pas une categorie : un article n a pas vendu zero, il ne vend
			// pas, et les deux ne se lisent pas pareil.
			$tid = (int) ( $r['tid'] ?? 0 );
			echo '<td>' . ( $tid ? esc_html( number_format_i18n( (int) ( $r['units'] ?? 0 ) ) ) : '<span class="description">—</span>' ) . '</td>';
			echo '<td><strong>+' . esc_html( number_format_i18n( (int) round( (float) ( $r['gain'] ?? 0 ) ) ) ) . '</strong></td>';
			// UN RANG, PAS UNE PROMESSE : pas de signe +, pas d unite.
			$worth = (float) ( $r['worth'] ?? 0 );
			echo '<td>' . ( $worth > 0
				? '<strong>' . esc_html( number_format_i18n( (int) round( $worth ) ) ) . '</strong>'
				: '<span class="description">—</span>' ) . '</td>';
			echo '<td>';
			foreach ( (array) ( $r['terms'] ?? [] ) as $t ) {
				echo '<span style="display:inline-block;margin:0 4px 4px 0;padding:1px 7px;border:1px solid #dcdcde;border-radius:10px;font-size:12px;">' . esc_html( (string) ( $t['q'] ?? '' ) ) . '</span>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
		self::render_notes();
	}

	/**
	 * COMMENT CES CHIFFRES SONT FAITS — replie, pour qui veut verifier.
	 *
	 * Cinq phrases sous un tableau sont cinq phrases que personne ne lit et qui
	 * poussent le travail hors de l ecran. Elles restent, sous un pli.
	 */
	private static function render_notes(): void {
		echo '<details class="dze-set" style="max-width:1100px;margin-top:14px;"><summary>'
			. esc_html__( 'How these figures are made', 'dazont-ecom' ) . '</summary>';
		echo '<p class="description">';
		esc_html_e( 'Impressions, clicks and position are what Google measured. Units are what WooCommerce recorded for that category over the same window.', 'dazont-ecom' );
		echo '</p><p class="description">';
		esc_html_e( 'Each language counts its OWN sales, and the language is the one of the product sold — translated products are filed in the original\'s categories here, so counting by the category\'s language would return nought for four languages out of five.', 'dazont-ecom' );
		echo '</p><p class="description">';
		esc_html_e( '"Clicks to gain" is an estimate: what the page would do at about fifth place, against what it does now. "Priority" has no unit and predicts nothing — it is that traffic weighted by whether the category sells at all. It deliberately does NOT multiply clicks by units-per-click: sales come from every source while these clicks are Google\'s alone, and that sum promised 171 units for 17 clicks.', 'dazont-ecom' );
		echo '</p><p class="description">';
		esc_html_e( 'It counts units and never money: this shop takes orders in eight currencies or more and the analytics table keeps each in its own, so adding them would mean nothing.', 'dazont-ecom' );
		echo '</p><p class="description"><strong>';
		esc_html_e( 'What Search Console does not give:', 'dazont-ecom' );
		echo '</strong> ';
		esc_html_e( 'its API has no backlinks — the Links report exists on screen and nowhere else. So this screen never claims to list the links you already have; it says where a new one would pay.', 'dazont-ecom' );
		echo '</p></details>';
	}
	/** Une adresse lisible : le chemin, pas le domaine repete cinquante fois. */
	private static function short( string $url ): string {
		$p = (string) wp_parse_url( $url, PHP_URL_PATH );
		return '' === $p || '/' === $p ? $url : trim( $p, '/' );
	}
}
