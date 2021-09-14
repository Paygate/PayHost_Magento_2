<?php
/*
 * Copyright (c) 2021 PayGate (Pty) Ltd
 *
 * Author: App Inlet (Pty) Ltd
 *
 * Released under the GNU General Public License
 */

namespace PayGate\PayHost\Controller;

use Magento\Checkout\Controller\Express\RedirectLoginInterface;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Customer\Model\{Session as CustomerSession, Url};
use Magento\Framework\{App\Action\Context,
    DB\Transaction as DBTransaction,
    DB\TransactionFactory,
    Exception\LocalizedException,
    Session\Generic,
    Stdlib\DateTime\DateTime,
    Url\Helper,
    Url\Helper\Data,
    UrlInterface,
    View\Result\PageFactory};
use Magento\Framework\App\Action\Action as AppAction;
use Magento\Framework\App\State;
use Magento\Quote\Model\Quote;
use Magento\Sales\{Api\Data\TransactionSearchResultInterfaceFactory,
    Api\OrderRepositoryInterface,
    Model\Order,
    Model\Order\Email\Sender\InvoiceSender,
    Model\Order\Email\Sender\OrderSender,
    Model\Order\Payment\Transaction\Builder,
    Model\OrderFactory,
    Model\ResourceModel\Order\CollectionFactory,
    Model\Service\InvoiceService};
use Magento\Store\Model\StoreManagerInterface;
use PayGate\PayHost\Helper\Data as PaygateHelper;
use PayGate\PayHost\Model\Config;
use PayGate\PayHost\Model\PayGate;
use Psr\Log\LoggerInterface;


