<?php
/**
 * Class that handles our retries custom tables creation.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

class AWC_Retry_Table_Maker extends AWC_Table_Maker {
	/**
	 * @inheritDoc
	 */
	protected $schema_version = 1;

	/**
	 * AWC_Retry_Table_Maker constructor.
	 */
	public function __construct() {
		$this->tables = array(
			AWC_Retry_Stores::get_database_store()->get_table_name(),
		);
	}

	/**
	 * @param string $table
	 *
	 * @return string
	 
	 */
	protected function get_table_definition( $table ) {
		global $wpdb;
		$table_name      = $wpdb->$table;
		$charset_collate = $wpdb->get_charset_collate();

		switch ( $table ) {
			case AWC_Retry_Stores::get_database_store()->get_table_name():
				return "
				CREATE TABLE {$table_name} (
					retry_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
					order_id BIGINT UNSIGNED NOT NULL,
					status varchar(255) NOT NULL,
					date_gmt datetime NOT NULL default '0000-00-00 00:00:00',
					rule_raw text,
					PRIMARY KEY  (retry_id),
					KEY order_id (order_id)
				) $charset_collate;
						";
			default:
				return '';
		}
	}
}
