<?php
/**
 * The old PayPal Standard Subscription Class.
 *
 * Filtered necessary functions in the WC_Paypal class to allow for subscriptions.
 *
 * Replaced by AWC_PayPal.
 */

class WC_PayPal_Standard_Subscriptions {

	public static $api_username;
	public static $api_password;
	public static $api_signature;
	public static $api_endpoint;

	private static $request_handler;

	/**
	 * Set the public properties to make sure  we don't trigger any fatal errors even though the class is deprecated.
	 *
	 
	 */
	public static function init() {
		self::$api_username  = AWC_PayPal::get_option( 'api_username' );
		self::$api_password  = AWC_PayPal::get_option( 'api_password' );
		self::$api_signature = AWC_PayPal::get_option( 'api_signature' );
		self::$api_endpoint  = ( 'no' == AWC_PayPal::get_option( 'testmode' ) ) ? 'https://api-3t.paypal.com/nvp' : 'https://api-3t.sandbox.paypal.com/nvp';
	}

	/**
	 * Checks if the PayPal API credentials are set.
	 *
	 
	 */
	public static function are_credentials_set() {
		return AWC_PayPal::are_credentials_set();
	}

	/**
	 * Add subscription support to the PayPal Standard gateway only when credentials are set
	 *
	 
	 */
	public static function add_paypal_standard_subscription_support( $is_supported, $feature, $gateway ) {
		return AWC_PayPal_Supports::add_feature_support( $is_supported, $feature, $gateway );
	}

	/**
	 * When a PayPal IPN messaged is received for a subscription transaction,
	 * check the transaction details and
	 *
	 
	 */
	public static function process_paypal_ipn_request( $transaction_details ) {
		AWC_PayPal::process_ipn_request( $transaction_details );
	}

	/**
	 * Override the default PayPal standard args in WooCommerce for subscription purchases when
	 * automatic payments are enabled and when the recurring order totals is over $0.00 (because
	 * PayPal doesn't support subscriptions with a $0 recurring total, we need to circumvent it and
	 * manage it entirely ourselves.)
	 *
	 
	 */
	public static function paypal_standard_subscription_args( $paypal_args, $order = '' ) {
		return AWC_PayPal_Standard_Request::get_paypal_args( $paypal_args, $order );
	}

	/**
	 * Adds extra PayPal credential fields required to manage subscriptions.
	 *
	 
	 */
	public static function add_subscription_form_fields() {
		AWC_PayPal_Admin::add_form_fields();
	}

	/**
	 * Returns a PayPal Subscription ID/Recurring Payment Profile ID based on a user ID and subscription key
	 *
	 * @param WC_Order|WC_Subscription A WC_Order object or child object (i.e. WC_Subscription)
	 
	 */
	public static function get_subscriptions_paypal_id( $order_id, $product_id = '' ) {
		return awc_get_paypal_id( $order_id );
	}

	/**
	 * Performs an Express Checkout NVP API operation as passed in $api_method.
	 *
	 * Although the PayPal Standard API provides no facility for cancelling a subscription, the PayPal
	 * Express Checkout NVP API can be used.
	 *
	 
	 */
	public static function change_subscription_status( $profile_id, $new_status, $order = null ) {
		return AWC_PayPal::get_api()->manage_recurring_payments_profile_status( $profile_id, $new_status, $order );
	}

	/**
	 * Checks a set of args and derives an Order ID with backward compatibility for WC < 1.7 where 'custom' was the Order ID.
	 *
	 
	 */
	public static function get_order_id_and_key( $args ) {
		return AWC_Paypal_Standard_IPN_Handler::get_order_id_and_key( $args, 'shop_order' );
	}




	/**
	 * Don't update the payment method on checkout when switching to PayPal - wait until we have the IPN message.
	 *
	 * @param  string $item_name
	 * @return string
	 
	 */
	public static function maybe_dont_update_payment_method( $update, $new_payment_method ) {
		
		return $update;
	}




	/**
	 * When a store manager or user cancels a subscription in the store, also cancel the subscription with PayPal.
	 *
	 
	 */
	public static function cancel_subscription_with_paypal( $order, $product_id = '', $profile_id = '' ) {
		foreach ( awc_get_subscriptions_for_order( $order, array( 'order_type' => 'parent' ) ) as $subscription ) {
			self::change_subscription_status( $profile_id, 'Cancel', $subscription );
		}
	}

	/**
	 * When a store manager or user suspends a subscription in the store, also suspend the subscription with PayPal.
	 *
	 */
	public static function suspend_subscription_with_paypal( $order, $product_id ) {
		foreach ( awc_get_subscriptions_for_order( $order, array( 'order_type' => 'parent' ) ) as $subscription ) {
			AWC_PayPal_Status_Manager::suspend_subscription( $subscription );
		}
	}

	/**
	 * When a store manager or user reactivates a subscription in the store, also reactivate the subscription with PayPal.
	 *
	 * How PayPal Handles suspension is discussed here: https://www.x.com/developers/paypal/forums/nvp/reactivate-recurring-profile
	 *
	 */
	public static function reactivate_subscription_with_paypal( $order, $product_id ) {
		foreach ( awc_get_subscriptions_for_order( $order, array( 'order_type' => 'parent' ) ) as $subscription ) {
			AWC_PayPal_Status_Manager::reactivate_subscription( $subscription );
		}
	}

	/**
	 * Don't transfer PayPal customer/token meta when creating a parent renewal order.
	 *
	 * @access public
	 * @param array $order_meta_query MySQL query for pulling the metadata
	 * @param int $original_order_id Post ID of the order being used to purchased the subscription being renewed
	 * @param int $renewal_order_id Post ID of the order created for renewing the subscription
	 * @param string $new_order_role The role the renewal order is taking, one of 'parent' or 'child'
	 * @return void
	 */
	public static function remove_renewal_order_meta( $order_meta_query, $original_order_id, $renewal_order_id, $new_order_role ) {
		

		if ( 'parent' == $new_order_role ) {
			// phpcs:disable WordPress.WhiteSpace.PrecisionAlignment.Found
			$order_meta_query .= ' AND `meta_key` NOT IN ('
				. "'Transaction ID', "
				. "'Payer first name', "
				. "'Payer last name', "
				. "'Payment type', "
				. "'Payer PayPal address', "
				. "'Payer PayPal first name', "
				. "'Payer PayPal last name', "
				. "'PayPal Subscriber ID' )";
			// phpcs:enable
		}

		return $order_meta_query;
	}



	/**
	 * Takes a timestamp for a date in the future and calculates the number of days between now and then
	 *
	 
	 */
	public static function calculate_trial_periods_until( $future_timestamp ) {
		return awc_calculate_paypal_trial_periods_until( $future_timestamp );
	}
}

