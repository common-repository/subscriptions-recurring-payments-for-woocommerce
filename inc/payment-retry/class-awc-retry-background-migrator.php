<?php
/**
 * Retry Background Updater.
 *
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Class AWC_Retry_Background_Migrator.
 *
 * Updates our retries on background.
 
 */
class AWC_Retry_Background_Migrator extends AWC_Background_Upgrader {
	/**
	 * Where we're saving/migrating our data.
	 *
	 * @var AWC_Retry_Store
	 */
	private $destination_store;

	/**
	 * Where the data comes from.
	 *
	 * @var AWC_Retry_Store
	 */
	private $source_store;

	/**
	 * Our migration class.
	 *
	 * @var AWC_Retry_Migrator
	 */
	private $migrator;

	/**
	 * construct.
	 *
	 * @param WC_Logger_Interface $logger The WC_Logger instance.
	 *
	 
	 */
	public function __construct( WC_Logger_Interface $logger ) {
		$this->scheduled_hook = 'awc_retries_migration_hook';
		$this->time_limit     = 30;

		$this->destination_store = AWC_Retry_Stores::get_database_store();
		$this->source_store      = AWC_Retry_Stores::get_post_store();

		$migrator_class = apply_filters( 'awc_retry_retry_migrator_class', 'AWC_Retry_Migrator' );
		$this->migrator = new $migrator_class( $this->source_store, $this->destination_store, new WC_Logger() );

		$this->log_handle = 'asub-retries-background-migrator';
		$this->logger     = $logger;
	}

	/**
	 * Get the items to be updated, if any.
	 *
	 * @return array An array of items to update, or empty array if there are no items to update.
	 
	 */
	protected function get_items_to_update() {
		return $this->source_store->get_retries( array( 'limit' => 10 ) );
	}

	/**
	 * Run the update for a single item.
	 *
	 * @param AWC_Retry $retry The item to update.
	 *
	 * @return int|null
	 
	 */
	protected function update_item( $retry ) {
		try {
			if ( ! is_a( $retry, 'AWC_Retry' ) ) {
				throw new Exception( 'The $retry parameter must be a valid AWC_Retry instance.' );
			}

			$new_item_id = $this->migrator->migrate_entry( $retry->get_id() );

			$this->log( sprintf( 'Payment retry ID: %d, has been migrated to custom table with new ID: %d.', $retry->get_id(), $new_item_id ) );

			return $new_item_id;
		} catch ( Exception $e ) {
			if ( is_object( $retry ) ) {
				$retry_description = get_class( $retry ) . '(id=' . awc_get_objects_property( $retry, 'id' ) . ')';
			} else {
				$retry_description = wp_json_encode( $retry );
			}

			$this->log( sprintf( '--- Exception caught migrating Payment retry %s - exception message: %s ---', $retry_description, $e->getMessage() ) );

			return null;
		}
	}

	/**
	 * Unscheduled the instance's hook in Action Scheduler
	 
	 */
	protected function unschedule_background_updates() {
		parent::unschedule_background_updates();

		$this->migrator->set_needs_migration();
	}
}
