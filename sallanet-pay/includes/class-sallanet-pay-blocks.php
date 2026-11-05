<?php
/**
 * Block checkout support.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

final class Sallanet_Pay_Blocks extends AbstractPaymentMethodType {

	protected $name = 'sallanet_pay';

	/** @var Sallanet_Pay_Gateway|null */
	private $gateway = null;

	public function initialize() {
		$this->settings = get_option( 'woocommerce_sallanet_pay_settings', array() );
		$gateways       = WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : array();
		$this->gateway  = isset( $gateways['sallanet_pay'] ) ? $gateways['sallanet_pay'] : null;
	}

	public function is_active() {
		return $this->gateway ? $this->gateway->is_available() : false;
	}

	public function get_payment_method_script_handles() {
		wp_register_script(
			'sallanet-pay-blocks',
			SALLANET_PAY_URL . 'assets/blocks.js',
			array( 'wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities' ),
			SALLANET_PAY_VERSION,
			true
		);
		return array( 'sallanet-pay-blocks' );
	}

	public function get_payment_method_data() {
		return array(
			'title'       => $this->get_setting( 'title' ),
			'description' => $this->get_setting( 'description' ),
			'icon'        => SALLANET_PAY_URL . 'assets/icon.svg',
			'supports'    => $this->gateway ? array_values( array_filter( $this->gateway->supports, array( $this->gateway, 'supports' ) ) ) : array( 'products' ),
		);
	}
}
