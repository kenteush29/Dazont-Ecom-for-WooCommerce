<?php
/**
 * Google Ads: where an order came from, the threshold, the report's key, the script.
 *
 * Run before every release:  php tools/test-ads.php dazont-ecom
 *
 * « Comparer ventes google ads aux ventes hors google ads. Outil pour couper
 * la pub passé un certain seuil. » (05/10/2026). These are the rules the
 * screens and the automatic cut stand on; the reading of real orders and of a
 * report was run on Kula (no write, a temporary table).
 */
$dir = $argv[1] ?? 'dazont-ecom';

define( 'ABSPATH', '/wp/' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );

$GLOBALS['opts'] = [ 'home' => 'https://shop.example' ];
function add_action() {} function add_filter() {} function do_action() {}
function is_admin() { return false; }
function get_option( $k, $d = false ) { return $GLOBALS['opts'][ $k ] ?? $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['opts'][ $k ] = $v; return true; }
function delete_option( $k ) { unset( $GLOBALS['opts'][ $k ] ); return true; }
function wp_generate_password( $n = 12, $s = true, $x = false ) { return substr( str_repeat( bin2hex( random_bytes( 32 ) ), 2 ), 0, $n ); }
function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }
function untrailingslashit( $s ) { return rtrim( $s, '/\\' ); }

require "$dir/includes/class-sales.php";
require "$dir/includes/class-ads.php";

$wrong = 0;
$same  = static function ( string $what, $got, $want ) use ( &$wrong ): void {
	$ok = $got === $want;
	if ( ! $ok ) {
		$wrong++;
	}
	printf( "  %s %s%s\n", $ok ? 'ok   ' : 'WRONG', $what, $ok ? '' : ' — got ' . var_export( $got, true ) . ', want ' . var_export( $want, true ) );
};

echo "Where an order came from (WooCommerce's attribution, as Kula records it)\n";
$same( 'google / cpc with a click id: an ad', DZE_Sales::channel( 'utm', 'google', 'cpc', 'https://kula-tactical.com/p?gad_source=1&gclid=Cj0' ), 'ads' );
$same( '« typed in » but landed with gad_source: an ad', DZE_Sales::channel( 'typein', '(direct)', '', 'https://kula-tactical.com/p?gad_source=1' ), 'ads' );
$same( 'Google Ads / CPC, tagged by hand', DZE_Sales::channel( 'utm', 'Google Ads', 'CPC', 'https://kula-tactical.com/' ), 'ads' );
$same( 'Google+Ads / CPC', DZE_Sales::channel( 'utm', 'Google+Ads', 'CPC', '' ), 'ads' );
$same( 'GoogleAds / CPC', DZE_Sales::channel( 'utm', 'GoogleAds', 'CPC', '' ), 'ads' );
$same( 'a free product listing (srsltid)', DZE_Sales::channel( 'organic', 'google', 'organic', 'https://kula-tactical.com/p?srsltid=AfmBOo' ), 'shopping' );
$same( 'Google search', DZE_Sales::channel( 'organic', 'google', 'organic', 'https://kula-tactical.com/' ), 'search' );
$same( 'typed in', DZE_Sales::channel( 'typein', '(direct)', '', '' ), 'direct' );
$same( 'a Klaviyo email', DZE_Sales::channel( 'utm', 'Klaviyo', 'flow', '' ), 'email' );
$same( 'another site', DZE_Sales::channel( 'referral', 'duckduckgo.com', 'referral', '' ), 'referral' );
$same( 'anything else', DZE_Sales::channel( 'utm', 'chatgpt.com', '', '' ), 'other' );
$same( 'Google without a paid medium is not an ad', DZE_Sales::channel( 'utm', 'google', 'organic', '' ), 'other' );

