<?php
/**
 * Make it possible for customers to change the payment gateway used for an existing subscription.
 *
 
 */
class AWC_Subscription_Change_Payment_Gateway {

	public static $is_request_to_change_payment = false;

	/**
	 * An internal cache of WooCommerce customer notices.
	 *
	 * @var array
	 */
	private static $notices = array();

	/**
	 * Bootstraps the class and hooks required actions & filters.
	 *
	 
	 */
	public static function init() {

		// Maybe allow for a recurring payment method to be changed
		add_action( 'plugins_loaded', __CLASS__ . '::set_change_payment_method_flag' );

		// Attach hooks which depend on WooCommerce constants
		add_action( 'woocommerce_loaded', __CLASS__ . '::attach_dependant_hooks' );

		// Keep a record of any messages or errors that should be displayed
		add_action( 'before_woocommerce_pay', __CLASS__ . '::store_pay_shortcode_messages', 5 );

		add_action( 'after_woocommerce_pay', __CLASS__ . '::maybe_replace_pay_shortcode', 100 );

		// Maybe allow for a recurring payment method to be changed
		add_filter( 'awc_view_details_subscription_myaccount_actions', __CLASS__ . '::change_payment_method_button', 10, 2 );

		// Maybe allow for a recurring payment method to be changed
		add_action( 'wp_loaded', __CLASS__ . '::change_payment_method_via_pay_shortcode', 20 );

		// Filter the available payment gateways to only show those which support acting as the new payment method
		add_filter( 'woocommerce_available_payment_gateways', __CLASS__ . '::get_available_payment_gateways' );

		// If we're changing the payment method, we want to make sure a number of totals return $0 (to prevent payments being processed now)
		add_filter( 'woocommerce_subscriptions_total_initial_payment', __CLASS__ . '::maybe_zero_total', 11, 2 );
		add_filter( 'woocommerce_subscriptions_sign_up_fee', __CLASS__ . '::maybe_zero_total', 11, 2 );

		// Redirect to My Account page after changing payment method
		add_filter( 'woocommerce_get_return_url', __CLASS__ . '::get_return_url', 11 );

		// Update the recurring payment method when a customer has completed the payment for a renewal payment which previously failed
		add_action( 'woocommerce_subscriptions_paid_for_failed_renewal_order', __CLASS__ . '::change_failing_payment_method', 10, 2 );

		// Add a 'new-payment-method' handler to the AWC_Subscription::can_be_updated_to() function
		add_filter( 'woocommerce_can_subscription_be_updated_to_new-payment-method', __CLASS__ . '::can_subscription_be_updated_to_new_payment_method', 10, 2 );

		// Change the "Pay for Order" page title to "Change Payment Method"
		add_filter( 'the_title', __CLASS__ . '::change_payment_method_page_title', 100 );

		// Change the "Pay for Order" breadcrumb to "Change Payment Method"
		add_filter( 'woocommerce_get_breadcrumb', __CLASS__ . '::change_payment_method_breadcrumb', 10, 1 );

		// Maybe filter subscriptions_needs_payment to return false when processing change-payment-gateway requests
		add_filter( 'woocommerce_subscription_needs_payment', __CLASS__ . '::maybe_override_needs_payment', 10, 1 );

		// Display a login form if the customer is requesting to change their payment method but aren't logged in.
		add_filter( 'the_content', array( __CLASS__, 'maybe_request_log_in' ) );

	}

	/**
	 * Attach WooCommerce version dependent hooks
	 *
	 
	 */
	public static function attach_dependant_hooks() {

		if ( AWC_Subscriptions::is_woocommerce_pre( '3.0' ) ) {

			// If we're changing the payment method, we want to make sure a number of totals return $0 (to prevent payments being processed now)
			add_filter( 'woocommerce_order_amount_total', __CLASS__ . '::maybe_zero_total', 11, 2 );

		} else {

			// If we're changing the payment method, we want to make sure a number of totals return $0 (to prevent payments being processed now)
			add_filter( 'woocommerce_subscription_get_total', __CLASS__ . '::maybe_zero_total', 11, 2 );

		}
	}

	/**
	 * Set a flag to indicate that the current request is for changing payment. Better than requiring other extensions
	 * to check the $_GET global as it allows for the flag to be overridden.
	 *
	 
	 */
	public static function set_change_payment_method_flag() {
		if ( isset( $_GET['change_payment_method'] ) ) {
			self::$is_request_to_change_payment = true;
		}
	}

