<?php
/**
 * An interface for creating a store for retry details.
 *
 */

abstract class AWC_Retry_Store {

	/** @var ActionScheduler_Store */
	private static $store = null;

	/**
	 * Save the details of a retry to the database
	 *
	 * @param AWC_Retry $retry
	 *
	 * @return int ID
	 */
	abstract public function save( AWC_Retry $retry );

	/**
	 * Get the details of a retry from the database
	 *
	 * @param int $retry_id
	 *
	 * @return AWC_Retry
	 */
	abstract public function get_retry( $retry_id );


	/**
	 * Deletes a retry.
	 *
	 * @param int
	 *
	 */
	public function delete_retry( $retry_id ) {
		awc_doing_it_wrong( __FUNCTION__, sprintf( "Method '%s' must be overridden.", __METHOD__ ), '2.4' );
	}

	/**
	 * Get a set of retries from the database
	 *
	 * @param array 
	 * @param string 
	 * @return array An array of AWC_Retry objects or ids.
	 
	 */
	abstract public function get_retries( $args = array(), $return = 'objects' );

	/**
	 * Get the IDs of all retries from the database for a given order
	 *
	 * @param int $order_id
	 *
	 * @return array
	 */
	public function get_retry_ids_for_order( $order_id ) {
		return array_values( $this->get_retries( array(
			'order_id' => $order_id,
			'orderby'  => 'ID',
			'order'    => 'ASC',
		), 'ids' ) );
	}

	/**
	 * Setup the class, if required
	 *
	 * @return null
	 */
	abstract public function init();

	/**
	 * Get the details of all retries (if any) for a given order
	 *
	 * @param int $order_id
	 *
	 * @return array
	 */
	public function get_retries_for_order( $order_id ) {
		return $this->get_retries( array( 'order_id' => $order_id ) );
	}

	/**
	 * Get the details of the last retry (if any) recorded for a given order
	 *
	 * @param int $order_id
	 *
	 * @return AWC_Retry | null
	 */
	public function get_last_retry_for_order( $order_id ) {

		$retry_ids  = $this->get_retry_ids_for_order( $order_id );
		$last_retry = null;

		if ( ! empty( $retry_ids ) ) {
			$last_retry_id = array_pop( $retry_ids );
			$last_retry    = $this->get_retry( $last_retry_id );
		}

		return $last_retry;
	}

	/**
	 * Get the number of retries stored in the database for a given order
	 *
	 * @param int $order_id
	 *
	 * @return int
	 */
	public function get_retry_count_for_order( $order_id ) {

		$retry_post_ids = $this->get_retry_ids_for_order( $order_id );

		return count( $retry_post_ids );
	}
}
