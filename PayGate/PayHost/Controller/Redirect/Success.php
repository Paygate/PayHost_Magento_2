<?php
/*
 * Copyright (c) 2021 PayGate (Pty) Ltd
 *
 * Author: App Inlet (Pty) Ltd
 *
 * Released under the GNU General Public License
 */

namespace PayGate\PayHost\Controller\Redirect;

use Exception;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\Order\Payment\Transaction;
use PayGate\PayHost\Controller\AbstractPaygate;

/**
 * Responsible for loading page content.
 *
 * This is a basic controller that only loads the corresponding layout file. It may duplicate other such
 * controllers, and thus it is considered tech debt. This code duplication will be resolved in future releases.
 */
class Success extends AbstractPaygate
{

    const PAYHOSTURL = "https://secure.paygate.co.za/payhost/process.trans";

    /**
     * @var string
     */
    private $redirectToSuccessPageString;

    /**
     * @var string
     */
    private $redirectToCartPageString;

    /**
     * Execute on paygate/redirect/success
     */
    public function execute()
    {
        $pre = __METHOD__ . " : ";
        $this->_logger->debug($pre . 'bof');
        $data         = $this->getRequest()->getPostValue();
        $this->_order = $this->_checkoutSession->getLastRealOrder();
        $order        = $this->getOrder();

        $this->pageFactory->create();
        $baseurl                           = $this->_storeManager->getStore()->getBaseUrl();
        $this->redirectToSuccessPageString = '<script>parent.location="' . $baseurl . 'checkout/onepage/success";</script>';
        $this->redirectToCartPageString    = '<script>parent.location="' . $baseurl . 'checkout/cart";</script>';

        $this->redirectIfOrderNotFound();

        $notifyResponse = $this->Notify($data);

        try {
            $order = $this->orderRepository->get($order->getId());
            if (isset($data['TRANSACTION_STATUS'])) {
                if ($notifyResponse['ns2TransactionStatusCode'] == 1 || $notifyResponse['ns2TransactionStatusDescription'] == "Approved") {
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

                        $order->setState($status)->setStatus($status)->save();
                        // Invoice capture code completed
                        echo $this->redirectToSuccessPageString;
                        exit;
                    case 2:
                        $this->messageManager->addNotice('Transaction has been declined.');
                        $this->_order->addStatusHistoryComment(
                            __(
                                'Redirect Response, Transaction has been declined, Pay_Request_Id: ' . $data['PAY_REQUEST_ID']
                            )
                        )->setIsCustomerNotified(false);
                        $this->_order->cancel()->save();
                        $this->_checkoutSession->restoreQuote();
                        // Save Transaction Response
                        $this->createTransaction($data);
                        echo $this->redirectToCartPageString;
                        exit;
                    case 0:
                    case 4:
                        $this->messageManager->addNotice('Transaction has been cancelled');
                        $this->_order->addStatusHistoryComment(
                            __(
                                'Redirect Response, Transaction has been cancelled, Pay_Request_Id: ' . $data['PAY_REQUEST_ID']
                            )
                        )->setIsCustomerNotified(false);
                        $this->_order->cancel()->save();
                        $this->_checkoutSession->restoreQuote();
                        // Save Transaction Response
                        $this->createTransaction($data);
                        echo $this->redirectToCartPageString;
                        exit;
                    default:
                        // Save Transaction Response
                        $this->createTransaction($data);
                        break;
                }
            }
        } catch (Exception $e) {
            // Save Transaction Response
            $this->createTransaction($data);
            $this->_logger->error($pre . $e->getMessage());
            $this->messageManager->addExceptionMessage($e, __('We can\'t start PayGate Checkout.'));
            echo $this->redirectToSuccessPageString;
        }

        return '';
    }

    public function createTransaction($paymentData = array())
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

    public function getOrderByIncrementId($incrementId)
    {
        return $this->order->loadByIncrementId($incrementId);
    }

    public function setlastOrderDetails()
    {
        $orderId      = $this->getRequest()->getParam('gid');
        $this->_order = $this->getOrderByIncrementId($orderId);
        $order        = $this->_order;
        $this->_checkoutSession->setData('last_order_id', $order->getId());
        $this->_checkoutSession->setData('last_success_quote_id', $order->getQuoteId());
        $this->_checkoutSession->setData('last_quote_id', $order->getQuoteId());
        $this->_checkoutSession->setData('last_real_order_id', $orderId);
        $_SESSION['customer_base']['customer_id']           = $order->getCustomerId();
        $_SESSION['default']['visitor_data']['customer_id'] = $order->getCustomerId();
        $_SESSION['customer_base']['customer_id']           = $order->getCustomerId();
    }

    public function Notify($data)
    {
        $queryFields = $this->prepareQueryXml($data);
        $response    = $this->_paymentMethod->curlPost(self::PAYHOSTURL, $queryFields);
        $respArray   = $this->_paymentMethod->formatXmlToArray($response);
        $ns2Status   = $respArray['ns2SingleFollowUpResponse']['ns2QueryResponse']['ns2Status'];

        return $ns2Status;
    }

    public function prepareQueryXml($data)
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

    private function getOrder()
    {
        if ( ! $this->_order->getId()) {
            $this->setlastOrderDetails();

            return $this->_order;
        } else {
            return $this->_order;
        }
    }

    private function redirectIfOrderNotFound()
    {
        if ( ! $this->_order->getId()) {
            // Redirect to Cart if Order not found
            echo $this->redirectToSuccessPageString;
            exit;
        }
    }
}
