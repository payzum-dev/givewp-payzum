<?php
/**
 * Plugin Name: Payzum Crypto & Stablecoin Donations for Give
 * Plugin URI:  https://payzum.com
 * Description: Accept crypto and stablecoin donations (USDC/USDT, multi-chain) in GiveWP with Payzum. Donors choose the coin on the Payzum checkout. Non-custodial — funds settle to your own wallet.
 * Version:     1.3.1
 * Author:      Payzum
 * Author URI:  https://payzum.com
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: payzum-give
 * Requires at least: 5.6
 * Requires PHP: 8.1
 * Requires Plugins: give
 *
 * Targets GiveWP's next-gen payment gateway API (3.x and later; verified against 4.16).
 * Disclosure: contributed by Payzum. Opt-in gateway; does not change default behaviour.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

define( 'PAYZUM_GIVE_VERSION', '1.3.0' );
define( 'PAYZUM_GIVE_PLUGIN_FILE', __FILE__ );
define( 'PAYZUM_GIVE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

/** Kept for backwards compatibility with anything that referenced the old constant. */
const PAYZUM_GIVE_ID = 'payzum';

/**
 * Boot once GiveWP is loaded.
 *
 * Everything lives behind this check — including the IPN listener, which used to be registered
 * unconditionally and returned a fatal 500 (rather than a 4xx) when GiveWP was inactive. Payzum retries
 * a delivery about six times either way — verified 2026-08-26, a 4xx does NOT stop the retries the
 * way the prose docs claim — so the listener answered a PHP fatal six times over. Booting behind
 * the guard makes it a clean 404 instead.
 */
add_action( 'plugins_loaded', 'payzum_give_init', 11 );

function payzum_give_init() {
	if ( ! class_exists( 'Give' ) ) {
		add_action( 'admin_notices', 'payzum_give_missing_give_notice' );
		return;
	}

	// The official payzum/payzum-php SDK, vendored. Namespaced (Payzum\*), so it coexists with
	// the other Payzum WordPress plugins' copies: whichever autoloader registers first serves it.
	require_once PAYZUM_GIVE_PLUGIN_DIR . 'vendor/autoload.php';
	require_once PAYZUM_GIVE_PLUGIN_DIR . 'includes/class-payzum-give-plugin.php';

	payzum_give_maybe_upgrade();

	new Payzum_Give_Plugin();

	add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'payzum_give_settings_link' );
}

/**
 * Register the next-gen gateway. GiveWP fires this once its PaymentGateway base class exists, which
 * is the only moment the subclass can legally be declared.
 */
add_action( 'givewp_register_payment_gateway', function ( $registrar ) {
	require_once PAYZUM_GIVE_PLUGIN_DIR . 'vendor/autoload.php';
	require_once PAYZUM_GIVE_PLUGIN_DIR . 'includes/class-payzum-give-plugin.php';
	require_once PAYZUM_GIVE_PLUGIN_DIR . 'includes/class-payzum-give-gateway.php';

	// Do not register the gateway until it can actually complete a donation.
	//
	// Without a webhook secret the IPN cannot be verified, so every genuine delivery is rejected
	// and the donation never leaves pending — the donor pays and the site never knows. That costs
	// real money and gives the site owner no signal, so the gateway stays unregistered rather than
	// accept a payment it cannot settle. Same for the API key, without which the invoice cannot be
	// created at all.
	if ( class_exists( 'Payzum_Give_Gateway' ) && array() === payzum_give_missing_credentials() ) {
		$registrar->registerGateway( Payzum_Give_Gateway::class );
	}
} );

/**
 * Credentials that must be present before the gateway can take a donation.
 *
 * @return string[] Names of the missing settings, empty when everything is configured.
 */
function payzum_give_missing_credentials() {
	if ( ! function_exists( 'give_get_option' ) ) {
		return array();
	}
	$missing = array();
	if ( '' === trim( (string) give_get_option( 'payzum_api_key', '' ) ) ) {
		$missing[] = __( 'API key', 'payzum-give' );
	}
	if ( '' === trim( (string) give_get_option( 'payzum_webhook_secret', '' ) ) ) {
		$missing[] = __( 'webhook secret', 'payzum-give' );
	}
	return $missing;
}

/** Admin notice naming what is missing — the donation form gives no clue on its own. */
add_action( 'admin_notices', function () {
	if ( ! current_user_can( 'manage_give_settings' ) ) {
		return;
	}
	$missing = payzum_give_missing_credentials();
	if ( array() === $missing ) {
		return;
	}
	printf(
		'<div class="notice notice-error"><p><strong>%s</strong> %s</p></div>',
		esc_html__( 'Payzum cannot take donations.', 'payzum-give' ),
		esc_html(
			sprintf(
				/* translators: %s: comma-separated list of missing settings */
				__( 'Missing: %s. The gateway is hidden on donation forms until these are set, because without them a donor could pay an invoice that never settles.', 'payzum-give' ),
				implode( ', ', $missing )
			)
		)
	);
} );

function payzum_give_settings_link( $links ) {
	$url  = admin_url( 'edit.php?post_type=give_forms&page=give-settings&tab=gateways&section=payzum' );
	$link = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'payzum-give' ) . '</a>';
	array_unshift( $links, $link );
	return $links;
}

function payzum_give_missing_give_notice() {
	echo '<div class="notice notice-error"><p>'
		. esc_html__( 'Payzum Crypto Donations requires GiveWP to be installed and active.', 'payzum-give' )
		. '</p></div>';
}

/**
 * One-time upgrade routine.
 *
 * 1.1.0 moved coin selection out of the plugin: the donor now picks on the Payzum hosted checkout,
 * limited to the merchant's allowlist (Payzum dashboard -> Merchants -> Settings -> Accepted
 * tokens). The old single "Settlement currency" is therefore meaningless — and its default,
 * usdttrc20, carries a $100 network minimum, so an untouched install rejected every ordinary
 * donation with AMOUNT_BELOW_MINIMUM. Drop it rather than leave a setting that can only do harm.
 *
 * The IPN signature header is fixed by the platform, so the field that let a fundraiser get it
 * wrong goes too.
 */
function payzum_give_maybe_upgrade() {
	if ( get_option( 'payzum_give_version' ) === PAYZUM_GIVE_VERSION ) {
		return;
	}

	$settings = get_option( 'give_settings' );
	if ( is_array( $settings ) ) {
		$obsolete = array(
			'payzum_pay_currency', // single settlement currency; the donor now chooses
			'payzum_sig_header',   // the IPN header is fixed, never merchant-configurable
		);
		$changed = false;
		foreach ( $obsolete as $key ) {
			if ( array_key_exists( $key, $settings ) ) {
				unset( $settings[ $key ] );
				$changed = true;
			}
		}
		if ( $changed ) {
			update_option( 'give_settings', $settings );
		}
	}

	update_option( 'payzum_give_version', PAYZUM_GIVE_VERSION );
}
