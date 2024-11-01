<?php
/**
 * Stores facade.
 *
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

class AWC_Retry_Stores {
	/**
	 * Where we're saving/migrating our data.
	 *
	 * @var AWC_Retry_Store
	 */
	private static $database_store;

	/**
	 * @access	private
	 * @var AWC_Retry_Store
	 */
	private static $post_store;


	/**
	 *
	 * @return AWC_Retry_Store 
	 */
	public static function get_database_store() {
		if ( empty( self::$database_store ) ) {
			$class                = self::get_database_store_class();
			self::$database_store = new $class();
			self::$database_store->init();
		}

		return self::$database_store;
	}

	/**
	 * Get the class used for instantiating retry storage via self::destination_store()
	 *
	 * @return string
	 
	 */
	public static function get_database_store_class() {
		return apply_filters( 'awc_retry_database_store_class', 'AWC_Retry_Database_Store' );
	}

	/**
	 * Access the object used to interface with the source store.
	 *
	 * @return AWC_Retry_Store
	 
	 */
	public static function get_post_store() {
		if ( empty( self::$post_store ) ) {
			$class            = self::get_post_store_class();
			self::$post_store = new $class();
			self::$post_store->init();
		}

		return self::$post_store;
	}

	/**
	 * Get the class used for instantiating retry storage via self::source_store()
	 *
	 * @return string
	 
	 */
	public static function get_post_store_class() {
		return apply_filters( 'awc_retry_post_store_class', 'AWC_Retry_Post_Store' );
	}
}
