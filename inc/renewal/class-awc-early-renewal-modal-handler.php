<?php
/**
 * A class to display and handle early renewal requests via the modal.
 *
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

class AWC_Early_Renewal_Modal_Handler {

	/**
	 * Attach callbacks.
	 *
	 
	 */
	public static function init() {
		add_action( 'woocommerce_subscription_details_table', array( __CLASS__, 'maybe_print_early_renewal_modal' ) );
		add_action( 'wp_loaded', array( __CLASS__, 'process_early_renewal_request' ), 20 );
	}

	/**
	 * Prints the early renewal modal for a specific subscription. If eligible.
	 *
	 
	 *
	 * @param WC_Subscription $subscription The subscription to print the modal for.
	 */
	public static function maybe_print_early_renewal_modal( $subscription ) {
		if ( ! self::can_user_renew_early_via_modal( $subscription ) ) {
			return;
		}

		$place_order_action = array(
			'text'       => __( 'Pay now', 'subscriptions-recurring-payments-for-woocommerce' ),
			'attributes' => array(
				'id'    => 'early_renewal_modal_submit',
				'class' => 'button alt ',
				'href'  => add_query_arg( array(
					'subscription_id'       => $subscription->get_id(),
					'process_early_renewal' => true,
					'awc_nonce'             => wp_create_nonce( 'asub-renew-early-modal-' . $subscription->get_id() ),
				) ),
			),
		);

		$callback_args = array(
			'callback'   => array( __CLASS__, 'output_early_renewal_modal' ),
			'parameters' => array( 'subscription' => $subscription ),
		);

		$modal = new AWC_Modal( $callback_args, '.subscription_renewal_early', 'callback', __( 'Renew early', 'subscriptions-recurring-payments-for-woocommerce' ) );
		$modal->add_action( $place_order_action );
		$modal->print_html();
	}

	/**
	 * Prints the early renewal modal HTML.
	 *
	 
	 * @param WC_Subscription $subscription The subscription to print the modal for.
	 */
	public static function output_early_renewal_modal( $subscription ) {
		$totals       = $subscription->get_order_item_totals();
		$date_changes = AWC_Early_Renewal_Manager::get_dates_to_update( $subscription );

		if ( isset( $totals['payment_method'] ) ) {
			$totals['payment_method']['label'] = __( 'Payment:', 'subscriptions-recurring-payments-for-woocommerce' );
		}

		// Convert the new next payment date into the site's timezone.
		if ( ! empty( $date_changes['next_payment'] ) ) {
			$new_next_payment_date = new AWC_DateTime( $date_changes['next_payment'], new DateTimeZone( 'UTC' ) );
			$new_next_payment_date->setTimezone( new DateTimeZone( wc_timezone_string() ) );
		} else {
			$new_next_payment_date = null;
		}

		wc_get_template(
			'html-early-renewal-modal-content.php',
			array(
				'subscription'          => $subscription,
				'totals'                => $totals,
				'new_next_payment_date' => $new_next_payment_date,
			),
			'',
			plugin_dir_path( AWC_Subscriptions::$plugin_file ) . '/temp/modal/'
		);
	}

	/**
	 * Processes the request to renew early via the modal.
	 *
	 
	 */
	public static function process_early_renewal_request() {
		if ( ! isset( $_GET['process_early_renewal'], $_GET['subscription_id'], $_GET['awc_nonce'] ) ) {
			return;
		}

		if ( ! wp_verify_nonce( $_GET['awc_nonce'], 'asub-renew-early-modal-' . $_GET['subscription_id'] ) ) {
			wc_add_notice( __( 'There was an error with your request. Please try again.', 'subscriptions-recurring-payments-for-woocommerce' ), 'error' );
			self::redirect();
		}

		$subscription = awc_get_subscription( absint( $_GET['subscription_id'] ) );

		if ( ! $subscription ) {
			wc_add_notice( __( 'We were unable to locate that subscription, please try again.', 'subscriptions-recurring-payments-for-woocommerce' ), 'error' );
			self::redirect();
		}

		if ( ! self::can_user_renew_early_via_modal( $subscription ) ) {
			wc_add_notice( __( "You can't renew the subscription at this time. Please try again.", 'subscriptions-recurring-payments-for-woocommerce' ), 'error' );
			self::redirect();
		}

		// Before processing the request, detach the functions which handle standard renewal orders. Note we don't need to reattach them as this request will terminate soon.
		self::detach_renewal_callbacks();

		$renewal_order = awc_create_renewal_order( $subscription );

		if ( ! awc_is_order( $renewal_order ) ) {
			wc_add_notice( __( "We couldn't create a renewal order for your subscription, please try again.", 'subscriptions-recurring-payments-for-woocommerce' ), 'error' );
			self::redirect();
		}

		$renewal_order->set_payment_method( wc_get_payment_gateway_by_order( $subscription ) );
		$renewal_order->update_meta_data( '_subscription_renewal_early', $subscription->get_id() );
		$renewal_order->save();

		// Attempt to collect payment with the subscription's current payment method.
		AWC_Subscription_Payment_Gateways::trigger_gateway_renewal_payment_hook( $renewal_order );

		// Now that we've attempted to process the payment, refresh the order.
		$renewal_order = wc_get_order( $renewal_order->get_id() );

		// Failed early renewals won't place the subscription on-hold so delete unsuccessful early renewal orders and redirect the user to complete the payment via checkout.
		if ( $renewal_order->needs_payment() ) {
			$renewal_order->delete( true );
			wc_add_notice( __( 'Payment for the renewal order was unsuccessful with your payment method on file, please try again.', 'subscriptions-recurring-payments-for-woocommerce' ), 'error' );
			wp_redirect( awc_get_early_renewal_url( $subscription ) );
			exit();
		} else {
			awc_update_dates_after_early_renewal( $subscription, $renewal_order );
			wc_add_notice( __( 'Your early renewal order was successful.', 'subscriptions-recurring-payments-for-woocommerce' ), 'success' );
		}

		self::redirect();
	}

	/**
	 * Checks if a user can renew a subscription early via the modal window.
	 *
	 * @param int|WC_Subscription $subscription Post ID of a 'shop_subscription' post, or instance of a WC_Subscription object.
	 * @param int $user_id The ID of a user. Defaults to the current user.
	 * @return boolean
	 *
	 
	 */
	public static function can_user_renew_early_via_modal( $subscription, $user_id = 0 ) {
		$user_id      = ! empty( $user_id ) ? absint( $user_id ) : get_current_user_id();
		$subscription = awc_get_subscription( $subscription );

		if ( ! AWC_Early_Renewal_Manager::is_early_renewal_via_modal_enabled() || ! awc_can_user_renew_early( $subscription, $user_id ) ) {
			return false;
		}

		return apply_filters( 'awc_subscription_can_user_renew_earlys_via_modal', $subscription->get_user_id() === $user_id, $subscription, $user_id );
	}

	/**
	 * Redirect the user after processing their early renewal request.
	 *
	 
	 */
	private static function redirect() {
		wp_redirect( remove_query_arg( array( 'process_early_renewal', 'subscription_id', 'awc_nonce' ) ) );
		exit();
	}

	/**
	 * Removes filters which shouldn't run while processing early renewals via the modal.
	 *
	 
	 */
	private static function detach_renewal_callbacks() {
		remove_filter( 'awc_renewal_order_created', 'AWC_Subscription_Renewal_Order::add_order_note', 10, 2 );
		remove_filter( 'woocommerce_order_status_changed', 'AWC_Subscription_Renewal_Order::maybe_record_subscription_payment', 10, 2 );
	}
}
