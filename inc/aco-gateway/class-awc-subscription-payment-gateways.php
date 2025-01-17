<?php
/**
 * Subscriptions Payment Gateways
 *
 * Hooks into the WooCommerce payment gateways class to add subscription specific functionality.
 *
 * @package    WooCommerce Subscriptions
 * @subpackage AWC_Subscription_Payment_Gateways
 * @category   Class
 
 */
class AWC_Subscription_Payment_Gateways {

	protected static $one_gateway_supports = array();

	/**
	 * Bootstraps the class and hooks required actions & filters.
	 *
	 */
	public static function init() {

		add_action( 'init', __CLASS__ . '::paypal_initialation', 5 );

		add_filter( 'woocommerce_available_payment_gateways', __CLASS__ . '::get_available_payment_gateways' );

		add_filter( 'woocommerce_no_available_payment_methods_message', __CLASS__ . '::no_available_payment_methods_message' );

		add_filter( 'woocommerce_payment_gateways_renewal_support_status_html', __CLASS__ . '::payment_gateways_support_tooltip', 11, 2 );

		// Trigger a hook for gateways to charge recurring payments.
		add_action( 'woocommerce_scheduled_subscription_payment', __CLASS__ . '::gateway_scheduled_subscription_payment', 10, 1 );

		// Create a gateway specific hooks for subscription events.
		add_action( 'woocommerce_subscription_status_updated', __CLASS__ . '::trigger_gateway_status_updated_hook', 10, 2 );
	}

	/**
	 * Instantiate our custom PayPal class
	 *
	 */
	public static function paypal_initialation() {		
		AWC_PayPal::init();
	}

	/**
	 * Returns a payment gateway object by gateway's ID, or false if it could not find the gateway.
	 *
	 */
	public static function get_payment_gateway( $gateway_id ) {
		$found_gateway = false;

		if ( WC()->payment_gateways ) {
			foreach ( WC()->payment_gateways->payment_gateways() as $gateway ) {
				if ( $gateway_id == $gateway->id ) {
					$found_gateway = $gateway;
				}
			}
		}

		return $found_gateway;
	}

	/**
	 * Only display the gateways which support subscriptions if manual payments are not allowed.
	 *
	 */
	public static function get_available_payment_gateways( $available_gateways ) {

		// We don't want to filter the available payment methods while the customer is paying for a standard order via the order-pay screen.
		if ( is_wc_endpoint_url( 'order-pay' ) ) {
			return $available_gateways;
		}

		if ( ! AWC_Subscription_Cart::cart_contains_subscription() && ( ! isset( $_GET['order_id'] ) || ! awc_order_contains_subscription( $_GET['order_id'] ) ) ) {
			return $available_gateways;
		}

		$accept_manual_renewals = AWC_Settings::get_option( 'accept_manual_payment' );
		$subscriptions_in_cart  = is_array( WC()->cart->recurring_carts ) ? count( WC()->cart->recurring_carts ) : 0;

		foreach ( $available_gateways as $gateway_id => $gateway ) {

			$supports_subscriptions = $gateway->supports( 'subscriptions' );

			// Remove the payment gateway if there are multiple subscriptions in the cart and this gateway either doesn't support multiple subscriptions or isn't manual (all manual gateways support multiple subscriptions)
			if ( $subscriptions_in_cart > 1 && $gateway->supports( 'multiple_subscriptions' ) !== true && ( $supports_subscriptions || ! $accept_manual_renewals ) ) {
				unset( $available_gateways[ $gateway_id ] );

			// If there is just the one subscription the cart, remove the payment gateway if manual renewals are disabled and this gateway doesn't support automatic payments
			} elseif ( ! $supports_subscriptions && ! $accept_manual_renewals ) {
				unset( $available_gateways[ $gateway_id ] );
			}
		}

		return $available_gateways;
	}

