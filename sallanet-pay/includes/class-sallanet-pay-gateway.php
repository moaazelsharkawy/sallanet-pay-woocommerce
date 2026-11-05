<?php
/**
 * WooCommerce payment gateway for sallanet Pay.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Sallanet_Pay_Gateway extends WC_Payment_Gateway {

	const ID = 'sallanet_pay';

	// Statuses that mean the buyer has paid (escrow = paid, waiting for settlement).
	const PAID_STATUSES = array( 'escrow', 'confirmed', 'completed' );

	const META_TX       = '_sallanet_tx_id';
	const META_AMOUNT   = '_sallanet_coin_amount';
	const META_CURRENCY = '_sallanet_coin_currency';
	const META_REFUNDED = '_sallanet_coin_refunded';

	public function __construct() {
		$this->id                 = self::ID;
		$this->method_title       = __( 'sallanet Pay', 'sallanet-pay' );
		$this->method_description = __( 'Customers pay in Pi or ST on the secure sallanet Pay page, then return to your store. Payment is held in escrow until the buyer confirms or the hold period ends.', 'sallanet-pay' );
		$this->has_fields         = false;
		$this->supports           = array( 'products', 'refunds' );
		$this->icon               = SALLANET_PAY_URL . 'assets/icon.svg';

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = $this->get_option( 'title' );
		$this->description = $this->get_option( 'description' );
		$this->enabled     = $this->get_option( 'enabled' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'woocommerce_thankyou_' . $this->id, array( $this, 'verify_on_return' ), 5 );
		add_action( 'woocommerce_order_status_completed', array( $this, 'maybe_release_on_complete' ) );
	}

	public function init_form_fields() {
		$this->form_fields = array(
			'enabled'          => array(
				'title'   => __( 'Enable', 'sallanet-pay' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable sallanet Pay', 'sallanet-pay' ),
				'default' => 'no',
			),
			'title'            => array(
				'title'   => __( 'Title', 'sallanet-pay' ),
				'type'    => 'text',
				'default' => __( 'Pay with Pi (sallanet Pay)', 'sallanet-pay' ),
			),
			'description'      => array(
				'title'   => __( 'Description', 'sallanet-pay' ),
				'type'    => 'textarea',
				'default' => __( 'You will be taken to sallanet Pay to complete the payment securely.', 'sallanet-pay' ),
			),
			'api_key'          => array(
				'title'       => __( 'API key', 'sallanet-pay' ),
				'type'        => 'password',
				'description' => __( 'From your sallanet Pay developer dashboard. It is shown only once when created.', 'sallanet-pay' ),
				'default'     => '',
			),
			'app_id'           => array(
				'title'       => __( 'App ID (optional)', 'sallanet-pay' ),
				'type'        => 'text',
				'description' => __( 'Needed only if your key has more than one linked app.', 'sallanet-pay' ),
				'default'     => '',
			),
			'webhook_secret'   => array(
				'title'       => __( 'Webhook secret', 'sallanet-pay' ),
				'type'        => 'password',
				'description' => sprintf(
					/* translators: %s: webhook URL */
					__( 'Set this webhook URL in your sallanet Pay dashboard: %s', 'sallanet-pay' ),
					'<code>' . esc_html( rest_url( 'sallanet-pay/v1/webhook' ) ) . '</code>'
				),
				'default'     => '',
			),
			'coin'             => array(
				'title'   => __( 'Charge in', 'sallanet-pay' ),
				'type'    => 'select',
				'options' => array(
					'pi' => 'Pi',
					'st' => 'ST',
				),
				'default' => 'pi',
			),
			'pricing'          => array(
				'title'       => __( 'Price conversion', 'sallanet-pay' ),
				'type'        => 'select',
				'options'     => array(
					'direct'   => __( 'Prices are already in the coin (store currency PI or ST)', 'sallanet-pay' ),
					'usd_live' => __( 'USD prices, convert with the live Pi price', 'sallanet-pay' ),
					'fixed'    => __( 'Fixed rates only (set below)', 'sallanet-pay' ),
				),
				'default'     => 'direct',
				'description' => __( 'How the order total is turned into coins. An order already in PI or ST is never converted. Any currency listed in Fixed rates uses that rate.', 'sallanet-pay' ),
			),
			'fixed_rate'       => array(
				'title'       => __( 'Fixed rates', 'sallanet-pay' ),
				'type'        => 'textarea',
				'description' => __( 'How many units of a currency equal 1 coin. One per line, like EGP=50 and USD=1.5. A number alone applies to the store currency. Add every currency your currency switcher plugin offers.', 'sallanet-pay' ),
				'default'     => '',
			),
			'release_on_complete' => array(
				'title'       => __( 'Instant settlement', 'sallanet-pay' ),
				'type'        => 'checkbox',
				'label'       => __( 'Release escrow when the order is marked Completed', 'sallanet-pay' ),
				'description' => __( 'Only for verified digital stores that have instant settlement enabled by sallanet.', 'sallanet-pay' ),
				'default'     => 'no',
			),
			'api_base'         => array(
				'title'       => __( 'API base URL', 'sallanet-pay' ),
				'type'        => 'text',
				'description' => __( 'Leave empty unless told otherwise.', 'sallanet-pay' ),
				'default'     => '',
			),
		);
	}

	public function api() {
		return new Sallanet_Pay_API( $this->get_option( 'api_key' ), $this->get_option( 'api_base' ) );
	}

	public function coin() {
		return 'st' === $this->get_option( 'coin' ) ? 'st' : 'pi';
	}

	public function is_available() {
		if ( ! parent::is_available() || '' === trim( (string) $this->get_option( 'api_key' ) ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Fixed rates as CODE => rate. A bare number is keyed to the store base currency.
	 */
	public function fixed_rates() {
		$rates = array();
		$parts = preg_split( '/[\r\n;]+/', (string) $this->get_option( 'fixed_rate' ) );
		foreach ( $parts as $part ) {
			$part = trim( $part );
			if ( '' === $part ) {
				continue;
			}
			if ( preg_match( '/^([A-Za-z]{2,5})\s*[=:]\s*([0-9]+(?:[.,][0-9]+)?)$/', $part, $m ) ) {
				$code = strtoupper( $m[1] );
				$rate = (float) str_replace( ',', '.', $m[2] );
			} elseif ( preg_match( '/^[0-9]+(?:[.,][0-9]+)?$/', $part ) ) {
				$code = strtoupper( get_option( 'woocommerce_currency' ) );
				$rate = (float) str_replace( ',', '.', $part );
			} else {
				continue;
			}
			if ( $rate > 0 ) {
				$rates[ $code ] = $rate;
			}
		}
		return $rates;
	}

	/**
	 * Converts an amount in the order currency to coins. Returns 0 when it cannot.
	 * Uses the order currency, so currency switcher plugins work when each of
	 * their currencies has a fixed rate (or is USD with live pricing).
	 */
	public function to_coin( $amount, $currency ) {
		$amount   = (float) $amount;
		$currency = strtoupper( (string) $currency );
		$coin     = $this->coin();
		$rates    = $this->fixed_rates();
		$coins    = 0.0;
		if ( strtoupper( $coin ) === $currency ) {
			$coins = $amount;
		} elseif ( isset( $rates[ $currency ] ) ) {
			$coins = $amount / $rates[ $currency ];
		} elseif ( 'usd_live' === $this->get_option( 'pricing' ) && 'pi' === $coin && 'USD' === $currency ) {
			$price = $this->api()->pi_usd_price();
			if ( $price > 0 ) {
				$coins = $amount / $price;
			}
		}
		// The API accepts at most 7 decimals; round up so the store never gets less.
		return $coins > 0 ? ceil( $coins * 1e7 - 1e-6 ) / 1e7 : 0.0;
	}

	/**
	 * Keys only administrators may see or change (shop managers cannot).
	 */
	const ADMIN_ONLY = array( 'api_key', 'webhook_secret', 'app_id', 'api_base' );

	public function get_form_fields() {
		$fields = parent::get_form_fields();
		if ( is_admin() && did_action( 'init' ) && ! current_user_can( 'manage_options' ) ) {
			foreach ( self::ADMIN_ONLY as $key ) {
				if ( isset( $fields[ $key ] ) ) {
					// 'title' fields are display only and are never saved, so the stored value stays.
					$fields[ $key ] = array(
						'title'       => $fields[ $key ]['title'],
						'type'        => 'title',
						'description' => __( 'Only site administrators can see or change this.', 'sallanet-pay' ),
					);
				}
			}
		}
		return $fields;
	}

	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return array( 'result' => 'failure' );
		}

		$coins = $this->to_coin( $order->get_total(), $order->get_currency() );
		if ( $coins <= 0 ) {
			wc_add_notice( __( 'This payment method cannot price this order right now. Please try again or choose another method.', 'sallanet-pay' ), 'error' );
			$order->add_order_note( __( 'sallanet Pay: could not convert the order total. Check the price conversion settings.', 'sallanet-pay' ) );
			return array( 'result' => 'failure' );
		}

		$result = $this->api()->create_checkout(
			$coins,
			$this->coin(),
			sprintf(
				/* translators: 1: order number, 2: site name */
				__( 'Order #%1$s at %2$s', 'sallanet-pay' ),
				$order->get_order_number(),
				wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES )
			),
			$this->get_return_url( $order ),
			$order->get_cancel_order_url_raw(),
			$this->get_option( 'app_id' )
		);

		if ( ! $result['ok'] || empty( $result['data']['checkout_url'] ) || empty( $result['data']['transaction_id'] ) ) {
			wc_add_notice( __( 'Could not start the payment. Please try again in a moment.', 'sallanet-pay' ), 'error' );
			$order->add_order_note( 'sallanet Pay: checkout creation failed (' . sanitize_text_field( $result['error'] ) . ')' );
			return array( 'result' => 'failure' );
		}

		$tx_id = sanitize_text_field( $result['data']['transaction_id'] );
		$order->update_meta_data( self::META_TX, $tx_id );
		$order->update_meta_data( self::META_AMOUNT, wc_format_decimal( $coins, 7 ) );
		$order->update_meta_data( self::META_CURRENCY, $this->coin() );
		$order->update_meta_data( self::META_REFUNDED, '0' );
		$order->set_transaction_id( $tx_id );
		$order->update_status(
			'pending',
			sprintf(
				/* translators: 1: amount, 2: coin, 3: transaction id */
				__( 'sallanet Pay checkout created for %1$s %2$s (transaction %3$s). Waiting for payment.', 'sallanet-pay' ),
				wc_format_decimal( $coins, 7 ),
				strtoupper( $this->coin() ),
				$tx_id
			)
		);
		$order->save();

		return array(
			'result'   => 'success',
			'redirect' => esc_url_raw( $result['data']['checkout_url'] ),
		);
	}

	/**
	 * Marks the order paid if the transaction is paid and matches the order.
	 * Safe to call many times (webhook, return page).
	 */
	public static function settle_order( $order, $tx_id, $status, $amount, $currency, $source ) {
		if ( ! $order || $order->get_payment_method() !== self::ID ) {
			return false;
		}
		if ( (string) $order->get_meta( self::META_TX ) !== (string) $tx_id ) {
			return false;
		}
		if ( ! in_array( (string) $status, self::PAID_STATUSES, true ) ) {
			return false;
		}
		$expected = (float) $order->get_meta( self::META_AMOUNT );
		if ( abs( (float) $amount - $expected ) > 1e-6 || strtolower( (string) $currency ) !== (string) $order->get_meta( self::META_CURRENCY ) ) {
			$order->add_order_note( 'sallanet Pay: paid amount does not match the order. Not marked as paid (' . esc_html( $source ) . ').' );
			return false;
		}
		if ( $order->is_paid() ) {
			return true;
		}
		$order->add_order_note(
			sprintf(
				/* translators: 1: amount, 2: coin, 3: source */
				__( 'sallanet Pay: payment of %1$s %2$s received and held in escrow (%3$s).', 'sallanet-pay' ),
				wc_format_decimal( $amount, 7 ),
				strtoupper( (string) $currency ),
				$source
			)
		);
		$order->payment_complete( $tx_id );
		return true;
	}

	/**
	 * Return page check, so the order is updated even before the webhook arrives.
	 */
	public function verify_on_return( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || $order->is_paid() ) {
			return;
		}
		$tx_id = (string) $order->get_meta( self::META_TX );
		if ( '' === $tx_id ) {
			return;
		}
		$result = $this->api()->transaction_status( $tx_id );
		if ( ! $result['ok'] || empty( $result['data']['transaction'] ) ) {
			return;
		}
		$tx = $result['data']['transaction'];
		self::settle_order( $order, $tx_id, $tx['status'] ?? '', $tx['amount'] ?? 0, $tx['currency'] ?? '', 'return page' );
	}

	public function maybe_release_on_complete( $order_id ) {
		if ( 'yes' !== $this->get_option( 'release_on_complete' ) ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order || $order->get_payment_method() !== self::ID || $order->get_meta( '_sallanet_released' ) ) {
			return;
		}
		$tx_id = (string) $order->get_meta( self::META_TX );
		if ( '' === $tx_id ) {
			return;
		}
		$result = $this->api()->confirm( $tx_id );
		if ( $result['ok'] ) {
			$order->update_meta_data( '_sallanet_released', '1' );
			$order->add_order_note( __( 'sallanet Pay: escrow released to your balance.', 'sallanet-pay' ) );
		} else {
			$order->add_order_note( 'sallanet Pay: release failed (' . sanitize_text_field( $result['error'] ) . ')' );
		}
		$order->save();
	}

	public function can_refund_order( $order ) {
		return $order && '' !== (string) $order->get_meta( self::META_TX ) && $order->is_paid() && parent::can_refund_order( $order );
	}

	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return new WP_Error( 'sallanet_refund', __( 'Order not found.', 'sallanet-pay' ) );
		}
		$tx_id = (string) $order->get_meta( self::META_TX );
		$total = (float) $order->get_total();
		$coins = (float) $order->get_meta( self::META_AMOUNT );
		if ( '' === $tx_id || $total <= 0 || $coins <= 0 ) {
			return new WP_Error( 'sallanet_refund', __( 'This order has no sallanet Pay transaction.', 'sallanet-pay' ) );
		}
		$amount = null === $amount ? $total : (float) $amount;
		if ( $amount <= 0 ) {
			return new WP_Error( 'sallanet_refund', __( 'Refund amount must be more than zero.', 'sallanet-pay' ) );
		}

		// sallanet Pay accepts one refund per transaction (full or partial).
		if ( (float) $order->get_meta( self::META_REFUNDED ) > 0 ) {
			return new WP_Error( 'sallanet_refund', __( 'sallanet Pay allows one refund per order and this order was already refunded.', 'sallanet-pay' ) );
		}

		// Refund in coins at the rate the buyer paid.
		$is_full    = abs( $amount - $total ) < 0.005;
		$coin_value = $is_full ? $coins : min( $coins, floor( $coins * ( $amount / $total ) * 1e7 + 1e-6 ) / 1e7 );
		if ( $coin_value <= 0 ) {
			return new WP_Error( 'sallanet_refund', __( 'Refund amount is too small.', 'sallanet-pay' ) );
		}

		$send   = abs( $coin_value - $coins ) < 1e-7 ? null : $coin_value;
		$result = $this->api()->refund( $tx_id, $send );
		if ( ! $result['ok'] ) {
			return new WP_Error( 'sallanet_refund', 'sallanet Pay: ' . sanitize_text_field( $result['error'] ) );
		}

		$order->update_meta_data( self::META_REFUNDED, wc_format_decimal( $coin_value, 7 ) );
		$order->add_order_note(
			sprintf(
				/* translators: 1: amount, 2: coin, 3: reason */
				__( 'sallanet Pay: refunded %1$s %2$s to the buyer. %3$s', 'sallanet-pay' ),
				wc_format_decimal( $coin_value, 7 ),
				strtoupper( (string) $order->get_meta( self::META_CURRENCY ) ),
				sanitize_text_field( $reason )
			)
		);
		$order->save();
		return true;
	}
}
