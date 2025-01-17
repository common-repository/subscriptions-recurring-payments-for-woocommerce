<?php
/**
 * Implement resubscribing to a subscription via the cart.
 *
 * Resubscribing is a similar process to renewal via checkout (which is why this class extends AWC_Cart_Renewal), only it:
 * - creates a new subscription with similar terms to the existing subscription, where as a renewal resumes the existing subscription
 * - is for an expired or cancelled subscription only.
 *
 * @package WooCommerce Subscriptions
 * @subpackage AWC_Cart_Resubscribe
 * @category Class
 * 
 
 */

class AWC_Cart_Resubscribe extends AWC_Cart_Renewal {

	/* The flag used to indicate if a cart item is a renewal */
	public $cart_item_key = 'subscription_resubscribe';

	/**
	 * Bootstraps the class and hooks required actions & filters.
	 *
	 
	 */
	public function __construct() {

		$this->setup_hooks();

		// When a resubscribe order is created on checkout, record the resubscribe, attached after AWC_Subscriptions_Checkout::process_checkout()
		add_action( 'woocommerce_checkout_subscription_created', array( &$this, 'maybe_record_resubscribe' ), 10, 3 );

		add_filter( 'woocommerce_subscriptions_recurring_cart_key', array( &$this, 'get_recurring_cart_key' ), 10, 2 );

		add_filter( 'awc_recurring_cart_next_payment_date', array( &$this, 'recurring_cart_next_payment_date' ), 100, 2 );

		// Mock a free trial on the cart item to make sure the resubscribe total doesn't include any recurring amount when honoring prepaid term
		add_filter( 'woocommerce_before_calculate_totals', array( &$this, 'maybe_set_free_trial' ), 100, 1 );
		add_action( 'woocommerce_subscription_cart_before_grouping', array( &$this, 'maybe_unset_free_trial' ) );
		add_action( 'woocommerce_subscription_cart_after_grouping', array( &$this, 'maybe_set_free_trial' ) );
		add_action( 'awc_recurring_cart_start_date', array( &$this, 'maybe_unset_free_trial' ), 0, 1 );
		add_action( 'awc_recurring_cart_end_date', array( &$this, 'maybe_set_free_trial' ), 100, 1 );
		add_filter( 'woocommerce_subscriptions_calculated_total', array( &$this, 'maybe_unset_free_trial' ), 10000, 1 );
		add_action( 'woocommerce_cart_totals_before_shipping', array( &$this, 'maybe_set_free_trial' ) );
		add_action( 'woocommerce_cart_totals_after_shipping', array( &$this, 'maybe_unset_free_trial' ) );
		add_action( 'woocommerce_review_order_before_shipping', array( &$this, 'maybe_set_free_trial' ) );
		add_action( 'woocommerce_review_order_after_shipping', array( &$this, 'maybe_unset_free_trial' ) );

		add_action( 'woocommerce_order_status_changed', array( &$this, 'maybe_cancel_existing_subscription' ), 10, 3 );

		add_filter( 'wc_dynamic_pricing_apply_cart_item_adjustment', array( &$this, 'prevent_compounding_dynamic_discounts' ), 10, 2 );
	}

