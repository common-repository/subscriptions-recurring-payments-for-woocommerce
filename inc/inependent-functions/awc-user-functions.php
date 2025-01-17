<?php
/**
 * WooCommerce Subscriptions User Functions
 *
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Give a user the Subscription's default subscriber role
 *
 */
function awc_make_user_active( $user_id ) {
	awc_update_users_role( $user_id, 'default_subscriber_role' );
}

/**
 * Give a user the Subscription's default subscriber's inactive role
 *
 */
function awc_make_user_inactive( $user_id ) {
	awc_update_users_role( $user_id, 'default_inactive_role' );
}

/**
 * Give a user the Subscription's default subscriber's inactive role if they do not have an active subscription
 *
 */
function awc_maybe_make_user_inactive( $user_id ) {
	if ( ! awc_user_has_subscription( $user_id, '', 'active' ) ) {
		awc_update_users_role( $user_id, 'default_inactive_role' );
	}
}

/**
 * Wrapper for awc_maybe_make_user_inactive() that accepts a subscription instead of a user ID.
 * Handy for hooks that pass a subscription object.
 *
 
 * @param WC_Subscription|WC_Order
 */
function awc_maybe_make_user_inactive_for( $subscription ) {
	awc_maybe_make_user_inactive( $subscription->get_user_id() );
}
add_action( 'woocommerce_subscription_status_failed', 'awc_maybe_make_user_inactive_for', 10, 1 );
add_action( 'woocommerce_subscription_status_on-hold', 'awc_maybe_make_user_inactive_for', 10, 1 );
add_action( 'woocommerce_subscription_status_cancelled', 'awc_maybe_make_user_inactive_for', 10, 1 );
add_action( 'woocommerce_subscription_status_switched', 'awc_maybe_make_user_inactive_for', 10, 1 );
add_action( 'woocommerce_subscription_status_expired', 'awc_maybe_make_user_inactive_for', 10, 1 );

/**
 * Update a user's role to a special subscription's role
 *
 * @param int $user_id The ID of a user
 * @param string $role_new The special name assigned to the role by Subscriptions, one of 'default_subscriber_role', 'default_inactive_role' or 'default_cancelled_role'
 * @return WP_User The user with the new role.
 
 */
function awc_update_users_role( $user_id, $role_new ) {

	$user = new WP_User( $user_id );

	// Never change an admin's role to avoid locking out admins testing the plugin
	if ( ! empty( $user->roles ) && in_array( 'administrator', $user->roles ) ) {
		return;
	}

	// Allow plugins to prevent Subscriptions from handling roles
	if ( ! apply_filters( 'woocommerce_subscriptions_update_users_role', true, $user, $role_new ) ) {
		return;
	}

	$roles = awc_get_new_user_role_names( $role_new );

	$role_new = $roles['new'];
	$role_old = $roles['old'];

	if ( ! empty( $role_old ) ) {
		$user->remove_role( $role_old );
	}

	$user->add_role( $role_new );

	do_action( 'woocommerce_subscriptions_updated_users_role', $role_new, $user, $role_old );
	return $user;
}

/**
 * Gets default new and old role names if the new role is 'default_subscriber_role'. Otherwise returns role_new and an
 * empty string.
 *
 * @param $role_new string the new role of the user
 * @return array with keys 'old' and 'new'.
 */
function awc_get_new_user_role_names( $role_new ) {
	$default_subscriber_role = AWC_Settings::get_option('subscriber_default_role') ? AWC_Settings::get_option('subscriber_default_role') : 'subscriber';
	$default_cancelled_role = AWC_Settings::get_option('disable_user_role') ? AWC_Settings::get_option('disable_user_role') : 'customer';
	$role_old = '';

	if ( 'default_subscriber_role' == $role_new ) {
		$role_old = $default_cancelled_role;
		$role_new = $default_subscriber_role;
	} elseif ( in_array( $role_new, array( 'default_inactive_role', 'default_cancelled_role' ) ) ) {
		$role_old = $default_subscriber_role;
		$role_new = $default_cancelled_role;
	}

	return array(
		'new' => $role_new,
		'old' => $role_old,
	);
}

