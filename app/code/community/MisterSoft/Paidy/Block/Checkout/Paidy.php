<?php
class MisterSoft_Paidy_Block_Checkout_Paidy extends Mage_Core_Block_Template {

	public function getApiKey() {
		return Mage::getStoreConfig('payment/paidy/api_key');
	}
	public function getPaidyData() {
		return Mage::getModel('paidy/paidy')->getPaidyData();;
	}
}