	/**
	 * Helper function to check if at least one payment gateway on the site supports a certain subscription feature.
	 *
	 
	 */
	public static function one_gateway_supports( $supports_flag ) {

		// Only check if we haven't already run the check
		if ( ! isset( self::$one_gateway_supports[ $supports_flag ] ) ) {

			self::$one_gateway_supports[ $supports_flag ] = false;

			foreach ( WC()->payment_gateways->get_available_payment_gateways() as $gateway ) {
				if ( $gateway->supports( $supports_flag ) ) {
					self::$one_gateway_supports[ $supports_flag ] = true;
					break;
				}
			}
		}

		return self::$one_gateway_supports[ $supports_flag ];
	}

	/**
	 * Improve message displayed on checkout when a subscription is in the cart but not gateways support subscriptions.
	 *
	 */
	public static function no_available_payment_methods_message( $no_gateways_message ) {
		if ( AWC_Subscription_Cart::cart_contains_subscription() && !AWC_Settings::get_option( 'accept_manual_payment' ) )
			$no_gateways_message = __( 'Sorry, it seems there are no available payment methods which support subscriptions. Please contact us if you require assistance or wish to make alternate arrangements.', 'subscriptions-recurring-payments-for-woocommerce' );
		

		return $no_gateways_message;
	}




	/**
	 * Fire a gateway specific whenever a subscription's status is changed.
	 */
	public static function trigger_gateway_status_updated_hook( $subscription, $new_status ) {

		if ( $subscription->is_manual() ) {
			return;
		}

		switch ( $new_status ) {
			case 'active':
				$hook_prefix = 'woocommerce_subscription_activated_';
				break;
			case 'on-hold':
				$hook_prefix = 'woocommerce_subscription_on-hold_';
				break;
			case 'pending-cancel':
				$hook_prefix = 'woocommerce_subscription_pending-cancel_';
				break;
			case 'cancelled':
				$hook_prefix = 'woocommerce_subscription_cancelled_';
				break;
			case 'expired':
				$hook_prefix = 'woocommerce_subscription_expired_';
				break;
			default:
				$hook_prefix = apply_filters( 'woocommerce_subscriptions_gateway_status_updated_hook_prefix', 'woocommerce_subscription_status_updated_', $subscription, $new_status );
				break;
		}

		do_action( $hook_prefix . $subscription->get_payment_method(), $subscription );
	}

	/**
	 * Fire a gateway specific hook for when a subscription renewal payment is due.
	 *
	 * @param WC_Order $renewal_order The renewal order to trigger the payment gateway hook for.
	 
	 */
	public static function trigger_gateway_renewal_payment_hook( $renewal_order ) {
		if ( ! empty( $renewal_order ) && $renewal_order->get_total() > 0 && $renewal_order->get_payment_method() ) {

			// Make sure gateways are setup
			WC()->payment_gateways();

			do_action( 'awc_subscription_scheduled_payment_' . $renewal_order->get_payment_method(), $renewal_order->get_total(), $renewal_order );
		}
	}

	/**
	 * Fire a gateway specific hook for when a subscription payment is due.
	 */
	public static function gateway_scheduled_subscription_payment( $subscription_id, $deprecated = null ) {

		if ( null != $deprecated ) {
			$subscription = awc_get_subscription_from_key( $deprecated );
		} elseif ( ! is_object( $subscription_id ) ) {
			$subscription = awc_get_subscription( $subscription_id );
		} else {
			// Support receiving a full subscription object for unit testing
			$subscription = $subscription_id;
		}

		if ( false === $subscription ) {
			// translators: %d: subscription ID.
			throw new InvalidArgumentException( sprintf( __( 'Subscription doesn\'t exist in scheduled action: %d', 'subscriptions-recurring-payments-for-woocommerce' ), $subscription_id ) );
		}

		if ( ! $subscription->is_manual() && ! $subscription->has_status( awc_get_subscription_ended_statuses() ) ) {
			self::trigger_gateway_renewal_payment_hook( $subscription->get_last_order( 'all', 'renewal' ) );
		}
	}

