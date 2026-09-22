<?php
/**
 * UNE SEULE ADRESSE DE RETOUR OAuth POUR TOUT LE PLUGIN.
 *
 * « Pourquoi ne pas utiliser un seul url de redirection standardisé pour tout
 * le plugin ? » Il n y avait aucune bonne raison. Chaque module qui se
 * connectait a Google apportait son adresse — `dze_gmc_oauth`,
 * `dze_nl_oauth` — donc une ligne de plus a coller dans la console Google, une
 * occasion de plus de se tromper, et une friction qui grandit a chaque module.
 *
 * OAuth a prevu exactement ce cas : le parametre `state` fait l aller-retour
 * sans etre touche, et sert a la fois a reconnaitre QUI a demande et a se
 * proteger du rejeu. On y met les deux, separes par deux-points :
 *
 *     state = « nl:a1b2c3d4 »
 *
 * Le routeur lit le premier morceau, remet le second a sa place dans `$_GET`,
 * et passe la main au module — qui verifie son propre jeton comme avant. Ce
 * fichier ne verifie rien lui-meme : la ou un module decide, c est le module
 * qui doit repondre.
 *
 * UNE CONNEXION QUI MARCHE NE SE CASSE PAS AU PASSAGE. L adresse de retour ne
 * sert qu a la PREMIERE autorisation ; le rafraichissement du jeton ne s en
 * sert pas. Changer d adresse ne coupe donc rien de ce qui tourne, et les
 * anciennes adresses restent branchees pour qu une autorisation en cours de
 * route atterrisse quand meme.
 *
 * @package Dazont_Ecom
 */

defined( 'ABSPATH' ) || exit;

final class DZE_Oauth {

	/** L action unique, et la seule adresse a declarer chez Google. */
	public const ACTION = 'dze_oauth';

	/**
	 * Qui peut etre appele, et comment. Un module absent ou eteint n est pas
	 * appele : la clef est lue, jamais construite depuis ce que Google renvoie.
	 *
	 * @return array<string,array{class:string,method:string}>
	 */
	private static function handlers(): array {
		return [
			'gmc' => [ 'class' => 'DZE_Gmc', 'method' => 'handle_oauth_callback' ],
			'nl'  => [ 'class' => 'DZE_Netlinking', 'method' => 'handle_oauth_callback' ],
		];
	}

	public static function init(): void {
		add_action( 'admin_post_' . self::ACTION, [ __CLASS__, 'route' ] );
	}

	/** L adresse a declarer chez Google — une, pour tout le plugin. */
	public static function redirect_uri(): string {
		return admin_url( 'admin-post.php?action=' . self::ACTION );
	}

	/**
	 * Le `state` d un module : qui demande, et son jeton anti-rejeu.
	 *
	 * Le jeton reste celui du module, avec son propre nom d action, pour que la
	 * verification faite a l arrivee soit exactement celle qui existait avant.
	 */
	public static function state( string $who, string $nonce_action ): string {
		return $who . ':' . wp_create_nonce( $nonce_action );
	}

	/**
	 * L aiguillage. Il ne decide rien : il reconnait le demandeur, rend a
	 * `$_GET['state']` la forme que le module attend, et s efface.
	 */
	public static function route(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'dazont-ecom' ) );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- le module appele verifie son propre jeton.
		$state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['state'] ) ) : '';
		$who   = '';
		$rest  = $state;
		if ( false !== strpos( $state, ':' ) ) {
			[ $who, $rest ] = explode( ':', $state, 2 );
		}
		$one = self::handlers()[ $who ] ?? null;
		if ( ! $one || ! class_exists( $one['class'] ) ) {
			// UN RETOUR QU ON NE SAIT PAS ATTRIBUER NE SE DEVINE PAS. Appeler un
			// module au hasard reviendrait a lui faire traiter le consentement
			// d un autre.
			wp_die( esc_html__( 'This Google sign-in does not name which part of the plugin asked for it. Start again from the screen you were connecting.', 'dazont-ecom' ) );
		}
		$_GET['state'] = $rest;
		$obj = call_user_func( [ $one['class'], 'instance' ] );
		call_user_func( [ $obj, $one['method'] ] );
	}
}
