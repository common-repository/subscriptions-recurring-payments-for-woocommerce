<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Define requirements for a related order data store and provide method for accessing active data store.
 *
 * @category Class
 * 
 */
abstract class AWC_Related_Order_Store {

	/** @var AWC_Related_Order_Store */
	private static $instance = null;

	/**
	 * Types of relationships the data store supports.
	 *
	 * @var array
	 */
	private static $relation_types = array(
		'renewal',
		'switch',
		'resubscribe',
	);

	/**
	 * An array using @see self::$relation_types as keys for more performant checks by @see $this->check_relation_type().
	 *
	 * Set when instantiated.
	 *
	 * @var array
	 */
	private static $relation_type_keys = array();

	/**
	 * Get the active related order data store.
	 *
	 * @return AWC_Related_Order_Store
	 */
	final public static function instance() {

		if ( empty( self::$instance ) ) {
			if ( ! did_action( 'plugins_loaded' ) ) {
				awc_doing_it_wrong( __METHOD__, 'This method was called before the "plugins_loaded" hook. It applies a filter to the related order data store instantiated. For that to work, it should first be called after all plugins are loaded.', '2.3.0' );
			}

			/**
			 * Allow third-parties to register their own custom order relationship types which should be handled by this store.
			 *
			 * @param array An array of order relationship types.
			 */
			foreach ( (array) apply_filters( 'awc_additional_related_order_relation_types', array() ) as $relation_type ) {
				self::$relation_types[] = $relation_type;

			}

			self::$relation_type_keys = array_fill_keys( self::$relation_types, true );

			$class = apply_filters( 'awc_related_order_store_class', 'AWC_Related_Order_Store_Cached_CPT' );
			self::$instance = new $class();
			self::$instance->init();
		}

		return self::$instance;
	}

	/**
	 * Stub for initialising the class outside the constructor, for things like attaching callbacks to hooks.
	 */
	protected function init() {}

	/**
	 * Get orders related to a given subscription with a given relationship type.
	 *
	 * @param WC_Order
	 * @param string
	 *
	 * @return array
	 */
	abstract public function get_related_order_ids( WC_Order $subscription, $relation_type );


	
	/**
	 * Find subscriptions related to a given order in a given way, if any.
	 *
	 * @param WC_Order 
	 * @param string 
	 * @return array
	 */
	abstract public function get_related_subscription_ids( WC_Order $order, $relation_type );

	/**
	 * Helper function for linking an order to a subscription via a given relationship.
	 *
	 * @param WC_Order $order The order to link with the subscription.
	 * @param WC_Order $subscription The order or subscription to link the order to.
	 * @param string $relation_type The relationship between the subscription and the order. Must be 'renewal', 'switch' or 'resubscribe' unless custom relationships are implemented.
	 */
	abstract public function add_relation( WC_Order $order, WC_Order $subscription, $relation_type );

	/**
	 * Remove the relationship between a given order and subscription.
	 *
	 * @param WC_Order $order An order that may be linked with subscriptions.
	 * @param WC_Order $subscription A subscription or order to unlink the order with, if a relation exists.
	 * @param string $relation_type The relationship between the subscription and the order. Must be 'renewal', 'switch' or 'resubscribe' unless custom relationships are implemented.
	 */
	abstract public function delete_relation( WC_Order $order, WC_Order $subscription, $relation_type );

	/**
	 * Remove all related orders/subscriptions of a given type from an order.
	 *
	 * @param WC_Order $order An order that may be linked with subscriptions.
	 * @param string $relation_type The relationship between the subscription and the order. Must be 'renewal', 'switch' or 'resubscribe' unless custom relationships are implemented.
	 */
	abstract public function delete_relations( WC_Order $order, $relation_type );

	/**
	 * Types of relationships the data store supports.
	 *
	 * @return array The possible relationships between a subscription and orders. Includes 'renewal', 'switch' or 'resubscribe' by default.
	 */
	public function get_relation_types() {
		return self::$relation_types;
	}

	/**
	 * Check if a given relationship is supported by the data store.
	 *
	 * @param string $relation_type The relationship between the subscription and the order. Must be 'renewal', 'switch' or 'resubscribe' unless custom relationships are implemented.
	 *
	 * @throws InvalidArgumentException If the given order relation is not a known type.
	 */
	protected function check_relation_type( $relation_type ) {
		if ( ! isset( self::$relation_type_keys[ $relation_type ] ) ) {
			// translators: 1: relation type, 2: list of valid relation types.
			throw new InvalidArgumentException( sprintf( __( 'Invalid relation type: %1$s. Order relationship type must be one of: %2$s.', 'subscriptions-recurring-payments-for-woocommerce' ), $relation_type, implode( ', ', $this->get_relation_types() ) ) );
		}
	}
}