/**
 * Check if a user has a subscription, optionally to a specific product and/or with a certain status.
 *
 * @param int $user_id (optional) The ID of a user in the store. If left empty, the current user's ID will be used.
 * @param int $product_id (optional) The ID of a product in the store. If left empty, the function will see if the user has any subscription.
 * @param mixed $status (optional) A valid subscription status string or array. If left empty, the function will see if the user has a subscription of any status.
 
 *
 * @return bool
 */
function awc_user_has_subscription( $user_id = 0, $product_id = '', $status = 'any' ) {

	$subscriptions = awc_get_users_subscriptions( $user_id );

	$has_subscription = false;

	if ( empty( $product_id ) ) { // Any subscription

		if ( ! empty( $status ) && 'any' != $status ) { // We need to check for a specific status
			foreach ( $subscriptions as $subscription ) {
				if ( $subscription->has_status( $status ) ) {
					$has_subscription = true;
					break;
				}
			}
		} elseif ( ! empty( $subscriptions ) ) {
			$has_subscription = true;
		}
	} else {

		foreach ( $subscriptions as $subscription ) {
			if ( $subscription->has_product( $product_id ) && ( empty( $status ) || 'any' == $status || $subscription->has_status( $status ) ) ) {
				$has_subscription = true;
				break;
			}
		}
	}

	return apply_filters( 'awc_user_has_subscription', $has_subscription, $user_id, $product_id, $status );
}

/**
 * Gets all the active and inactive subscriptions for a user, as specified by $user_id
 *
 * @param int $user_id (optional) The id of the user whose subscriptions you want. Defaults to the currently logged in user.
 
 *
 * @return WC_Subscription[]
 */
function awc_get_users_subscriptions( $user_id = 0 ) {
	if ( 0 === $user_id || empty( $user_id ) ) {
		$user_id = get_current_user_id();
	}

	$subscriptions = array();

	if ( has_filter( 'awc_pre_get_users_subscriptions' ) ) {
		$filtered_subscriptions = apply_filters( 'awc_pre_get_users_subscriptions', $subscriptions, $user_id );

		if ( is_array( $filtered_subscriptions ) ) {
			$subscriptions = $filtered_subscriptions;
		}
	}

	if ( empty( $subscriptions ) && 0 !== $user_id && ! empty( $user_id ) ) {
		$subscription_ids = awc_Customer_Store::instance()->get_users_subscription_ids( $user_id );

		foreach ( $subscription_ids as $subscription_id ) {
			$subscription = awc_get_subscription( $subscription_id );

			if ( $subscription ) {
				$subscriptions[ $subscription_id ] = $subscription;
			}
		}
	}

	return apply_filters( 'awc_get_users_subscriptions', $subscriptions, $user_id );
}

/**
 * Get subscription IDs for the given user.
 *
 * @author Jeremy Pry
 *
 * @param int $user_id The ID of the user whose subscriptions you want.
 *
 * @return array Array of Subscription IDs.
 */
function awc_get_users_subscription_ids( $user_id ) {
	return awc_Customer_Store::instance()->get_users_subscription_ids( $user_id );
}

/**
 * Get subscription IDs for a user using caching.
 *
 * @author Jeremy Pry
 *
 * @param int $user_id The ID of the user whose subscriptions you want.
 *
 * @return array Array of subscription IDs.
 */
function awc_get_cached_user_subscription_ids( $user_id = 0 ) {

	$user_id = absint( $user_id );

	if ( 0 === $user_id ) {
		$user_id = get_current_user_id();
	}

	return awc_Customer_Store::instance()->get_users_subscription_ids( $user_id );
}

/**
 * Return a link for subscribers to change the status of their subscription, as specified with $status parameter
 *
 * @param int $subscription_id A subscription's post ID
 * @param string $status A subscription's post ID
 * @param string $current_status A subscription's current status
 
 */
