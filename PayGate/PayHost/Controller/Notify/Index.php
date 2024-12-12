<?php

/*
 * Copyright (c) 2024 Payfast (Pty) Ltd
 *
 * Author: App Inlet (Pty) Ltd
 *
 * Released under the GNU General Public License
 */

namespace PayGate\PayHost\Controller\Notify;

use Exception;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Customer\Model\Url;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\State;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\DB\Transaction as DBTransaction;
use Magento\Framework\DB\TransactionFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\Session\Generic;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Framework\Url\Helper\Data;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Result\PageFactory;
use Magento\Framework\Webapi\Rest\Request;
use Magento\Framework\Webapi\Rest\Response;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Sales\Api\Data\TransactionSearchResultInterfaceFactory;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Api\OrderStatusHistoryRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Email\Sender\InvoiceSender;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;
use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\Order\Payment\Transaction;
use Magento\Sales\Model\Order\Payment\Transaction\Builder;
use Magento\Sales\Model\OrderFactory;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory;
use Magento\Sales\Model\Service\InvoiceService;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Vault\Api\PaymentTokenManagementInterface;
use PayGate\PayHost\Controller\AbstractPaygate;
use PayGate\PayHost\Controller\Redirect\Success;
use PayGate\PayHost\Helper\Data as PaygateHelper;
use PayGate\PayHost\Model\Config;
use PayGate\PayHost\Model\PayGate;
use Psr\Log\LoggerInterface;

class Index extends AbstractPaygate
{

    /**
     * @var PageFactory
     */
    protected PageFactory $pageFactory;

    /**
     * Config method type
     *
     * @var string
     */
    protected $_configMethod = Config::METHOD_CODE;
    /**
     * @var JsonFactory
     */
    protected JsonFactory $jsonFactory;

    /**
     * @var ResultFactory
     */
    protected ResultFactory $resultFactory;

    /**
     * @var ManagerInterface
     */
    protected ManagerInterface $messageManager;

    /**
     * @var LoggerInterface
     */
    protected LoggerInterface $logger;

    /**
     * @var Order
     */
    protected Order $order;
    /**
     * @var CheckoutSession
     */
    protected CheckoutSession $checkoutSession;
    /**
     * @var Response
     */
    private Response $response;
    /**
     * @var CustomerSession
     */
    protected CustomerSession $customerSession;
    protected OrderRepositoryInterface $orderRepository;
    protected CartRepositoryInterface $quoteRepository;
    /**
     * @var Request
     */
    protected Request $request;
    /**
     * @var PayGate
     */
    protected PayGate $paymentMethod;
    private string $secret;
    private string $id;
    /**
     * @var Builder
     */
    protected Builder $transactionBuilder;
    /**
     * @var OrderSender
     */
    protected OrderSender $orderSender;
    /**
     * @var OrderStatusHistoryRepositoryInterface
     */
    protected OrderStatusHistoryRepositoryInterface $orderStatusHistoryRepository;


