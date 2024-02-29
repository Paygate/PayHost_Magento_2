/*
 * Copyright (c) 2024 Payfast (Pty) Ltd
 *
 * Author: App Inlet (Pty) Ltd
 *
 * Released under the GNU General Public License
 */

/*browser:true*/
/*global define*/
define(
  [
    'uiComponent',
    'Magento_Checkout/js/model/payment/renderer-list'
  ],
  function (Component,
    rendererList
  ) {
    'use strict'

    rendererList.push(
      {
        type: 'payhost',
        component: 'PayGate_PayHost/js/view/payment/method-renderer/payhost-method'
      }
    )
    /** Add view logic here if needed */
    return Component.extend({})
  }
)
