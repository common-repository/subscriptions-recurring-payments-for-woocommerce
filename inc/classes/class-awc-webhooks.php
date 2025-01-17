<?php
/**
 * WooCommerce Subscriptions Webhook class
 *
 * This class introduces webhooks to, storing and retrieving webhook data from the associated
 * `shop_webhook` custom post type, as well as delivery logs from the `webhook_delivery`
 * comment type.
 *
 * Subscription Webhooks are enqueued to their associated actions, delivered, and logged.
 *
 * 
 * @category    Webhooks
 
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

class AWC_Webhooks {

	/**
	 * Setup webhook for subscriptions
	 *
	 
	 */
	public static function init() {

		add_filter( 'woocommerce_webhook_topic_hooks', __CLASS__ . '::add_topics', 20, 2 );

		add_filter( 'woocommerce_webhook_payload', __CLASS__ . '::create_payload', 10, 4 );

		add_filter( 'woocommerce_valid_webhook_resources', __CLASS__ . '::add_resource', 10, 1 );

		add_filter( 'woocommerce_valid_webhook_events', __CLASS__ . '::add_event', 10, 1 );

		add_action( 'woocommerce_checkout_subscription_created', __CLASS__ . '::add_subscription_created_callback', 10, 1 );

		add_action( 'woocommerce_subscription_date_updated', __CLASS__ . '::add_subscription_updated_callback', 10, 1 );

		add_action( 'awc_subscriptions_switch_completed', __CLASS__ . '::add_subscription_switched_callback', 10, 1 );

		add_filter( 'woocommerce_webhook_topics', __CLASS__ . '::add_topics_admin_menu', 10, 1 );

		add_filter( 'awc_new_order_created', __CLASS__ . '::add_subscription_created_order_callback', 10, 1 );

	}

	/**
	 * Trigger `order.create` every time an order is created by Subscriptions.
	 *
	 * @param WC_Order $order WC_Order Object
	 */
	public static function add_subscription_created_order_callback( $order ) {

		do_action( 'awc_webhook_order_created', awc_get_objects_property( $order, 'id' ) );

		return $order;
	}

	/**
	 * Add Subscription webhook topics
	 *
	 * @param array $topic_hooks
	 
	 */
	public static function add_topics( $topic_hooks, $webhook ) {

		switch ( $webhook->get_resource() ) {
			case 'order':
				$topic_hooks['order.created'][] = 'awc_webhook_order_created';
				break;

			case 'subscription':
				$topic_hooks = apply_filters( 'woocommerce_subscriptions_webhook_topics', array(
					'subscription.created'  => array(
						'awc_api_subscription_created',
						'awc_webhook_subscription_created',
						'woocommerce_process_shop_subscription_meta',
					),
					'subscription.updated'  => array(
						'awc_api_subscription_updated',
						'woocommerce_subscription_status_changed',
						'awc_webhook_subscription_updated',
						'woocommerce_process_shop_subscription_meta',
					),
					'subscription.deleted'  => array(
						'woocommerce_subscription_trashed',
						'woocommerce_subscription_deleted',
						'woocommerce_api_delete_subscription',
					),
					'subscription.switched' => array(
						'awc_webhook_subscription_switched',
					),
				), $webhook );
				break;
		}

		return $topic_hooks;
	}

	/**
	 * Add Subscription topics to the Webhooks dropdown menu in when creating a new webhook.
	 *
	 
	 */
	public static function add_topics_admin_menu( $topics ) {

		$front_end_topics = array(
			'subscription.created'  => __( ' Subscription Created', 'subscriptions-recurring-payments-for-woocommerce' ),
			'subscription.updated'  => __( ' Subscription Updated', 'subscriptions-recurring-payments-for-woocommerce' ),
			'subscription.deleted'  => __( ' Subscription Deleted', 'subscriptions-recurring-payments-for-woocommerce' ),
			'subscription.switched' => __( ' Subscription Switched', 'subscriptions-recurring-payments-for-woocommerce' ),
		);

		return array_merge( $topics, $front_end_topics );
	}

	/**
	 * Setup payload for subscription webhook delivery.
	 *
	 
	 */
	public static function create_payload( $payload, $resource, $resource_id, $id ) {

		if ( 'subscription' == $resource && empty( $payload ) && awc_is_subscription( $resource_id ) ) {
			$webhook      = new WC_Webhook( $id );
			$event        = $webhook->get_event();
			$current_user = get_current_user_id();

			wp_set_current_user( $webhook->get_user_id() );

			$webhook_api_version = ( method_exists( $webhook, 'get_api_version' ) ) ? $webhook->get_api_version() : 'legacy_v3';

			switch ( $webhook_api_version ) {
				case 'legacy_v3':
					WC()->api->WC_API_Subscriptions->register_routes( array() );
					$payload = WC()->api->WC_API_Subscriptions->get_subscription( $resource_id );
					break;
				case 'wp_api_v1':
				case 'wp_api_v2':
				case 'wp_api_v3':
					$request    = new WP_REST_Request( 'GET' );
					$controller = new AWC_Subscription_REST_Controller;

					$request->set_param( 'id', $resource_id );
					$result  = $controller->get_item( $request );
					$payload = isset( $result->data ) ? $result->data : array();
					break;
			}

			wp_set_current_user( $current_user );
		}

		return $payload;
	}

	/**
	 * Add webhook resource for subscription.
	 *
	 * @param array $resources
	 
	 */
	public static function add_resource( $resources ) {

		$resources[] = 'subscription';

		return $resources;
	}

	/**
	 * Add webhook event for subscription switched.
	 *
	 * @param array $events
	 
	 */
	public static function add_event( $events ) {

		$events[] = 'switched';

		return $events;
	}

	/**
	 * Call a "subscription created" action hook with the first parameter being a subscription id so that it can be used
	 * for webhooks.
	 *
	 
	 */
	public static function add_subscription_created_callback( $subscription ) {
		do_action( 'awc_webhook_subscription_created', $subscription->get_id() );
	}

	/**
	 * Call a "subscription updated" action hook with a subscription id as the first parameter to be used for webhooks payloads.
	 *
	 
	 */
	public static function add_subscription_updated_callback( $subscription ) {
		do_action( 'awc_webhook_subscription_updated', $subscription->get_id() );
	}

	/**
	 * For each switched subscription in an order, call a "subscription switched" action hook with a subscription id as the first parameter to be used for webhooks payloads.
	 *
	 
	 */
	public static function add_subscription_switched_callback( $order ) {
		$switched_subscriptions = awc_get_subscriptions_for_switch_order( $order );
		foreach ( array_keys( $switched_subscriptions ) as $subscription_id ) {
			do_action( 'awc_webhook_subscription_switched', $subscription_id );
		}
	}

}
