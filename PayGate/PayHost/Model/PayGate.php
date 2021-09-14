<?php
/*
 * Copyright (c) 2021 PayGate (Pty) Ltd
 *
 * Author: App Inlet (Pty) Ltd
 *
 * Released under the GNU General Public License
 */

namespace PayGate\PayHost\Model;

use DateTime;
use Magento\Checkout\Model\Session;
use Magento\Framework\Api\AttributeValueFactory;
use Magento\Framework\Api\ExtensionAttributesFactory;
use Magento\Framework\App\Area;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\Data\Form\FormKey;
use Magento\Framework\DataObject;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\LocalizedExceptionFactory;
use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Registry;
use Magento\Framework\UrlInterface;
use Magento\Payment\Model\InfoInterface;
use Magento\Payment\Model\Method\AbstractMethod;
use Magento\Payment\Model\Method\Logger;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Quote\Model\Quote;
use Magento\Sales\Api\Data\OrderPaymentInterface;
use Magento\Sales\Api\Data\TransactionInterface;
use Magento\Sales\Api\Data\TransactionSearchResultInterfaceFactory;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Api\TransactionRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use Magento\Sales\Model\Order\Payment\Transaction;
use Magento\Sales\Model\Order\Payment\Transaction\BuilderInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Vault\Api\Data\PaymentTokenInterface;
use Magento\Vault\Api\PaymentTokenManagementInterface;
use Magento\Vault\Api\PaymentTokenRepositoryInterface;
use Magento\Vault\Model\CreditCardTokenFactory;
use Magento\Vault\Model\ResourceModel\PaymentToken as PaymentTokenResourceModel;
use Magento\Vault\Model\Ui\VaultConfigProvider;
use PayGate\Payhost\CountryData;
use PayGate\PayHost\Helper\Data;
use SimpleXMLElement;

