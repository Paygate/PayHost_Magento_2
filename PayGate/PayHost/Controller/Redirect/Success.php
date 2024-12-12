<?php

/*
 * Copyright (c) 2024 Payfast (Pty) Ltd
 *
 * Author: App Inlet (Pty) Ltd
 *
 * Released under the GNU General Public License
 */

namespace PayGate\PayHost\Controller\Redirect;

use Exception;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Customer\Model\Customer;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\Order\Payment\Transaction;
use Magento\Vault\Api\PaymentTokenManagementInterface;
use PayGate\PayHost\Controller\AbstractPaygate;
use PayGate\PayHost\Model\Config;

/**
 * Responsible for loading page content.
 *
 * This is a basic controller that only loads the corresponding layout file. It may duplicate other such
 * controllers, and thus it is considered tech debt. This code duplication will be resolved in future releases.
 */
class Success extends AbstractPaygate
{
    public const PAYHOSTURL = "https://secure.paygate.co.za/payhost/process.trans";

    /**
     * @var string
     */
    private $redirectToSuccessPageString;

    /**
     * @var string
     */
    private $redirectToCartPageString;
    /**
     * @var string
     */
    private string $secret;
    /**
     * @var string
     */
    private string $id;
    /**
     * @var bool
     */
    private bool $vaultActive;
    /**
     * @var CustomerSession
     */
    protected CustomerSession $customerSession;

    /**
     * Browser redirect returns here with POST
     * Transaction reference gid in query string
     * Execute on paygate/redirect/success
     *
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function execute()
    {
        $baseurl                           = $this->storeManager->getStore()->getBaseUrl();
        $this->redirectToSuccessPageString = 'checkout/onepage/success';
        $this->redirectToCartPageString    = 'checkout/cart';
        $pre                               = __METHOD__ . " : ";
        $data                              = $this->request->getPostValue();
        $reference                         = $this->request->getParam('gid');

        $this->order = $this->checkoutSession->getLastRealOrder();
        $order       = $this->getOrder();

        $resultRedirectFactory = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);

        $debugCron           = $this->getConfigData('debug_cron');
        $canProcessThisOrder = $this->paymentMethod->getConfigData('ipn_method') != '0';

        if ($debugCron) {
            $this->messageManager->addNoticeMessage(
                __(
                    'This is a cron debugging test, the order is still in pending.'
                )
            );

            return $resultRedirectFactory->setPath('checkout/onepage/success');
        }

        try {
            $this->secret      = $this->getConfigData('encryption_key');
            $this->id          = $this->getConfigData('paygate_id');
            $this->vaultActive = (int)$this->getConfigData('payhost_cc_vault_active') === 1;

            $this->logger->debug($pre . 'bof');
            $chkSum            = array_pop($data);
            $data['REFERENCE'] = $reference;
            $data['CHECKSUM']  = $chkSum;

            $customerId    = $order->getCustomerId();
            $objectManager = ObjectManager::getInstance();

            if ($customerId !== null) {
                $customerData = $objectManager->create(Customer::class)
                                              ->load($customerId);

                $this->customerSession->setCustomerAsLoggedIn($customerData);
            }

            if (!$this->verifyChecksum($data)) {
                throw new LocalizedException(__('Checksum mismatch: ' . json_encode($data)));
            }

            $this->logger->debug('Success: ' . json_encode($data));

            $this->pageFactory->create();

            $this->redirectIfOrderNotFound();

            $notifyResponse = $this->notify($data);

            $order = $this->orderRepository->get($order->getId());

            if (isset($data['TRANSACTION_STATUS'])) {
                if ($notifyResponse['ns2TransactionStatusCode'] == 1
                    || $notifyResponse['ns2TransactionStatusDescription'] == "Approved") {
                    $status = 1;
                } else {
                    $status = $data['TRANSACTION_STATUS'];
                }

                switch ($status) {
                    case 1:
                        if ($canProcessThisOrder) {
                            $status = \Magento\Sales\Model\Order::STATE_PROCESSING;
                            if ($this->getConfigData('Successful_Order_status') != "") {
                                $status = $this->getConfigData('Successful_Order_status');
                            }

                            $model                  = $this->paymentMethod;
                            $order_successful_email = $model->getConfigData('order_email');

                            $history = $order->addCommentToStatusHistory(
                                __(
                                    'Redirect Response, Transaction has been approved, Pay_Request_Id: '
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
                                if ($this->vaultActive && !empty($notifyResponse['ns2VaultId'])) {
                                    $model = $this->paymentMethod;
                                    $model->saveVaultData($order, $notifyResponse);
                                }

                                $order->setStatus($status);
                                $order->setState($status);
                                // Magento\Sales\Model\Order\Email\Sender\InvoiceSender
                                $send_invoice_email = $model->getConfigData('invoice_email');
                                $history            = $order->addCommentToStatusHistory(
                                    __(
                                        'Redirect Response, update order.'
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
                        // Invoice capture code completed
                        $resultRedirect = $resultRedirectFactory->setPath($this->redirectToSuccessPageString);
                        break;
                    case 2:
                        $this->messageManager->addNoticeMessage('Transaction has been declined.');

                        if ($canProcessThisOrder) {
                            $history = $order->addCommentToStatusHistory(
                                __(
                                    'Redirect Response, Transaction has been declined, Pay_Request_Id: '
                                    . $data['PAY_REQUEST_ID']
                                )
                            );
                            $history->setIsCustomerNotified(false);

                            try {
                                // Save the status history
                                $this->orderStatusHistoryRepository->save($history);

                                // Save the order
                                $this->orderRepository->save($order);
                            } catch (LocalizedException $e) {
                                // Handle any exceptions during the save process
                                $this->logger->error('Order save error: ' . $e->getMessage());
                            }

                            if ($order->getStatus() != 'canceled') {
                                $this->order->cancel();
                                $this->orderRepository->save($this->order);
                                $this->checkoutSession->restoreQuote();
                                // Save Transaction Response
                                $this->createTransaction($data);
                            }
                        } else {
                            $this->checkoutSession->restoreQuote();
                        }

                        $resultRedirect = $resultRedirectFactory->setPath($this->redirectToCartPageString);
                        break;
                    case 0:
                    case 4:
                        $this->messageManager->addNoticeMessage('Transaction has been cancelled');

                        if ($canProcessThisOrder) {
                            $history = $order->addCommentToStatusHistory(
                                __(
                                    'Redirect Response, Transaction has been cancelled, Pay_Request_Id: '
                                    . $data['PAY_REQUEST_ID']
                                )
                            );
                            $history->setIsCustomerNotified(false);

                            try {
                                // Save the status history
                                $this->orderStatusHistoryRepository->save($history);

                                // Save the order
                                $this->orderRepository->save($order);
                            } catch (LocalizedException $e) {
                                // Handle any exceptions during the save process
                                $this->logger->error('Order save error: ' . $e->getMessage());
                            }

                            if ($order->getStatus() != 'canceled') {
                                $this->order->cancel();
                                $this->orderRepository->save($this->order);
                                $this->checkoutSession->restoreQuote();
                                // Save Transaction Response
                                $this->createTransaction($data);
                            }
                        } else {
                            $this->checkoutSession->restoreQuote();
                        }

                        $resultRedirect = $resultRedirectFactory->setPath($this->redirectToCartPageString);
                        break;
                    default:
                        if ($canProcessThisOrder) {
                            // Save Transaction Response
                            $this->createTransaction($data);
                        }
                        break;
                }
            }
        } catch (LocalizedException $exception) {
            $this->logger->error($pre . $exception->getMessage());
            $this->messageManager->addExceptionMessage(
                $exception,
                __(
                    'An error occurred with the payment. Error: ' . $exception->getMessage()
                )
            );
            $this->restoreQuote();
            $resultRedirect = $resultRedirectFactory->setPath($this->redirectToCartPageString);
        } catch (Exception $e) {
            // Save Transaction Response
            $this->createTransaction($data);
            $this->logger->error($pre . $e->getMessage());
            $this->messageManager->addExceptionMessage($e, __('We can\'t start Paygate Checkout.'));
            $resultRedirect = $resultRedirectFactory->setPath($this->redirectToSuccessPageString);
        }

        return $resultRedirect;
    }

    /**
     * Create transaction for payment data
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

            $payment->addTransactionCommentsToOrder($transaction, 'Redirect Response, update transaction.');
            $payment->setParentTransactionId(null);
            $this->orderRepository->save($this->order);

            $response = $transaction->getTransactionId();
        } catch (Exception $e) {
            $this->logger->error($e->getMessage());
        }

        return $response;
    }

    /**
     * Get the order ID from the Model
     *
     * @param int $incrementId
     *
     * @return \Magento\Sales\Model\Order
     */
    public function getOrderByIncrementId($incrementId)
    {
        return $this->order->loadByIncrementId($incrementId);
    }