	/**
	 * Store any messages or errors added by other plugins.
	 *
	 * This is particularly important for those occasions when the new payment method caused and error or failure.
	 */
	public static function store_pay_shortcode_messages() {
		self::$notices = wc_get_notices();
	}

	/**
	 * Store messages ore errors added by other plugins.
	 *
	 */
	public static function store_pay_shortcode_mesages() {
		self::store_pay_shortcode_messages();
	}

	/**
	 * If requesting a payment method change, replace the woocommerce_pay_shortcode() with a change payment form.
	 *
	 
	 */
	public static function maybe_replace_pay_shortcode() {
		global $wp;
		$valid_request = false;

		// Exit early if this isn't a change payment request and it's not a order-pay page for a subscription
		if ( ! self::$is_request_to_change_payment && ( ! isset( $wp->query_vars['order-pay'] ) || ! awc_is_subscription( absint( $wp->query_vars['order-pay'] ) ) ) ) {
			return;
		}

		/*
		 * Clear the output buffer.
		 *
		 * Because this function is hooked onto 'after_woocommerce_pay', WC would have started outputting
		 * the core order pay shortcode. Clearing the output buffer removes that partially outputted template.
		 */
		ob_clean();

		// Because we've cleared the buffer, we need to re-include the opening container div.
		echo '<div class="woocommerce">';

		// If the request to pay for the order belongs to a subscription but there's no GET params for changing payment method, show receipt page.
		if ( ! self::$is_request_to_change_payment ) {
			$valid_request    = true;
			$subscription     = awc_get_subscription( absint( $wp->query_vars['order-pay'] ) );
			$subscription_key = isset( $_GET['key'] ) ? wc_clean( $_GET['key'] ) : '';

			do_action( 'before_woocommerce_pay' );

			/**
			 * awc_before_replace_pay_shortcode
			 *
			 * Action that allows payment methods to modify the subscription object so, for example,
			 * if the new payment method still hasn't been set, they can set it temporarily (without saving).
			 *
			 
			 *
			 * @param WC_Subscription $subscription
			 */
			do_action( 'awc_before_replace_pay_shortcode', $subscription );

			if ( $subscription && $subscription->get_id() === absint( $wp->query_vars['order-pay'] ) && $subscription->get_order_key() === $subscription_key ) {
				awc_Template_Loader::get_subscription_receipt_template( $subscription );
			} else {
				// The before_woocommerce_pay action would have printed all the notices so we need to print the notice directly.
				wc_print_notice( __( 'Sorry, this subscription change payment method request is invalid and cannot be processed.', 'subscriptions-recurring-payments-for-woocommerce' ), 'error' );
			}
		} else {

			// Re-add all the notices that would have been displayed but have now been cleared from the output.
			foreach ( self::$notices as $notice_type => $notices ) {
				foreach ( $notices as $notice ) {
					if ( AWC_Subscriptions::is_woocommerce_pre( '3.9' ) ) {
						wc_add_notice( $notice, $notice_type );
					} else {
						wc_add_notice( $notice['notice'], $notice_type, $notice['data'] );
					}
				}
			}

			$subscription  = awc_get_subscription( absint( $_GET['change_payment_method'] ) );
			$valid_request = self::validate_change_payment_request( $subscription );

			// WC display notices on this hook so trigger it after all notices have been added,
			do_action( 'before_woocommerce_pay' );

			if ( $valid_request ) {
				if ( $subscription->get_time( 'next_payment' ) > 0 ) {
					// translators: placeholder is next payment's date
					$next_payment_string = sprintf( __( ' Next payment is due %s.', 'subscriptions-recurring-payments-for-woocommerce' ), $subscription->get_date_to_display( 'next_payment' ) );
				} else {
					$next_payment_string = '';
				}

				// translators: placeholder is either empty or "Next payment is due..."
				wc_print_notice( sprintf( __( 'Choose a new payment method.%s', 'subscriptions-recurring-payments-for-woocommerce' ), $next_payment_string ), 'notice' );

				// Set the customer location to subscription billing location
				foreach ( array( 'country', 'state', 'postcode' ) as $address_property ) {
					$subscription_address = $subscription->{"get_billing_$address_property"}();

					if ( $subscription_address ) {
						WC()->customer->{"set_billing_$address_property"}( $subscription_address );
					}
				}

				wc_get_template( 'checkout/form-change-payment-method.php', array( 'subscription' => $subscription ), '', plugin_dir_path( AWC_Subscriptions::$plugin_file ) . 'temp/' );
			}
		}

		if ( false === $valid_request ) {
			wc_print_notices();
		}
	}


