<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Related order data store for orders stored in Custom Post Types.
 *
 *
 */
class AWC_Related_Order_Store_CPT extends AWC_Related_Order_Store {

	/**
	 * Meta keys used to link an order with a subscription for each type of relationship.
	 *
	 
	 * @var array $meta_keys Relationship => Meta key
	 */
	private $meta_keys;

	/**
	 * Constructor: sets meta keys used for storing each order relation.
	 */
	public function __construct() {
		foreach ( $this->get_relation_types() as $relation_type ) {
			$this->meta_keys[ $relation_type ] = sprintf( '_subscription_%s', $relation_type );
		}
	}

	/**
	 * Find orders related to a given subscription in a given way.
	 *
	 *
	 * @param WC_Order $subscription The ID of the subscription for which calling code wants the related orders.
	 * @param string $relation_type The relationship between the subscription and the orders. Must be 'renewal', 'switch' or 'resubscribe.
	 *
	 * @return array
	 */
	public function get_related_order_ids( WC_Order $subscription, $relation_type ) {
		$related_order_ids = get_posts( array(
			'posts_per_page'         => -1,
			'post_type'              => 'shop_order',
			'post_status'            => 'any',
			'fields'                 => 'ids',
			'meta_query'             => array(
				array(
					'key'     => $this->get_meta_key( $relation_type ),
					'compare' => '=',
					'value'   => awc_get_objects_property( $subscription, 'id' ), // We can't rely on get_id() being available here, because we only require a WC_Order, not a WC_Subscription, and WC_Order does not have get_id() available with WC < 3.0
					'type'    => 'numeric',
				),
			),
			'update_post_term_cache' => false,
		) );

		rsort( $related_order_ids );

		return $related_order_ids;
	}

	/**
	 * Find subscriptions related to a given order in a given way, if any.
	 *
	 * @param WC_Order $order The ID of an order that may be linked with subscriptions.
	 * @param string $relation_type The relationship between the subscription and the orders. Must be 'renewal', 'switch' or 'resubscribe.
	 *
	 * @return array
	 */
	public function get_related_subscription_ids( WC_Order $order, $relation_type ) {
		$related_order_meta_key = $this->get_meta_key( $relation_type );
		if ( AWC_Subscriptions::is_woocommerce_pre( '3.0' ) ) {
			$related_subscription_ids = get_post_meta( awc_get_objects_property( $order, 'id' ), $related_order_meta_key, false );
		} else {
			$related_subscription_ids = $order->get_meta( $related_order_meta_key, false );
			// Normalise the return value: WooCommerce returns a set of WC_Meta_Data objects, with values cast to strings, even if they're integers
			$related_subscription_ids = wp_list_pluck( $related_subscription_ids, 'value' );
			$related_subscription_ids = array_map( 'absint', $related_subscription_ids );
			$related_subscription_ids = array_values( $related_subscription_ids );
		}

		$related_subscription_ids = $this->apply_deprecated_related_order_filter( $related_subscription_ids, $order, $relation_type );

		return apply_filters( 'awc_orders_related_subscription_ids', $related_subscription_ids, $order, $relation_type );
	}

	/**
	 * Apply the deprecated 'awc_subscriptions_for_renewal_order' and 'awc_subscriptions_for_resubscribe_order' filters
	 * to maintain backward compatibility.
	 *
	 * @param array $subscription_ids The IDs of subscription linked to the given order, if any.
	 * @param WC_Order $order An instance of an order that may be linked with subscriptions.
	 * @param string $relation_type The relationship between the subscription and the orders. Must be 'renewal', 'switch' or 'resubscribe'.
	 *
	 * @return array
	 */
	protected function apply_deprecated_related_order_filter( $subscription_ids, WC_Order $order, $relation_type ) {

		$deprecated_filter_hook = "awc_subscriptions_for_{$relation_type}_order";

		if ( has_filter( $deprecated_filter_hook ) ) {
			_deprecated_function( sprintf( '"%s" hook should no longer be used and', esc_html( $deprecated_filter_hook ) ), '2.3.2', '"awc_orders_related_subscription_ids" with a check on the 3rd param, to take advantage of the new persistent caching layer for related subscription IDs' );

			$subscriptions = array();

			foreach ( $subscription_ids as $subscription_id ) {
				if ( awc_is_subscription( $subscription_id ) ) {
					$subscriptions[ $subscription_id ] = awc_get_subscription( $subscription_id );
				}
			}

			$filtered_subscriptions = apply_filters( $deprecated_filter_hook, $subscriptions, $order );

			// Although this array was previously ordered by ID => instance, that key requirement wasn't enforced so it's possible 3rd party code was not using the ID as the key, and instead, numerical indexes are being used, so its safest not to rely on IDs as keys
			if ( $filtered_subscriptions != $subscriptions ) {

				$subscription_ids = array();

				foreach ( $filtered_subscriptions as $subscription ) {
					$subscription_ids[] = $subscription->get_id();
				}
			}
		}

		return $subscription_ids;
	}