function awc_get_users_change_status_link( $subscription_id, $status, $current_status = '' ) {

	if ( '' === $current_status ) {
		$subscription = awc_get_subscription( $subscription_id );

		if ( $subscription instanceof WC_Subscription ) {
			$current_status = $subscription->get_status();
		}
	}

	$action_link = add_query_arg(
		array(
			'subscription_id'        => $subscription_id,
			'change_subscription_to' => $status,
		)
	);
	$action_link = wp_nonce_url( $action_link, $subscription_id . $current_status );

	return apply_filters( 'awc_users_change_status_link', $action_link, $subscription_id, $status );
}

/**
 * Check if a given user (or the currently logged in user) has permission to put a subscription on hold.
 *
 * By default, a store manager can put all subscriptions on hold, while other users can only suspend their own subscriptions.
 *
 * @param int|WC_Subscription $subscription An instance of a WC_Snbscription object or ID representing a 'shop_subscription' post
 
 */
function awc_can_user_put_subscription_on_hold( $subscription, $user = '' ) {

	$user_can_suspend = false;

	if ( empty( $user ) ) {
		$user = wp_get_current_user();
	} elseif ( is_int( $user ) ) {
		$user = get_user_by( 'id', $user );
	}

	if ( user_can( $user, 'manage_woocommerce' ) ) { // Admin, so can always suspend a subscription

		$user_can_suspend = true;

	} else {  // Need to make sure user owns subscription & the suspension limit hasn't been reached

		if ( ! is_object( $subscription ) ) {
			$subscription = awc_get_subscription( $subscription );
		}

		// Make sure current user owns subscription
		if ( $user->ID == $subscription->get_user_id() ) {

			// Make sure subscription suspension count hasn't been reached
			$suspension_count    = intval( $subscription->get_suspension_count() );
			$allowed_suspensions = AWC_Settings::get_option('customer_suspension_number') && AWC_Settings::get_option('push_subscription') ? AWC_Settings::get_option('customer_suspension_number') : 0;

			if ( $allowed_suspensions && $allowed_suspensions > $suspension_count ) { // 0 not > anything so prevents a customer ever being able to suspend
				$user_can_suspend = true;
			}
		}
	}

	return apply_filters( 'awc_can_user_put_subscription_on_hold', $user_can_suspend, $subscription );
}

/**
 * Retrieve available actions that a user can perform on the subscription
 *
 
 *
 * @param WC_Subscription $subscription The subscription.
 * @param int             $user_id      The user.
 *
 * @return array
 */
function awc_get_all_user_actions_for_subscription( $subscription, $user_id ) {

	$actions = array();

	if ( user_can( $user_id, 'edit_shop_subscription_status', $subscription->get_id() ) ) {

		$max_customer_suspension = AWC_Settings::get_option('customer_suspension_number') ? AWC_Settings::get_option('customer_suspension_number') : 0;
		$admin_with_suspension_disallowed = current_user_can( 'manage_woocommerce' ) && 0 === $max_customer_suspension;
		$current_status = $subscription->get_status();

		if ( $subscription->can_be_updated_to( 'on-hold' ) && awc_can_user_put_subscription_on_hold( $subscription, $user_id ) && ! $admin_with_suspension_disallowed ) {
			$actions['suspend'] = array(
				'url'  => awc_get_users_change_status_link( $subscription->get_id(), 'on-hold', $current_status ),
				'name' => __( 'Suspend', 'subscriptions-recurring-payments-for-woocommerce' ),
			);
		} elseif ( $subscription->can_be_updated_to( 'active' ) && ! $subscription->needs_payment() ) {
			$actions['reactivate'] = array(
				'url'  => awc_get_users_change_status_link( $subscription->get_id(), 'active', $current_status ),
				'name' => __( 'Reactivate', 'subscriptions-recurring-payments-for-woocommerce' ),
			);
		}

		if ( awc_can_user_resubscribe_to( $subscription, $user_id ) && false == $subscription->can_be_updated_to( 'active' ) && AWC_Settings::get_option('allow_resubscribe') ) {
			$actions['resubscribe'] = array(
				'url'  => awc_get_users_resubscribe_link( $subscription ),
				'name' => __( 'Resubscribe', 'subscriptions-recurring-payments-for-woocommerce' ),
			);
		}

		// Show button for subscriptions which can be cancelled and which may actually require cancellation (i.e. has a future payment)
		$next_payment = $subscription->get_time( 'next_payment' );
		if ( ($subscription->can_be_updated_to( 'cancelled' ) && ( ! $subscription->is_one_payment() && ( $subscription->has_status( 'on-hold' ) && empty( $next_payment ) ) || $next_payment > 0 ) ) && AWC_Settings::get_option('cancel_capability')) {
			$actions['cancel'] = array(
				'url'  => awc_get_users_change_status_link( $subscription->get_id(), 'cancelled', $current_status ),
				'name' => _x( 'Cancel', 'an action on a subscription', 'subscriptions-recurring-payments-for-woocommerce' ),
			);
		}
	}

	return apply_filters( 'awc_view_details_subscription_myaccount_actions', $actions, $subscription );
}

