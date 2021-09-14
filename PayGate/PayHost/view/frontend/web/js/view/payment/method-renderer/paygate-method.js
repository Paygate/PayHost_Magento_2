/*
 * Copyright (c) 2021 PayGate (Pty) Ltd
 *
 * Author: App Inlet (Pty) Ltd
 *
 * Released under the GNU General Public License
 */

define(
  [
    'jquery',
    'Magento_Checkout/js/view/payment/default',
    'Magento_Checkout/js/action/place-order',
    'Magento_Checkout/js/action/select-payment-method',
    'Magento_Customer/js/model/customer',
    'Magento_Checkout/js/checkout-data',
    'Magento_Checkout/js/model/payment/additional-validators',
    'mage/url',
    'Magento_Payment/js/view/payment/cc-form',
    'Magento_Vault/js/view/payment/vault-enabler'
  ],
  function ($,
    Component,
    placeOrderAction,
    selectPaymentMethodAction,
    customer,
    checkoutData,
    additionalValidators,
    url,
    CCForm,
    VaultEnabler
  ) {
    'use strict'

    return Component.extend({
      defaults: {
        template: 'PayGate_PayHost/payment/paygate'
      },
      getData: function () {
        var data = {
          'method': this.item.method
        }
        return data
      },

      placeOrder: function (data, event) {
        if (event) {
          event.preventDefault()
        }
        var self = this,
          placeOrder,
          emailValidationResult = customer.isLoggedIn(),
          loginFormSelector = 'form[data-role=email-with-possible-login]'
        if (!customer.isLoggedIn()) {
          $(loginFormSelector).validation()
          emailValidationResult = Boolean($(loginFormSelector + ' input[name=username]').valid())
        }
        if (emailValidationResult && this.validate() && additionalValidators.validate()) {
          this.isPlaceOrderActionAllowed(false)
          placeOrder = placeOrderAction(this.getData(), false, this.messageContainer)
          $.when(placeOrder).fail(function () {
            self.isPlaceOrderActionAllowed(true)
          }).done(this.afterPlaceOrder.bind(this))
          return true
        }
      },
      getCode: function () {
        return 'payhost'
      },
      selectPaymentMethod: function () {
        selectPaymentMethodAction(this.getData())
        checkoutData.setSelectedPaymentMethod(this.item.method)
        return true
      },
      /**
       * Get value of instruction field.
       * @returns {String}
       */
      getInstructions: function () {
        return window.checkoutConfig.payment.instructions[this.item.method]
      },
      isAvailable: function () {
        return quote.totals().grand_total <= 0
      },
      afterPlaceOrder: function () {
        window.location.replace(url.build(window.checkoutConfig.payment.payhost.redirectUrl.payhost))
      },
      /** Returns payment acceptance mark link path */
      getPaymentAcceptanceMarkHref: function () {
        return window.checkoutConfig.payment.payhost.paymentAcceptanceMarkHref
      },
      /** Returns payment acceptance mark image path */
      getPaymentAcceptanceMarkSrc: function () {
        return window.checkoutConfig.payment.payhost.paymentAcceptanceMarkSrc
      }

    })
  }
)
