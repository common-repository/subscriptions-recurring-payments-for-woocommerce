<?php
/**
 * WooCommerce Subscriptions API Subscriptions Class
 *
 * Handles requests to the /subscriptions endpoint
 
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

class WC_API_Subscriptions extends WC_API_Orders {

	/* @var string $base the route base */
	protected $base = '/subscriptions';

	/**
	 * Register the routes for this class
	 *
	 * GET|POST /subscriptions
	 * GET /subscriptions/count
	 * GET|PUT|DELETE /subscriptions/<subscription_id>
	 * GET /subscriptions/<subscription_id>/notes
	 * GET /subscriptions/<subscription_id>/notes/<id>
	 * GET /subscriptions/<subscription_id>/orders
	 *
	 
	 * @param array $routes
	 * @return array $routes
	 */
	public function register_routes( $routes ) {

		$this->post_type = 'shop_subscription';

		# GET /subscriptions
		$routes[ $this->base ] = array(
			array( array( $this, 'get_subscriptions' ), WC_API_Server::READABLE ),
			array( array( $this, 'create_subscription' ), WC_API_Server::CREATABLE | WC_API_Server::ACCEPT_DATA ),
		);

		# GET /subscriptions/count
		$routes[ $this->base . '/count' ] = array(
			array( array( $this, 'get_subscription_count' ), WC_API_Server::READABLE ),
		);

		# GET /subscriptions/statuses
		$routes[ $this->base . '/statuses' ] = array(
			array( array( $this, 'get_statuses' ), WC_API_Server::READABLE ),
		);

		# GET|PUT|DELETE /subscriptions/<subscription_id>
		$routes[ $this->base . '/(?P<subscription_id>\d+)' ] = array(
			array( array( $this, 'get_subscription' ), WC_API_Server::READABLE ),
			array( array( $this, 'edit_subscription' ), WC_API_Server::EDITABLE | WC_API_Server::ACCEPT_DATA ),
			array( array( $this, 'delete_subscription' ), WC_API_Server::DELETABLE ),
		);

		# GET /subscriptions/<subscription_id>/notes
		$routes[ $this->base . '/(?P<subscription_id>\d+)/notes' ] = array(
			array( array( $this, 'get_subscription_notes' ), WC_API_Server::READABLE ),
			array( array( $this, 'create_subscription_note' ), WC_API_Server::CREATABLE | WC_API_Server::ACCEPT_DATA ),
		);

		# GET /subscriptions/<subscription_id>/notes/<id>
		$routes[ $this->base . '/(?P<subscription_id>\d+)/notes/(?P<id>\d+)' ] = array(
			array( array( $this, 'get_subscription_note' ), WC_API_Server::READABLE ),
			array( array( $this, 'edit_subscription_note' ), WC_API_SERVER::EDITABLE | WC_API_Server::ACCEPT_DATA ),
			array( array( $this, 'delete_subscription_note' ), WC_API_SERVER::DELETABLE ),
		);

		# GET /subscriptions/<subscription_id>/orders
		$routes[ $this->base . '/(?P<subscription_id>\d+)/orders' ] = array(
			array( array( $this, 'get_all_subscription_orders' ), WC_API_Server::READABLE ),
		);

		return $routes;
	}

	/**
	 * Ensures the statuses are in the correct format and are valid subscription statues.
	 *
	 
	 * @param $status string | array
	 */
	protected function format_statuses( $status = null ) {
		$statuses = 'any';

		if ( ! empty( $status ) ) {
			// get list of statuses and check each on is in the correct format and is valid
			$statuses = explode( ',', $status );

			// attach the wc- prefix to those statuses that have not specified it
			foreach ( $statuses as &$status ) {
				if ( 'wc-' != substr( $status, 0, 3 ) ) {
					$status = 'wc-' . $status;

					if ( ! array_key_exists( $status, awc_get_subscription_statuses() ) ) {
						return new WP_Error( 'awc_api_invalid_subscription_status', __( 'Invalid subscription status given.', 'subscriptions-recurring-payments-for-woocommerce' ) );
					}
				}
			}
		}

		return $statuses;
	}

	/**
	 * Gets all subscriptions
	 *
	 
	 * @param null $fields
	 * @param array $filter
	 * @param null $status
	 * @param null $page
	 * @return array
	 */
	public function get_subscriptions( $fields = null, $filter = array(), $status = null, $page = 1 ) {
		// check user permissions
		if ( ! current_user_can( 'read_private_shop_orders' ) ) {
			return new WP_Error( 'awc_api_user_cannot_read_subscription_count', __( 'You do not have permission to read the subscriptions count', 'subscriptions-recurring-payments-for-woocommerce' ), array( 'status' => 401 ) );
		}

		$status = $this->format_statuses( $status );

		if ( is_wp_error( $status ) ) {
			return $status;
		}

		$filter['page'] = $page;

		$base_args = array(
			'post_status' => $status,
			'post_type'   => 'shop_subscription',
			'fields'      => 'ids',
		);

		$subscriptions = array();
		$query_args    = array_merge( $base_args, $filter );
		$query         = $this->query_orders( $query_args );

		foreach ( $query->posts as $subscription_id ) {

			if ( ! $this->is_readable( $subscription_id ) ) {
				continue;
			}

			$subscriptions[] = current( $this->get_subscription( $subscription_id, $fields, $filter ) );
		}

		$this->server->add_pagination_headers( $query );

		return array( 'subscriptions' => apply_filters( 'awc_api_get_subscriptions_response', $subscriptions, $fields, $filter, $status, $page, $this->server ) );
	}

	/**
	 * Creating Subscription.
	 *
	 
	 * @param array data raw order data
	 * @return array
	 */
	public function create_subscription( $data ) {

		$data = isset( $data['subscription'] ) ? $data['subscription'] : array();

		try {

			if ( ! current_user_can( 'publish_shop_orders' ) ) {
				throw new WC_API_Exception( 'awc_api_user_cannot_create_subscription', __( 'You do not have permission to create subscriptions', 'subscriptions-recurring-payments-for-woocommerce' ), 401 );
			}

			$data['order'] = $data;

			remove_filter( 'woocommerce_api_order_response', array( WC()->api->WC_API_Customers, 'add_customer_data' ), 10 );

			$subscription = $this->create_order( $data );

			add_filter( 'woocommerce_api_order_response', array( WC()->api->WC_API_Customers, 'add_customer_data' ), 10, 2 );

			unset( $data['order'] );

			if ( is_wp_error( $subscription ) ) {
				$data = $subscription->get_error_data();
				throw new WC_API_Exception( $subscription->get_error_code(), $subscription->get_error_message(), $data['status'] );
			}

			$subscription = awc_get_subscription( $subscription['order']['id'] );
			unset( $data['billing_period'] );
			unset( $data['billing_interval'] );

			$this->update_schedule( $subscription, $data );

			// allow order total to be manually set, especially for those cases where there's no line items added to the subscription
			if ( isset( $data['order_total'] ) ) {
				$subscription->set_total( wc_format_decimal( $data['order_total'], get_option( 'woocommerce_price_num_decimals' ) ) );
			}

			if ( isset( $data['payment_details'] ) && is_array( $data['payment_details'] ) ) {
				$this->update_payment_method( $subscription, $data['payment_details'], false );
			}

			$subscription->save();

			do_action( 'awc_api_subscription_created', $subscription->get_id(), $this );

			return array( 'creating_subscription' => $this->get_subscription( $subscription->get_id() ) );

		} catch ( WC_API_Exception $e ) {
			return new WP_Error( $e->getErrorCode(), $e->getMessage(), array( 'status' => $e->getCode() ) );
		} catch ( Exception $e ) {
			$response = array(
				'error',
				array(
					'awc_api_error_create_subscription' => array(
						'message' => $e->getMessage(),
						'status'  => $e->getCode(),
					),
				),
			);

			// show the subscription in response if it was still created but errored.
			if ( ! empty( $subscription ) && ! is_wp_error( $subscription ) ) {
				$response['creating_subscription'] = $this->get_subscription( $subscription->get_id() );
			}

			return $response;
		}
	}

	/**
	 * Edit Subscription
	 *
	 
	 * @return array
	 */
	public function edit_subscription( $subscription_id, $data, $fields = null ) {
		$data = apply_filters( 'awc_api_edit_subscription_data', isset( $data['subscription'] ) ? $data['subscription'] : array(), $subscription_id, $fields );

		try {

			$subscription_id = $this->validate_request( $subscription_id, $this->post_type, 'edit' );

			if ( is_wp_error( $subscription_id ) ) {
				throw new WC_API_Exception( 'awc_api_cannot_edit_subscription', __( 'The requested subscription cannot be edited.', 'subscriptions-recurring-payments-for-woocommerce' ), 400 );
			}

			$subscription = awc_get_subscription( $subscription_id );

			if ( isset( $data['payment_details'] ) && is_array( $data['payment_details'] ) ) {

				if ( empty( $data['payment_details']['method_id'] ) || 'manual' == $data['payment_details']['method_id'] ) {
					$subscription->set_payment_method( '' );
				} else {
					$this->update_payment_method( $subscription, $data['payment_details'], true );
				}
			}

			if ( ! empty( $data['order_id'] ) ) {
				$subscription->set_parent_id( $data['order_id'] );
			}

			// set $data['order'] = $data['subscription'] so that edit_order can read in the request
			$data['order'] = $data;
			// edit subscription by calling WC_API_Orders::edit_order()
			$edited = $this->edit_order( $subscription_id, $data, $fields );
			// remove part of the array that isn't being used
			unset( $data['order'] );

			if ( is_wp_error( $edited ) ) {
				$data = $edited->get_error_data();
				// translators: placeholder is error message
				throw new WC_API_Exception( 'awc_api_cannot_edit_subscription', sprintf( _x( 'Edit subscription failed with error: %s', 'API error message when editing the order failed', 'subscriptions-recurring-payments-for-woocommerce' ), $edited->get_error_message() ), $data['status'] );
			}

			$this->update_schedule( $subscription, $data );

			$subscription->save();

			do_action( 'awc_api_subscription_updated', $subscription_id, $data, $this );

			return $this->get_subscription( $subscription_id );

		} catch ( WC_API_Exception $e ) {
			return new WP_Error( $e->getErrorCode(), $e->getMessage(), array( 'status' => $e->getCode() ) );

		} catch ( Exception $e ) {
			return new WP_Error( 'awc_api_cannot_edit_subscription', $e->getMessage(), array( 'status' => $e->getCode() ) );

		}

	}

	/**
	 * Setup the new payment information to call AWC_Subscription::set_payment_method()
	 *
	 * @param $subscription WC_Subscription
	 * @param $payment_details array payment data from api request
	 
	 */
	public function update_payment_method( $subscription, $payment_details, $updating ) {
		global $wpdb;

		$payment_gateways = WC()->payment_gateways->get_available_payment_gateways();
		$payment_method   = ( ! empty( $payment_details['method_id'] ) ) ? $payment_details['method_id'] : 'manual';
		$payment_gateway  = ( isset( $payment_gateways[ $payment_details['method_id'] ] ) ) ? $payment_gateways[ $payment_details['method_id'] ] : '';

		try {
			$wpdb->query( 'START TRANSACTION' );

			if ( $updating && ! array_key_exists( $payment_method, awc_Change_Payment_Method_Admin::get_valid_payment_methods( $subscription ) ) ) {
				throw new Exception( 'awc_api_edit_subscription_error', __( 'Gateway does not support admin changing the payment method on a Subscription.', 'subscriptions-recurring-payments-for-woocommerce' ) );
			}

			$payment_method_meta = apply_filters( 'woocommerce_subscription_payment_meta', array(), $subscription );

			if ( ! empty( $payment_gateway ) && isset( $payment_method_meta[ $payment_gateway->id ] ) ) {
				$payment_method_meta = $payment_method_meta[ $payment_gateway->id ];

				if ( ! empty( $payment_method_meta ) ) {

					foreach ( $payment_method_meta as $meta_table => $meta ) {

						if ( ! is_array( $meta ) ) {
							continue;
						}

						foreach ( $meta as $meta_key => $meta_data ) {

							if ( isset( $payment_details[ $meta_table ][ $meta_key ] ) ) {
								$payment_method_meta[ $meta_table ][ $meta_key ]['value'] = $payment_details[ $meta_table ][ $meta_key ];
							}
						}
					}
				}
			}

			if ( '' == $subscription->get_payment_method() ) {
				$subscription->set_payment_method( $payment_gateway );
			}

			$subscription->set_payment_method( $payment_gateway, $payment_method_meta );

			$wpdb->query( 'COMMIT' );

		} catch ( Exception $e ) {
			$wpdb->query( 'ROLLBACK' );

			// translators: 1$: gateway id, 2$: error message
			throw new Exception( sprintf( __( 'Subscription payment method could not be set to %1$s and has been set to manual with error message: %2$s', 'subscriptions-recurring-payments-for-woocommerce' ), ( ! empty( $payment_gateway->id ) ) ? $payment_gateway->id : 'manual', $e->getMessage() ) );
		}
	}

	/**
	 * Override WC_API_Order::create_base_order() to create a subscription
	 * instead of a WC_Order when calling WC_API_Order::create_order().
	 *
	 
	 * @param $array
	 * @return WC_Subscription
	 */
	protected function create_base_order( $args, $data ) {

		$args['order_id']         = ( ! empty( $data['order_id'] ) ) ? $data['order_id'] : '';
		$args['billing_interval'] = ( ! empty( $data['billing_interval'] ) ) ? $data['billing_interval'] : '';
		$args['billing_period']   = ( ! empty( $data['billing_period'] ) ) ? $data['billing_period'] : '';

		return awc_create_subscription( $args );
	}

	/**
	 * Update all subscription specific meta (i.e. Billing interval/period and date fields )
	 *
	 
	 * @param $data array
	 * @param $subscription WC_Subscription
	 */
	protected function update_schedule( $subscription, $data ) {

		if ( isset( $data['billing_interval'] ) ) {

			$interval = absint( $data['billing_interval'] );

			if ( 0 == $interval ) {
				throw new WC_API_Exception( 'awc_api_invalid_subscription_meta', __( 'Invalid subscription billing interval given. Must be an integer greater than 0.', 'subscriptions-recurring-payments-for-woocommerce' ), 400 );
			}

			$subscription->set_billing_interval( $interval );
		}

		if ( ! empty( $data['billing_period'] ) ) {

			$period = strtolower( $data['billing_period'] );

			if ( ! in_array( $period, array_keys( awc_get_subscription_period_strings() ) ) ) {
				throw new WC_API_Exception( 'awc_api_invalid_subscription_meta', __( 'Invalid subscription billing period given.', 'subscriptions-recurring-payments-for-woocommerce' ), 400 );
			}

			$subscription->set_billing_period( $period );
		}

		$dates_to_update = array();

		foreach ( array( 'start', 'trial_end', 'end', 'next_payment' ) as $date_type ) {
			if ( isset( $data[ $date_type . '_date' ] ) ) {
				$dates_to_update[ $date_type ] = $data[ $date_type . '_date' ];
			}
		}

		if ( ! empty( $dates_to_update ) ) {
			$subscription->update_dates( $dates_to_update );
		}

	}

	/**
	 * Delete subscription
	 *
	 
	 */
	public function delete_subscription( $subscription_id, $force = false ) {

		$subscription_id = $this->validate_request( $subscription_id, $this->post_type, 'delete' );

		if ( is_wp_error( $subscription_id ) ) {
			return $subscription_id;
		}

		wc_delete_shop_order_transients( $subscription_id );

		do_action( 'woocommerce_api_delete_subscription', $subscription_id, $this );

		return $this->delete( $subscription_id, 'subscription', ( 'true' === $force ) );
	}

	/**
	 * Retrieves the subscription by the given id.
	 *
	 * Called by: /subscriptions/<subscription_id>
	 *
	 
	 * @param int $subscription_id
	 * @param array $fields
	 * @param array $filter
	 * @return array
	 */
	public function get_subscription( $subscription_id, $fields = null, $filter = array() ) {

		$subscription_id = $this->validate_request( $subscription_id, $this->post_type, 'read' );

		if ( is_wp_error( $subscription_id ) ) {
			return $subscription_id;
		}

		$subscription      = awc_get_subscription( $subscription_id );
		$order_data        = $this->get_order( $subscription_id );
		$subscription_data = $order_data['order'];

		// Not all order meta relates to a subscription (a subscription doesn't "complete")
		if ( isset( $subscription_data['completed_at'] ) ) {
			unset( $subscription_data['completed_at'] );
		}

		$subscription_data['billing_schedule'] = array(
			'period'          => $subscription->get_billing_period(),
			'interval'        => $subscription->get_billing_interval(),
			'start_at'        => $this->get_formatted_datetime( $subscription, 'start' ),
			'trial_end_at'    => $this->get_formatted_datetime( $subscription, 'trial_end' ),
			'next_payment_at' => $this->get_formatted_datetime( $subscription, 'next_payment' ),
			'end_at'          => $this->get_formatted_datetime( $subscription, 'end' ),
		);

		if ( $subscription->get_parent_id() ) {
			$subscription_data['parent_order_id'] = $subscription->get_parent_id();
		} else {
			$subscription_data['parent_order_id'] = array();
		}

		return array( 'subscription' => apply_filters( 'awc_api_get_subscription_response', $subscription_data, $fields, $filter, $this->server ) );
	}

	/**
	 * Returns a list of all the available subscription statuses.
	 *
	 * @see awc_get_subscription_statuses() in asub-functions.php
	 
	 * @return array
	 *
	 */
	public function get_statuses() {
		return array( 'subscription_statuses' => awc_get_subscription_statuses() );
	}

	/**
	 * Get the total number of subscriptions
	 *
	 * Called by: /subscriptions/count
	 
	 * @param $status string
	 * @param $filter array
	 * @return int | WP_Error
	 */
	public function get_subscription_count( $status = null, $filter = array() ) {
		return $this->get_orders_count( $status, $filter );
	}

	/**
	 * Returns all the notes tied to the subscription
	 *
	 * Called by: subscription/<subscription_id>/notes
	 
	 * @param $subscription_id
	 * @param $fields
	 * @return WP_Error|array
	 */
	public function get_subscription_notes( $subscription_id, $fields = null ) {

		$notes = $this->get_order_notes( $subscription_id, $fields );

		if ( is_wp_error( $notes ) ) {
			return $notes;
		}

		return array( 'subscription_notes' => apply_filters( 'awc_api_subscription_notes_response', $notes['order_notes'], $subscription_id, $fields ) );
	}

	/**
	 * Get information about a subscription note.
	 *
	 
	 * @param int $subscription_id
	 * @param int $id
	 * @param array $fields
	 *
	 * @return array Subscription note
	 */
	public function get_subscription_note( $subscription_id, $id, $fields = null ) {

		$note = $this->get_order_note( $subscription_id, $id, $fields );

		if ( is_wp_error( $note ) ) {
			return $note;
		}

		return array( 'subscription_note' => apply_filters( 'awc_api_subscription_note_response', $note['order_note'], $subscription_id, $id, $fields ) );

	}

	/**
	 * Get information about a subscription note.
	 *
	 * @param int $subscription_id
	 * @param int $id
	 * @param array $fields
	 *
	 * @return WP_Error|array Subscription note
	 */
	public function create_subscription_note( $subscription_id, $data ) {

		$note = $this->create_order_note( $subscription_id, $data );

		if ( is_wp_error( $note ) ) {
			return $note;
		}

		do_action( 'awc_api_created_subscription_note', $subscription_id, $note['order_note'], $this );

		return array( 'subscription_note' => $note['order_note'] );
	}

	/**
	 * Verify and edit subscription note.
	 *
	 
	 * @param int $subscription_id
	 * @param int $id
	 *
	 * @return WP_Error|array Subscription note edited
	 */
	public function edit_subscription_note( $subscription_id, $id, $data ) {

		$note = $this->edit_order_note( $subscription_id, $id, $data );

		if ( is_wp_error( $note ) ) {
			return $note;
		}

		do_action( 'awc_api_edit_subscription_note', $subscription_id, $id, $note['order_note'], $this );

		return array( 'subscription_note' => $note['order_note'] );
	}

	/**
	 * Verify and delete subscription note.
	 *
	 
	 * @param int $subscription_id
	 * @param int $id
	 * @return WP_Error|array deleted subscription note status
	 */
	public function delete_subscription_note( $subscription_id, $id ) {

		$deleted_note = $this->delete_order_note( $subscription_id, $id );

		if ( is_wp_error( $deleted_note ) ) {
			return $deleted_note;
		}

		do_action( 'awc_api_subscription_note_status', $subscription_id, $id, $this );

		return array( 'message' => _x( 'Permanently deleted subscription note', 'API response confirming order note deleted from a subscription', 'subscriptions-recurring-payments-for-woocommerce' ) );

	}

	/**
	 * Get information about the initial order and renewal orders of a subscription.
	 *
	 * Called by: /subscriptions/<subscription_id>/orders
	 
	 * @param $subscription_id
	 * @param $fields
	 */
	public function get_all_subscription_orders( $subscription_id, $filters = null ) {

		$subscription_id = $this->validate_request( $subscription_id, $this->post_type, 'read' );

		if ( is_wp_error( $subscription_id ) ) {
			return $subscription_id;
		}

		$subscription = awc_get_subscription( $subscription_id );

		$subscription_orders = $subscription->get_related_orders();

		$formatted_orders = array();

		if ( ! empty( $subscription_orders ) ) {

			// set post_type back to shop order so that get_orders doesn't try return a subscription.
			$this->post_type = 'shop_order';

			foreach ( $subscription_orders as $order_id ) {
				$formatted_orders[] = $this->get_order( $order_id );
			}

			$this->post_type = 'shop_subscription';

		}

		return array( 'subscription_orders' => apply_filters( 'awc_api_subscription_orders_response', $formatted_orders, $subscription_id, $filters, $this->server ) );
	}

	/**
	 * Get a certain date for a subscription, if it exists, formatted for return
	 *
	 
	 * @param $subscription
	 * @param $date_type
	 */
	protected function get_formatted_datetime( $subscription, $date_type ) {

		$timestamp = $subscription->get_time( $date_type );

		if ( $timestamp > 0 ) {
			$formatted_datetime = $this->server->format_datetime( $timestamp );
		} else {
			$formatted_datetime = '';
		}

		return $formatted_datetime;
	}

	/**
	 * Helper method to get order post objects
	 *
	 * We need to override WC_API_Orders::query_orders() because it uses wc_get_order_statuses()
	 * for the query, but subscriptions use the values returned by awc_get_subscription_statuses().
	 *
	 
	 * @param array $args request arguments for filtering query
	 * @return WP_Query
	 */
	protected function query_orders( $args ) {

		// set base query arguments
		$query_args = array(
			'fields'      => 'ids',
			'post_type'   => $this->post_type,
			'post_status' => array_keys( awc_get_subscription_statuses() ),
		);

		// add status argument
		if ( ! empty( $args['status'] ) ) {

			$statuses                  = 'wc-' . str_replace( ',', ',wc-', $args['status'] );
			$statuses                  = explode( ',', $statuses );
			$query_args['post_status'] = $statuses;

			unset( $args['status'] );
		}

		if ( ! empty( $args['customer_id'] ) ) {
			$query_args['meta_query'] = array(
				array(
					'key'     => '_customer_user',
					'value'   => absint( $args['customer_id'] ),
					'compare' => '=',
				),
			);
		}

		$query_args = $this->merge_query_args( $query_args, $args );

		return new WP_Query( $query_args );
	}

}
