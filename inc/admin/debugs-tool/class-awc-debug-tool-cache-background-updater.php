<?php
/**
 * Debug Tool with methods to update cached data in the background
 *
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

/**
 * AWC_Debug_Tool_Cache_Background_Updater Class
 *
 * Provide APIs for a debug tool to update a cached data store's data in the background using Action Scheduler.
 */
class AWC_Debug_Tool_Cache_Background_Updater extends AWC_Background_Updater {

	/**
	 * @var AWC_Cache_Updater The data store used to manage the cache.
	 */
	protected $data_store;

	/**
	 * AWC_Debug_Tool_Cache_Background_Updater constructor.
	 *
	 * @param string $scheduled_hook The hook to schedule to run the update.
	 * @param AWC_Cache_Updater $data_store
	 */
	public function __construct( $scheduled_hook, AWC_Cache_Updater $data_store ) {
		$this->scheduled_hook = $scheduled_hook;
		$this->data_store     = $data_store;
	}

	/**
	 * Get the items to be updated, if any.
	 *
	 * @return array An array of items to update, or empty array if there are no items to update.
	 */
	protected function get_items_to_update() {
		return $this->data_store->get_items_to_update();
	}

	/**
	 * Run the update for a single item.
	 *
	 * @param mixed $item The item to update.
	 */
	protected function update_item( $item ) {
		return $this->data_store->update_items_cache( $item );
	}
}