	/**
	 * Validates the request to change a subscription's payment method.
	 *
	 * Will display a customer facing notice if the request is invalid.
	 *
	 
	 *
	 * @param WC_Subscription $subscription
	 * @return bool Whether the request is valid or not.
	 */
	private static function validate_change_payment_request( $subscription ) {
		$is_valid = true;

		if ( wp_verify_nonce( $_GET['_wpnonce'] ) === false ) {
			$is_valid = false;
			wc_add_notice( __( 'There was an error with your request. Please try again.', 'subscriptions-recurring-payments-for-woocommerce' ), 'error' );
		} elseif ( empty( $subscription ) ) {
			$is_valid = false;
			wc_add_notice( __( 'Invalid Subscription.', 'subscriptions-recurring-payments-for-woocommerce' ), 'error' );
		} elseif ( ! current_user_can( 'edit_shop_subscription_payment_method', $subscription->get_id() ) ) {
			$is_valid = false;
			wc_add_notice( __( 'That doesn\'t appear to be one of your subscriptions.', 'subscriptions-recurring-payments-for-woocommerce' ), 'error' );
		} elseif ( ! $subscription->can_be_updated_to( 'new-payment-method' ) ) {
			$is_valid = false;
			wc_add_notice( __( 'The payment method can not be changed for that subscription.', 'subscriptions-recurring-payments-for-woocommerce' ), 'error' );
		} elseif ( $subscription->get_order_key() !== $_GET['key'] ) {
			$is_valid = false;
			wc_add_notice( __( 'Invalid order.', 'subscriptions-recurring-payments-for-woocommerce' ), 'error' );
		}

		return $is_valid;
	}

	/**
	 * Add a "Change Payment Method" button to the "My Subscriptions" table.
	 *
	 * @param array $all_actions The $subscription_key => $actions array with all actions that will be displayed for a subscription on the "My Subscriptions" table
	 * @param array $subscriptions All of a given users subscriptions that will be displayed on the "My Subscriptions" table
	 
	 */
	public static function change_payment_method_button( $actions, $subscription ) {

		if ( $subscription->can_be_updated_to( 'new-payment-method' ) ) {

			if ( $subscription->has_payment_gateway() && wc_get_payment_gateway_by_order( $subscription )->supports( 'subscriptions' ) ) {
				$action_name = _x( 'Change payment', 'label on button, imperative', 'subscriptions-recurring-payments-for-woocommerce' );
			} else {
				$action_name = _x( 'Add payment', 'label on button, imperative', 'subscriptions-recurring-payments-for-woocommerce' );
			}

			$actions['change_payment_method'] = array(
				'url'  => wp_nonce_url( add_query_arg( array( 'change_payment_method' => $subscription->get_id() ), $subscription->get_checkout_payment_url() ) ),
				'name' => $action_name,
			);
		}

		return $actions;
	}

