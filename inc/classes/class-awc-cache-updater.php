<?php
/**
 * awc_Cache_Updater Interface
 *
 * Define methods that can be reliably used to update a cache on an object.
 *
 
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

/**
 * AWC_Cache_Updater Interface
 *
 * Define a set of methods that can be used to update a cache
 */
interface AWC_Cache_Updater {

	/**
	 * Get the items to be updated, if any.
	 *
	 * @return array An array of items to update, or empty array if there are no items to update.
	 */
	public function get_items_to_update();

	/**
	 * Update for a single item, of the form returned by get_items_to_update().
	 *
	 * @param mixed $item The item to update.
	 */
	public function update_items_cache( $item );

	/**
	 * Clear all caches for all items.
	 */
	public function delete_all_caches();
}
