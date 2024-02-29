<?php

/*
 * Copyright (c) 2024 Payfast (Pty) Ltd
 *
 * Author: App Inlet (Pty) Ltd
 *
 * Released under the GNU General Public License
 */

namespace PayGate\PayHost\Helper;

use Magento\Framework\App\Config\BaseFactory;
use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Psr\Log\LoggerInterface;

/**
 * Paygate Data helper
 */
class Cron extends AbstractHelper
{

    /**
     * @var LoggerInterface
     */
    protected $_logger;

    /**
     * @param Context $context
     */
    public function __construct(
        Context $context
    ) {
        $this->_logger = $context->getLogger();
    }
}
