<?php
/*
 * Copyright (c) 2021 PayGate (Pty) Ltd
 *
 * Author: App Inlet (Pty) Ltd
 *
 * Released under the GNU General Public License
 */

namespace PayGate\PayHost\Block\Payment;

use Magento\Framework\View\Element\Template\Context;
use Magento\Payment\Model\Config;
use PayGate\PayHost\Model\InfoFactory;

/**
 * PayGate common payment info block
 * Uses default templates
 */
class Info extends \Magento\Payment\Block\Info
{
    /**
     * @var InfoFactory
     */
    protected $_PaygateInfoFactory;

    /**
     * @var Config
     */
    private $_paymentConfig;

    /**
     * @param Context $context
     * @param Config $paymentConfig
     * @param InfoFactory $PaygateInfoFactory
     * @param array $data
     */
    public function __construct(
        Context $context,
        Config $paymentConfig,
        InfoFactory $PaygateInfoFactory,
        array $data = []
    ) {
        $this->_paymentConfig      = $paymentConfig;
        $this->_PaygateInfoFactory = $PaygateInfoFactory;
        parent::__construct($context, $data);
    }

}