	/**
	 * Helper function for linking an order to a subscription via a given relationship.
	 *
	 * Existing order relationships of the same type will not be overwritten. This only adds a relationship. To overwrite,
	 * you must also remove any existing relationship with @see $this->delete_relation().
	 *
	 * This data store links the relationship for a renewal order and a subscription in meta data against the order.
	 * That's inefficient for queries, so will be changed in future with a different data store. It also leads to potential
	 * bugs when WooCommerce 3.0 or newer is running with a custom data store for order data, as related orders are queried
	 * in $this->get_related_order_ids() using post meta directly, but set here using the CRUD WC_Data::add_meta_data() method.
	 * This is unfortunately unavoidable. See the awc_Related_Order_Store_CPT docblock for more details.
	 *
	 * @param WC_Order $order The order to link with the subscription.
	 * @param WC_Order $subscription The order or subscription to link the order to.
	 * @param string $relation_type The relationship between the subscription and the order. Must be 'renewal', 'switch' or 'resubscribe' unless custom relationships are implemented.
	 */
	public function add_relation( WC_Order $order, WC_Order $subscription, $relation_type ) {

		// We can't rely on $subscription->get_id() being available here, because we only require a WC_Order, not a WC_Subscription, and WC_Order does not have get_id() available with WC < 3.0
		$subscription_id        = awc_get_objects_property( $subscription, 'id' );
		$related_order_meta_key = $this->get_meta_key( $relation_type );

		if ( AWC_Subscriptions::is_woocommerce_pre( '3.0' ) ) {
			$order_id             = awc_get_objects_property( $order, 'id' );
			$existing_related_ids = get_post_meta( $order_id, $related_order_meta_key, false );

			if ( empty( $existing_related_ids ) || ! in_array( $subscription_id, $existing_related_ids ) ) {
				add_post_meta( $order_id, $related_order_meta_key, $subscription_id, false );
			}
		} else {
			// We want to allow more than one piece of meta per key on the order, but we don't want to duplicate the same meta key => value combination, so we need to check if it is set first
			$existing_relations   = $order->get_meta( $related_order_meta_key, false );
			$existing_related_ids = wp_list_pluck( $existing_relations, 'value' );

			if ( empty( $existing_relations ) || ! in_array( $subscription_id, $existing_related_ids ) ) {
				$order->add_meta_data( $related_order_meta_key, $subscription_id, false );
				$order->save_meta_data();
			}
		}
	}

	/**
	 * Remove the relationship between a given order and subscription.
	 *
	 * This data store links the relationship for a renewal order and a subscription in meta data against the order.
	 * That's inefficient for queries, so will be changed in future with a different data store. It also leads to bugs
	 * with custom data stores for order data, as $this->get_related_order_ids() queries post meta directly. This is
	 * unavoidable. See the awc_Related_Order_Store_CPT docblock for more details.
	 *
	 * @param WC_Order $order An order that may be linked with subscriptions.
	 * @param WC_Order $subscription A subscription or order to unlink the order with, if a relation exists.
	 * @param string $relation_type The relationship between the subscription and the order. Must be 'renewal', 'switch' or 'resubscribe' unless custom relationships are implemented.
	 */
	public function delete_relation( WC_Order $order, WC_Order $subscription, $relation_type ) {
		$related_order_meta_key = $this->get_meta_key( $relation_type );
		if ( AWC_Subscriptions::is_woocommerce_pre( '3.0' ) ) {
			delete_post_meta( awc_get_objects_property( $order, 'id' ), $related_order_meta_key, awc_get_objects_property( $subscription, 'id' ) );
		} else {
			foreach ( $order->get_meta_data() as $meta ) {
				if ( $meta->key == $related_order_meta_key && $meta->value == awc_get_objects_property( $subscription, 'id' ) ) { // we can't do strict comparison here, because WC_Meta_Data casts the subscription ID to be a string
					$order->delete_meta_data_by_mid( $meta->id );
				}
			}
			$order->save_meta_data();
		}
	}

	/**
	 * Remove all related orders/subscriptions of a given type from an order.
	 *
	 * @param WC_Order $order An order that may be linked with subscriptions.
	 * @param string $relation_type The relationship between the subscription and the order. Must be 'renewal', 'switch' or 'resubscribe' unless custom relationships are implemented.
	 */
	public function delete_relations( WC_Order $order, $relation_type ) {
		$related_order_meta_key = $this->get_meta_key( $relation_type );
		if ( AWC_Subscriptions::is_woocommerce_pre( '3.0' ) ) {
			delete_post_meta( awc_get_objects_property( $order, 'id' ), $related_order_meta_key, null );
		} else {
			$order->delete_meta_data( $related_order_meta_key );
			$order->save_meta_data();
		}
	}

	/**
	 * Get the meta keys used to link orders with subscriptions.
	 *
	 * @return array
	 */
	protected function get_meta_keys() {
		return $this->meta_keys;
	}

	/**
	 * Get the meta key used to link an order with a subscription based on the type of relationship.
	 *
	 * @param string $relation_type The order's relationship with the subscription. Must be 'renewal', 'switch' or 'resubscribe'.
	 * @param string $prefix_meta_key Whether to add the underscore prefix to the meta key or not. 'prefix' to prefix the key. 'do_not_prefix' to not prefix the key.
	 * @return string
	 */
	protected function get_meta_key( $relation_type, $prefix_meta_key = 'prefix' ) {

		$this->check_relation_type( $relation_type );

		$meta_key = $this->meta_keys[ $relation_type ];

		if ( 'do_not_prefix' === $prefix_meta_key ) {
			$meta_key = awc_maybe_unprefix_key( $meta_key );
		}

		return $meta_key;
	}
}