	/**
	 * Display a list of each gateway supported features in a tooltip
	 *
	 
	 */
	public static function payment_gateways_support_tooltip( $status_html, $gateway ) {

		if ( ( ! is_array( $gateway->supports ) || ! in_array( 'subscriptions', $gateway->supports ) ) && 'paypal' !== $gateway->id ) {
			return $status_html;
		}


		
		$core_features         = (array) apply_filters( 'woocommerce_subscriptions_payment_gateway_features_list', $gateway->supports, $gateway );
		$subscription_features = $change_payment_method_features = array();

		foreach ( $core_features as $key => $feature ) {

			// Skip any non-subscription related features.
			if ( 'gateway_scheduled_payments' !== $feature && false === strpos( $feature, 'subscription' ) ) {
				continue;
			}

			$feature = str_replace( 'subscription_', '', $feature );

			if ( 0 === strpos( $feature, 'payment_method' ) ) {
				switch ( $feature ) {
					case 'payment_method_change':
						$change_payment_method_features[] = 'payment method change';
						break;

					case 'payment_method_change_customer':
						$change_payment_method_features[] = 'customer change payment';
						break;

					case 'payment_method_change_admin':
						$change_payment_method_features[] = 'admin change payment';
						break;

					default:
						$change_payment_method_features[] = str_replace( 'payment_method', ' ', $feature );
						break;
				}
			} else {
				$subscription_features[] = $feature;
			}

			unset( $core_features[ $key ] );
		}

		
		$status_html .= '<span class="payment-method-features-info tips" data-tip="';
		$status_html .= esc_attr( '<strong><u>' . __( 'Supported features:', 'subscriptions-recurring-payments-for-woocommerce' ) . '</u></strong></br>' . implode( '<br />', str_replace( '_', ' ', $core_features ) ) );

		if ( ! empty( $subscription_features ) ) {
			$status_html .= esc_attr( '</br><strong><u>' . __( 'Subscription features:', 'subscriptions-recurring-payments-for-woocommerce' ) . '</u></strong></br>' . implode( '<br />', str_replace( '_', ' ', $subscription_features ) ) );
		}

		if ( ! empty( $change_payment_method_features ) ) {
			$status_html .= esc_attr( '</br><strong><u>' . __( 'Change payment features:', 'subscriptions-recurring-payments-for-woocommerce' ) . '</u></strong></br>' . implode( '<br />', str_replace( '_', ' ', $change_payment_method_features ) ) );
		}

		$status_html .= '"></span>';

		$allowed_html = wp_kses_allowed_html( 'post' );
		$allowed_html['span']['data-tip'] = true;

		return wp_kses( $status_html, $allowed_html );
	}

	/**
	 * Fire a gateway specific hook for when a subscription is activated.
	 *
	 */
	public static function trigger_gateway_activated_subscription_hook( $user_id, $subscription_key ) {
		self::trigger_gateway_status_updated_hook( awc_get_subscription_from_key( $subscription_key ), 'active' );
	}

	/**
	 * Fire a gateway specific hook for when a subscription is activated.
	 *
	 
	 */
	public static function trigger_gateway_reactivated_subscription_hook( $user_id, $subscription_key ) {
		self::trigger_gateway_status_updated_hook( awc_get_subscription_from_key( $subscription_key ), 'active' );
	}

	/**
	 * Fire a gateway specific hook for when a subscription is on-hold.
	 *
	 
	 */
	public static function trigger_gateway_subscription_put_on_hold_hook( $user_id, $subscription_key ) {
		self::trigger_gateway_status_updated_hook( awc_get_subscription_from_key( $subscription_key ), 'on-hold' );
	}

	/**
	 * Fire a gateway specific when a subscription is cancelled.
	 *
	 */
	public static function trigger_gateway_cancelled_subscription_hook( $user_id, $subscription_key ) {
		self::trigger_gateway_status_updated_hook( awc_get_subscription_from_key( $subscription_key ), 'cancelled' );
	}

	/**
	 * Fire a gateway specific hook when a subscription expires.
	 *
	 */
	public static function trigger_gateway_subscription_expired_hook( $user_id, $subscription_key ) {
		self::trigger_gateway_status_updated_hook( awc_get_subscription_from_key( $subscription_key ), 'expired' );
	}
}
