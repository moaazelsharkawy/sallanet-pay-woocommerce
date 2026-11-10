<?php
/**
 * Signed webhook receiver: POST /wp-json/sallanet-pay/v1/webhook
 *
 * Body is signed with HMAC-SHA256 (hex) of the raw body using the webhook secret,
 * sent in the X-Salla-Signature header.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Sallanet_Pay_Webhook {

	/** Seconds a signed delivery stays valid. */
	const MAX_AGE = 900;

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_route' ) );
	}

	public static function register_route() {
		register_rest_route(
			'sallanet-pay/v1',
			'/webhook',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle' ),
				'permission_callback' => '__return_true', // Authenticated by the HMAC signature below.
			)
		);
	}

	private static function gateway() {
		$gateways = WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : array();
		return isset( $gateways[ Sallanet_Pay_Gateway::ID ] ) ? $gateways[ Sallanet_Pay_Gateway::ID ] : new Sallanet_Pay_Gateway();
	}

	public static function verify_signature( $raw, $signature, $secret ) {
		$secret    = trim( (string) $secret );
		$signature = strtolower( trim( (string) $signature ) );
		if ( '' === $secret || '' === $signature ) {
			return false;
		}
		return hash_equals( hash_hmac( 'sha256', (string) $raw, $secret ), $signature );
	}

	private static function find_order( $tx_id ) {
		$orders = wc_get_orders(
			array(
				'limit'          => 1,
				'payment_method' => Sallanet_Pay_Gateway::ID,
				'meta_key'       => Sallanet_Pay_Gateway::META_TX, // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'     => $tx_id, // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);
		return $orders ? $orders[0] : null;
	}

	public static function handle( WP_REST_Request $request ) {
		$gateway   = self::gateway();
		$raw       = $request->get_body();
		$signature = $request->get_header( 'x_salla_signature' );
		if ( ! $signature ) {
			$signature = $request->get_header( 'x_webhook_signature' );
		}
		if ( ! self::verify_signature( $raw, $signature, $gateway->get_option( 'webhook_secret' ) ) ) {
			return new WP_REST_Response( array( 'error' => 'invalid signature' ), 401 );
		}

		$payload = json_decode( $raw, true );
		if ( ! is_array( $payload ) ) {
			return new WP_REST_Response( array( 'error' => 'invalid body' ), 400 );
		}
		$event = (string) ( $payload['event'] ?? '' );
		// An old signed body sent again is refused: every delivery carries the time it was sent.
		$sent_at = strtotime( (string) ( $payload['timestamp'] ?? '' ) );
		if ( ! $sent_at || abs( time() - $sent_at ) > self::MAX_AGE ) {
			return new WP_REST_Response( array( 'error' => 'stale webhook' ), 400 );
		}
		if ( 'webhook.test' === $event ) {
			return new WP_REST_Response( array( 'ok' => true ), 200 );
		}

		$tx_id = sanitize_text_field( (string) ( $payload['transaction_id'] ?? '' ) );
		$order = '' !== $tx_id ? self::find_order( $tx_id ) : null;
		if ( ! $order ) {
			// 200 so the sender does not retry for a transaction this store does not know.
			return new WP_REST_Response( array( 'ok' => true, 'ignored' => 'unknown transaction' ), 200 );
		}

		// Idempotency: skip deliveries already handled.
		$delivery = sanitize_text_field( (string) ( $payload['delivery_id'] ?? '' ) );
		$seen     = (array) $order->get_meta( '_sallanet_deliveries' );
		if ( '' !== $delivery && in_array( $delivery, $seen, true ) ) {
			return new WP_REST_Response( array( 'ok' => true, 'duplicate' => true ), 200 );
		}

		$amount   = $payload['amount'] ?? 0;
		$currency = (string) ( $payload['currency'] ?? '' );
		$status   = (string) ( $payload['status'] ?? '' );

		switch ( $event ) {
			case 'payment.success':
				// Mark paid only on the live status from sallanet, never on the body alone.
				$live = $gateway->api()->transaction_status( $tx_id );
				if ( $live['ok'] && ! empty( $live['data']['transaction'] ) ) {
					$tx = $live['data']['transaction'];
					Sallanet_Pay_Gateway::settle_order( $order, $tx_id, $tx['status'] ?? '', $tx['amount'] ?? 0, $tx['currency'] ?? '', 'webhook' );
				}
				break;
			case 'payment.confirmed':
				Sallanet_Pay_Gateway::settle_order( $order, $tx_id, 'confirmed', $order->get_meta( Sallanet_Pay_Gateway::META_AMOUNT ), $order->get_meta( Sallanet_Pay_Gateway::META_CURRENCY ), 'webhook' );
				$order->add_order_note( __( 'sallanet Pay: escrow released to your balance.', 'sallanet-pay' ) );
				break;
			case 'payment.refunded':
				$order->add_order_note(
					sprintf(
						/* translators: 1: amount, 2: coin */
						__( 'sallanet Pay: refund of %1$s %2$s confirmed.', 'sallanet-pay' ),
						wc_format_decimal( $amount, 7 ),
						strtoupper( $currency )
					)
				);
				// Refund made outside WooCommerce (sallanet dashboard or dispute): mark the order refunded.
				$full = abs( (float) $amount - (float) $order->get_meta( Sallanet_Pay_Gateway::META_AMOUNT ) ) < 1e-6;
				if ( 'refunded' === $status && $full && ! $order->get_refunds() && ! $order->has_status( 'refunded' ) ) {
					$order->update_status( 'refunded', __( 'Refunded from sallanet Pay.', 'sallanet-pay' ) );
				}
				break;
			default:
				break;
		}

		if ( '' !== $delivery ) {
			$seen[] = $delivery;
			$order->update_meta_data( '_sallanet_deliveries', array_slice( $seen, -20 ) );
		}
		$order->save();
		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}
}
