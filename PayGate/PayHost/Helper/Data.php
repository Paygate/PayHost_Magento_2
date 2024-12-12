<?php

/*
 * Copyright (c) 2024 Payfast (Pty) Ltd
 *
 * Author: App Inlet (Pty) Ltd
 *
 * Released under the GNU General Public License
 */

namespace PayGate\PayHost\Helper;

use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Magento\Directory\Model\CurrencyFactory;
use Magento\Framework\App\Config\BaseFactory;
use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Framework\DataObject;
use Magento\Framework\DB\Transaction as DBTransaction;
use Magento\Framework\Exception\LocalizedException;
use Magento\Payment\Api\PaymentMethodListInterface;
use Magento\Payment\Model\InfoInterface;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Sales\Api\Data\TransactionSearchResultInterfaceFactory;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Api\OrderStatusHistoryRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Email\Sender\InvoiceSender;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;
use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\Order\Payment\Transaction;
use Magento\Sales\Model\Order\Payment\Transaction\Builder;
use Magento\Sales\Model\Service\InvoiceService;
use Magento\Store\Model\StoreManagerInterface;
use PayGate\PayHost\Model\Config as PayGateConfig;
use Psr\Log\LoggerInterface;
use SimpleXMLElement;

/**
 * Paygate Data helper
 */
class Data extends AbstractHelper
{
    public const PAYHOSTURL = "https://secure.paygate.co.za/payhost/process.trans";

    /**
     * Cache for shouldAskToCreateBillingAgreement()
     *
     * @var bool
     */
    protected static $_shouldAskToCreateBillingAgreement = false;

    /**
     * @var \Magento\Payment\Helper\Data
     */
    protected $_paymentData;
    /**
     * @var LoggerInterface
     */
    protected $_logger;
    /**
     * @var StoreManagerInterface
     */
    protected $storeManager;
    /**
     * @var CurrencyFactory
     */
    protected $currencyFactory;
    /**
     * @var Magento\Sales\Model\Order\Payment\Transaction\Builder $_transactionBuilder
     */
    protected $_transactionBuilder;
    /**
     * @var TransactionSearchResultInterfaceFactory
     */
    protected $transactionSearchResultInterfaceFactory;
    /**
     * @var OrderSender
     */
    protected $OrderSender;
    /**
     * @var InvoiceService
     */
    protected $_invoiceService;
    /**
     * @var InvoiceSender
     */
    protected $invoiceSender;
    /**
     * @var DBTransaction
     */
    protected $dbTransaction;
    /**
     * @var array
     */
    private $methodCodes;
    /**
     * @var ConfigFactory
     */
    private $configFactory;
    /**
     * @var ConfigFactory
     */
    private $_paygateconfig;
    /**
     * @var PaymentMethodListInterface
     */
    protected PaymentMethodListInterface $paymentMethodList;
    /**
     * @var OrderRepositoryInterface
     */
    private OrderRepositoryInterface $orderRepository;
    /**
     * @var OrderStatusHistoryRepositoryInterface
     */
    private OrderStatusHistoryRepositoryInterface $orderStatusHistoryRepository;

    /**
     * @param Context $context
     * @param \Magento\Payment\Helper\Data $paymentData
     * @param BaseFactory $configFactory
     * @param PayGateConfig $paygateconfig
     * @param StoreManagerInterface $storeManager
     * @param CurrencyFactory $currencyFactory
     * @param Builder $_transactionBuilder
     * @param TransactionSearchResultInterfaceFactory $transactionSearchResultInterfaceFactory
     * @param OrderSender $OrderSender
     * @param DBTransaction $dbTransaction
     * @param InvoiceService $invoiceService
     * @param InvoiceSender $invoiceSender
     * @param PaymentMethodListInterface $paymentMethodList
     * @param array $methodCodes
     * @param OrderRepositoryInterface $orderRepository
     * @param OrderStatusHistoryRepositoryInterface $orderStatusHistoryRepository
     */
    public function __construct(
        Context $context,
        \Magento\Payment\Helper\Data $paymentData,
        BaseFactory $configFactory,
        PayGateConfig $paygateconfig,
        StoreManagerInterface $storeManager,
        CurrencyFactory $currencyFactory,
        Builder $_transactionBuilder,
        TransactionSearchResultInterfaceFactory $transactionSearchResultInterfaceFactory,
        OrderSender $OrderSender,
        DBTransaction $dbTransaction,
        InvoiceService $invoiceService,
        InvoiceSender $invoiceSender,
        PaymentMethodListInterface $paymentMethodList,
        array $methodCodes,
        OrderRepositoryInterface $orderRepository,
        OrderStatusHistoryRepositoryInterface $orderStatusHistoryRepository
    ) {
        $this->_logger = $context->getLogger();

        $pre = __METHOD__ . " : ";
        $this->_logger->debug($pre . 'bof, methodCodes is : ', $methodCodes);

        $this->_paymentData   = $paymentData;
        $this->methodCodes    = $methodCodes;
        $this->configFactory  = $configFactory;
        $this->_paygateconfig = $paygateconfig;

        /* Currency Converter */
        $this->storeManager    = $storeManager;
        $this->currencyFactory = $currencyFactory;

        parent::__construct($context);
        $this->_logger->debug($pre . 'eof');

        $this->transactionSearchResultInterfaceFactory = $transactionSearchResultInterfaceFactory;
        $this->_transactionBuilder                     = $_transactionBuilder;
        $this->OrderSender                             = $OrderSender;
        $this->_invoiceService                         = $invoiceService;
        $this->invoiceSender                           = $invoiceSender;
        $this->dbTransaction                           = $dbTransaction;
        $this->paymentMethodList                       = $paymentMethodList;
        $this->orderRepository                         = $orderRepository;
        $this->orderStatusHistoryRepository            = $orderStatusHistoryRepository;
    }

