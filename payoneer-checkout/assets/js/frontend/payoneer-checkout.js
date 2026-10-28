/******/ (() => { // webpackBootstrap
/******/ 	"use strict";

;// external ["wp","i18n"]
const external_wp_i18n_namespaceObject = window["wp"]["i18n"];
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
;// ./src/js/frontend/payoneer-checkout.js
/**
 * Internal dependencies
 */

(function () {
  'use strict';

  const params = window.payoneer_checkout_general_params || {};
  const form = document.querySelector('form.checkout, form#order_review');
  if (form && params.tab_token) {
    jQuery('<input>', {
      type: 'hidden',
      name: 'payoneer_tab_token',
      value: params.tab_token
    }).appendTo(form);
  }
  window.addEventListener('pageshow', event => {
    if (!event.persisted) {
      return;
    }
    const $form = jQuery('form.checkout, form#order_review');
    $form.removeClass('processing').unblock();
    jQuery('#place_order').prop('disabled', false);
  });
  if ('embedded' === params?.payment_flow) {
    _embeddedCheckout.init(params.env || 'test', params.ajax_url || '', params.reset_nonce || '');
  }
})();
/******/ })()
;