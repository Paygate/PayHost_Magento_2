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
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\View\Result\PageFactory;
use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\Order\Payment\Transaction;
use PayGate\PayHost\Controller\AbstractPaygate;
use PayGate\PayHost\Controller\Redirect\Success;
use PayGate\PayHost\Model\Config;

class Index extends AbstractPaygate
{

    /**
     * @var PageFactory
     */
    protected $resultPageFactory;

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
     * Notify returns here with POST
     * Transaction reference gid in query string
     * Execute
     *
     * @param JsonFactory $jsonFactory
     */
    public function execute()
    {
        $pre  = __METHOD__ . " : ";
        $data = $this->request->getPostValue();

        $this->_logger->info('Notify Data: ' . json_encode($data));

        $reference  = $this->request->getParam('gid');
        $jsonResult = $this->jsonFactory->create();

        $this->_order = $this->_checkoutSession->getLastRealOrder();
        $order        = $this->getOrder();
        $invoices     = $order->getInvoiceCollection()->count();
        $due          = $order->getTotalDue();

        $resultRaw = $this->resultFactory->create(ResultFactory::TYPE_RAW);

        $resultRaw->setHttpResponseCode(200);

        $resultRaw->setContents('OK');

        if ($invoices > 0 && $due == 0.0) {
            return $resultRaw;
        }

        try {
            $this->secret      = $this->getConfigData('encryption_key');
            $this->id          = $this->getConfigData('paygate_id');
            $this->vaultActive = (int)$this->getConfigData('payhost_cc_vault_active') === 1;

            $this->_logger->debug($pre . 'bof');

            $chkSum            = array_pop($data);
            $data['REFERENCE'] = $reference;
            $data['CHECKSUM']  = $chkSum;

            if (!$this->verifyChecksum($data)) {
                throw new LocalizedException(__('Checksum mismatch: ' . json_encode($data)));
            }

            $this->_logger->debug('Success: ' . json_encode($data));

            $this->pageFactory->create();

            if (!$this->_order->getId()) {
                throw new LocalizedException(__('Notify: Order not found: ' . json_encode($data)));
            }

            $notifyResponse = $this->notify($data);
            $this->_logger->info('Notify Query: ' . json_encode($notifyResponse));

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
                        $status = \Magento\Sales\Model\Order::STATE_PROCESSING;
                        if ($this->getConfigData('Successful_Order_status') != "") {
                            $status = $this->getConfigData('Successful_Order_status');
                        }

                        $model                  = $this->_paymentMethod;
                        $order_successful_email = $model->getConfigData('order_email');

                        if ($order_successful_email != '0') {
                            $this->OrderSender->send($order);
                            $order->addStatusHistoryComment(
                                __('Notified customer about order #%1.', $order->getId())
                            )->setIsCustomerNotified(true)->save();
                        }

                        // Capture invoice when payment is successful
                        $invoice = $this->_invoiceService->prepareInvoice($order);
                        $invoice->setRequestedCaptureCase(Invoice::CAPTURE_ONLINE);
                        $invoice->register();

                        // Save the invoice to the order
                        $transaction = $this->dbTransaction
                            ->addObject($invoice)
                            ->addObject($invoice->getOrder());

                        $transaction->save();

                        // Magento\Sales\Model\Order\Email\Sender\InvoiceSender
                        $send_invoice_email = $model->getConfigData('invoice_email');
                        if ($send_invoice_email != '0') {
                            $this->invoiceSender->send($invoice);
                            $order->addStatusHistoryComment(
                                __('Notified customer about invoice #%1.', $invoice->getId())
                            )->setIsCustomerNotified(true)->save();
                        }

                        // Save Transaction Response
                        $transactionId = $this->createTransaction($data);

                        $invoice->setTransactionId($transactionId);
                        $invoice->save();

                        // Save card vault data
                        if ($this->vaultActive && !empty($notifyResponse['ns2VaultId'])) {
                            $model->saveVaultData($order, $notifyResponse);
                        }

                        $order->setState($status)->setStatus($status)->save();
                        break;
                    case 2:
                        $this->messageManager->addNotice('Transaction has been declined.');
                        $this->_order->addStatusHistoryComment(
                            __(
                                'Redirect Response, Transaction has been declined, Pay_Request_Id: '
                                . $data['PAY_REQUEST_ID']
                            )
                        )->setIsCustomerNotified(false);
                        if ($order->getStatus() != 'canceled') {
                            $this->_order->cancel()->save();
                            $this->_checkoutSession->restoreQuote();
                            // Save Transaction Response
                            $this->createTransaction($data);
                        }
                        break;
                    case 0:
                    case 4:
                        $this->messageManager->addNotice('Transaction has been cancelled');
                        $this->_order->addStatusHistoryComment(
                            __(
                                'Redirect Response, Transaction has been cancelled, Pay_Request_Id: '
                                . $data['PAY_REQUEST_ID']
                            )
                        )->setIsCustomerNotified(false);
                        if ($order->getStatus() != 'canceled') {
                            $this->_order->cancel()->save();
                            $this->_checkoutSession->restoreQuote();
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

            $this->_logger->debug('Successful Notify');

            return $resultRaw;
        } catch (LocalizedException $exception) {
            $this->_logger->error($pre . $exception->getMessage());
        } catch (Exception $e) {
            // Save Transaction Response
            $this->createTransaction($data);
            $this->_logger->error($pre . $e->getMessage());
            $this->messageManager->addExceptionMessage($e, __('We can\'t start Paygate Checkout.'));
        }
    }

    /**
     * Get the order ID from the Model
     *
     * @param int $incrementId
     *
     * @return \Magento\Sales\Model\Order
     */
    public function getOrderByIncrementId($incrementId): \Magento\Sales\Model\Order
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
        try {
            // Get payment object from order object
            $payment = $this->_order->getPayment();
            $payment->setLastTransId($paymentData['PAY_REQUEST_ID'])
                    ->setTransactionId($paymentData['PAY_REQUEST_ID'])
                    ->setAdditionalInformation(
                        [Transaction::RAW_DETAILS => (array)$paymentData]
                    );
            $formattedPrice = $this->_order->getBaseCurrency()->formatTxt(
                $this->_order->getGrandTotal()
            );

            $message = __('The authorized amount is %1.', $formattedPrice);
            // Get the object of builder class
            $trans       = $this->_transactionBuilder;
            $transaction = $trans->setPayment($payment)
                                 ->setOrder($this->_order)
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
            $payment->save();
            $this->_order->save();

            return $transaction->save()->getTransactionId();
        } catch (Exception $e) {
            $this->_logger->error($e->getMessage());
        }
    }

    /**
     * @inheritDoc
     */
    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        $this->_logger->debug("Invalid request exception when attempting to validate CSRF");
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
     * @return \Magento\Sales\Model\Order
     */
    private function getOrder(): \Magento\Sales\Model\Order
    {
        $orderId      = $this->request->getParam('gid');
        $this->_order = $this->getOrderByIncrementId($orderId);
        $this->_checkoutSession->setData('last_order_id', $this->_order->getId());
        $this->_checkoutSession->setData('last_success_quote_id', $this->_order->getQuoteId());
        $this->_checkoutSession->setData('last_quote_id', $this->_order->getQuoteId());
        $this->_checkoutSession->setData('last_real_order_id', $orderId);

        return $this->_order;
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
        $response    = $this->_paymentMethod->curlPost(Success::PAYHOSTURL, $queryFields);
        $respArray   = $this->_paymentMethod->formatXmlToArray($response);

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
        $cred           = $this->_paymentMethod->getPayGateCredentials();
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
