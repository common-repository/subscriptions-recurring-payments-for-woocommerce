<?php
/**
 * Allow for payment dates to be synchronised to a specific day of the week, month or year.
 *
 */
class AWC_Synchroniser {
	/**
	 * @return	option from DB
	 * @access	public
	 */
	public static $setting_id;



	/**
	 * Bootstraps the class and hooks required actions & filters.
	 *
	 */
	public static function init() {
		self::$setting_id = '';
		add_action( 'woocommerce_variable_subscription_pricing', __CLASS__ . '::awc_variable_subscription_product_fields', 10, 3 );
	}


		/**
	 * Add the sync setting fields to the variation section of the Edit Product screen
	 *
	 
	 */
	public static function awc_variable_subscription_product_fields( $loop, $variation_data, $variation ) {

		if ( self::awc_syncing_enabled() ) {

			// Set month as the default billing period
			$subscription_period = AWC_Subscription_Products::awc_get_period( $variation );

			if ( empty( $subscription_period ) ) {
				$subscription_period = 'month';
			}

			$display_week_month_select = ( ! in_array( $subscription_period, array( 'month', 'week' ) ) ) ? 'display: none;' : '';
			$display_annual_select     = ( 'year' != $subscription_period ) ? 'display: none;' : '';

			$payment_day = self::get_products_payment_day( $variation );

			// An annual sync date is already set in the form: array( 'day' => 'nn', 'month' => 'nn' ), create a MySQL string from those values (year and time are irrelvent as they are ignored)
			if ( is_array( $payment_day ) ) {
				$payment_month = ( 0 === (int) $payment_day['day'] ) ? 0 : $payment_day['month'];
				$payment_day   = $payment_day['day'];
			} else {
				$payment_month = 0;
			}

			include( AWC_PATH . 'temp/variation-synchronisation-html.php' );
		}
	}


	/**
	 * Determine whether a product, specified with $product, needs to have its first payment processed on a
	 * specific day (instead of at the time of sign-up).
	 *
	 * @return (bool) True is the product's first payment will be synced to a certain day.
	 
	 */
	public static function awc_is_product_synced( $product ) {

		if ( ! is_object( $product ) ) {
			$product = wc_get_product( $product );
		}

		if ( ! is_object( $product ) || ! self::awc_syncing_enabled() || 'day' == AWC_Subscription_Products::awc_get_period( $product ) || ! AWC_Subscription_Products::awc_is_subscription( $product ) ) {
			return false;
		}

		$payment_date = self::get_products_payment_day( $product );

		return ( ! is_array( $payment_date ) && $payment_date > 0 ) || ( isset( $payment_date['day'] ) && $payment_date['day'] > 0 );
	}

	/**
	 * Check if payment syncing is enabled on the store.
	 *
	 
	 */
	public static function awc_syncing_enabled() {
		return 'yes' === get_option( self::$setting_id, 'no' );
	}
}

