<?php
/**
 * Methods for adding Subscriptions Debug Tools
 *
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

/**
 * AWC_Debug_Tool_Factory Class
 *
 * Add debug tools to the WooCommerce > System Status > Tools page.
 */
final class AWC_Debug_Tool_Factory {

	/**
	 * Add a debug tool for manually managing a data store's cache.
	 *
	 * @param string $tool_type A known type of cache tool. Known types are 'eraser' or 'generator'.
	 * @param string $tool_name The section name given to the tool on the admin screen.
	 * @param string $tool_desc The long description for the tool on the admin screen.
	 * @param AWC_Cache_Updater $data_store
	 * @throws InvalidArgumentException When a class for the given tool is not found.
	 */
	public static function add_cache_tool( $tool_type, $tool_name, $tool_desc, awc_Cache_Updater $data_store ) {
		if ( ! is_admin() && ! defined( 'DOING_CRON' ) && ! defined( 'WP_CLI' ) ) {
			return;
		}

		$tool_class_name = self::get_cache_tool_class_name( $tool_type );
		$tool_key        = self::get_tool_key( $tool_name );
		if ( 'generator' === $tool_type ) {
			$cache_updater = new AWC_Debug_Tool_Cache_Background_Updater( $tool_key, $data_store );
			$tool = new $tool_class_name( $tool_key, $tool_name, $tool_desc, $data_store, $cache_updater );
		} else {
			$tool = new $tool_class_name( $tool_key, $tool_name, $tool_desc, $data_store );
		}

		/** @var awc_Debug_Tool $tool */
		$tool->init();
	}

	/**
	 * Get the string used to identify the tool.
	 *
	 * @param string The name of the cache tool being created
	 * @return string The key used to identify the tool - sanitized name with awc_ prefix.
	 */
	protected static function get_tool_key( $tool_name ) {
		return sprintf( 'awc_%s', str_replace( ' ', '_', strtolower( $tool_name ) ) );
	}

	/**
	 * Get a cache tool's class name by passing in the cache name and type.
	 * For example, get_cache_tool_class_name( 'related-order', 'generator' ) will return AWC_Debug_Tool_Related_Order_Cache_Generator.
	 *
	 *
	 * @param string $cache_tool_type The type of cache tool. Known tools are 'eraser' and 'generator'.
	 * @return string The cache tool's class name.
	 */
	protected static function get_cache_tool_class_name( $cache_tool_type ) {
		$tool_class_name = sprintf( 'AWC_Debug_Tool_Cache_%s', ucfirst( $cache_tool_type ) );

		if ( ! class_exists( $tool_class_name ) ) {
			throw new InvalidArgumentException( sprintf(
				'%s() requires a valid tool name. Class "%s" does not exist.',
				__METHOD__,
				$tool_class_name
			) );
		}

		return $tool_class_name;
	}
}
