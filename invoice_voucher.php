<?php
/* Copyright (C) 2026 Fred Omega Junior */

declare(strict_types=1);

$res = 0;
if (!$res && file_exists('../main.inc.php')) $res = include '../main.inc.php';
if (!$res && file_exists('../../main.inc.php')) $res = include '../../main.inc.php';
if (!$res) die('Include of main fails');

require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
require_once __DIR__.'/class/dolivoucherinvoicesettlementservice.class.php';

$langs->loadLangs(array('bills', 'payments', 'dolivoucher@dolivoucher'));
$invoiceId = GETPOSTINT('facid');
$action = GETPOST('action', 'aZ09');
$confirm = GETPOST('confirm', 'alpha');
$entity = (int) $conf->entity;
$invoice = new Facture($db);
if ($invoiceId <= 0 || $invoice->fetch($invoiceId) <= 0 || (int) $invoice->entity !== $entity) accessforbidden($langs->trans('ErrorRecordNotFound'));
restrictedArea($user, 'facture', $invoiceId, 'facture');

$canUse = !empty($user->admin) || ($user->hasRight('dolivoucher', 'settlement', 'use') && $user->hasRight('facture', 'paiement'));
$canReverse = !empty($user->admin) || ($user->hasRight('dolivoucher', 'settlement', 'reverse') && $user->hasRight('facture', 'paiement'));
if (!$canUse && !$canReverse) accessforbidden();

$form = new Form($db);
$service = new DoliVoucherInvoiceSettlementService($db);
$voucher = null;

/* Actions */
if ($action === 'lookup' && $canUse) {
	$voucher = $service->findVoucher(GETPOST('voucher_identifier', 'alphanohtml'), $entity);
	if (!$voucher) setEventMessages($langs->trans('ErrorVoucherIdentifierNotFound'), null, 'errors');
}

if ($action === 'confirm_apply' && $confirm === 'yes' && $canUse) {
	$result = $service->apply($invoiceId, GETPOSTINT('voucher_id'), $entity, GETPOST('amount', 'alphanohtml'), GETPOST('idempotency_key', 'alphanohtml'), $user, GETPOST('reason', 'restricthtml'), GETPOST('external_ref', 'alphanohtml'));
	if ($result > 0) {
		setEventMessages($langs->trans('VoucherSettlementCreated'), null, 'mesgs');
		header('Location: '.DOL_URL_ROOT.'/compta/facture/card.php?facid='.$invoiceId);
		exit;
	}
}

if ($action === 'confirm_reverse' && $confirm === 'yes' && $canReverse) {
	$result = $service->reverse(GETPOSTINT('settlement_id'), $entity, GETPOST('idempotency_key', 'alphanohtml'), $user, GETPOST('reason', 'restricthtml'), GETPOST('external_ref', 'alphanohtml'));
	if ($result > 0) {
		setEventMessages($langs->trans('VoucherSettlementReversed'), null, 'mesgs');
		header('Location: '.$_SERVER['PHP_SELF'].'?facid='.$invoiceId);
		exit;
	}
}

/* Views */
llxHeader('', $langs->trans('UseVoucherOnInvoice'));
$linkback = '<a href="'.DOL_URL_ROOT.'/compta/facture/card.php?facid='.$invoiceId.'">'.$langs->trans('BackToInvoice').'</a>';
dol_banner_tab($invoice, 'ref', $linkback, 1, 'ref', 'ref');

