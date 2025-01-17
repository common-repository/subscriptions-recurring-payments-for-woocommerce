<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}
/**
 * Customer Invoice
 *
 * sent ‍a mail to the customer via admin.
 *
 */
class AWC_Email_Customer_Renewal_Invoice extends WC_Email_Customer_Invoice {

	/**
	 * Strings to find in subjects/headings.
	 * @var array
	 */
	public $find = array();

	/**
	 * Strings to replace in subjects/headings.
	 * @var array
	 */
	public $replace = array();

	var $subject_paid = null;
	var $heading_paid = null;

	/**
	 * Constructor
	 */
	function __construct() {

		$this->id             = 'customer_renewal_invoice';
		$this->title          = __( 'Customer Renewal Invoice', 'subscriptions-recurring-payments-for-woocommerce' );
		$this->description    = __( 'Sent to a customer when the subscription is due for renewal and the renewal requires a manual payment, either because it uses manual renewals or the automatic recurring payment failed for the initial attempt and all automatic retries (if any). The email contains renewal order information and payment links.', 'subscriptions-recurring-payments-for-woocommerce' );
		$this->customer_email = true;

		$this->template_html  = 'emails/customer-renewal-invoice.php';
		$this->template_plain = 'emails/text/customer-renewal-invoice.php';
		$this->template_base  = plugin_dir_path( AWC_Subscriptions::$plugin_file ) . 'temp/';

		$this->subject        = __( 'Invoice for renewal order {order_number} from {order_date}', 'subscriptions-recurring-payments-for-woocommerce' );
		$this->heading        = __( 'Invoice for renewal order {order_number}', 'subscriptions-recurring-payments-for-woocommerce' );

		// Triggers for this email
		add_action( 'woocommerce_generated_manual_renewal_order_renewal_notification', array( $this, 'trigger' ) );
		add_action( 'woocommerce_order_status_failed_renewal_notification', array( $this, 'trigger' ) );

		// We want all the parent's methods, with none of its properties, so call its parent's constructor, rather than my parent constructor
		WC_Email::__construct();
	}

	/**
	 * Get the default e-mail subject.
	 *
	 * @param bool $paid Whether the order has been paid or not.
	 
	 * @return string
	 */
	public function get_default_subject( $paid = false ) {
		return $this->subject;
	}

	/**
	 * Get the default e-mail heading.
	 *
	 * @param bool $paid Whether the order has been paid or not.
	 
	 * @return string
	 */
	public function get_default_heading( $paid = false ) {
		return $this->heading;
	}

	/**
	 * trigger function.
	 *
	 * We need to override WC_Email_Customer_Invoice's trigger method because it expects to be run only once
	 * per request (but multiple subscription renewal orders can be generated per request).
	 *
	 * @access public
	 * @return void
	 */
	function trigger( $order_id, $order = null ) {

		if ( $order_id && ! is_a( $order, 'WC_Order' ) ) {
			$order = wc_get_order( $order_id );
		}

		if ( is_a( $order, 'WC_Order' ) ) {
			$this->object    = $order;
			$this->recipient = awc_get_objects_property( $this->object, 'billing_email' );

			$order_date_index = array_search( '{order_date}', $this->find );
			if ( false === $order_date_index ) {
				$this->find['order_date']    = '{order_date}';
				$this->replace['order_date'] = awc_format_datetime( awc_get_objects_property( $this->object, 'date_created' ) );
			} else {
				$this->replace[ $order_date_index ] = awc_format_datetime( awc_get_objects_property( $this->object, 'date_created' ) );
			}

			$order_number_index = array_search( '{order_number}', $this->find );
			if ( false === $order_number_index ) {
				$this->find['order_number']    = '{order_number}';
				$this->replace['order_number'] = $this->object->get_order_number();
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
	 * get_subject function.
	 *
	 * @access public
	 * @return string
	 */
	function get_subject() {
		return apply_filters( 'woocommerce_subscriptions_email_subject_new_renewal_order', parent::get_subject(), $this->object );
	}

	/**
	 * get_heading function.
	 *
	 * @access public
	 * @return string
	 */
	function get_heading() {
		return apply_filters( 'woocommerce_email_heading_customer_renewal_order', parent::get_heading(), $this->object );
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
				'sent_to_admin'      => false,
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
				'sent_to_admin'      => false,
				'plain_text'         => true,
				'email'              => $this,
			),
			'',
			$this->template_base
		);
	}

	/**
	 * Initialise Settings Form Fields, but add an enable/disable field
	 * to this email as WC doesn't include that for customer Invoices.
	 *
	 * @access public
	 * @return void
	 */
	function init_form_fields() {

		parent::init_form_fields();

		if ( isset( $this->form_fields['heading_paid'] ) ) {
			unset( $this->form_fields['heading_paid'] );
		}

		if ( isset( $this->form_fields['subject_paid'] ) ) {
			unset( $this->form_fields['subject_paid'] );
		}

		$this->form_fields = array_merge(
			array(
				'enabled' => array(
					'title'   => _x( 'Enable/Disable', 'an email notification', 'subscriptions-recurring-payments-for-woocommerce' ),
					'type'    => 'checkbox',
					'label'   => __( 'Enable this email notification', 'subscriptions-recurring-payments-for-woocommerce' ),
					'default' => 'yes',
				),
			),
			$this->form_fields
		);
	}
}