/**
 * @SuppressWarnings(PHPMD.TooManyFields)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class PayGate extends AbstractMethod
{
    const PAYHOSTURL = "https://secure.paygate.co.za/payhost/process.trans";
    const SECURE     = array('_secure' => true);
    /**
     * @var string
     */
    protected $_code = Config::METHOD_CODE;
    /**
     * @var string
     */
    protected $_formBlockType = 'PayGate\PayHost\Block\Form';
    /**
     * @var string
     */
    protected $_infoBlockType = 'PayGate\PayHost\Block\Payment\Info';
    /**
     * @var string
     */
    protected $_configType = 'PayGate\PayHost\Model\Config';
    /**
     * Payment Method feature
     *
     * @var bool
     */
    protected $_isInitializeNeeded = true;
    /**
     * Availability option
     *
     * @var bool
     */
    protected $_isGateway = false;
    /**
     * Availability option
     *
     * @var bool
     */
    protected $_canOrder = true;
    /**
     * Availability option
     *
     * @var bool
     */
    protected $_canAuthorize = true;
    /**
     * Availability option
     *
     * @var bool
     */
    protected $_canCapture = true;
    /**
     * Availability option
     *
     * @var bool
     */
    protected $_canVoid = false;
    /**
     * Availability option
     *
     * @var bool
     */
    protected $_canUseInternal = true;
    /**
     * Availability option
     *
     * @var bool
     */
    protected $_canUseCheckout = true;
    /**
     * Availability option
     *
     * @var bool
     */
    protected $_canFetchTransactionInfo = true;
    /**
     * Availability option
     *
     * @var bool
     */
    protected $_canReviewPayment = true;
    /**
     * Website Payments Pro instance
     *
     * @var Config $config
     */
    protected $_config;
    /**
     * Payment additional information key for payment action
     *
     * @var string
     */
    protected $_isOrderPaymentActionKey = 'is_order_action';
    /**
     * Payment additional information key for number of used authorizations
     *
     * @var string
     */
    protected $_authorizationCountKey = 'authorization_count';
    /**
     * @var StoreManagerInterface
     */
    protected $_storeManager;
    /**
     * @var UrlInterface
     */
    protected $_urlBuilder;
    /**
     * @var UrlInterface
     */
    protected $_formKey;
    /**
     * @var Session
     */
    protected $_checkoutSession;
    /**
     * @var LocalizedExceptionFactory
     */
    protected $_exception;
    /**
     * @var TransactionRepositoryInterface
     */
    protected $transactionRepository;
    /**
     * @var BuilderInterface
     */
    protected $transactionBuilder;
    protected $creditCardTokenFactory;
    protected $paymentTokenRepository;
    /**
     * @var PaymentTokenManagementInterface
     */
    protected $paymentTokenManagement;
    /**
     * @var EncryptorInterface
     */
    protected $encryptor;
    /**
     * @var Payment
     */
    protected $payment;
    /**
     * Availability option
     *
     * @var bool
     */
    protected $_canRefund = true;
    protected $_canRefundInvoicePartial = true;
    /**
     * @var PaymentTokenResourceModel
     */
    protected $paymentTokenResourceModel;
    protected $transactions;
    /**
     * \Magento\Payment\Helper\Data $paymentData,
     */
    protected $_paymentData;

    /**
     * @var OrderRepositoryInterface $orderRepository
     */
    protected $orderRepository;

    /**
     * @param Context $context
     * @param Registry $registry
     * @param ExtensionAttributesFactory $extensionFactory
     * @param AttributeValueFactory $customAttributeFactory
     * @param \Magento\Payment\Helper\Data $paymentData
     * @param ScopeConfigInterface $scopeConfig
     * @param Logger $logger
     * @param ConfigFactory $configFactory
     * @param StoreManagerInterface $storeManager
     * @param UrlInterface $urlBuilder
     * @param FormKey $formKey
     * @param CartFactory $cartFactory
     * @param Session $checkoutSession
     * @param LocalizedExceptionFactory $exception
     * @param TransactionRepositoryInterface $transactionRepository
     * @param BuilderInterface $transactionBuilder
     * @param AbstractResource $resource
     * @param AbstractDb $resourceCollection
     * @param array $data
     * @SuppressWarnings(PHPMD.ExcessiveParameterList)
     */
    public function __construct(
        Context $context,
        Registry $registry,
        ExtensionAttributesFactory $extensionFactory,
        AttributeValueFactory $customAttributeFactory,
        \Magento\Payment\Helper\Data $paymentData,
        Data $payhostData,
        ScopeConfigInterface $scopeConfig,
        Logger $logger,
        ConfigFactory $configFactory,
        StoreManagerInterface $storeManager,
        UrlInterface $urlBuilder,
        FormKey $formKey,
        Session $checkoutSession,
        LocalizedExceptionFactory $exception,
        TransactionRepositoryInterface $transactionRepository,
        BuilderInterface $transactionBuilder,
        CreditCardTokenFactory $CreditCardTokenFactory,
        PaymentTokenRepositoryInterface $PaymentTokenRepositoryInterface,
        PaymentTokenManagementInterface $paymentTokenManagement,
        EncryptorInterface $encryptor,
        PaymentTokenResourceModel $paymentTokenResourceModel,
        TransactionSearchResultInterfaceFactory $transactions,
        OrderRepositoryInterface $orderRepository,
        AbstractResource $resource = null,
        AbstractDb $resourceCollection = null,

        array $data = []
    ) {
        parent::__construct(
            $context,
            $registry,
            $extensionFactory,
            $customAttributeFactory,
            $paymentData,
            $scopeConfig,
            $logger,
            $resource,
            $resourceCollection,
            $data
        );
        $this->_storeManager             = $storeManager;
        $this->_urlBuilder               = $urlBuilder;
        $this->_formKey                  = $formKey;
        $this->_checkoutSession          = $checkoutSession;
        $this->_exception                = $exception;
        $this->transactionRepository     = $transactionRepository;
        $this->transactionBuilder        = $transactionBuilder;
        $this->creditCardTokenFactory    = $CreditCardTokenFactory;
        $this->paymentTokenRepository    = $PaymentTokenRepositoryInterface;
        $this->paymentTokenManagement    = $paymentTokenManagement;
        $this->encryptor                 = $encryptor;
        $this->paymentTokenResourceModel = $paymentTokenResourceModel;
        $this->transactions              = $transactions;
        $this->_paymentData              = $payhostData;
        $this->orderRepository           = $orderRepository;

        $parameters = ['params' => [$this->_code]];

        $this->_config = $configFactory->create($parameters);
    }

    /**
     * Store setter
     * Also updates store ID in config object
     *
     * @param Store|int $store
     *
     * @return $this
     */
    public function setStore($store)
    {
        $this->setData('store', $store);

        if (null === $store) {
            $store = $this->_storeManager->getStore()->getId();
        }
        $this->_config->setStoreId(is_object($store) ? $store->getId() : $store);

        return $this;
    }

    /**
     * Whether method is available for specified currency
     *
     * @param string $currencyCode
     *
     * @return bool
     */
    public function canUseForCurrency($currencyCode)
    {
        return $this->_config->isCurrencyCodeSupported($currencyCode);
    }

    /**
     * Payment action getter compatible with payment model
     *
     * @return string
     * @see \Magento\Sales\Model\Payment::place()
     */
    public function getConfigPaymentAction()
    {
        return $this->_config->getPaymentAction();
    }

    /**
     * Check whether payment method can be used
     *
     * @param CartInterface|Quote|null $quote
     *
     * @return bool
     */
    public function isAvailable(CartInterface $quote = null)
    {
        return parent::isAvailable($quote) && $this->_config->isMethodAvailable();
    }

    /**
     * This is where we compile data posted by the form to PayGate
     * @return array
     */
    public function getStandardCheckoutFormFields()
    {
        // Variable initialization

        $payhostFields = $this->prepareFields();
        $response      = $this->curlPost(self::PAYHOSTURL, $payhostFields);

        $respArray = $this->formatXmlToArray($response);
        $respData  = $respArray['ns2SinglePaymentResponse']['ns2WebPaymentResponse']['ns2Redirect']['ns2UrlParams'];

        $order = $this->_checkoutSession->getLastRealOrder();

        $processData = array();
        foreach ($respData as $field) {
            $processData[$field['ns2key']] = $field['ns2value'];
        }
        $processData['PAYMENT_TITLE'] = "PAYGATE_PAYHOST";

        $this->_paymentData->createTransaction($order, $processData);

        return ($processData);
    }

    public function getPayGateCredentials()
    {
        // If NOT test mode, use normal credentials
        $cred = array();
        if ($this->getConfigData('test_mode') != '1') {
            $cred['paygateId'] = $this->getConfigData('paygate_id');
            $cred['password']  = $this->getConfigData('encryption_key');
        } else {
            $cred['paygateId'] = '10011072130';
            $cred['password']  = 'test';
        }

        return $cred;
    }

    public function prepareFields()
    {
        $pre = __METHOD__ . ' : ';

        $order = $this->_checkoutSession->getLastRealOrder();

        $order->getPayment()->getData();

        $this->_logger->debug($pre . 'serverMode : ' . $this->getConfigData('test_mode'));

        $cred = $this->getPayGateCredentials();

        $paygateId = $cred['paygateId'];
        $password  = $cred['password'];

        $billing = $order->getBillingAddress();
        $billing->getCountryId();

        $DateTime = new DateTime();

        $notifyUrl = $this->_urlBuilder->getUrl('payhost/notify', self::SECURE) . '?gid=' . $order->getRealOrderId();

        $returnUrl = $this->_urlBuilder->getUrl(
                'payhost/redirect/success',
                self::SECURE
            ) . '?gid=' . $order->getRealOrderId();

        return '<SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/">
                <SOAP-ENV:Header/>
                <SOAP-ENV:Body>
                    <SinglePaymentRequest xmlns="http://www.paygate.co.za/PayHOST">
                        <WebPaymentRequest>
                            <Account>
                                <PayGateId>' . $paygateId . '</PayGateId>
                                <Password>' . $password . '</Password>
                            </Account>
                            <Customer>
                                <FirstName>' . $order->getCustomerFirstname() . '</FirstName>
                                <LastName>' . $order->getCustomerLastname() . '</LastName>
                                <Email>' . $order->getCustomerEmail() . '</Email>
                            </Customer>
                            <Redirect>
                                <NotifyUrl>' . $notifyUrl . '</NotifyUrl>
                                <ReturnUrl>' . $returnUrl . '</ReturnUrl>
                            </Redirect>
                            <Order>
                                <MerchantOrderId>' . $order->getRealOrderId() . '</MerchantOrderId>
                                <Currency>ZAR</Currency>
                                <Amount>' . number_format($this->getTotalAmount($order), 2, '', '') . '</Amount>
                                <TransactionDate>' . $DateTime->format('Y-m-d') . 'T' . $DateTime->format('h:i:s') . '</TransactionDate>
                                <BillingDetails>
                                    <Customer>
                                        <FirstName>' . $order->getCustomerFirstname() . '</FirstName>
                                        <LastName>' . $order->getCustomerLastname() . '</LastName>
                                        <Email>' . $order->getCustomerEmail() . '</Email>
                                    </Customer>
                                    <Address>
                                        <Country>ZAF</Country>
                                    </Address>
                                </BillingDetails>
                            </Order>
                        </WebPaymentRequest>
                    </SinglePaymentRequest>
                </SOAP-ENV:Body>
            </SOAP-ENV:Envelope>';
    }

    public function curlPost($url, $xml)
    {
        $curl = curl_init();

        curl_setopt_array(
            $curl,
            array(
                CURLOPT_URL            => self::PAYHOSTURL,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING       => "",
                CURLOPT_MAXREDIRS      => 10,
                CURLOPT_TIMEOUT        => 0,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST  => "POST",
                CURLOPT_POSTFIELDS     => "$xml",
                CURLOPT_HTTPHEADER     => array(
                    "Content-Type: text/xml",
                    "SOAPAction: WebPaymentRequest"
                ),
            )
        );

        $response = curl_exec($curl);

        curl_close($curl);

        return $response;
    }

    public function getErrorCodes()
    {
        return array(
            'DATA_CHK'        => 'Checksum calculated incorrectly',
            'DATA_PAY_REQ_ID' => 'Pay request ID missing or invalid',
            'ND_INV_PGID'     => 'Invalid PayGate ID',
            'ND_INV_PRID'     => 'Invalid Pay Request ID',
            'PGID_NOT_EN'     => 'PayGate ID not enabled, there are no available payment methods or there are no available currencies',
            'TXN_CAN'         => 'Transaction has already been cancelled',
            'TXN_CMP'         => 'Transaction has already been completed',
            'TXN_PRC'         => 'Transaction is older than 30 minutes or there has been an error processing it',
            'DATA_PM'         => 'Pay Method or Pay Method Detail fields invalid'
        );
    }

    /**
     * getTotalAmount
     */
    public function getTotalAmount($order)
    {
        return $this->getNumberFormat($order->getGrandTotal());
    }

    /**
     * getNumberFormat
     */
    public function getNumberFormat($number)
    {
        return number_format($number, 2, '.', '');
    }

    /**
     * getPaidSuccessUrl
     */
    public function getPaidSuccessUrl()
    {
        return $this->_urlBuilder->getUrl('payhost/redirect/success', self::SECURE);
    }

    public function getOrderPlaceRedirectUrl()
    {
        return $this->getCheckoutRedirectUrl();
    }

    /**
     * Checkout redirect URL getter for onepage checkout (hardcode)
     *
     * @return string
     * @see Quote\Payment::getCheckoutRedirectUrl()
     * @see \Magento\Checkout\Controller\Onepage::savePaymentAction()
     */
    public function getCheckoutRedirectUrl()
    {
        return $this->_urlBuilder->getUrl('payhost/redirect');
    }

    /**
     *
     * @param string $paymentAction
     * @param object $stateObject
     *
     * @return $this
     */
    public function initialize($paymentAction, $stateObject)
    {
        $stateObject->setState(Order::STATE_PENDING_PAYMENT);
        $stateObject->setStatus('pending_payment');
        $stateObject->setIsNotified(false);

        return parent::initialize($paymentAction, $stateObject);
    }

    /*
     * called dynamically by checkout's framework.
     */

    /**
     * getPaidNotifyUrl
     */
    public function getPaidNotifyUrl()
    {
        return $this->_urlBuilder->getUrl('payhost/notify', self::SECURE);
    }

    /**
     * Add link between payment token and order payment.
     *
     * @param int $paymentTokenId Payment token ID.
     * @param int $orderPaymentId Order payment ID.
     *
     * @return bool
     */
    public function addLinkToOrderPayment($paymentTokenId, $orderPaymentId)
    {
        return $this->paymentTokenResourceModel->addLinkToOrderPayment($paymentTokenId, $orderPaymentId);
    }

    public function getCountryDetails($code2)
    {
        $countries = CountryData::getCountries();

        foreach ($countries as $key => $val) {
            if ($key == $code2) {
                return $val[2];
            }
        }
    }

    /**
     * Check refund availability.
     * The main factor is that the last capture transaction exists and has an Payflow\Pro::TRANSPORT_PAYFLOW_TXN_ID in
     * additional information(needed to perform online refund. Requirement of the Payflow gateway)
     *
     * @return bool
     */
    public function canRefund()
    {
        /** @var Payment $paymentInstance */
        $paymentInstance = $this->getInfoInstance();
        // we need the last capture transaction was made
        $captureTransaction = $this->transactionRepository->getByTransactionType(
            Transaction::TYPE_CAPTURE,
            $paymentInstance->getId(),
            $paymentInstance->getOrder()->getId()
        );

        return $captureTransaction && $captureTransaction->getTransactionId() && $this->_canRefund;
    }

    /**
     * Check partial refund availability for invoice
     *
     * @return bool
     * @api
     */
    public function canRefundPartialPerInvoice()
    {
        return $this->canRefund();
    }

    /**
     * Refund specified amount for payment
     *
     * @param DataObject|InfoInterface $payment
     * @param float $amount
     *
     * @return $this
     * @throws LocalizedException
     * @api
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function refund(InfoInterface $payment, $amount)
    {
        $order = $payment->getOrder();


        $helper          = $this->_paymentData;
        $amount          = $helper->convertToOrderCurrency($order, $amount);
        $refundResponse  = $this->ProcessRefund($payment, $amount);
        $transactionData = $refundResponse['ns2SingleFollowUpResponse']['ns2RefundResponse']['ns2Status'];

        /* Set Comment to Order*/
        if ($transactionData['ns2TransactionId'] && $transactionData['ns2PayRequestId'] && $transactionData['ns2StatusName'] == "Completed") {
            $trxId    = $transactionData['ns2TransactionId'];
            $payReqId = $transactionData['ns2PayRequestId'];
            $status   = $transactionData['ns2StatusName'];
            $order->addStatusHistoryComment(
                __(
                    "Order Successfullt Refunded with Transaction Id - $trxId Pay Request Id - $payReqId  & status - $status."
                )
            )->save();

            return true;
        } else {
            $order->addStatusHistoryComment(__("Refund not successfull."))->save();

            return false;
        }
    }

    public function ProcessRefund($payment, $amount)
    {
        $orderId     = $payment->getParentId();
        $transaction = $this->transactions->create()->addOrderIdFilter($orderId)->getFirstItem();

        $transactionId = $transaction->getData('txn_id');

        // If NOT test mode, use normal credentials
        if ($this->getConfigData('test_mode') != '1') {
            $paygateId = $this->getConfigData('paygate_id');
            $password  = $this->getConfigData('encryption_key');
        } else {
            $paygateId = '10011072130';
            $password  = 'test';
        }
        /*
        ** The Query function allows you to query the final status of previously processed transactions.
        ** The Query function will accept a PayRequestId, TransId or a Reference as a search key.
        ** QueryRequestType
        */
        $query                = '
				<QueryRequest>
					<Account>
						<PayGateId>' . $paygateId . '</PayGateId>
						<Password>' . $password . '</Password>
					</Account>
					<PayRequestId>' . $transactionId . '</PayRequestId>
				</QueryRequest>
			';
        $queryResponse        = $this->ProcessRefundRequest($query);
        $queryArray           = $this->formatXmlToArray($queryResponse);
        $PaygateTransactionId = $queryArray['ns2SingleFollowUpResponse']['ns2QueryResponse']['ns2Status']['ns2TransactionId'];

        /*
        ** This function allows the merchant to refund a transaction that has already been settled.
        ** RefundRequestType
        */
        $refund = '
			<RefundRequest>
				<Account>
					<PayGateId>' . $paygateId . '</PayGateId>
					<Password>' . $password . '</Password>
				</Account>
				<TransactionId>' . $PaygateTransactionId . '</TransactionId>
				<Amount>10</Amount>
			</RefundRequest>
		';

        $refundResponse = $this->ProcessRefundRequest($refund);

        return $this->formatXmlToArray($refundResponse);
    }

    public function ProcessRefundRequest($xml)
    {
        $xml = $this->MakeUpXml($xml);

        return $this->curlPost(self::PAYHOSTURL, $xml);
    }

    public function MakeUpXml($xml)
    {
        return '
			<SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/">
				<SOAP-ENV:Header/>
				<SOAP-ENV:Body>
					<SingleFollowUpRequest xmlns="http://www.paygate.co.za/PayHOST">
						' . $xml . '
					</SingleFollowUpRequest>
				</SOAP-ENV:Body>
			</SOAP-ENV:Envelope>
		';
    }

    public function formatXmlToArray($response)
    {
        $response = preg_replace("/(<\/?)(\w+):([^>]*>)/", "$1$2$3", $response);
        $xml      = new SimpleXMLElement($response);
        $body     = $xml->xpath('//SOAP-ENV:Body')[0];

        return json_decode(json_encode((array)$body), true);
    }

    public function getOrderbyOrderId($order_id)
    {
        return $this->orderRepository->get($order_id);
    }

    /**
     * @inheritdoc
     */
    public function fetchTransactionInfo(InfoInterface $payment, $transactionId)
    {
        $state = ObjectManager::getInstance()->get('\Magento\Framework\App\State');
        if ($state->getAreaCode() == Area::AREA_ADMINHTML) {
            $order_id = $payment->getOrder()->getId();
            $order    = $this->getOrderbyOrderId($order_id);

            $result = $this->_paymentData->getQueryResult($transactionId);

            if (isset($result['ns2PaymentType'])) {
                $result['PAYMENT_TYPE_METHOD'] = $result['ns2PaymentType']['ns2Method'];
                $result['PAYMENT_TYPE_DETAIL'] = $result['ns2PaymentType']['ns2Detail'];
            }
            unset($result['ns2PaymentType']);

            $result['PAY_REQUEST_ID'] = $transactionId;
            $result['PAYMENT_TITLE']  = "PAYGATE_PAYHOST";
            $this->_paymentData->updatePaymentStatus($order, $result);
        }

        return $result;
    }

    /**
     * @return mixed
     */
    protected function getStoreName()
    {
        return $this->_scopeConfig->getValue(
            'general/store_information/name',
            ScopeInterface::SCOPE_STORE
        );
    }

    /**
     * Place an order with authorization or capture action
     *
     * @param Payment $payment
     * @param float $amount
     *
     * @return $this
     */
    protected function _placeOrder(Payment $payment, $amount)
    {
        $pre = __METHOD__ . " : ";
        $this->_logger->debug($pre . 'bof');
    }

    /**
     * Get transaction with type order
     *
     * @param OrderPaymentInterface $payment
     *
     * @return false|TransactionInterface
     */
    protected function getOrderTransaction($payment)
    {
        return $this->transactionRepository->getByTransactionType(
            Transaction::TYPE_ORDER,
            $payment->getId(),
            $payment->getOrder()->getId()
        );
    }
}
