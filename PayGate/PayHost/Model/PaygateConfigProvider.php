<?php

/*
 * Copyright (c) 2024 Payfast (Pty) Ltd
 *
 * Author: App Inlet (Pty) Ltd
 *
 * Released under the GNU General Public License
 */

namespace PayGate\PayHost\Model;

use Magento\Checkout\Model\ConfigProviderInterface;
use Magento\Customer\Helper\Session\CurrentCustomer;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Locale\ResolverInterface;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Asset\Repository;
use Magento\Payment\Helper\Data as PaymentHelper;
use Magento\Payment\Model\Method\AbstractMethod;
use Magento\Vault\Api\PaymentTokenManagementInterface;
use PayGate\PayHost\Helper\Data as PaygateHelper;
use Psr\Log\LoggerInterface;

class PaygateConfigProvider implements ConfigProviderInterface
{
    /**
     * @var ResolverInterface
     */
    protected $localeResolver;

    /**
     * @var Config
     */
    protected $config;

    /**
     * @var CurrentCustomer
     */
    protected $currentCustomer;

    /**
     * @var LoggerInterface
     */
    protected $_logger;

    /**
     * @var PaygateHelper
     */
    protected $paygateHelper;

    /**
     * @var string[]
     */
    protected $methodCodes = [
        Config::METHOD_CODE,
    ];

    /**
     * @var AbstractMethod[]
     */
    protected $methods = [];

    /**
     * @var PaymentHelper
     */
    protected $paymentHelper;

    /**
     * @var Repository
     */
    protected $assetRepo;

    /**
     * @var UrlInterface
     */
    protected $urlBuilder;

    /**
     * @var RequestInterface
     */
    protected $request;
    /**
     * @var \Magento\Vault\Api\PaymentTokenManagementInterface
     */
    private PaymentTokenManagementInterface $tokenManagement;

    /**
     * @param LoggerInterface $logger
     * @param ConfigFactory $configFactory
     * @param ResolverInterface $localeResolver
     * @param CurrentCustomer $currentCustomer
     * @param PaygateHelper $paygateHelper
     * @param PaymentHelper $paymentHelper
     * @param Repository $assetRepo
     * @param UrlInterface $urlBuilder
     * @param RequestInterface $request
     * @param PaymentTokenManagementInterface $tokenManagement
     */
    public function __construct(
        LoggerInterface $logger,
        ConfigFactory $configFactory,
        ResolverInterface $localeResolver,
        CurrentCustomer $currentCustomer,
        PaygateHelper $paygateHelper,
        PaymentHelper $paymentHelper,
        Repository $assetRepo,
        UrlInterface $urlBuilder,
        RequestInterface $request,
        PaymentTokenManagementInterface $tokenManagement
    ) {
        $this->_logger = $logger;
        $pre           = __METHOD__ . ' : ';
        $this->_logger->debug($pre . 'bof');

        $this->localeResolver  = $localeResolver;
        $this->config          = $configFactory->create();
        $this->currentCustomer = $currentCustomer;
        $this->paygateHelper   = $paygateHelper;
        $this->paymentHelper   = $paymentHelper;
        $this->assetRepo       = $assetRepo;
        $this->urlBuilder      = $urlBuilder;
        $this->request         = $request;
        $this->tokenManagement = $tokenManagement;

        foreach ($this->methodCodes as $code) {
            $this->methods[$code] = $this->paymentHelper->getMethodInstance($code);
        }

        $this->_logger->debug($pre . 'eof and this  methods has : ', $this->methods);
    }

    /**
     * @inheritdoc
     */
    public function getConfig(): array
    {
        $pre = __METHOD__ . ' : ';

        // Cards
        $cards     = [];
        $cardCount = 0;
        if ($customerId = $this->currentCustomer->getCustomerId()) {
            $cardList = $this->tokenManagement->getListByCustomerId($customerId);
            foreach ($cardList as $card) {
                if ($card->getIsActive() && $card->getIsVisible() && $card->getPaymentMethodCode() === 'payhost') {
                    $cardDetail = json_decode($card->getTokenDetails());
                    $cards[]    = [
                        'masked_cc' => $cardDetail->maskedCC,
                        'token'     => $card->getPublicHash(),
                        'card_type' => $cardDetail->type,
                    ];
                    $cardCount++;
                }
            }
            $isVault = $this->config->isVault();
        } else {
            $isVault = false;
        }

        $this->_logger->debug($pre . 'bof');
        $payHostConfig = [
            'payment' => [
                'payhost' => [
                    'paymentAcceptanceMarkSrc'  => $this->config->getPaymentMarkImageUrl(),
                    'paymentAcceptanceMarkHref' => $this->config->getPaymentMarkWhatIsPaygate(),
                    'isVault'                   => $isVault,
                    'saved_card_data'           => json_encode($cards),
                    'card_count'                => $cardCount,
                ],
            ],
        ];

        foreach ($this->methodCodes as $code) {
            if ($this->methods[$code]->isAvailable()) {
                $payHostConfig['payment']['payhost']['redirectUrl'][$code]          = $this->getMethodRedirectUrl(
                    $code
                );
                $payHostConfig['payment']['payhost']['billingAgreementCode'][$code] = $this->getBillingAgreementCode(
                    $code
                );
            }
        }
        $this->_logger->debug($pre . 'eof', $payHostConfig);

        return $payHostConfig;
    }

    /**
     * Retrieve url of a view file
     *
     * @param string $fileId
     * @param array $params
     *
     * @return string
     */
    public function getViewFileUrl($fileId, array $params = [])
    {
        try {
            $params = array_merge(['_secure' => $this->request->isSecure()], $params);

            return $this->assetRepo->getUrlWithParams($fileId, $params);
        } catch (LocalizedException $e) {
            $this->_logger->critical($e);

            return $this->urlBuilder->getUrl('', ['_direct' => 'core/index/notFound']);
        }
    }

    /**
     * Return redirect URL for method
     *
     * @param string $code
     *
     * @return mixed
     */
    protected function getMethodRedirectUrl($code)
    {
        $pre = __METHOD__ . ' : ';
        $this->_logger->debug($pre . 'bof');

        $methodUrl = $this->methods[$code]->getCheckoutRedirectUrl();

        $this->_logger->debug($pre . 'eof');

        return $methodUrl;
    }

    /**
     * Return billing agreement code for method
     *
     * @param string $code
     *
     * @return null|string
     */
    protected function getBillingAgreementCode($code)
    {
        $pre = __METHOD__ . ' : ';
        $this->_logger->debug($pre . 'bof');

        $customerId = $this->currentCustomer->getCustomerId();
        $this->config->setMethod($code);

        $this->_logger->debug($pre . 'eof');

        // Always return null
        return $this->paygateHelper->shouldAskToCreateBillingAgreement($this->config, $customerId);
    }
}
