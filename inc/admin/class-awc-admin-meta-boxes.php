<?php
/**
 * WooCommerce Subscriptions Admin Meta Boxes
 *
 * Sets up the write panels used by the subscription custom order/post type
 *
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * WC_Admin_Meta_Boxes
 */
class AWC_Admin_Meta_Boxes {

	/**
	 * Constructor
	 */
	public function __construct() {

		add_action( 'add_meta_boxes', array( $this, 'add_meta_boxes' ), 25 );

		add_action( 'add_meta_boxes', array( $this, 'remove_meta_boxes' ), 35 );

		// We need to remove core WC save methods for meta boxes we don't use
		add_action( 'woocommerce_process_shop_order_meta', array( $this, 'remove_meta_box_save' ), -1, 2 );

		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_styles_scripts' ), 20 );

		// We need to hook to the 'shop_order' rather than 'shop_subscription' because we declared that the 'shop_susbcription' order type supports 'order-meta-boxes'
		add_action( 'woocommerce_process_shop_order_meta', 'awc_Meta_Box_Schedule::save', 10, 2 );
		add_action( 'woocommerce_process_shop_order_meta', 'awc_Meta_Box_Subscription_Data::save', 10, 2 );

		add_filter( 'woocommerce_order_actions', array( __CLASS__, 'add_subscription_actions' ), 10, 1 );

		add_action( 'woocommerce_order_action_awc_process_renewal', array( __CLASS__, 'process_renewal_action_request' ), 10, 1 );
		add_action( 'woocommerce_order_action_awc_create_pending_renewal', array( __CLASS__, 'create_pending_renewal_action_request' ), 10, 1 );
		add_action( 'woocommerce_order_action_awc_create_pending_parent', array( __CLASS__, 'create_pending_parent_action_request' ), 10, 1 );

		if ( AWC_Subscriptions::is_woocommerce_pre( '3.2' ) )
			add_filter( 'woocommerce_resend_order_emails_available', array( __CLASS__, 'remove_order_email_actions' ), 0, 1 );
		

		add_action( 'woocommerce_order_action_awc_retry_renewal_payment', array( __CLASS__, 'process_retry_renewal_payment_action_request' ), 10, 1 );

		// Disable stock managment while adding line items to a subscription via AJAX.
		add_action( 'option_woocommerce_manage_stock', array( __CLASS__, 'override_stock_management' ) );
	}

	/**
	 * Add WC Meta boxes 
	 */
	public function add_meta_boxes() {
		global $post_ID;

		add_meta_box( 'woocommerce-subscription-data', _x( 'Subscription Data', 'meta box title', 'subscriptions-recurring-payments-for-woocommerce' ), 'AWC_Meta_Box_Subscription_Data::output', 'shop_subscription', 'normal', 'high' );

		add_meta_box( 'woocommerce-subscription-schedule', _x( 'Schedule', 'meta box title', 'subscriptions-recurring-payments-for-woocommerce' ), 'AWC_Meta_Box_Schedule::output', 'shop_subscription', 'side', 'default' );

		remove_meta_box( 'woocommerce-order-data', 'shop_subscription', 'normal' );

		add_meta_box( 'subscription_renewal_orders', __( 'Related Orders', 'subscriptions-recurring-payments-for-woocommerce' ), 'AWC_Meta_Box_Related_Orders::output', 'shop_subscription', 'normal', 'low' );

		// Only display the meta box if an order relates to a subscription
		if ( 'shop_order' === get_post_type( $post_ID ) && awc_order_contains_subscription( $post_ID, 'any' ) ) {
			add_meta_box( 'subscription_renewal_orders', __( 'Related Subscription', 'subscriptions-recurring-payments-for-woocommerce' ), 'AWC_Meta_Box_Related_Orders::output', 'shop_order', 'normal', 'low' );
		}
	}

	/**
	 * Removes the core Order Data meta box as we add our own Subscription Data meta box
	 */
	public function remove_meta_boxes() {
		remove_meta_box( 'woocommerce-order-data', 'shop_subscription', 'normal' );
	}

	/**
	 * Don't save save some order related meta boxes
	 */
	public function remove_meta_box_save( $post_id, $post ) {

		if ( 'shop_subscription' == $post->post_type ) {
			remove_action( 'woocommerce_process_shop_order_meta', 'WC_Meta_Box_Order_Data::save', 40 );
		}
	}

