<?php
/**
 * Plugin bootstrapper.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


class AWC_Paypal_Payment_Gateway_Loader {

	/**
	 * Constructor.
	 */
	public function __construct() {
		
		
		
		require_once AWC_Paypal_Payment_Methods_for_Subscription::$includes_path . 'class-awc-paypal-payment-gateway-refund.php';
		require_once AWC_Paypal_Payment_Methods_for_Subscription::$includes_path . 'abstract-awc-paypal-gateway.php';

		require_once AWC_Paypal_Payment_Methods_for_Subscription::$includes_path . 'class-awc-paypal-payment-gateway.php';
		require_once AWC_Paypal_Payment_Methods_for_Subscription::$includes_path . 'class-awc-paypal-payment-gateway-with-credit.php';
		require_once AWC_Paypal_Payment_Methods_for_Subscription::$includes_path . 'class-awc-paypal-payment-gateway-paypal-addons.php';
		require_once AWC_Paypal_Payment_Methods_for_Subscription::$includes_path . 'class-awc-paypal-payment-gateway-for-spb.php';
		require_once AWC_Paypal_Payment_Methods_for_Subscription::$includes_path . 'class-awc-paypal-payment-gateway-spb-addons.php';

		add_filter( 'woocommerce_payment_gateways', array( $this, 'payment_gateways' ) );
	}

	/**
	 * Register the PPEC payment methods.
	 *
	 * @param array $methods Payment methods.
	 *
	 * @return array Payment methods
	 */
	public function payment_gateways( $methods ) {
		$settings = awc_paypal_ec_gateway()->settings;

		if ( 'yes' === $settings->use_spb ) {
			// if ( $this->can_use_addons() ) {
				$methods[] = 'AWC_Paypal_Payment_Gateway_For_SPB_Addons';
			// } else {
			// 	$methods[] = 'AWC_Paypal_Payment_Gateway_For_SPB';
			// }
			return $methods;
		}

		// if ( $this->can_use_addons() ) {
			$methods[] = 'AWC_Paypal_Payment_Gateway_Paypal_Addons';
		// } else {
		// 	$methods[] = 'AWC_Paypal_Payment_Gateway';
		// }

		if ( $settings->is_credit_enabled() ) {
			$methods[] = 'AWC_Paypal_Payment_Gateway_with_Credit';
		}
		
		return $methods;
	}
}
