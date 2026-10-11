<?php
if (!defined('NOREQUIREMENU')) define('NOREQUIREMENU', '1');
if (!defined('NOREQUIREHTML')) define('NOREQUIREHTML', '1');
require '../../../main.inc.php';
header('Content-Type: application/javascript; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
$langs->load('dolivoucher@dolivoucher');
?>
(function ($) {
	'use strict';
	var busy = false;
	var inspectedIdentifier = '';
	function panel() { return $('#dolivoucher-takepos-panel').last(); }
	function field(selector) { return panel().find(selector); }
	function storageKey() { return 'dolivoucher_takepos_intent_' + field('#dolivoucher-takepos-invoice').val(); }
	function setBusy(state) {
		busy = state;
		field('#dolivoucher-takepos-submit').toggleClass('butAction', !state).toggleClass('butActionRefused', state).attr('aria-disabled', state ? 'true' : 'false');
	}
	function resetPanel() {
		inspectedIdentifier = '';
		var form = field('#dolivoucher-takepos-form')[0];
		if (form) form.reset();
		field('#dolivoucher-takepos-details, #dolivoucher-takepos-message').empty();
	}
	function request(action, data) {
		data.action = action;
		data.token = '<?php echo dol_escape_js(currentToken()); ?>';
		return $.ajax({url: '<?php echo dol_escape_js(dol_buildpath('/dolivoucher/ajax/takepos.php', 1)); ?>', method: 'POST', dataType: 'json', data: data});
	}
	function message(text, error) { field('#dolivoucher-takepos-message').attr('class', error ? 'error' : 'ok').text(text); }
	window.DoliVoucherTakePosOpen = function () {
		var currentInvoice = parseInt(parent.$('#invoiceid').val(), 10);
		if (currentInvoice > 0) field('#dolivoucher-takepos-invoice').val(currentInvoice);
		panel().show();
		field('#dolivoucher-takepos-identifier').trigger('focus');
	};
	$(function () {
		var openButton = $('#dolivoucher-takepos-open').last();
		var container = openButton.parent();
		if (!openButton.length || container.children('.dolivoucher-takepos-keypad-layout').length) return;
		var paymentButtons = container.find('button.calcbutton2').filter(function () {
			var handler = String($(this).attr('onclick') || '');
			return this.id === 'dolivoucher-takepos-open' || handler.indexOf('Validate(') >= 0 || handler.indexOf('ValidateSumup') >= 0 || handler.indexOf('ValidateStripe') >= 0;
		});
		var allButtons = container.children('button');
		var keypadButtons = allButtons.not(paymentButtons);
		var nativeButtonHeight = container.find('button.calcbutton').first().outerHeight();
		var nativePaymentHeight = paymentButtons.first().outerHeight();
		var layout = $('<div class="dolivoucher-takepos-keypad-layout"></div>').css({display: 'flex', width: '100%', alignItems: 'flex-start'});
		var keypad = $('<div class="dolivoucher-takepos-keypad-actions"></div>').css({display: 'flex', flexWrap: 'wrap', flex: '0 0 75%', maxWidth: '75%'});
		var payments = $('<div class="dolivoucher-takepos-payment-modes"></div>').css({display: 'flex', flexDirection: 'column', flex: '0 0 25%', maxWidth: '25%'});
		keypadButtons.detach().css({float: 'none', width: 'calc(33.333333% - 2px)', height: nativeButtonHeight + 'px', boxSizing: 'border-box'}).appendTo(keypad);
		paymentButtons.detach().css({float: 'none', width: 'calc(100% - 2px)', height: nativePaymentHeight + 'px', boxSizing: 'border-box'}).appendTo(payments);
		layout.append(keypad).append(payments);
		container.prepend(layout);
	});
	$(document).off('.dolivoucherTakePos');
	$(document).on('click.dolivoucherTakePos', '#dolivoucher-takepos-submit', function (event) {
		event.preventDefault();
		if (!busy) field('#dolivoucher-takepos-form').trigger('submit');
	});
	$(document).on('click.dolivoucherTakePos', '#dolivoucher-takepos-cancel', function (event) {
		event.preventDefault();
		if (busy) return;
		var uuid = window.sessionStorage.getItem(storageKey());
		if (!uuid) { resetPanel(); panel().hide(); return; }
		request('cancel', {intent_uuid: uuid}).always(function () {
			window.sessionStorage.removeItem(storageKey());
			resetPanel();
			panel().hide();
		});
	});
	$(document).on('submit.dolivoucherTakePos', '#dolivoucher-takepos-form', function (event) {
		event.preventDefault(); if (busy) return;
		setBusy(true); message('<?php echo dol_escape_js($langs->transnoentities('TakePosVoucherPreparing')); ?>', false);
		var identifier = field('#dolivoucher-takepos-identifier').val();
		if (inspectedIdentifier !== identifier) {
			request('search', {invoice_id: field('#dolivoucher-takepos-invoice').val(), identifier: identifier}).then(function (result) {
				if (!result.success) throw new Error(result.error);
				inspectedIdentifier = identifier;
				field('#dolivoucher-takepos-amount').val(result.data.maximum);
				field('#dolivoucher-takepos-details').text(result.data.voucher_ref + ' — <?php echo dol_escape_js($langs->transnoentities('Status')); ?>: ' + result.data.voucher_status + ' — <?php echo dol_escape_js($langs->transnoentities('ExpirationDate')); ?>: ' + (result.data.voucher_expiration || '-') + ' — <?php echo dol_escape_js($langs->transnoentities('Balance')); ?>: ' + result.data.voucher_balance + ' — <?php echo dol_escape_js($langs->transnoentities('RemainToPay')); ?>: ' + result.data.invoice_remainder);
				message('<?php echo dol_escape_js($langs->transnoentities('TakePosVoucherConfirmAfterCheck')); ?>', false);
			}).fail(function (error) { message(error.responseJSON ? error.responseJSON.error : error.message, true); }).always(function () { setBusy(false); });
			return;
		}
		var key = (window.crypto && crypto.randomUUID) ? crypto.randomUUID() : String(Date.now()) + '-dv-' + Math.random().toString(16).slice(2);
		var data = {invoice_id: field('#dolivoucher-takepos-invoice').val(), identifier: identifier, amount: field('#dolivoucher-takepos-amount').val(), place: field('#dolivoucher-takepos-place').val(), idempotency_key: key};
		request('prepare', data).then(function (prepared) {
			if (!prepared.success) throw new Error(prepared.error);
			window.sessionStorage.setItem(storageKey(), prepared.data.intent_uuid);
			return request('authorize', {intent_uuid: prepared.data.intent_uuid}).then(function (authorized) {
				if (!authorized.success) throw new Error(authorized.error);
				if (!authorized.data.native_validation_required) return authorized;
				message('<?php echo dol_escape_js($langs->transnoentities('TakePosVoucherValidatingSale')); ?>', false);
				return new Promise(function (resolve, reject) {
					parent.$('#poslines').load('<?php echo dol_escape_js(DOL_URL_ROOT.'/takepos/invoice.php'); ?>?place=' + encodeURIComponent(authorized.data.place) + '&action=valid&token=<?php echo dol_escape_js(newToken()); ?>&pay=delayed&amount=0&invoiceid=' + encodeURIComponent(authorized.data.invoice_id), function (response, status) {
						if (status === 'error') { reject(new Error('NATIVE_VALIDATION_FAILED')); return; }
						resolve(authorized);
					});
				});
			});
		}).then(function (authorized) {
			return request('observe', {intent_uuid: authorized.data.intent_uuid});
		}).then(function (observed) {
			if (!observed.success) throw new Error(observed.error);
			message('<?php echo dol_escape_js($langs->transnoentities('TakePosVoucherApplying')); ?>', false);
			return request('settle', {intent_uuid: observed.data.intent_uuid});
		}).then(function (settled) {
			if (!settled.success) throw new Error(settled.error);
			window.sessionStorage.removeItem(storageKey());
			message('<?php echo dol_escape_js($langs->transnoentities('TakePosVoucherSuccess')); ?>', false);
			setTimeout(function () {
				panel().hide();
				var refreshUrl = '<?php echo dol_escape_js(DOL_URL_ROOT.'/takepos/invoice.php'); ?>?action=history&place=' + encodeURIComponent(settled.data.place) + '&placeid=' + encodeURIComponent(settled.data.invoice_id) + '&invoiceid=' + encodeURIComponent(settled.data.invoice_id);
				parent.$('#poslines').load(refreshUrl, function (response, status) {
					if (status === 'error') { message('<?php echo dol_escape_js($langs->transnoentities('ErrorTakePosRefreshFailed')); ?>', true); return; }
					if (settled.data.invoice_paid) {
						parent.$('#invoiceid').val('');
						parent.$.colorbox.close();
					} else {
						location.reload();
					}
				});
			}, 500);
		}).fail(function (error) {
			message(error.responseJSON ? error.responseJSON.error : error.message, true);
		}).always(function () { setBusy(false); });
	});
	$(document).on('input.dolivoucherTakePos', '#dolivoucher-takepos-identifier', function () { inspectedIdentifier = ''; field('#dolivoucher-takepos-details').empty(); });
})(jQuery);
