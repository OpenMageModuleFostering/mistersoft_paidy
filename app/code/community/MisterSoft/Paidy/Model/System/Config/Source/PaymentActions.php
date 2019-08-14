<?php
/**
 * Magento
 */

/**
 * Source model for available payment actions
 */
class MisterSoft_Paidy_Model_System_Config_Source_PaymentActions
{
    /**
     * Options getter
     *
     * @return array
     */
    public function toOptionArray()
    {
        return array(
			'auth'			=> Mage::helper('paidy')->__('Authorization Only'),
			'auth&capture'	=> Mage::helper('paidy')->__('Authorization&Capture')
		);
    }
}
