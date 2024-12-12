<?php

/*
 * Copyright (c) 2024 Payfast (Pty) Ltd
 *
 * Author: App Inlet (Pty) Ltd
 *
 * Released under the GNU General Public License
 */

namespace PayGate\PayHost\Controller\Redirect;

use Magento\Framework\Controller\ResultFactory;
use PayGate\PayHost\Controller\AbstractPaygate;

class Order extends AbstractPaygate
{
    /**
     * Request order data and get a response json object
     *
     * @return object
     */
    public function execute()
    {
        $this->resultFactory->create(ResultFactory::TYPE_PAGE);
        $formFields = $this->paymentMethod->getStandardCheckoutFormFields();
        $response   = $this->resultFactory->create(ResultFactory::TYPE_RAW);
        $response->setHeader('Content-type', 'text/plain');
        $response->setHeader('X-Magento-Cache-Control', ' max-age=0, must-revalidate, no-cache, no-store Age: 0');
        $response->setHeader('X-Magento-Cache-Debug', 'MISS');
        $response->setContents(
            json_encode($formFields)
        );

        return $response;
    }
}
