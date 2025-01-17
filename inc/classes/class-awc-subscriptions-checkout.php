<?php
/**
 * Subscriptions Checkout
 *
 * Extends the WooCommerce checkout class to add subscription meta on checkout.
 *
 */
class AWC_Subscriptions_Checkout {

	private static $guest_checkout_option_changed = false;

	/**
	 * Bootstraps the class and hooks required actions & filters.
	 *
	 
	 */
	public static function init() {

		// We need to create subscriptions on checkout and want to do it after almost all other extensions have added their products/items/fees
		add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'process_checkout' ), 100, 2 );

		// Make sure users can register on checkout (before any other hooks before checkout)
		add_action( 'woocommerce_before_checkout_form', array( __CLASS__, 'make_checkout_registration_possible' ), -1 );

		// Display account fields as required
		add_action( 'woocommerce_checkout_fields', array( __CLASS__, 'make_checkout_account_fields_required' ), 10 );

		// Restore the settings after switching them for the checkout form
		add_action( 'woocommerce_after_checkout_form', array( __CLASS__, 'restore_checkout_registration_settings' ), 100 );

		// Some callbacks need to hooked after WC has loaded.
		add_action( 'woocommerce_loaded', array( __CLASS__, 'attach_dependant_hooks' ) );

		// Force registration during checkout process
		add_action( 'woocommerce_before_checkout_process', array( __CLASS__, 'force_registration_during_checkout' ), 10 );

		// When a line item is added to a subscription on checkout, ensure the backorder data added by WC is removed
		add_action( 'woocommerce_checkout_create_order_line_item', array( __CLASS__, 'remove_backorder_meta_from_subscription_line_item' ), 10, 4 );

		// When a line item is added to a subscription, ensure the __has_trial meta data is added if applicable.
		add_action( 'woocommerce_checkout_create_order_line_item', array( __CLASS__, 'maybe_add_free_trial_item_meta' ), 10, 4 );

		
	}

	/**
	 
	 */
	public static function attach_dependant_hooks() {
		// Make sure guest checkout is not enabled in option param passed to WC JS
		if ( AWC_Subscriptions::is_woocommerce_pre( '3.3' ) ) {
			add_filter( 'woocommerce_params', array( __CLASS__, 'filter_woocommerce_script_parameters' ), 10, 1 );
			add_filter( 'wc_checkout_params', array( __CLASS__, 'filter_woocommerce_script_parameters' ), 10, 1 );
		} else {
			add_filter( 'woocommerce_get_script_data', array( __CLASS__, 'filter_woocommerce_script_parameters' ), 10, 2 );
		}
	}

	/**
	 * Create subscriptions purchased on checkout.
	 *
	 * @param int $order_id The post_id of a shop_order post/WC_Order object
	 * @param array $posted_data The data posted on checkout
	 
	 */
	public static function process_checkout( $order_id, $posted_data ) {

		if ( ! AWC_Subscription_Cart::cart_contains_subscription() ) {
			return;
		}

		$order = new WC_Order( $order_id );

		$subscriptions = array();

		// First clear out any subscriptions created for a failed payment to give us a clean slate for creating new subscriptions
		$subscriptions = awc_get_subscriptions_for_order( awc_get_objects_property( $order, 'id' ), array( 'order_type' => 'parent' ) );

		if ( ! empty( $subscriptions ) ) {
			remove_action( 'before_delete_post', 'AWC_Subscription_Manager::maybe_cancel_subscription' );
			foreach ( $subscriptions as $subscription ) {
				wp_delete_post( $subscription->get_id() );
			}
			add_action( 'before_delete_post', 'AWC_Subscription_Manager::maybe_cancel_subscription' );
		}

		AWC_Subscription_Cart::set_global_recurring_shipping_packages();

		// Create new subscriptions for each group of subscription products in the cart (that is not a renewal)
		foreach ( WC()->cart->recurring_carts as $recurring_cart ) {

			$subscription = self::create_subscription( $order, $recurring_cart, $posted_data ); // Exceptions are caught by WooCommerce

			if ( is_wp_error( $subscription ) ) {
				throw new Exception( $subscription->get_error_message() );
			}

			do_action( 'woocommerce_checkout_subscription_created', $subscription, $order, $recurring_cart );
		}

		do_action( 'subscriptions_created_for_order', $order ); // Backward compatibility
	}

	/**
	 * Create a new subscription from a cart item on checkout.
	 *
	 * The function doesn't validate whether the cart item is a subscription product, meaning it can be used for any cart item,
	 * but the item will need a `subscription_period` and `subscription_period_interval` value set on it, at a minimum.
	 *
	 * @param WC_Order $order
	 * @param WC_Cart $cart
	 
	 */
	public static function create_subscription( $order, $cart, $posted_data ) {
		global $wpdb;

		try {
			// Start transaction if available
			$wpdb->query( 'START TRANSACTION' );

			// Set the recurring line totals on the subscription
			$variation_id = awc_cart_pluck( $cart, 'variation_id' );
			$product_id   = empty( $variation_id ) ? awc_cart_pluck( $cart, 'product_id' ) : $variation_id;

			$subscription = awc_create_subscription( array(
				'start_date'       => $cart->start_date,
				'order_id'         => awc_get_objects_property( $order, 'id' ),
				'customer_id'      => $order->get_user_id(),
				'billing_period'   => awc_cart_pluck( $cart, 'subscription_period' ),
				'billing_interval' => awc_cart_pluck( $cart, 'subscription_period_interval' ),
				'customer_note'    => awc_get_objects_property( $order, 'customer_note' ),
			) );

			if ( is_wp_error( $subscription ) ) {
				throw new Exception( $subscription->get_error_message() );
			}

			// Set the subscription's billing and shipping address
			$subscription = awc_copy_order_address( $order, $subscription );

			$subscription->update_dates( array(
				'trial_end'    => $cart->trial_end_date,
				'next_payment' => $cart->next_payment_date,
				'end'          => $cart->end_date,
			) );

			// Store trial period for PayPal
			if ( awc_cart_pluck( $cart, 'subscription_trial_length' ) > 0 ) {
				$subscription->set_trial_period( awc_cart_pluck( $cart, 'subscription_trial_period' ) );
			}

			// Set the payment method on the subscription
			$available_gateways   = WC()->payment_gateways->get_available_payment_gateways();
			$order_payment_method = awc_get_objects_property( $order, 'payment_method' );

			if ( $cart->needs_payment() && isset( $available_gateways[ $order_payment_method ] ) ) {
				$subscription->set_payment_method( $available_gateways[ $order_payment_method ] );
			}

			if ( ! $cart->needs_payment() || 'yes' == get_option( AWC_Subscriptions_Admin::$option_prefix . '_turn_off_automatic_payments', 'no' ) ) {
				$subscription->set_requires_manual_renewal( true );
			} elseif ( ! isset( $available_gateways[ $order_payment_method ] ) || ! $available_gateways[ $order_payment_method ]->supports( 'subscriptions' ) ) {
				$subscription->set_requires_manual_renewal( true );
			}

			awc_copy_order_meta( $order, $subscription, 'subscription' );

			// Store the line items
			if ( is_callable( array( WC()->checkout, 'create_order_line_items' ) ) ) {
				WC()->checkout->create_order_line_items( $subscription, $cart );
			} else {
				foreach ( $cart->get_cart() as $cart_item_key => $cart_item ) {
					$item_id = self::add_cart_item( $subscription, $cart_item, $cart_item_key );
				}
			}

			// Store fees (although no fees recur by default, extensions may add them)
			if ( is_callable( array( WC()->checkout, 'create_order_fee_lines' ) ) ) {
				WC()->checkout->create_order_fee_lines( $subscription, $cart );
			} else {
				foreach ( $cart->get_fees() as $fee_key => $fee ) {
					$item_id = $subscription->add_fee( $fee );

					if ( ! $item_id ) {
						// translators: placeholder is an internal error number
						throw new Exception( sprintf( __( 'Error %d: Unable to create subscription. Please try again.', 'subscriptions-recurring-payments-for-woocommerce' ), 403 ) );
					}

					// Allow plugins to add order item meta to fees
					do_action( 'woocommerce_add_order_fee_meta', $subscription->get_id(), $item_id, $fee, $fee_key );
				}
			}

			self::add_shipping( $subscription, $cart );

			// Store tax rows
			if ( is_callable( array( WC()->checkout, 'create_order_tax_lines' ) ) ) {
				WC()->checkout->create_order_tax_lines( $subscription, $cart );
			} else {
				foreach ( array_keys( $cart->taxes + $cart->shipping_taxes ) as $tax_rate_id ) {
					if ( $tax_rate_id && ! $subscription->add_tax( $tax_rate_id, $cart->get_tax_amount( $tax_rate_id ), $cart->get_shipping_tax_amount( $tax_rate_id ) ) && apply_filters( 'woocommerce_cart_remove_taxes_zero_rate_id', 'zero-rated' ) !== $tax_rate_id ) {
						// translators: placeholder is an internal error number
						throw new Exception( sprintf( __( 'Error %d: Unable to add tax to subscription. Please try again.', 'subscriptions-recurring-payments-for-woocommerce' ), 405 ) );
					}
				}
			}

			// Store coupons
			if ( is_callable( array( WC()->checkout, 'create_order_coupon_lines' ) ) ) {
				WC()->checkout->create_order_coupon_lines( $subscription, $cart );
			} else {
				foreach ( $cart->get_coupons() as $code => $coupon ) {
					if ( ! $subscription->add_coupon( $code, $cart->get_coupon_discount_amount( $code ), $cart->get_coupon_discount_tax_amount( $code ) ) ) {
						// translators: placeholder is an internal error number
						throw new Exception( sprintf( __( 'Error %d: Unable to create order. Please try again.', 'subscriptions-recurring-payments-for-woocommerce' ), 406 ) );
					}
				}
			}

			// Set the recurring totals on the subscription
			$subscription->set_shipping_total( $cart->shipping_total );
			$subscription->set_discount_total( $cart->get_cart_discount_total() );
			$subscription->set_discount_tax( $cart->get_cart_discount_tax_total() );
			$subscription->set_cart_tax( $cart->tax_total );
			$subscription->set_shipping_tax( $cart->shipping_tax_total );
			$subscription->set_total( $cart->total );

			// Hook to adjust subscriptions before saving with WC 3.0+ (matches WC 3.0's new 'woocommerce_checkout_create_order' hook)
			do_action( 'woocommerce_checkout_create_subscription', $subscription, $posted_data, $order, $cart );

			// Save the subscription if using WC 3.0 & CRUD
			$subscription->save();

			// If we got here, the subscription was created without problems
			$wpdb->query( 'COMMIT' );

		} catch ( Exception $e ) {
			// There was an error adding the subscription
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'checkout-error', $e->getMessage() );
		}

		return $subscription;
	}


	/**
	 * Stores shipping info on the subscription
	 *
	 * @param WC_Subscription $subscription instance of a subscriptions object
	 * @param WC_Cart $cart A cart with recurring items in it
	 */
	public static function add_shipping( $subscription, $cart ) {

		// We need to make sure we only get recurring shipping packages
		AWC_Subscription_Cart::set_calculation_type( 'recurring_total' );

		foreach ( $cart->get_shipping_packages() as $package_index => $base_package ) {

			$package = AWC_Subscription_Cart::get_calculated_shipping_for_package( $base_package );

			$recurring_shipping_package_key = AWC_Subscription_Cart::get_recurring_shipping_package_key( $cart->recurring_cart_key, $package_index );

			$shipping_method_id = isset( WC()->checkout()->shipping_methods[ $package_index ] ) ? WC()->checkout()->shipping_methods[ $package_index ] : '';

			if ( isset( WC()->checkout()->shipping_methods[ $recurring_shipping_package_key ] ) ) {
				$shipping_method_id = WC()->checkout()->shipping_methods[ $recurring_shipping_package_key ];
				$package_key        = $recurring_shipping_package_key;
			} else {
				$package_key        = $package_index;
			}

			if ( isset( $package['rates'][ $shipping_method_id ] ) ) {

				if ( AWC_Subscriptions::is_woocommerce_pre( '3.0' ) ) {

					$item_id = $subscription->add_shipping( $package['rates'][ $shipping_method_id ] );

					// Allows plugins to add order item meta to shipping
					do_action( 'woocommerce_add_shipping_order_item', $subscription->get_id(), $item_id, $package_key );
					do_action( 'woocommerce_subscriptions_add_recurring_shipping_order_item', $subscription->get_id(), $item_id, $package_key );

				} else { // WC 3.0+

					$shipping_rate            = $package['rates'][ $shipping_method_id ];
					$item                     = new WC_Order_Item_Shipping();
					$item->legacy_package_key = $package_key; // @deprecated For legacy actions.
					$item->set_props( array(
						'method_title' => $shipping_rate->label,
						'method_id'    => $shipping_rate->id,
						'total'        => wc_format_decimal( $shipping_rate->cost ),
						'taxes'        => array( 'total' => $shipping_rate->taxes ),
						'order_id'     => $subscription->get_id(),
					) );

					foreach ( $shipping_rate->get_meta_data() as $key => $value ) {
						$item->add_meta_data( $key, $value, true );
					}

					$subscription->add_item( $item );

					$item->save(); // We need the item ID for old hooks, this can be removed once support for WC < 3.0 is dropped
					wc_do_deprecated_action( 'woocommerce_subscriptions_add_recurring_shipping_order_item', array( $subscription->get_id(), $item->get_id(), $package_key ), '2.2.0', 'CRUD and woocommerce_checkout_create_subscription_shipping_item action instead' );

					do_action( 'woocommerce_checkout_create_order_shipping_item', $item, $package_key, $package, $subscription ); // WC 3.0+ will also trigger the deprecated 'woocommerce_add_shipping_order_item' hook
					do_action( 'woocommerce_checkout_create_subscription_shipping_item', $item, $package_key, $package, $subscription );
				}
			}
		}

		AWC_Subscription_Cart::set_calculation_type( 'none' );
	}

	/**
	 * Remove the Backordered meta data from subscription line items added on the checkout.
	 *
	 * @param WC_Order_Item_Product $order_item
	 * @param string $cart_item_key The hash used to identify the item in the cart
	 * @param array $cart_item The cart item's data.
	 * @param WC_Order|WC_Subscription $subscription The order or subscription object to which the line item relates
	 
	 */
	public static function remove_backorder_meta_from_subscription_line_item( $item, $cart_item_key, $cart_item, $subscription ) {

		if ( awc_is_subscription( $subscription ) ) {
			$item->delete_meta_data( apply_filters( 'woocommerce_backordered_item_meta_name', __( 'Backordered', 'subscriptions-recurring-payments-for-woocommerce' ) ) );
		}
	}

	/**
	 * Set a flag in subscription line item meta if the line item has a free trial.
	 *
	 * @param WC_Order_Item_Product $item The item being added to the subscription.
	 * @param string $cart_item_key The item's cart item key.
	 * @param array $cart_item The cart item.
	 * @param WC_Subscription $subscription The subscription the item is being added to.
	 
	 */
	public static function maybe_add_free_trial_item_meta( $item, $cart_item_key, $cart_item, $subscription ) {
		if ( awc_is_subscription( $subscription ) && AWC_Subscription_Products::awc_get_trial_length( $item->get_product() ) > 0 ) {
			$item->update_meta_data( '_has_trial', 'true' );
		}
	}

	/**
	 * Add a cart item to a subscription.
	 *
	 
	 */
	public static function add_cart_item( $subscription, $cart_item, $cart_item_key ) {
		if ( ! AWC_Subscriptions::is_woocommerce_pre( '3.0' ) ) {
			_deprecated_function( __METHOD__, '2.2.0', 'WC_Checkout::create_order_line_items( $subscription, $cart )' );
		}

		$item_id = $subscription->add_product(
			$cart_item['data'],
			$cart_item['quantity'],
			array(
				'variation' => $cart_item['variation'],
				'totals'    => array(
					'subtotal'     => $cart_item['line_subtotal'],
					'subtotal_tax' => $cart_item['line_subtotal_tax'],
					'total'        => $cart_item['line_total'],
					'tax'          => $cart_item['line_tax'],
					'tax_data'     => $cart_item['line_tax_data'],
				),
			)
		);

		if ( ! $item_id ) {
			// translators: placeholder is an internal error number
			throw new Exception( sprintf( __( 'Error %d: Unable to create subscription. Please try again.', 'subscriptions-recurring-payments-for-woocommerce' ), 402 ) );
		}

		$cart_item_product_id = ( 0 != $cart_item['variation_id'] ) ? $cart_item['variation_id'] : $cart_item['product_id'];

		if ( AWC_Subscription_Products::awc_get_trial_length( awc_get_canonical_product_id( $cart_item ) ) > 0 ) {
			wc_add_order_item_meta( $item_id, '_has_trial', 'true' );
		}

		// Allow plugins to add order item meta
		if ( AWC_Subscriptions::is_woocommerce_pre( '3.0' ) ) {
			do_action( 'woocommerce_add_order_item_meta', $item_id, $cart_item, $cart_item_key );
			do_action( 'woocommerce_add_subscription_item_meta', $item_id, $cart_item, $cart_item_key );
		} else {
			wc_do_deprecated_action( 'woocommerce_add_order_item_meta', array( $item_id, $cart_item, $cart_item_key ), '3.0', 'CRUD and woocommerce_checkout_create_order_line_item action instead' );
			wc_do_deprecated_action( 'woocommerce_add_subscription_item_meta', array( $item_id, $cart_item, $cart_item_key ), '3.0', 'CRUD and woocommerce_checkout_create_order_line_item action instead' );
		}

		return $item_id;
	}

	/**
	 * When a new order is inserted, add subscriptions related order meta.
	 *
	 
	 */
	public static function add_order_meta( $order_id, $posted ) {
		
	}

	/**
	 * Add each subscription product's details to an order so that the state of the subscription persists even when a product is changed
	 *
	 
	 */
	public static function add_order_item_meta( $item_id, $values ) {
		
	}

	/**
	 * If shopping cart contains subscriptions, make sure a user can register on the checkout page
	 *
	 
	 */
	public static function make_checkout_registration_possible( $checkout = '' ) {

		if ( AWC_Subscription_Cart::cart_contains_subscription() && ! is_user_logged_in() ) {

			// Make sure users are required to register an account
			if ( true === $checkout->enable_guest_checkout ) {
				$checkout->enable_guest_checkout = false;
				self::$guest_checkout_option_changed = true;

				$checkout->must_create_account = true;
			}
		}
	}

	/**
	 * Make sure account fields display the required "*" when they are required.
	 *
	 
	 */
	public static function make_checkout_account_fields_required( $checkout_fields ) {

		if ( AWC_Subscription_Cart::cart_contains_subscription() && ! is_user_logged_in() ) {

			$account_fields = array(
				'account_username',
				'account_password',
				'account_password-2',
			);

			foreach ( $account_fields as $account_field ) {
				if ( isset( $checkout_fields['account'][ $account_field ] ) ) {
					$checkout_fields['account'][ $account_field ]['required'] = true;
				}
			}
		}

		return $checkout_fields;
	}

	/**
	 * After displaying the checkout form, restore the store's original registration settings.
	 *
	 
	 */
	public static function restore_checkout_registration_settings( $checkout = '' ) {

		if ( self::$guest_checkout_option_changed ) {
			$checkout->enable_guest_checkout = true;
			if ( ! is_user_logged_in() ) { // Also changed must_create_account
				$checkout->must_create_account = false;
			}
		}
	}

	/**
	 * Also make sure the guest checkout option value passed to the woocommerce.js forces registration.
	 * Otherwise the registration form is hidden by woocommerce.js.
	 *
	 * @param string $handle Default empty string ('').
	 * @param array  $woocommerce_params
	 *
	 
	 * @return array
	 */
	public static function filter_woocommerce_script_parameters( $woocommerce_params, $handle = '' ) {
		// WC 3.3+ deprecates handle-specific filters in favor of 'woocommerce_get_script_data'.
		if ( 'woocommerce_get_script_data' === current_filter() && ! in_array( $handle, array( 'woocommerce', 'wc-checkout' ) ) ) {
			return $woocommerce_params;
		}

		if ( AWC_Subscription_Cart::cart_contains_subscription() && ! is_user_logged_in() && isset( $woocommerce_params['option_guest_checkout'] ) && 'yes' == $woocommerce_params['option_guest_checkout'] ) {
			$woocommerce_params['option_guest_checkout'] = 'no';
		}

		return $woocommerce_params;
	}

	/**
	 * Also make sure the guest checkout option value passed to the woocommerce.js forces registration.
	 * Otherwise the registration form is hidden by woocommerce.js.
	 *
	 */
	public static function filter_woocommerce_script_paramaters( $woocommerce_params, $handle = '' ) {
		_deprecated_function( __METHOD__, '2.5.3', 'AWC_Subscriptions_Admin::filter_woocommerce_script_parameters( $woocommerce_params, $handle )' );

		return self::filter_woocommerce_script_parameters( $woocommerce_params, $handle );
	}

	/**
	 * During the checkout process, force registration when the cart contains a subscription.
	 *
	 */
	public static function force_registration_during_checkout( $woocommerce_params ) {

		if ( AWC_Subscription_Cart::cart_contains_subscription() && ! is_user_logged_in() ) {
			$_POST['createaccount'] = 1;
		}

	}

	/**
	 * When creating an order at checkout, if the checkout is to renew a subscription from a failed
	 * payment, hijack the order creation to make a renewal order - not a plain WooCommerce order.
	 *
	 
	 * 
	 */
	public static function filter_woocommerce_create_order( $order_id, $checkout_object ) {
		
		return $order_id;
	}

	/**
	 * Customise which actions are shown against a subscriptions order on the My Account page.
	 *
	 
	 */
	public static function filter_woocommerce_my_account_my_orders_actions( $actions, $order ) {
		_deprecated_function( __METHOD__, '2.0', 'awc_Cart_Renewal::filter_my_account_my_orders_actions()' );
		return $actions;
	}
}
