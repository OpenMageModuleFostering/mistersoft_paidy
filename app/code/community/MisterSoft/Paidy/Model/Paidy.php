<?php
	 
/**
* Our test CC module adapter
*/
class MisterSoft_Paidy_Model_Paidy extends Mage_Payment_Model_Method_Abstract
{
	/**
	* unique internal payment method identifier
	*
	* @var string [a-z0-9_]
	*/
	protected $_code = 'paidy';
 
	/**
	 * Here are examples of flags that will determine functionality availability
	 * of this module to be used by frontend and backend.
	 *
	 * @see all flags and their defaults in Mage_Payment_Model_Method_Abstract
	 *
	 * It is possible to have a custom dynamic logic by overloading
	 * public function can* for each flag respectively
	 */
	 
	protected $_isGateway					= true;
	protected $_canOrder					= false;
	protected $_canAuthorize				= true;
	protected $_canCapture					= true;
	protected $_canCapturePartial			= true;
	protected $_canCaptureOnce				= false;
	protected $_canRefund					= true;
	protected $_canRefundInvoicePartial		= true;
	protected $_canVoid						= true;
	protected $_canUseInternal				= true;
	protected $_canUseCheckout				= true;
	protected $_canUseForMultishipping		= true;
	protected $_isInitializeNeeded			= false;
	protected $_canFetchTransactionInfo		= true;
	protected $_canReviewPayment			= false;
	protected $_canCreateBillingAgreement	= false;
	protected $_canManageRecurringProfiles	= true;
	protected $_canSaveCc = false;


	// form for hidden field
	protected $_formBlockType = 'paidy/checkout_form';
	protected $allowed_transactions = array(
		'status',
		'capture',
		'update',
		'close',
		'refund',
		'authorize'
	);
	const PAIDY_URL = 'https://api.paidy.com/pay/';
	protected $last_result = null;

	/**
	 * Here you will need to implement authorize, capture and void public methods
	 *
	 * @see examples of transaction specific public methods such as
	 * authorize, capture and void in MisterSoft_Paidy_Model_Paidy
	 */

	/**
	 * Authorize payment
	 *
	 * @param Mage_Sales_Model_Order_Payment $payment
	 * @return MisterSoft_Paidy_Model_Paidy
	 */
	public function authorize(Varien_Object $payment, $amount)
	{
		$pform = Mage::app()->getRequest()->getParams('payment');
		if (!empty($pform['payment']['paymentid'])) {
			if ($this->checkTransactionStatus($pform['payment']['paymentid'])) {
				$payment->setTransactionId($pform['payment']['paymentid'])->setIsTransactionClosed(0);
			} else {
				$payment->setTransactionId($pform['payment']['paymentid'])->setIsTransactionClosed(0);
				$payment->setIsTransactionPending(true);
				$payment->setIsFraudDetected(true);
			};
		} else {
			Mage::throwException(Mage::helper('paidy')->__('No paidy transaction information.'));
		};
		return $this;
	}
	/**
	 * Capture payment
	 *
	 * @param Mage_Sales_Model_Order_Payment $payment
	 * @return MisterSoft_Paidy_Model_Paidy
	 */
	public function capture(Varien_Object $payment, $amount) {
		$auth_tid = null;
		if (!(($auth = $payment->getAuthorizationTransaction()) && ($auth_tid = $auth->getTxnId()))) {
			$this->authorize($payment, $amount);
			$auth_tid = $payment->getAuthorizationTransaction()->getTxnId();
		}
		if (is_null($auth_tid)) {
			Mage::throwException(Mage::helper('paidy')->__('No authorize transaction find for capture'));
		};
		// process capture
		$data = array($auth_tid);
// lookup for invoice
		$invoice = Mage::registry('current_invoice');
		if ( $invoice ) {
			// TODO: PARTIAL PROCESS THERE - BUT NO DOCS FOR NOW
			// fill data with partial info from invoice
			$i = 1;
			$seq = array();
			foreach ($payment->getOrder()->getAllItems() as $oitem) {
				$seq[$oitem->getItemId()] = $i++; 
			};
			foreach ($invoice->getAllItems() as $item) {
				if ($item->getParentId()) { continue; };
	
				$paidy_item = array(
					'item_id'	=> (string)$seq[$item->getOrderItemId()],
					'quantity'	=> (int)$item->getQty()
				);

				$data['items'][] = $paidy_item;

			};
			$data['tax'] = $invoice->getTaxAmount();
			$data['shipping'] = $invoice->getShippingAmount();
		};
		$result = $this->processTransaction('capture', $data);
		if ($result->status == 'capture_success') {
			// create transaction 
			$payment->setTransactionId($result->capture_id);
		} else {
			Mage::throwException(Mage::helper('paidy')->__('Cannot capture'));
		};

		return $this;
	}


