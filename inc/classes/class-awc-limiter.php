<?php
/**
 * A class to make it possible to limit a subscription product.
 *
 * @package WooCommerce Subscriptions
 * @category Class
 
 */
class AWC_Limiter {

	/* cache whether a given product is purchasable or not to save running lots of queries for the same product in the same request */
	protected static $is_purchasable_cache = array();

	/* cache the check on whether the session has an order awaiting payment for a given product */
	protected static $order_awaiting_payment_for_product = array();


	public static function init() {

		// Add limiting subscriptions options on edit product page
		add_action( 'woocommerce_product_options_advanced', __CLASS__ . '::admin_edit_product_fields' );

		// Only attach limited subscription purchasability logic on the front end.
		if ( awc_is_frontend_request() ) {
			add_filter( 'woocommerce_subscription_is_purchasable', __CLASS__ . '::is_purchasable_switch', 12, 2 );
			add_filter( 'woocommerce_subscription_variation_is_purchasable', __CLASS__ . '::is_purchasable_switch', 12, 2 );
			add_filter( 'woocommerce_subscription_is_purchasable', __CLASS__ . '::is_purchasable_renewal', 12, 2 );
			add_filter( 'woocommerce_subscription_variation_is_purchasable', __CLASS__ . '::is_purchasable_renewal', 12, 2 );
			add_filter( 'woocommerce_valid_order_statuses_for_order_again', array( __CLASS__, 'filter_order_again_statuses_for_limited_subscriptions' ) );
		}
	}


	/**
	 * Filters the order statuses that enable the order again button and functionality.
	 *
	 * This function will return no statuses if the order contains non purchasable or limited products.
	 *
	 *
	 * @param array $statuses The order statuses that enable the order again button.
	 * @return array $statuses An empty array if the order contains limited products, otherwise the default statuses are returned.
	 */
	public static function filter_order_again_statuses_for_limited_subscriptions( $statuses ) {
		global $wp;

		if ( is_view_order_page() ) {
			$order = wc_get_order( absint( $wp->query_vars['view-order'] ) );
		} elseif ( is_order_received_page() ) {
			$order = wc_get_order( absint( $wp->query_vars['order-received'] ) );
		}

		if ( empty( $order ) ) {
			return $statuses;
		}

		$is_purchasable = true;

		foreach ( $order->get_items() as $line_item ) {
			$product = $line_item->get_product();

			if ( AWC_Subscription_Products::awc_is_subscription( $product ) && awc_is_product_limited_for_user( $product ) ) {
				$is_purchasable = false;
				break;
			}
		}

		// If all products are purchasable, return the default statuses, otherwise return no statuses.
		if ( $is_purchasable ) {
			return $statuses;
		} else {
			return array();
		}
	}

	/**
	 * Determines whether a product is purchasable based on whether the cart is to resubscribe or renew.
	 *
	  Combines AWC_Cart_Renewal::is_purchasable and AWC_Cart_Resubscribe::is_purchasable
	 * @return bool
	 */
	public static function is_purchasable_renewal( $is_purchasable, $product ) {
		if ( false === $is_purchasable && false === self::is_purchasable_product( $is_purchasable, $product ) ) {

			// Resubscribe logic
			if ( isset( $_GET['resubscribe'] ) || false !== ( $resubscribe_cart_item = awc_cart_contains_resubscribe() ) ) {
				$subscription_id       = ( isset( $_GET['resubscribe'] ) ) ? absint( $_GET['resubscribe'] ) : $resubscribe_cart_item['subscription_resubscribe']['subscription_id'];
				$subscription          = awc_get_subscription( $subscription_id );

				if ( false != $subscription && $subscription->has_product( $product->get_id() ) && awc_can_user_resubscribe_to( $subscription ) ) {
					$is_purchasable = true;
				}

			// Renewal logic
			} elseif ( isset( $_GET['subscription_renewal'] ) || awc_cart_contains_renewal() ) {
				$is_purchasable = true;

			// Restoring cart from session, so need to check the cart in the session (awc_cart_contains_renewal() only checks the cart)
			} elseif ( ! empty( WC()->session->cart ) ) {
				foreach ( WC()->session->cart as $cart_item_key => $cart_item ) {
					if ( $product->get_id() == $cart_item['product_id'] && ( isset( $cart_item['subscription_renewal'] ) || isset( $cart_item['subscription_resubscribe'] ) ) ) {
						$is_purchasable = true;
						break;
					}
				}
			}
		}
		return $is_purchasable;
	}

	

