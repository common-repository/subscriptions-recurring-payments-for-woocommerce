<?php

/**
 * Class ActionScheduler_Store_Deprecated
 * @codeCoverageIgnore
 */
abstract class ActionScheduler_Store_Deprecated {

	/**
	 * Mark an action that failed to fetch correctly as failed.
	 *
	 
	 *
	 * @param int $action_id The ID of the action.
	 */
	public function mark_failed_fetch_action( $action_id ) {
		self::$store->mark_failure( $action_id );
	}

	/**
	 * Add base hooks
	 *
	 */
	protected static function hook() {}

	/**
	 * Remove base hooks
	 *
	 */
	protected static function unhook() {}

	/**
	 * Get the site's local time.
	 *
	 * 
	 * @return DateTimeZone
	 */
	protected function get_local_timezone() {
		return ActionScheduler_TimezoneHelper::get_local_timezone();
	}
}