    /**
     * @param PageFactory $pageFactory
     * @param CustomerSession $customerSession
     * @param CheckoutSession $checkoutSession
     * @param OrderFactory $orderFactory
     * @param Generic $paygateSession
     * @param Data $urlHelper
     * @param Url $customerUrl
     * @param LoggerInterface $logger
     * @param TransactionFactory $transactionFactory
     * @param InvoiceService $invoiceService
     * @param InvoiceSender $invoiceSender
     * @param PayGate $paymentMethod
     * @param UrlInterface $urlBuilder
     * @param OrderRepositoryInterface $orderRepository
     * @param StoreManagerInterface $storeManager
     * @param OrderSender $orderSender
     * @param DateTime $date
     * @param CollectionFactory $orderCollectionFactory
     * @param Builder $transactionBuilder
     * @param DBTransaction $dbTransaction
     * @param Order $order
     * @param Config $config
     * @param State $state
     * @param PaygateHelper $paygatehelper
     * @param TransactionSearchResultInterfaceFactory $transactionSearchResultInterfaceFactory
     * @param CartRepositoryInterface $quoteRepository
     * @param PaymentTokenManagementInterface $tokenManagement
     * @param JsonFactory $jsonFactory
     * @param ResultFactory $resultFactory
     * @param Request $request
     * @param ManagerInterface $messageManager
     * @param Response $response
     * @param OrderStatusHistoryRepositoryInterface $orderStatusHistoryRepository
     */
    public function __construct(
        PageFactory $pageFactory,
        CustomerSession $customerSession,
        CheckoutSession $checkoutSession,
        OrderFactory $orderFactory,
        Generic $paygateSession,
        Data $urlHelper,
        Url $customerUrl,
        LoggerInterface $logger,
        TransactionFactory $transactionFactory,
        InvoiceService $invoiceService,
        InvoiceSender $invoiceSender,
        PayGate $paymentMethod,
        UrlInterface $urlBuilder,
        OrderRepositoryInterface $orderRepository,
        StoreManagerInterface $storeManager,
        OrderSender $orderSender,
        DateTime $date,
        CollectionFactory $orderCollectionFactory,
        Builder $transactionBuilder,
        DBTransaction $dbTransaction,
        Order $order,
        Config $config,
        State $state,
        PaygateHelper $paygatehelper,
        TransactionSearchResultInterfaceFactory $transactionSearchResultInterfaceFactory,
        CartRepositoryInterface $quoteRepository,
        PaymentTokenManagementInterface $tokenManagement,
        JsonFactory $jsonFactory,
        ResultFactory $resultFactory,
        Request $request,
        ManagerInterface $messageManager,
        OrderStatusHistoryRepositoryInterface $orderStatusHistoryRepository
    ) {
        $this->pageFactory                  = $pageFactory;
        $this->jsonFactory                  = $jsonFactory;
        $this->resultFactory                = $resultFactory;
        $this->messageManager               = $messageManager;
        $this->logger                       = $logger;
        $this->order                        = $order;
        $this->checkoutSession              = $checkoutSession;
        $this->customerSession              = $customerSession;
        $this->orderRepository              = $orderRepository;
        $this->quoteRepository              = $quoteRepository;
        $this->request                      = $request;
        $this->paymentMethod                = $paymentMethod;
        $this->transactionBuilder           = $transactionBuilder;
        $this->orderSender                  = $orderSender;
        $this->orderStatusHistoryRepository = $orderStatusHistoryRepository;

        parent::__construct(
            $pageFactory,
            $customerSession,
            $checkoutSession,
            $orderFactory,
            $paygateSession,
            $urlHelper,
            $customerUrl,
            $logger,
            $transactionFactory,
            $invoiceService,
            $invoiceSender,
            $paymentMethod,
            $urlBuilder,
            $orderRepository,
            $storeManager,
            $orderSender,
            $date,
            $orderCollectionFactory,
            $transactionBuilder,
            $dbTransaction,
            $order,
            $config,
            $state,
            $paygatehelper,
            $transactionSearchResultInterfaceFactory,
            $quoteRepository,
            $tokenManagement,
            $jsonFactory,
            $resultFactory,
            $request,
            $messageManager,
            $orderStatusHistoryRepository
        );
    }


    /**
     * Notify returns here with POST
     * Transaction reference gid in query string
     * Execute
     *
     * @return ResultInterface|void
     */
    public function execute()
    {
        $pre  = __METHOD__ . " : ";
        $data = $this->request->getPostValue();

        $this->logger->info('Notify Data: ' . json_encode($data));

        $reference  = $this->request->getParam('gid');
        $jsonResult = $this->jsonFactory->create();

        $this->order = $this->checkoutSession->getLastRealOrder();
        $order       = $this->getOrder();
        $invoices    = $order->getInvoiceCollection()->count();
        $due         = $order->getTotalDue();

        $resultRaw = $this->resultFactory->create(ResultFactory::TYPE_RAW);

        $resultRaw->setHttpResponseCode(200);

        $resultRaw->setContents('OK');

        $debugCron             = $this->paymentMethod->getConfigData('debug_cron');
        $canProcessThisOrder   = $this->paymentMethod->getConfigData('ipn_method') != '1';
        $resultRedirectFactory = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);


        if ($invoices > 0 && $due == 0.0) {
            return $resultRaw;
        }