	/**
	 * If a product is being marked as not purchasable because it is limited and the customer has a subscription,
	 * but the current request is to switch the subscription, then mark it as purchasable.
	 *
	 * @return bool
	 */
	public static function is_purchasable_switch( $is_purchasable, $product ) {
		$product_key = awc_get_canonical_product_id( $product );

		// Set an empty cache if one isn't set yet.
		if ( ! isset( self::$is_purchasable_cache[ $product_key ] ) ) {
			self::$is_purchasable_cache[ $product_key ] = array();
		}

		// Exit early if we've already determined this product's purchasability via switching.
		if ( isset( self::$is_purchasable_cache[ $product_key ]['switch'] ) ) {
			return self::$is_purchasable_cache[ $product_key ]['switch'];
		}

		if ( true === $is_purchasable || ! is_user_logged_in() || ! awc_is_product_switchable_type( $product ) || ! AWC_Subscription_Products::awc_is_subscription( $product->get_id() ) ) {
			self::$is_purchasable_cache[ $product_key ]['switch'] = $is_purchasable;
			return self::$is_purchasable_cache[ $product_key ]['switch'];
		}

		$user_id            = get_current_user_id();
		$product_limitation = awc_get_product_limitation( $product );

		if ( 'no' == $product_limitation || ! awc_user_has_subscription( $user_id, $product->get_id(), awc_get_product_limitation( $product ) ) ) {
			self::$is_purchasable_cache[ $product_key ]['switch'] = $is_purchasable;
			return self::$is_purchasable_cache[ $product_key ]['switch'];
		}

		// Limited products are only purchasable while switching the subscription which contains that product so we need the customer's subscriptions to this product.
		$subscriptions = awc_get_subscriptions( array(
			'customer_id' => $user_id,
			'status'      => $product_limitation,
			'product_id'  => $product->get_id(),
		) );

		// Adding to cart
		if ( isset( $_GET['switch-subscription'] ) && array_key_exists( $_GET['switch-subscription'], $subscriptions ) ) {
			$is_purchasable = true;
		} else {
			// If we have a variation product get the variable product's ID. We can't use the variation ID for comparison because this function sometimes receives a variable product.
			$product_id    = $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id();
			$cart_contents = array();

			// Use the version of the cart we have access to. We may need to look for switches in the cart being loaded from the session.
			if ( AWC_Subscription_Switchers::cart_contains_switches() ) {
				$cart_contents = WC()->cart->cart_contents;
			} elseif ( isset( WC()->session->cart ) ) {
				$cart_contents = WC()->session->cart;
			}

			// Check if the cart contains a switch for this specific product.
			foreach ( $cart_contents as $cart_item ) {
				if ( $product_id === $cart_item['product_id'] && isset( $cart_item['subscription_switch']['subscription_id'] ) && array_key_exists( $cart_item['subscription_switch']['subscription_id'], $subscriptions ) ) {
					$is_purchasable = true;
					break;
				}
			}
		}

		self::$is_purchasable_cache[ $product_key ]['switch'] = $is_purchasable;
		return self::$is_purchasable_cache[ $product_key ]['switch'];
	}



	/**
	 * If a product is limited and the customer already has a subscription, mark it as not purchasable.
	 *
	  Moved from AWC_Subscription_Products
	 * @return bool
	 */
	public static function is_purchasable_product( $is_purchasable, $product ) {

		//Set up cache
		if ( ! isset( self::$is_purchasable_cache[ $product->get_id() ] ) ) {
			self::$is_purchasable_cache[ $product->get_id() ] = array();
		}

		if ( ! isset( self::$is_purchasable_cache[ $product->get_id() ]['standard'] ) ) {
			self::$is_purchasable_cache[ $product->get_id() ]['standard'] = $is_purchasable;

			if ( AWC_Subscription_Products::awc_is_subscription( $product->get_id() ) && 'no' != awc_get_product_limitation( $product ) && ! awc_is_order_received_page() && ! awc_is_paypal_api_page() ) {

				if ( awc_is_product_limited_for_user( $product ) && ! self::order_awaiting_payment_for_product( $product->get_id() ) ) {
					self::$is_purchasable_cache[ $product->get_id() ]['standard'] = false;
				}
			}
		}
		return self::$is_purchasable_cache[ $product->get_id() ]['standard'];

	}


    /**
	 * Canonical is_purchasable method to be called by product classes.
	 *
	 
	 * @param bool $purchasable Whether the product is purchasable as determined by parent class
	 * @param mixed $product The product in question to be checked if it is purchasable.
	 *
	 * @return bool
	 */
	public static function is_purchasable( $purchasable, $product ) {
		switch ( $product->get_type() ) {
			case 'subscription':
			case 'variable-subscription':
				if ( true === $purchasable && false === self::is_purchasable_product( $purchasable, $product ) ) {
					$purchasable = false;
				}
				break;
			case 'subscription_variation':
				$variable_product = wc_get_product( $product->get_parent_id() );

				if ( 'no' != awc_get_product_limitation( $variable_product ) && ! empty( WC()->cart->cart_contents ) && ! awc_is_order_received_page() && ! awc_is_paypal_api_page() ) {

					// When mixed checkout is disabled, the variation is replaceable
					if ( !AWC_Settings::get_option('mixed_checkout') ) {
						$purchasable = true;
					} else { // When mixed checkout is enabled
						foreach ( WC()->cart->cart_contents as $cart_item ) {
							// If the variable product is limited, it can't be purchased if it is the same variation
							if ( $product->get_parent_id() == awc_get_objects_property( $cart_item['data'], 'parent_id' ) && $product->get_id() != $cart_item['data']->get_id() ) {
								$purchasable = false;
								break;
							}
						}
					}
				}
				break;
		}
		return $purchasable;
	}


	/**
	 * Adds limit options to 'Edit Product' screen.
	 *
	  Moved from AWC_Subscriptions_Admin
	 */
	public static function admin_edit_product_fields() {
		global $post;

		echo '<div class="options_group limit_subscription show_if_subscription show_if_variable-subscription omar15 hidden">';

		// Only one Subscription per customer
		woocommerce_wp_select(
			array(
				'id'          => '_subscription_limit',
				'label'       => __( 'Limit subscription', 'subscriptions-recurring-payments-for-woocommerce' ),
				// translators: placeholders are opening and closing link tags
				'description' => __( 'Only allow a customer to have one subscription to this product.', 'subscriptions-recurring-payments-for-woocommerce' ),
				'options'     => array(
					'no'     => __( 'Do not limit', 'subscriptions-recurring-payments-for-woocommerce' ),
					'active' => __( 'Limit to one active subscription', 'subscriptions-recurring-payments-for-woocommerce' ),
					'any'    => __( 'Limit to one of any status', 'subscriptions-recurring-payments-for-woocommerce' ),
				),
			)
		);
		echo '</div>';

		do_action( 'woocommerce_subscriptions_product_options_advanced' );
	}

}