	/**
	 * Print admin styles/scripts
	 */
	public function enqueue_styles_scripts() {
		global $post;

		// Get admin screen id
		$screen    = get_current_screen();
		$screen_id = isset( $screen->id ) ? $screen->id : '';

		if ( 'shop_subscription' == $screen_id ) {

			wp_register_script( 'jstz', plugin_dir_url( AWC_Subscriptions::$plugin_file ) . 'assets/js/admin/jstz.min.js' );

			wp_register_script( 'momentjs', plugin_dir_url( AWC_Subscriptions::$plugin_file ) . 'assets/js/admin/moment.min.js' );

			wp_enqueue_script( 'awc-admin-meta-boxes-subscription', plugin_dir_url( AWC_Subscriptions::$plugin_file ) . 'assets/js/admin/meta-boxes-subscription.js', array( 'wc-admin-meta-boxes', 'jstz', 'momentjs' ), WC_VERSION );

			wp_localize_script( 'awc-admin-meta-boxes-subscription', 'awc_admin_meta_boxes', apply_filters( 'woocommerce_subscriptions_admin_meta_boxes_script_parameters', array(
				'i18n_start_date_notice'         => __( 'Please enter a start date in the past.', 'subscriptions-recurring-payments-for-woocommerce' ),
				'i18n_past_date_notice'          => __( 'Please enter a date at least one hour into the future.', 'subscriptions-recurring-payments-for-woocommerce' ),
				'i18n_next_payment_start_notice' => __( 'Please enter a date after the trial end.', 'subscriptions-recurring-payments-for-woocommerce' ),
				'i18n_next_payment_trial_notice' => __( 'Please enter a date after the start date.', 'subscriptions-recurring-payments-for-woocommerce' ),
				'i18n_trial_end_start_notice'    => __( 'Please enter a date after the start date.', 'subscriptions-recurring-payments-for-woocommerce' ),
				'i18n_trial_end_next_notice'     => __( 'Please enter a date before the next payment.', 'subscriptions-recurring-payments-for-woocommerce' ),
				'i18n_end_date_notice'           => __( 'Please enter a date after the next payment.', 'subscriptions-recurring-payments-for-woocommerce' ),
				'process_renewal_action_warning' => __( "Are you sure you want to process a renewal?\n\nThis will charge the customer and email them the renewal order (if emails are enabled).", 'subscriptions-recurring-payments-for-woocommerce' ),
				'payment_method'                 => awc_get_subscription( $post )->get_payment_method(),
				'search_customers_nonce'         => wp_create_nonce( 'search-customers' ),
				'get_customer_orders_nonce'      => wp_create_nonce( 'get-customer-orders' ),
			) ) );
		} else if ( 'shop_order' == $screen_id ) {

			wp_enqueue_script( 'asub-admin-meta-boxes-order', plugin_dir_url( AWC_Subscriptions::$plugin_file ) . 'assets/js/admin/asub-meta-boxes-order.js' );

			wp_localize_script(
				'awc-admin-meta-boxes-order',
				'awc_admin_order_meta_boxes',
				array(
					'retry_renewal_payment_action_warning' => __( "Are you sure you want to retry payment for this renewal order?\n\nThis will attempt to charge the customer and send renewal order emails (if emails are enabled).", 'subscriptions-recurring-payments-for-woocommerce' ),
				)
			);
		}

		// Enqueue the metabox script for coupons.
		if ( ! AWC_Subscriptions::is_woocommerce_pre( '3.2' ) && in_array( $screen_id, array( 'shop_coupon', 'edit-shop_coupon' ) ) ) {
			wp_enqueue_script(
				'awc-admin-coupon-meta-boxes',
				plugin_dir_url( AWC_Subscriptions::$plugin_file ) . 'assets/js/admin/meta-boxes-coupon.js',
				array( 'jquery', 'wc-admin-meta-boxes' ),
				AWC_Subscriptions::$version
			);
		}
	}

	/**
	 * Adds actions to the admin edit subscriptions page, if the subscription hasn't ended and the payment method supports them.
	 *
	 * @param array $actions An array of available actions
	 * @return array An array of updated actions
	 */
	public static function add_subscription_actions( $actions ) {
		global $theorder;

		if ( awc_is_subscription( $theorder ) ) {
			if ( ! AWC_Subscriptions::is_woocommerce_pre( '3.2' ) ) {
				unset( $actions['send_order_details'], $actions['send_order_details_admin'] );
			}

			if ( ! $theorder->has_status( awc_get_subscription_ended_statuses() ) ) {
				if ( $theorder->payment_method_supports( 'subscription_date_changes' ) && $theorder->has_status( 'active' ) ) {
					$actions['awc_process_renewal'] = esc_html__( 'Process renewal', 'subscriptions-recurring-payments-for-woocommerce' );
				}

				if ( count( $theorder->get_related_orders() ) > 0 ) {
					$actions['awc_create_pending_renewal'] = esc_html__( 'Create pending renewal order', 'subscriptions-recurring-payments-for-woocommerce' );
				} else {
					$actions['awc_create_pending_parent'] = esc_html__( 'Create pending parent order', 'subscriptions-recurring-payments-for-woocommerce' );
				}
			}
		} else if ( self::can_renewal_order_be_retried( $theorder ) ) {
			$actions['awc_retry_renewal_payment'] = esc_html__( 'Retry Renewal Payment', 'subscriptions-recurring-payments-for-woocommerce' );
		}

		return $actions;
	}

