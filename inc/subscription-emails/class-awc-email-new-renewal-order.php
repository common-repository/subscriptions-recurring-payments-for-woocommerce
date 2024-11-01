<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}
/**
 * New Order Email
 *
 * An email sent to the admin when a new order is received/paid for.
 *
 */
class AWC_Email_New_Renewal_Order extends WC_Email_New_Order {

	/**
	 * Constructor
	 */
	function __construct() {

		$this->id             = 'new_renewal_order';
		$this->title          = __( 'New Renewal Order', 'subscriptions-recurring-payments-for-woocommerce' );
		$this->description    = __( 'New renewal order emails are sent when a subscription renewal payment is processed.', 'subscriptions-recurring-payments-for-woocommerce' );

		$this->heading        = __( 'New subscription renewal order', 'subscriptions-recurring-payments-for-woocommerce' );
		$this->subject        = __( '[{blogname}] New subscription renewal order ({order_number}) - {order_date}', 'subscriptions-recurring-payments-for-woocommerce' );

		$this->template_html  = 'emails/admin-new-renewal-order.php';
		$this->template_plain = 'emails/text/admin-new-renewal-order.php';
		$this->template_base  = plugin_dir_path( AWC_Subscriptions::$plugin_file ) . 'temp/';

		// Triggers for this email
		add_action( 'woocommerce_order_status_pending_to_processing_renewal_notification', array( $this, 'trigger' ) );
		add_action( 'woocommerce_order_status_pending_to_completed_renewal_notification', array( $this, 'trigger' ) );
		add_action( 'woocommerce_order_status_pending_to_on-hold_renewal_notification', array( $this, 'trigger' ) );
		add_action( 'woocommerce_order_status_failed_to_processing_renewal_notification', array( $this, 'trigger' ) );
		add_action( 'woocommerce_order_status_failed_to_completed_renewal_notification', array( $this, 'trigger' ) );
		add_action( 'woocommerce_order_status_failed_to_on-hold_renewal_notification', array( $this, 'trigger' ) );
		add_action( 'woocommerce_order_status_cancelled_to_processing_renewal_notification', array( $this, 'trigger' ) );
		add_action( 'woocommerce_order_status_cancelled_to_completed_renewal_notification', array( $this, 'trigger' ) );
		add_action( 'woocommerce_order_status_cancelled_to_on-hold_renewal_notification', array( $this, 'trigger' ) );

		// We want all the parent's methods, with none of its properties, so call its parent's constructor, rather than my parent constructor
		WC_Email::__construct();

		// Other settings
		$this->recipient = $this->get_option( 'recipient' );

		if ( ! $this->recipient ) {
			$this->recipient = get_option( 'admin_email' );
		}
	}

	/**
	 * Get the default e-mail subject.
	 *
	 
	 * @return string
	 */
	public function get_default_subject() {
		return $this->subject;
	}

	/**
	 * Get the default e-mail heading.
	 *
	 
	 * @return string
	 */
	public function get_default_heading() {
		return $this->heading;
	}

	/**
	 * trigger function.
	 *
	 * We need to override WC_Email_New_Order's trigger method because it expects to be run only once
	 * per request (but multiple subscription renewal orders can be generated per request).
	 *
	 * @access public
	 * @return void
	 */
	function trigger( $order_id, $order = null ) {

		if ( $order_id ) {
			$this->object = new WC_Order( $order_id );

			$order_date_index = array_search( '{order_date}', $this->find );
			if ( false === $order_date_index ) {
				$this->find['order-date']    = '{order_date}';
				$this->replace['order-date'] = awc_format_datetime( awc_get_objects_property( $this->object, 'date_created' ) );
			} else {
				$this->replace[ $order_date_index ] = awc_format_datetime( awc_get_objects_property( $this->object, 'date_created' ) );
			}

			$order_number_index = array_search( '{order_number}', $this->find );
			if ( false === $order_number_index ) {
				$this->find['order-number']    = '{order_number}';
				$this->replace['order-number'] = $this->object->get_order_number();
			} else {
				$this->replace[ $order_number_index ] = $this->object->get_order_number();
			}
		}

		if ( ! $this->is_enabled() || ! $this->get_recipient() ) {
			return;
		}

		$this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
	}

	/**
	 * get_content_html function.
	 *
	 * @access public
	 * @return string
	 */
	function get_content_html() {
		return wc_get_template_html(
			$this->template_html,
			array(
				'order'              => $this->object,
				'email_heading'      => $this->get_heading(),
				'additional_content' => is_callable( array( $this, 'get_additional_content' ) ) ? $this->get_additional_content() : '', // WC 3.7 introduced an additional content field for all emails.
				'sent_to_admin'      => true,
				'plain_text'         => false,
				'email'              => $this,
			),
			'',
			$this->template_base
		);
	}

	/**
	 * get_content_plain function.
	 *
	 * @access public
	 * @return string
	 */
	function get_content_plain() {
		return wc_get_template_html(
			$this->template_plain,
			array(
				'order'              => $this->object,
				'email_heading'      => $this->get_heading(),
				'additional_content' => is_callable( array( $this, 'get_additional_content' ) ) ? $this->get_additional_content() : '', // WC 3.7 introduced an additional content field for all emails.
				'sent_to_admin'      => true,
				'plain_text'         => true,
				'email'              => $this,
			),
			'',
			$this->template_base
		);
	}
}
