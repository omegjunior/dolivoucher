<?php
/* Copyright (C) 2026 Fred Omega Junior */

declare(strict_types=1);

$res = @include '../../main.inc.php';
if (!$res) die('Include of main fails');
require_once __DIR__.'/class/dolivouchersettlementdiagnosticservice.class.php';
require_once __DIR__.'/lib/dolivoucher_settlement_diagnostic.lib.php';

$langs->loadLangs(array('bills', 'payments', 'dolivoucher@dolivoucher'));
if (!isModEnabled('dolivoucher') || !$user->hasRight('dolivoucher', 'audit', 'read') || $user->socid > 0) accessforbidden();
$entity = (int) $conf->entity;
$id = GETPOSTINT('id');
$canDiagnose = !empty($user->admin) || $user->hasRight('dolivoucher', 'settlement', 'diagnose');
$service = new DoliVoucherSettlementDiagnosticService($db);
$item = $service->fetchOne($id, $entity);
if (!$item) accessforbidden($langs->trans('ErrorRecordNotFound'));
$row = $item['record'];
$diagnostic = $item['diagnostic'];
$reversalReason = (string) ($row->event_type === 'REVERSAL' ? $row->operation_reason : ($row->linked_reversal_reason ?? ''));

/* Views */
llxHeader('', $langs->trans('SettlementCard'));
$linkback = '<a href="settlement_list.php?restore_lastsearch_values=1">'.$langs->trans('BackToList').'</a>';
print load_fiche_titre($langs->trans('SettlementCard').' '.dol_escape_htmltag($row->settlement_uuid), $linkback, 'payment');
print '<div class="fichecenter"><div class="fichehalfleft"><table class="border centpercent">';
print '<tr><td class="titlefield">'.$langs->trans('SettlementUuid').'</td><td>'.dol_escape_htmltag($row->settlement_uuid).'</td></tr>';
print '<tr><td>'.$langs->trans('Date').'</td><td>'.dol_print_date($db->jdate($row->date_creation), 'dayhour').'</td></tr>';
print '<tr><td>'.$langs->trans('Event').'</td><td>'.$langs->trans('Settlement'.$row->event_type).'</td></tr>';
print '<tr><td>'.$langs->trans('SettlementState').'</td><td>'.$langs->trans('SettlementState'.dolivoucherSettlementState($row)).'</td></tr>';
print '<tr><td>'.$langs->trans('RequestSource').'</td><td>'.dol_escape_htmltag($row->request_source).'</td></tr>';
if ($canDiagnose) print '<tr><td>'.$langs->trans('IdempotencyKey').'</td><td class="wordbreak">'.dol_escape_htmltag($row->idempotency_key).'</td></tr>';
print '<tr><td>'.$langs->trans('Amount').'</td><td>'.price($row->amount, 0, $langs, 1, -1, -1, $conf->currency).'</td></tr>';
print '<tr><td>'.$langs->trans('User').'</td><td>'.dol_escape_htmltag(trim((string) $row->user_firstname.' '.(string) $row->user_lastname).' ('.(string) $row->user_login.')').'</td></tr>';
print '<tr><td>'.$langs->trans('ExternalRef').'</td><td>'.dol_escape_htmltag((string) $row->external_ref).'</td></tr>';
if ($reversalReason !== '') print '<tr><td>'.$langs->trans('ReversalReason').'</td><td title="'.dol_escape_htmltag($reversalReason).'">'.dol_trunc(dol_escape_htmltag($reversalReason), 160).'</td></tr>';
print '</table></div><div class="fichehalfright"><table class="border centpercent">';
print '<tr><td class="titlefield">'.$langs->trans('Voucher').'</td><td>'.dolivoucherDiagnosticObjectDisplay($row->voucher_ref, (int) $row->fk_voucher, (int) $row->voucher_entity, $entity, 'voucher_card.php?id='.(int) $row->fk_voucher).'</td><td>'.(int) $row->voucher_entity.'</td></tr>';
print '<tr><td>'.$langs->trans('Operation').'</td><td>'.(!empty($row->operation_exists) && (int) $row->operation_entity === $entity ? '<a href="operation_list.php?search_uuid='.urlencode($row->operation_uuid).'">'.dol_escape_htmltag($row->operation_uuid).'</a>' : '#'.(int) $row->fk_operation).'</td><td>'.(int) $row->operation_entity.'</td></tr>';
print '<tr><td>'.$langs->trans('Invoice').'</td><td>'.dolivoucherDiagnosticObjectDisplay($row->invoice_ref ?: $row->invoice_ref_snapshot, (int) $row->fk_facture, (int) $row->invoice_entity, $entity, DOL_URL_ROOT.'/compta/facture/card.php?facid='.(int) $row->fk_facture).'</td><td>'.(int) $row->invoice_entity.'</td></tr>';
print '<tr><td>'.$langs->trans('Payment').'</td><td>'.dolivoucherDiagnosticObjectDisplay($row->payment_ref ?: $row->payment_ref_snapshot, (int) $row->fk_paiement, (int) $row->payment_entity, $entity, DOL_URL_ROOT.'/compta/paiement/card.php?id='.(int) $row->fk_paiement).'</td><td>'.(int) $row->payment_entity.'</td></tr>';
print '<tr><td>'.$langs->trans('Allocation').'</td><td>'.((int) $row->allocation_count > 0 ? (int) $row->allocation_count.' — '.price($row->allocation_amount, 0, $langs, 1, -1, -1, $conf->currency) : $langs->trans('Missing')).'</td><td>'.((int) $row->allocation_count > 0 ? '#'.(int) $row->allocation_invoice_min : '').'</td></tr>';
print '<tr><td>'.$langs->trans('InvoiceSnapshot').'</td><td colspan="2">'.dol_escape_htmltag($row->invoice_ref_snapshot).'</td></tr>';
print '<tr><td>'.$langs->trans('PaymentSnapshot').'</td><td colspan="2">'.dol_escape_htmltag((string) $row->payment_ref_snapshot).'</td></tr>';
print '<tr><td>'.$langs->trans('NativePaymentMode').'</td><td colspan="2">'.dol_escape_htmltag((string) $row->payment_mode_code).'</td></tr>';
print '<tr><td>'.$langs->trans('BankLine').'</td><td colspan="2">'.((int) $row->payment_bank_id > 0 ? '#'.(int) $row->payment_bank_id : $langs->trans('None')).'</td></tr>';
if ((int) ($row->linked_reversal_id ?? 0) > 0) print '<tr><td>'.$langs->trans('Reversal').'</td><td colspan="2"><a href="settlement_card.php?id='.(int) $row->linked_reversal_id.'">#'.(int) $row->linked_reversal_id.'</a></td></tr>';
if ((int) ($row->reversal_of ?? 0) > 0) {
	$parentLabel = '#'.(int) $row->reversal_of;
	if ((int) ($row->parent_entity ?? 0) === $entity) $parentLabel = '<a href="settlement_card.php?id='.(int) $row->reversal_of.'">'.$parentLabel.'</a>';
	print '<tr><td>'.$langs->trans('ReversalOf').'</td><td colspan="2">'.$parentLabel.'</td></tr>';
}
print '</table></div></div><div class="clearboth"></div>';

print '<br>'.load_fiche_titre($langs->trans('SettlementDiagnostic'), '', 'search');
print '<div class="center">'.dolivoucherDiagnosticBadge($diagnostic['level'], $langs).'</div>';
print '<div class="div-table-responsive"><table class="tagtable liste centpercent"><tr class="liste_titre"><th>'.$langs->trans('Level').'</th><th>'.$langs->trans('DiagnosticCode').'</th><th>'.$langs->trans('DiagnosticResult').'</th></tr>';
foreach ($diagnostic['checks'] as $check) print '<tr class="oddeven"><td>'.dolivoucherDiagnosticBadge($check['level'], $langs).'</td><td>'.dol_escape_htmltag($check['code']).'</td><td>'.$langs->trans($check['message_key']).'</td></tr>';
print '</table></div>';
llxFooter();
