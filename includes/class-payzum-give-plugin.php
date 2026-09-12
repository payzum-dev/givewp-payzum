<?php
/**
 * Everything the Payzum GiveWP integration does that does NOT depend on GiveWP's PaymentGateway
 * base class: settings, the signed IPN listener, the modal/inline widget host, and the helpers the
 * gateway itself calls.
 *
 * Kept separate so the gateway class — which `extends PaymentGateway` and therefore can only be
 * declared once GiveWP has loaded — stays the only part with that dependency.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Payzum\Errors\PayzumException;
use Payzum\Errors\SignatureException;
use Payzum\Payzum;
use Payzum\PaymentStatus;
use Payzum\Webhooks\Verifier;

class Payzum_Give_Plugin {

	/** Gateway id, and the value of ?give-listener= that routes the IPN here. */
	const ID = 'payzum';

	/** Donation meta holding recently seen IPN event ids — retries reuse the id. */
	const EVENT_IDS_META = '_payzum_ipn_event_ids';

	/** How many past event ids to keep per donation for deduplication. */
	const EVENT_IDS_KEEP = 20;

	/** Sentinel that defers the pay-currency choice to the donor on the hosted checkout. */
	const PAY_CURRENCY_ANY = 'all';

	/**
	 * How far the settled amount may fall short of the donation before it is held.
	 *
	 * Half a cent: the donation amount is a decimal and price_amount arrives as a JSON number, so
	 * the two round differently and `==` on floats would reject good payments.
	 */
	const AMOUNT_TOLERANCE = 0.005;

	/** Seconds to wait for the per-donation IPN lock before giving up and asking for a retry. */
	const LOCK_TIMEOUT = 10;

	/** Embeddable checkout widget (modal / inline render modes). */
	const WIDGET_URL = 'https://merchant.payzum.com/widget/v1/payzum.js';

	public function __construct() {
		add_filter( 'give_get_sections_gateways', array( $this, 'register_settings_section' ) );
		add_filter( 'give_get_settings_gateways', array( $this, 'register_settings' ) );

		// Signed IPN callback (server-to-server, no session).
		add_action( 'init', array( $this, 'maybe_handle_ipn' ) );
	}

	/* ------------------------------------------------------------------------------ settings */

	public function register_settings_section( $sections ) {
		$sections[ self::ID ] = __( 'Payzum', 'payzum-give' );
		return $sections;
	}

	/**
	 * Donations → Settings → Payment Gateways → Payzum.
	 *
	 * There is deliberately no coin selector: which crypto a charity accepts is a Payzum dashboard
	 * setting, and no API endpoint exposes a merchant's allowlist for the plugin to mirror.
	 */
	public function register_settings( $settings ) {
		if ( self::ID !== give_get_current_setting_section() ) {
			return $settings;
		}

		return array(
			array(
				'id'    => 'payzum_title',
				'type'  => 'title',
				'title' => __( 'Payzum', 'payzum-give' ),
			),
			array(
				'id'   => 'payzum_api_key',
				'name' => __( 'API key', 'payzum-give' ),
				'type' => 'api_key',
				'desc' => __( 'Your Payzum API key (64-hex). Dashboard → Merchants → API key.', 'payzum-give' ),
			),
			array(
				'id'   => 'payzum_webhook_secret',
				'name' => __( 'Webhook secret', 'payzum-give' ),
				'type' => 'api_key',
				'desc' => __( 'The IPN signing secret from your Payzum webhook settings. Used to verify HMAC-SHA-512 signatures.', 'payzum-give' ),
			),
			array(
				'id'      => 'payzum_environment',
				'name'    => __( 'Environment', 'payzum-give' ),
				'type'    => 'radio_inline',
				'default' => 'production',
				'options' => array(
					'production' => __( 'Production', 'payzum-give' ),
					'staging'    => __( 'Staging / sandbox', 'payzum-give' ),
				),
				'desc'    => __( 'Staging (staging.payzum.com) is an isolated environment with its own API keys — a production key will not work there.', 'payzum-give' ),
			),
			array(
				'id'   => 'payzum_ipn_info',
				'type' => 'give_description',
				'name' => __( 'Your IPN URL', 'payzum-give' ),
				'desc' => sprintf(
					/* translators: %s: the IPN callback URL */
					__( 'Paste this into your Payzum webhook settings: %s', 'payzum-give' ),
					'<code>' . esc_html( self::ipn_url() ) . '</code>'
				),
			),
			array(
				'id'   => 'payzum_currencies_info',
				'type' => 'give_description',
				'name' => __( 'Accepted coins', 'payzum-give' ),
				'desc' => __( 'Which crypto/stablecoins you accept is configured in your Payzum dashboard, under <strong>Merchants → Settings → Accepted tokens</strong>. The donor picks one of those on the Payzum checkout — there is nothing to configure here.', 'payzum-give' ),
			),
			array(
				'id'      => 'payzum_currency_mode',
				'name'    => __( 'Currency mode', 'payzum-give' ),
				'type'    => 'radio_inline',
				'default' => 'fiat',
				'options' => array(
					'fiat'   => __( 'Fiat (default)', 'payzum-give' ),
					'crypto' => __( 'Crypto', 'payzum-give' ),
				),
				'desc'    => __( 'Fiat: Payzum converts the donation total to whichever coin the donor picks. Crypto: your amounts are already in a coin, so the donor gives that exact amount with no conversion — and no coin choice.', 'payzum-give' ),
			),
			array(
				'id'      => 'payzum_price_currency',
				'name'    => __( 'Fiat price currency', 'payzum-give' ),
				'type'    => 'select',
				'default' => '',
				'options' => self::fiat_currency_options(),
				'desc'    => __( 'Fiat mode only. Leave on <em>site currency</em> unless you know what you are doing: the amount is sent <strong>as-is</strong>, with no conversion, so picking a different currency here re-denominates the donation rather than converting it.', 'payzum-give' ),
			),
			array(
				'id'   => 'payzum_crypto_symbol',
				'name' => __( 'Crypto symbol', 'payzum-give' ),
				'type' => 'text',
				'desc' => __( 'Crypto mode only. The bare coin symbol your amounts are in — <code>usdc</code>, <code>usdt</code>, <code>btc</code>. Not the network-suffixed ticker (<code>usdcmatic</code> is set via the field below).', 'payzum-give' ),
			),
			array(
				'id'   => 'payzum_crypto_network',
				'name' => __( 'Crypto network', 'payzum-give' ),
				'type' => 'text',
				'desc' => __( 'Crypto mode only. The chain for that symbol — <code>polygon</code>, <code>tron</code>, <code>ethereum</code>, <code>solana</code>… Leave empty for a native coin such as <code>btc</code>.', 'payzum-give' ),
			),
			array(
				'id'      => 'payzum_render_mode',
				'name'    => __( 'Render mode', 'payzum-give' ),
				'type'    => 'radio_inline',
				'default' => 'redirect',
				'options' => array(
					'redirect' => __( 'Redirect', 'payzum-give' ),
					'modal'    => __( 'Modal', 'payzum-give' ),
					'inline'   => __( 'Inline', 'payzum-give' ),
				),
				'desc'    => __( 'Redirect sends the donor to the Payzum checkout. Modal and inline load the Payzum widget on your donation-confirmation page instead. If your site sends a Content-Security-Policy header, allow <code>merchant.payzum.com</code> in <code>script-src</code>, <code>frame-src</code> and <code>connect-src</code>.', 'payzum-give' ),
			),
			array(
				'id'      => 'payzum_debug',
				'name'    => __( 'Debug log', 'payzum-give' ),
				'type'    => 'checkbox',
				'desc'    => __( 'Log gateway events to the WordPress debug log, prefixed [payzum].', 'payzum-give' ),
			),
			array( 'id' => 'payzum_end', 'type' => 'sectionend' ),
		);
	}

	/** Fiat options for the price-currency select: site currency first, then GiveWP's list. */
	private static function fiat_currency_options() {
		$options = array( '' => __( 'Site currency (recommended)', 'payzum-give' ) );
		if ( function_exists( 'give_get_currencies_list' ) ) {
			foreach ( array_keys( give_get_currencies_list() ) as $code ) {
				if ( '' !== $code ) {
					$options[ strtolower( $code ) ] = $code;
				}
			}
		}
		return $options;
	}

	/* ------------------------------------------------------------- helpers used by the gateway */

	public static function ipn_url() {
		return add_query_arg( 'give-listener', self::ID, home_url( '/' ) );
	}

	/** Configured render mode, falling back to the redirect flow for any unknown value. */
	public static function render_mode() {
		$mode = (string) give_get_option( 'payzum_render_mode', 'redirect' );
		return in_array( $mode, array( 'redirect', 'modal', 'inline' ), true ) ? $mode : 'redirect';
	}

	/**
	 * The SDK entry point, pointed at the configured environment. Built per call rather than
	 * cached: the admin can save a new key or switch environment and the next request must
	 * honour it.
	 */
	public static function payzum_client() {
		$api_key = trim( (string) give_get_option( 'payzum_api_key', '' ) );

		return 'staging' === give_get_option( 'payzum_environment', 'production' )
			? Payzum::sandbox( $api_key )
			: new Payzum( $api_key );
	}

	/**
	 * A per-merchant-unique reference. GiveWP donation ids are unique per site; we suffix the
	 * purchase key so a guessed id can't be spoofed into matching.
	 */
	public static function order_reference( $donation_id, $purchase_key ) {
		return (int) $donation_id . '-' . substr( (string) $purchase_key, -8 );
	}

	/** Short human description shown on the Payzum hosted checkout (API caps this at 2000). */
	public static function order_description( $donation_id, $form_title ) {
		$text = sprintf(
			/* translators: 1: form title, 2: site name */
			__( 'Donation to %1$s at %2$s', 'payzum-give' ),
			$form_title ? $form_title : __( 'a campaign', 'payzum-give' ),
			wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES )
		);
		return mb_substr( $text, 0, 2000 );
	}

	/** Where the donor lands after giving. */
	public static function success_url( $purchase_key ) {
		return add_query_arg( 'payment-confirmation', self::ID, give_get_success_page_uri() );
	}

	public static function cancel_url() {
		return give_get_failed_transaction_uri();
	}

	/**
	 * The pricing half of the create-payment body.
	 *
	 * Fiat (default): the donor picks the coin on the Payzum checkout, so pay_currency is "all"
	 * and Payzum converts from the donation currency.
	 *
	 * Crypto: amounts are already denominated in a coin, so pricing_mode "direct" sends the total
	 * through unconverted. The API requires price_currency to equal the *bare* pay symbol
	 * (max 8 chars — `usdc`, not `usdcmatic`), with the chain in `network`.
	 *
	 * @param string $donation_currency ISO code of the donation.
	 * @param int    $donation_id       only used for logging a misconfiguration.
	 * @return array<string, string>
	 */
	public static function pricing_payload( $donation_currency, $donation_id ) {
		if ( 'crypto' === give_get_option( 'payzum_currency_mode', 'fiat' ) ) {
			$symbol  = self::crypto_symbol();
			$network = strtolower( trim( (string) give_get_option( 'payzum_crypto_network', '' ) ) );

			if ( '' !== $symbol ) {
				return array(
					'price_currency' => $symbol,
					'pay_currency'   => $symbol,
					'pricing_mode'   => 'direct',
					'network'        => '' !== $network ? $network : null,
				);
			}

			// Misconfigured: fall through to fiat rather than failing the donor's checkout outright.
			self::log( 'currency_mode=crypto but no crypto_symbol configured — falling back to fiat for donation ' . $donation_id );
		}

		$configured = strtolower( trim( (string) give_get_option( 'payzum_price_currency', '' ) ) );

		return array(
			// Empty setting = the donation's own currency, which is the only always-correct value:
			// the amount is sent as-is, never converted.
			'price_currency' => '' !== $configured ? $configured : strtolower( (string) $donation_currency ),
			// "all" defers the coin choice to the donor, limited to the merchant's allowlist.
			'pay_currency'   => self::PAY_CURRENCY_ANY,
			'pricing_mode'   => 'fiat',
			'network'        => null,
		);
	}

	/** An actionable donor-facing message for the error codes a donor can do something about. */
	public static function buyer_notice_for( $raw_code ) {
		switch ( $raw_code ) {
			case 'AMOUNT_BELOW_MINIMUM':
				return __( 'This amount is below the minimum for crypto payment. Increase the donation or choose another payment method.', 'payzum-give' );
			case 'CURRENCY_NOT_SUPPORTED':
			case 'NO_ELIGIBLE_CURRENCIES':
				return __( 'Crypto payment is not available for this currency or amount. Please choose another payment method.', 'payzum-give' );
			default:
				return __( 'Unable to start the crypto payment. Please try again or pick another method.', 'payzum-give' );
		}
	}

	/** Sanitised bare coin symbol for crypto mode ("usdc"), or '' when unset. */
	private static function crypto_symbol() {
		$symbol = strtolower( trim( (string) give_get_option( 'payzum_crypto_symbol', '' ) ) );
		$symbol = preg_replace( '/[^a-z0-9]/', '', (string) $symbol );
		return (string) substr( (string) $symbol, 0, 8 );
	}

	public static function log( $message ) {
		if ( ! give_is_setting_enabled( give_get_option( 'payzum_debug', 'disabled' ) ) ) {
			return;
		}
		error_log( '[payzum] ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}

	/* ----------------------------------------------------------------------------- IPN listener */

	/** Route ?give-listener=payzum to handle_ipn(). */
	public function maybe_handle_ipn() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['give-listener'] ) || self::ID !== $_GET['give-listener'] ) {
			return;
		}
		$this->handle_ipn();
	}

	/**
	 * Handle a signed IPN.
	 *
	 * The SDK's Verifier does the dangerous parts: it reads the correct, fixed signature header
	 * itself (case-insensitively, CGI form included), verifies HMAC-SHA-512 over the RAW bytes in
	 * constant time, and enforces the 10-minute replay window on the signed event_at. On top of
	 * that this handler deduplicates by event id — delivery retries reuse it, so a second
	 * delivery must be a no-op, not a second settlement.
	 *
	 * Verification deliberately uses `new Verifier($secret)` and not the Payzum entry class:
	 * verifying an already-paid IPN must not depend on the API key being configured.
	 */
	private function handle_ipn() {
		$raw = file_get_contents( 'php://input' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( '' === $raw || false === $raw ) {
			$this->respond( 400, 'empty body' );
		}

		$secret = (string) give_get_option( 'payzum_webhook_secret', '' );
		if ( '' === $secret ) {
			self::log( 'IPN received but no webhook secret configured.' );
			$this->respond( 500, 'not configured' );
		}

		$headers = $this->request_headers();

		try {
			$verifier = new Verifier( $secret );
			$data     = $verifier->verifyPaymentIpn( $raw, $headers );
		} catch ( SignatureException $e ) {
			self::log( 'IPN rejected (' . $e->reason . '): ' . $e->getMessage() );
			$this->respond( 401, 'bad signature' );
			return; // respond() exits; this keeps static analysis honest.
		} catch ( PayzumException $e ) {
			self::log( 'IPN body unusable: ' . $e->getMessage() );
			$this->respond( 400, 'bad json' );
			return;
		}

		$reference   = isset( $data['order_id'] ) ? (string) $data['order_id'] : '';
		$status      = isset( $data['payment_status'] ) ? (string) $data['payment_status'] : '';
		$donation_id = $this->resolve_donation_id( $reference );

		if ( ! $donation_id ) {
			self::log( 'IPN for unknown donation reference: ' . $reference );
			$this->respond( 404, 'donation not found' );
		}

		// Everything from here to the release is one critical section: read the event ids, decide
		// the transition, write it, record the event id. Two deliveries carrying DIFFERENT event
		// ids both used to read "not complete yet" and both completed the donation — two receipts
		// to the donor, and the donation counted twice in the campaign totals. The dedup check
		// belongs inside the lock too: on its own it only catches the same event id, and it is
		// itself a read-then-write.
		$lock = $this->acquire_donation_lock( $donation_id );
		if ( false === $lock ) {
			// Another delivery for this donation is mid-transition. 503 rather than 200: this IPN
			// is not a duplicate, only late, and Payzum retrying it is the right outcome.
			self::log( 'IPN for donation ' . $donation_id . ' could not take the donation lock — asking for a retry' );
			$this->respond( 503, 'busy' );
		}

		// Drop the cached donation now that the lock is held: it was read before we waited, so a
		// concurrent delivery may have completed it since and apply_status() would otherwise read
		// the same stale status and complete it a second time.
		clean_post_cache( $donation_id );

		// Retries and multi-transition deliveries reuse the event id; a replay must be a no-op.
		$event_id = (string) $verifier->eventId( $headers );
		if ( '' !== $event_id && $this->is_duplicate_event( $donation_id, $event_id ) ) {
			self::log( 'IPN duplicate event ' . $event_id . ' for donation ' . $donation_id . ' — ignored' );
			$this->release_donation_lock( $lock );
			$this->respond( 200, 'duplicate' );
		}

		// Once the donor has picked a coin the IPN carries it — record it for the fundraiser.
		if ( ! empty( $data['pay_currency'] ) ) {
			give_update_payment_meta( $donation_id, '_payzum_pay_currency', sanitize_text_field( (string) $data['pay_currency'] ) );
		}

		self::log( 'IPN verified for donation ' . $donation_id . ' status=' . $status . ( '' !== $event_id ? ' event=' . $event_id : '' ) );
		$this->apply_status( $donation_id, $status, $data );

		if ( '' !== $event_id ) {
			$this->remember_event( $donation_id, $event_id );
		}

		$this->release_donation_lock( $lock );
		$this->respond( 200, 'ok' );
	}

	/**
	 * Take a cross-request lock for this donation, for the length of the IPN transition.
	 *
	 * GET_LOCK is the only lock WordPress can count on here. wp_cache_add() is request-local
	 * unless a persistent object cache is installed, so it would look like a lock on every site
	 * that has none and protect nothing — the failure mode this guard exists to prevent.
	 *
	 * @param int $donation_id
	 * @return string|null|false lock name when acquired; false when it timed out (another
	 *                           delivery holds it); null when the database offers no lock, in
	 *                           which case the caller proceeds unlocked rather than refusing a
	 *                           donation it can still process.
	 */
	private function acquire_donation_lock( $donation_id ) {
		global $wpdb;

		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return null;
		}
		// GET_LOCK names are scoped to the whole MySQL server, not to a database or a connection,
		// so every part of "which install, which site, which plugin, which record" has to be in
		// the name or unrelated stores block each other on a shared server.
		//
		// DB_NAME separates installs. $wpdb->prefix separates the sites of a multisite network
		// and the several WordPress installs people put in one database with different prefixes.
		// The 'give-donation' tag separates THIS plugin from the Payzum plugins for WooCommerce,
		// EDD and PMPro, which all key by a small integer id on the very same site. Hashed to
		// stay inside GET_LOCK's 64-character limit.
		$name = 'payzum_' . substr( md5( DB_NAME . '|' . $wpdb->prefix . '|give-donation|' . $donation_id ), 0, 32 );

		// 1 = acquired, 0 = timed out, NULL = error (or a server without GET_LOCK).
		$got = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $name, self::LOCK_TIMEOUT ) );
		if ( '1' === (string) $got ) {
			return $name;
		}
		if ( null === $got ) {
			self::log( 'GET_LOCK unavailable — processing IPN for donation ' . $donation_id . ' without a lock' );
			return null;
		}
		return false;
	}

	/** Release a lock taken by acquire_donation_lock(). Safe to call with null (nothing taken). */
	private function release_donation_lock( $name ) {
		global $wpdb;

		if ( ! is_string( $name ) || '' === $name || ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return;
		}
		$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
	}

	/**
	 * Request headers for the Verifier. getallheaders() when the SAPI provides it, otherwise the
	 * raw $_SERVER array — the Verifier accepts the CGI form (HTTP_X_NOWPAYMENTS_SIG) directly.
	 *
	 * @return array<string, string>
	 */
	private function request_headers() {
		if ( function_exists( 'getallheaders' ) ) {
			$headers = getallheaders();
			if ( is_array( $headers ) ) {
				return $headers;
			}
		}
		return array_filter( $_SERVER, 'is_string' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- raw bytes needed for HMAC lookup; never echoed.
	}

	/** Whether this event id was already processed for the donation. */
	private function is_duplicate_event( $donation_id, $event_id ) {
		$seen = give_get_payment_meta( $donation_id, self::EVENT_IDS_META, true );
		return is_array( $seen ) && in_array( $event_id, $seen, true );
	}

	/** Record a processed event id, keeping only the most recent ones. */
	private function remember_event( $donation_id, $event_id ) {
		$seen   = give_get_payment_meta( $donation_id, self::EVENT_IDS_META, true );
		$seen   = is_array( $seen ) ? $seen : array();
		$seen[] = $event_id;
		give_update_payment_meta( $donation_id, self::EVENT_IDS_META, array_slice( $seen, -self::EVENT_IDS_KEEP ) );
	}

	/**
	 * Map a Payzum payment_status onto the donation. Idempotent — repeated IPNs for an already
	 * settled donation add neither a status change nor a duplicate note.
	 *
	 * @param int    $donation_id
	 * @param string $status
	 * @param array  $data        the verified payload — `finished` is only honoured when the
	 *                            amount and currency in it match the donation.
	 */
	private function apply_status( $donation_id, $status, array $data = array() ) {
		$current = (string) give_get_payment_status( $donation_id );

		// GiveWP stores a completed donation as "publish"; "complete" is the legacy alias.
		$settled = array( 'publish', 'complete' );

		// The SDK's PaymentStatus models the five values the contract promises (waiting,
		// partially_paid, finished, expired, failed) and throws on anything else. The guard
		// degrades an unknown value to a donation note instead of a 500: a contract change
		// should surface as a note to investigate, not as six failed delivery retries.
		try {
			$mapped = PaymentStatus::fromMerchant( $status );
		} catch ( PayzumException $e ) {
			self::log( 'IPN carried an unknown payment_status "' . $status . '" — contract change?' );
			give_insert_payment_note(
				$donation_id,
				sprintf( /* translators: %s: status */ __( 'Payzum: status update — %s.', 'payzum-give' ), $status )
			);
			return;
		}

		switch ( $mapped->value ) {
			case 'finished':
				if ( in_array( $current, $settled, true ) ) {
					return;
				}
				// `finished` only says the Payzum invoice settled — it says nothing about that
				// invoice having been for THIS donation's amount. Completing on the status alone
				// receipts a donation that was settled for less, or in a cheaper currency. The
				// payload carries both numbers, so check them before receipting.
				$mismatch = self::settlement_mismatch( $donation_id, $data );
				if ( null !== $mismatch ) {
					self::log( 'IPN finished for donation ' . $donation_id . ' rejected: ' . $mismatch );
					if ( 'processing' !== $current ) {
						// GiveWP has no "on hold"; processing is its awaiting-settlement state,
						// and it does NOT count the donation as complete.
						give_update_payment_status( $donation_id, 'processing' );
					}
					give_insert_payment_note(
						$donation_id,
						sprintf(
							/* translators: %s: what did not match, e.g. "it settled 3.00 USD while the donation is for 6.00 USD" */
							__( 'Payzum: reported as finished but %s — held for review, NOT completed.', 'payzum-give' ),
							$mismatch
						)
					);
					return;
				}
				give_update_payment_status( $donation_id, 'complete' );
				give_insert_payment_note( $donation_id, __( 'Payzum: donation confirmed in full (finished).', 'payzum-give' ) );
				break;

			case 'partially_paid':
				if ( in_array( $current, $settled, true ) || 'processing' === $current ) {
					return;
				}
				// GiveWP has no "on hold"; processing is its closest awaiting-settlement state.
				give_update_payment_status( $donation_id, 'processing' );
				give_insert_payment_note( $donation_id, __( 'Payzum: partial payment received — awaiting the remainder.', 'payzum-give' ) );
				break;

			case 'expired':
				if ( in_array( $current, $settled, true ) || 'abandoned' === $current ) {
					return;
				}
				give_update_payment_status( $donation_id, 'abandoned' );
				give_insert_payment_note( $donation_id, __( 'Payzum: invoice expired before full payment.', 'payzum-give' ) );
				break;

			case 'failed':
				if ( in_array( $current, $settled, true ) || 'failed' === $current ) {
					return;
				}
				give_update_payment_status( $donation_id, 'failed' );
				give_insert_payment_note( $donation_id, __( 'Payzum: payment failed.', 'payzum-give' ) );
				break;

			default:
				// waiting -> note only. (Refund tracking stays in GiveWP's own flow.)
				give_insert_payment_note(
					$donation_id,
					sprintf( /* translators: %s: status */ __( 'Payzum: status update — %s.', 'payzum-give' ), $mapped->value )
				);
				break;
		}
	}

	/**
	 * How the settled amount/currency differ from the donation, as a human phrase, or null when
	 * they match.
	 *
	 * Compared against the same two values the create call was built from — the donation amount,
	 * and pricing_payload()'s price_currency rather than the raw donation currency, because in
	 * crypto pricing mode the invoice is denominated in the coin symbol and the donation currency
	 * would never match.
	 *
	 * A donation the model cannot load is not a mismatch: it is unverifiable on OUR side, and
	 * refusing it would drop good donations for a reason the sender had no part in. It is logged.
	 *
	 * An amount that cannot be read as a number is a different story and IS a mismatch.
	 * price_amount is `required` in the contract, so null, "", an array or an object means either
	 * a contract break or a forged payload — and in both cases the one number that proves the
	 * invoice was raised for this donation is missing. Skipping the comparison there would
	 * complete the donation unverified, which is exactly how an attacker turns a 1-cent invoice
	 * into a completed donation. Unverifiable is treated as mismatched.
	 *
	 * @param int   $donation_id
	 * @param array $data verified payload
	 * @return string|null
	 */
	private static function settlement_mismatch( $donation_id, array $data ) {
		$has_amount    = isset( $data['price_amount'] ) && is_numeric( $data['price_amount'] );
		$paid_amount   = $has_amount ? (float) $data['price_amount'] : 0.0;
		$paid_currency = isset( $data['price_currency'] ) ? strtolower( trim( (string) $data['price_currency'] ) ) : '';

		$donation = class_exists( 'Give\Donations\Models\Donation' )
			? Give\Donations\Models\Donation::find( $donation_id )
			: null;
		if ( ! $donation ) {
			self::log( 'IPN for donation ' . $donation_id . ' could not load the donation — settlement could not be verified' );
			return null;
		}

		$expected_amount   = (float) $donation->amount->formatToDecimal();
		$pricing           = self::pricing_payload( $donation->amount->getCurrency()->getCode(), $donation_id );
		$expected_currency = strtolower( (string) $pricing['price_currency'] );

		if ( ! $has_amount ) {
			self::log( 'IPN for donation ' . $donation_id . ' carried no usable price_amount — settlement could not be verified, not crediting' );
			return sprintf(
				/* translators: 1: donation amount, 2: donation currency */
				__( 'it reported no readable settled amount, so it cannot be shown to cover the %1$s %2$s this donation is for', 'payzum-give' ),
				number_format( $expected_amount, 2, '.', '' ),
				strtoupper( $expected_currency )
			);
		}

		// Never `==` on floats, and never a strict "must be exact": an overpayment is still a
		// paid donation, so only a SHORTFALL beyond half a cent counts.
		if ( ( $expected_amount - $paid_amount ) > self::AMOUNT_TOLERANCE ) {
			return sprintf(
				/* translators: 1: settled amount, 2: settled currency, 3: donation amount, 4: donation currency */
				__( 'it settled %1$s %2$s while the donation is for %3$s %4$s', 'payzum-give' ),
				number_format( $paid_amount, 2, '.', '' ),
				'' !== $paid_currency ? strtoupper( $paid_currency ) : strtoupper( $expected_currency ),
				number_format( $expected_amount, 2, '.', '' ),
				strtoupper( $expected_currency )
			);
		}

		// Case-insensitive: the API echoes the currency in whatever case it stored it.
		if ( '' !== $paid_currency && '' !== $expected_currency && $paid_currency !== $expected_currency ) {
			return sprintf(
				/* translators: 1: settled currency, 2: expected currency */
				__( 'it settled in %1$s while the donation was invoiced in %2$s', 'payzum-give' ),
				strtoupper( $paid_currency ),
				strtoupper( $expected_currency )
			);
		}

		return null;
	}

	/**
	 * Find the donation id from the reference we sent as order_id.
	 *
	 * @return int 0 when it resolves to nothing.
	 */
	private function resolve_donation_id( $reference ) {
		if ( '' === $reference ) {
			return 0;
		}
		// We send "<id>-<last 8 of purchase key>"; the numeric prefix is the donation id.
		$donation_id = (int) $reference;
		if ( ! $donation_id || ! class_exists( 'Give\Donations\Models\Donation' ) ) {
			return 0;
		}
		$donation = Give\Donations\Models\Donation::find( $donation_id );
		if ( ! $donation ) {
			return 0;
		}
		// Pre-1.1 invoices sent the bare id, so accept that too rather than orphan them.
		$expected = self::order_reference( $donation_id, (string) $donation->purchaseKey );
		if ( $reference !== $expected && $reference !== (string) $donation_id ) {
			return 0;
		}
		return $donation_id;
	}

	private function respond( $code, $message ) {
		status_header( $code );
		nocache_headers();
		echo esc_html( $message );
		exit;
	}
}