/**
 * Checks if a user has a certain capability
 *
 * @access public
 * @param array $allcaps
 * @param array $caps
 * @param array $args
 * @return array
 */
function awc_user_has_capability( $allcaps, $caps, $args ) {
	if ( isset( $caps[0] ) ) {
		switch ( $caps[0] ) {
			case 'edit_shop_subscription_payment_method':
				$user_id  = $args[1];
				$subscription = awc_get_subscription( $args[2] );

				if ( $subscription && $user_id === $subscription->get_user_id() ) {
					$allcaps['edit_shop_subscription_payment_method'] = true;
				}
			break;
			case 'edit_shop_subscription_status':
				$user_id  = $args[1];
				$subscription = awc_get_subscription( $args[2] );

				if ( $user_id === $subscription->get_user_id() ) {
					$allcaps['edit_shop_subscription_status'] = true;
				}
			break;
			case 'edit_shop_subscription_line_items':
				$user_id  = $args[1];
				$subscription = awc_get_subscription( $args[2] );

				if ( $user_id === $subscription->get_user_id() ) {
					$allcaps['edit_shop_subscription_line_items'] = true;
				}
			break;
			case 'switch_shop_subscription':
				$user_id  = $args[1];
				$subscription = awc_get_subscription( $args[2] );

				if ( $user_id === $subscription->get_user_id() ) {
					$allcaps['switch_shop_subscription'] = true;
				}
			break;
			case 'subscribe_again':
				$user_id  = $args[1];
				$subscription = awc_get_subscription( $args[2] );

				if ( $user_id === $subscription->get_user_id() ) {
					$allcaps['subscribe_again'] = true;
				}
			break;
			case 'pay_for_order':
				$user_id = $args[1];
				$order   = wc_get_order( $args[2] );

				if ( $order && awc_order_contains_subscription( $order, 'any' ) ) {

					if ( $user_id === $order->get_user_id() ) {
						$allcaps['pay_for_order'] = true;
					} else {
						unset( $allcaps['pay_for_order'] );
					}
				}
			break;
			case 'toggle_shop_subscription_auto_renewal':
				$user_id      = $args[1];
				$subscription = awc_get_subscription( $args[2] );

				if ( $subscription && $user_id === $subscription->get_user_id() ) {
					$allcaps['toggle_shop_subscription_auto_renewal'] = true;
				} else {
					unset( $allcaps['toggle_shop_subscription_auto_renewal'] );
				}
			break;
		}
	}
	return $allcaps;
}
add_filter( 'user_has_cap', 'awc_user_has_capability', 15, 3 );

/**
 * Grants shop managers the capability to edit subscribers.
 *
 
 * @param array $roles The user roles shop managers can edit.
 * @return array The list of roles editable by shop managers.
 */
function awc_grant_shop_manager_editable_roles( $roles ) {
	$roles[] = AWC_Settings::get_option('subscriber_default_role') ? AWC_Settings::get_option('subscriber_default_role') : 'subscriber';
	return $roles;
}

add_filter( 'woocommerce_shop_manager_editable_roles', 'awc_grant_shop_manager_editable_roles' );
