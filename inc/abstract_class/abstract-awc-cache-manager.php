<?php
/**
 * Abstract Subscription Cache Manager Class
 *
 * Implements methods to deal with the soft caching layer
 *
 */
abstract class AWC_Cache_Manager {

	final public static function get_instance() {
		/**
		 * Modeled after WP_Session_Tokens
		 */
		$manager = apply_filters( 'awc_cache_manager_class', 'AWC_Cached_Data_Manager' );
		return new $manager;
	}

	/**
	 * AWC_Cache_Manager constructor.
	 *
	 * Loads the logger if it's not overwritten.
	 */
	abstract function __construct();

	/**
	 * Initialises some form of logger
	 */
	abstract public function load_logger();

	/**
	 * This method should implement adding to the log file
	 * @return mixed
	 */
	abstract public function log( $message );

	/**
	 * Caches and returns data. Implementation can vary by classes.
	 *
	 * @return mixed
	 */
	abstract public function awc_cache_and_get( $key, $callback, $params = array(), $expires = WEEK_IN_SECONDS );

	/**
	 * Deletes a cached version of data.
	 *
	 * @return mixed
	 */
	abstract public function delete_cached( $key );
}
