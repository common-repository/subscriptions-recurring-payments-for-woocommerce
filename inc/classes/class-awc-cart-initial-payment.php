<?php
/**
 * Handles the initial payment for a pending subscription via the cart.
 * 
 */

if(!class_exists('AWC_Cart_Initial_Payment')){
	class AWC_Cart_Initial_Payment extends AWC_Cart_Renewal {

		/* The flag used to indicate if a cart item is for a initial payment */
		public $cart_item_key = 'subscription_initial_payment';


		/**
		 * Bootstraps the class and hooks required actions & filters.
		 */
		public function __construct() {
			$this->setup_hooks();

			
			add_filter( 'woocommerce_create_order', array( &$this, 'update_cart_hash' ), 10, 1 );
			add_action( 'woocommerce_setup_cart_for_subscription_initial_payment', array( $this, 'setup_discounts' ) );

			// Initialise the stock mananger.
			AWC_Initial_Cart_Stock_Manager::attach_callbacks();
		}

		/**
		 * Setup the cart for paying for a delayed initial payment for a subscription.
		 * @access	public
		*/
		public function maybe_setup_cart() {
			global $wp;

			if ( ! isset( $_GET['pay_for_order'] ) || ! isset( $_GET['key'] ) || ! isset( $wp->query_vars['order-pay'] ) ) {
				return;
			}

			// Pay for existing order
			$order_key = $_GET['key'];
			$order_id  = absint( $wp->query_vars['order-pay'] );
			$order     = wc_get_order( $order_id );

			if ( awc_get_objects_property( $order, 'order_key' ) !== $order_key || ! $order->has_status( array( 'pending', 'failed' ) ) || ! awc_order_contains_subscription( $order, 'parent' ) || awc_order_contains_subscription( $order, 'resubscribe' ) ) {
				return;
			}

			if ( ! is_user_logged_in() ) {
				// Allow the customer to login first and then redirect them back.
				$redirect = add_query_arg( array(
					'awc_redirect'    => 'pay_for_order',
					'awc_redirect_id' => $order_id,
				), get_permalink( wc_get_page_id( 'myaccount' ) ) );
			} elseif ( ! current_user_can( 'pay_for_order', $order_id ) ) {
				wc_add_notice( __( 'That doesn\'t appear to be your order.', 'subscriptions-recurring-payments-for-woocommerce' ), 'error' );

				$redirect = get_permalink( wc_get_page_id( 'myaccount' ) );
			} else {
				$subscriptions = awc_get_subscriptions_for_order( $order );
				do_action( 'awc_before_parent_order_setup_cart', $subscriptions, $order );

				// Add the existing order items to the cart
				$this->setup_cart( $order, array(
					'order_id' => $order_id,
				) );

				do_action( 'awc_after_parent_order_setup_cart', $subscriptions, $order );

				// Store order's ID in the session so it can be re-used after payment
				WC()->session->set( 'order_awaiting_payment', $order_id );

				$this->set_cart_hash( $order_id );

				$redirect = wc_get_checkout_url();
			}

			if ( ! empty( $redirect ) ) {
				wp_safe_redirect( $redirect );
				exit;
			}
		}


		
		/**
		 * Checks the cart to see if it contains an initial payment item.
		 *
		 * @return bool | Array The cart item containing the initial payment, else false.
		 
		*/
		protected function cart_contains() {

			$contains_initial_payment = false;

			if ( ! empty( WC()->cart->cart_contents ) ) {
				foreach ( WC()->cart->cart_contents as $cart_item ) {
					if ( isset( $cart_item[ $this->cart_item_key ] ) ) {
						$contains_initial_payment = $cart_item;
						break;
					}
				}
			}

			return apply_filters( 'awc_cart_contains_initial_payment', $contains_initial_payment );
		}

		/**
		 * Get the order object used to construct the initial payment cart.
		 *
		 * @param Array The initial payment cart item.
		 * @return WC_Order | The order object
		 
		*/
		protected function get_order( $cart_item = '' ) {
			$order = false;

			if ( empty( $cart_item ) ) {
				$cart_item = $this->cart_contains();
			}

			if ( false !== $cart_item && isset( $cart_item[ $this->cart_item_key ] ) ) {
				$order = wc_get_order( $cart_item[ $this->cart_item_key ]['order_id'] );
			}

			return $order;
		}

	}
}