        try {
            if (!$debugCron && $canProcessThisOrder) {
                $this->secret = $this->paymentMethod->getConfigData('encryption_key');
                $this->id     = $this->paymentMethod->getConfigData('paygate_id');
                $vaultActive  = (int)$this->paymentMethod->getConfigData('payhost_cc_vault_active') === 1;

                $this->logger->debug($pre . 'bof');

                $chkSum            = array_pop($data);
                $data['REFERENCE'] = $reference;
                $data['CHECKSUM']  = $chkSum;

                if (!$this->verifyChecksum($data)) {
                    throw new LocalizedException(__('Checksum mismatch: ' . json_encode($data)));
                }

                $this->logger->debug('Success: ' . json_encode($data));

                $this->pageFactory->create();

                if (!$this->order->getId()) {
                    throw new LocalizedException(__('Notify: Order not found: ' . json_encode($data)));
                }

                $notifyResponse = $this->notify($data);
                $this->logger->info('Notify Query: ' . json_encode($notifyResponse));

                $order = $this->orderRepository->get($order->getId());
                if (isset($data['TRANSACTION_STATUS'])) {
                    if ($notifyResponse['ns2TransactionStatusCode'] == 1 ||
                        $notifyResponse['ns2TransactionStatusDescription'] == "Approved") {
                        $status = 1;
                    } else {
                        $status = $data['TRANSACTION_STATUS'];
                    }
                    switch ($status) {
                        case 1:
                            $orderState = $order->getState();
                            if ($orderState != Order::STATE_COMPLETE && $orderState != Order::STATE_PROCESSING) {
                                $status = Order::STATE_PROCESSING;
                                if ($this->paymentMethod->getConfigData('Successful_Order_status') != "") {
                                    $status = $this->paymentMethod->getConfigData('Successful_Order_status');
                                }

                                $model                  = $this->paymentMethod;
                                $order_successful_email = $model->getConfigData('order_email');

                                $history = $order->addCommentToStatusHistory(
                                    __(
                                        'Notify Response, Transaction has been approved, Pay_Request_Id: '
                                        . $data['PAY_REQUEST_ID']
                                    )
                                );

                                if ($order_successful_email != '0') {
                                    $this->orderSender->send($order);
                                    $history->setIsCustomerNotified(true);
                                } else {
                                    $history->setIsCustomerNotified(false);
                                }

                                try {
                                    // Save the status history
                                    $this->orderStatusHistoryRepository->save($history);

                                    // Save the order
                                    $this->orderRepository->save($order);
                                } catch (LocalizedException $e) {
                                    // Handle any exceptions during the save process
                                    $this->logger->error('Order save error: ' . $e->getMessage());
                                }

                                $invoices = $order->getInvoiceCollection()->count();
                                $due      = $order->getTotalDue();

                                $alreadyPaid = false;
                                if ($invoices > 0 && $due == 0.0) {
                                    $alreadyPaid = true;
                                    $this->logger->info('Payment has already been processed');
                                }
                                if (!$alreadyPaid) {
                                    // Capture invoice when payment is successful
                                    $invoice = $this->invoiceService->prepareInvoice($order);
                                    $invoice->setRequestedCaptureCase(Invoice::CAPTURE_ONLINE);
                                    $invoice->register();

                                    // Save the invoice to the order
                                    $transaction = $this->dbTransaction
                                        ->addObject($invoice)
                                        ->addObject($invoice->getOrder());

                                    $transaction->save();

                                    // Save Transaction Response
                                    $this->createTransaction($data);

                                    // Save card vault data
                                    if ($vaultActive && !empty($notifyResponse['ns2VaultId'])) {
                                        $model = $this->paymentMethod;
                                        $model->saveVaultData($order, $notifyResponse);
                                    }

                                    $order->setStatus($status);
                                    $order->setState($status);
                                    // Magento\Sales\Model\Order\Email\Sender\InvoiceSender
                                    $send_invoice_email = $model->getConfigData('invoice_email');
                                    $history            = $order->addCommentToStatusHistory(
                                        __(
                                            'Notify Response, update order.'
                                        )
                                    );
                                    if ($send_invoice_email != '0') {
                                        $this->invoiceSender->send($invoice);
                                        $history->setIsCustomerNotified(true);
                                    } else {
                                        $history->setIsCustomerNotified(false);
                                    }
                                    $this->orderStatusHistoryRepository->save($history);
                                    $this->orderRepository->save($order);
                                }
                            }
                            break;
                        case 2:
                            $this->messageManager->addNoticeMessage('Transaction has been declined.');

                            $history = $order->addCommentToStatusHistory(
                                __(
                                    'Notify Response, Transaction has been declined, Pay_Request_Id: '
                                    . $data['PAY_REQUEST_ID']
                                )
                            );
                            $history->setIsCustomerNotified(false);

                            if ($order->getStatus() != 'canceled') {
                                $this->order->cancel();
                                $this->orderRepository->save($order);
                                $this->checkoutSession->restoreQuote();
                                // Save Transaction Response
                                $this->createTransaction($data);
                            }
                            break;
                        case 0:
                        case 4:
                            $this->messageManager->addNoticeMessage('Transaction has been cancelled');

                            $history = $order->addCommentToStatusHistory(
                                __(
                                    'Notify Response, Transaction has been cancelled, Pay_Request_Id: '
                                    . $data['PAY_REQUEST_ID']
                                )
                            );
                            $history->setIsCustomerNotified(false);

                            if ($order->getStatus() != 'canceled') {
                                $this->order->cancel();
                                $this->orderRepository->save($order);
                                $this->checkoutSession->restoreQuote();
                                // Save Transaction Response
                                $this->createTransaction($data);
                            }
                            break;
                        default:
                            // Save Transaction Response
                            $this->createTransaction($data);
                            break;
                    }
                }

                $this->logger->debug('Successful Notify');
            }

            return $resultRaw;
        } catch (LocalizedException $exception) {
            $this->logger->error($pre . $exception->getMessage());
        } catch (Exception $e) {
            // Save Transaction Response
            $this->createTransaction($data);
            $this->logger->error($pre . $e->getMessage());
            $this->messageManager->addExceptionMessage($e, __('We can\'t start Paygate Checkout.'));
        }
    }

    /**
     * Get the order ID from the Model
     *
     * @param int $incrementId
     *
     * @return Order
     */
    public function getOrderByIncrementId($incrementId): Order
    {
        return $this->order->loadByIncrementId($incrementId);
    }

    /**
     * Create Transaction
     *
     * @param array $paymentData
     *
     * @return int|void
     */
    public function createTransaction(array $paymentData = [])
    {
        $response = '';

        try {
            // Get payment object from order object
            $payment = $this->order->getPayment();
            $payment->setLastTransId($paymentData['PAY_REQUEST_ID'])
                    ->setTransactionId($paymentData['PAY_REQUEST_ID'])
                    ->setAdditionalInformation(
                        [Transaction::RAW_DETAILS => (array)$paymentData]
                    );

            // Get the object of builder class
            $trans       = $this->transactionBuilder;
            $transaction = $trans->setPayment($payment)
                                 ->setOrder($this->order)
                                 ->setTransactionId($paymentData['PAY_REQUEST_ID'])
                                 ->setAdditionalInformation(
                                     [Transaction::RAW_DETAILS => (array)$paymentData]
                                 )
                                 ->setFailSafe(true)
                // Build method creates the transaction and returns the object
                                 ->build(Transaction::TYPE_CAPTURE);

            $payment->addTransactionCommentsToOrder($transaction, 'Notify Response, update transaction.');
            $payment->setParentTransactionId(null);
            $this->orderRepository->save($this->order);

            $response = $transaction->getTransactionId();
        } catch (Exception $e) {
            $this->logger->error($e->getMessage());
        }

        return $response;
    }

    /**
     * @inheritDoc
     */
    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return $this->logger->debug("Invalid request exception when attempting to validate CSRF");
    }

    /**
     * @inheritDoc
     */
    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }

    /**
     * Get the order data
     *
     * @return Order
     */
    private function getOrder(): Order
    {
        $orderId     = $this->request->getParam('gid');
        $this->order = $this->getOrderByIncrementId($orderId);
        $this->checkoutSession->setData('last_order_id', $this->order->getId());
        $this->checkoutSession->setData('last_success_quote_id', $this->order->getQuoteId());
        $this->checkoutSession->setData('last_quote_id', $this->order->getQuoteId());
        $this->checkoutSession->setData('last_realorder_id', $orderId);

        return $this->order;
    }

    /**
     * Verify the Checksum for the data from the request
     *
     * @param array $data
     *
     * @return bool
     */
    private function verifyChecksum(array $data): bool
    {
        $checks        = false;
        $theirChecksum = $data['CHECKSUM'] ?? '';
        unset($data['CHECKSUM']);
        $checkStr = $this->id . implode('', $data);
        $checkStr .= $this->secret;
        // phpcs:ignore Magento2.Security.InsecureFunction
        $encyryptedCheckStr = md5($checkStr);
        if (hash_equals($theirChecksum, $encyryptedCheckStr)) {
            $checks = true;
        }

        return $checks;
    }

    /**
     * Prepare the XML data and send a curl request with the notify response data
     *
     * @param array $data
     *
     * @return mixed
     */
    private function notify($data): mixed
    {
        $queryFields = $this->prepareQueryXml($data);
        $response    = $this->paymentMethod->guzzlePost(Success::PAYHOSTURL, $queryFields);
        $respArray   = $this->paymentMethod->formatXmlToArray($response);

        return $respArray['ns2SingleFollowUpResponse']['ns2QueryResponse']['ns2Status'];
    }

    /**
     * Prepare the XML data and send a curl request with the notify response data
     *
     * @param array $data
     *
     * @return mixed
     */
    private function prepareQueryXml($data)
    {
        $cred           = $this->paymentMethod->getPayGateCredentials();
        $paygateId      = $cred['paygateId'];
        $password       = $cred['password'];
        $pay_request_id = $data['PAY_REQUEST_ID'];

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
}