    /**
     * Check whether customer should be asked confirmation to sign a billing agreement.
     *
     * @return bool
     */
    public function shouldAskToCreateBillingAgreement()
    {
        $pre = __METHOD__ . " : ";
        $this->_logger->debug($pre . "bof");
        $this->_logger->debug($pre . "eof");

        return self::$_shouldAskToCreateBillingAgreement;
    }

    /**
     * Retrieve available billing agreement methods
     *
     * @param CartInterface $quote
     *
     * @return MethodInterface[]
     */
    public function getBillingAgreementMethods(CartInterface $quote)
    {
        $pre = __METHOD__ . " : ";
        $this->_logger->debug($pre . 'bof');
        $result           = [];
        $availableMethods = $this->paymentMethodList->getActiveList($quote->getId());

        foreach ($availableMethods as $method) {
            if ($method instanceof MethodInterface) {
                $result[] = $method;
            }
        }
        $this->_logger->debug($pre . 'eof | result : ', $result);

        return $result;
    }

    /**
     * Convert Currency to Order Currency. If both currency are same dont do any changes
     *
     * @param DataObject|InfoInterface $order
     * @param int $price
     *
     * @return int
     */
    public function convertToOrderCurrency($order, $price)
    {
        $storeCurrency = $order->getStoreCurrencyCode();
        $orderCurrency = $order->getOrderCurrencyCode();
        if ($storeCurrency != $orderCurrency) {
            $rate = $this->currencyFactory->create()->load($storeCurrency)->getAnyRate($orderCurrency);

            return $price * $rate;
        }

        return $price;
    }

    /**
     * Get payment transaction data from the db
     *
     * @param DataObject|InfoInterface $payment
     * @param int $txn_id
     *
     * @return int
     */
    public function getTransactionData($payment, $txn_id)
    {
        $transactionSearchResult = $this->transactionSearchResultInterfaceFactory;

        return $transactionSearchResult->create()->addPaymentIdFilter($payment->getId())->getFirstItem();
    }

    /**
     * Create transanction
     *
     * @param DataObject|InfoInterface $order
     * @param DataObject|InfoInterface $paymentData
     *
     * @return mixed
     */
    public function createTransaction($order = null, $paymentData = [])
    {
        $response = '';
        try {
            // Get payment object from order object
            $payment = $order->getPayment();
            $payment->setLastTransId($paymentData['PAY_REQUEST_ID'])
                    ->setTransactionId($paymentData['PAY_REQUEST_ID'])
                    ->setAdditionalInformation(
                        [Transaction::RAW_DETAILS => (array)$paymentData]
                    );
            $formatedPrice = $order->getBaseCurrency()->formatTxt(
                $order->getGrandTotal()
            );

            $message = __('The authorized amount is %1.', $formatedPrice);
            // Get the object of builder class
            $trans       = $this->_transactionBuilder;
            $transaction = $trans->setPayment($payment)
                                 ->setOrder($order)
                                 ->setTransactionId($paymentData['PAY_REQUEST_ID'])
                                 ->setAdditionalInformation(
                                     [Transaction::RAW_DETAILS => (array)$paymentData]
                                 )
                                 ->setFailSafe(true)
                // Build method creates the transaction and returns the object
                                 ->build(Transaction::TYPE_CAPTURE);

            $payment->addTransactionCommentsToOrder(
                $transaction,
                $message
            );
            $payment->setParentTransactionId(null);
            $this->orderRepository->save($order);

            $response = $transaction->getTransactionId();
        } catch (Exception $e) {
            $this->_logger->error($e->getMessage());
        }

        return $response;
    }

    /**
     * Get the configuration data
     *
     * @param string $field
     *
     * @return string
     */
    public function getConfigData($field)
    {
        return $this->_paygateconfig->getConfig($field);
    }