	/**
	 * Handles the action request to process a renewal order.
	 *
	 * @param array $subscription
	 */
	public static function process_renewal_action_request( $subscription ) {
		$subscription->add_order_note( __( 'Process renewal order action requested by admin.', 'subscriptions-recurring-payments-for-woocommerce' ), false, true );
		do_action( 'woocommerce_scheduled_subscription_payment', $subscription->get_id() );
	}

	/**
	 * Handles the action request to create a pending renewal order.
	 *
	 * @param array $subscription
	 */
	public static function create_pending_renewal_action_request( $subscription ) {
		$subscription->add_order_note( __( 'Create pending renewal order requested by admin action.', 'subscriptions-recurring-payments-for-woocommerce' ), false, true );
		$subscription->update_status( 'on-hold' );

		$renewal_order = awc_create_renewal_order( $subscription );

		if ( ! $subscription->is_manual() ) {

			$renewal_order->set_payment_method( wc_get_payment_gateway_by_order( $subscription ) ); // We need to pass the payment gateway instance to be compatible with WC < 3.0, only WC 3.0+ supports passing the string name

			if ( is_callable( array( $renewal_order, 'save' ) ) ) { // WC 3.0+
				$renewal_order->save();
			}
		}
	}

	/**
	 * Handles the action request to create a pending parent order.
	 *
	 * @param array $subscription
	 */
	public static function create_pending_parent_action_request( $subscription ) {

		if ( ! $subscription->has_status( array( 'pending', 'on-hold' ) ) ) {
			$subscription->update_status( 'on-hold' );
		}

		$parent_order = awc_create_order_from_subscription( $subscription, 'parent' );

		$subscription->set_parent_id( awc_get_objects_property( $parent_order, 'id' ) );
		$subscription->save();

		if ( ! $subscription->is_manual() ) {

			$parent_order->set_payment_method( wc_get_payment_gateway_by_order( $subscription ) ); // We need to pass the payment gateway instance to be compatible with WC < 3.0, only WC 3.0+ supports passing the string name

			if ( is_callable( array( $parent_order, 'save' ) ) ) { // WC 3.0+
				$parent_order->save();
			}
		}

		wc_maybe_reduce_stock_levels( $parent_order );
		$subscription->add_order_note( __( 'Create pending parent order requested by admin action.', 'subscriptions-recurring-payments-for-woocommerce' ), false, true );
	}

	/**
	 * Removes order related emails from the available actions.
	 *
	 * @param array $available_emails
	 */
	public static function remove_order_email_actions( $email_actions ) {
		global $theorder;

		if ( awc_is_subscription( $theorder ) ) {
			$email_actions = array();
		}

		return $email_actions;
	}

	/**
	 * Process the action request to retry renewal payment for failed renewal orders.
	 *
	 * @param WC_Order $order
	 */
	public static function process_retry_renewal_payment_action_request( $order ) {

		if ( self::can_renewal_order_be_retried( $order ) ) {
			// init payment gateways
			WC()->payment_gateways();

			$order->add_order_note( __( 'Retry renewal payment action requested by admin.', 'subscriptions-recurring-payments-for-woocommerce' ), false, true );
			do_action( 'awc_subscription_scheduled_payment_' . awc_get_objects_property( $order, 'payment_method' ), $order->get_total(), $order );
		}
	}

	/** 
	 *
	 * @param WC_Order $order
	 * @return bool
	 */
	private static function can_renewal_order_be_retried( $order ) {

		$can_be_retried = false;

		if ( awc_order_contains_renewal( $order ) && $order->needs_payment() && '' != awc_get_objects_property( $order, 'payment_method' ) ) {
			$supports_date_changes          = false;
			$order_payment_gateway          = wc_get_payment_gateway_by_order( $order );
			$order_payment_gateway_supports = ( isset( $order_payment_gateway->id ) ) ? has_action( 'awc_subscription_scheduled_payment_' . $order_payment_gateway->id ) : false;

			foreach ( awc_get_subscriptions_for_renewal_order( $order ) as $subscription ) {
				$supports_date_changes = $subscription->payment_method_supports( 'subscription_date_changes' );
				$is_automatic = ! $subscription->is_manual();
				break;
			}

			$can_be_retried = $order_payment_gateway_supports && $supports_date_changes && $is_automatic;
		}

		return $can_be_retried;
	}

	/**
	 * Disables stock managment while adding items to a subscription via the edit subscription screen.
	 *
	 *
	 * @param string $manage_stock The default manage stock setting.
	 * @return string Whether the stock should be managed.
	 */
	public static function override_stock_management( $manage_stock ) {

		// Override stock management while adding line items to a subscription via AJAX.
		if ( isset( $_POST['order_id'] ) && wp_verify_nonce( $_REQUEST['security'], 'order-item' ) && doing_action( 'wp_ajax_woocommerce_add_order_item' ) && awc_is_subscription( absint( wp_unslash( $_POST['order_id'] ) ) ) ) {
			$manage_stock = 'no';
		}

		return $manage_stock;
	}
}