	/**
	 * Refund capture
	 *
	 * @param Mage_Sales_Model_Order_Payment $payment
	 * @return MisterSoft_Paidy_Model_Paidy
	 */
	public function refund(Varien_Object $payment, $amount)
	{
		$data = array($payment->getParentTransactionId(), (int)$amount);
		$result = $this->processTransaction('refund', $data);
		if ($result->status == 'refund_success') {
			$payment->setTransactionId($payment->getParentTransactionId() . '-refund')->setIsTransactionClosed(1);
			$payment->setShouldCloseParentTransaction(!$payment->getCreditmemo()->getInvoice()->canRefund());
		} else {
			Mage::throwException(Mage::helper('paidy')->__('Refund Failed'));
		};
		return $this;
	}

	public function void(Varien_Object $payment) {
		$data = array($payment->getParentTransactionId());
		$result = $this->processTransaction('close', $data);
		if ($result->status == 'close_success') {
			$payment->setTransactionId($payment->getParentTransactionId() . '-close')
				->setIsTransactionClosed(1)
				->setShouldCloseParentTransaction(1);
		} else {
			Mage::throwException(Mage::helper('paidy')->__('Void Failed'));
		};
		return $this;
	}

	public function cancel(Varien_Object $payment) {
		return $this->void($payment);
	}

    /**
     * Fetch transaction details info
     *
     * @param Mage_Payment_Model_Info $payment
     * @param string $transactionId
     * @return array
     */
    public function fetchTransactionInfo(Mage_Payment_Model_Info $payment, $transactionId)
    {
		// we can fetch transaction only for auth transactions
		$txn = $payment->getTransaction($transactionId);

		if ($txn->getTxnType() != Mage_Sales_Model_Order_Payment_Transaction::TYPE_AUTH) {
			Mage::throwException(sprintf(Mage::helper('paidy')->__("Only transaction with type '%s' can be fetched from Paidy.", Mage_Sales_Model_Order_Payment_Transaction::TYPE_AUTH)));
			return array();
		};
		
		$data = array($transactionId);
		$result = $this->processTransaction('status', $data);
		if (in_array($result->status, array('open', 'closed'))) {
			$payment->setIsTransactionClosed($result->status != 'open');
			//$payment->setIsTransactionApproved(true);
		} else {
			//$payment->setIsTransactionDenied(true);
			Mage::throwException(Mage::helper('paidy')->__('Status Failed'));
		};
		Mage::log("fetchTransactionInfo - " . json_encode($result), Zend_Log::ERR, 'paidy.log');
		return (array)$result;
    }



