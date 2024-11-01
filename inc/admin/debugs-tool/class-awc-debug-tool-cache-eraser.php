<?php
/**
 * AWC_Debug_Tool_Cache_Eraser Class
 *
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

/**
 * AWC_Debug_Tool_Cache_Eraser Class
 *
 */
class AWC_Debug_Tool_Cache_Eraser extends AWC_Debug_Tool_Cache_Updater {

	/**
	 * AWC_Debug_Tool_Cache_Eraser constructor.
	 *
	 * @param string $tool_key The key used to add the tool to the array of available tools.
	 * @param string $tool_name The section name given to the tool on the admin screen.
	 * @param string $tool_description The long description for the tool displayed on the admin screen.
	 * @param AWC_Cache_Updater $data_store The cached data store this tool will use for erasing cache.
	 */
	public function __construct( $tool_key, $tool_name, $tool_description, AWC_Cache_Updater $data_store ) {
		$this->tool_key   = $tool_key;
		$this->data_store = $data_store;
		$this->tool_data  = array(
			'name'     => $tool_name,
			'button'   => $tool_name,
			'desc'     => $tool_description,
			'callback' => array( $this, 'delete_caches' ),
		);
	}

	/**
	 * Clear all of the data store's caches.
	 */
	public function delete_caches() {
		if ( $this->is_data_store_cached() ) {
			$this->data_store->delete_all_caches();
		}
	}
}
