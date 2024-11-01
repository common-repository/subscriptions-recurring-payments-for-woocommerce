<?php
/**
 * WooCommerce Subscriptions Deprecated Functions
 *
 *
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Wrapper for wc_doing_it_wrong.
 *
 * @param  string $function
 * @param  string $version
 * @param  string $replacement
 */
function awc_doing_it_wrong( $function, $message, $version ) {

	if ( function_exists( 'wc_doing_it_wrong' ) ) {
		wc_doing_it_wrong( $function, $message, $version );
	} else {
		// Reimplment wc_doing_it_wrong() when WC 3.0 is not active
		if ( is_ajax() ) {
			do_action( 'doing_it_wrong_run', $function, $message, $version );
			error_log( "{$function} was called incorrectly. {$message}. This message was added in version {$version}." );
		} else {
			_doing_it_wrong( esc_attr( $function ), esc_attr( $message ), esc_attr( $version ) );
		}
	}
}



/**
 * Get the string key for a subscription used in Subscriptions prior to 2.0.
 *
 * @param AWC_Subscription 
 * @return string 
 
 */
function awc_get_old_subscription_key( AWC_Subscription $subscription ) {

	// Get an ID to use as the order ID
	$order_id = ( false == $subscription->get_parent_id() ) ? $subscription->get_id() : $subscription->get_parent_id();

	// Get an ID to use as the product ID
	$subscription_items = $subscription->get_items();
	$first_item         = reset( $subscription_items );

	return $order_id . '_' . AWC_Subscriptions_Order::get_items_product_id( $first_item );
}



/**
 * Return the post ID of a WC_Subscription object for the given subscription key (if one exists).
 *
 * @param string $subscription_key A subscription key in the deprecated form created by @see AWC_Subscription_Manager::get_subscription_key()
 * @return int|null The post ID for the subscription if it can be found (i.e. an order exists) or null if no order exists for the subscription.
 
 */
function awc_get_subscription_id_from_key( $subscription_key ) {
	global $wpdb;

	// it can be either 8_13 or just 8. If it's 8, it'll be an integer
	if ( ! is_string( $subscription_key ) && ! is_int( $subscription_key ) ) {
		return null;
	}

	$order_and_product_id = explode( '_', $subscription_key );

	$subscription_ids = array();

	// If we have an order ID and product ID, query based on that
	if ( ! empty( $order_and_product_id[0] ) && ! empty( $order_and_product_id[1] ) ) {

		$subscription_ids = $wpdb->get_col( $wpdb->prepare( "
			SELECT DISTINCT order_items.order_id FROM {$wpdb->prefix}woocommerce_order_items as order_items
				LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta AS itemmeta ON order_items.order_item_id = itemmeta.order_item_id
				LEFT JOIN {$wpdb->posts} AS posts ON order_items.order_id = posts.ID
			WHERE posts.post_type = 'shop_subscription'
				AND posts.post_parent = %d
				AND itemmeta.meta_value = %d
				AND itemmeta.meta_key IN ( '_variation_id', '_product_id' )",
		$order_and_product_id[0], $order_and_product_id[1] ) );

	} elseif ( ! empty( $order_and_product_id[0] ) ) {

		$subscription_ids = get_posts( array(
			'posts_per_page' => 1,
			'post_parent'    => $order_and_product_id[0],
			'post_status'    => 'any',
			'post_type'      => 'shop_subscription',
			'fields'         => 'ids',
		) );

	}

	return ( ! empty( $subscription_ids ) ) ? $subscription_ids[0] : null;
}



/**
 * Return an instance of a WC_Subscription object for the given subscription key (if one exists).
 *
 * @param string $subscription_key A subscription key in the deprecated form created by @see self::get_subscription_key()
 * @return WC_Subscription|null The subscription object if it can be found (i.e. an order exists) or null if no order exists for the subscription (i.e. it was manually created).
 
 */
function awc_get_subscription_from_key( $subscription_key ) {

	$subscription_id = awc_get_subscription_id_from_key( $subscription_key );

	if ( null !== $subscription_id && is_numeric( $subscription_id ) ) {
		$subscription = awc_get_subscription( $subscription_id );
	}

	if ( ! is_object( $subscription ) ) {
		// translators: placeholder is either subscription key or a subscription id, or, failing that, empty (e.g. "145_21" or "145")
		throw new InvalidArgumentException( sprintf( __( 'Could not get subscription. Most likely the subscription key does not refer to a subscription. The key was: "%s".', 'subscriptions-recurring-payments-for-woocommerce' ), $subscription_key ) );
	}

	return $subscription;
}

