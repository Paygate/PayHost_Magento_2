<?php

/*
 * Copyright (c) 2024 Payfast (Pty) Ltd
 *
 * Author: App Inlet (Pty) Ltd
 *
 * Released under the GNU General Public License
 */

namespace PayGate\PayHost\Controller;

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
use Magento\Framework\DB\Transaction as DBTransaction;
use Magento\Framework\DB\TransactionFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\Session\Generic;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Framework\Url\Helper;
use Magento\Framework\Url\Helper\Data;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Result\PageFactory;
use Magento\Framework\Webapi\Rest\Request;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote;
use Magento\Sales\Api\Data\TransactionSearchResultInterfaceFactory;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Api\OrderStatusHistoryRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Email\Sender\InvoiceSender;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;
use Magento\Sales\Model\Order\Payment\Transaction\Builder;
use Magento\Sales\Model\OrderFactory;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory;
use Magento\Sales\Model\Service\InvoiceService;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Vault\Api\PaymentTokenManagementInterface;
use PayGate\PayHost\Helper\Data as PaygateHelper;
use PayGate\PayHost\Model\Config;
use PayGate\PayHost\Model\PayGate;
use Psr\Log\LoggerInterface;

/**
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
abstract class AbstractPaygate implements
    HttpPostActionInterface,
    HttpGetActionInterface,
    CsrfAwareActionInterface
{

    /**
     * Internal cache of checkout models
     *
     * @var array
     */
    protected $checkoutTypes = [];

    /**
     * @var Quote
     */
    protected $quote = false;

    /**
     * Config mode type
     *
     * @var string
     */
    protected $configType = Config::class;

    /**
     * Config method type
     *
     * @var string
     */
    protected $configMethod = Config::METHOD_CODE;

    /**
     * Checkout mode type
     *
     * @var string
     */
    protected $checkoutType;

    /**
     * @var CustomerSession
     */
    protected CustomerSession $customerSession;

    /**
     * @var CheckoutSession
     */
    protected CheckoutSession $checkoutSession;

    /**
     * @var OrderFactory
     */
    protected $orderFactory;

    /**
     * @var Generic
     */
    protected $paygateSession;

    /**
     * @var Helper
     */
    protected $urlHelper;

    /**
     * @var Url
     */
    protected $customerUrl;

    /**
     * @var LoggerInterface
     */
    protected LoggerInterface $logger;

    /**
     * @var PageFactory
     */
    protected PageFactory $pageFactory;

    /**
     * @var TransactionFactory
     */
    protected $transactionFactory;

    /**
     * @var  StoreManagerInterface $storeManager
     */
    protected $storeManager;

    /**
     * @var PayGate $paymentMethod
     */
    protected PayGate $paymentMethod;

    /**
     * @var OrderRepositoryInterface
     */
    protected OrderRepositoryInterface $orderRepository;

    /**
     * @var CollectionFactory $orderCollectionFactory
     */
    protected $orderCollectionFactory;

    /**
     * @var Builder
     */
    protected Builder $transactionBuilder;
    /**
     * @var DBTransaction
     */
    protected $dbTransaction;
    /**
     * @var Order
     */
    protected Order $order;
    /**
     * @var Config
     */
    protected $config;
    /**
     * @var TransactionSearchResultInterfaceFactory
     */
    protected $transactionSearchResultInterfaceFactory;
    /**
     * @var InvoiceService
     */
    protected $invoiceService;
    /**
     * @var InvoiceSender
     */
    protected $invoiceSender;
    /**
     * @var OrderSender
     */
    protected OrderSender $orderSender;
    /**
     * @var State
     */
    protected State $state;
    /**
     * @var \Magento\Quote\Api\CartRepositoryInterface
     */
    protected CartRepositoryInterface $quoteRepository;
    /**
     * @var JsonFactory $jsonFactory
     */
    protected JsonFactory $jsonFactory;
    /**
     * @var ResultFactory
     */
    protected ResultFactory $resultFactory;
    /**
     * @var Request
     */
    protected Request $request;
    /**
     * @var ManagerInterface
     */
    protected ManagerInterface $messageManager;
    /**
     * @var UrlInterface
     */
    private $urlBuilder;
    /**
     * @var DateTime
     */
    private $date;
    /**
     * @var State
     */
    private $paygatehelper;
    /**
     * @var \Magento\Vault\Api\PaymentTokenManagementInterface
     */
    private PaymentTokenManagementInterface $tokenManagement;
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
     * @param OrderSenderOrderSender $orderSenderOrderSender
     * @param DateTime $date
     * @param CollectionFactory $orderCollectionFactory
     * @param Builder $transactionBuilder
     * @param DBTransaction $dbTransaction
     * @param Order $order
     * @param Config $config
     * @param \Magento\Framework\App\State $state
     * @param \PayGate\PayHost\Helper\Data $paygatehelper
     * @param TransactionSearchResultInterfaceFactory $transactionSearchResultInterfaceFactory
     * @param \Magento\Quote\Api\CartRepositoryInterface $quoteRepository
     * @param PaymentTokenManagementInterface $tokenManagement
     * @param JsonFactory $jsonFactory
     * @param ResultFactory $resultFactory
     * @param Request $request
     * @param ManagerInterface $messageManager
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
        $pre = __METHOD__ . " : ";

        $this->logger = $logger;

        $this->logger->debug($pre . 'bof');

        $this->dbTransaction                           = $dbTransaction;
        $this->order                                   = $order;
        $this->config                                  = $config;
        $this->transactionSearchResultInterfaceFactory = $transactionSearchResultInterfaceFactory;
        $this->customerSession                         = $customerSession;
        $this->checkoutSession                         = $checkoutSession;
        $this->orderFactory                            = $orderFactory;
        $this->paygateSession                          = $paygateSession;
        $this->urlHelper                               = $urlHelper;
        $this->customerUrl                             = $customerUrl;
        $this->pageFactory                             = $pageFactory;
        $this->invoiceService                          = $invoiceService;
        $this->invoiceSender                           = $invoiceSender;
        $this->orderSender                             = $orderSender;
        $this->transactionFactory                      = $transactionFactory;
        $this->paymentMethod                           = $paymentMethod;
        $this->urlBuilder                              = $urlBuilder;
        $this->orderRepository                         = $orderRepository;
        $this->storeManager                            = $storeManager;
        $this->date                                    = $date;
        $this->orderCollectionFactory                  = $orderCollectionFactory;
        $this->transactionBuilder                      = $transactionBuilder;
        $this->quoteRepository                         = $quoteRepository;
        $this->tokenManagement                         = $tokenManagement;
        $this->resultFactory                           = $resultFactory;
        $this->request                                 = $request;
        $this->messageManager                          = $messageManager;
        $this->state                                   = $state;
        $this->paygatehelper                           = $paygatehelper;
        $this->jsonFactory                             = $jsonFactory;
        $this->orderStatusHistoryRepository            = $orderStatusHistoryRepository;

        $this->logger->debug($pre . 'eof');
    }

    /**
     * Custom getter for payment configuration
     *
     * @param string $field i.e paygate_id, test_mode
     *
     * @return mixed
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function getConfigData($field)
    {
        return $this->paymentMethod->getConfigData($field);
    }

    /**
     * Returns before_auth_url redirect parameter for customer session
     *
     * @return null
     */
    public function getCustomerBeforeAuthUrl()
    {
        return null;
    }

    /**
     * Returns a list of action flags [flag_key] => boolean
     *
     * @return array
     */
    public function getActionFlagList()
    {
        return [];
    }

    /**
     * Returns login url parameter for redirect
     *
     * @return string
     */
    public function getLoginUrl()
    {
        return $this->customerUrl->getLoginUrl();
    }

    /**
     * Returns action name which requires redirect
     *
     * @return string
     */
    public function getRedirectActionName()
    {
        return 'index';
    }

    /**
     * Redirect to login page
     *
     * @return void
     */
    public function redirectLogin()
    {
        $this->actionFlag->set('', 'no-dispatch', true);
        $this->customerSession->setBeforeAuthUrl($this->redirect->getRefererUrl());
        $this->getResponse()->setRedirect(
            $this->urlHelper->addRequestParam($this->customerUrl->getLoginUrl(), ['context' => 'checkout'])
        );
    }

    /**
     * @inheritDoc
     */
    public function createCsrfValidationException(
        RequestInterface $request
    ): ?InvalidRequestException {
        return null;
    }

    /**
     * @inheritDoc
     */
    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }

    /**
     * Instantiate
     *
     * @return void
     * @throws LocalizedException
     */
    protected function _initCheckout()
    {
        $pre = __METHOD__ . " : ";
        $this->logger->debug($pre . 'bof');
        $this->order = $this->checkoutSession->getLastRealOrder();

        if (!$this->order->getId()) {
            $this->getResponse()->setStatusHeader(404, '1.1', 'Not found');
            throw new LocalizedException(__('We could not find "Order" for processing'));
        }

        if ($this->order->getState() != Order::STATE_PENDING_PAYMENT) {
            $this->order->setState(Order::STATE_PENDING_PAYMENT);
            $this->orderRepository->save($this->order);
        }

        if ($this->order->getQuoteId()) {
            $this->checkoutSession->setPaygateQuoteId($this->checkoutSession->getQuoteId());
            $this->checkoutSession->setPaygateSuccessQuoteId($this->checkoutSession->getLastSuccessQuoteId());
            $this->checkoutSession->setPaygateRealOrderId($this->checkoutSession->getLastRealOrderId());
            // Deactivate the quote and save using the repository
            $quote = $this->checkoutSession->getQuote();
            $quote->setIsActive(false);

            // Save the quote using the repository
            $this->quoteRepository->save($quote);
        }

        $this->logger->debug($pre . 'eof');
    }

    /**
     * Paygate session instance getter
     *
     * @return Generic
     */
    protected function _getSession()
    {
        return $this->paygateSession;
    }

    /**
     * Return checkout session object
     *
     * @return CheckoutSession
     */
    protected function _getCheckoutSession()
    {
        return $this->checkoutSession;
    }

    /**
     * Return checkout quote object
     *
     * @return Quote
     */
    protected function _getQuote()
    {
        if (!$this->quote) {
            $this->quote = $this->_getCheckoutSession()->getQuote();
        }

        return $this->quote;
    }
}
