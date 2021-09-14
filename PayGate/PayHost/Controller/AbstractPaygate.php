<?php
/*
 * Copyright (c) 2021 PayGate (Pty) Ltd
 *
 * Author: App Inlet (Pty) Ltd
 *
 * Released under the GNU General Public License
 */

namespace PayGate\PayHost\Controller;

if (interface_exists("Magento\Framework\App\CsrfAwareActionInterface")) {
    class_alias('PayGate\PayHost\Controller\AbstractPaygatem230', 'PayGate\PayHost\Controller\AbstractPaygate');
} else {
    class_alias('PayGate\PayHost\Controller\AbstractPaygatem220', 'PayGate\PayHost\Controller\AbstractPaygate');
}
