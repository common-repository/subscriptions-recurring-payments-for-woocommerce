<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

define( 'AWC_PAYPAL_PPEC_VERSION', '2.1.3' );

/**
 * Return instance of AWC_Paypal_Payment_Methods_for_Subscription.
 *
 * @return AWC_Paypal_Payment_Methods_for_Subscription
 */
function awc_paypal_ec_gateway() {
	static $plugin;

	if ( ! isset( $plugin ) ) {
		require_once 'inc_files/class-awc-paypal-payment-methods-for-subscription.php';

		$plugin = new AWC_Paypal_Payment_Methods_for_Subscription( __FILE__, AWC_PAYPAL_PPEC_VERSION );
	}

	return $plugin;
}

awc_paypal_ec_gateway()->maybe_run();


/**
 * Adds the WooCommerce Inbox option on plugin activation
 *
 */
if ( ! function_exists( 'add_woocommerce_inbox_variant' ) ) {
	function add_woocommerce_inbox_variant() {
		$option = 'woocommerce_inbox_variant_assignment';

		if ( false === get_option( $option, false ) ) {
			update_option( $option, wp_rand( 1, 12 ) );
		}
	}
}
register_activation_hook( AWC_FILE, 'add_woocommerce_inbox_variant' );