	/**
	 * Process the change payment form.
	 *
	 * Based on the @see woocommerce_pay_action() function.
	 *
	 * @access public
	 * @return void
	 
	 */
	public static function change_payment_method_via_pay_shortcode() {

		if ( ! isset( $_POST['_asubnonce'] ) || ! wp_verify_nonce( $_POST['_asubnonce'], 'awc_change_payment_method' ) ) {
			return;
		}

		$subscription_id = absint( $_POST['woocommerce_change_payment'] );
		$subscription = awc_get_subscription( $subscription_id );

		do_action( 'woocommerce_subscription_change_payment_method_via_pay_shortcode', $subscription );

		ob_start();

		if ( $subscription->get_order_key() == $_GET['key'] ) {

			$subscription_billing_country  = $subscription->get_billing_country();
			$subscription_billing_state    = $subscription->get_billing_state();
			$subscription_billing_postcode = $subscription->get_billing_postcode();
			$subscription_billing_city     = $subscription->get_billing_postcode();

			// Set customer location to order location
			if ( $subscription_billing_country ) {
				$setter = is_callable( array( WC()->customer, 'set_billing_country' ) ) ? 'set_billing_country' : 'set_country';
				WC()->customer->$setter( $subscription_billing_country );
			}
			if ( $subscription_billing_state ) {
				$setter = is_callable( array( WC()->customer, 'set_billing_state' ) ) ? 'set_billing_state' : 'set_state';
				WC()->customer->$setter( $subscription_billing_state );
			}
			if ( $subscription_billing_postcode ) {
				$setter = is_callable( array( WC()->customer, 'set_billing_postcode' ) ) ? 'set_billing_postcode' : 'set_postcode';
				WC()->customer->$setter( $subscription_billing_postcode );
			}
			if ( $subscription_billing_city ) {
				$setter = is_callable( array( WC()->customer, 'set_billing_city' ) ) ? 'set_billing_city' : 'set_city';
				WC()->customer->$setter( $subscription_billing_city );
			}

			// Update payment method
			$new_payment_method = wc_clean( $_POST['payment_method'] );
			$notice = $subscription->has_payment_gateway() ? __( 'Payment method updated.', 'subscriptions-recurring-payments-for-woocommerce' ) : __( 'Payment method added.', 'subscriptions-recurring-payments-for-woocommerce' );

			// Allow some payment gateways which can't process the payment immediately, like PayPal, to do it later after the payment/sign-up is confirmed
			if ( apply_filters( 'woocommerce_subscriptions_update_payment_via_pay_shortcode', true, $new_payment_method, $subscription ) ) {
				self::update_payment_method( $subscription, $new_payment_method );
			}

			$available_gateways = WC()->payment_gateways->get_available_payment_gateways();

			// Validate
			$available_gateways[ $new_payment_method ]->validate_fields();

			// Process payment for the new method (with a $0 order total)
			if ( wc_notice_count( 'error' ) == 0 ) {

				$result = $available_gateways[ $new_payment_method ]->process_payment( $subscription->get_id() );

				if ( 'success' == $result['result'] && wc_get_page_permalink( 'myaccount' ) == $result['redirect'] ) {
					$result['redirect'] = $subscription->get_view_order_url();
				}

				$result = apply_filters( 'woocommerce_subscriptions_process_payment_for_change_method_via_pay_shortcode', $result, $subscription );

				if ( 'success' != $result['result'] ) {
					return;
				}

				$subscription->set_requires_manual_renewal( false );
				$subscription->save();

				// Does the customer want all current subscriptions to be updated to this payment method?
				if (
					isset( $_POST['update_all_subscriptions_payment_method'] )
					&& $_POST['update_all_subscriptions_payment_method']
					&& WC_Subscriptions_Change_Payment_Gateway::can_update_all_subscription_payment_methods( $available_gateways[ $new_payment_method ], $subscription )
				) {
					// Allow some payment gateways which can't process the payment immediately, like PayPal, to do it later after the payment/sign-up is confirmed
					if ( ! apply_filters( 'woocommerce_subscriptions_update_payment_via_pay_shortcode', true, $new_payment_method, $subscription ) ) {
						$subscription->update_meta_data( '_delayed_update_payment_method_all', $new_payment_method );
						$subscription->save();
						$notice = __( 'Payment method updated for all your current subscriptions.', 'subscriptions-recurring-payments-for-woocommerce' );
					} elseif ( self::update_all_payment_methods_from_subscription( $subscription, $new_payment_method ) ) {
						$notice = __( 'Payment method updated for all your current subscriptions.', 'subscriptions-recurring-payments-for-woocommerce' );
					}
				}

				// Redirect to success/confirmation/payment page
				wc_add_notice( $notice );
				wp_redirect( $result['redirect'] );
				exit;
			}
		}

		ob_get_clean();
	}

	/**
	 * Update the recurring payment method on all current subscriptions to the payment method on this subscription.
	 *
	 * @param  WC_Subscription $subscription An instance of a WC_Subscription object.
	 * @param  string $new_payment_method The ID of the new payment method.
	 * @return bool Were other subscriptions updated.
	 
	 */
	public static function update_all_payment_methods_from_subscription( $subscription, $new_payment_method ) {

		// Require the delayed payment update method to match the current gateway if it is set
		if ( self::will_subscription_update_all_payment_methods( $subscription ) ) {
			if ( $subscription->get_meta( '_delayed_update_payment_method_all' ) != $new_payment_method ) {
				return false;
			}

			$subscription->delete_meta_data( '_delayed_update_payment_method_all' );
			$subscription->save_meta_data();
		}

		$payment_meta_table = awc_Payment_tokens::get_subscription_payment_meta( $subscription, $new_payment_method );
		if ( ! is_array( $payment_meta_table ) ) {
			return false;
		}

		$subscription_ids = awc_Customer_Store::instance()->get_users_subscription_ids( $subscription->get_customer_id() );

		foreach ( $subscription_ids as $subscription_id ) {
			// Skip the subscription providing the new payment meta.
			if ( $subscription->get_id() == $subscription_id ) {
				continue;
			}

			$user_subscription = awc_get_subscription( $subscription_id );
			// Skip if subscription's current payment method is not supported
			if ( ! $user_subscription->payment_method_supports( 'subscription_cancellation' ) ) {
				continue;
			}

			// Skip if there are no remaining payments or the subscription is not current.
			if ( $user_subscription->get_time( 'next_payment' ) <= 0 || ! $user_subscription->has_status( array( 'active', 'on-hold' ) ) ) {
				continue;
			}

			self::update_payment_method( $user_subscription, $new_payment_method, $payment_meta_table );

			$user_subscription->set_requires_manual_renewal( false );
			$user_subscription->save();
		}

		return true;
	}

