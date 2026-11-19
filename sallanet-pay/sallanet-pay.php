<?php
/**
 * Plugin Name: sallanet Pay for WooCommerce
 * Plugin URI: https://github.com/moaazelsharkawy/sallanet-pay-woocommerce
 * Description: Accept Pi and ST payments in WooCommerce through sallanet Pay (hosted checkout, signed webhooks, refunds).
 * Version: 0.5.0
 * Author: sallanet
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * WC requires at least: 8.0
 * WC tested up to: 10.3
 * Text Domain: sallanet-pay
 * Domain Path: /languages
 * License: GPLv2 or later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SALLANET_PAY_VERSION', '0.5.0' );
define( 'SALLANET_PAY_FILE', __FILE__ );
define( 'SALLANET_PAY_DIR', plugin_dir_path( __FILE__ ) );
define( 'SALLANET_PAY_URL', plugin_dir_url( __FILE__ ) );

// Domain ownership check: serves the code from the dashboard at
// /.well-known/sallanet-pay.txt so sallanet Pay can verify this site.
// Only a well formed code is ever printed.
add_action(
	'init',
	function () {
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
		$home = rtrim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );
		if ( $path !== $home . '/.well-known/sallanet-pay.txt' && '/.well-known/sallanet-pay.txt' !== $path ) {
			return;
		}
		$settings = get_option( 'woocommerce_sallanet_pay_settings', array() );
		$code     = is_array( $settings ) && isset( $settings['domain_token'] ) ? trim( (string) $settings['domain_token'] ) : '';
		if ( ! preg_match( '/^sallanet-pay-verify=[0-9a-f]{32}$/', $code ) ) {
			return;
		}
		nocache_headers();
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'X-Robots-Tag: noindex' );
		echo $code; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- matched by the pattern above.
		exit;
	},
	0
);

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
	'init',
	function () {
		load_plugin_textdomain( 'sallanet-pay', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
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
		add_action( 'wp_ajax_sallanet_pay_test_connection', array( 'Sallanet_Pay_Gateway', 'ajax_test_connection' ) );
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
