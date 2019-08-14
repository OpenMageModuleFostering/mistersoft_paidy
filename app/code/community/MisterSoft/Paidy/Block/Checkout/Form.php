<?php
class MisterSoft_Paidy_Block_Checkout_Form extends Mage_Payment_Block_Form {

	protected function _construct() {
		parent::_construct();
		$this->setTemplate('mistersoft_paidy/checkout/form.phtml');
	}
}
