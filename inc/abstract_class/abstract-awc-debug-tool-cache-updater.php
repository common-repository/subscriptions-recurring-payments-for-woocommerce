<?php
/**
 * AWC_Debug_Tool_Cache_Updater Class
 *
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

/**
 * AWC_Debug_Tool_Cache_Updater Class
 *
 * Shared methods for tool on the WooCommerce > System Status > Tools page that need to
 * update a cached data store's cache.
 */
abstract class AWC_Debug_Tool_Cache_Updater extends AWC_Debug_Tool {

	/**
	 * @var mixed $data_Store The store used for updating the cache.
	 */
	protected $data_store;

	/**
	 * Attach callbacks and hooks, if the class's data store is using caching.
	 */
	public function init() {
		if ( $this->is_data_store_cached() ) {
			parent::init();
		}
	}

	/**
	 * Check if the store is a cache updater, and has methods required to erase or generate cache.
	 */
	protected function is_data_store_cached() {
		return is_a( $this->data_store, 'AWC_Cache_Updater' );
	}
}
