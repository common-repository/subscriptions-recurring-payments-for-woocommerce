<?php
/**
 * AWC_Background_Upgrader Class
 *
 * Provide APIs for an upgrade script to update data in the background using Action Scheduler.
 *
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class AWC_Background_Upgrader extends AWC_Background_Updater {

	/**
	 * WC Logger instance for logging messages.
	 *
	 * @var WC_Logger_Interface
	 */
	protected $logger;

	/**
	 * @var string The log file handle to write messages to.
	 */
	protected $log_handle;

	/**
	 * Schedule the @see $this->scheduled_hook action to start repairing subscriptions in
	 * @see $this->time_limit seconds (60 seconds by default).
	 *
	 */
	public function schedule_repair() {
		$this->schedule_background_update();
	}

	/**
	 * Add a message to the asub-upgrade-subscriptions-paypal-suspended log
	 *
	 * @param string $message The message to be logged
	 */
	protected function log( $message ) {
		$this->logger->add( $this->log_handle, $message );
	}
}
