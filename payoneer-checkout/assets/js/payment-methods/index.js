/******/ (() => { // webpackBootstrap
/******/ 	"use strict";
/******/ 	// The require scope
/******/ 	var __webpack_require__ = {};
/******/ 	
/************************************************************************/
/******/ 	/* webpack/runtime/compat get default export */
/******/ 	(() => {
/******/ 		// getDefaultExport function for compatibility with non-harmony modules
/******/ 		__webpack_require__.n = (module) => {
/******/ 			var getter = module && module.__esModule ?
/******/ 				() => (module['default']) :
/******/ 				() => (module);
/******/ 			__webpack_require__.d(getter, { a: getter });
/******/ 			return getter;
/******/ 		};
/******/ 	})();
/******/ 	
/******/ 	/* webpack/runtime/define property getters */
/******/ 	(() => {
/******/ 		// define getter functions for harmony exports
/******/ 		__webpack_require__.d = (exports, definition) => {
/******/ 			for(var key in definition) {
/******/ 				if(__webpack_require__.o(definition, key) && !__webpack_require__.o(exports, key)) {
/******/ 					Object.defineProperty(exports, key, { enumerable: true, get: definition[key] });
/******/ 				}
/******/ 			}
/******/ 		};
/******/ 	})();
/******/ 	
/******/ 	/* webpack/runtime/hasOwnProperty shorthand */
/******/ 	(() => {
/******/ 		__webpack_require__.o = (obj, prop) => (Object.prototype.hasOwnProperty.call(obj, prop))
/******/ 	})();
/******/ 	
/************************************************************************/

;// external ["wp","apiFetch"]
const external_wp_apiFetch_namespaceObject = window["wp"]["apiFetch"];
var external_wp_apiFetch_default = /*#__PURE__*/__webpack_require__.n(external_wp_apiFetch_namespaceObject);
;// ./src/js/payment-methods/_constants.js
const PAYMENT_METHOD_NAME = 'payoneer-checkout';
;// external ["wp","htmlEntities"]
const external_wp_htmlEntities_namespaceObject = window["wp"]["htmlEntities"];
;// ./src/js/payment-methods/_settings.js
/**
 * External dependencies
 */


/**
 * Internal dependencies
 */

const wcSettings = wc.wcSettings.getSetting(PAYMENT_METHOD_NAME + '_data', {});
const settings = wcSettings;
const title = (0,external_wp_htmlEntities_namespaceObject.decodeEntities)(settings.title || 'Payoneer - Credit / Debit cards');
const description = (0,external_wp_htmlEntities_namespaceObject.decodeEntities)(settings.description || '');

/**
 * Whether the integration type is embedded.
 *
 * @return {boolean} True when using the embedded SDK widget.
 */
const isEmbedded = () => {
  return settings?.payment_flow === 'embedded';
};

/**
 * Return the SDK environment string.
 *
 * @return {string} 'test' or 'live'.
 */
const getEnv = () => {
  return settings.env || 'test';
};

/**
 * Return the LIST session longId.
 *
 * @return {string} The longId or an empty string when unavailable.
 */
const getLongId = () => {
  return settings.long_id || '';
};
const setLongId = newLongId => {
  settings.long_id = newLongId; // eslint-disable-line camelcase
};

/**
 * Return the WC-AJAX URL template.
 *
 * @return {string} URL with %%endpoint%% placeholder, or empty string.
 */
const getAjaxUrl = () => {
  return settings.ajax_url || '';
};

/**
 * Return the session-scoped reset nonce.
 *
 * @return {string} Nonce value or empty string.
 */
const getResetNonce = () => {
  return settings.reset_nonce || '';
};
;// external ["wp","i18n"]
const external_wp_i18n_namespaceObject = window["wp"]["i18n"];
;// external ["wp","data"]
const external_wp_data_namespaceObject = window["wp"]["data"];
;// ./src/js/frontend/_embeddedCheckout.js
/**
 * External dependencies
 */

function createAsyncQueue() {
  let chain = Promise.resolve();
  return function enqueue(fn) {
    const task = chain.then(fn);
    chain = task.catch(() => {});
    return task;
  };
}

/**
 * Build a WC-AJAX endpoint URL from an action name.
 *
 * @param {string} action  AJAX action (without the payoneer_checkout_ prefix).
 * @param {string} ajaxUrl WC-AJAX URL template containing %%endpoint%%.
 *
 * @return {string} Full WC-AJAX URL for the given action.
 */
function getWcAjaxUrl(action, ajaxUrl) {
  return ajaxUrl.toString().replace('%%endpoint%%', `payoneer_checkout_${action}`);
}
const DECLINE_MESSAGE_KEY = 'payoneer_decline_message';
const RETRY_INTERACTION_CODES = ['RETRY', 'TRY_OTHER_NETWORK', 'TRY_OTHER_ACCOUNT'];
/**
 * Check whether every visible required field in the form has a value.
 *
 * @param {jQuery} $form Checkout/order-review form.
 *
 * @return {boolean} True when all visible required fields are filled.
 */
function requiredFieldsFilled($form) {
  let filled = true;
  $form.find('.validate-required:visible').each(function () {
    const field = jQuery(this).find('input, select, textarea').first();
    if (!field.length) {
      return;
    }
    if ('checkbox' === field.attr('type')) {
      if (!field.is(':checked')) {
        filled = false;
      }
    } else if (!String(field.val() || '').trim()) {
      filled = false;
    }
  });
  return filled;
}
const embeddedCheckout = {
  env: 'test',
  ajaxUrl: '',
  resetNonce: '',
  mountedLongId: '',
  mountedContainers: {},
  hasRetried: false,
  checkout: null,
  dropIns: {},
  validationState: {},
  payResolve: null,
  chargeReady: false,
  chargeInProgress: false,
  pendingRedirectUrl: '',
  onSessionReset: null,
  _enqueueMount: createAsyncQueue(),
  fallbackActive: false,
  customerUpdateTimer: null,
  walletType: '',
  walletPayPromise: null,
  /**
   * Initialize the embedded checkout.
   *
   * @param {string} env        SDK environment ('test' or 'live').
   * @param {string} ajaxUrl    WC-AJAX URL template (contains %%endpoint%%).
   * @param {string} resetNonce Session-scoped nonce required by the reset_session ajax endpoint.
   */
  init(env, ajaxUrl, resetNonce) {
    this.env = env;
    this.ajaxUrl = ajaxUrl || '';
    this.resetNonce = resetNonce || '';
    this.mountWidget();
    this.bindEvents();
    this.maybeRestoreDeclineNotice();
  },
  maybeRestoreDeclineNotice() {
    const message = window.sessionStorage.getItem(DECLINE_MESSAGE_KEY);
    if (!message) {
      return;
    }
    window.sessionStorage.removeItem(DECLINE_MESSAGE_KEY);
    this.showCheckoutError(message);
  },
  mountWidget() {
    return this._enqueueMount(() => this._mountImpl());
  },
  forceRemount() {
    this.checkout = null;
    this.dropIns = {};
    this.validationState = {};
    this.mountedLongId = '';
    this.mountedContainers = {};
    return this.mountWidget();
  },
  /**
   * Find all mount containers and mount Payoneer drop-in widgets.
   */
  async _mountImpl() {
    const containers = document.querySelectorAll('.payoneer-dropin-mount');
    if (!containers.length) {
      return;
    }
    const longId = containers[0].dataset.longId;
    if (!longId) {
      return;
    }
    const longIdChanged = this.mountedLongId && longId !== this.mountedLongId;
    if (this.checkout && this.checkout.state !== 'ERROR' && this._allContainersConnected() && longIdChanged) {
      this.checkout.updateLongId(longId);
      this.mountedLongId = longId;
      this.hasRetried = false;
      this.clearFallback();
      this._mountContainers(containers);
      return;
    }
    if (!this.checkout || this.checkout.state === 'ERROR' || longIdChanged) {
      this.checkout = null;
      this.dropIns = {};
      this.validationState = {};
      this.mountedLongId = '';
      this.mountedContainers = {};
      try {
        const CheckoutWeb = window.Payoneer?.CheckoutWeb;
        if (!CheckoutWeb) {
          this.handleMountError();
          return;
        }
        this.checkout = await CheckoutWeb({
          env: this.env,
          longId,
          refetchListBeforeCharge: true,
          onBeforeCharge: checkout => this.onBeforeCharge(checkout),
          onPaymentSuccess: () => this.onPaymentSuccess(),
          onPaymentDeclined: (_co, _cn, data) => this.onPaymentDeclined(data),
          onPaymentFailure: (_co, _cn, data) => this.onPaymentFailure(data),
          onBeforeError: (_co, _cn, errorData) => this.onBeforeError(errorData),
          onBeforeProviderRedirect: () => true,
          onValidationInfo: (_co, _cn, data) => this.onValidationInfo(data),
          onPaymentSessionExpired: () => this.onPaymentSessionExpired(),
          onAlreadyPaid: () => this.onAlreadyPaid()
        });
        if (this.checkout.state === 'ERROR') {
          this.handleMountError();
          return;
        }
        this.checkout.on('charge:started', data => {
          if (data?.networkCode === 'CARD') {
            this.extendPaymentTimeout();
          }
        });
        this.checkout.on('payment:3ds:started', () => {
          this.extendPaymentTimeout();
        });
        this.checkout.on('payment:cancelled', () => this.onPaymentCancelled());
      } catch (error) {
        this.handleMountError();
        return;
      }
    }
    this._mountContainers(containers);
    this.mountedLongId = longId;
    this.hasRetried = false;
    this.clearFallback();
  },
  _mountContainers(containers) {
    containers.forEach(el => {
      const name = el.dataset.dropinName;
      if (!name || !this.checkout.isAvailable(name)) {
        return;
      }
      if (this.mountedContainers[name] === el && el.isConnected) {
        return;
      }
      el.innerHTML = '';
      const dropIn = this.checkout.dropIn(name, {
        hidePaymentButton: true
      });
      dropIn.mount(el);
      this.dropIns[name] = dropIn;
      this.mountedContainers[name] = el;
    });
  },
  /**
   * @return {boolean} True when every tracked container is still in the DOM.
   */
  _allContainersConnected() {
    const names = Object.keys(this.mountedContainers);
    if (!names.length) {
      return false;
    }
    return names.every(n => this.mountedContainers[n]?.isConnected);
  },
  /**
   * Map a WC gateway ID to the SDK dropIn component name.
   *
   * @param {string} gatewayId WC payment gateway identifier.
   *
   * @return {string|null} SDK component name, or null if not a Payoneer gateway.
   */
  gatewayToDropIn(gatewayId) {
    if (gatewayId === 'payoneer-checkout') {
      return 'cards';
    }
    const prefix = 'payoneer-';
    if (gatewayId.startsWith(prefix)) {
      return gatewayId.slice(prefix.length);
    }
    return null;
  },
  /**
   * @return {string|null} The dropIn name for the currently selected payment method radio.
   */
  getSelectedDropInName() {
    const selected = document.querySelector('input[name="payment_method"]:checked');
    if (!selected) {
      return null;
    }
    return this.gatewayToDropIn(selected.value);
  },
  /**
   * Whether a Payoneer gateway is selected and its widget is ready.
   *
   * @return {boolean} True when the embedded checkout handler should take over.
   */
  shouldIntercept() {
    const name = this.getSelectedDropInName();
    return !!(name && this.dropIns[name]);
  },
  /**
   * @return {boolean} True when the active payment element is a wallet (Apple/Google Pay).
   */
  isWalletSelected() {
    return this.walletType.includes('apple') || this.walletType.includes('google');
  },
  /**
   * Fire pay() for a wallet inside the click gesture and expose the in-flight promise.
   *
   * @param {string} [dropInName] Explicit dropIn name (Blocks); omitted for classic.
   *
   * @return {Promise<{success: boolean, message?: string}>} The pay outcome promise.
   */
  fireWalletGesture(dropInName) {
    this.pendingRedirectUrl = '';
    this.walletPayPromise = this.payAndWait(dropInName);
    return this.walletPayPromise;
  },
  /**
   * Wrap dropIn.pay() in a Promise resolved by SDK event callbacks.
   *
   * @param {string|null} explicitDropIn Optional dropIn name (used by Blocks checkout).
   *
   * @return {Promise<{success: boolean, message?: string}>} Resolves when SDK reports an outcome.
   */
  payAndWait(explicitDropIn) {
    return new Promise(resolve => {
      this.payResolve = resolve;
      this.chargeReady = true;
      this.chargeInProgress = false;
      const dropInName = explicitDropIn || this.getSelectedDropInName();
      const activeDropIn = dropInName ? this.dropIns[dropInName] : null;
      if (!activeDropIn) {
        this.resolvePayOutcome({
          success: false,
          message: (0,external_wp_i18n_namespaceObject.__)('No payment method selected.', 'payoneer-checkout')
        });
        return;
      }
      const walletPay = this.isWalletSelected();
      if (!walletPay && this.validationState[dropInName] === false) {
        this.resolvePayOutcome({
          success: false,
          message: (0,external_wp_i18n_namespaceObject.__)('Your payment information is incomplete. Please review your payment information before placing your order.', 'payoneer-checkout')
        });
        return;
      }
      this.payTimeout = setTimeout(() => {
        this.resolvePayOutcome({
          success: false,
          message: (0,external_wp_i18n_namespaceObject.__)('Payment confirmation timed out. Please check your order status before retrying.', 'payoneer-checkout')
        });
      }, walletPay ? 120000 : 20000);
      try {
        activeDropIn.pay();
      } catch (error) {
        this.resolvePayOutcome({
          success: false,
          message: (0,external_wp_i18n_namespaceObject.__)('A payment processing error occurred. Please try again.', 'payoneer-checkout')
        });
      }
    });
  },
  /**
   * Resolve a pending payAndWait() Promise and clear the safety timeout.
   *
   * @param {{success: boolean, message?: string}} outcome Payment outcome.
   */
  resolvePayOutcome(outcome) {
    clearTimeout(this.payTimeout);
    this.chargeReady = false;
    this.chargeInProgress = false;
    if (this.payResolve) {
      this.payResolve(outcome);
      this.payResolve = null;
    }
  },
  /**
   * Poll a predicate until it is truthy or the timeout elapses.
   *
   * @param {Function} predicate Returns truthy when the wait is satisfied.
   * @param {number}   timeoutMs Maximum time to wait, in milliseconds.
   *
   * @return {Promise<boolean>} True when satisfied, false on timeout.
   */
  waitFor(predicate, timeoutMs) {
    return new Promise(resolve => {
      if (predicate()) {
        resolve(true);
        return;
      }
      const start = Date.now();
      const timer = setInterval(() => {
        if (predicate()) {
          clearInterval(timer);
          resolve(true);
        } else if (Date.now() - start >= timeoutMs) {
          clearInterval(timer);
          resolve(false);
        }
      }, 100);
    });
  },
  async onBeforeCharge(checkout) {
    if (!this.chargeReady || this.chargeInProgress) {
      return false;
    }
    this.chargeInProgress = true;
    if (this.isWalletSelected() && !this.pendingRedirectUrl) {
      const ready = await this.waitFor(() => !!this.pendingRedirectUrl, 20000);
      if (!ready) {
        this.resolvePayOutcome({
          success: false,
          message: (0,external_wp_i18n_namespaceObject.__)('Payment confirmation timed out. Please check your order status before retrying.', 'payoneer-checkout')
        });
        return false;
      }
    }
    const statusCode = checkout?.listData?.status?.code || '';
    const statusReason = checkout?.listData?.status?.reason || '';
    const alreadyPaid = 'charged' === statusCode || 'preauthorized' === statusCode || 'ended' === statusCode && 'list_used' === statusReason;
    if (alreadyPaid) {
      this.resolvePayOutcome({
        success: true,
        message: (0,external_wp_i18n_namespaceObject.__)('This order has already been paid. Redirecting…', 'payoneer-checkout')
      });
      if (this.pendingRedirectUrl) {
        window.location.href = this.pendingRedirectUrl;
      }
      return false;
    }
    clearTimeout(this.payTimeout);
    this.payTimeout = setTimeout(() => {
      this.resolvePayOutcome({
        success: false,
        message: (0,external_wp_i18n_namespaceObject.__)('Payment confirmation timed out. Please check your order status before retrying.', 'payoneer-checkout')
      });
    }, 120000);
    return true;
  },
  extendPaymentTimeout() {
    clearTimeout(this.payTimeout);
    const expiresAt = new Date(this.checkout?.listData?.expiresAt).getTime();
    this.payTimeout = setTimeout(() => {
      this.resolvePayOutcome({
        success: false,
        message: (0,external_wp_i18n_namespaceObject.__)('Payment confirmation timed out. Please check your order status before retrying.', 'payoneer-checkout')
      });
    }, expiresAt > Date.now() ? expiresAt - Date.now() : 600000);
  },
  onValidationInfo(data) {
    if (undefined !== data?.paymentElementType) {
      this.walletType = String(data.paymentElementType).toLowerCase();
    }
    if (data?.methodName) {
      this.validationState['card' === data.methodName ? 'cards' : data.methodName] = !!data?.valid;
    }
    this.updatePlaceOrderButton();
  },
  /**
   * Disable or enable the Place Order button based on the selected
   * payment method's validation state.
   */
  updatePlaceOrderButton() {
    const btn = document.getElementById('place_order');
    if (!btn) {
      return;
    }
    const dropInName = this.getSelectedDropInName();
    if (!this.isWalletSelected() && dropInName && this.validationState[dropInName] === false) {
      btn.setAttribute('disabled', 'disabled');
    } else {
      btn.removeAttribute('disabled');
    }
  },
  onPaymentSuccess() {
    this.resolvePayOutcome({
      success: true
    });
  },
  onPaymentCancelled() {
    this.resolvePayOutcome({
      success: false,
      cancelled: true,
      message: (0,external_wp_i18n_namespaceObject.__)('Payment was cancelled. Please try again or choose a different payment method.', 'payoneer-checkout')
    });
  },
  onPaymentDeclined(data) {
    this.resolvePayOutcome({
      success: false,
      message: this.formattedMessage(data, (0,external_wp_i18n_namespaceObject.__)('Payment was declined. Please try a different payment method.', 'payoneer-checkout')),
      interactionCode: data?.interaction?.code || ''
    });
  },
  onPaymentFailure(data) {
    this.resolvePayOutcome({
      success: false,
      message: this.formattedMessage(data, (0,external_wp_i18n_namespaceObject.__)('Payment failed. Please try again.', 'payoneer-checkout')),
      interactionCode: data?.interaction?.code || ''
    });
  },
  onBeforeError() {
    this.resolvePayOutcome({
      success: false,
      message: (0,external_wp_i18n_namespaceObject.__)('A payment processing error occurred. Please try again.', 'payoneer-checkout')
    });
    return false;
  },
  onPaymentSessionExpired() {
    this.resolvePayOutcome({
      success: false,
      message: (0,external_wp_i18n_namespaceObject.__)('Payment session expired. Please try again.', 'payoneer-checkout')
    });
    this.resetSession().then(newLongId => {
      if (typeof this.onSessionReset === 'function') {
        this.onSessionReset(newLongId);
      }
      jQuery(document.body).trigger('update_checkout');
    });
  },
  onAlreadyPaid() {
    this.resolvePayOutcome({
      success: true
    });
    if (this.pendingRedirectUrl) {
      window.location.href = this.pendingRedirectUrl;
    }
  },
  formattedMessage(data, defaultMessage = '') {
    const title = data?.interaction?.customerMessage?.title || false;
    const text = data?.interaction?.customerMessage?.text || data?.resultInfo || defaultMessage;
    return title ? `<b>${title}</b></br>${text}` : text;
  },
  /**
   * Display a WooCommerce-style error notice and unblock the checkout form.
   *
   * @param {string} message Error message to display.
   */
  showCheckoutError(message) {
    const $form = jQuery('form.checkout, form#order_review').first();
    if (!$form.length) {
      return;
    }
    jQuery('.woocommerce-NoticeGroup-checkout, .woocommerce-error, .woocommerce-message').remove();
    $form.prepend(`<div class="woocommerce-NoticeGroup woocommerce-NoticeGroup-checkout">
				<ul class="woocommerce-error" role="alert">
					<li><span>${message}</span></li>
				</ul>
			</div>`);
    jQuery('html, body').animate({
      scrollTop: $form.offset().top - 100
    }, 1000);
    $form.removeClass('processing');
    if (typeof $form.unblock === 'function') {
      $form.unblock();
    }
    jQuery(document.body).trigger('checkout_error');
  },
  /**
   * Display pre-rendered WC error notices on the pay-for-order form.
   *
   * @param {jQuery} $form    The order-review form.
   * @param {string} messages Pre-rendered HTML notices from the server.
   */
  showPayForOrderError($form, messages) {
    jQuery('.woocommerce-NoticeGroup-checkout, .woocommerce-error, .woocommerce-message').remove();
    if (messages) {
      $form.prepend('<div class="woocommerce-NoticeGroup woocommerce-NoticeGroup-checkout">' + messages + '</div>');
    }
    jQuery('html, body').animate({
      scrollTop: $form.offset().top - 100
    }, 1000);
    $form.removeClass('processing').unblock();
  },
  /**
   * Handle a failed widget mount by resetting the session and refreshing checkout.
   */
  async handleMountError() {
    if (this.hasRetried) {
      this.activateFallback();
      return;
    }
    this.hasRetried = true;
    const newLongId = await this.resetSession();
    if (typeof this.onSessionReset === 'function') {
      this.onSessionReset(newLongId);
    }
    if (newLongId) {
      document.querySelectorAll('.payoneer-dropin-mount').forEach(el => {
        el.dataset.longId = newLongId;
      });
      this.mountWidget();
    }
    jQuery(document.body).trigger('update_checkout');
  },
  /**
   * Inject a hidden input into the checkout form to signal a hosted-page fallback.
   */
  activateFallback() {
    this.fallbackActive = true;
    const $form = jQuery('form.checkout, form#order_review').first();
    if (!$form.length) {
      return;
    }
    if ($form.find('input[name="payoneer_use_hosted"]').length) {
      return;
    }
    $form.append(jQuery('<input>').attr({
      type: 'hidden',
      name: 'payoneer_use_hosted',
      value: '1'
    }));
  },
  /**
   * Remove the hosted-page fallback signal from the checkout form.
   */
  clearFallback() {
    this.fallbackActive = false;
    jQuery('form.checkout, form#order_review').find('input[name="payoneer_use_hosted"]').remove();
  },
  /**
   * Reset the LIST session and return the new longId.
   *
   * @return {Promise<string>} The new longId, or empty string on failure.
   */
  async resetSession() {
    if (!this.ajaxUrl || !this.resetNonce) {
      return '';
    }
    try {
      const response = await jQuery.ajax({
        url: getWcAjaxUrl('reset_session', this.ajaxUrl),
        type: 'POST',
        dataType: 'json',
        data: {
          _payoneer_reset_nonce: this.resetNonce
        }
      });
      if (response?.data?.nonce) {
        this.resetNonce = response.data.nonce;
      }
      return response?.data?.long_id || '';
    } catch (error) {
      if (403 === error?.status) {
        this.showCheckoutError((0,external_wp_i18n_namespaceObject.__)('Your checkout session has expired. Please refresh the page and try again.', 'payoneer-checkout'));
      }
      return '';
    }
  },
  /**
   * Debounced re-sync of the LIST session after a customer field change.
   */
  debouncedCustomerUpdate() {
    clearTimeout(this.customerUpdateTimer);
    this.customerUpdateTimer = setTimeout(() => {
      if (!this.checkout) {
        return;
      }
      const name = this.getSelectedDropInName();
      if (name && 'cards' === name) {
        return;
      }
      jQuery(document.body).trigger('update_checkout');
    }, 250);
  },
  /**
   * Apply a resolved payment outcome to the classic checkout UI.
   *
   * @param {{success: boolean, cancelled?: boolean, message?: string, interactionCode?: string}} outcome Payment outcome.
   */
  handleClassicOutcome(outcome) {
    if (outcome.success) {
      window.location.href = this.pendingRedirectUrl;
      return;
    }
    if (outcome.cancelled) {
      const $form = jQuery('form.checkout, form#order_review').first();
      $form.removeClass('processing');
      if (typeof $form.unblock === 'function') {
        $form.unblock();
      }
      jQuery('#place_order').prop('disabled', false);
      return;
    }
    if (RETRY_INTERACTION_CODES.includes(outcome.interactionCode)) {
      this.showCheckoutError(outcome.message);
      return;
    }
    window.sessionStorage.setItem(DECLINE_MESSAGE_KEY, outcome.message || '');
    this.resetSession().finally(() => window.location.reload());
  },
  /**
   * Bind WooCommerce legacy checkout events.
   */
  bindEvents() {
    const self = this;
    jQuery(document.body).on('updated_checkout', () => {
      self.forceRemount();
    });
    jQuery(document.body).on('payment_method_selected', () => {
      self.walletType = '';
      self.updatePlaceOrderButton();
      self.debouncedCustomerUpdate();
    });
    jQuery('form.checkout').on('change', '#billing_first_name, #billing_last_name, #billing_email', () => self.debouncedCustomerUpdate());
    document.addEventListener('click', event => {
      if (!event.target.closest('#place_order') || !self.shouldIntercept() || !self.isWalletSelected() || self.fallbackActive || self.payResolve) {
        return;
      }
      const $form = jQuery('form.checkout, form#order_review').first();
      if (!requiredFieldsFilled($form)) {
        return;
      }
      self.fireWalletGesture().then(outcome => self.handleClassicOutcome(outcome));
    }, true);
    jQuery('form.checkout').off('checkout_place_order_success.payoneer_checkout').on('checkout_place_order_success.payoneer_checkout', (_e, result) => {
      if (!self.shouldIntercept()) {
        return true;
      }
      self.pendingRedirectUrl = result.redirect;
      result.redirect = '#payoneer-processing';
      if (result.payoneer_wc_nonce) {
        jQuery('input[name="woocommerce-process-checkout-nonce"]').val(result.payoneer_wc_nonce);
      }
      if (self.isWalletSelected()) {
        return true;
      }
      self.payAndWait().then(outcome => self.handleClassicOutcome(outcome));
      return true;
    });

    // Pay-for-order form: submit via AJAX so we can intercept the SDK payment.
    const $orderReview = jQuery('form#order_review');
    if ($orderReview.length) {
      $orderReview.on('submit.payoneer_checkout', e => self.handlePayForOrder(e, $orderReview));
    }
  },
  /**
   * Handle pay-for-order form submission via AJAX.
   *
   * @param {Event}  e     Submit event.
   * @param {jQuery} $form The order-review form.
   *
   * @return {boolean} False to prevent default submission.
   */
  handlePayForOrder(e, $form) {
    if (!this.shouldIntercept()) {
      return true;
    }
    e.preventDefault();
    $form.addClass('processing').block({
      message: null,
      overlayCSS: {
        background: '#fff',
        opacity: 0.6
      }
    });
    jQuery.ajax({
      url: window.location.href,
      type: 'POST',
      data: $form.serialize(),
      dataType: 'json',
      success: json => {
        if (!json.success) {
          this.showPayForOrderError($form, json.data?.messages);
          if (json.data?.refresh) {
            window.location.reload();
          }
          return;
        }
        const result = json.data;
        this.pendingRedirectUrl = result.redirect;
        if (this.isWalletSelected()) {
          return;
        }
        this.payAndWait().then(outcome => {
          if (outcome.success) {
            window.location.href = this.pendingRedirectUrl;
          } else {
            this.showCheckoutError(outcome.message);
          }
        });
      },
      error: () => {
        this.showCheckoutError((0,external_wp_i18n_namespaceObject.__)('A payment processing error occurred. Please try again.', 'payoneer-checkout'));
      }
    });
    return false;
  }
};
/* harmony default export */ const _embeddedCheckout = (embeddedCheckout);
;// external "ReactJSXRuntime"
const external_ReactJSXRuntime_namespaceObject = window["ReactJSXRuntime"];
;// ./src/js/payment-methods/_payment-method.js
/**
 * External dependencies
 */



/**
 * Internal dependencies
 */



const {
  useEffect,
  useRef,
  useState
} = window.wp.element;

/**
 * Whether WC Blocks reports validation errors on the checkout fields.
 *
 * @return {boolean} True when any field validation error is registered.
 */
function hasCheckoutValidationErrors() {
  const {
    validationStore
  } = window.wc?.wcBlocksData ?? {};
  if (!validationStore) {
    return false;
  }
  return !!(0,external_wp_data_namespaceObject.select)(validationStore)?.hasValidationErrors();
}
const useSdkRefreshOnBillingChange = (billing, cartData) => {
  const country = billing?.billingAddress?.country || '';
  const state = billing?.billingAddress?.state || '';
  const listHash = cartData?.extensions?.['payoneer-checkout']?.list_hash || '';
  const initialized = useRef(false);
  useEffect(() => {
    if (!country) {
      return;
    }
    if (!initialized.current) {
      initialized.current = true;
      return;
    }
    if (_embeddedCheckout.chargeInProgress || _embeddedCheckout.payResolve) {
      return;
    }
    _embeddedCheckout.forceRemount();
  }, [country, state, listHash]);
};

/**
 * Subscribe to WC Blocks checkout events for the embedded payment flow.
 *
 * @param {Function} onPaymentSetup        WC Blocks onPaymentSetup registration.
 * @param {Function} onCheckoutSuccess     WC Blocks onCheckoutSuccess registration.
 * @param {Function} onCheckoutFail        WC Blocks onCheckoutFail registration.
 * @param {string}   emitResponseSuccess   Response type constant for success.
 * @param {string}   emitResponseError     Response type constant for error.
 * @param {string}   noticeContextPayments Notice context for payment errors.
 *
 * @param {string}   dropInName            SDK dropIn component name (e.g. 'cards', 'afterpay').
 * @return {Function} Cleanup function that unsubscribes all handlers.
 */
const payoneerCheckoutHandler = (onPaymentSetup, onCheckoutSuccess, onCheckoutFail, emitResponseSuccess, emitResponseError, noticeContextPayments, dropInName) => {
  const unsubscribePaymentSetup = onPaymentSetup(() => {
    if (!Object.keys(_embeddedCheckout.dropIns).length) {
      return {
        type: emitResponseSuccess,
        meta: {
          paymentMethodData: {
            payoneer_use_hosted: '1' // eslint-disable-line camelcase
          }
        }
      };
    }
    if (hasCheckoutValidationErrors()) {
      return;
    }
    if (!_embeddedCheckout.isWalletSelected() && dropInName && _embeddedCheckout.validationState[dropInName] === false) {
      return {
        type: emitResponseError,
        message: (0,external_wp_i18n_namespaceObject.__)('Your payment information is incomplete. Please review your payment information before placing your order.', 'payoneer-checkout'),
        messageContext: noticeContextPayments
      };
    }
    return {
      type: emitResponseSuccess
    };
  });
  const unsubscribeCheckoutSuccess = onCheckoutSuccess(({
    redirectUrl: checkoutRedirectUrl
  }) => {
    if (!Object.keys(_embeddedCheckout.dropIns).length) {
      return {
        type: emitResponseSuccess,
        redirectUrl: checkoutRedirectUrl
      };
    }
    _embeddedCheckout.pendingRedirectUrl = checkoutRedirectUrl;
    const payPromise = _embeddedCheckout.isWalletSelected() && _embeddedCheckout.walletPayPromise ? _embeddedCheckout.walletPayPromise : _embeddedCheckout.payAndWait(dropInName || null);
    return payPromise.then(async outcome => {
      if (outcome.success) {
        return {
          type: emitResponseSuccess,
          redirectUrl: checkoutRedirectUrl
        };
      }
      const retryable = ['RETRY', 'TRY_OTHER_NETWORK', 'TRY_OTHER_ACCOUNT'].includes(outcome.interactionCode);
      if (!retryable) {
        const newLongId = await _embeddedCheckout.resetSession();
        if (newLongId && typeof _embeddedCheckout.onSessionReset === 'function') {
          _embeddedCheckout.onSessionReset(newLongId);
        }
      }
      return {
        type: emitResponseError,
        message: outcome.message,
        messageContext: noticeContextPayments
      };
    }).catch(() => ({
      type: emitResponseError,
      message: (0,external_wp_i18n_namespaceObject.__)('A payment processing error occurred. Please try again.', 'payoneer-checkout'),
      messageContext: noticeContextPayments
    }));
  });
  const unsubscribeCheckoutFail = onCheckoutFail(({
    processingResponse
  }) => {
    const details = processingResponse?.paymentDetails || {};
    const newLongId = details.payoneer_long_id;
    if (newLongId && typeof _embeddedCheckout.onSessionReset === 'function') {
      _embeddedCheckout.onSessionReset(newLongId);
    }
    _embeddedCheckout.forceRemount();
    if (details.payoneer_session_message) {
      return {
        type: emitResponseError,
        message: details.payoneer_session_message,
        messageContext: noticeContextPayments
      };
    }
    return {
      type: emitResponseError
    };
  });
  const onPlaceOrderClick = event => {
    if (!event.target.closest('.wc-block-components-checkout-place-order-button')) {
      return;
    }
    if (!_embeddedCheckout.dropIns[dropInName] || !_embeddedCheckout.isWalletSelected() || _embeddedCheckout.fallbackActive || _embeddedCheckout.payResolve) {
      return;
    }
    if (hasCheckoutValidationErrors()) {
      return;
    }
    _embeddedCheckout.fireWalletGesture(dropInName);
  };
  document.addEventListener('click', onPlaceOrderClick, true);
  return () => {
    unsubscribePaymentSetup();
    unsubscribeCheckoutSuccess();
    if (unsubscribeCheckoutFail) {
      unsubscribeCheckoutFail();
    }
    document.removeEventListener('click', onPlaceOrderClick, true);
    _embeddedCheckout.walletType = '';
  };
};

/**
 * Renders the payment method description paragraph.
 *
 * @return {JSX.Element|null} Description paragraph or null when empty.
 */
const Description = () => {
  if (!description) {
    return null;
  }
  return /*#__PURE__*/(0,external_ReactJSXRuntime_namespaceObject.jsx)("p", {
    className: "payoneer-checkout-description",
    children: description
  });
};

/**
 * Embedded checkout widget with SDK mount and payment event handling.
 *
 * @param {Object} props                   Props from the payment API.
 * @param {Object} props.billing           WC Blocks billing data (BillingDataProps).
 * @param {Object} props.cartData          WC Blocks cart data (CartDataProps).
 * @param {Object} props.eventRegistration WC Blocks event registration object.
 * @param {Object} props.emitResponse      WC Blocks emit response helpers.
 *
 * @return {JSX.Element|null} Widget container div, or null when falling back to hosted.
 */
const EmbeddedContent = ({
  billing,
  cartData,
  eventRegistration,
  emitResponse
}) => {
  const containerRef = useRef(null);
  const {
    onPaymentSetup,
    onCheckoutSuccess,
    onCheckoutFail
  } = eventRegistration || {};
  const {
    responseTypes,
    noticeContexts
  } = emitResponse || {};
  const [longId, setLongIdState] = useState(getLongId);
  const [isFallback, setIsFallback] = useState(false);
  useSdkRefreshOnBillingChange(billing, cartData);
  useEffect(() => {
    _embeddedCheckout.onSessionReset = newLongId => {
      const resolvedId = newLongId || getLongId();
      if (resolvedId) {
        setLongId(resolvedId);
        setLongIdState(resolvedId);
      }
      setIsFallback(false);
    };
    return () => {
      _embeddedCheckout.onSessionReset = null;
    };
  }, []);
  useEffect(() => {
    if (!containerRef.current || !longId) {
      return;
    }
    containerRef.current.dataset.longId = longId;
    containerRef.current.dataset.dropinName = 'cards';
    _embeddedCheckout.env = getEnv();
    _embeddedCheckout.ajaxUrl = getAjaxUrl();
    _embeddedCheckout.resetNonce = getResetNonce();
    _embeddedCheckout.mountWidget().then(() => {
      if (_embeddedCheckout.fallbackActive) {
        setIsFallback(true);
      }
    });
  }, [longId]);
  useEffect(() => {
    if (!onPaymentSetup || !onCheckoutSuccess) {
      return;
    }
    return payoneerCheckoutHandler(onPaymentSetup, onCheckoutSuccess, onCheckoutFail, responseTypes.SUCCESS, responseTypes.ERROR, noticeContexts.PAYMENTS, 'cards');
  }, [onPaymentSetup, onCheckoutSuccess, onCheckoutFail, responseTypes, noticeContexts]);
  if (isFallback) {
    return null;
  }
  return /*#__PURE__*/(0,external_ReactJSXRuntime_namespaceObject.jsx)("div", {
    ref: containerRef,
    className: "payoneer-dropin-mount",
    "data-dropin-name": "cards"
  });
};

/**
 * Content component — delegates to the correct integration variant.
 *
 * @param {Object} props Props from the payment API.
 *
 * @return {JSX.Element} Hosted description or embedded SDK widget.
 */
const PayoneerContent = props => {
  return /*#__PURE__*/(0,external_ReactJSXRuntime_namespaceObject.jsxs)(external_ReactJSXRuntime_namespaceObject.Fragment, {
    children: [/*#__PURE__*/(0,external_ReactJSXRuntime_namespaceObject.jsx)(Description, {}), isEmbedded() && /*#__PURE__*/(0,external_ReactJSXRuntime_namespaceObject.jsx)(EmbeddedContent, {
      ...props
    })]
  });
};

/**
 * Edit component — renders description only (no SDK in the block editor).
 *
 * @return {JSX.Element} Description paragraph.
 */
const PayoneerEditContent = () => {
  return /*#__PURE__*/(0,external_ReactJSXRuntime_namespaceObject.jsx)(Description, {});
};

/**
 * Network payment method content with embedded SDK widget.
 *
 * @param {Object} props                   Props from the payment API.
 * @param {Object} props.billing           WC Blocks billing data (BillingDataProps).
 * @param {Object} props.cartData          WC Blocks cart data (CartDataProps).
 * @param {Object} props.settings          Network-specific settings from PHP.
 * @param {Object} props.eventRegistration WC Blocks event registration object.
 * @param {Object} props.emitResponse      WC Blocks emit response helpers.
 *
 * @return {JSX.Element} Network widget container.
 */
const NetworkContent = ({
  billing,
  cartData,
  settings: networkSettings,
  eventRegistration,
  emitResponse
}) => {
  const containerRef = useRef(null);
  const {
    onPaymentSetup,
    onCheckoutSuccess,
    onCheckoutFail
  } = eventRegistration || {};
  const {
    responseTypes,
    noticeContexts
  } = emitResponse || {};
  const dropinName = networkSettings.dropin_name;
  useSdkRefreshOnBillingChange(billing, cartData);
  useEffect(() => {
    if (!containerRef.current || !networkSettings.long_id) {
      return;
    }
    containerRef.current.dataset.longId = networkSettings.long_id;
    containerRef.current.dataset.dropinName = dropinName;
    _embeddedCheckout.env = networkSettings.env || 'test';
    _embeddedCheckout.ajaxUrl = networkSettings.ajax_url || '';
    _embeddedCheckout.resetNonce = networkSettings.reset_nonce || '';
    _embeddedCheckout.mountWidget();
  }, [networkSettings.long_id, dropinName]); // eslint-disable-line react-hooks/exhaustive-deps

  useEffect(() => {
    if (!onPaymentSetup || !onCheckoutSuccess) {
      return;
    }
    return payoneerCheckoutHandler(onPaymentSetup, onCheckoutSuccess, onCheckoutFail, responseTypes.SUCCESS, responseTypes.ERROR, noticeContexts.PAYMENTS, dropinName);
  }, [onPaymentSetup, onCheckoutSuccess, onCheckoutFail, responseTypes, noticeContexts, dropinName]);
  const networkDescription = networkSettings.description || '';
  return /*#__PURE__*/(0,external_ReactJSXRuntime_namespaceObject.jsxs)(external_ReactJSXRuntime_namespaceObject.Fragment, {
    children: [networkDescription && /*#__PURE__*/(0,external_ReactJSXRuntime_namespaceObject.jsx)("p", {
      className: "payoneer-checkout-description",
      children: networkDescription
    }), /*#__PURE__*/(0,external_ReactJSXRuntime_namespaceObject.jsx)("div", {
      ref: containerRef,
      className: "payoneer-dropin-mount"
    })]
  });
};

/**
 * Network edit content — renders description only (no SDK in the block editor).
 *
 * @param {Object} props          Props from the payment API.
 * @param {Object} props.settings Network-specific settings from PHP.
 *
 * @return {JSX.Element|null} Description paragraph or null.
 */
const NetworkEditContent = ({
  settings: networkSettings
}) => {
  const networkDescription = networkSettings.description || '';
  if (!networkDescription) {
    return null;
  }
  return /*#__PURE__*/(0,external_ReactJSXRuntime_namespaceObject.jsx)("p", {
    className: "payoneer-checkout-description",
    children: networkDescription
  });
};

/**
 * Network label with icon for the payment method list.
 *
 * @param {Object} props          Props from the payment API.
 * @param {Object} props.network  Network data (code, label, logo).
 * @param {Object} props.settings Network-specific settings from PHP.
 *
 * @return {JSX.Element} Label with optional icon.
 */
const NetworkLabel = ({
  network,
  settings: networkSettings,
  ...props
}) => {
  const {
    PaymentMethodLabel,
    PaymentMethodIcons
  } = props.components;
  const networkTitle = networkSettings.title || network.label;
  return /*#__PURE__*/(0,external_ReactJSXRuntime_namespaceObject.jsxs)(external_ReactJSXRuntime_namespaceObject.Fragment, {
    children: [/*#__PURE__*/(0,external_ReactJSXRuntime_namespaceObject.jsx)(PaymentMethodLabel, {
      text: networkTitle
    }), networkSettings.icon && /*#__PURE__*/(0,external_ReactJSXRuntime_namespaceObject.jsx)(PaymentMethodIcons, {
      className: "payoneer-checkout-icons",
      icons: [{
        id: network.code.toLowerCase(),
        src: networkSettings.icon,
        alt: networkTitle
      }]
    })]
  });
};

;// ./src/js/payment-methods/index.js
/**
 * External dependencies
 */
const {
  registerPaymentMethod
} = wc.wcBlocksRegistry;


/**
 * Internal dependencies
 */




external_wp_apiFetch_default().use((options, next) => {
  if (settings.tab_token && (options.path || options.url || '').includes('/wc/store/')) {
    options.headers = {
      ...options.headers,
      'X-Payoneer-Tab': settings.tab_token
    };
  }
  return next(options);
});

/**
 * Payment method label rendered beside the radio button.
 *
 * @param {Object} props Props from the payment API.
 *
 * @return {JSX.Element} Label component.
 */
const Label = props => {
  const {
    PaymentMethodLabel,
    PaymentMethodIcons
  } = props.components;
  return /*#__PURE__*/(0,external_ReactJSXRuntime_namespaceObject.jsxs)(external_ReactJSXRuntime_namespaceObject.Fragment, {
    children: [/*#__PURE__*/(0,external_ReactJSXRuntime_namespaceObject.jsx)(PaymentMethodLabel, {
      text: title
    }), settings.icon && /*#__PURE__*/(0,external_ReactJSXRuntime_namespaceObject.jsx)(PaymentMethodIcons, {
      className: "payoneer-checkout-icons",
      icons: [{
        id: 'payoneer',
        src: settings.icon,
        alt: title
      }]
    })]
  });
};
registerPaymentMethod({
  name: PAYMENT_METHOD_NAME,
  label: /*#__PURE__*/(0,external_ReactJSXRuntime_namespaceObject.jsx)(Label, {}),
  ariaLabel: title,
  content: /*#__PURE__*/(0,external_ReactJSXRuntime_namespaceObject.jsx)(PayoneerContent, {}),
  edit: /*#__PURE__*/(0,external_ReactJSXRuntime_namespaceObject.jsx)(PayoneerEditContent, {}),
  canMakePayment: () => true,
  paymentMethodId: PAYMENT_METHOD_NAME,
  supports: {
    features: settings.supports || ['products']
  }
});

/**
 * Register extra network payment methods (PayPal, Klarna, etc.).
 */
const enabledNetworks = settings.enabled_networks || [];
enabledNetworks.forEach(network => {
  const networkId = 'payoneer-' + network.code.toLowerCase();
  const networkSettings = wc.wcSettings.getSetting(networkId + '_data', {});
  if (!networkSettings || !Object.keys(networkSettings).length) {
    return;
  }
  registerPaymentMethod({
    name: networkId,
    label: /*#__PURE__*/(0,external_ReactJSXRuntime_namespaceObject.jsx)(NetworkLabel, {
      network: network,
      settings: networkSettings
    }),
    ariaLabel: networkSettings.title || network.label,
    content: /*#__PURE__*/(0,external_ReactJSXRuntime_namespaceObject.jsx)(NetworkContent, {
      settings: networkSettings
    }),
    edit: /*#__PURE__*/(0,external_ReactJSXRuntime_namespaceObject.jsx)(NetworkEditContent, {
      settings: networkSettings
    }),
    canMakePayment: ({
      paymentMethods
    }) => {
      if (!paymentMethods?.length) {
        return true;
      }
      return paymentMethods.includes(networkId);
    },
    paymentMethodId: networkId,
    supports: {
      features: networkSettings.supports || ['products']
    }
  });
});
/******/ })()
;