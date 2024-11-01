<?php
/**
 * Show PayPal admin notices
 *
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

foreach ( $notices as $notice_args ) {
	$notice_args = wp_parse_args( $notice_args, array(
		'type' => 'error',
		'text' => '',
	) );

	switch ( $notice_args['type'] ) {
		case 'warning' :
			$notice = new awc_Admin_Notice( 'updated', array( 'style' => array( 'border-left: 4px solid #ffba00' ) ) );
			break;
		case 'info' :
			$notice = new awc_Admin_Notice( 'notice notice-info' );
			break;
		case 'error' :
			$notice = new awc_Admin_Notice( 'updated error' );
			break;
		case 'confirmation' :
		default :
			$notice = new awc_Admin_Notice( 'updated' );
			break;
	}

	$notice->set_simple_content( $notice_args['text'] );
	$notice->display();
}
