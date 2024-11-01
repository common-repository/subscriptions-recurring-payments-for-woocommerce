<?php
/**
 * WooCommerce Subscriptions API
 *
 * Handles WC-API endpoint requests related to Subscriptions
 *
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AWC_API {

	public static function init() {
		add_filter( 'woocommerce_api_classes', __CLASS__ . '::includes' );

		add_action( 'rest_api_init', __CLASS__ . '::register_routes', 15 );
	}

	/**
	 * Include the required files for the REST API and add register the subscription
	 * API class in the WC_API_Server.
	 *
	 
	 * @param Array $wc_api_classes WC_API::registered_resources list of api_classes
	 * @return array
	 */
	public static function includes( $wc_api_classes ) {

		if ( ! defined( 'WC_API_REQUEST_VERSION' ) || 3 == WC_API_REQUEST_VERSION ) {
			array_push( $wc_api_classes, 'WC_API_Subscriptions' );
			array_push( $wc_api_classes, 'WC_API_Subscriptions_Customers' );
		}

		return $wc_api_classes;
	}

	/**
	 * Load the new REST API subscription endpoints
	 *
	 
	 */
	public static function register_routes() {
		global $wp_version;

		if ( version_compare( $wp_version, 4.4, '<' ) || AWC_Subscriptions::is_woocommerce_pre( '2.6' ) ) {
			return;
		}

		foreach ( array( 'AWC_Subscription_REST_Controller', 'WC_REST_Subscription_Notes_Controller') as $api_class ) {
			$controller = new $api_class();
			$controller->register_routes();
		}
	}
}
