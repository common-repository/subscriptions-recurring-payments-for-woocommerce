<?php
/**
 * Line Item (product) Pending Switch
 *
 */

class WC_Order_Item_Pending_Switch extends WC_Order_Item_Product {

	/**
	 * Get item type.
	 *
	 * @return string
	 
	 */
	public function get_type() {
		return 'line_item_pending_switch';
	}
}
