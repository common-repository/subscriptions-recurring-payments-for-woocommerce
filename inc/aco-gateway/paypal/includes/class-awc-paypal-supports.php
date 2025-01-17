<?php
/**
 * PayPal Subscription Support Class.
 *
 *
* 
 
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

class AWC_PayPal_Supports {

	protected static $standard_supported_features = array(
		'subscriptions',
		'gateway_scheduled_payments',
		'subscription_payment_method_change_customer',
		'subscription_cancellation',
		'subscription_suspension',
		'subscription_reactivation',
	);

	protected static $reference_transaction_supported_features = array(
		'subscription_payment_method_change_customer',
		'subscription_payment_method_change_admin',
		'subscription_amount_changes',
		'subscription_date_changes',
		'multiple_subscriptions',
		'subscription_payment_method_delayed_change',
	);

	/**
	 * Bootstraps the class and hooks required actions & filters.
	 *
	 */
	public static function init() {

		// Set the PayPal Standard gateway to support subscriptions after it is added to the woocommerce_payment_gateways array
		add_filter( 'woocommerce_payment_gateway_supports', __CLASS__ . '::add_feature_support_for_gateway', 10, 3 );

		// Check for specific subscription support based on whether the subscription is using a billing agreement or subscription for recurring payments with PayPal
		add_filter( 'awc_subscription_payment_gateway_supports', __CLASS__ . '::add_feature_support_for_subscription', 10, 3 );

		add_filter( 'woocommerce_subscriptions_payment_gateway_features_list', array( __CLASS__, 'add_paypal_billing_type_supported_features' ), 10, 2 );
	}

	/**
	 * Add subscription support to the PayPal Standard gateway only when credentials are set
	 *
	 */
	public static function add_feature_support_for_gateway( $is_supported, $feature, $gateway ) {

		if ( 'paypal' === $gateway->id && AWC_PayPal::are_credentials_set() ) {

			if ( in_array( $feature, self::$standard_supported_features ) ) {
				$is_supported = true;
			} elseif ( in_array( $feature, self::$reference_transaction_supported_features ) && AWC_PayPal::are_reference_transactions_enabled() ) {
				$is_supported = true;
			}
		}

		return $is_supported;
	}

	/**
	 * Add additional feature support at the subscription level instead of just the gateway level because some subscriptions may have been
	 * setup with PayPal Standard while others may have been setup with Billing Agreements to use with Reference Transactions.
	 *
	 
	 */
	public static function add_feature_support_for_subscription( $is_supported, $feature, $subscription ) {

		if ( 'paypal' === $subscription->get_payment_method() && AWC_PayPal::are_credentials_set() ) {

			$paypal_profile_id    = awc_get_paypal_id( $subscription->get_id() );
			$is_billing_agreement = awc_is_paypal_profile_a( $paypal_profile_id, 'billing_agreement' );

			if ( 'gateway_scheduled_payments' === $feature && $is_billing_agreement ) {

				$is_supported = false;

			} elseif ( in_array( $feature, self::$standard_supported_features ) ) {

				if ( awc_is_paypal_profile_a( $paypal_profile_id, 'out_of_date_id' ) ) {
					$is_supported = false;
				} else {
					$is_supported = true;
				}
			} elseif ( in_array( $feature, self::$reference_transaction_supported_features ) ) {

				if ( $is_billing_agreement ) {
					$is_supported = true;
				} else {
					$is_supported = false;
				}
			}
		}

		return $is_supported;
	}

	/**
	 * Adds the payment gateway features supported by the type of billing the PayPal account supports (Reference Transactions or Standard).
	 *
	 
	 *
	 * @param array $features The list of features the payment gateway supports.
	 * @param WC_Payment_Gateway $gateway The payment gateway object.
	 * @return array $features
	 */
	public static function add_paypal_billing_type_supported_features( $features, $gateway ) {

		// update_option( 'tests', 'paypal billing type omar' );

		if ( 'awc_paypal_payment' !== $gateway->id ) {
			return $features;
		}

		// The base feature list is the PayPal Standard features + the basic features the payment gateways support ($gateway->supports).
		$features = array_merge( self::$standard_supported_features, $features );

		// Reference Transactions support all base features + Reference Transactions features - 'gateway_scheduled_payments'.
		if ( AWC_PayPal::are_reference_transactions_enabled() ) {
			// Remove gateway scheduled payments.
			if ( false !== ( $key = array_search( 'gateway_scheduled_payments', $features ) ) ) {
				unset( $features[ $key ] );
			}

			$features = array_merge( self::$reference_transaction_supported_features, $features );
		}

		return array_unique( $features );
	}
}
