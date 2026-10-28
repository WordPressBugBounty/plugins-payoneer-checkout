/******/ (() => { // webpackBootstrap
(function ($) {
  'use strict';

  const {
    __,
    sprintf
  } = wp.i18n;
  const params = window.payoneer_checkout_admin_params || {};
  let networksByDivision = params.networks_by_division || {};

  // -------------------------------------------------------------------------
  // Conditional field toggling.
  // -------------------------------------------------------------------------

  $('.conditional-hide').each(function () {
    const $el = $(this);
    const $relElement = $(`#woocommerce_payoneer-checkout_${$el.data('show-rel')}`);
    if ($relElement.length) {
      maybeShowHide($el, $relElement);
      $relElement.on('change', function () {
        maybeShowHide($el, $relElement);
      });
    }
  });
  function maybeShowHide($el, $relElement) {
    const $row = $el.closest('tr');
    const $target = $row.length ? $row : $el;
    if ($relElement.attr('type') === 'checkbox') {
      const value = $el.data('show-if');
      let expected;
      if (typeof value !== 'undefined') {
        if (value === 'yes' || value === '1' || value === true) {
          expected = true;
        } else {
          expected = false;
        }
      } else {
        expected = true;
      }
      if ($relElement.is(':checked') === expected) {
        $target.show();
      } else {
        $target.hide();
      }
    } else if ($relElement.val() === $el.data('show-if')) {
      $target.show();
    } else {
      $target.hide();
    }
  }

  // -------------------------------------------------------------------------
  // Chevron expand/collapse
  // -------------------------------------------------------------------------

  $(document).on('click', '.payoneer-payment-method__toggle-details', function () {
    const $button = $(this);
    const $detailsRow = $button.closest('.payoneer-payment-method').next('.payoneer-payment-method__details');
    const isExpanded = $button.attr('aria-expanded') === 'true';
    $button.attr('aria-expanded', isExpanded ? 'false' : 'true');
    $button.closest('.payoneer-payment-method').toggleClass('is-expanded', !isExpanded);
    $detailsRow.toggle(!isExpanded);
  });

  // -------------------------------------------------------------------------
  // Integration type toggle: embedded ↔ hosted blocks
  // -------------------------------------------------------------------------

  const $integrationTypeSelect = $('#woocommerce_payoneer-checkout_payment_flow');
  const $embeddedBlock = $('.payoneer-payment-methods--embedded');
  const $hostedBlock = $('.payoneer-payment-methods--hosted');
  if ($integrationTypeSelect.length) {
    toggleIntegrationBlocks();
    $integrationTypeSelect.on('change', toggleIntegrationBlocks);
  }
  function toggleIntegrationBlocks() {
    if ($integrationTypeSelect.val() === 'hosted') {
      $embeddedBlock.hide();
      $hostedBlock.show();
    } else {
      $embeddedBlock.show();
      $hostedBlock.hide();
    }
  }

  // -------------------------------------------------------------------------
  // Validate Credentials AJAX
  // -------------------------------------------------------------------------

  const $validateWrap = $('.payoneer-validate-credentials');
  const $validateBtn = $('#payoneer-validate-credentials-btn');
  const $spinner = $('#payoneer-validate-spinner');
  const $message = $('#payoneer-validate-message');
  const $liveModeCheckbox = $('#woocommerce_payoneer-checkout_live_mode');
  const $validationSections = $('.payoneer-settings-section--requires-validation');
  const $loggingCheckbox = $('#woocommerce_payoneer-checkout_logging');
  const $logsExportSection = $('.payoneer-settings-section--requires-logging');
  let debounceTimer;
  const validated = {
    test: !!params.is_test_validated,
    live: !!params.is_live_validated
  };
  function currentMode() {
    return $liveModeCheckbox.is(':checked') ? 'live' : 'test';
  }
  function syncLogsExport() {
    $logsExportSection.toggle(validated[currentMode()] && $loggingCheckbox.is(':checked'));
  }
  function showInvalidatedState() {
    $validateWrap.show();
    $validateBtn.show();
    $validateBtn.prop('disabled', false);
    $message.text('').removeClass('notice-error notice-success');
    $validationSections.hide();
    syncLogsExport();
  }
  function showValidatedState() {
    $validateWrap.hide();
    $message.text('').removeClass('notice-error notice-success');
    $validationSections.show();
    syncLogsExport();
  }
  function syncUI() {
    if (validated[currentMode()]) {
      showValidatedState();
    } else {
      showInvalidatedState();
    }
  }
  $loggingCheckbox.on('change', syncLogsExport);
  $('#woocommerce_payoneer-checkout_merchant_code, #woocommerce_payoneer-checkout_merchant_token').on('change', function () {
    validated.live = false;
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(syncUI, 250);
  });
  $('#woocommerce_payoneer-checkout_sandbox_merchant_code, #woocommerce_payoneer-checkout_sandbox_merchant_token').on('change', function () {
    validated.test = false;
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(syncUI, 250);
  });
  $liveModeCheckbox.on('change', syncUI);
  $validateBtn.on('click', function () {
    const isLiveMode = $liveModeCheckbox.is(':checked');
    const codeField = isLiveMode ? 'merchant_code' : 'sandbox_merchant_code';
    const tokenField = isLiveMode ? 'merchant_token' : 'sandbox_merchant_token';
    const username = $(`#woocommerce_payoneer-checkout_${codeField}`).val();
    const token = $(`#woocommerce_payoneer-checkout_${tokenField}`).val();
    if (!username || !token) {
      showValidationMessage(__('Please enter your credentials.', 'payoneer-checkout'), 'error');
      return;
    }
    $validateBtn.prop('disabled', true);
    $spinner.addClass('is-active');
    $message.text('').removeClass('notice-error notice-success');
    $.post(params.ajax_url, {
      action: 'payoneer_checkout_validate_credentials',
      nonce: params.validate_nonce,
      live_mode: isLiveMode ? 'yes' : 'no',
      merchant_code: username,
      merchant_token: token
    }).done(function (response) {
      if (response.success && response.data) {
        onValidationSuccess(response.data, !isLiveMode);
        $validateBtn.prop('disabled', true);
      } else {
        showValidationMessage(response.data?.message || __('Invalid credentials. Please check your Merchant Code and Payment Code.', 'payoneer-checkout'), 'error');
        $validateBtn.prop('disabled', false);
      }
    }).fail(function () {
      showValidationMessage(__('Invalid credentials. Please check your Merchant Code and Payment Code.', 'payoneer-checkout'), 'error');
      $validateBtn.prop('disabled', false);
    }).always(function () {
      $spinner.removeClass('is-active');
    });
  });
  function onValidationSuccess(data, isTestMode) {
    const divisions = data.divisions || [];
    networksByDivision = data.networks_by_division || {};
    if (!divisions.length) {
      showValidationMessage(__('No stores found for these credentials.', 'payoneer-checkout'), 'error');
      return;
    }
    const storeKey = isTestMode ? 'sandbox_store_code' : 'store_code';
    const $divisionSelect = $(`#woocommerce_payoneer-checkout_${storeKey}`);
    if ($divisionSelect.length) {
      const currentVal = $divisionSelect.val();
      $divisionSelect.empty();
      if (divisions.length > 1) {
        $divisionSelect.append($('<option>', {
          value: '',
          text: __('Select Store Code', 'payoneer-checkout')
        }));
      }
      divisions.forEach(function (div) {
        const label = div.displayName ? div.displayName + ' (' + div.name + ')' : div.name;
        $divisionSelect.append($('<option>', {
          value: div.name,
          text: label
        }));
      });
      if (divisions.length === 1) {
        $divisionSelect.val(divisions[0].name);
      } else if (currentVal) {
        $divisionSelect.val(currentVal);
      }
      $divisionSelect.trigger('change');
    }
    validated[isTestMode ? 'test' : 'live'] = true;
    $validationSections.show();
    syncLogsExport();
    showValidationMessage(__('Credentials validated. Save your settings to apply changes.', 'payoneer-checkout'), 'success');
  }
  function showValidationMessage(text, type) {
    $message.text(text).removeClass('notice-error notice-success').addClass(type === 'error' ? 'notice-error' : 'notice-success');
  }

  // -------------------------------------------------------------------------
  // Division change → populate networks
  // -------------------------------------------------------------------------

  const networkStateCache = {
    test: {},
    live: {}
  };
  const networkEnabledState = {
    test: {},
    live: {}
  };
  const persistedDisabledNetworks = {
    test: params.disabled_networks && params.disabled_networks.test || [],
    live: params.disabled_networks && params.disabled_networks.live || []
  };
  const currentDivision = {
    test: $('#woocommerce_payoneer-checkout_sandbox_store_code').val() || '',
    live: $('#woocommerce_payoneer-checkout_store_code').val() || ''
  };
  snapshotRenderedRows(true);
  snapshotRenderedRows(false);
  $(document).on('change', '#woocommerce_payoneer-checkout_store_code, #woocommerce_payoneer-checkout_sandbox_store_code', function () {
    const divisionCode = $(this).val();
    const isTest = this.id === 'woocommerce_payoneer-checkout_sandbox_store_code';
    populateNetworks(divisionCode, isTest);
  });
  function getNetworkWrapper(isTest) {
    return $('.payoneer-payment-methods--embedded .payoneer-network-list[data-show-if="' + (isTest ? 'no' : 'yes') + '"]');
  }
  function orderNetworks(list) {
    const order = params.network_order || [];
    const pos = code => {
      const i = order.indexOf(code);
      return i === -1 ? Number.MAX_SAFE_INTEGER : i;
    };
    return (list || []).slice().sort((a, b) => pos(a.code) - pos(b.code));
  }
  function snapshotRenderedRows(isTest) {
    const mode = isTest ? 'test' : 'live';
    const division = currentDivision[mode];
    if (!division) {
      return;
    }
    const $wrapper = getNetworkWrapper(isTest);
    const state = {};
    $wrapper.find('tr.payoneer-payment-method--network').each(function () {
      const code = $(this).data('network-code');
      if (!code) {
        return;
      }
      const $toggle = $(this).find('.payoneer-toggle__input');
      if (!$toggle.length) {
        return;
      }
      if (!state[code]) {
        state[code] = {};
      }
      state[code].checked = $toggle.is(':checked');
      networkEnabledState[mode][code] = state[code].checked;
    });
    $wrapper.find('tr.payoneer-payment-method__details.payoneer-payment-method--network').each(function () {
      const code = $(this).data('network-code');
      if (!code) {
        return;
      }
      if (!state[code]) {
        state[code] = {};
      }
      state[code].title = $(this).find('input[data-field="title"]').val();
      state[code].description = $(this).find('input[data-field="description"]').val();
    });
    networkStateCache[mode][division] = state;
  }
  function populateNetworks(divisionCode, isTest) {
    const mode = isTest ? 'test' : 'live';
    const prefix = isTest ? 'test_' : '';
    const $wrapper = getNetworkWrapper(isTest);
    const template = document.getElementById('payoneer-network-row-template');
    snapshotRenderedRows(isTest);
    currentDivision[mode] = divisionCode || '';
    $wrapper.find('.payoneer-payment-method--network').remove();
    if (!divisionCode || !networksByDivision[divisionCode] || !template) {
      return;
    }
    const networks = orderNetworks(networksByDivision[divisionCode]);
    const saved = networkStateCache[mode][divisionCode] || {};
    networks.forEach(function (network) {
      const prior = saved[network.code] || {};
      const globalChecked = networkEnabledState[mode][network.code];
      let isChecked;
      if (globalChecked !== undefined) {
        isChecked = globalChecked;
      } else if (prior.checked !== undefined) {
        isChecked = prior.checked;
      } else {
        isChecked = persistedDisabledNetworks[mode].indexOf(network.code) === -1;
      }
      const title = prior.title !== undefined ? prior.title : network.label || network.code;
      const description = prior.description !== undefined ? prior.description : '';
      const fragment = template.content.cloneNode(true);
      const $rows = $(fragment).children('tr');
      const $mainRow = $rows.eq(0);
      $mainRow.attr('data-network-code', network.code);
      $mainRow.find('.payoneer-payment-method__label').text(network.label || network.code);
      $mainRow.find('.payoneer-payment-method__logo').attr('src', network.logo || '').attr('alt', network.label || network.code);
      $mainRow.find('.payoneer-toggle__input').attr('name', 'payoneer_' + prefix + 'enabled_networks[]').val(network.code).prop('checked', isChecked);
      $mainRow.find('.screen-reader-text').text('Enable ' + (network.label || network.code));
      $mainRow.find('.payoneer-payment-method__toggle-details .screen-reader-text').text('Toggle details for ' + (network.label || network.code));
      const $detailsRow = $rows.eq(1);
      $detailsRow.attr('data-network-code', network.code);
      $detailsRow.find('input[data-field="title"]').attr('name', 'payoneer_' + prefix + 'network_settings[' + network.code + '][title]').val(title);
      $detailsRow.find('input[data-field="description"]').attr('name', 'payoneer_' + prefix + 'network_settings[' + network.code + '][description]').val(description);
      $wrapper.append($mainRow, $detailsRow);
    });
  }

  // -------------------------------------------------------------------------
  // Logs export.
  // -------------------------------------------------------------------------

  const $exportNoticeRow = $('.payoneer-logs-export__notice-row');
  const $exportNotice = $('.payoneer-logs-export__notice');
  function showExportNotice(message, level) {
    $exportNotice.removeClass('notice-error notice-warning notice-success').addClass('notice-' + level).html('<p></p>').find('p').text(message);
    $exportNoticeRow.show();
  }
  function hideExportNotice() {
    $exportNotice.empty();
    $exportNoticeRow.hide();
  }
  function syncExportPanels() {
    const value = $('#payoneer-logs-export-type').val();
    $('.payoneer-logs-export__panel').each(function () {
      $(this).toggle($(this).data('panel') === value);
    });
  }
  $('#payoneer-logs-export-type').on('change', function () {
    syncExportPanels();
    hideExportNotice();
  });
  $(window).on('pageshow', syncExportPanels);
  const $exportTable = $('[data-payoneer-logs-export]');
  const noticeCode = $exportTable.data('notice-code') || '';
  const noticeDate = $exportTable.data('notice-date') || '';
  if (noticeCode === 'no_logs' && noticeDate) {
    $('#payoneer-logs-export-type').val('date').trigger('change');
    $('#payoneer-logs-export-date').val(noticeDate);
    showExportNotice(sprintf(
    // translators: %s: selected date.
    __('No logs found for %s.', 'payoneer-checkout'), noticeDate), 'warning');
    $exportNotice.get(0)?.scrollIntoView({
      behavior: 'smooth',
      block: 'center'
    });
  }
  if ($.fn.datepicker) {
    $('#payoneer-logs-export-date').datepicker({
      dateFormat: 'yy-mm-dd',
      maxDate: 0,
      changeMonth: true,
      changeYear: true,
      showButtonPanel: true
    });
  }
  $('#payoneer-logs-export-status-btn').on('click', function (e) {
    e.preventDefault();
    const $form = $('#payoneer-logs-export-status-form');
    const $host = $('#payoneer-logs-export-status-host');
    const $btn = $(this);
    hideExportNotice();
    $btn.prop('disabled', true);
    $.ajax({
      url: $form.data('ajax-url'),
      method: 'POST',
      data: {
        action: $form.data('ajax-action'),
        _wpnonce: $form.data('nonce')
      }
    }).done(function (resp) {
      if (!resp?.success || !resp?.data?.html) {
        const message = resp?.data?.message || __('Export failed.', 'payoneer-checkout');
        showExportNotice(message, 'error');
        return;
      }
      $host.empty().html(resp.data.html);
      $host.find('button').attr('type', 'button');
      const $generate = $host.find('.debug-report').first();
      const $download = $host.find('#download-for-support').first();
      if (!$generate.length || !$download.length) {
        showExportNotice(__('Could not generate the status report.', 'payoneer-checkout'), 'error');
        $host.empty();
        return;
      }
      $generate.trigger('click');
      $download.trigger('click');
      $host.empty();
    }).fail(function () {
      showExportNotice(__('Network error. Please try again.', 'payoneer-checkout'), 'error');
    }).always(function () {
      $btn.prop('disabled', false);
    });
  });
  $('#payoneer-logs-export-date-btn').on('click', function (e) {
    e.preventDefault();
    const $form = $('#payoneer-logs-export-date-form');
    const date = $form.find('[name="date"]').val();
    hideExportNotice();
    if (!date) {
      showExportNotice(__('Please select a date.', 'payoneer-checkout'), 'warning');
      return;
    }
    const query = $.param({
      action: $form.data('action'),
      type: 'date',
      date,
      _wpnonce: $form.data('nonce')
    });
    window.location.href = $form.data('action-url') + '?' + query;
  });
})(jQuery);
/******/ })()
;