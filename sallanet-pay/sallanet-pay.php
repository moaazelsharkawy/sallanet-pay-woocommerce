<?php
/**
 * Plugin Name: sallanet Pay for WooCommerce
 * Plugin URI: https://github.com/moaazelsharkawy/sallanet-pay-woocommerce
 * Description: Accept Pi and ST payments in WooCommerce through sallanet Pay (hosted checkout, signed webhooks, refunds).
 * Version: 0.2.0
 * Author: sallanet
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * WC requires at least: 8.0
 * WC tested up to: 10.3
 * Text Domain: sallanet-pay
 * License: GPLv2 or later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SALLANET_PAY_VERSION', '0.2.0' );
define( 'SALLANET_PAY_FILE', __FILE__ );
define( 'SALLANET_PAY_DIR', plugin_dir_path( __FILE__ ) );
define( 'SALLANET_PAY_URL', plugin_dir_url( __FILE__ ) );

// HPOS and block checkout compatibility.
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
		}
	}
);

add_action(
	'plugins_loaded',
	function () {
		if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
			return;
		}
		require_once SALLANET_PAY_DIR . 'includes/class-sallanet-pay-api.php';
		require_once SALLANET_PAY_DIR . 'includes/class-sallanet-pay-gateway.php';
		require_once SALLANET_PAY_DIR . 'includes/class-sallanet-pay-webhook.php';

		add_filter(
			'woocommerce_payment_gateways',
			function ( $gateways ) {
				$gateways[] = 'Sallanet_Pay_Gateway';
				return $gateways;
			}
		);

		Sallanet_Pay_Webhook::init();
	}
);

// Optional Pi / ST store currencies, so prices can be written directly in coins.
add_filter(
	'woocommerce_currencies',
	function ( $currencies ) {
		$currencies['PI'] = __( 'Pi', 'sallanet-pay' );
		$currencies['ST'] = __( 'ST (Salla Token)', 'sallanet-pay' );
		return $currencies;
	}
);
add_filter(
	'woocommerce_currency_symbol',
	function ( $symbol, $currency ) {
		if ( 'PI' === $currency ) {
			return 'Pi';
		}
		if ( 'ST' === $currency ) {
			return 'ST';
		}
		return $symbol;
	},
	10,
	2
);

add_action(
	'woocommerce_blocks_loaded',
	function () {
		if ( ! class_exists( '\Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
			return;
		}
		require_once SALLANET_PAY_DIR . 'includes/class-sallanet-pay-blocks.php';
		add_action(
			'woocommerce_blocks_payment_method_type_registration',
			function ( $registry ) {
				$registry->register( new Sallanet_Pay_Blocks() );
			}
		);
	}
);

add_filter(
	'plugin_action_links_' . plugin_basename( __FILE__ ),
	function ( $links ) {
		$url = admin_url( 'admin.php?page=wc-settings&tab=checkout&section=sallanet_pay' );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'sallanet-pay' ) . '</a>' );
		return $links;
	}
);
