<?php
/**
 * Retry migration class.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

class AWC_Retry_Migrator extends AWC_Migrator {
	/**
	 * @var AWC_Retry_Store
	 */
	protected $source_store;

	/**
	 * @var AWC_Retry_Store
	 */
	protected $destination_store;

	/**
	 * @var string
	 */
	protected $log_handle = 'asub-retry-migrator';

	/**
	 * @var string
	 */
	static protected $needs_migration_option_name = 'awc_payment_retry_needs_migration';

	/**
	 * Should this retry be migrated.
	 *
	 * @param int $retry_id
	 *
	 * @return bool
	 
	 */
	public function should_migrate_entry( $retry_id ) {
		return ! $this->destination_store->get_retry( $retry_id );
	}

	/**
	 * Gets the item from the source store.
	 *
	 * @param int $entry_id
	 *
	 * @return AWC_Retry
	 
	 */
	public function get_source_store_entry( $entry_id ) {
		return $this->source_store->get_retry( $entry_id );
	}

	/**
	 * save the item to the destination store.
	 *
	 * @param int $entry_id
	 *
	 * @return mixed
	 
	 */
	public function save_destination_store_entry( $entry_id ) {
		$source_retry = $this->get_source_store_entry( $entry_id );

		return $this->destination_store->save( $source_retry );
	}

	/**
	 * deletes the item from the source store.
	 *
	 * @param int $entry_id
	 *
	 * @return bool
	 
	 */
	public function delete_source_store_entry( $entry_id ) {
		return $this->source_store->delete_retry( $entry_id );
	}

	/**
	 * Add a message to the log
	 *
	 * @param int $old_retry_id Old retry id.
	 * @param int $new_retry_id New retry id.
	 */
	protected function migrated_entry( $old_retry_id, $new_retry_id ) {
		$this->log( sprintf( 'Retry ID %d migrated to %s with ID %d.', $old_retry_id, AWC_Retry_Stores::get_database_store()->get_full_table_name(), $new_retry_id ) );
	}

	/**
	 * If options exists, we need to run migration.
	 *
	 
	 * @return bool
	 */
	public static function needs_migration() {
		return apply_filters( self::$needs_migration_option_name, ( 'true' === get_option( self::$needs_migration_option_name ) ) );
	}

	/**
	 * Sets needs migration option.
	 *
	 
	 */
	public static function set_needs_migration() {
		if ( AWC_Retry_Stores::get_post_store()->get_retries( array( 'limit' => 1 ), 'ids' ) ) {
			update_option( self::$needs_migration_option_name, 'true' );
		} else {
			delete_option( self::$needs_migration_option_name );
		}
	}
}