/**
 * Checkout Controller
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
abstract class AbstractPaygatem220 extends AppAction implements RedirectLoginInterface
{
    /**
     * Internal cache of checkout models
     *
     * @var array
     */
    protected $_checkoutTypes = [];

    /**
     * @var Config
     */
    protected $_config;

    /**
     * @var Quote
     */
    protected $_quote = false;

    /**
     * Config mode type
     *
     * @var string
     */
    protected $_configType = 'PayGate\PayHost\Model\Config';

    /** Config method type @var string */
    protected $_configMethod = Config::METHOD_CODE;

    /**
     * Checkout mode type
     *
     * @var string
     */
    protected $_checkoutType;

    /**
     * @var CustomerSession
     */
    protected $_customerSession;

    /**
     * @var CheckoutSession $_checkoutSession
     */
    protected $_checkoutSession;

    /**
     * @var OrderFactory
     */
    protected $_orderFactory;

    /**
     * @var Generic
     */
    protected $paygateSession;

    /**
     * @var Helper
     */
    protected $_urlHelper;

    /**
     * @var Url
     */
    protected $_customerUrl;

    /**
     * @var LoggerInterface
     */
    protected $_logger;

    /**
     * @var  Order $_order
     */
    protected $_order;

    /**
     * @var PageFactory
     */
    protected $pageFactory;

    /**
     * @var TransactionFactory
     */
    protected $_transactionFactory;

    /**
     * @var  StoreManagerInterface $storeManager
     */
    protected $_storeManager;

    /**
     * @var PayGate $_paymentMethod
     */
    protected $_paymentMethod;

    /**
     * @var OrderRepositoryInterface $orderRepository
     */
    protected $orderRepository;

    /**
     * @var CollectionFactory $_orderCollectionFactory
     */
    protected $_orderCollectionFactory;

    /**
     * @var Magento\Sales\Model\Order\Payment\Transaction\Builder $_transactionBuilder
     */
    protected $_transactionBuilder;
    /**
     * @var DBTransaction
     */
    protected $dbTransaction;
    /**
     * @var Order
     */
    protected $order;
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
    protected $_invoiceService;
    /**
     * @var InvoiceSender
     */
    protected $invoiceSender;
    /**
     * @var OrderSender
     */
    protected $OrderSender;
    /**
     * @var UrlInterface
     */
    private $_urlBuilder;
    /**
     * @var DateTime
     */
    private $_date;

    /** @var State * */
    protected $state;

    /** @var State * */
    private $_paygatehelper;

    /**
     * @param Context $context ,
     * @param PageFactory $pageFactory ,
     * @param CustomerSession $customerSession ,
     * @param CheckoutSession $checkoutSession ,
     * @param OrderFactory $orderFactory ,
     * @param Generic $paygateSession ,
     * @param Data $urlHelper ,
     * @param Url $customerUrl ,
     * @param LoggerInterface $logger ,
     * @param TransactionFactory $transactionFactory ,
     * @param InvoiceService $invoiceService ,
     * @param InvoiceSender $invoiceSender ,
     * @param PayGate $paymentMethod ,
     * @param UrlInterface $urlBuilder ,
     * @param OrderRepositoryInterface $orderRepository ,
     * @param StoreManagerInterface $storeManager ,
     * @param OrderSender $OrderSender ,
     * @param DateTime $date ,
     * @param CollectionFactory $orderCollectionFactory ,
     * @param Builder $_transactionBuilder
     * @param DBTransaction $dbTransaction
     * @param Order $order
     * @param Config $config
     * @param TransactionSearchResultInterfaceFactory $transactionSearchResultInterfaceFactory
     */
    public function __construct(
        Context $context,
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
        OrderSender $OrderSender,
        DateTime $date,
        CollectionFactory $orderCollectionFactory,
        Builder $_transactionBuilder,
        DBTransaction $dbTransaction,
        Order $order,
        Config $config,
        State $state,
        PaygateHelper $paygatehelper,
        TransactionSearchResultInterfaceFactory $transactionSearchResultInterfaceFactory

    ) {
        $pre = __METHOD__ . " : ";

        $this->_logger = $logger;

        $this->_logger->debug($pre . 'bof');

        $this->dbTransaction                           = $dbTransaction;
        $this->order                                   = $order;
        $this->config                                  = $config;
        $this->transactionSearchResultInterfaceFactory = $transactionSearchResultInterfaceFactory;
        $this->_customerSession                        = $customerSession;
        $this->_checkoutSession                        = $checkoutSession;
        $this->_orderFactory                           = $orderFactory;
        $this->paygateSession                          = $paygateSession;
        $this->_urlHelper                              = $urlHelper;
        $this->_customerUrl                            = $customerUrl;
        $this->pageFactory                             = $pageFactory;
        $this->_invoiceService                         = $invoiceService;
        $this->invoiceSender                           = $invoiceSender;
        $this->OrderSender                             = $OrderSender;
        $this->_transactionFactory                     = $transactionFactory;
        $this->_paymentMethod                          = $paymentMethod;
        $this->_urlBuilder                             = $urlBuilder;
        $this->orderRepository                         = $orderRepository;
        $this->_storeManager                           = $storeManager;
        $this->_date                                   = $date;
        $this->_orderCollectionFactory                 = $orderCollectionFactory;
        $this->_transactionBuilder                     = $_transactionBuilder;

        $this->state          = $state;
        $this->_paygatehelper = $paygatehelper;

        parent::__construct($context);

        $this->_logger->debug($pre . 'eof');
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
        return $this->_paymentMethod->getConfigData($field);
    }

    /**
     * Returns before_auth_url redirect parameter for customer session
     * @return null
     */
    public function getCustomerBeforeAuthUrl()
    {
    }

    /**
     * Returns a list of action flags [flag_key] => boolean
     * @return array
     */
    public function getActionFlagList()
    {
        return [];
    }

    /**
     * Returns login url parameter for redirect
     * @return string
     */
    public function getLoginUrl()
    {
        return $this->_customerUrl->getLoginUrl();
    }

    /**
     * Returns action name which requires redirect
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
        $this->_actionFlag->set('', 'no-dispatch', true);
        $this->_customerSession->setBeforeAuthUrl($this->_redirect->getRefererUrl());
        $this->getResponse()->setRedirect(
            $this->_urlHelper->addRequestParam($this->_customerUrl->getLoginUrl(), ['context' => 'checkout'])
        );
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
        $this->_logger->debug($pre . 'bof');
        $this->_order = $this->_checkoutSession->getLastRealOrder();

        if ( ! $this->_order->getId()) {
            $this->getResponse()->setStatusHeader(404, '1.1', 'Not found');
            throw new LocalizedException(__('We could not find "Order" for processing'));
        }

        if ($this->_order->getState() != Order::STATE_PENDING_PAYMENT) {
            $this->_order->setState(
                Order::STATE_PENDING_PAYMENT
            )->save();
        }

        if ($this->_order->getQuoteId()) {
            $this->_checkoutSession->setPaygateQuoteId($this->_checkoutSession->getQuoteId());
            $this->_checkoutSession->setPaygateSuccessQuoteId($this->_checkoutSession->getLastSuccessQuoteId());
            $this->_checkoutSession->setPaygateRealOrderId($this->_checkoutSession->getLastRealOrderId());
            $this->_checkoutSession->getQuote()->setIsActive(false)->save();
        }

        $this->_logger->debug($pre . 'eof');
    }

    /**
     * PayGate session instance getter
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
        return $this->_checkoutSession;
    }

    /**
     * Return checkout quote object
     *
     * @return Quote
     */
    protected function _getQuote()
    {
        if ( ! $this->_quote) {
            $this->_quote = $this->_getCheckoutSession()->getQuote();
        }

        return $this->_quote;
    }

}
