<?php
/**
 * Subscription Early Renewal Manager Class
 *
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

class AWC_Early_Renewal_Manager {

	/**
	 * A helper function to check if the early renewal feature is enabled or not.
	 * @return bool
	 */
	public static function is_early_renewal_enabled() {
		$enabled = AWC_Settings::get_option( 'early_renewal' );

		return apply_filters( 'awc_is_early_renewal_enabled', $enabled );
	}

	/**
	 * Finds if the store has enabled early renewal via a modal.
	 *
	 * @return bool
	 */
	public static function is_early_renewal_via_modal_enabled() {
		return self::is_early_renewal_enabled() && apply_filters( 'awc_early_renewal_via_modal_enabled', true );
	}

	/**
	 * Gets the dates which need to be updated after an early renewal is processed.
	 *
	 
	 *
	 * @param WC_Subscription $subscription The subscription to calculate the dates for.
	 * @return array The subscription dates which need to be updated. For example array( $date_type => $mysql_form_date_string ).
	 */
	public static function get_dates_to_update( $subscription ) {
		$next_payment_time = $subscription->get_time( 'next_payment' );
		$dates_to_update   = array();

		if ( $next_payment_time > 0 && $next_payment_time > current_time( 'timestamp', true ) ) {
			$next_payment_timestamp = awc_add_time( $subscription->get_billing_interval(), $subscription->get_billing_period(), $next_payment_time );

			if ( $subscription->get_time( 'end' ) === 0 || $next_payment_timestamp < $subscription->get_time( 'end' ) ) {
				$dates_to_update['next_payment'] = gmdate( 'Y-m-d H:i:s', $next_payment_timestamp );
			} else {
				// Delete the next payment date if the calculated next payment date occurs after the end date.
				$dates_to_update['next_payment'] = 0;
			}
		} elseif ( $subscription->get_time( 'end' ) > 0 ) {
			$dates_to_update['end'] = gmdate( 'Y-m-d H:i:s', awc_add_time( $subscription->get_billing_interval(), $subscription->get_billing_period(), $subscription->get_time( 'end' ) ) );
		}

		return $dates_to_update;
	}
}