if ($action === 'ask_apply' && $canUse) {
	$questions = array(
		array('type' => 'hidden', 'name' => 'facid', 'value' => $invoiceId),
		array('type' => 'hidden', 'name' => 'voucher_id', 'value' => GETPOSTINT('voucher_id')),
		array('type' => 'hidden', 'name' => 'amount', 'value' => GETPOST('amount', 'alphanohtml')),
		array('type' => 'hidden', 'name' => 'idempotency_key', 'value' => GETPOST('idempotency_key', 'alphanohtml')),
		array('type' => 'hidden', 'name' => 'reason', 'value' => GETPOST('reason', 'restricthtml')),
		array('type' => 'hidden', 'name' => 'external_ref', 'value' => GETPOST('external_ref', 'alphanohtml')),
	);
	print $form->formconfirm($_SERVER['PHP_SELF'].'?facid='.$invoiceId, $langs->trans('ConfirmVoucherSettlement'), $langs->trans('ConfirmVoucherSettlementQuestion'), 'confirm_apply', $questions, 0, 1);
}
if ($action === 'ask_reverse' && $canReverse) {
	$questions = array(
		array('type' => 'hidden', 'name' => 'facid', 'value' => $invoiceId),
		array('type' => 'hidden', 'name' => 'settlement_id', 'value' => GETPOSTINT('settlement_id')),
		array('type' => 'hidden', 'name' => 'idempotency_key', 'value' => GETPOST('idempotency_key', 'alphanohtml')),
		array('type' => 'hidden', 'name' => 'reason', 'value' => GETPOST('reason', 'restricthtml')),
		array('type' => 'hidden', 'name' => 'external_ref', 'value' => GETPOST('external_ref', 'alphanohtml')),
	);
	print $form->formconfirm($_SERVER['PHP_SELF'].'?facid='.$invoiceId, $langs->trans('ReverseVoucherSettlement'), $langs->trans('ConfirmVoucherSettlementReversal'), 'confirm_reverse', $questions, 0, 1);
}

$invoice->fetch($invoiceId);
$rawRemain = (string) $invoice->getRemainToPay();
try {
	$remain = DoliVoucherMoney::normalize($rawRemain);
} catch (InvalidArgumentException $e) {
	$remain = '0.00000000';
}
$invoiceCurrency = empty($invoice->multicurrency_code) ? (string) $conf->currency : (string) $invoice->multicurrency_code;
$eligibleInvoice = (int) $invoice->status === Facture::STATUS_VALIDATED
	&& in_array((int) $invoice->type, array(Facture::TYPE_STANDARD, Facture::TYPE_REPLACEMENT), true)
	&& $invoiceCurrency === (string) $conf->currency
	&& DoliVoucherMoney::compare($remain, '0.00000000') > 0;
print '<div class="fichecenter"><table class="border centpercent">';
print '<tr><td class="titlefield">'.$langs->trans('RemainToPay').'</td><td>'.price($rawRemain, 0, $langs, 1, -1, -1, $conf->currency).'</td></tr>';
print '<tr><td>'.$langs->trans('Currency').'</td><td>'.dol_escape_htmltag($conf->currency).'</td></tr></table></div>';

if ($canUse && $eligibleInvoice) {
	print '<form method="POST" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'">';
	print '<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="lookup"><input type="hidden" name="facid" value="'.$invoiceId.'">';
	print '<table class="border centpercent"><tr><td class="titlefield fieldrequired">'.$langs->trans('VoucherSerialOrBarcode').'</td><td><input class="minwidth300" name="voucher_identifier" autofocus required></td>';
	print '<td class="right"><input type="submit" class="button button-save" value="'.$langs->trans('Search').'"></td></tr></table></form>';
}

if ($voucher) {
	$balance = DoliVoucherMoney::normalize((string) $voucher->current_balance);
	$maximum = DoliVoucherMoney::compare($balance, $remain) <= 0 ? $balance : $remain;
	$usable = in_array((int) $voucher->status, array(DoliVoucherVoucher::STATUS_ACTIVE, DoliVoucherVoucher::STATUS_PARTIALLY_CONSUMED), true) && (empty($voucher->date_expiration) || $db->jdate($voucher->date_expiration) >= dol_now());
	print load_fiche_titre($langs->trans('VoucherFound'), '', 'ticket');
	print '<table class="border centpercent"><tr><td class="titlefield">'.$langs->trans('Voucher').'</td><td><a href="voucher_card.php?id='.(int) $voucher->rowid.'">'.dol_escape_htmltag($voucher->ref).'</a></td></tr>';
	print '<tr><td>'.$langs->trans('CurrentBalance').'</td><td>'.price($balance, 0, $langs, 1, -1, -1, $conf->currency).'</td></tr><tr><td>'.$langs->trans('MaximumUsableAmount').'</td><td>'.price($maximum, 0, $langs, 1, -1, -1, $conf->currency).'</td></tr></table>';
	if ($usable && DoliVoucherMoney::compare($maximum, '0.00000000') > 0) {
		print '<form method="POST" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'"><input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="ask_apply"><input type="hidden" name="facid" value="'.$invoiceId.'"><input type="hidden" name="voucher_id" value="'.(int) $voucher->rowid.'"><input type="hidden" name="idempotency_key" value="'.dol_escape_htmltag(DoliVoucherService::uuid()).'">';
		print '<table class="border centpercent"><tr><td class="titlefield fieldrequired">'.$langs->trans('Amount').'</td><td><input name="amount" value="'.dol_escape_htmltag($maximum).'" required> '.$conf->currency.'</td></tr><tr><td>'.$langs->trans('Reason').'</td><td><input class="minwidth300" name="reason"></td></tr><tr><td>'.$langs->trans('ExternalRef').'</td><td><input class="minwidth300" name="external_ref"></td></tr></table>';
		print '<div class="center"><input type="submit" class="button button-save" value="'.$langs->trans('UseVoucherOnInvoice').'"></div></form>';
	} else {
		setEventMessages($langs->trans('ErrorVoucherNotConsumable'), null, 'warnings');
	}
}

