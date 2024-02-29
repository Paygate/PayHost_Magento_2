<?php

/*
 * Copyright (c) 2024 Payfast (Pty) Ltd
 *
 * Author: App Inlet (Pty) Ltd
 *
 * Released under the GNU General Public License
 */

namespace PayGate\PayHost\Model;

use DateTime;
use Magento\Checkout\Model\Session;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\Api\AttributeValueFactory;
use Magento\Framework\Api\ExtensionAttributesFactory;
use Magento\Framework\App\Area;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\App\State;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\Data\Form\FormKey;
use Magento\Framework\DataObject;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\LocalizedExceptionFactory;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\Model\AbstractExtensibleModel;
use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Registry;
use Magento\Framework\UrlInterface;
use Magento\Payment\Model\InfoInterface;
use Magento\Payment\Model\Method\AbstractMethod;
use Magento\Payment\Model\Method\Logger;
use Magento\Payment\Model\MethodInterface;
use Magento\Payment\Observer\AbstractDataAssignObserver;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Quote\Api\Data\PaymentMethodInterface;
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
use PayGate\PayHost\Block\Form;
use PayGate\PayHost\Block\Payment\Info;
use PayGate\Payhost\CountryData;
use PayGate\PayHost\Helper\Data;
use phpseclib3\Crypt\EC\Formats\Keys\XML;
use Psr\Log\LoggerInterface;
use SimpleXMLElement;