	/**
	 * Check whether a payment method supports updating all current subscriptions' payment method.
	 *
	 * @param  WC_Payment_Gateway $gateway The payment gateway to check.
	 * @param  AWC_Subscription $subscription An instance of a AWC_Subscription object.
	 * @return bool Gateway supports updating all current subscriptions.
	 
	 */
	public static function can_update_all_subscription_payment_methods( $gateway, $subscription ) {

		if ( $gateway->supports( 'subscription_payment_method_delayed_change' ) ) {
			return true;
		}

		if ( ! $gateway->supports( 'subscription_payment_method_change_admin' ) ) {
			return false;
		}

		if ( apply_filters( 'woocommerce_subscriptions_update_payment_via_pay_shortcode', true, $gateway->id, $subscription ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Check whether a subscription will update all current subscriptions' payment method.
	 *
	 * @param  WC_Subscription $subscription An instance of a WC_Subscription object.
	 * @return bool Subscription will update all current subscriptions.
	 
	 */
	public static function will_subscription_update_all_payment_methods( $subscription ) {
		if ( ! awc_is_subscription( $subscription ) ) {
			return false;
		}

		return (bool) $subscription->get_meta( '_delayed_update_payment_method_all' );
	}

	/**
	 * Update the recurring payment method on a subscription order.
	 *
	 * @param WC_Subscription $subscription An instance of a WC_Subscription object.
	 * @param string $new_payment_method The ID of the new payment method.
	 * @param array  $new_payment_method_meta The meta for the new payment method. Optional. Default false.
	 
	 */
	public static function update_payment_method( $subscription, $new_payment_method, $new_payment_method_meta = false ) {

		$old_payment_method       = $subscription->get_payment_method();
		$old_payment_method_title = $subscription->get_payment_method_title();
		$available_gateways       = WC()->payment_gateways->get_available_payment_gateways(); // Also inits all payment gateways to make sure that hooks are attached correctly

		do_action( 'woocommerce_subscriptions_pre_update_payment_method', $subscription, $new_payment_method, $old_payment_method );

		// Make sure the subscription is cancelled with the current gateway
		AWC_Subscription_Payment_Gateways::trigger_gateway_status_updated_hook( $subscription, 'cancelled' );

		// Update meta
		if ( isset( $available_gateways[ $new_payment_method ] ) ) {
			$new_payment_method_title = $available_gateways[ $new_payment_method ]->get_title();
		} else {
			$new_payment_method_title = '';
		}

		if ( empty( $old_payment_method_title ) ) {
			$old_payment_method_title = $old_payment_method;
		}

		if ( empty( $new_payment_method_title ) ) {
			$new_payment_method_title = $new_payment_method;
		}

		$subscription->update_meta_data( '_old_payment_method', $old_payment_method );
		$subscription->update_meta_data( '_old_payment_method_title', $old_payment_method_title );
		$subscription->set_payment_method( $new_payment_method, $new_payment_method_meta );
		$subscription->set_payment_method_title( $new_payment_method_title );

		// Log change on order
		// translators: 1: old payment title, 2: new payment title.
		$subscription->add_order_note( sprintf( _x( 'Payment method changed from "%1$s" to "%2$s" by the subscriber from their account page.', '%1$s: old payment title, %2$s: new payment title', 'subscriptions-recurring-payments-for-woocommerce' ), $old_payment_method_title, $new_payment_method_title ) );

		$subscription->save();

		do_action( 'woocommerce_subscription_payment_method_updated', $subscription, $new_payment_method, $old_payment_method );
		do_action( 'woocommerce_subscription_payment_method_updated_to_' . $new_payment_method, $subscription, $old_payment_method );
		if ( $old_payment_method ) {
			do_action( 'woocommerce_subscription_payment_method_updated_from_' . $old_payment_method, $subscription, $new_payment_method );
		}
	}

	/**
	 * Only display gateways which support changing payment method when paying for a failed renewal order or
	 * when requesting to change the payment method.
	 *
	 * @param array $available_gateways The payment gateways which are currently being allowed.
	 
	 */
	public static function get_available_payment_gateways( $available_gateways ) {
		$is_change_payment_method_request = isset( $_GET['change_payment_method'] );

		// If we're on a order-pay page but not changing a subscription's payment method, exit early - we don't want to filter the available payment gateways while the customer pays for an order.
		if ( ! $is_change_payment_method_request && is_wc_endpoint_url( 'order-pay' ) ) {
			return $available_gateways;
		}

		$renewal_order_cart_item             = awc_cart_contains_failed_renewal_order_payment();
		$cart_contains_failed_renewal        = (bool) $renewal_order_cart_item;
		$cart_contains_failed_manual_renewal = false;

		/**
		 * If there's no change payment request and the cart contains a failed renewal order, check if the subscription is manual.
		 *
		 * We update failing, non-manual subscriptions in @see WC_Subscriptions_Change_Payment_Gateway::change_failing_payment_method() so we
		 * don't need to apply our available payment gateways filter if the subscription is manual.
		 */
		if ( ! $is_change_payment_method_request && $cart_contains_failed_renewal ) {
			$subscription = awc_get_subscription( $renewal_order_cart_item['subscription_renewal']['subscription_id'] );
			$cart_contains_failed_manual_renewal = $subscription->is_manual();
		}

		if ( apply_filters( 'awc_payment_gateways_change_payment_method', $is_change_payment_method_request || ( $cart_contains_failed_renewal && ! $cart_contains_failed_manual_renewal ) ) ) {
			foreach ( $available_gateways as $gateway_id => $gateway ) {
				if ( true !== $gateway->supports( 'subscription_payment_method_change_customer' ) ) {
					unset( $available_gateways[ $gateway_id ] );
				}
			}
		}

		return $available_gateways;
	}

	/**
	 * Make sure certain totals are set to 0 when the request is to change the payment method without charging anything.
	 *
	 
	 */
	public static function maybe_zero_total( $total, $subscription ) {
		global $wp;

		if ( ! empty( $_POST['_asubnonce'] ) && wp_verify_nonce( $_POST['_asubnonce'], 'awc_change_payment_method' ) && isset( $_POST['woocommerce_change_payment'] ) && awc_is_subscription( $subscription ) && $subscription->get_order_key() == $_GET['key'] && $subscription->get_id() == absint( $_POST['woocommerce_change_payment'] ) ) {
			$total = 0;
		} elseif ( ! self::$is_request_to_change_payment && isset( $wp->query_vars['order-pay'] ) && awc_is_subscription( absint( $wp->query_vars['order-pay'] ) ) ) {
			// if the request to pay for the order belongs to a subscription but there's no GET params for changing payment method, the receipt page is being used to collect credit card details so we still need to $0 the total
			$total = 0;
		}

		return $total;
	}

	/**
	 * Redirect back to the "My Account" page instead of the "Thank You" page after changing the payment method.
	 *
	 
	 */
	public static function get_return_url( $return_url ) {

		if ( ! empty( $_POST['_asubnonce'] ) && wp_verify_nonce( $_POST['_asubnonce'], 'awc_change_payment_method' ) && isset( $_POST['woocommerce_change_payment'] ) ) {
			$return_url = get_permalink( wc_get_page_id( 'myaccount' ) );
		}

		return $return_url;
	}

	/**
	 * Update the recurring payment method for a subscription after a customer has paid for a failed renewal order
	 * (which usually failed because of an issue with the existing payment, like an expired card or token).
	 *
	 * Also trigger a hook for payment gateways to update any meta on the original order for a subscription.
	 *
	 * @param WC_Order $renewal_order The order which recorded the successful payment (to make up for the failed automatic payment).
	 * @param WC_Order $original_order The original order in which the subscription was purchased.
	 
	 */
	public static function change_failing_payment_method( $renewal_order, $subscription ) {

		if ( ! $subscription->is_manual() ) {

			if ( ! empty( $_POST['_asubnonce'] ) && wp_verify_nonce( $_POST['_asubnonce'], 'awc_change_payment_method' ) && isset( $_POST['payment_method'] ) ) {
				$new_payment_method = wc_clean( $_POST['payment_method'] );
			} else {
				$new_payment_method = awc_get_objects_property( $renewal_order, 'payment_method' );
			}

			self::update_payment_method( $subscription, $new_payment_method );

			do_action( 'woocommerce_subscription_failing_payment_method_updated', $subscription, $renewal_order );
			do_action( 'woocommerce_subscription_failing_payment_method_updated_' . $new_payment_method, $subscription, $renewal_order );
		}
	}

	/**
	 * Add a 'new-payment-method' handler to the @see AWC_Subscription::can_be_updated_to() function
	 * to determine whether the recurring payment method on a subscription can be changed.
	 *
	 * For the recurring payment method to be changeable, the subscription must be active, have future (automatic) payments
	 * and use a payment gateway which allows the subscription to be cancelled.
	 *
	 * @param bool            $subscription_can_be_changed Flag of whether the subscription can be changed.
	 * @param AWC_Subscription $subscription The subscription to check.
	 * @return bool Flag indicating whether the subscription payment method can be updated.
	 
	 */
	public static function can_subscription_be_updated_to_new_payment_method( $subscription_can_be_changed, $subscription ) {

		// Don't allow if automatic payments are disabled and the toggle is also disabled.
		if ( AWC_Settings::get_option('turn_of_automatic_payment') && ! AWC_My_Account_Auto_Renew_Toggle::is_enabled() ) {
			return false;
		}

		// If there's no recurring payment, there's no need to add or update the payment method. Use the 'edit' context so we check the unfiltered total.
		if ( $subscription->get_total( 'edit' ) == 0 ) {
			return false;
		}

		// Don't allow if no gateways support changing methods.
		if ( ! AWC_Subscription_Payment_Gateways::one_gateway_supports( 'subscription_payment_method_change_customer' ) ) {
			return false;
		}

		// Don't allow if there are no remaining payments or the subscription is not active.
		if ( $subscription->get_time( 'next_payment' ) <= 0 || ! $subscription->has_status( 'active' ) ) {
			return false;
		}

		// Don't allow on subscription that doesn't support changing methods.
		if ( ! $subscription->payment_method_supports( 'subscription_cancellation' ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Replace a page title with the endpoint title
	 *
	 * @param  string $title
	 * @return string
	 
	 */
	public static function change_payment_method_page_title( $title ) {

		global $wp;

		// Skip if not on checkout pay page or not a payment change request.
		if ( ! self::$is_request_to_change_payment || ! is_main_query() || ! in_the_loop() || ! is_page() || ! is_checkout_pay_page() ) {
			return $title;
		}

		$subscription = awc_get_subscription( absint( $wp->query_vars['order-pay'] ) );
		if ( ! $subscription ) {
			return $title;
		}

		if ( $subscription->has_payment_gateway() ) {
			$title = _x( 'Change payment method', 'the page title of the change payment method form', 'subscriptions-recurring-payments-for-woocommerce' );
		} else {
			$title = _x( 'Add payment method', 'the page title of the add payment method form', 'subscriptions-recurring-payments-for-woocommerce' );
		}

		return $title;
	}

	/**
	 * Replace the breadcrumbs structure to add a link to the subscription page and change the current page to "Change Payment Method"
	 *
	 * @param  array $crumbs
	 * @return array
	 
	 */
	public static function change_payment_method_breadcrumb( $crumbs ) {

		if ( is_main_query() && is_page() && is_checkout_pay_page() && self::$is_request_to_change_payment ) {
			global $wp_query;
			$subscription = awc_get_subscription( absint( $wp_query->query_vars['order-pay'] ) );

			if ( ! $subscription ) {
				return $crumbs;
			}

			$crumbs[1] = array(
				get_the_title( wc_get_page_id( 'myaccount' ) ),
				get_permalink( wc_get_page_id( 'myaccount' ) ),
			);

			$crumbs[2] = array(
				// translators: %s: order number.
				sprintf( _x( 'Subscription #%s', 'hash before order number', 'subscriptions-recurring-payments-for-woocommerce' ), $subscription->get_order_number() ),
				esc_url( $subscription->get_view_order_url() ),
			);

			if ( $subscription->has_payment_gateway() ) {
				$crumbs[3] = array(
					_x( 'Change payment method', 'the page title of the change payment method form', 'subscriptions-recurring-payments-for-woocommerce' ),
					'',
				);
			} else {
				$crumbs[3] = array(
					_x( 'Add payment method', 'the page title of the add payment method form', 'subscriptions-recurring-payments-for-woocommerce' ),
					'',
				);
			}
		}

		return $crumbs;
	}

	/**
	 * When processing a change_payment_method request on a subscription that has a failed or pending renewal,
	 * we don't want the `$order->needs_payment()` check inside WC_Shortcode_Checkout::order_pay() to pass.
	 * This is causing `$gateway->payment_fields()` to be called multiple times.
	 *
	 * @param bool $needs_payment
	 * @param WC_Subscription $subscription
	 * @return bool
	 .7
	 */
	public static function maybe_override_needs_payment( $needs_payment ) {

		if ( $needs_payment && self::$is_request_to_change_payment ) {
			$needs_payment = false;
		}

		return $needs_payment;
	}

	/**
	 * Display a login form on the change payment method page if the customer isn't logged in.
	 *
	 * @param string $content The default HTML page content.
	 * @return string $content.
	 
	 */
	public static function maybe_request_log_in( $content ) {
		global $wp;

		if ( ! self::$is_request_to_change_payment || is_user_logged_in() || ! isset( $wp->query_vars['order-pay'] ) ) {
			return $content;
		}

		$subscription = awc_get_subscription( absint( $wp->query_vars['order-pay'] ) );

		if ( $subscription ) {
			wc_add_notice( __( 'Please log in to your account below to choose a new payment method for your subscription.', 'subscriptions-recurring-payments-for-woocommerce' ), 'notice' );

			ob_start();
			woocommerce_login_form( array(
				'redirect' => $subscription->get_change_payment_method_url(),
				'message'  => wc_print_notices( true ),
			) );

			$content = ob_get_clean();
		}

		return $content;
	}

	/** Deprecated Functions **/

	/**
	 * Update the recurring payment method on a subscription order.
	 *
	 * @param array $available_gateways The payment gateways which are currently being allowed.
	 
	 * 
	 */
	public static function update_recurring_payment_method( $subscription_key, $order, $new_payment_method ) {
		_deprecated_function( __METHOD__, '2.0', __CLASS__ . '::update_payment_method()' );
		self::update_payment_method( awc_get_subscription_from_key( $subscription_key ), $new_payment_method );
	}

	/**
	 * Keep a record of an order's dates if we're marking it as completed during a request to change the payment method.
	 *
	 * Deprecated as we now operate on a WC_Subscription object instead of the parent order, so we don't need to hack around date changes.
	 *
	 
	 * 
	 */
	public static function store_original_order_dates( $new_order_status, $subscription_id ) {
		
	}

	/**
	 * Restore an order's dates if we marked it as completed during a request to change the payment method.
	 *
	 * Deprecated as we now operate on a AWC_Subscription object instead of the parent order, so we don't need to hack around date changes.
	 *
	 
	 * 
	 */
	public static function restore_original_order_dates( $order_id ) {
		
	}

	/**
	 * Add a 'new-payment-method' handler to the @see AWC_Subscription::can_be_updated_to() function
	 * to determine whether the recurring payment method on a subscription can be changed.
	 *
	 * For the recurring payment method to be changeable, the subscription must be active, have future (automatic) payments
	 * and use a payment gateway which allows the subscription to be cancelled.
	 *
	 * @param bool $subscription_can_be_changed Flag of whether the subscription can be changed to
	 * @param string $new_status_or_meta The status or meta data you want to change th subscription to. Can be 'active', 'on-hold', 'cancelled', 'expired', 'trash', 'deleted', 'failed', 'new-payment-date' or some other value attached to the 'woocommerce_can_subscription_be_changed_to' filter.
	 * @param object $args Set of values used in @see AWC_Subscription_Manager::can_subscription_be_changed_to() for determining if a subscription can be changes, include:
	 *    'subscription_key'           string A subscription key of the form created by @see AWC_Subscription_Manager::get_subscription_key()
	 *    'subscription'               array Subscription of the form returned by @see AWC_Subscription_Manager::get_subscription()
	 *    'user_id'                    int The ID of the subscriber.
	 *    'order'                      WC_Order The order which recorded the successful payment (to make up for the failed automatic payment).
	 *    'payment_gateway'            WC_Payment_Gateway The subscription's recurring payment gateway
	 *    'order_uses_manual_payments' bool A boolean flag indicating whether the subscription requires manual renewal payment.
	 
	 */
	public static function can_subscription_be_changed_to( $subscription_can_be_changed, $new_status_or_meta, $args ) {
		_deprecated_function( __METHOD__, '2.0', __CLASS__ . '::can_subscription_be_updated_to_new_payment_method()' );

		if ( 'new-payment-method' === $new_status_or_meta ) {
			$subscription_can_be_changed = awc_get_subscription_from_key( $args->subscription_key )->can_be_updated_to( 'new-payment-method' );
		}

		return $subscription_can_be_changed;
	}
}