    /**
     * Set the order details of the last order
     *
     * @noinspection PhpUndefinedMethodInspection
     */
    public function setlastOrderDetails()
    {
        $orderId     = $this->request->getParam('gid');
        $this->order = $this->getOrderByIncrementId($orderId);
        $order       = $this->order;
        $this->checkoutSession->setData('last_order_id', $order->getId());
        $this->checkoutSession->setData('last_success_quote_id', $order->getQuoteId());
        $this->checkoutSession->setData('last_quote_id', $order->getQuoteId());
        $this->checkoutSession->setData('last_real_order_id', $orderId);
    }

    /**
     * Restore the quote
     *
     * @return void
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function restoreQuote()
    {
        $session = $this->checkoutSession;
        $order   = $session->getLastRealOrder();
        $quoteId = $order->getQuoteId();
        $quote   = $this->quoteRepository->get($quoteId);
        $quote->setIsActive(1)->setReservedOrderId(null);
        $this->quoteRepository->save($quote);
        $session->replaceQuote($quote)->unsLastRealOrderId();
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
        $response    = $this->paymentMethod->guzzlePost(self::PAYHOSTURL, $queryFields);
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

    /**
     * Get the order object
     *
     * @return \Magento\Sales\Model\Order
     */
    private function getOrder(): \Magento\Sales\Model\Order
    {
        if (!$this->order->getId()) {
            $this->setlastOrderDetails();

            return $this->order;
        } else {
            return $this->order;
        }
    }

    /**
     * Redirect if order is not found
     *
     * @return
     */
    private function redirectIfOrderNotFound()
    {
        $jsonResult = $this->jsonFactory->create();
        if (!$this->order->getId()) {
            // Redirect to Cart if Order not found
            $jsonResult->setData(json_encode($this->redirectToSuccessPageString));

            return $jsonResult;
        }
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
}