print '<br>'.load_fiche_titre($langs->trans('DoliVoucherSettlements'), '', 'list');
$sql = 'SELECT s.*, v.ref AS voucher_ref, o.operation_uuid, r.rowid AS reversal_id FROM '.$db->prefix().'dolivoucher_invoice_settlement s';
$sql .= ' INNER JOIN '.$db->prefix().'dolivoucher_voucher v ON v.rowid=s.fk_voucher AND v.entity=s.entity';
$sql .= ' INNER JOIN '.$db->prefix().'dolivoucher_operation o ON o.rowid=s.fk_operation AND o.entity=s.entity';
$sql .= ' LEFT JOIN '.$db->prefix().'dolivoucher_invoice_settlement r ON r.reversal_of=s.rowid';
$sql .= ' WHERE s.entity='.$entity.' AND s.fk_facture='.$invoiceId.' ORDER BY s.date_creation DESC, s.rowid DESC';
$resql = $db->query($sql);
print '<div class="div-table-responsive"><table class="tagtable liste centpercent"><tr class="liste_titre"><th>'.$langs->trans('Date').'</th><th>'.$langs->trans('Event').'</th><th>'.$langs->trans('SettlementUuid').'</th><th>'.$langs->trans('Operation').'</th><th>'.$langs->trans('Voucher').'</th><th>'.$langs->trans('Payment').'</th><th class="right">'.$langs->trans('Amount').'</th><th></th></tr>';
$found = false;
while ($resql && ($row = $db->fetch_object($resql))) {
	$found = true;
	$operationLink = '<a href="operation_list.php?search_uuid='.urlencode($row->operation_uuid).'">'.dol_escape_htmltag($row->operation_uuid).'</a>';
	print '<tr class="oddeven"><td>'.dol_print_date($db->jdate($row->date_creation), 'dayhour').'</td><td>'.$langs->trans('Settlement'.$row->event_type).'</td><td>'.dol_escape_htmltag($row->settlement_uuid).'</td><td>'.$operationLink.'</td><td><a href="voucher_card.php?id='.(int) $row->fk_voucher.'">'.dol_escape_htmltag($row->voucher_ref).'</a></td>';
	print '<td><a href="'.DOL_URL_ROOT.'/compta/paiement/card.php?id='.(int) $row->fk_paiement.'">'.dol_escape_htmltag($row->payment_ref_snapshot).'</a></td><td class="right">'.price($row->amount, 0, $langs, 1, -1, -1, $conf->currency).'</td><td class="right">';
	if ($row->event_type === DoliVoucherInvoiceSettlement::EVENT_APPLY && empty($row->reversal_id) && $canReverse) {
		print '<form class="inline-block" method="POST"><input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="ask_reverse"><input type="hidden" name="facid" value="'.$invoiceId.'"><input type="hidden" name="settlement_id" value="'.(int) $row->rowid.'"><input type="hidden" name="idempotency_key" value="'.dol_escape_htmltag(DoliVoucherService::uuid()).'"><input class="minwidth200" name="reason" required placeholder="'.$langs->trans('Reason').'"><input type="submit" class="button button-delete" value="'.$langs->trans('ReverseVoucherSettlement').'"></form>';
	}
	print '</td></tr>';
}
if (!$found) print '<tr class="oddeven"><td colspan="8" class="opacitymedium">'.$langs->trans('None').'</td></tr>';
print '</table></div>';
llxFooter();
