<?php
/**
 * WooCommerce Subscriptions Early Renewal functions.
 *
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Checks the cart to see if it contains an early subscription renewal.
 *
 * @return bool|array The cart item containing the early renewal, else false.
 
 */
function awc_cart_contains_early_renewal() {

	$cart_item = awc_cart_contains_renewal();

	if ( $cart_item && ! empty( $cart_item['subscription_renewal']['subscription_renewal_early'] ) ) {
		return $cart_item;
	}

	return false;
}

/**
 * Checks if a user can renew an active subscription early.
 *
 * @param int|WC_Subscription $subscription Post ID of a 'shop_subscription' post, or instance of a WC_Subscription object.
 * @param int $user_id The ID of a user.
 
 * @return bool Whether the user can renew a subscription early.
 */
function awc_can_user_renew_early( $subscription, $user_id = 0 ) {
	$subscription = awc_get_subscription( $subscription );
	$user_id      = ! empty( $user_id ) ? $user_id : get_current_user_id();
	$reason       = '';

	// Check for all the normal reasons a subscription can't be renewed early.
	if ( ! $subscription ) {
		$reason = 'not_a_subscription';
	} elseif ( ! $subscription->has_status( array( 'active' ) ) ) {
		$reason = 'subscription_not_active';
	} elseif ( 0.0 === floatval( $subscription->get_total() ) ) {
		$reason = 'subscription_zero_total';
	} elseif ( $subscription->get_time( 'trial_end' ) > gmdate( 'U' ) ) {
		$reason = 'subscription_still_in_free_trial';
	} elseif ( ! $subscription->get_time( 'next_payment' ) ) {
		$reason = 'subscription_no_next_payment';
	} elseif ( ! $subscription->payment_method_supports( 'subscription_date_changes' ) ) {
		$reason = 'payment_method_not_supported';
	} elseif (
		WC_Subscriptions_Synchroniser::subscription_contains_synced_product( $subscription ) &&
		/**
		 * Determine whether a subscription with Synchronized products can be renewed early.
		 *
		 * @param bool            $can_renew_early Whether the subscription can be renewed early.
		 * @param WC_Subscription $subscription    The subscription to be renewed early.
		 */
		! boolval( apply_filters( 'awc_allow_synced_product_early_renewal', false, $subscription ) )
	) {
		$reason = 'subscription_contains_synced_product';
	}

	// Make sure all line items still exist.
	foreach ( $subscription->get_items() as $line_item ) {
		$product = wc_get_product( awc_get_canonical_product_id( $line_item ) );

		if ( false === $product ) {
			$reason = 'line_item_no_longer_exists';
			break;
		}
	}

	// Non-empty $reason means we can't renew early.
	$can_renew_early = empty( $reason );

	/**
	 * Allow third-parties to filter whether the customer can renew a subscription early.
	 *
	 */
	return apply_filters( 'awc_subscription_can_user_renew_earlys', $can_renew_early, $subscription, $user_id, $reason );
}

/**
 * Check if a given order is a subscription renewal order.
 *
 * @param WC_Order|int $order The WC_Order object or ID of a WC_Order order.
 
 * @return bool True if the order contains an early renewal, otherwise false.
 */
function awc_order_contains_early_renewal( $order ) {

	if ( ! is_object( $order ) ) {
		$order = wc_get_order( $order );
	}

	$subscription_id  = absint( awc_get_objects_property( $order, 'subscription_renewal_early' ) );
	$is_early_renewal = awc_is_order( $order ) && $subscription_id > 0;

	/**
	 * Allow third-parties to filter whether this order contains the early renewal flag.
	 *
	 
	 * @param bool     $is_renewal True if early renewal meta was found on the order, otherwise false.
	 * @param WC_Order $order The WC_Order object.
	 */
	return apply_filters( 'awc_subscription_is_early_renewals_order', $is_early_renewal, $order );
}

/**
 * Returns a URL for early renewal of a subscription.
 *
 * @param  int|AWC_Subscription $subscription AWC_Subscription ID, or instance of a AWC_Subscription object.
 * @return string The early renewal URL.
 
 */
function awc_get_early_renewal_url( $subscription ) {
	$subscription_id = is_a( $subscription, 'AWC_Subscription' ) ? $subscription->get_id() : absint( $subscription );

	$url = add_query_arg( array(
		'subscription_renewal_early' => $subscription_id,
		'subscription_renewal'       => 'true',
	), get_permalink( wc_get_page_id( 'myaccount' ) ) );

	/**
	 * Allow third-parties to filter the early renewal URL.
	 *
	 */
	return apply_filters( 'woocommerce_subscriptions_get_early_renewal_url', $url, $subscription_id );
}






/**
 * Returns a URL for edit of a subscription quantity.
 *
 * @param  int|AWC_Subscription $subscription AWC_Subscription ID, or instance of a AWC_Subscription object.
 * @return string The early renewal URL.
 
 */
function awc_get_subscription_edit_qty_url( $subscription ) {
	$subscription_id = is_a( $subscription, 'AWC_Subscription' ) ? $subscription->get_id() : absint( $subscription );

	$url = add_query_arg( array(
		'subscription_id' => $subscription_id,
		'qty_edit'       => 'true',
	), get_permalink( wc_get_page_id( 'myaccount' ) ) );

	/**
	 * Allow third-parties to filter the early renewal URL.
	 *
	 
	 * @param string $url The early renewal URL.
	 * @param int    $subscription_id The ID of the subscription to renew to.
	 */
	return apply_filters( 'awc_subscription_edit_qty', $url, $subscription_id );
}



/**
 * Update the subscription dates after processing an early renewal.
 *
 
 *
 * @param WC_Subscription $subscription The subscription to update.
 * @param WC_Order $early_renewal       The early renewal.
 */
function awc_update_dates_after_early_renewal( $subscription, $early_renewal ) {
	$dates_to_update = AWC_Early_Renewal_Manager::get_dates_to_update( $subscription );

	if ( ! empty( $dates_to_update ) ) {
		// translators: %s: order ID.
		$order_number = sprintf( _x( '#%s', 'hash before order number', 'subscriptions-recurring-payments-for-woocommerce' ), $early_renewal->get_order_number() );
		$order_link   = sprintf( '<a href="%s">%s</a>', esc_url( awc_get_edit_post_link( $early_renewal->get_id() ) ), $order_number );

		try {
			$subscription->update_dates( $dates_to_update );

			// translators: placeholder contains a link to the order's edit screen.
			$subscription->add_order_note( sprintf( __( 'Customer successfully renewed early with order %s.', 'subscriptions-recurring-payments-for-woocommerce' ), $order_link ) );
		} catch ( Exception $e ) {
			// translators: placeholder contains a link to the order's edit screen.
			$subscription->add_order_note( sprintf( __( 'Failed to update subscription dates after customer renewed early with order %s.', 'subscriptions-recurring-payments-for-woocommerce' ), $order_link ) );
		}
	}
}