echo "The threshold\n";
$rule = [ 'auto' => false, 'spend' => 30.0, 'roas' => 1.0, 'span' => 30 ];
$same( 'spent the threshold and sold nothing: past it', DZE_Ads::signal( [ 'cost' => 50, 'ads_revenue' => 0, 'value' => 0 ], $rule ), 'over' );
$same( 'spent it and brought more back: fine', DZE_Ads::signal( [ 'cost' => 50, 'ads_revenue' => 60, 'value' => 0 ], $rule ), '' );
$same( 'the shop saw little, Google counted more: the benefit of the doubt', DZE_Ads::signal( [ 'cost' => 50, 'ads_revenue' => 20, 'value' => 80 ], $rule ), '' );
$same( 'under the threshold: never cut', DZE_Ads::signal( [ 'cost' => 20, 'ads_revenue' => 0, 'value' => 0 ], $rule ), '' );
$same( 'sells without ads, no spend: budget may be missing', DZE_Ads::signal( [ 'cost' => 0, 'other_orders' => 3, 'other_revenue' => 300 ], $rule ), 'unfunded' );
$same( 'a little spend still counts as unfunded', DZE_Ads::signal( [ 'cost' => 10, 'other_orders' => [ 1 => true, 2 => true ], 'other_revenue' => 300 ], $rule ), 'unfunded' );
$same( 'one order is not a pattern', DZE_Ads::signal( [ 'cost' => 0, 'other_orders' => 1, 'other_revenue' => 300 ], $rule ), '' );
$same( 'past the threshold comes first', DZE_Ads::signal( [ 'cost' => 40, 'ads_revenue' => 0, 'value' => 0, 'other_orders' => 3, 'other_revenue' => 900 ], $rule ), 'over' );

echo "The threshold's settings\n";
$GLOBALS['opts']['dze_ads_rule'] = [ 'auto' => 1, 'spend' => 50, 'roas' => 2, 'span' => 90 ];
$same( 'another form saved: what is stored stays', DZE_Ads::sanitize_rule( null ), [ 'auto' => true, 'spend' => 50.0, 'roas' => 2.0, 'span' => 90 ] );
$same( 'the switch unticked posts nothing: off', DZE_Ads::sanitize_rule( [ 'spend' => '45,5' ] ), [ 'auto' => false, 'spend' => 45.5, 'roas' => 2.0, 'span' => 90 ] );
$same( 'a period that is not offered: 30 days', DZE_Ads::sanitize_rule( [ 'auto' => '1', 'span' => '7' ] )['span'], 30 );
$same( 'a threshold of nothing: at least 1', DZE_Ads::sanitize_rule( [ 'spend' => '0' ] )['spend'], 1.0 );

echo "The report's key\n";
$key  = DZE_Ads::secret();
$body = '{"account":"1234567890","spans":{}}';
$same( 'a key is made the first time', strlen( $key ) >= 32, true );
$same( 'and kept', DZE_Ads::secret(), $key );
$same( 'a report signed with it is accepted', DZE_Ads::signed( $body, hash_hmac( 'sha256', $body, $key ) ), true );
$same( 'a report changed after signing is refused', DZE_Ads::signed( $body . ' ', hash_hmac( 'sha256', $body, $key ) ), false );
$same( 'an unsigned report is refused', DZE_Ads::signed( $body, '' ), false );
$old = $key;
$same( 'a new key refuses the old script', DZE_Ads::signed( $body, hash_hmac( 'sha256', $body, $old ) ) && DZE_Ads::new_secret() !== $old && ! DZE_Ads::signed( $body, hash_hmac( 'sha256', $body, $old ) ), true );

echo "The script pasted into Google Ads\n";
$js = DZE_Ads::script();
$same( 'it sends to the shop\'s main address', false !== strpos( $js, "var SHOP = 'https://shop.example/wp-json/dazont/v1/ads-report';" ), true );
$same( 'with the shop\'s key', false !== strpos( $js, "var KEY = '" . DZE_Ads::secret() . "';" ), true );
$same( 'it reads the product report, Performance Max included', false !== strpos( $js, 'FROM shopping_performance_view' ), true );
$same( 'over explicit dates (Google has no LAST_90_DAYS)', false !== strpos( $js, "segments.date BETWEEN '" ) && false === strpos( $js, 'LAST_90_DAYS' ), true );
$same( 'the three spans', false !== strpos( $js, '[7, 30, 90].forEach' ), true );
$same( 'signed in UTF-8, as PHP checks it', false !== strpos( $js, 'computeHmacSha256Signature(body, KEY, Utilities.Charset.UTF_8)' ), true );
$same( 'it changes nothing in the account', 0 === preg_match( '/\.(pause|enable|remove|set[A-Z]\w*|mutate|newCampaign)\(/', $js ), true );
$same( 'a refusal shows in Google Ads\' own log', false !== strpos( $js, "throw new Error('The shop refused the report" ), true );

echo $wrong ? "\n$wrong wrong\n" : "\nall right\n";
