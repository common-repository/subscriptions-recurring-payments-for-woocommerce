<?php
/**
 * PayPal Subscription Status Manager Class.
 *
 * @package     WooCommerce Subscriptions
 * @subpackage  Gateways/PayPal
 * @category    Class
 * 
 
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

class AWC_PayPal_Status_Manager extends AWC_PayPal {

	/**
	 * Bootstraps the class and hooks required actions & filters.
	 *
	 
	 */
	public static function init() {

		// When a subscriber or store manager changes a subscription's status in the store, change the status with PayPal
		add_action( 'woocommerce_subscription_cancelled_paypal', __CLASS__ . '::cancel_subscription' );
		add_action( 'woocommerce_subscription_pending-cancel_paypal', __CLASS__ . '::suspend_subscription' );
		add_action( 'woocommerce_subscription_expired_paypal', __CLASS__ . '::suspend_subscription' );
		add_action( 'woocommerce_subscription_on-hold_paypal', __CLASS__ . '::suspend_subscription' );
		add_action( 'woocommerce_subscription_activated_paypal', __CLASS__ . '::reactivate_subscription' );

		add_filter( 'awc_gateway_status_payment_changed', __CLASS__ . '::suspend_subscription_on_payment_changed', 10, 2 );
	}

	/**
	 * When a store manager or user cancels a subscription in the store, also cancel the subscription with PayPal.
	 *
	 
	 */
	public static function cancel_subscription( $subscription ) {
		if ( ! awc_is_paypal_profile_a( awc_get_paypal_id( $subscription->get_id() ), 'billing_agreement' ) && self::update_subscription_status( $subscription, 'Cancel' ) ) {
			$subscription->add_order_note( __( 'Subscription cancelled with PayPal', 'subscriptions-recurring-payments-for-woocommerce' ) );
		}
	}

	/**
	 * When a store manager or user suspends a subscription in the store, also suspend the subscription with PayPal.
	 *
	 
	 */
	public static function suspend_subscription( $subscription ) {
		if ( ! awc_is_paypal_profile_a( awc_get_paypal_id( $subscription->get_id() ), 'billing_agreement' ) && self::update_subscription_status( $subscription, 'Suspend' ) ) {
			$subscription->add_order_note( __( 'Subscription suspended with PayPal', 'subscriptions-recurring-payments-for-woocommerce' ) );
		}
	}

	/**
	 * When a store manager or user reactivates a subscription in the store, also reactivate the subscription with PayPal.
	 *
	 * How PayPal Handles suspension is discussed here: https://www.x.com/developers/paypal/forums/nvp/reactivate-recurring-profile
	 *
	 
	 */
	public static function reactivate_subscription( $subscription ) {
		if ( ! awc_is_paypal_profile_a( awc_get_paypal_id( $subscription->get_id() ), 'billing_agreement' ) && self::update_subscription_status( $subscription, 'Reactivate' ) ) {
			$subscription->add_order_note( __( 'Subscription reactivated with PayPal', 'subscriptions-recurring-payments-for-woocommerce' ) );
		}
	}

	/**
	 * Performs an Express Checkout NVP API operation as passed in $api_method.
	 *
	 * Although the PayPal Standard API provides no facility for cancelling a subscription, the PayPal
	 * Express Checkout NVP API can be used.
	 *
	 
	 */
	public static function update_subscription_status( $subscription, $new_status ) {

		$profile_id = awc_get_paypal_id( $subscription->get_id() );

		if ( awc_is_paypal_profile_a( $profile_id, 'billing_agreement' ) ) {

			// Nothing to do here, leave the billing agreement active at PayPal for use with other subscriptions and just change the status in the store
			$status_updated = true;

		} elseif ( ! empty( $profile_id ) ) {

			// We need to change the subscriptions status at PayPal, which is doing via Express Checkout APIs, despite the subscription having been created with PayPal Standard
			$response = self::get_api()->manage_recurring_payments_profile_status( $profile_id, $new_status, $subscription );

			if ( ! $response->has_api_error() ) {
				$status_updated = true;
			} else {
				$status_updated = false;

				if ( $response->has_api_error_for_credentials() ) {

					// Store the profile ID so we can lookup which profiles are affected
					$profile_ids = get_option( 'awc_paypal_credentials_error_affected_profiles', '' );

					if ( ! empty( $profile_ids ) ) {
						$profile_ids .= ', ';
					}
					$profile_ids .= $profile_id;

					update_option( 'awc_paypal_credentials_error_affected_profiles', $profile_ids );

					// And set a flag to display notice
					update_option( 'awc_paypal_credentials_error', 'yes' );

					// This message will be added as an order note on by AWC_Subscription::update_status()
					throw new Exception( sprintf( __( 'PayPal API error - credentials are incorrect.', 'subscriptions-recurring-payments-for-woocommerce' ), $new_status ) );
				}
			}
		} else {
			$status_updated = false;
		}

		return $status_updated;
	}

	/**
	 * When changing the payment method on edit subscription screen from PayPal, only suspend the subscription rather
	 * than cancelling it.
	 *
	 * @param string $status The subscription status sent to the current payment gateway before changing subscription payment method.
	 * @return object $subscription
	 
	 */
	public static function suspend_subscription_on_payment_changed( $status, $subscription ) {
		return ( 'paypal' == $subscription->get_payment_method() ) ? 'on-hold' : $status;
	}

}
