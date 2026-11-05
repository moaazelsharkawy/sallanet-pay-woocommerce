<?php
/**
 * Thin client for the sallanet Pay external API.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Sallanet_Pay_API {

	const DEFAULT_BASE = 'https://iagohfijczwnuihedsml.supabase.co/functions/v1';

	/** @var string */
	private $base;

	/** @var string */
	private $api_key;

	public function __construct( $api_key, $base = '' ) {
		$this->api_key = trim( (string) $api_key );
		$base          = trim( (string) $base );
		$this->base    = untrailingslashit( '' !== $base ? $base : self::DEFAULT_BASE );
	}

	/**
	 * @return array{ok:bool,status:int,data:array,error:string}
	 */
	private function request( $method, $path, $body = null ) {
		$args = array(
			'method'  => $method,
			'timeout' => 20,
			'headers' => array(
				'X-API-Key'    => $this->api_key,
				'Content-Type' => 'application/json',
				'Accept'       => 'application/json',
			),
		);
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}
		$response = wp_remote_request( $this->base . '/' . ltrim( $path, '/' ), $args );
		if ( is_wp_error( $response ) ) {
			return array(
				'ok'     => false,
				'status' => 0,
				'data'   => array(),
				'error'  => $response->get_error_message(),
			);
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		$data   = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$data   = is_array( $data ) ? $data : array();
		$ok     = $status >= 200 && $status < 300 && ! empty( $data['success'] );
		$error  = '';
		if ( ! $ok ) {
			$error = isset( $data['error'] ) && is_string( $data['error'] ) ? $data['error'] : 'HTTP ' . $status;
		}
		return array(
			'ok'     => $ok,
			'status' => $status,
			'data'   => $data,
			'error'  => $error,
		);
	}

	public function create_checkout( $amount, $currency, $description, $success_url, $cancel_url, $app_id = '' ) {
		$body = array(
			'amount'      => $amount,
			'currency'    => $currency,
			'description' => $description,
			'success_url' => $success_url,
			'cancel_url'  => $cancel_url,
		);
		if ( '' !== trim( (string) $app_id ) ) {
			$body['app_id'] = trim( (string) $app_id );
		}
		return $this->request( 'POST', 'extapi-create-checkout', $body );
	}

	public function transaction_status( $transaction_id ) {
		return $this->request( 'POST', 'extapi-transaction-status', array( 'transaction_id' => $transaction_id ) );
	}

	public function refund( $transaction_id, $amount = null ) {
		$body = array( 'transaction_id' => $transaction_id );
		if ( null !== $amount ) {
			$body['amount'] = $amount;
		}
		return $this->request( 'POST', 'extapi-refund', $body );
	}

	public function confirm( $transaction_id ) {
		return $this->request( 'POST', 'extapi-confirm', array( 'transaction_id' => $transaction_id ) );
	}

	/**
	 * Live Pi price in USD (public endpoint, cached 5 minutes on the server).
	 */
	public function pi_usd_price() {
		$cached = get_transient( 'sallanet_pay_pi_usd' );
		if ( false !== $cached && (float) $cached > 0 ) {
			return (float) $cached;
		}
		$response = wp_remote_get( $this->base . '/get-pi-price', array( 'timeout' => 15 ) );
		if ( is_wp_error( $response ) ) {
			return 0.0;
		}
		$data  = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$price = is_array( $data ) && isset( $data['price'] ) ? (float) $data['price'] : 0.0;
		if ( $price > 0 ) {
			set_transient( 'sallanet_pay_pi_usd', $price, 5 * MINUTE_IN_SECONDS );
		}
		return $price;
	}
}