	/**
	 * Checks if the current request is by a user to resubcribe to a subscription, and if it is setup a
	 * subscription resubcribe process via the cart for the product/variation/s that are being renewed.
	 *
	 
	 */
	public function maybe_setup_cart() {
		global $wp;

		if ( isset( $_GET['resubscribe'] ) && isset( $_GET['_wpnonce'] ) ) {

			$subscription = awc_get_subscription( $_GET['resubscribe'] );
			$redirect_to  = get_permalink( wc_get_page_id( 'myaccount' ) );

			if ( wp_verify_nonce( $_GET['_wpnonce'], $subscription->get_id() ) === false ) {

				wc_add_notice( __( 'There was an error with your request to resubscribe. Please try again.', 'subscriptions-recurring-payments-for-woocommerce' ), 'error' );

			} elseif ( empty( $subscription ) ) {

				wc_add_notice( __( 'That subscription does not exist. Has it been deleted?', 'subscriptions-recurring-payments-for-woocommerce' ), 'error' );

			} elseif ( ! current_user_can( 'subscribe_again', $subscription->get_id() ) ) {

				wc_add_notice( __( 'That doesn\'t appear to be one of your subscriptions.', 'subscriptions-recurring-payments-for-woocommerce' ), 'error' );

			} elseif ( ! awc_can_user_resubscribe_to( $subscription ) ) {

				wc_add_notice( __( 'You can not resubscribe to that subscription. Please contact us if you need assistance.', 'subscriptions-recurring-payments-for-woocommerce' ), 'error' );

			} else {

				$this->setup_cart( $subscription, array(
					'subscription_id' => $subscription->get_id(),
				), 'all_items_required' );

				if ( WC()->cart->get_cart_contents_count() != 0 ) {
					wc_add_notice( __( 'Complete checkout to resubscribe.', 'subscriptions-recurring-payments-for-woocommerce' ), 'success' );
				}

				$redirect_to = wc_get_checkout_url();
			}

			wp_safe_redirect( $redirect_to );
			exit;

		} elseif ( isset( $_GET['pay_for_order'] ) && isset( $_GET['key'] ) && isset( $wp->query_vars['order-pay'] ) ) {

			$order_id     = ( isset( $wp->query_vars['order-pay'] ) ) ? $wp->query_vars['order-pay'] : absint( $_GET['order_id'] );
			$order        = wc_get_order( $wp->query_vars['order-pay'] );
			$order_key    = $_GET['key'];

			if ( awc_get_objects_property( $order, 'order_key' ) == $order_key && $order->has_status( array( 'pending', 'failed' ) ) && awc_order_contains_resubscribe( $order ) ) {

				if ( ! is_user_logged_in() ) {

					$redirect = add_query_arg( array(
						'awc_redirect'    => 'pay_for_order',
						'awc_redirect_id' => $order_id,
					), get_permalink( wc_get_page_id( 'myaccount' ) ) );

					wp_safe_redirect( $redirect );
					exit;
				}

				wc_add_notice( __( 'Complete checkout to resubscribe.', 'subscriptions-recurring-payments-for-woocommerce' ), 'success' );

				$subscriptions = awc_get_subscriptions_for_resubscribe_order( $order );

				foreach ( $subscriptions as $subscription ) {
					if ( current_user_can( 'subscribe_again', $subscription->get_id() ) ) {
						$this->setup_cart( $subscription, array(
							'subscription_id' => $subscription->get_id(),
						), 'all_items_required' );
					} else {
						wc_add_notice( __( 'That doesn\'t appear to be one of your subscriptions.', 'subscriptions-recurring-payments-for-woocommerce' ), 'error' );
						wp_safe_redirect( get_permalink( wc_get_page_id( 'myaccount' ) ) );
						exit;
					}
				}

				wp_safe_redirect( wc_get_checkout_url() );
				exit;
			}
		}
	}

	/**
	 * When creating an order at checkout, if the checkout is to resubscribe to an expired or cancelled
	 * subscription, make sure we record that on the order and new subscription.
	 *
	 
	 */
	public function maybe_record_resubscribe( $new_subscription, $order, $recurring_cart ) {

		$cart_item = $this->cart_contains( $recurring_cart );

		if ( false !== $cart_item ) {
			$old_subscription = awc_get_subscription( $cart_item[ $this->cart_item_key ]['subscription_id'] );
			awc_Related_Order_Store::instance()->add_relation( $order, $old_subscription, 'resubscribe' );
			awc_Related_Order_Store::instance()->add_relation( $new_subscription, $old_subscription, 'resubscribe' );
		}
	}

	/**
	 * Restore renewal flag when cart is reset and modify Product object with renewal order related info
	 *
	 
	 */
	public function get_cart_item_from_session( $cart_item_session_data, $cart_item, $key ) {
		if ( isset( $cart_item[ $this->cart_item_key ]['subscription_id'] ) ) {

			// Setup the cart as if it's a renewal (as the setup process is almost the same)
			$cart_item_session_data = parent::get_cart_item_from_session( $cart_item_session_data, $cart_item, $key );

			// Need to get the original subscription price, not the current price
			$subscription = awc_get_subscription( $cart_item[ $this->cart_item_key ]['subscription_id'] );
			if ( $subscription ) {
				// Make sure the original subscription terms perisist
				$_product = $cart_item_session_data['data'];
				awc_set_objects_property( $_product, 'subscription_period', $subscription->get_billing_period(), 'set_prop_only' );
				awc_set_objects_property( $_product, 'subscription_period_interval', $subscription->get_billing_interval(), 'set_prop_only' );

				// And don't give another free trial period
				awc_set_objects_property( $_product, 'subscription_trial_length', 0, 'set_prop_only' );
			}
		}

		return $cart_item_session_data;
	}

	/**
	 * If a product is being marked as not purchasable because it is limited and the customer has a subscription,
	 * but the current request is to resubscribe to the subscription, then mark it as purchasable.
	 *
	 
	 * @return bool
	 */
	public function is_purchasable( $is_purchasable, $product ) {
		return awc_Limiter::is_purchasable_renewal( $is_purchasable, $product );

	}

	/**
	 * Checks the cart to see if it contains a subscription resubscribe item.
	 *
	 * @see awc_cart_contains_resubscribe()
	 * @param WC_Cart $cart The cart object to search in.
	 * @return bool | Array The cart item containing the renewal, else false.
	 
	 */
	protected function cart_contains( $cart = '' ) {
		return awc_cart_contains_resubscribe( $cart );
	}