    /**
     * Get Paygate credentials
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
     * Get the query result
     *
     * @param int $transaction_id
     *
     * @return int
     */
    public function getQueryResult($transaction_id)
    {
        $queryFields = $this->prepareQueryXml($transaction_id);
        $response    = $this->guzzlePost(self::PAYHOSTURL, $queryFields);
        $respArray   = $this->formatXmlToArray($response);

        return $respArray['ns2SingleFollowUpResponse']['ns2QueryResponse']['ns2Status'];
    }

    /**
     * Send a curl post with the xml data
     *
     * @param string $url
     * @param XML $xml
     *
     * @return XML
     */
    public function guzzlePost($url, $xml)
    {
        // Initialize Guzzle client
        $client = new Client();

        try {
            // Send POST request
            $response = $client->post(
                $url,
                [
                    'body'            => $xml,
                    'headers'         => [
                        'Content-Type' => 'text/xml',
                        'SOAPAction'   => 'WebPaymentRequest',
                    ],
                    'timeout'         => 0, // Optionally set timeout as needed
                    'allow_redirects' => true, // Similar to CURLOPT_FOLLOWLOCATION
                    'http_errors'     => false // Disable throwing exceptions on HTTP errors
                ]
            );

            // Get the response body as a string
            return $response->getBody()->getContents();
        } catch (RequestException $e) {
            // Handle Guzzle request exceptions if needed
            $this->_logger->error('Guzzle request failed: ' . $e->getMessage());

            return null;
        }
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
     * Prepare the xml from the query
     *
     * @param XML $pay_request_id
     *
     * @return XML
     */
    public function prepareQueryXml($pay_request_id)
    {
        $cred      = $this->getPayGateCredentials();
        $paygateId = $cred['paygateId'];
        $password  = $cred['password'];

        return '<SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/">
                <SOAP-ENV:Header/>
                <SOAP-ENV:Body>
                    <SingleFollowUpRequest xmlns="http://www.paygate.co.za/PayHOST">
						<QueryRequest>
							<Account>
								<PayGateId>' . $paygateId . '</PayGateId>
								<Password>' . $password . '</Password>
							</Account>
							<PayRequestId>' . $pay_request_id . '</PayRequestId>
						</QueryRequest>
					</SingleFollowUpRequest>
                </SOAP-ENV:Body>
            </SOAP-ENV:Envelope>';
    }

    /**
     * Update the payment status of an order
     *
     * @param DataObject|InfoInterface $order
     * @param array $resp
     */
    public function updatePaymentStatus($order, $resp)
    {
        if (!empty($resp)) {
            if ($resp['ns2TransactionStatusCode'] == 1) {
                $status = Order::STATE_PROCESSING;
                $order->setStatus($status);
                $order->setState($status);
                $order->save();
                try {
                    if ($order->getInvoiceCollection()->count() <= 0) {
                        $this->generateInvoice($order);
                    }
                } catch (Exception $ex) {
                    $this->_logger->error($ex->getMessage());
                }
            } else {
                $status = Order::STATE_CANCELED;
                $order->setStatus($status);
                $order->setState($status);
                $order->save();
            }
            $this->createTransaction($order, $resp);
        }
    }

    /**
     * Generate the order invoice
     *
     * @param Order $order
     */
    public function generateInvoice(Order $order)
    {
        $order_successful_email = $this->getConfigData('order_email');

        if ($order_successful_email != '0') {
            $this->OrderSender->send($order);
            // Add status history comment
            $history = $order->addCommentToStatusHistory(
                __('Notified customer about order #%1.', $order->getId())
            );
            $history->setIsCustomerNotified(true);

            try {
                // Save the status history
                $this->orderStatusHistoryRepository->save($history);

                // Save the order
                $this->orderRepository->save($order);
            } catch (LocalizedException $e) {
                // Handle any exceptions during the save process
                $this->_logger->error('Order save error: ' . $e->getMessage());
            }
        }

        // Capture invoice when payment is successfull
        $invoice = $this->_invoiceService->prepareInvoice($order);
        $invoice->setRequestedCaptureCase(Invoice::CAPTURE_ONLINE);
        $invoice->register();

        // Save the invoice to the order
        $transaction = $this->dbTransaction
            ->addObject($invoice)
            ->addObject($invoice->getOrder());

        $transaction->save();

        // Magento\Sales\Model\Order\Email\Sender\InvoiceSender
        $send_invoice_email = $this->getConfigData('invoice_email');
        if ($send_invoice_email != '0') {
            $this->invoiceSender->send($invoice);

            // Add status history comment
            $history = $order->addCommentToStatusHistory(
                __('Notified customer about order #%1.', $invoice->getId())
            );
            $history->setIsCustomerNotified(true);

            try {
                // Save the status history
                $this->orderStatusHistoryRepository->save($history);

                // Save the order
                $this->orderRepository->save($order);
            } catch (LocalizedException $e) {
                // Handle any exceptions during the save process
                $this->_logger->error('Order save error: ' . $e->getMessage());
            }
        }
    }
}
