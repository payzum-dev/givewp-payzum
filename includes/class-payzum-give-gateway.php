<?php
/**
 * GiveWP next-gen payment gateway for Payzum.
 *
 * createPayment() creates the invoice with pay_currency:"all" and returns a RedirectOffsite — to
 * the Payzum hosted checkout in redirect mode, or back to our own confirmation page in modal /
 * inline mode, where Payzum_Give_Plugin mounts the widget.
 *
 * The donation is completed from the signed IPN, never from the donor's return, so a closed browser
 * tab can't lose a settled donation. Non-custodial: funds settle to the charity's own wallet.
 *
 * One-off donations only. GiveWP offers a gateway for recurring giving only when it implements
 * SubscriptionModule; this one deliberately does not, so a recurring form never offers Payzum
 * rather than charging once and never renewing.
 *
 * Only declared when GiveWP's PaymentGateway base class is available (guarded by the caller).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Give\Donations\Models\Donation;
use Give\Framework\PaymentGateways\Commands\PaymentComplete;
use Give\Framework\PaymentGateways\Commands\RedirectOffsite;
use Give\Framework\PaymentGateways\Commands\RespondToBrowser;
use Give\Framework\PaymentGateways\Exceptions\PaymentGatewayException;
use Give\Framework\PaymentGateways\PaymentGateway;

if ( class_exists( PaymentGateway::class ) && ! class_exists( 'Payzum_Give_Gateway' ) ) {

	class Payzum_Give_Gateway extends PaymentGateway {

		public static function id(): string {
			return Payzum_Give_Plugin::ID;
		}

		public function getId(): string {
			return self::id();
		}

		public function getName(): string {
			return __( 'Crypto / Stablecoins (Payzum)', 'payzum-give' );
		}

		public function getPaymentMethodLabel(): string {
			return __( 'Crypto / Stablecoins (Payzum)', 'payzum-give' );
		}

		/** Redirect gateway — no on-form fields; the donor picks the coin on the Payzum checkout. */
		public function getLegacyFormFieldMarkup( int $formId, array $args ): string {
			return '';
		}

		/**
		 * Register the gateway with the v3 donation-form front end.
		 *
		 * This method is not decoration: PaymentGateway::supportsFormVersions() reports version 3
		 * only for gateways that implement enqueueScript(), and version 2 only for those that
		 * implement getLegacyFormFieldMarkup(). Implementing just the latter — as this plugin did
		 * until 1.1.0 — makes the gateway invisible on every modern GiveWP form, no matter that it
		 * is registered and enabled.
		 *
		 * @param int $formId
		 */
		public function enqueueScript( int $formId ) {
			// The embeddable checkout, needed by the modal / inline render modes. Loaded first so
			// window.Payzum is normally ready by the time the donor submits.
			if ( 'redirect' !== Payzum_Give_Plugin::render_mode() ) {
				wp_enqueue_script(
					'payzum-widget',
					Payzum_Give_Plugin::WIDGET_URL,
					array(),
					PAYZUM_GIVE_VERSION,
					true
				);
			}

			wp_enqueue_script(
				'payzum-give-gateway',
				plugins_url( 'assets/payzum-gateway.js', PAYZUM_GIVE_PLUGIN_FILE ),
				array( 'react' ),
				PAYZUM_GIVE_VERSION,
				true
			);
		}

		/**
		 * Create the Payzum invoice and hand the donor onward.
		 *
		 * @param Donation $donation
		 * @param array    $gatewayData
		 * @return RedirectOffsite|RespondToBrowser|PaymentComplete
		 * @throws PaymentGatewayException
		 */
		public function createPayment( Donation $donation, $gatewayData ) {
			// A zero-amount donation has nothing to charge, and the API answers 400 INVALID_REQUEST
			// ("price_amount: Number must be greater than 0"). Complete it here rather than sending
			// the donor to a checkout that cannot succeed.
			$amount = (float) $donation->amount->formatToDecimal();
			if ( $amount <= 0 ) {
				Payzum_Give_Plugin::log( 'donation ' . $donation->id . ' has a zero amount — completing without an invoice' );
				give_insert_payment_note( $donation->id, __( 'Payzum: nothing to charge, donation completed without a crypto invoice.', 'payzum-give' ) );
				return new PaymentComplete( null );
			}

			$pricing   = Payzum_Give_Plugin::pricing_payload(
				$donation->amount->getCurrency()->getCode(),
				$donation->id
			);
			$reference = Payzum_Give_Plugin::order_reference( $donation->id, $donation->purchaseKey );

			try {
				// The amount travels as a string end to end: formatToDecimal() already returns
				// one, and the SDK writes it into the JSON as an exact number. Casting to float
				// would round it on the way out.
				$result = Payzum_Give_Plugin::payzum_client()->payments->create(
					priceAmount:      (string) $donation->amount->formatToDecimal(),
					priceCurrency:    $pricing['price_currency'],
					payCurrency:      $pricing['pay_currency'],
					orderId:          $reference,
					orderDescription: Payzum_Give_Plugin::order_description( $donation->id, $donation->formTitle ),
					network:          $pricing['network'],
					ipnCallbackUrl:   Payzum_Give_Plugin::ipn_url(),
					successUrl:       Payzum_Give_Plugin::success_url( $donation->purchaseKey ),
					cancelUrl:        Payzum_Give_Plugin::cancel_url(),
					pricingMode:      $pricing['pricing_mode'],
					// The reference is unique to this donation, so the key can be static per
					// donation: it makes a transport retry safe without ever pinning a
					// different donation to a stale invoice.
					idempotencyKey:   'give-' . $reference,
				);
			} catch ( \Payzum\Errors\ApiException $e ) {
				// Branch on the typed code, never on the message.
				Payzum_Give_Plugin::log( 'create failed for donation ' . $donation->id . ' [' . $e->rawCode . ']: ' . $e->getMessage() );
				throw new PaymentGatewayException( Payzum_Give_Plugin::buyer_notice_for( $e->rawCode ) );
			} catch ( \Payzum\Errors\PayzumException $e ) {
				Payzum_Give_Plugin::log( 'create failed for donation ' . $donation->id . ': ' . $e->getMessage() );
				throw new PaymentGatewayException(
					__( 'Unable to start the crypto payment. Please try again or pick another method.', 'payzum-give' )
				);
			}

			$invoice_url = isset( $result['invoice_url'] ) ? (string) $result['invoice_url'] : '';
			if ( '' === $invoice_url ) {
				Payzum_Give_Plugin::log( 'create_payment returned no invoice_url for donation ' . $donation->id . ': ' . wp_json_encode( $result ) );
				throw new PaymentGatewayException(
					__( 'Payzum did not return a checkout URL. Please try again.', 'payzum-give' )
				);
			}

			// Payzum returns the payment id as `payment_id` (alias `id` in older docs); store either.
			$pzid = '';
			if ( isset( $result['payment_id'] ) ) {
				$pzid = (string) $result['payment_id'];
			} elseif ( isset( $result['id'] ) ) {
				$pzid = (string) $result['id'];
			}
			if ( '' !== $pzid ) {
				$donation->gatewayTransactionId = sanitize_text_field( $pzid );
				$donation->save();
				give_update_payment_meta( $donation->id, '_payzum_payment_id', sanitize_text_field( $pzid ) );
			}
			// The invoice is a draft until the donor picks a coin, and reads don't return the URL —
			// keep it so the donation can link back to the checkout.
			give_update_payment_meta( $donation->id, '_payzum_invoice_url', esc_url_raw( $invoice_url ) );

			give_insert_payment_note( $donation->id, __( 'Payzum invoice created — awaiting payment.', 'payzum-give' ) );
			Payzum_Give_Plugin::log( 'invoice ' . $pzid . ' created for donation ' . $donation->id . ' (' . Payzum_Give_Plugin::render_mode() . ')' );

			if ( 'redirect' === Payzum_Give_Plugin::render_mode() ) {
				return new RedirectOffsite( $invoice_url );
			}

			// Modal / inline never leave the page. GiveWP renders its form in an iframe it owns and
			// only navigates the top window for an off-site RedirectOffsite — a same-origin target
			// comes back as a plain 200 and the form quietly shows its own receipt instead. So hand
			// the invoice to the gateway's own front-end handler and let it mount the widget there,
			// which is what RespondToBrowser exists for.
			return new RespondToBrowser( array(
				'payzumPaymentId' => $pzid,
				'payzumInvoice'   => $invoice_url,
				'payzumMode'      => Payzum_Give_Plugin::render_mode(),
				'payzumReturnUrl' => Payzum_Give_Plugin::success_url( $donation->purchaseKey ),
				'payzumCancelUrl' => Payzum_Give_Plugin::cancel_url(),
			) );
		}

		/**
		 * Refunds happen on-chain, outside the plugin — the gateway is non-custodial and never holds
		 * the funds. Required by the interface.
		 */
		public function refundDonation( Donation $donation ) {
			throw new PaymentGatewayException(
				__( 'Payzum is non-custodial: refunds are issued from your own wallet, not from here.', 'payzum-give' )
			);
		}
	}
}
