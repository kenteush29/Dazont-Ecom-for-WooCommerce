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
 * LES CATEGORIES, ET RIEN D AUTRE.
 *
 * « Tu as intégré des recommandations de liens au niveau produit, ce qui est
 * faux. […] Mieux vaut rester sur la data des produits remontée au niveau des
 * catégories avec les ventes WooCommerce. Et data Google mise en relation
 * seulement niveau catégories. »
 *
 * Une cible de lien externe est une page de categorie. Google est lu pour
 * l adresse de la categorie elle-meme, jamais pour ses fiches produit ; les
 * ventes, elles, sont celles des produits ranges dans la categorie, comptees
 * dans la langue du produit vendu. Une fiche produit, un article ou une page
 * n apparaissent donc plus du tout.
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
			/* translators: 1: how many categories, 2: how long ago */
			'message' => sprintf( __( '%1$s categories worth a link, read %2$s ago.', 'dazont-ecom' ), number_format_i18n( self::targets_count() ), human_time_diff( (int) $d['at'], time() ) ),
		];
	}

	/**
	 * COMBIEN DE CATEGORIES MERITENT UN LIEN AUJOURD HUI — celles « a portee »,
	 * pas toutes celles que la lecture a vues. L accueil et le journal de sante
	 * disent ce chiffre-la : c est le travail, le reste est le decor.
	 */
	public static function targets_count(): int {
		$n = 0;
		foreach ( (array) ( self::data()['rows'] ?? [] ) as $r ) {
			if ( 'reach' === (string) ( $r['status'] ?? '' ) ) {
				$n++;
			}
		}
		return $n;
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

	/** Combien de pages on lit, et combien de requetes on garde par categorie. */
	private const MAX_PAGES   = 5000;
	private const MAX_ROWS    = 25000;
	private const KEEP_ANCHOR = 5;
	/** Combien de lignes par page du tableau. */
	private const PER_PAGE = 50;
	/** Le plancher d impressions sous lequel un gain calcule ne veut rien dire. */
	private const MIN_IMPR = 20;
	/**
	 * CE QU UN CLIC RAPPORTE NE SE JUGE PAS SUR QUATRE CLICS. Le chiffre
	 * d affaires d une categorie vient de partout ; divise par quatre clics
	 * Google, une seule grosse commande en fait 500 $ le clic. La priorite le
	 * compte sur au moins autant de clics que ceci.
	 */
	public const SMOOTH_CLICKS = 20;

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
	 * CE QUI N EST PAS UNE VENTE : ce que WooCommerce Analytics ecarte de lui-
	 * meme (en attente de paiement, echouee, annulee), la corbeille et les
	 * brouillons. Tout le reste compte — y compris les statuts qu une boutique
	 * ajoute : sur Kula, « Shipped » porte a lui seul 659 lignes en 90 jours,
	 * et une liste blanche l aurait jete.
	 */
	private const NOT_SOLD = [ 'wc-pending', 'wc-failed', 'wc-cancelled', 'wc-checkout-draft', 'pending', 'failed', 'cancelled', 'checkout-draft', 'trash', 'draft', 'auto-draft' ];

	/**
	 * LE CHIFFRE D AFFAIRES, RAMENE A LA DEVISE DE LA BOUTIQUE.
	 *
	 * « Il faudrait quantité d'articles vendus et chiffre d'affaire. »
	 *
	 * La table d analyse garde chaque ligne dans la devise de SA commande, et
	 * cette boutique en encaisse vingt-cinq. Dans l ordre :
	 *   1. le taux que WooPayments a garde sur la commande le jour du paiement
	 *      (`_wcpay_multi_currency_stripe_exchange_rate`, devise de la commande
	 *      vers celle de la boutique) — le vrai ;
	 *   2. sinon, le taux courant de WooCommerce Multilingual (une unite de la
	 *      boutique vaut `rate` unites de la devise) — ce qui s en approche ;
	 *   3. sinon rien : une somme qu on ne sait pas convertir n est pas
	 *      additionnee. La version d avant la comptait telle quelle, un pour un :
	 *      des lires turques et des pesos argentins passaient pour des dollars.
	 *
	 * @param array<string,float> $wcml devise => taux WCML.
	 * @return float|null null quand la somme ne peut pas etre convertie.
	 */
	public static function to_shop_currency( float $net, string $cur, float $rate, string $shop, array $wcml ): ?float {
		if ( $rate > 0 ) {
			return $net * $rate;
		}
		if ( '' === $cur || $cur === $shop ) {
			return $net;
		}
		$r = (float) ( $wcml[ $cur ] ?? 0 );
		return $r > 0 ? $net / $r : null;
	}

	/** Les taux courants de WooCommerce Multilingual, devise => taux. */
	private static function wcml_rates(): array {
		$s   = get_option( '_wcml_settings', [] );
		$out = [];
		foreach ( (array) ( is_array( $s ) ? ( $s['currency_options'] ?? [] ) : [] ) as $code => $o ) {
			$r = (float) ( is_array( $o ) ? ( $o['rate'] ?? 0 ) : 0 );
			if ( $r > 0 ) {
				$out[ (string) $code ] = $r;
			}
		}
		return $out;
	}

	/**
	 * CE QUE CHAQUE COMMANDE EST : si elle existe encore, si c est une vente,
	 * dans quelle devise et a quel taux.
	 *
	 * LA TABLE D ANALYSE GARDE LES LIGNES DES COMMANDES SUPPRIMEES. Mesure sur
	 * Kula : 28 lignes sur 1 529 en 90 jours appartenaient a des commandes qui
	 * n existent plus, et pesaient 1,48 million — une categorie de vestes a
	 * cinq ventes affichait 674 102 de chiffre d affaires. Une commande
	 * introuvable ne compte pas.
	 *
	 * UN REMBOURSEMENT COMPTE CE QUE COMPTE SA COMMANDE : ses lignes sont
	 * negatives, elles se deduisent quand la commande est une vente, et il prend
	 * la devise et le taux de sa commande s il n a pas les siens.
	 *
	 * @param int[] $ids
	 * @return array<int,array{ok:bool,cur:string,rate:float}>
	 */
	private static function order_facts( array $ids ): array {
		global $wpdb;
		$ids = array_values( array_filter( array_map( 'intval', array_unique( $ids ) ) ) );
		if ( ! $ids || ! $wpdb ) {
			return [];
		}
		$hpos = 'yes' === get_option( 'woocommerce_custom_orders_table_enabled' )
			&& $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'wc_orders' ) ) === $wpdb->prefix . 'wc_orders';
		$read = static function ( array $chunk ) use ( $wpdb, $hpos ): array {
			$in = implode( ',', array_map( 'intval', $chunk ) );
			// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integers only, joined above.
			if ( $hpos ) {
				$o = $wpdb->prefix . 'wc_orders';
				$m = $wpdb->prefix . 'wc_orders_meta';
				$sql = "SELECT o.id AS id, o.type AS type, o.status AS status, o.parent_order_id AS parent, o.currency AS cur,
				               ( SELECT meta_value FROM {$m} WHERE order_id = o.id AND meta_key = '_wcpay_multi_currency_stripe_exchange_rate' LIMIT 1 ) AS rate
				          FROM {$o} o WHERE o.id IN ( {$in} )";
			} else {
				$sql = "SELECT p.ID AS id, p.post_type AS type, p.post_status AS status, p.post_parent AS parent,
				               MAX( CASE WHEN pm.meta_key = '_order_currency' THEN pm.meta_value END ) AS cur,
				               MAX( CASE WHEN pm.meta_key = '_wcpay_multi_currency_stripe_exchange_rate' THEN pm.meta_value END ) AS rate
				          FROM {$wpdb->posts} p
				          LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key IN ( '_order_currency', '_wcpay_multi_currency_stripe_exchange_rate' )
				         WHERE p.ID IN ( {$in} ) GROUP BY p.ID";
			}
			// phpcs:enable
			$out = [];
			foreach ( (array) $wpdb->get_results( $sql, ARRAY_A ) as $r ) {
				$out[ (int) $r['id'] ] = $r;
			}
			return $out;
		};
		$raw = [];
		foreach ( array_chunk( $ids, 500 ) as $chunk ) {
			$raw += $read( $chunk );
		}
		// Les commandes des remboursements, lues a leur tour.
		$parents = [];
		foreach ( $raw as $r ) {
			if ( 'shop_order_refund' === (string) $r['type'] && (int) $r['parent'] > 0 && ! isset( $raw[ (int) $r['parent'] ] ) ) {
				$parents[] = (int) $r['parent'];
			}
		}
		foreach ( array_chunk( array_values( array_unique( $parents ) ), 500 ) as $chunk ) {
			$raw += $read( $chunk );
		}
		$out = [];
		foreach ( $ids as $id ) {
			$r = $raw[ $id ] ?? null;
			if ( ! $r ) {
				$out[ $id ] = [ 'ok' => false, 'cur' => '', 'rate' => 0.0 ];
				continue;
			}
			$sale = $r;
			if ( 'shop_order_refund' === (string) $r['type'] ) {
				$sale = $raw[ (int) $r['parent'] ] ?? null;
			}
			$ok = null !== $sale && ! in_array( (string) $sale['status'], self::NOT_SOLD, true );
			$cur  = (string) ( $r['cur'] ?? '' );
			$rate = (float) ( $r['rate'] ?? 0 );
			if ( null !== $sale && $sale !== $r ) {
				$cur  = '' !== $cur ? $cur : (string) ( $sale['cur'] ?? '' );
				$rate = $rate > 0 ? $rate : (float) ( $sale['rate'] ?? 0 );
			}
			$out[ $id ] = [ 'ok' => $ok, 'cur' => $cur, 'rate' => $rate ];
		}
		return $out;
	}

	/**
	 * CE QUE CHAQUE CATEGORIE A VENDU, dans chaque langue.
	 *
	 * « La data GSC doit être recroisée avec les ventes au niveau des
	 * catégories produits. » Sans cela le module classe une categorie a 4 000
	 * impressions qui ne vend rien au-dessus d une a 800 qui vend : du trafic
	 * pour du trafic, ce qui n est pas le metier de cette boutique.
	 *
	 * CE SONT LES VENTES DES PRODUITS RANGES DANS LA CATEGORIE, jamais leurs
	 * clics : « si 10 clics sur les produits de la catégorie x, compter +10
	 * clics sur la catégorie […] c'est trop farfelu. » Google est lu pour la
	 * categorie elle-meme, WooCommerce pour ce que ses produits ont vendu.
	 *
	 * CHAQUE LANGUE COMPTE SES PROPRES VENTES. « Les ventes sont comptabilisées
	 * seulement sur la langue concernée. » Un zero sur la page allemande EST
	 * l information — ce catalogue ne vend pas encore.
	 *
	 * LA LANGUE EST CELLE DU PRODUIT VENDU, jamais celle de sa categorie. Sur ce
	 * catalogue les produits traduits sont ranges dans les categories
	 * ANGLAISES : compter par la langue de la categorie rendrait zero pour
	 * quatre langues sur cinq. On prend donc la categorie du produit et on la
	 * ramene dans SA langue par le groupe de traduction.
	 *
	 * La table de WooCommerce Analytics est la source ; absente, on rend un
	 * tableau vide et le module continue sans les ventes plutot que de tomber.
	 *
	 * @return array<int,array{units:int,revenue:float}> par term_id
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
		// UNE LIGNE PAR COMMANDE ET PAR CATEGORIE : la conversion et le tri des
		// commandes se font ensuite, commande par commande.
		$rows = (array) $wpdb->get_results( $wpdb->prepare(
			$wpml
				? "SELECT l.order_id AS oid, ct.trid AS trid, pl.language_code AS lang, SUM( l.product_qty ) AS units, SUM( l.product_net_revenue ) AS net
				     FROM {$lookup} l
				     INNER JOIN {$icl} pl ON pl.element_id = l.product_id AND pl.element_type = 'post_product'
				     INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = l.product_id
				     INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
				     INNER JOIN {$icl} ct ON ct.element_id = tt.term_taxonomy_id AND ct.element_type = 'tax_product_cat'
				    WHERE tt.taxonomy = 'product_cat'
				      AND l.date_created > DATE_SUB( NOW(), INTERVAL %d DAY )
				    GROUP BY l.order_id, ct.trid, pl.language_code"
				: "SELECT l.order_id AS oid, tt.term_id AS tid, '' AS lang, SUM( l.product_qty ) AS units, SUM( l.product_net_revenue ) AS net
				     FROM {$lookup} l
				     INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = l.product_id
				     INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
				    WHERE tt.taxonomy = 'product_cat'
				      AND l.date_created > DATE_SUB( NOW(), INTERVAL %d DAY )
				    GROUP BY l.order_id, tt.term_id",
			max( 1, $days )
		), ARRAY_A );
		// Le terme de chaque groupe, langue par langue.
		$members = [];
		if ( $wpml ) {
			foreach ( (array) $wpdb->get_results(
				"SELECT ic.trid, ic.language_code AS lang, tt.term_id AS tid
				   FROM {$icl} ic
				   INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = ic.element_id
				  WHERE ic.element_type = 'tax_product_cat'",
				ARRAY_A
			) as $m ) {
				$members[ (int) $m['trid'] ][ (string) $m['lang'] ] = (int) $m['tid'];
			}
		}
		// phpcs:enable
		$facts = self::order_facts( array_map( static fn( $r ) => (int) $r['oid'], $rows ) );
		$shop  = (string) get_option( 'woocommerce_currency', '' );
		$wcml  = self::wcml_rates();
		$out   = [];
		foreach ( $rows as $r ) {
			$f = $facts[ (int) $r['oid'] ] ?? null;
			if ( ! $f || empty( $f['ok'] ) ) {
				continue; // commande disparue, ou qui n est pas une vente.
			}
			if ( $wpml ) {
				// LA CATEGORIE DE CETTE LANGUE, ou rien : inventer une
				// attribution est pire que de se taire.
				$tid = (int) ( $members[ (int) $r['trid'] ][ (string) $r['lang'] ] ?? 0 );
			} else {
				$tid = (int) $r['tid'];
			}
			if ( ! $tid ) {
				continue;
			}
			$out[ $tid ]['units']   = (int) ( $out[ $tid ]['units'] ?? 0 ) + (int) $r['units'];
			$out[ $tid ]['revenue'] = (float) ( $out[ $tid ]['revenue'] ?? 0.0 );
			$money = self::to_shop_currency( (float) $r['net'], (string) $f['cur'], (float) $f['rate'], $shop, $wcml );
			if ( null !== $money ) {
				$out[ $tid ]['revenue'] += $money;
			}
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
		// L ADRESSE ENREGISTREE, PAS home_url() : WPML la reecrit dans la langue
		// de la requete. La lecture tourne en cron, declenche par une visite sur
		// n importe quel domaine ; sur le domaine francais, home_url() rendait
		// kula-tactical.fr, deja dans la liste, et le domaine principal n y
		// entrait plus — les pages anglaises perdaient leur langue.
		$home = (string) wp_parse_url( (string) get_option( 'home' ), PHP_URL_HOST );
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
		// LA TABLE DE WPML, PAS SON SÉLECTEUR : `wpml_active_languages` perd le
		// russe dès que la requête n'est pas anglaise (voir
		// DZE_Wpml::get_active_languages()).
		$out = class_exists( 'DZE_Wpml' ) ? DZE_Wpml::language_codes() : [];
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
	 * UNE ADRESSE EST A LA LANGUE DE SON DOMAINE, JAMAIS A UNE AUTRE. « Non ce
	 * n'est pas espagnol. L'espagnol est sur un autre domaine. » Google garde
	 * les anciennes adresses : kula-tactical.es/sniper-veil est le slug anglais
	 * sur le domaine espagnol, d avant que la categorie y ait le sien, et elle
	 * redirige aujourd hui vers kula-tactical.es/velos-de-camuflaje-para-
	 * francotiradores. Le slug seul la donnait a la categorie ANGLAISE : ses
	 * chiffres s ajoutaient a ceux de kula-tactical.com/sniper-veil, et la ligne
	 * prenait la langue de la page lue la premiere — « ES » sur une adresse
	 * .com. Vingt lignes sur 595 l etaient. Le slug d une autre langue mene
	 * maintenant a la traduction de la categorie dans la langue de l adresse
	 * (meme groupe WPML), ou a rien quand il n y en a pas ; le slug seul ne sert
	 * plus que quand la langue de l adresse est inconnue, ou que WPML ne connait
	 * pas le terme.
	 *
	 * Rend 0 quand l adresse n est pas une categorie — un article, une page, un
	 * produit — et c est une reponse, pas un echec.
	 */
	public static function term_of_url( string $url, array $slug_map ): int {
		$slug = self::url_slug( $url );
		if ( '' === $slug ) {
			return 0;
		}
		$lang = self::lang_of_url( $url );
		if ( '' === $lang ) {
			return (int) ( $slug_map[ '|' . $slug ] ?? 0 );
		}
		if ( isset( $slug_map[ $lang . '|' . $slug ] ) ) {
			return (int) $slug_map[ $lang . '|' . $slug ];
		}
		$other = (int) ( $slug_map[ '|' . $slug ] ?? 0 );
		$trid  = $other ? (int) ( $slug_map[ '#' . $other ] ?? 0 ) : 0;
		if ( $trid <= 0 ) {
			return $other;
		}
		return (int) ( $slug_map[ '@' . $trid . '|' . $lang ] ?? 0 );
	}

	/** Le dernier morceau du chemin d une adresse : son slug, ou rien. */
	private static function url_slug( string $url ): string {
		$path = trim( (string) wp_parse_url( $url, PHP_URL_PATH ), '/' );
		if ( '' === $path ) {
			return '';
		}
		$bits = explode( '/', $path );
		return (string) end( $bits );
	}

	/** L adresse porte-t-elle le slug d aujourd hui de cette categorie, dans sa langue ? */
	private static function is_live_url( string $url, int $tid, array $slug_map ): bool {
		$slug = self::url_slug( $url );
		return '' !== $slug && $tid === (int) ( $slug_map[ self::lang_of_url( $url ) . '|' . $slug ] ?? -1 );
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
	 *
	 * Deux clefs encore, pour l adresse d une langue qui porte le slug d une
	 * autre (term_of_url()) : « #term_id » donne son groupe de traduction WPML,
	 * « @groupe|langue » le terme de ce groupe dans cette langue.
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
				? "SELECT t.term_id AS tid, t.slug AS slug, ic.language_code AS lang, COALESCE( ic.trid, 0 ) AS trid
				     FROM {$wpdb->terms} t
				     INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id
				     LEFT JOIN {$icl} ic ON ic.element_id = tt.term_taxonomy_id AND ic.element_type = 'tax_product_cat'
				    WHERE tt.taxonomy = 'product_cat'"
				: "SELECT t.term_id AS tid, t.slug AS slug, '' AS lang, 0 AS trid
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
			$trid = (int) ( $r['trid'] ?? 0 );
			if ( '' !== $lang ) {
				$out[ $lang . '|' . $slug ] = $tid;
				if ( $trid > 0 ) {
					$out[ '#' . $tid ]                = $trid;
					$out[ '@' . $trid . '|' . $lang ] = $tid;
				}
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
	 * UNE LIGNE PAR CATEGORIE. Google rend des adresses, et une categorie peut
	 * en avoir plusieurs (avec ou sans barre finale, un ancien slug) : elles
	 * s additionnent, et la position est la moyenne ponderee par les
	 * impressions, comme Search Console la calcule. Une adresse qui n est pas
	 * une categorie — fiche produit, article, page — est ignoree : ni cible, ni
	 * chiffre.
	 *
	 * Une categorie que Google n a montree a personne mais qui VEND a sa ligne
	 * aussi : c est une information, et souvent la plus utile.
	 *
	 * CE QUE DIT LE STATUT :
	 *   reach   un lien la ferait monter : au-dela de la 3e place, en deca de
	 *           la 30e, assez d impressions pour qu un gain veuille dire
	 *           quelque chose, et un taux de clic sous celui de la 5e place ;
	 *   strong  deja dans les trois premiers, ou deja au taux de clic que la
	 *           5e place donnerait ;
	 *   unseen  vend, et Google ne l a montree a personne sur la periode ;
	 *   far     au-dela de la 30e place, ou trop peu vue pour en juger ;
	 *   skip    jamais une cible : noindex, vide, ou la categorie par defaut.
	 *
	 * @param array<string,array<string,mixed>> $pages    Ce que Search Console rend, par adresse.
	 * @param array<int,array<string,mixed>>    $sales    Ventes par categorie (sales_by_term()).
	 * @param array<string,int>                 $slug_map « langue|slug » => term_id (slug_map()).
	 * @param array<int,array<string,mixed>>    $meta     Ce que la boutique sait de chaque categorie (term_meta()).
	 * @return array<int,array<string,mixed>> Les categories, a portee d abord et par priorite.
	 */
	public static function rank( array $pages, array $sales = [], array $slug_map = [], array $meta = [] ): array {
		$by = [];
		foreach ( $pages as $p ) {
			$url = (string) ( $p['url'] ?? '' );
			$tid = $slug_map ? self::term_of_url( $url, $slug_map ) : 0;
			if ( ! $tid ) {
				continue;
			}
			$impr = (float) ( $p['impr'] ?? 0 );
			if ( ! isset( $by[ $tid ] ) ) {
				$by[ $tid ] = [ 'url' => $url, 'prop' => (string) ( $p['prop'] ?? '' ), 'lang' => (string) ( $p['lang'] ?? '' ), 'clicks' => 0.0, 'impr' => 0.0, 'pos_w' => 0.0, 'best' => -1.0, 'live' => false, 'terms' => [] ];
			}
			$by[ $tid ]['clicks'] += (float) ( $p['clicks'] ?? 0 );
			$by[ $tid ]['impr']   += $impr;
			$by[ $tid ]['pos_w']  += (float) ( $p['pos'] ?? 0 ) * $impr;
			// L ADRESSE QUI COMPTE : celle qui porte le slug d aujourd hui, et entre
			// deux du meme genre, celle que Google montre le plus. Une ancienne
			// adresse, qui redirige, peut etre la plus vue ; un lien doit viser
			// celle qui repond.
			$live = self::is_live_url( $url, (int) $tid, $slug_map );
			if ( [ $live, $impr ] > [ $by[ $tid ]['live'], $by[ $tid ]['best'] ] ) {
				$by[ $tid ]['live'] = $live;
				$by[ $tid ]['best'] = $impr;
				$by[ $tid ]['url']  = $url;
				$by[ $tid ]['prop'] = (string) ( $p['prop'] ?? '' );
			}
			foreach ( (array) ( $p['terms'] ?? [] ) as $t ) {
				$q = (string) ( $t['q'] ?? '' );
				if ( '' === $q ) {
					continue;
				}
				$ti = (float) ( $t['impr'] ?? 0 );
				$by[ $tid ]['terms'][ $q ]['impr']  = (float) ( $by[ $tid ]['terms'][ $q ]['impr'] ?? 0 ) + $ti;
				$by[ $tid ]['terms'][ $q ]['pos_w'] = (float) ( $by[ $tid ]['terms'][ $q ]['pos_w'] ?? 0 ) + (float) ( $t['pos'] ?? 0 ) * $ti;
			}
		}
		// CE QUI VEND SANS ETRE VU : une ligne aussi, sans chiffre Google.
		foreach ( $sales as $tid => $sold ) {
			$tid = (int) $tid;
			if ( $tid && ! isset( $by[ $tid ] ) && isset( $meta[ $tid ] ) && (int) ( $sold['units'] ?? 0 ) > 0 ) {
				$by[ $tid ] = [ 'url' => '', 'prop' => '', 'lang' => '', 'clicks' => 0.0, 'impr' => 0.0, 'pos_w' => 0.0, 'best' => 0.0, 'live' => false, 'terms' => [] ];
			}
		}
		$marks = self::brand_marks();
		$out   = [];
		foreach ( $by as $tid => $row ) {
			$m      = (array) ( $meta[ $tid ] ?? [] );
			$impr   = (float) $row['impr'];
			$clicks = (float) $row['clicks'];
			$pos    = $impr > 0 ? (float) $row['pos_w'] / $impr : 0.0;
			$ctr    = $impr > 0 ? $clicks / $impr : 0.0;
			$sold   = (array) ( $sales[ $tid ] ?? [] );
			$units  = (int) ( $sold['units'] ?? 0 );
			$rev    = (float) ( $sold['revenue'] ?? 0.0 );

			// LES ANCRES : ce que les gens tapent deja pour arriver ici, la plus
			// vue en tete — SANS LA MARQUE. « kula tactical gorka » dit le nom de
			// la boutique ; c est une ancre de marque, pas un mot a viser.
			$terms = [];
			foreach ( (array) $row['terms'] as $q => $t ) {
				if ( self::is_brand( (string) $q, $marks ) ) {
					continue;
				}
				$ti      = (float) $t['impr'];
				$terms[] = [ 'q' => (string) $q, 'impr' => $ti, 'pos' => $ti > 0 ? (float) $t['pos_w'] / $ti : 0.0 ];
			}
			usort( $terms, static fn( $a, $b ) => $b['impr'] <=> $a['impr'] );
			$terms = array_slice( $terms, 0, self::KEEP_ANCHOR );

			// JAMAIS UNE CIBLE : un lien vers une page que Google ne doit pas
			// indexer, ou vers un rayon vide, est un lien perdu.
			$skip = '';
			if ( ! empty( $m['default'] ) ) {
				$skip = 'default';
			} elseif ( ! empty( $m['noindex'] ) ) {
				$skip = 'noindex';
			} elseif ( ! empty( $m['empty'] ) ) {
				$skip = 'empty';
			}
			$gain = 0.0;
			if ( '' !== $skip ) {
				$status = 'skip';
			} elseif ( $impr <= 0 ) {
				$status = 'unseen';
			} elseif ( $pos < 4.0 ) {
				$status = 'strong';
			} elseif ( $pos <= 30.0 && $impr >= self::MIN_IMPR ) {
				$gain   = $impr * max( 0.0, self::ctr_at( 5 ) - $ctr );
				$status = $gain >= 1 ? 'reach' : 'strong';
			} else {
				$status = 'far';
			}
			if ( 'reach' !== $status ) {
				$gain = 0.0;
			}
			// UN TITRE AVANT UN LIEN. Bien placee et peu cliquee, une page a un
			// probleme d extrait — titre, description — qu aucun lien ne regle,
			// et qui se regle gratuitement.
			$ctr_low = $impr >= 100 && $pos >= 1.0 && $pos <= 10.0 && $ctr < 0.5 * self::ctr_at( (int) round( $pos ) );

			$out[] = [
				'tid'       => (int) $tid,
				// LA LANGUE DE LA CATEGORIE, pas celle de la premiere adresse lue.
				'lang'      => '' !== (string) ( $m['lang'] ?? '' ) ? (string) $m['lang'] : (string) $row['lang'],
				'name'      => (string) ( $m['name'] ?? '' ),
				'url'       => (string) $row['url'],
				'prop'      => (string) $row['prop'],
				'clicks'    => $clicks,
				'impr'      => $impr,
				'ctr'       => $ctr,
				'pos'       => $pos,
				'units'     => $units,
				'revenue'   => $rev,
				// CE QU UN CLIC RAPPORTE ICI. « Manque comptage de la valeur de
				// chaque clic. » Le chiffre d affaires de la categorie divise par
				// les clics de Google sur elle : il SURESTIME, puisque les ventes
				// viennent aussi du direct, de la publicite et des e-mails, et
				// c est dit. Il sert a comparer deux categories entre elles.
				'per_click' => $rev > 0 && $clicks >= 1 ? $rev / $clicks : 0.0,
				'gain'      => $gain,
				'worth'     => 0, // score() la pose, sur toute la lecture.
				'status'    => $status,
				'skip'      => $skip,
				'ctr_low'   => $ctr_low,
				'terms'     => $terms,
			];
		}
		return self::score( $out );
	}

	/**
	 * LA PRIORITE : les clics qu une meilleure place donnerait, fois ce qu un
	 * clic rapporte deja a cette categorie.
	 *
	 * « Le score est mauvais. Le revenu par clic et les ventes par rapport à la
	 * position de la catégorie actuelle, c'est ce qui m'intéresse vraiment. »
	 * L ancienne priorite ponderait les clics a gagner par le LOGARITHME des
	 * unites vendues : les ventes n y pesaient presque rien, et Gorka Suits
	 * (19 038 impressions, 3 ventes, 0,90 $ le clic) passait devant Tactical
	 * belt suspenders (76 ventes, 63 $ le clic).
	 *
	 * La position entre par les clics a gagner — la cinquieme place contre la
	 * place actuelle —, les ventes par ce qu un clic rapporte : le chiffre
	 * d affaires sur les clics, compte sur au moins SMOOTH_CLICKS clics. Sans
	 * vente, pas de priorite. Rendue sur 100, la premiere categorie de la
	 * lecture valant 100 : C EST UN RANG, PAS UNE PREVISION, puisque le chiffre
	 * d affaires vient de toutes les sources et les clics de Google seul.
	 *
	 * Refaite a l affichage aussi : une lecture gardee d une version d avant
	 * montre le rang d aujourd hui sans attendre la suivante.
	 *
	 * @param array<int,array<string,mixed>> $rows Les lignes de rank(), ou celles d une lecture gardee.
	 * @return array<int,array<string,mixed>> Les memes avec « value » et « worth », a portee d abord et par priorite.
	 */
	public static function score( array $rows ): array {
		$best = 0.0;
		foreach ( $rows as $i => $r ) {
			$rev   = (float) ( $r['revenue'] ?? 0 );
			$value = 'reach' === (string) ( $r['status'] ?? '' ) && $rev > 0
				? $rev / max( (float) ( $r['clicks'] ?? 0 ), (float) self::SMOOTH_CLICKS )
				: 0.0;
			$rows[ $i ]['value'] = $value;
			$rows[ $i ]['raw']   = $value * max( 0.0, (float) ( $r['gain'] ?? 0 ) );
			$best                = max( $best, $rows[ $i ]['raw'] );
		}
		// A PORTEE D ABORD, PAR PRIORITE PUIS PAR CLICS A GAGNER ; le reste par
		// ce qu il vend, puis par ce que Google en montre.
		$order = [ 'reach' => 0, 'strong' => 1, 'unseen' => 2, 'far' => 3, 'skip' => 4 ];
		usort( $rows, static function ( $a, $b ) use ( $order ) {
			$sa = $order[ (string) ( $a['status'] ?? '' ) ] ?? 9;
			$sb = $order[ (string) ( $b['status'] ?? '' ) ] ?? 9;
			if ( $sa !== $sb ) {
				return $sa <=> $sb;
			}
			return [ $b['raw'], (float) ( $b['gain'] ?? 0 ), (float) ( $b['revenue'] ?? 0 ), (float) ( $b['impr'] ?? 0 ) ]
				<=> [ $a['raw'], (float) ( $a['gain'] ?? 0 ), (float) ( $a['revenue'] ?? 0 ), (float) ( $a['impr'] ?? 0 ) ];
		} );
		foreach ( $rows as $i => $r ) {
			// CE QUI VAUT QUELQUE CHOSE NE S AFFICHE JAMAIS « 0 ».
			$rows[ $i ]['worth'] = $best > 0 && $r['raw'] > 0 ? max( 1, (int) round( 100 * $r['raw'] / $best ) ) : 0;
			unset( $rows[ $i ]['raw'] );
		}
		return $rows;
	}

	/** Un texte reduit a ses lettres et chiffres, en minuscules : « Kula-Tactical » → « kulatactical ». */
	public static function squash( string $s ): string {
		$s = function_exists( 'mb_strtolower' ) ? mb_strtolower( $s, 'UTF-8' ) : strtolower( $s );
		return (string) preg_replace( '/[^\p{L}\p{N}]+/u', '', $s );
	}

	/**
	 * CE QUI DESIGNE LA BOUTIQUE ELLE-MEME dans une requete : son nom, et le
	 * nom de chacun de ses domaines (kula-tactical.fr → « kulatactical »).
	 *
	 * @return string[]
	 */
	public static function brand_marks(): array {
		$marks = [];
		$name  = self::squash( (string) get_option( 'blogname' ) );
		if ( strlen( $name ) >= 4 ) {
			$marks[ $name ] = true;
		}
		foreach ( self::domains() as $host ) {
			$bits = explode( '.', (string) $host );
			if ( count( $bits ) >= 2 ) {
				$label = self::squash( (string) $bits[ count( $bits ) - 2 ] );
				if ( strlen( $label ) >= 4 ) {
					$marks[ $label ] = true;
				}
			}
		}
		return array_keys( $marks );
	}

	/** Une requete qui nomme la boutique. */
	public static function is_brand( string $q, array $marks ): bool {
		$s = self::squash( $q );
		foreach ( $marks as $m ) {
			if ( '' !== (string) $m && false !== strpos( $s, (string) $m ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * CE QUE LA BOUTIQUE SAIT DE CHAQUE CATEGORIE, en trois requetes : son nom et
	 * sa langue, si elle est vide, si Rank Math la dit « noindex », si c est la
	 * categorie par defaut. Pas ses liens internes : « internal link n'a pas lieu
	 * d'être ici » — ils sont l affaire de l ecran du maillage.
	 *
	 * VIDE VEUT DIRE VIDE AVEC SA DESCENDANCE : une categorie de tete ne porte
	 * souvent rien elle-meme et tout son rayon dessous.
	 *
	 * @return array<int,array{name:string,lang:string,empty:bool,noindex:bool,default:bool}>
	 */
	public static function term_meta(): array {
		global $wpdb;
		if ( ! $wpdb ) {
			return [];
		}
		$icl  = $wpdb->prefix . 'icl_translations';
		$wpml = class_exists( 'DZE_Wpml' ) && DZE_Wpml::is_active() && DZE_Wpml::has_table( $icl );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- core and WPML tables, read once per reading.
		$rows = (array) $wpdb->get_results(
			$wpml
				? "SELECT t.term_id AS tid, t.name AS name, tt.parent AS parent, tt.count AS n, COALESCE( ic.language_code, '' ) AS lang, COALESCE( ic.trid, 0 ) AS trid
				     FROM {$wpdb->terms} t
				     INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id
				     LEFT JOIN {$icl} ic ON ic.element_id = tt.term_taxonomy_id AND ic.element_type = 'tax_product_cat'
				    WHERE tt.taxonomy = 'product_cat'"
				: "SELECT t.term_id AS tid, t.name AS name, tt.parent AS parent, tt.count AS n, '' AS lang, 0 AS trid
				     FROM {$wpdb->terms} t
				     INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id
				    WHERE tt.taxonomy = 'product_cat'",
			ARRAY_A
		);
		$noindex = [];
		foreach ( (array) $wpdb->get_results(
			"SELECT tm.term_id AS tid, tm.meta_value AS v
			   FROM {$wpdb->termmeta} tm
			   INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = tm.term_id AND tt.taxonomy = 'product_cat'
			  WHERE tm.meta_key = 'rank_math_robots'",
			ARRAY_A
		) as $r ) {
			$v = maybe_unserialize( (string) $r['v'] );
			if ( is_array( $v ) && in_array( 'noindex', $v, true ) ) {
				$noindex[ (int) $r['tid'] ] = true;
			}
		}
		// phpcs:enable
		$own  = [];
		$kids = [];
		$trid = [];
		foreach ( $rows as $r ) {
			$tid          = (int) $r['tid'];
			$own[ $tid ]  = (int) $r['n'];
			$trid[ $tid ] = (int) $r['trid'];
			$kids[ (int) $r['parent'] ][] = $tid;
		}
		// Ce que porte une categorie avec toute sa descendance, garde contre une
		// boucle de parents qu une base abimee pourrait contenir.
		$total = static function ( int $tid ) use ( &$total, $own, $kids ): int {
			static $seen = [];
			if ( isset( $seen[ $tid ] ) ) {
				return 0;
			}
			$seen[ $tid ] = true;
			$n = (int) ( $own[ $tid ] ?? 0 );
			foreach ( (array) ( $kids[ $tid ] ?? [] ) as $k ) {
				$n += $total( (int) $k );
			}
			unset( $seen[ $tid ] );
			return $n;
		};
		// LA CATEGORIE PAR DEFAUT, dans toutes ses langues : WPML en donne une
		// traduction par langue, et « Non classe » n est pas plus une cible en
		// polonais qu en anglais.
		$def      = (int) get_option( 'default_product_cat', 0 );
		$def_trid = (int) ( $trid[ $def ] ?? 0 );
		$out      = [];
		foreach ( $rows as $r ) {
			$tid         = (int) $r['tid'];
			$out[ $tid ] = [
				'name'    => (string) $r['name'],
				'lang'    => (string) $r['lang'],
				'empty'   => 0 === $total( $tid ),
				'noindex' => isset( $noindex[ $tid ] ),
				'default' => $tid === $def || ( $def_trid > 0 && $trid[ $tid ] === $def_trid ),
			];
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
		// LES CATEGORIES D ABORD : seules leurs adresses sont gardees, et les
		// requetes ne sont rangees que sous elles — cinq mille fiches produit
		// n ont rien a faire en memoire pendant la lecture.
		$map   = self::slug_map();
		$pages = [];
		$seen  = 0;
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
				$seen++;
				if ( ! self::term_of_url( $url, $map ) ) {
					continue;
				}
				$pages[ $url ] = [
					'url'    => $url,
					'clicks' => (float) ( $r['clicks'] ?? 0 ),
					'impr'   => (float) ( $r['impressions'] ?? 0 ),
					'ctr'    => (float) ( $r['ctr'] ?? 0 ),
					'pos'    => (float) ( $r['position'] ?? 0 ),
					// D OU ELLE VIENT : la propriete renvoie a la source dans Search
					// Console, la langue permet de travailler un catalogue a la fois.
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

		// Le classement — separe de la lecture, parce qu une regle enfouie dans
		// un appel reseau ne s eprouve pas.
		$out = self::rank( $pages, self::sales_by_term( $days ), $map, self::term_meta() );

		update_option( self::OPT_DATA, [
			'at'       => time(),
			'property' => implode( ', ', $props ),
			'days'     => $days,
			'from'     => $start,
			'to'       => $end,
			'rows'     => $out,
			'seen'     => $seen,
			'model'    => 2, // une ligne par categorie ; 1 melangeait produits et articles.
		], false );
		$reach = count( array_filter( $out, static fn( $r ) => 'reach' === $r['status'] ) );
		return [ 'targets' => $reach, 'categories' => count( $out ), 'pages' => $seen ];
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

	/**
	 * DEUX ONGLETS, COMME LES TRADUCTIONS : le travail, et d ou il vient.
	 *
	 * « Les menus du module traduction WPML devraient servir de modèle. » Le
	 * premier onglet est la liste des categories ; le second dit a quelle
	 * Search Console le site est relie, avec quel compte, et permet de
	 * deconnecter — ce qui vivait en bas de page, plie, ou n existait pas.
	 */
	private static function tab_now(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- navigation only.
		$want = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( (string) $_GET['tab'] ) ) : '';
		return 'console' === $want ? 'console' : 'categories';
	}

	public static function render_page(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$tab = self::tab_now();
		echo '<div class="wrap dze-wrap dze-admin dze-nl"><h1>' . esc_html( DZE_Screens::label( 'netlinking' ) ) . '</h1>';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		if ( ! empty( $_GET['dze_nl_error'] ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html( sanitize_text_field( wp_unslash( (string) $_GET['dze_nl_error'] ) ) ) . '</p></div>'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		$names = DZE_Screens::tabs_of( 'netlinking' );
		$strip = [];
		foreach ( [ 'categories', 'console' ] as $id ) {
			$strip[ $id ] = [
				'label' => (string) ( $names[ $id ] ?? $id ),
				'url'   => add_query_arg( 'tab', $id, self::page_url() ),
				// UN BADGE VEUT DIRE « OCCUPE-TOI DE MOI » : les categories a
				// pousser, et rien sur l onglet de la connexion.
				'n'     => 'categories' === $id && self::connected() ? self::targets_count() : null,
			];
		}
		echo wp_kses_post( DZE_Screens::strip( $strip, $tab ) );
		if ( ! self::connected() ) {
			self::render_connect();
			echo '</div>';
			return;
		}
		if ( 'console' === $tab ) {
			self::render_console();
		} else {
			self::render_targets();
		}
		echo '</div>';
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
	 * L ONGLET SEARCH CONSOLE : le compte, les proprietes lues, et la sortie.
	 *
	 * « Pas de paramètres pour ce module netlinking. Où je vois à quelle Search
	 * Console le site est lié ? » Ici, en entier et ouvert.
	 */
	private static function render_console(): void {
		$c = self::connection();
		$d = self::data();
		echo '<div class="dze-trd-sec dze-nl-card"><div class="dze-nl-cardbody">';
		echo '<h2>' . esc_html__( 'Google account', 'dazont-ecom' ) . '</h2>';
		echo '<p>';
		if ( ! empty( $c['connected'] ) ) {
			printf(
				/* translators: %s: how long ago */
				esc_html__( 'Connected %s ago, read-only: this can see your Search Console figures and can never change anything there.', 'dazont-ecom' ),
				esc_html( human_time_diff( (int) $c['connected'], time() ) )
			);
		} else {
			esc_html_e( 'Connected, read-only: this can see your Search Console figures and can never change anything there.', 'dazont-ecom' );
		}
		echo '</p><p class="dze-nl-cardrow">';
		$next = wp_next_scheduled( self::HOOK );
		if ( $next ) {
			echo '<span class="description">' . esc_html( sprintf(
				/* translators: 1: a number of days, 2: how long until the next reading */
				__( 'Reads the last %1$s days once a day; next reading in %2$s.', 'dazont-ecom' ),
				number_format_i18n( self::window() ),
				human_time_diff( time(), (int) $next )
			) ) . '</span>';
		}
		echo '<a class="button" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=dze_nl_disconnect' ), 'dze_nl_disconnect' ) ) . '">' . esc_html__( 'Disconnect', 'dazont-ecom' ) . '</a>';
		echo '</p></div></div>';
		self::render_props( $d );
	}

	/**
	 * LE BOUTON ET SON ECOUTEUR NE SE SEPARENT PAS.
	 *
	 * « Il ne se passe rien. » Le script vivait a la FIN de la liste, apres le
	 * retour anticipe qui sert quand il n y a rien a montrer — donc le jour ou
	 * la liste etait vide, le bouton etait dessine et plus personne ne
	 * l ecoutait. Il est imprime avec le bouton, une fois, quoi qu il y ait
	 * dessous.
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
			// CHANGER LA PERIODE RELIT DANS LA FOULEE : un reglage enregistre qui
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
			'<span class="dze-nl-chip" title="%3$s"><span class="dashicons dashicons-%1$s"></span>%2$s</span>',
			esc_attr( $icon ),
			esc_html( $text ),
			esc_attr( $title )
		);
	}

	/**
	 * CE QUE LA LISTE TIENT AUJOURD HUI, en une ligne et en chiffres.
	 *
	 * Une pastille se tait quand elle n a rien a dire — « 0 » se lit comme un
	 * probleme la ou il n y a qu une absence.
	 */
	private static function render_chips( array $d, array $rows ): void {
		$out   = '';
		$reach = array_values( array_filter( $rows, static fn( $r ) => 'reach' === (string) ( $r['status'] ?? '' ) ) );
		if ( $reach ) {
			$out .= self::chip( 'admin-links', sprintf(
				/* translators: %s: how many categories */
				_n( '%s category worth a link', '%s categories worth a link', count( $reach ), 'dazont-ecom' ),
				number_format_i18n( count( $reach ) )
			) );
			// CE QUE CES CATEGORIES PESENT DEJA : un classement sans ordre de
			// grandeur ne dit pas s il vaut une matinee ou un trimestre.
			$money = 0.0;
			$units = 0;
			foreach ( $reach as $r ) {
				$money += (float) ( $r['revenue'] ?? 0 );
				$units += (int) ( $r['units'] ?? 0 );
			}
			if ( $units > 0 ) {
				$out .= self::chip( 'cart', sprintf(
					/* translators: 1: how many units, 2: how much money */
					__( '%1$s units · %2$s', 'dazont-ecom' ),
					number_format_i18n( $units ),
					self::money( $money )
				), __( 'What these categories already sell over the period read.', 'dazont-ecom' ) );
			}
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
		if ( '' !== $out ) {
			echo '<p class="dze-nl-chips">' . wp_kses_post( $out ) . '</p>';
		}
	}

	/** Une somme dans la devise de la boutique, sans decimales inutiles. */
	private static function money( float $n ): string {
		$sym = function_exists( 'get_woocommerce_currency_symbol' )
			? html_entity_decode( (string) get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' )
			: '';
		return $sym . number_format_i18n( $n, $n < 100 ? 2 : 0 );
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

	/** Le lien vers cette page DANS Search Console, pour aller voir la source. */
	private static function gsc_url( string $page_url, string $property ): string {
		if ( '' === $property || '' === $page_url ) {
			return '';
		}
		return add_query_arg( [
			'resource_id' => $property,
			'page'        => '!' . $page_url,
		], 'https://search.google.com/search-console/performance/search-analytics' );
	}

	/**
	 * CE QUE LA LISTE MONTRE, un filtre a la fois, comme les traductions.
	 *
	 * @return array<string,string> statut => libelle
	 */
	private static function statuses(): array {
		return [
			'reach'  => __( 'Worth a link', 'dazont-ecom' ),
			'strong' => __( 'Already strong', 'dazont-ecom' ),
			'unseen' => __( 'Selling, not seen in Google', 'dazont-ecom' ),
			'far'    => __( 'Too far or too little seen', 'dazont-ecom' ),
			'skip'   => __( 'Left out: noindex, empty', 'dazont-ecom' ),
			'all'    => __( 'All categories', 'dazont-ecom' ),
		];
	}

	/**
	 * LES FILTRES, LUS DANS L ADRESSE : une vue filtree et triee est un signet,
	 * comme les listes de WordPress. La liste s ouvre sur le travail — les
	 * categories qui meritent un lien — et non sur tout le catalogue, comme le
	 * tableau des traductions s ouvre sur « Not completed ».
	 *
	 * @return array{lang:string,status:string,s:string,by:string,dir:string,paged:int}
	 */
	private static function filters(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- navigation only.
		$lang   = isset( $_GET['lang'] ) ? sanitize_key( wp_unslash( (string) $_GET['lang'] ) ) : '';
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( (string) $_GET['status'] ) ) : 'reach';
		$s      = isset( $_GET['s'] ) ? trim( sanitize_text_field( wp_unslash( (string) $_GET['s'] ) ) ) : '';
		$paged  = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
		// phpcs:enable
		if ( ! isset( self::statuses()[ $status ] ) ) {
			$status = 'reach';
		}
		[ $by, $dir ] = self::sort_now();
		return [ 'lang' => $lang, 'status' => $status, 's' => $s, 'by' => $by, 'dir' => $dir, 'paged' => $paged ];
	}

	/** L adresse de la liste avec ces filtres, et ce qui change. */
	private static function view_url( array $f, array $change = [] ): string {
		$args = array_merge( [
			'tab'    => 'categories',
			'lang'   => $f['lang'],
			'status' => $f['status'],
			's'      => $f['s'],
			'by'     => $f['by'],
			'dir'    => $f['dir'],
			'paged'  => $f['paged'],
		], $change );
		// Ce qui vaut le defaut ne s ecrit pas : l adresse reste lisible.
		foreach ( [ 'lang' => '', 's' => '', 'status' => 'reach', 'by' => 'worth', 'dir' => 'desc', 'paged' => 1 ] as $k => $def ) {
			if ( (string) $args[ $k ] === (string) $def ) {
				unset( $args[ $k ] );
			}
		}
		return add_query_arg( $args, self::page_url() );
	}

	/**
	 * LES COLONNES, ET CELLE SUR LAQUELLE ON TRIE.
	 *
	 * « Manque fonction de tri par header. » Le tri est DANS L ADRESSE, comme
	 * les filtres : une vue triee est un signet, et c est l idiome des tableaux
	 * de WordPress.
	 *
	 * CE QUI COMPTE, ET RIEN D AUTRE : « il y a des colonnes en trop. internal
	 * link n'a pas lieu d'être ici. […] Le revenu par clic et les ventes par
	 * rapport à la position de la catégorie actuelle, c'est ce qui m'intéresse
	 * vraiment. » Les impressions passent au survol de la position, les clics a
	 * gagner au survol de la priorite ; les liens internes sont l affaire de
	 * l ecran du maillage. Un signet trie sur une colonne partie retombe sur la
	 * priorite (sort_now()).
	 *
	 * @return array<string,array{label:string,title:string}>
	 */
	private static function columns(): array {
		return [
			'pos'       => [ 'label' => __( 'Position', 'dazont-ecom' ), 'title' => __( 'Average position of the category page in Google over the period.', 'dazont-ecom' ) ],
			'clicks'    => [ 'label' => __( 'Clicks', 'dazont-ecom' ), 'title' => __( 'How often the category page was clicked from Google.', 'dazont-ecom' ) ],
			'units'     => [ 'label' => __( 'Units sold', 'dazont-ecom' ), 'title' => __( 'What the products filed in this category sold over the same period, in this language.', 'dazont-ecom' ) ],
			'revenue'   => [ 'label' => __( 'Revenue', 'dazont-ecom' ), 'title' => __( 'Converted to the shop currency at the rate recorded with each order.', 'dazont-ecom' ) ],
			'per_click' => [ 'label' => __( 'Revenue / click', 'dazont-ecom' ), 'title' => __( 'Revenue divided by the clicks Google sent to the category. It overstates, because sales come from every source: it compares categories, it does not predict.', 'dazont-ecom' ) ],
			'worth'     => [ 'label' => __( 'Priority', 'dazont-ecom' ), 'title' => __( 'The clicks a better place would bring, times what a click earns this category. 100 is the first category of this reading.', 'dazont-ecom' ) ],
		];
	}

	/** Le tri demande, ou celui par defaut. */
	private static function sort_now(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- navigation only.
		$by  = isset( $_GET['by'] ) ? sanitize_key( wp_unslash( (string) $_GET['by'] ) ) : 'worth';
		$dir = isset( $_GET['dir'] ) && 'asc' === $_GET['dir'] ? 'asc' : 'desc';
		// phpcs:enable
		if ( 'name' !== $by && ! isset( self::columns()[ $by ] ) ) {
			$by = 'worth';
		}
		return [ $by, $dir ];
	}

	/** Un en-tete qui trie, du meme dessin que ceux des traductions. */
	private static function th( string $key, string $label, string $title, array $f, string $class ): string {
		$on   = $f['by'] === $key;
		$next = ( $on && 'desc' === $f['dir'] ) ? 'asc' : ( $on ? 'desc' : ( 'name' === $key ? 'asc' : 'desc' ) );
		return sprintf(
			'<th class="%1$s" title="%2$s"><a class="dze-trd-sort%3$s" href="%4$s">%5$s<span class="dashicons dashicons-sort" aria-hidden="true"></span></a></th>',
			esc_attr( $class ),
			esc_attr( $title ),
			$on ? ( 'asc' === $f['dir'] ? ' is-asc' : ' is-desc' ) : '',
			esc_url( self::view_url( $f, [ 'by' => $key, 'dir' => $next, 'paged' => 1 ] ) ),
			esc_html( $label )
		);
	}

	/** Le nom d une langue, tel que WPML le montre. */
	private static function lang_name( string $code ): string {
		static $names = null;
		if ( null === $names ) {
			$names = [];
			if ( class_exists( 'DZE_Wpml' ) ) {
				foreach ( DZE_Wpml::get_active_languages() as $l ) {
					$names[ (string) $l['code'] ] = (string) ( $l['native_name'] ?? $l['code'] );
				}
			}
		}
		return (string) ( $names[ $code ] ?? strtoupper( $code ) );
	}

	/** Le drapeau WPML d une langue, ou son code. */
	private static function flag( string $code ): string {
		if ( '' === $code ) {
			return '';
		}
		return class_exists( 'DZE_Wpml' ) && method_exists( 'DZE_Wpml', 'flag_html' )
			? (string) DZE_Wpml::flag_html( $code )
			: esc_html( strtoupper( $code ) );
	}

	private static function render_targets(): void {
		$d   = self::data();
		$all = (array) ( $d['rows'] ?? [] );
		// UNE LECTURE D AVANT CETTE VERSION MELANGEAIT FICHES PRODUIT ET
		// ARTICLES : elle ne se montre pas, elle se relit.
		if ( $all && 2 !== (int) ( $d['model'] ?? 0 ) ) {
			$all = [];
		}
		// LE RANG D AUJOURD HUI, meme sur une lecture faite avant lui.
		$all = self::score( $all );
		$f   = self::filters();

		self::render_error();
		self::render_chips( $d, $all );

		// LES LANGUES PRESENTES, et ce que chaque statut compte dans la langue
		// choisie : un filtre dit ce qu il va montrer avant qu on le choisisse.
		$langs  = [];
		$counts = array_fill_keys( array_keys( self::statuses() ), 0 );
		foreach ( $all as $r ) {
			$code = (string) ( $r['lang'] ?? '' );
			if ( '' !== $code ) {
				$langs[ $code ] = true;
			}
			if ( '' !== $f['lang'] && $code !== $f['lang'] ) {
				continue;
			}
			$counts[ (string) $r['status'] ] = (int) ( $counts[ (string) $r['status'] ] ?? 0 ) + 1;
			$counts['all']++;
		}
		$dirty = '' !== $f['lang'] || 'reach' !== $f['status'] || '' !== $f['s'];
		?>
		<form method="get" class="dze-trd-global dze-nl-filters" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::MENU_SLUG ); ?>" />
			<input type="hidden" name="tab" value="categories" />
			<?php if ( count( $langs ) > 1 ) : ?>
				<select name="lang" aria-label="<?php esc_attr_e( 'Language', 'dazont-ecom' ); ?>">
					<option value=""><?php esc_html_e( 'All languages', 'dazont-ecom' ); ?></option>
					<?php foreach ( array_keys( $langs ) as $dze_code ) : ?>
						<option value="<?php echo esc_attr( (string) $dze_code ); ?>" <?php selected( $f['lang'], (string) $dze_code ); ?>><?php echo esc_html( self::lang_name( (string) $dze_code ) ); ?></option>
					<?php endforeach; ?>
				</select>
			<?php endif; ?>
			<select name="status" aria-label="<?php esc_attr_e( 'Which categories', 'dazont-ecom' ); ?>">
				<?php foreach ( self::statuses() as $dze_k => $dze_label ) : ?>
					<option value="<?php echo esc_attr( $dze_k ); ?>" <?php selected( $f['status'], $dze_k ); ?>><?php echo esc_html( $dze_label . ' (' . number_format_i18n( (int) ( $counts[ $dze_k ] ?? 0 ) ) . ')' ); ?></option>
				<?php endforeach; ?>
			</select>
			<input type="search" name="s" value="<?php echo esc_attr( $f['s'] ); ?>" placeholder="<?php esc_attr_e( 'Category name', 'dazont-ecom' ); ?>" aria-label="<?php esc_attr_e( 'Search a category', 'dazont-ecom' ); ?>" />
			<button type="submit" class="button"><?php esc_html_e( 'Filter', 'dazont-ecom' ); ?></button>
			<?php if ( $dirty ) : ?>
				<a class="dze-trd-clear" href="<?php echo esc_url( self::view_url( [ 'lang' => '', 'status' => 'reach', 's' => '', 'by' => 'worth', 'dir' => 'desc', 'paged' => 1 ] ) ); ?>"><span class="dashicons dashicons-no-alt" aria-hidden="true"></span><?php esc_html_e( 'Clear filters', 'dazont-ecom' ); ?></a>
			<?php endif; ?>
			<span class="dze-trd-grow"></span>
			<select id="dze-nl-days" aria-label="<?php esc_attr_e( 'Period read', 'dazont-ecom' ); ?>">
				<?php foreach ( [ 7, 28, 90 ] as $dze_days ) : ?>
					<option value="<?php echo (int) $dze_days; ?>" <?php selected( $dze_days, self::window() ); ?>><?php echo esc_html( sprintf( /* translators: %s: a number of days */ _n( 'Last %s day', 'Last %s days', $dze_days, 'dazont-ecom' ), number_format_i18n( $dze_days ) ) ); ?></option>
				<?php endforeach; ?>
			</select>
			<button type="button" class="button" id="dze-nl-refresh"><?php esc_html_e( 'Read Search Console now', 'dazont-ecom' ); ?></button>
			<span class="description dze-nl-state" id="dze-nl-state"><?php echo empty( $d['at'] ) ? esc_html__( 'Not read yet — it reads itself once a day.', 'dazont-ecom' ) : ''; ?></span>
		</form>
		<?php
		// AVANT TOUT RETOUR ANTICIPE : le bouton vient d etre dessine.
		self::render_script();

		if ( ! $all ) {
			echo '<p class="description">' . ( empty( $d['at'] ) || 2 !== (int) ( $d['model'] ?? 0 )
				? esc_html__( 'Nothing read yet in this version: press « Read Search Console now », or wait for tonight\'s reading.', 'dazont-ecom' )
				: esc_html__( 'No product category of this shop appears in Search Console over the period, and none sells. That is an answer, not a fault.', 'dazont-ecom' ) ) . '</p>';
			self::render_notes();
			return;
		}

		$needle = '' !== $f['s'] ? self::squash( $f['s'] ) : '';
		$rows   = array_values( array_filter( $all, static function ( $r ) use ( $f, $needle ) {
			if ( '' !== $f['lang'] && (string) ( $r['lang'] ?? '' ) !== $f['lang'] ) {
				return false;
			}
			if ( 'all' !== $f['status'] && (string) ( $r['status'] ?? '' ) !== $f['status'] ) {
				return false;
			}
			return '' === $needle || false !== strpos( self::squash( html_entity_decode( (string) ( $r['name'] ?? '' ), ENT_QUOTES, 'UTF-8' ) ), $needle );
		} ) );
		// LE TRI DEMANDE ; l ordre de la lecture — a portee d abord, par priorite
		// — reste celui des egalites, le tri de PHP etant stable.
		if ( 'worth' !== $f['by'] || 'desc' !== $f['dir'] ) {
			$by  = $f['by'];
			$dir = $f['dir'];
			usort( $rows, static function ( $a, $b ) use ( $by, $dir ) {
				if ( 'name' === $by ) {
					$c = strcasecmp( (string) ( $a['name'] ?? '' ), (string) ( $b['name'] ?? '' ) );
				} else {
					// UNE CASE SANS CHIFFRE VA EN BAS, dans les deux sens.
					$x = $a[ $by ] ?? null;
					$y = $b[ $by ] ?? null;
					if ( null === $x || null === $y ) {
						return ( null === $x ) <=> ( null === $y );
					}
					$c = (float) $x <=> (float) $y;
				}
				return 'asc' === $dir ? $c : -$c;
			} );
		}
		$found = count( $rows );
		$pages = max( 1, (int) ceil( $found / self::PER_PAGE ) );
		$f['paged'] = min( $f['paged'], $pages );
		$rows  = array_slice( $rows, ( $f['paged'] - 1 ) * self::PER_PAGE, self::PER_PAGE );
		$multi = count( $langs ) > 1;
		$cols  = self::columns();

		if ( ! $rows ) {
			echo '<p class="description">' . esc_html__( 'No category matches these filters.', 'dazont-ecom' ) . '</p>';
			self::render_notes();
			return;
		}
		// THE TABLE SCROLLS IN ITS OWN BOX on a narrow screen, never the page:
		// « page maintenant étirée en largeur. Ce n'est pas bon. »
		echo '<div class="dze-nl-scroll"><table class="widefat striped dze-trd-table dze-nl-table"><thead><tr>';
		echo wp_kses_post( self::th( 'name', __( 'Category', 'dazont-ecom' ), __( 'The category page a link should point at.', 'dazont-ecom' ), $f, 'dze-nl-name' ) );
		foreach ( $cols as $key => $col ) {
			echo wp_kses_post( self::th( (string) $key, (string) $col['label'], (string) $col['title'], $f, 'dze-nl-fig' ) );
		}
		echo '</tr></thead><tbody>';
		foreach ( $rows as $r ) {
			$tid    = (int) ( $r['tid'] ?? 0 );
			$url    = (string) ( $r['url'] ?? '' );
			$status = (string) ( $r['status'] ?? '' );
			$name   = html_entity_decode( (string) ( $r['name'] ?? '' ), ENT_QUOTES, 'UTF-8' );
			if ( '' === $url && $tid > 0 && function_exists( 'get_term_link' ) ) {
				$link = get_term_link( $tid, 'product_cat' );
				$url  = is_string( $link ) ? $link : '';
			}
			// ONE LINE A CATEGORY. « Les lignes sont très grosses. Manque de
			// lisibilité. » The cell stacked the name, its address on a line of its
			// own, advice written as sentences that wrapped, and WordPress's row
			// actions, which keep their line even while hidden. The address is the
			// link's tooltip, the actions two small icons, and every piece of advice
			// one or two words — the explanation is on hover.
			echo '<tr class="is-' . esc_attr( $status ) . '"><td class="dze-nl-name"><div class="dze-nl-line">';
			if ( $multi ) {
				echo '<span class="dze-nl-flag">' . wp_kses_post( self::flag( (string) ( $r['lang'] ?? '' ) ) ) . '</span>';
			}
			// THE ANCHOR IDEAS ON HOVER, not in a column: « page maintenant étirée
			// en largeur […] tu peux enlever anchor ideas ». A column of words on one
			// line pushed the table past the screen, under the admin menu.
			$dze_tip = '' !== $url ? '/' . self::short( $url ) : '';
			$dze_q   = array_values( array_filter( array_map( static fn( $t ) => (string) ( $t['q'] ?? '' ), (array) ( $r['terms'] ?? [] ) ) ) );
			if ( $dze_q ) {
				/* translators: %s: the searches that already lead to the category */
				$dze_tip .= ( '' !== $dze_tip ? "\n" : '' ) . sprintf( __( 'Searched as: %s', 'dazont-ecom' ), implode( ' · ', $dze_q ) );
			}
			echo '' !== $url
				? '<a class="dze-nl-cat" href="' . esc_url( $url ) . '" target="_blank" rel="noopener" title="' . esc_attr( $dze_tip ) . '">' . esc_html( '' !== $name ? $name : self::short( $url ) ) . '</a>'
				: '<span class="dze-nl-cat" title="' . esc_attr( $dze_tip ) . '">' . esc_html( $name ) . '</span>';
			if ( $tid > 0 && function_exists( 'get_edit_term_link' ) ) {
				$edit = get_edit_term_link( $tid, 'product_cat' );
				if ( is_string( $edit ) && '' !== $edit ) {
					echo '<a class="dze-nl-ico" href="' . esc_url( $edit ) . '" title="' . esc_attr__( 'Edit the category', 'dazont-ecom' ) . '"><span class="dashicons dashicons-edit" aria-hidden="true"></span><span class="screen-reader-text">' . esc_html__( 'Edit', 'dazont-ecom' ) . '</span></a>';
				}
			}
			$gsc = self::gsc_url( (string) ( $r['url'] ?? '' ), (string) ( $r['prop'] ?? '' ) );
			if ( '' !== $gsc ) {
				echo '<a class="dze-nl-ico" href="' . esc_url( $gsc ) . '" target="_blank" rel="noopener" title="' . esc_attr__( 'Open it in Search Console', 'dazont-ecom' ) . '"><span class="dashicons dashicons-chart-area" aria-hidden="true"></span><span class="screen-reader-text">' . esc_html__( 'Search Console', 'dazont-ecom' ) . '</span></a>';
			}
			// CE QUI PASSE AVANT UN LIEN : un mot sur la ligne, la phrase au survol.
			if ( ! empty( $r['ctr_low'] ) ) {
				echo '<span class="dze-nl-note is-warn" title="' . esc_attr__( 'Low click rate for its place. Google shows it well placed, and few people click: rework its title and meta description first — a link does not fix a result nobody wants to click, a better one is free.', 'dazont-ecom' ) . '">' . esc_html__( 'Low CTR', 'dazont-ecom' ) . '</span>';
			}
			$skip_said = [
				'noindex' => [ __( 'noindex', 'dazont-ecom' ), __( 'Google is told not to index it: a link would be wasted.', 'dazont-ecom' ) ],
				'empty'   => [ __( 'Empty', 'dazont-ecom' ), __( 'No product in it or under it.', 'dazont-ecom' ) ],
				'default' => [ __( 'Default', 'dazont-ecom' ), __( 'The default category of the shop.', 'dazont-ecom' ) ],
			];
			if ( 'skip' === $status && isset( $skip_said[ (string) ( $r['skip'] ?? '' ) ] ) ) {
				$dze_skip = $skip_said[ (string) $r['skip'] ];
				echo '<span class="dze-nl-note" title="' . esc_attr( $dze_skip[1] ) . '">' . esc_html( $dze_skip[0] ) . '</span>';
			}
			if ( 'unseen' === $status ) {
				echo '<span class="dze-nl-note" title="' . esc_attr__( 'It sells, and Google showed its page to nobody over the period. Check that the page is indexed before anything else.', 'dazont-ecom' ) . '">' . esc_html__( 'Not in Google', 'dazont-ecom' ) . '</span>';
			}
			echo '</div>';
			echo '</td>';
			$seen = (float) ( $r['impr'] ?? 0 ) > 0;
			$dash = '<span class="description">—</span>';
			// THE POSITION, with what Google showed of it on hover.
			$impr_tip = $seen ? sprintf( /* translators: %s: how many impressions */ __( '%s impressions', 'dazont-ecom' ), number_format_i18n( (int) ( $r['impr'] ?? 0 ) ) ) : '';
			echo '<td class="dze-nl-fig" title="' . esc_attr( $impr_tip ) . '">' . ( $seen ? esc_html( number_format_i18n( (float) ( $r['pos'] ?? 0 ), 1 ) ) : $dash ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built above.
			echo '<td class="dze-nl-fig" title="' . esc_attr( $seen ? sprintf( /* translators: %s: a click-through rate */ __( 'Click rate: %s%%', 'dazont-ecom' ), number_format_i18n( 100 * (float) ( $r['ctr'] ?? 0 ), 1 ) ) : '' ) . '">' . ( $seen ? esc_html( number_format_i18n( (int) ( $r['clicks'] ?? 0 ) ) ) : $dash ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<td class="dze-nl-fig">' . esc_html( number_format_i18n( (int) ( $r['units'] ?? 0 ) ) ) . '</td>';
			echo '<td class="dze-nl-fig">' . ( (float) ( $r['revenue'] ?? 0 ) > 0 ? esc_html( self::money( (float) $r['revenue'] ) ) : $dash ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			// A REVENUE PER CLICK ON FOUR CLICKS SAYS SO: one large order makes it
			// $500 a click. Greyed, and the hover says what the priority counts.
			$per     = (float) ( $r['per_click'] ?? 0 );
			$value   = (float) ( $r['value'] ?? 0 );
			$n_click = (int) ( $r['clicks'] ?? 0 );
			$thin    = $per > 0 && $n_click < self::SMOOTH_CLICKS;
			$per_tip = '';
			if ( $thin ) {
				/* translators: %s: how many clicks */
				$per_tip = sprintf( _n( 'On %s click only: too few to judge.', 'On %s clicks only: too few to judge.', $n_click, 'dazont-ecom' ), number_format_i18n( $n_click ) );
				if ( $value > 0 ) {
					/* translators: %s: an amount per click */
					$per_tip .= ' ' . sprintf( __( 'The priority counts %s a click.', 'dazont-ecom' ), self::money( $value ) );
				}
			}
			echo '<td class="dze-nl-fig' . ( $thin ? ' is-thin' : '' ) . '" title="' . esc_attr( $per_tip ) . '">' . ( $per > 0 ? esc_html( self::money( $per ) ) : $dash ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			// THE PRIORITY, and on hover the two figures it is made of.
			$worth = (int) ( $r['worth'] ?? 0 );
			$w_tip = $worth > 0
				? sprintf(
					/* translators: 1: how many clicks, 2: an amount per click */
					__( '+%1$s clicks at about fifth place × %2$s a click', 'dazont-ecom' ),
					number_format_i18n( (int) round( (float) ( $r['gain'] ?? 0 ) ) ),
					self::money( $value )
				)
				: '';
			echo '<td class="dze-nl-fig dze-nl-prio" title="' . esc_attr( $w_tip ) . '">' . ( $worth > 0 ? '<strong>' . esc_html( number_format_i18n( $worth ) ) . '</strong>' : $dash ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '</tr>';
		}
		echo '</tbody></table></div>';
		self::render_pager( $found, $pages, $f );
		self::render_notes();
	}

	/** La pagination de WordPress, avec ses classes et ses fleches. */
	private static function render_pager( int $found, int $pages, array $f ): void {
		$num = sprintf(
			/* translators: %s: how many categories */
			_n( '%s category', '%s categories', $found, 'dazont-ecom' ),
			number_format_i18n( $found )
		);
		echo '<div class="tablenav bottom"><div class="tablenav-pages' . ( $pages < 2 ? ' one-page' : '' ) . '"><span class="displaying-num">' . esc_html( $num ) . '</span>';
		if ( $pages > 1 ) {
			$now  = (int) $f['paged'];
			$link = static function ( int $to, string $cls, string $sym, string $said, bool $off ) use ( $f ): string {
				if ( $off ) {
					return '<span class="tablenav-pages-navspan button disabled" aria-hidden="true">' . $sym . '</span>';
				}
				return '<a class="' . esc_attr( $cls ) . ' button" href="' . esc_url( self::view_url( $f, [ 'paged' => $to ] ) ) . '"><span class="screen-reader-text">' . esc_html( $said ) . '</span><span aria-hidden="true">' . $sym . '</span></a>';
			};
			echo '<span class="pagination-links">';
			echo $link( 1, 'first-page', '&laquo;', __( 'First page', 'dazont-ecom' ), $now <= 1 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built above.
			echo ' ' . $link( $now - 1, 'prev-page', '&lsaquo;', __( 'Previous page', 'dazont-ecom' ), $now <= 1 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo ' <span class="paging-input"><span class="tablenav-paging-text">' . esc_html( sprintf(
				/* translators: 1: current page, 2: total pages */
				__( '%1$s of %2$s', 'dazont-ecom' ),
				number_format_i18n( $now ),
				number_format_i18n( $pages )
			) ) . '</span></span> ';
			echo $link( $now + 1, 'next-page', '&rsaquo;', __( 'Next page', 'dazont-ecom' ), $now >= $pages ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo ' ' . $link( $pages, 'last-page', '&raquo;', __( 'Last page', 'dazont-ecom' ), $now >= $pages ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '</span>';
		}
		echo '</div></div>';
	}

	/**
	 * A QUELLE SEARCH CONSOLE CE SITE EST RELIE — dit, et pas suppose.
	 *
	 * Les proprietes viennent du compte Google connecte, une par domaine que
	 * WPML declare, et chacune est nommee avec sa langue, comme l ecran des
	 * traductions nomme les siennes.
	 */
	private static function render_props( array $d ): void {
		$props = array_filter( array_map( 'trim', explode( ',', (string) ( $d['property'] ?? '' ) ) ) );
		if ( ! $props ) {
			$props = array_map( 'strval', (array) ( self::settings()['properties'] ?? [] ) );
		}
		echo '<div class="dze-trd-sec dze-nl-card"><div class="dze-nl-cardbody">';
		echo '<h2>' . esc_html( sprintf(
			/* translators: %s: how many Search Console properties */
			_n( 'Linked to %s Search Console property', 'Linked to %s Search Console properties', count( $props ), 'dazont-ecom' ),
			number_format_i18n( count( $props ) )
		) ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'WPML keeps this shop on one domain per language, so Search Console holds one property for each, and every one the connected account can see is read.', 'dazont-ecom' ) . '</p>';
		if ( ! $props ) {
			echo '<p class="description">' . esc_html__( 'None yet — read once and they are chosen from what your account can see.', 'dazont-ecom' ) . '</p>';
			echo '</div></div>';
			return;
		}
		echo '<table class="widefat striped"><tbody>';
		$domains = self::domains();
		foreach ( $props as $one ) {
			$code = '';
			foreach ( $domains as $lang => $host ) {
				if ( 'sc-domain:' . $host === $one || false !== strpos( $one, '://' . $host ) ) {
					$code = (string) $lang;
					break;
				}
			}
			echo '<tr><td style="width:70px;">' . ( '' !== $code ? wp_kses_post( self::flag( $code ) ) : '' ) . '</td>';
			echo '<td><code>' . esc_html( $one ) . '</code></td>';
			echo '<td style="width:170px;"><a href="' . esc_url( add_query_arg( 'resource_id', $one, 'https://search.google.com/search-console' ) ) . '" target="_blank" rel="noopener">'
				. esc_html__( 'Open in Search Console', 'dazont-ecom' ) . ' ↗</a></td></tr>';
		}
		echo '</tbody></table>';
		// UN DOMAINE QUE WPML CONNAIT ET QUE GOOGLE N A PAS est un trou qu il
		// vaut mieux nommer : ce catalogue ne sera jamais dans la liste. Une
		// propriete de domaine couvre ses sous-domaines (ru.exemple.com).
		$missing = [];
		foreach ( $domains as $lang => $host ) {
			$found = false;
			foreach ( $props as $one ) {
				if ( 'sc-domain:' . $host === $one || false !== strpos( $one, '://' . $host )
					|| ( 0 === strpos( $one, 'sc-domain:' ) && '.' . substr( $one, 10 ) === substr( '.' . $host, - strlen( '.' . substr( $one, 10 ) ) ) ) ) {
					$found = true;
					break;
				}
			}
			if ( ! $found ) {
				$missing[] = $host;
			}
		}
		if ( $missing ) {
			echo '<p class="description"><strong>' . esc_html__( 'Not read:', 'dazont-ecom' ) . '</strong> '
				. esc_html( implode( ', ', $missing ) ) . ' — '
				. esc_html__( 'your Google account cannot see a Search Console property for these, so those catalogues never appear in the list.', 'dazont-ecom' ) . '</p>';
		}
		echo '</div></div>';
	}

	/**
	 * COMMENT S EN SERVIR, ET COMMENT CES CHIFFRES SONT FAITS — replie, pour
	 * qui veut le lire. « Si un module est bien fait, il n'est pas nécessaire
	 * d'ajouter du texte partout. »
	 */
	private static function render_notes(): void {
		echo '<details class="dze-set dze-nl-how"><summary>' . esc_html__( 'How to use this list', 'dazont-ecom' ) . '</summary><ul>';
		foreach ( [
			__( 'Point the link at the category page itself, never at a product: a category passes what it receives on to every product it lists, and it stays online when a product goes.', 'dazont-ecom' ),
			__( 'Vary the anchor: the category name, your brand, the bare address, and now and then one of the searches it is already found with — shown when you hover its name. The same exact words on every link looks bought.', 'dazont-ecom' ),
			__( 'One link from a site about the same subject is worth more than ten from anywhere. Leave out footers, sidebars, link swaps and bought packages.', 'dazont-ecom' ),
			__( 'A category marked « Low click rate » has a title problem, not a link problem: rework its title and meta description first, it costs nothing.', 'dazont-ecom' ),
			__( 'Look again a few weeks after a link is placed: Google takes that long to move, and the period read can be set above the list.', 'dazont-ecom' ),
		] as $dze_line ) {
			echo '<li>' . esc_html( $dze_line ) . '</li>';
		}
		echo '</ul></details>';

		echo '<details class="dze-set dze-nl-how"><summary>' . esc_html__( 'How these figures are made', 'dazont-ecom' ) . '</summary>';
		foreach ( [
			__( 'Only product categories are listed. Position, impressions and clicks are what Google measured for the category page itself; a category with several addresses has them added up.', 'dazont-ecom' ),
			__( 'Units and revenue are what the products filed in the category sold over the same period, as WooCommerce recorded it. Each language counts its OWN sales: the language is the one of the product sold, so a sale on the French shop counts for the French category and for it alone.', 'dazont-ecom' ),
			__( 'Revenue is converted to the shop currency at the rate recorded WITH EACH ORDER on the day it was paid; an order that recorded none is converted at WooCommerce Multilingual\'s current rate, and a sum in a currency nobody can convert is left out rather than counted one for one. Refunds are taken off; unpaid, failed, cancelled and deleted orders are not counted.', 'dazont-ecom' ),
			sprintf(
				/* translators: %s: a number of clicks */
				__( '"Priority" puts first the categories whose clicks earn the most and that a better place would bring the most clicks to: the clicks the category would get at about fifth place against what it gets now, times its revenue per click — counted on at least %s clicks, so one large order on four clicks does not jump the queue. No sale, no priority. 100 is the first category of the reading. It ranks and predicts nothing: sales come from every source, while these clicks are Google\'s alone. Hover a priority for the two figures it is made of.', 'dazont-ecom' ),
				number_format_i18n( self::SMOOTH_CLICKS )
			),
			__( 'Worth a link: past the third place and before the thirtieth, seen at least twenty times, and clicked less than fifth place would be. Left out: a category marked noindex, an empty one, and the default category.', 'dazont-ecom' ),
		] as $dze_line ) {
			echo '<p class="description">' . esc_html( $dze_line ) . '</p>';
		}
		echo '<p class="description"><strong>' . esc_html__( 'What Search Console does not give:', 'dazont-ecom' ) . '</strong> '
			. esc_html__( 'its API has no backlinks — the Links report exists on screen and nowhere else. So this screen never claims to list the links you already have; it says where a new one would pay.', 'dazont-ecom' ) . '</p>';
		echo '</details>';
	}

	/** Une adresse lisible : le chemin, pas le domaine repete cinquante fois. */
	private static function short( string $url ): string {
		$p = (string) wp_parse_url( $url, PHP_URL_PATH );
		return '' === $p || '/' === $p ? $url : trim( $p, '/' );
	}
}