/**
 * @SuppressWarnings(PHPMD.TooManyFields)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class PayGate extends AbstractExtensibleModel implements MethodInterface, PaymentMethodInterface
{

    public const PAYHOSTURL = "https://secure.paygate.co.za/payhost/process.trans";
    public const SECURE     = ['_secure' => true];
    /**
     * @var string
     */
    protected $_code = Config::METHOD_CODE;
    /**
     * @var string
     */
    protected $_formBlockType = Form::class;
    /**
     * @var string
     */
    protected $_infoBlockType = Info::class;
    /**
     * @var string
     */
    protected $_configType = Config::class;
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
    /**
     * @var CreditCardTokenFactory
     */
    protected $creditCardTokenFactory;
    /**
     * @var PaymentTokenRepositoryInterface
     */
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
    /**
     * Refund invoice status
     *
     * @var bool
     */
    protected $_canRefundInvoicePartial = true;
    /**
     * @var PaymentTokenResourceModel
     */
    protected $paymentTokenResourceModel;
    /**
     * @var TransactionSearchResultInterfaceFactory
     */
    protected $transactions;

    /**
     * @var OrderRepositoryInterface $orderRepository
     */
    protected $orderRepository;
    /**
     * @var ScopeConfigInterface
     */
    protected ScopeConfigInterface $_scopeConfig;
    /**
     * @var LoggerInterface
     */
    protected $_logger;
    /**
     * @var CustomerSession
     */
    protected CustomerSession $customerSession;
    /**
     * @var PaymentTokenManagementInterface
     */
    protected PaymentTokenManagementInterface $paymentTokenManagementInterface;
    /**
     * @var Curl
     */
    private Curl $curl;

    /**
     * @param Registry $registry
     * @param ExtensionAttributesFactory $extensionFactory
     * @param AttributeValueFactory $customAttributeFactory
     * @param Data $payhostData
     * @param ScopeConfigInterface $scopeConfig
     * @param Logger $logger
     * @param ConfigFactory $configFactory
     * @param StoreManagerInterface $storeManager
     * @param UrlInterface $urlBuilder
     * @param FormKey $formKey
     * @param Session $checkoutSession
     * @param LocalizedExceptionFactory $exception
     * @param TransactionRepositoryInterface $transactionRepository
     * @param BuilderInterface $transactionBuilder
     * @param CreditCardTokenFactory $CreditCardTokenFactory
     * @param PaymentTokenRepositoryInterface $PaymentTokenRepositoryInterface
     * @param PaymentTokenManagementInterface $paymentTokenManagement
     * @param EncryptorInterface $encryptor
     * @param PaymentTokenResourceModel $paymentTokenResourceModel
     * @param TransactionSearchResultInterfaceFactory $transactions
     * @param OrderRepositoryInterface $orderRepository
     * @param Curl $curl
     * @param ScopeConfigInterface $_scopeConfig
     * @param ManagerInterface $_eventManager
     * @param LoggerInterface $_logger
     * @param CustomerSession $customerSession
     * @param PaymentTokenManagementInterface $paymentTokenManagementInterface
     * @param AbstractResource $resource
     * @param AbstractDb $resourceCollection
     * @param array $data
     * @SuppressWarnings(PHPMD.ExcessiveParameterList)
     */
    public function __construct(
        Registry $registry,
        ExtensionAttributesFactory $extensionFactory,
        AttributeValueFactory $customAttributeFactory,
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
        Curl $curl,
        ScopeConfigInterface $_scopeConfig,
        ManagerInterface $_eventManager,
        LoggerInterface $_logger,
        CustomerSession $customerSession,
        PaymentTokenManagementInterface $paymentTokenManagementInterface,
        AbstractResource $resource = null,
        AbstractDb $resourceCollection = null,
        array $data = []
    ) {
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

        $this->_config                         = $configFactory->create($parameters);
        $this->curl                            = $curl;
        $this->_scopeConfig                    = $_scopeConfig;
        $this->_eventManager                   = $_eventManager;
        $this->_logger                         = $_logger;
        $this->customerSession                 = $customerSession;
        $this->paymentTokenManagementInterface = $paymentTokenManagementInterface;
    }

    /**
     * Store setter
     *
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
        return $this->_config->isMethodAvailable();
    }

    /**
     * This is where we compile data posted by the form to Paygate
     *
     * @return array|null
     */
    public function getStandardCheckoutFormFields()
    {
        // Variable initialization
        $pre             = __METHOD__ . ' : ';
        $processData     = [];
        $customerSession = $this->customerSession;
        $encryptionKey   = $this->_config->getEncryptionKey();
        $order           = $this->_checkoutSession->getLastRealOrder();
        $orderData       = $order->getPayment()->getData();

        if (!$order || $order->getPayment() == null) {
            return ["error" => "invalid order"];
        }

        $saveCard     = "new-save";
        $dontsaveCard = "new";
        $vaultId      = "";
        $vaultEnabled = 0;

        if ($customerSession->isLoggedIn() && isset($orderData['additional_information']['payhost-payvault-method'])) {
            $vaultEnabled = $orderData['additional_information']['payhost-payvault-method'];

            $vaultoptions = ['0', '1', 'new-save', 'new'];
            if (!in_array($vaultEnabled, $vaultoptions)) {
                $customerId = $customerSession->getCustomer()->getId();
                $cardData   = $this->paymentTokenManagementInterface->getByPublicHash($vaultEnabled, $customerId);
                if ($cardData->getEntityId()) {
                    $vaultId = $cardData->getGatewayToken();
                }
            }
        }

        $this->_logger->debug($pre . 'serverMode : ' . $this->getConfigData('test_mode'));
        $payhostFields = $this->prepareFields($order);

        $response = $this->curlPost(self::PAYHOSTURL, $payhostFields);

        $respArray = $this->formatXmlToArray($response);
        if (($respArray['ns2SinglePaymentResponse']['ns2WebPaymentResponse']['ns2Status']['ns2StatusName'] ?? '')
            === 'Error') {
            return $respArray['ns2SinglePaymentResponse']['ns2WebPaymentResponse']['ns2Status'] ?? null;
        }
        $respData = $respArray['ns2SinglePaymentResponse']['ns2WebPaymentResponse']['ns2Redirect']['ns2UrlParams'];

        foreach ($respData as $field) {
            $processData[$field['ns2key']] = $field['ns2value'];
        }
        $processData['PAYMENT_TITLE'] = "PAYGATE_PAYHOST";

        $this->_paymentData->createTransaction($order, $processData);

        return ($processData);
    }

    /**
     * Get the Paygate credentials
     *
     * @return array
     */
    public function getPayGateCredentials()
    {
        // If NOT test mode, use normal credentials
        $cred = [];
        if ($this->getConfigData('test_mode') != '1') {
            $cred['paygateId'] = $this->getConfigData('paygate_id');
            $cred['password']  = $this->getConfigData('encryption_key');
        } else {
            $cred['paygateId'] = '10011072130';
            $cred['password']  = 'test';
        }

        return $cred;
    }

    /**
     * Prepare the request fields and get a response
     *
     * @param DataObject|InfoInterface $order
     *
     * @return mixed
     */
    public function prepareFields($order): string
    {
        $pre = __METHOD__ . ' : ';

        $order->getPayment()?->getData();
        $additionalInformation = $order->getPayment()->getAdditionalInformation();
        $vaultId               = $additionalInformation['payhost-payvault-method'] ?? '';

        $vaultActive = false;
        if ((int)$this->getConfigData('payhost_cc_vault_active') === 1) {
            $vaultActive = true;
        }
        $vaultActive = $vaultActive && ($vaultId === 'new-save' || $vaultId === '1' || strlen($vaultId) > 10);
        $vaultToken  = null;
        if (strlen($vaultId) > 10) {
            $token      = $this->paymentTokenManagement->getByPublicHash($vaultId, $order->getCustomerId());
            $vaultToken = $token->getGatewayToken();
        }

        $this->_logger->debug($pre . 'serverMode : ' . $this->getConfigData('test_mode'));

        $cred = $this->getPayGateCredentials();

        $paygateId = $cred['paygateId'];
        $password  = $cred['password'];

        $billing = $order->getBillingAddress();
        $billing->getCountryId();

        $DateTime = new DateTime();

        $notifyUrl = $this->_urlBuilder->getUrl('payhost/notify', self::SECURE)
                     . '?gid=' . $order->getRealOrderId();

        $returnUrl = $this->_urlBuilder->getUrl('payhost/redirect/success', self::SECURE) .
                     '?gid=' . $order->getRealOrderId();

        $amount = number_format($this->getTotalAmount($order), 2, '', '');
        $tdate  = $DateTime->format('Y-m-d') . 'T' . $DateTime->format('h:i:s');

        $return = '<SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/">
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
                            ';
        if ($vaultToken) {
            $return .= "
                            <Vault>1</Vault>
                            <VaultId>$vaultToken</VaultId>
";
        } elseif ($vaultActive) {
            $return .= '
                            <Vault>' . (string)$vaultActive . '</Vault>';
        }

        $return .= '        <Redirect>
                                <NotifyUrl>' . $notifyUrl . '</NotifyUrl>
                                <ReturnUrl>' . $returnUrl . '</ReturnUrl>
                            </Redirect>
                            <Order>
                                <MerchantOrderId>' . $order->getRealOrderId() . '</MerchantOrderId>
                                <Currency>ZAR</Currency>
                                <Amount>' . $amount . '</Amount>
                                <TransactionDate>' . $tdate . '</TransactionDate>
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

        return $return;
    }

    /**
     * Send a curl request with the xml fields
     *
     * @param string $url
     * @param XML $xml
     *
     * @return XML
     */
    public function curlPost($url, $xml)
    {
        // phpcs:ignore Magento2.Security.InsecureFunction
        $curl = curl_init();

        curl_setopt_array(
            $curl,
            [
                CURLOPT_URL            => self::PAYHOSTURL,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING       => "",
                CURLOPT_MAXREDIRS      => 10,
                CURLOPT_TIMEOUT        => 0,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST  => "POST",
                CURLOPT_POSTFIELDS     => "$xml",
                CURLOPT_HTTPHEADER     => [
                    "Content-Type: text/xml",
                    "SOAPAction: WebPaymentRequest"
                ],
            ]
        );
        $response = curl_exec($curl);

        curl_close($curl);

        return $response;
    }

    /**
     * Get error codes as an array
     *
     * @return array
     */
    public function getErrorCodes()
    {
        return [
            'DATA_CHK'        => 'Checksum calculated incorrectly',
            'DATA_PAY_REQ_ID' => 'Pay request ID missing or invalid',
            'ND_INV_PGID'     => 'Invalid Paygate ID',
            'ND_INV_PRID'     => 'Invalid Pay Request ID',
            'PGID_NOT_EN'     => 'Paygate ID not enabled, there are no available payment methods
             or there are no available currencies',
            'TXN_CAN'         => 'Transaction has already been cancelled',
            'TXN_CMP'         => 'Transaction has already been completed',
            'TXN_PRC'         => 'Transaction is older than 30 minutes or there has been an error processing it',
            'DATA_PM'         => 'Pay Method or Pay Method Detail fields invalid'
        ];
    }

    /**
     * Get the total amount
     *
     * @param DataObject|InfoInterface $order
     *
     * @return string
     */
    public function getTotalAmount($order)
    {
        return $this->getNumberFormat($order->getGrandTotal());
    }

    /**
     * Format the amount to a float
     *
     * @param int $number
     *
     * @return float
     */
    public function getNumberFormat($number)
    {
        return number_format($number, 2, '.', '');
    }

    /**
     * Get the success url
     *
     * @return string
     */
    public function getPaidSuccessUrl()
    {
        return $this->_urlBuilder->getUrl('payhost/redirect/success', self::SECURE);
    }

    /**
     * Get the order place redirect url
     *
     * @return string
     */
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
     * Intialize the Paygate Model
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

        return $this;
    }

    /*
     * called dynamically by checkout's framework.
     */

    /**
     * Get the paid notify url
     *
     * @return string
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

    /**
     * Get the country details
     *
     * @param string $code2
     *
     * @return string
     */
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
        $refundResponse  = $this->processRefund($payment, $amount);
        $transactionData = $refundResponse['ns2SingleFollowUpResponse']['ns2RefundResponse']['ns2Status'];

        /* Set Comment to Order*/
        if ($transactionData['ns2TransactionId']
            && $transactionData['ns2PayRequestId']
            && $transactionData['ns2StatusName'] == "Completed") {
            $trxId    = $transactionData['ns2TransactionId'];
            $payReqId = $transactionData['ns2PayRequestId'];
            $status   = $transactionData['ns2StatusName'];
            $order->addStatusHistoryComment(
                __(
                    "Order Successfullt Refunded with Transaction Id - 
                    $trxId Pay Request Id - $payReqId  & status - $status."
                )
            )->save();

            return true;
        } else {
            $order->addStatusHistoryComment(__("Refund not successfull."))->save();

            return false;
        }
    }

    /**
     * Process the refund
     *
     * @param DataObject|InfoInterface $payment
     * @param float $amount
     *
     * @return array
     */
    public function processRefund($payment, $amount)
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
        $queryResponse        = $this->processRefundRequest($query);
        $queryArray           = $this->formatXmlToArray($queryResponse);
        $PaygateTransactionId =
            $queryArray['ns2SingleFollowUpResponse']['ns2QueryResponse']['ns2Status']['ns2TransactionId'];

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

        $refundResponse = $this->processRefundRequest($refund);

        return $this->formatXmlToArray($refundResponse);
    }

    /**
     * Process the refund request
     *
     * @param XML $xml
     *
     * @return XML
     */
    public function processRefundRequest($xml)
    {
        $xml = $this->makeUpXml($xml);

        return $this->curlPost(self::PAYHOSTURL, $xml);
    }

    /**
     * Makeup the XML
     *
     * @param XML $xml
     *
     * @return XML
     */
    public function makeUpXml($xml)
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

    /**
     * Process the refund
     *
     * @param XML $response
     *
     * @return XML
     */
    public function formatXmlToArray($response)
    {
        $response = preg_replace("/(<\/?)(\w+):([^>]*>)/", "$1$2$3", $response);
        $xml      = new SimpleXMLElement($response);
        $body     = $xml->xpath('//SOAP-ENV:Body')[0];

        return json_decode(json_encode((array)$body), true);
    }

    /**
     * Get the order from the model by id
     *
     * @param int $order_id
     *
     * @return DataObject|InfoInterface
     */
    public function getOrderbyOrderId($order_id)
    {
        return $this->orderRepository->get($order_id);
    }

    /**
     * Fetch the transaction info
     *
     * @param InfoInterface $payment
     * @param string $transactionId
     *
     * @inheritdc
     */
    public function fetchTransactionInfo(InfoInterface $payment, $transactionId)
    {
        $state = ObjectManager::getInstance()->get(State::class);
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
     * Save the vault data
     *
     * @param \Magento\Sales\Model\Order $order
     * @param array $data
     *
     * @return void
     * @throws \Exception
     */
    public function saveVaultData(Order $order, array $data)
    {
        $paymentToken = $this->paymentTokenManagement->getByGatewayToken(
            $data['ns2VaultId'],
            'payhost',
            $order->getCustomerId()
        ) ?? $this->creditCardTokenFactory->create();

        $paymentToken->setGatewayToken($data['ns2VaultId']);
        $last4 = substr($data['ns2PayVaultData'][0]['ns2value'], -4);
        $month = substr($data['ns2PayVaultData'][1]['ns2value'], 0, 2);
        $year  = substr($data['ns2PayVaultData'][1]['ns2value'], -4);
        $paymentToken->setTokenDetails(
            json_encode(
                [
                    'type'           => $data['ns2PaymentType']['ns2Detail'],
                    'maskedCC'       => $last4,
                    'expirationDate' => "$month/$year",
                ]
            )
        );
        $paymentToken->setExpiresAt($this->getExpirationDate($month, $year));

        $paymentToken->setMaskedCC("$last4");
        $paymentToken->setIsActive(true);
        $paymentToken->setIsVisible(true);
        $paymentToken->setPaymentMethodCode('payhost');
        $paymentToken->setCustomerId($order->getCustomerId());
        $paymentToken->setPublicHash($this->generatePublicHash($paymentToken));

        $this->paymentTokenRepository->save($paymentToken);

        /* Retrieve Payment Token */
        $this->creditCardTokenFactory->create();
        $this->addLinkToOrderPayment($paymentToken->getEntityId(), $order->getPayment()->getEntityId());
    }

    /**
     * Gets gateway code
     *
     * @return \PayGate\PayWeb\Model\PayGate|string
     */
    public function getCode()
    {
        return $this->_code;
    }

    /**
     * Gets gateway form block type
     *
     * @return string
     */
    public function getFormBlockType()
    {
        return $this->_formBlockType;
    }

    /**
     * Gets gateway title
     *
     * @return string
     */
    public function getTitle()
    {
        return $this->getConfigData('title');
    }

    /**
     * Gets gateway store name
     *
     * @return mixed
     */
    public function getStore()
    {
        return $this->getStoreName();
    }

    /**
     * Gateway can order attribute
     *
     * @return bool
     */
    public function canOrder()
    {
        return $this->_canOrder;
    }

    /**
     * Gateway can authorize attribute
     *
     * @return bool
     */
    public function canAuthorize()
    {
        return $this->_canAuthorize;
    }

    /**
     * Gateway can capture attribute
     *
     * @return bool
     */
    public function canCapture()
    {
        return $this->_canCapture;
    }

    /**
     * Gateway can capture partial attribute
     *
     * @return bool
     */
    public function canCapturePartial()
    {
        return $this->_canCapture;
    }

    /**
     * Gateway can capture once attribute
     *
     * @return bool
     */
    public function canCaptureOnce()
    {
        return $this->_canCapture;
    }

    /**
     * Gateway can void attribute
     *
     * @return bool
     */
    public function canVoid()
    {
        return $this->_canVoid;
    }

    /**
     * Gateway can use internal attribute
     *
     * @return bool
     */
    public function canUseInternal()
    {
        return $this->_canUseInternal;
    }

    /**
     * Gateway can use checkout attribute
     *
     * @return bool
     */
    public function canUseCheckout()
    {
        return $this->_canUseCheckout;
    }

    /**
     * Gateway can edit attribute
     *
     * @return false
     */
    public function canEdit()
    {
        return true;
    }

    /**
     * Gateway can transaction fetch info attribute
     *
     * @return bool
     */
    public function canFetchTransactionInfo()
    {
        return $this->_canFetchTransactionInfo;
    }

    /**
     * Gateway is gateway attribute
     *
     * @return bool
     */
    public function isGateway()
    {
        return $this->_isGateway;
    }

    /**
     * Gateway is offline attribute
     *
     * @return false
     */
    public function isOffline()
    {
        return false;
    }

    /**
     * Gateway is initialisation needed attribute
     *
     * @return bool
     */
    public function isInitializeNeeded()
    {
        return $this->_isInitializeNeeded;
    }

    /**
     * To check billing country is allowed for the payment method
     *
     * @param string $country
     *
     * @return bool
     */
    public function canUseForCountry($country)
    {
        /*
        for specific country, the flag will set up as 1
        */
        if ($this->getConfigData('allowspecific') == 1) {
            $availableCountries = explode(',', $this->getConfigData('specificcountry') ?? '');
            if (!in_array($country, $availableCountries)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Gateway get info block type
     *
     * @return string
     */
    public function getInfoBlockType()
    {
        return $this->_infoBlockType;
    }

    /**
     * Retrieve payment information model object
     *
     * @return InfoInterface
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function getInfoInstance()
    {
        $instance = $this->getData('info_instance');
        if (!$instance instanceof InfoInterface) {
            throw new \Magento\Framework\Exception\LocalizedException(
                __('We cannot retrieve the payment information object instance.')
            );
        }

        return $instance;
    }

    /**
     * Gateway set info instance
     *
     * @param InfoInterface $info
     *
     * @return false
     */
    public function setInfoInstance(InfoInterface $info)
    {
        $this->setData('info_instance', $info);
    }

    /**
     * Validate payment method information object
     *
     * @return $this
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function validate()
    {
        /**
         * to validate payment method is allowed for billing country or not
         */
        $paymentInfo = $this->getInfoInstance();
        if ($paymentInfo instanceof Payment) {
            $billingCountry = $paymentInfo->getOrder()->getBillingAddress()->getCountryId();
        } else {
            $billingCountry = $paymentInfo->getQuote()->getBillingAddress()->getCountryId();
        }
        $billingCountry = $billingCountry ?: $this->directory->getDefaultCountry();

        if (!$this->canUseForCountry($billingCountry)) {
            throw new \Magento\Framework\Exception\LocalizedException(
                __('You can\'t use the payment type you selected to make payments to the billing country.')
            );
        }

        return $this;
    }

    /**
     * Gateway order function
     *
     * @param InfoInterface $payment
     * @param float $amount
     *
     * @return PayGate
     */
    public function order(InfoInterface $payment, $amount)
    {
        return $this;
    }

    /**
     * Gateway authorize function
     *
     * @param InfoInterface $payment
     * @param float $amount
     *
     * @return PayGate
     */
    public function authorize(InfoInterface $payment, $amount)
    {
        return $this;
    }

    /**
     * Gateway capture function
     *
     * @param InfoInterface $payment
     * @param float $amount
     *
     * @return PayGate
     */
    public function capture(InfoInterface $payment, $amount)
    {
        return $this;
    }

    /**
     * Gateway cancel function
     *
     * @param InfoInterface $payment
     *
     * @return PayGate
     */
    public function cancel(InfoInterface $payment)
    {
        return $this;
    }

    /**
     * Gateway void function
     *
     * @param InfoInterface $payment
     *
     * @return PayGate
     */
    public function void(InfoInterface $payment)
    {
        return $this;
    }

    /**
     * Gateway can review attribute
     *
     * @return bool
     */
    public function canReviewPayment()
    {
        return $this->_canReviewPayment;
    }

    /**
     * Gateway accept payment attribute
     *
     * @param InfoInterface $payment
     *
     * @return false
     */
    public function acceptPayment(InfoInterface $payment)
    {
        return false;
    }

    /**
     * Gateway deny payment attribute
     *
     * @param InfoInterface $payment
     *
     * @return PayGate
     */
    public function denyPayment(InfoInterface $payment)
    {
        return $this;
    }

    /**
     * Retrieve information from payment configuration
     *
     * @param string $field
     * @param int|string|null|Store $storeId
     *
     * @return mixed
     * @throws NoSuchEntityException
     */
    public function getConfigData($field, $storeId = null)
    {
        if ('order_place_redirect_url' === $field) {
            return $this->getOrderPlaceRedirectUrl();
        }
        if (null === $storeId) {
            $storeId = $this->_storeManager->getStore()->getId();
        }
        $path = 'payment/' . $this->getCode() . '/' . $field;

        return $this->_scopeConfig->getValue($path, \Magento\Store\Model\ScopeInterface::SCOPE_STORE, $storeId);
    }

    /**
     * Assign data to info model instance
     *
     * @param array|\Magento\Framework\DataObject $data
     *
     * @return $this
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function assignData(\Magento\Framework\DataObject $data)
    {
        $this->_eventManager->dispatch(
            'payment_method_assign_data_' . $this->getCode(),
            [
                AbstractDataAssignObserver::METHOD_CODE => $this,
                AbstractDataAssignObserver::MODEL_CODE  => $this->getInfoInstance(),
                AbstractDataAssignObserver::DATA_CODE   => $data
            ]
        );

        $this->_eventManager->dispatch(
            'payment_method_assign_data',
            [
                AbstractDataAssignObserver::METHOD_CODE => $this,
                AbstractDataAssignObserver::MODEL_CODE  => $this->getInfoInstance(),
                AbstractDataAssignObserver::DATA_CODE   => $data
            ]
        );

        return $this;
    }

    /**
     * Is active
     *
     * @param int|null $storeId
     *
     * @return bool
     */
    public function isActive($storeId = null)
    {
        return (bool)(int)$this->getConfigData('active', $storeId);
    }

    /**
     * Get the store name
     *
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

    /**
     * Generate vault payment public hash
     *
     * @param PaymentTokenInterface $paymentToken
     *
     * @return string
     */
    protected function generatePublicHash(PaymentTokenInterface $paymentToken): string
    {
        $hashKey = $paymentToken->getGatewayToken();
        if ($paymentToken->getCustomerId()) {
            $hashKey = $paymentToken->getCustomerId();
        }
        $paymentToken->getTokenDetails();

        $hashKey .= $paymentToken->getPaymentMethodCode() . $paymentToken->getType() . $paymentToken->getGatewayToken()
                    . $paymentToken->getTokenDetails();

        return $this->encryptor->getHash($hashKey);
    }

    /**
     * Get the expiration date
     *
     * @param string $month
     * @param string $year
     *
     * @return string
     * @throws \Exception
     */
    private function getExpirationDate($month, $year)
    {
        $response = '';
        try {
            $expDate = new \DateTime(
                $year
                . '-'
                . $month
                . '-'
                . '01'
                . ' '
                . '00:00:00',
                new \DateTimeZone('UTC')
            );

            $expDate->add(new \DateInterval('P1M'));

            $response = $expDate->format('Y-m-d 00:00:00');
        } catch (\Exception $e) {
            $this->_logger->debug($e->getMessage());
        }

        return $response;
    }
}
