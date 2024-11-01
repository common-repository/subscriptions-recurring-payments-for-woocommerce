<?php 
/**
 * A class to make it possible to limit a subscription product.
 *
 * @package WooCommerce Subscriptions
 * @category Class
 
 */
class AWC_Settings {

    /**
     * @access  public
     * @return array / string
     * @desc    Get subscription settings
     */
    public static function get_option($name = false){
        $options = get_option('awc_settings', array());
        if($name)
            return isset($options[$name]) ? $options[$name] : false;
        
        return $options;
    }

}