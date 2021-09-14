<?php
/*
 * Copyright (c) 2021 PayGate (Pty) Ltd
 *
 * Author: App Inlet (Pty) Ltd
 *
 * Released under the GNU General Public License
 */

namespace PayGate\PayHost\Controller\Notify;

/**
 * Check for existence of CsrfAwareActionInterface - only v2.3.0+
 */
if (interface_exists("Magento\Framework\App\CsrfAwareActionInterface")) {
    class_alias('PayGate\PayHost\Controller\Notify\Indexm230', 'PayGate\PayHost\Controller\Notify\Index');
} else {
    class_alias('PayGate\PayHost\Controller\Notify\Indexm220', 'PayGate\PayHost\Controller\Notify\Index');
}
