<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Duplicate of implementation in AWC_Paypal_Payment_Gateway_For_SPB.
 */


class AWC_Paypal_Payment_Gateway_For_SPB_Addons extends AWC_Paypal_Payment_Gateway_Paypal_Addons {
	public function __construct() {
		parent::__construct();

		add_action( 'woocommerce_review_order_after_submit', array( $this, 'display_paypal_button' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'payment_scripts' ) );
	}

	/**
	 * Display PayPal button on the checkout page order review.
	 */
	public function display_paypal_button() {
		wp_enqueue_script( 'wc-gateway-ppec-smart-payment-buttons' );
		?>
		<div id="woo_pp_ec_button_checkout"></div>
		<?php
	}

	/**
	 * Output script for conditionally showing Smart Payment Buttons on regular checkout.
	 *
	 */
	public function payment_scripts() {
		if ( ! awc_paypal_ec_gateway()->checkout->has_active_session() ) {
			wp_enqueue_script( 'awc-paypal-gateway-orderreview', awc_paypal_ec_gateway()->plugin_url . 'assets/js/awc-paypal-gateway-orderreview.js', array( 'jquery' ), awc_paypal_ec_gateway()->version, true );
		}
	}

	/**
	 * Save data necessary for authorizing payment to session, in order to
	 * go ahead with processing payment and bypass redirecting to PayPal.
	 *
	 * @param int $order_id
	 * @return array
	 */
	public function process_payment( $order_id ) {
		if ( isset( $_POST['payerID'] ) && isset( $_POST['paymentToken'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$session = WC()->session->get( 'paypal', new stdClass() );

			$session->checkout_completed = true;
			$session->payer_id           = $_POST['payerID']; // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash
			$session->token              = $_POST['paymentToken']; // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash

			WC()->session->set( 'paypal', $session );
		}

		return parent::process_payment( $order_id );
	}

}