	/**
	 * Get the subscription object used to construct the resubscribe cart.
	 *
	 * @param Array The resubscribe cart item.
	 * @return WC_Subscription | The subscription object.
	 
	 */
	protected function get_order( $cart_item = '' ) {
		$subscription = false;

		if ( empty( $cart_item ) ) {
			$cart_item = $this->cart_contains();
		}

		if ( false !== $cart_item && isset( $cart_item[ $this->cart_item_key ] ) ) {
			$subscription = awc_get_subscription( $cart_item[ $this->cart_item_key ]['subscription_id'] );
		}

		return $subscription;
	}

	/**
	 * Make sure that a resubscribe item's cart key is based on the end of the pre-paid term if the user already has a subscription that is pending-cancel, not the date calculated for the product.
	 *
	 
	 */
	public function get_recurring_cart_key( $cart_key, $cart_item ) {
		$subscription = $this->get_order( $cart_item );
		if ( false !== $subscription && $subscription->has_status( 'pending-cancel' ) ) {
			remove_filter( 'woocommerce_subscriptions_recurring_cart_key', array( &$this, 'get_recurring_cart_key' ), 10 );
			$cart_key = AWC_Subscription_Cart::get_recurring_cart_key( $cart_item, $subscription->get_time( 'end' ) );
			add_filter( 'woocommerce_subscriptions_recurring_cart_key', array( &$this, 'get_recurring_cart_key' ), 10, 2 );
		}

		return $cart_key;
	}

	/**
	 * Make sure when displaying the next payment date for a subscription, the date takes into
	 * account the end of the pre-paid term if the user is resubscribing to a subscription that is pending-cancel.
	 *
	 
	 */
	public function recurring_cart_next_payment_date( $first_renewal_date, $cart ) {
		foreach ( $cart->get_cart() as $cart_item_key => $cart_item ) {
			$subscription = $this->get_order( $cart_item );
			if ( false !== $subscription && $subscription->has_status( 'pending-cancel' ) ) {
				$first_renewal_date = ( '1' != AWC_Subscription_Products::awc_get_length( $cart_item['data'] ) ) ? $subscription->get_date( 'end' ) : 0;
				break;
			}
		}
		return $first_renewal_date;
	}

	/**
	 * Make sure resubscribe cart item price doesn't include any recurring amount by setting a free trial.
	 *
	 
	 * @param mixed $total This parameter is unused. Its sole purpose is for returning an unchanged variable while setting the mock trial when hooked onto filters. Optional.
	 * @return mixed $total The unchanged $total parameter.
	 */
	public function maybe_set_free_trial( $total = '' ) {
		$subscription = $this->get_order();

		if ( false !== $subscription && $subscription->has_status( 'pending-cancel' ) ) {
			foreach ( WC()->cart->cart_contents as &$cart_item ) {
				if ( isset( $cart_item[ $this->cart_item_key ] ) ) {
					awc_set_objects_property( $cart_item['data'], 'subscription_trial_length', 1, 'set_prop_only' );
				}
			}
		}

		return $total;
	}

	/**
	 * Remove mock free trials from resubscribe cart items.
	 *
	 
	 * @param mixed $total This parameter is unused. Its sole purpose is for returning an unchanged variable while unsetting the mock trial when hooked onto filters. Optional.
	 * @return mixed $total The unchanged $total parameter.
	 */
	public function maybe_unset_free_trial( $total = '' ) {
		$subscription = $this->get_order();

		if ( false !== $subscription && $subscription->has_status( 'pending-cancel' ) ) {
			foreach ( WC()->cart->cart_contents as &$cart_item ) {
				if ( isset( $cart_item[ $this->cart_item_key ] ) ) {
					awc_set_objects_property( $cart_item['data'], 'subscription_trial_length', 0, 'set_prop_only' );
				}
			}
		}

		return $total;
	}

	/**
	 * When the user resubscribes to a subscription that is pending-cancel, cancel the existing subscription.
	 *
	 
	 */
	public function maybe_cancel_existing_subscription( $order_id, $old_order_status, $new_order_status ) {
		if ( awc_order_contains_subscription( $order_id ) && awc_order_contains_resubscribe( $order_id ) ) {
			$order                = wc_get_order( $order_id );
			$order_completed      = in_array( $new_order_status, array( apply_filters( 'woocommerce_payment_complete_order_status', 'processing', $order_id, $order ), 'processing', 'completed' ) );
			$order_needed_payment = in_array( $old_order_status, apply_filters( 'woocommerce_valid_order_statuses_for_payment', array( 'pending', 'on-hold', 'failed' ), $order ) );

			foreach ( awc_get_subscriptions_for_resubscribe_order( $order_id ) as $subscription ) {
				if ( $subscription->has_status( 'pending-cancel' ) ) {
					// translators: %s: order number.
					$cancel_note = sprintf( __( 'Customer resubscribed in order #%s', 'subscriptions-recurring-payments-for-woocommerce' ), $order->get_order_number() );
					$subscription->update_status( 'cancelled', $cancel_note );
				}
			}
		}
	}
}
