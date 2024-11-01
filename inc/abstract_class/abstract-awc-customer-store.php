<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Define requirements for a customer data store and provide method for accessing active data store.
 *
 */
if(!class_exists('AWC_Customer_Store')){
abstract class AWC_Customer_Store {

	/** @var AWC_Customer_Store */
	private static $instance = null;

	/**
	 * Get the IDs for a given user's subscriptions.
	 *
	 * @param int $user_id The id of the user whose subscriptions you want.
	 * @return array
	 */
	abstract public function get_users_subscription_ids( $user_id );

	/**
	 * Get the active customer data store.
	 *
	 * @return AWC_Customer_Store
	 */
	final public static function instance() {

		if ( empty( self::$instance ) ) {
			if ( ! did_action( 'plugins_loaded' ) ) {
				awc_doing_it_wrong( __METHOD__, 'This method was called before the "plugins_loaded" hook. It applies a filter to the customer data store instantiated. For that to work, it should first be called after all plugins are loaded.', '2.3.0' );
			}

			$class = apply_filters( 'awc_customer_store_class', 'awc_Customer_Store_Cached_CPT' );
			self::$instance = new $class();
			self::$instance->init();
		}

		return self::$instance;
	}

	/**
	 * Stub for initialising the class outside the constructor, for things like attaching callbacks to hooks.
	 */
	protected function init() {}
}
}