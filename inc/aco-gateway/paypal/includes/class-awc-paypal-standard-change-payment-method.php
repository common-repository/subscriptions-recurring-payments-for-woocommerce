<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

class AWC_PayPal_Standard_Change_Payment_Method {

	/**
	 * Bootstraps the class and hooks required actions & filters.
	 *
	 
	 */
	public static function init() {

		// Don't automatically cancel a subscription with PayPal on payment method change - we'll cancel it ourselves
		add_action( 'woocommerce_subscriptions_pre_update_payment_method', __CLASS__ . '::maybe_remove_subscription_cancelled_callback', 10, 3 );
		add_action( 'woocommerce_subscription_payment_method_updated', __CLASS__ . '::maybe_reattach_subscription_cancelled_callback', 10, 3 );

		// Don't update payment methods immediately when changing to PayPal - wait for the IPN notification
		add_filter( 'woocommerce_subscriptions_update_payment_via_pay_shortcode', __CLASS__ . '::maybe_dont_update_payment_method', 10, 3 );

		add_filter( 'awc_gateway_change_payment_button_text', __CLASS__ . '::change_payment_button_text', 10, 2 );
	}

	/**
	 * If changing a subscriptions payment method from and to PayPal, wait until an appropriate IPN message
	 * has come in before deciding to cancel the old subscription.
	 *
	 
	 */
	public static function maybe_remove_subscription_cancelled_callback( $subscription, $new_payment_method, $old_payment_method ) {
		if ( 'paypal' == $new_payment_method && 'paypal' == $old_payment_method && ! AWC_PayPal::are_reference_transactions_enabled() ) {
			remove_action( 'woocommerce_subscription_cancelled_paypal', 'AWC_PayPal_Status_Manager::cancel_subscription' );
		}
	}

	/**
	 * If changing a subscriptions payment method from and to PayPal, the cancelled subscription hook was removed in
	 * @see self::maybe_remove_cancelled_subscription_hook() so we want to add it again for other subscriptions.
	 *
	 
	 */
	public static function maybe_reattach_subscription_cancelled_callback( $subscription, $new_payment_method, $old_payment_method ) {
		if ( 'paypal' == $new_payment_method && 'paypal' == $old_payment_method && ! AWC_PayPal::are_reference_transactions_enabled() ) {
			add_action( 'woocommerce_subscription_cancelled_paypal', 'AWC_PayPal_Status_Manager::cancel_subscription' );
		}
	}

	/**
	 * Don't update the payment method on checkout when switching to PayPal - wait until we have the IPN message.
	 *
	 * @param  string $item_name
	 * @return string
	 
	 */
	public static function maybe_dont_update_payment_method( $update, $new_payment_method, $subscription ) {

		if ( 'paypal' == $new_payment_method ) {
			$update = false;
		}

		return $update;
	}

	/**
	 * Change the "Change Payment Method" button for PayPal
	 *
	 * @param string $change_button_text
	 * @param WC_Payment_Gateway $gateway
	 .8
	 */
	public static function change_payment_button_text( $change_button_text, $gateway ) {

		if ( is_object( $gateway ) && isset( $gateway->id ) && 'paypal' == $gateway->id && ! empty( $gateway->order_button_text ) ) {
			$change_button_text = $gateway->order_button_text;
		}

		return $change_button_text;
	}
}