	/**
	 * Payment action getter compatible with payment model
	 *
	 * @see Mage_Sales_Model_Payment::place()
	 * @return string
	 */
	public function getConfigPaymentAction() {
		switch ($this->getConfigData('payment_action')) {
			case 'auth':
				return Mage_Payment_Model_Method_Abstract::ACTION_AUTHORIZE;
			case 'auth&capture':
				return Mage_Payment_Model_Method_Abstract::ACTION_AUTHORIZE_CAPTURE;
		}
	}
	/* 
	 * Check transaction status - is exists or not
	 */
	private function checkTransactionStatus($tid) {
		$result = $this->processTransaction('status', array($tid));
		//Mage::log(print_r($result, true), Zend_Log::ERR, 'paidy.log', true);
		$this->last_result = $result;
		return ($result && $result->status === 'open');
	}
	private function processTransaction($type, $data) {
		$result = null;
		if (in_array($type, $this->allowed_transactions)) {
			$request = array();
			$payment_id = array_shift($data);
			switch ($type) {
				case 'refund':
					$amount = array_shift($data);
					if (!is_null($amount)) {
						$request['amount']	= (int)$amount;
					};
					$request['capture_id']	= $payment_id;
					$request['checksum']	= base64_encode( hash ('sha256', $this->getConfigData('api_secret') . $payment_id, true ) );
					break;
				case 'capture':
					if (!empty($data)) {
						foreach ($data as $dkey => $dvalue) {
							$request[$dkey] = $dvalue;
						}
					};
				case 'close':
				case 'status':
					$request['payment_id']	= $payment_id;
					$request['checksum']	= base64_encode( hash ('sha256', $this->getConfigData('api_secret') . $payment_id, true ) );
					break;
				default:
					Mage::throwException(Mage::helper('paidy')->__('Not existing transaction type'));
					break;
			};
			$client = new Varien_Http_Client(self::PAIDY_URL . $type);
			$client->setMethod(Varien_Http_Client::POST);
			$client->setHeaders('Content-type: application/json');
			$client->setHeaders('Authorization: Bearer ' . $this->getConfigData('api_key'));
			$client->setRawData(json_encode($request));
			Mage::log("Send - " . json_encode($request), Zend_Log::ERR, 'paidy.log');
			$response = $client->request();
			Mage::log("Received - " . $response->getBody(), Zend_Log::ERR, 'paidy.log');
			if ($response->isError()) {
				Mage::throwException($response->getMessage() . print_r($client, true));
			};
			$result = json_decode($response->getBody());
		} else {
			Mage::throwException(sprintf(Mage::helper('paidy')->__('Not allowed transaction type: %s'), $type));
		};
		return $result;
	}
	public function getPaidyData() {
		$quote = Mage::getSingleton('checkout/session')->getQuote();

		$billing = $quote->getBillingAddress();
		$customer = $quote->getCustomer();
		$paidy_data = array();
		$paidy_data['buyer'] = array();
		$paidy_data['buyer']['name'] = $billing->getName();
		$paidy_data['buyer']['name2'] = $billing->getName();
		$paidy_data['buyer']['dob'] = $quote->getCustomerDob() ? $quote->getCustomerDob() : '';
		$paidy_data['buyer']['email'] = array('address' => $quote->getCustomerEmail());
		$paidy_data['buyer']['address'] = array(
			'address1'	=> $billing->getStreet2(),
			'address2'	=> $billing->getStreet1(),
			'address3'	=> $billing->getCity(),
			'address4'	=> $billing->getRegion() . ' ' . Mage::getModel('directory/country')->loadByCode($billing->getCountry())->getName(),
			'postal_code' => $billing->getPostcode()
		);
		$paidy_data['buyer']['phone'] = array('number'	=> $billing->getTelephone());
		$paidy_data['order'] = array();
		$paidy_data['order']['items'] = array();
		$i = 1;
		foreach ($quote->getAllItems() as $item) {
			if ($item->getParentId()) { continue; };

			$paidy_item = array(
				'item_id'	=> (string)$i++,
				'title'		=> (string)$item->getProduct()->getName(),
				'amount'	=> (int)$item->getPrice(),
				'quantity'	=> (int)$item->getQty()
			);

			$paidy_data['order']['items'][] = $paidy_item;
		}
		$paidy_data['order']['tax'] = (int)$quote->getShippingAddress()->getTaxAmount();
		$paidy_data['order']['shipping'] = (int)$quote->getShippingAddress()->getShippingAmount();
		$paidy_data['order']['total_amount'] = (int)$quote->getGrandTotal();

		$paidy_data['merchant_data'] = $this->getMerchantData($customer);
		$paidy_data['checksum'] = $this->getPaidyChecksum($paidy_data);
		$paidy_data['tracking'] = array(
			"cunsumer_ip"	=> $_SERVER['REMOTE_ADDR'],
		);
		$paidy_data['test'] = (bool)Mage::getStoreConfig('payment/paidy/test_mode');
		$paidy_data["options"] = array(
			"authorize_type"	=> "extended"
		);
		
		return $paidy_data;
	}
	private function getMerchantData($customer) {
		$result = array(
			"store"				=> Mage::getStoreConfig("payment/paidy/store_name") 
				? Mage::getStoreConfig("payment/paidy/store_name") : Mage::app()->getStore()->getName(),
			"category"			=> 1,
			"customer_age"		=> 0,
			"last_order"		=> 0,
			"last_order_amount"	=> 0,
			"num_orders"		=> 0,
			"known_address"		=> false,
			"ltv"				=> 0
		);
		$orders = Mage::getModel('sales/order')->getCollection()
			->addFieldToFilter('customer_id', $customer->getId())
			->addFieldToFilter('state', Mage::getStoreConfig('payment/paidy/completed_status'))
			->setOrder('created_at', 'ASC')
			->load();
		$result["num_orders"] = $orders->count();
		if ($first_order = $orders->getFirstItem()) {
			$cdate = new DateTime();
			$result['customer_age'] = (int)$cdate->diff(new DateTime($first_order->getCreatedAt()))->days;
		};
		if ($last_order = $orders->getLastItem()) {
			$cdate = new DateTime();
			$result['last_order'] = (int)$cdate->diff(new DateTime($last_order->getCreatedAt()))->days;
			$result['last_order_amount'] = (int)$last_order->getGrandTotal();
		};
		foreach ($orders as $o) {
			$result['ltv'] += $o->getGrandTotal();
		};
			
		return $result;
	}
	private function getPaidyChecksum($t) {
		$string = Mage::getStoreConfig('payment/paidy/api_secret') .
			$t['order']['total_amount'] .
			$t['merchant_data']['store'] .
			$t['merchant_data']['customer_age'] .
			$t['merchant_data']['last_order'] .
			$t['merchant_data']['last_order_amount'] .
			($t['merchant_data']['known_address'] ? 'true' : 'false' ) .
			$t['merchant_data']['num_orders'] .
			$t['merchant_data']['ltv'];

		$sha = hash ('sha256', $string, true );
			
		return base64_encode($sha);
	}
}
?>
