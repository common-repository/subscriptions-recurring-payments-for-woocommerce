<?php
defined( 'ABSPATH' ) || exit; // Exit if accessed directly.

/**
 * Customer On Hold Renewal Order Email.
 *
 *
 */
class AWC_Email_Customer_On_Hold_Renewal_Order extends WC_Email_Customer_On_Hold_Order {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id             = 'customer_on_hold_renewal_order';
		$this->customer_email = true;
		$this->title          = __( 'On-hold Renewal Order', 'subscriptions-recurring-payments-for-woocommerce' );
		$this->description    = __( 'This is an order notification sent to customers containing order details after a renewal order is placed on-hold.', 'subscriptions-recurring-payments-for-woocommerce' );
		$this->subject        = __( 'Your {blogname} renewal order has been received!', 'subscriptions-recurring-payments-for-woocommerce' );
		$this->heading        = __( 'Thank you for your renewal order', 'subscriptions-recurring-payments-for-woocommerce' );
		$this->template_html  = 'emails/customer-on-hold-renewal-order.php';
		$this->template_plain = 'emails/text/customer-on-hold-renewal-order.php';
		$this->template_base  = plugin_dir_path( AWC_Subscriptions::$plugin_file ) . 'temp/';
		$this->placeholders   = array(
			'{order_date}'   => '',
			'{order_number}' => '',
		);

		// Triggers for this email.
		add_action( 'woocommerce_order_status_pending_to_on-hold_renewal_notification', array( $this, 'trigger' ), 10, 2 );
		add_action( 'woocommerce_order_status_failed_to_on-hold_renewal_notification', array( $this, 'trigger' ), 10, 2 );
		add_action( 'woocommerce_order_status_cancelled_to_on-hold_renewal_notification', array( $this, 'trigger' ), 10, 2 );

		// We want most of the parent's methods, with none of its properties, so call its parent's constructor
		WC_Email::__construct();
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
	 * Get content html.
	 *
	 * @return string
	 */
	public function get_content_html() {
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
	 * Get content plain.
	 *
	 * @return string
	 */
	public function get_content_plain() {
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
}