/**
 * Return an associative array of a given subscriptions details (if it exists) in the pre v2.0 data structure.
 *
 * @param WC_Subscription $subscription An instance of WC_Subscription
 * @return array Subscription details
 
 */
function awc_get_subscription_in_deprecated_structure( WC_Subscription $subscription ) {

	$completed_payments = array();

	if ( $subscription->get_payment_count() ) {

		$order = $subscription->get_parent();

		if ( ! empty( $order ) ) {
			$parent_order_created_date = awc_get_objects_property( $order, 'date_created' );

			if ( ! is_null( $parent_order_created_date ) ) {
				$completed_payments[] = awc_get_datetime_utc_string( $parent_order_created_date );
			}
		}

		foreach ( $subscription->get_related_orders( 'all', 'renewal' ) as $renewal_order ) {

			// Not all gateways would call $order->payment_complete() with WC < 3.0, so we need to find renewal orders with a paid status or a paid date (WC 3.0+ takes care of setting the paid date when payment_complete() wasn't called)
			if ( null !== awc_get_objects_property( $renewal_order, 'date_paid' ) || $renewal_order->has_status( $subscription->get_paid_order_statuses() ) ) {

				$date_created = awc_get_objects_property( $renewal_order, 'date_created' );

				if ( ! is_null( $date_created ) ) {
					$completed_payments[] = awc_get_datetime_utc_string( $date_created );
				}
			}
		}
	}

	$items = $subscription->get_items();
	$item  = array_pop( $items );

	if ( ! empty( $item ) ) {

		$deprecated_subscription_object = array(
			'order_id'           => $subscription->get_parent_id(),
			'product_id'         => isset( $item['product_id'] ) ? $item['product_id'] : 0,
			'variation_id'       => isset( $item['variation_id'] ) ? $item['variation_id'] : 0,
			'status'             => $subscription->get_status(),

			// Subscription billing details
			'period'             => $subscription->get_billing_period(),
			'interval'           => $subscription->get_billing_interval(),
			'length'             => awc_estimate_periods_between( ( 0 == $subscription->get_time( 'trial_end' ) ) ? $subscription->get_time( 'date_created' ) : $subscription->get_time( 'trial_end' ), $subscription->get_time( 'end' ) + 120, $subscription->get_billing_period(), 'floor' ) / $subscription->get_billing_interval(), // Since subscriptions no longer have a length, we need to calculate the length given the start and end dates and the period.

			// Subscription dates
			'start_date'         => $subscription->get_date( 'start' ),
			'expiry_date'        => $subscription->get_date( 'end' ),
			'end_date'           => $subscription->has_status( awc_get_subscription_ended_statuses() ) ? $subscription->get_date( 'end' ) : 0,
			'trial_expiry_date'  => $subscription->get_date( 'trial_end' ),

			// Payment & status change history
			'failed_payments'    => $subscription->get_failed_payment_count(),
			'completed_payments' => $completed_payments,
			'suspension_count'   => $subscription->get_suspension_count(),
			'last_payment_date'  => $subscription->get_date( 'last_order_date_created' ),
		);

	} else {

		$deprecated_subscription_object = array();

	}

	return $deprecated_subscription_object;
}

/**
 * Wrapper for wc_deprecated_hook to improve handling of ajax requests, even when
 * WooCommerce 3.3.0's wc_deprecated_hook method is not available.
 *
 
 * @param string $hook        The hook that was used.
 * @param string $version     The version that deprecated the hook.
 * @param string $replacement The hook that should have been used.
 * @param string $message     A message regarding the change.
 */
function awc_deprecated_hook( $hook, $version, $replacement = null, $message = null ) {

	if ( function_exists( 'wc_deprecated_hook' ) ) {
		wc_deprecated_hook( $hook, $version, $replacement, $message );
	} else {
		// Reimplement awc_deprecated_function() when WC 3.0 is not active
		if ( is_ajax() ) {
			do_action( 'deprecated_hook_run', $hook, $replacement, $version, $message );

			$message    = empty( $message ) ? '' : ' ' . $message;
			$log_string = "{$hook} is deprecated since version {$version}";
			$log_string .= $replacement ? "! Use {$replacement} instead." : ' with no alternative available.';

			error_log( $log_string . $message );
		} else {
			_deprecated_hook( $hook, $version, $replacement, $message );
		}
	}
}
