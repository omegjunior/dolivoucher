<?php
/* Copyright (C) 2026 Fred Omega Junior */

declare(strict_types=1);

$res = @include '../../main.inc.php';
if (!$res) die('Include of main fails');
require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
require_once __DIR__.'/class/dolivouchersettlementdiagnosticservice.class.php';
require_once __DIR__.'/lib/dolivoucher_settlement_diagnostic.lib.php';

$langs->load('dolivoucher@dolivoucher');
if (!isModEnabled('dolivoucher') || (!empty($user->socid)) || (empty($user->admin) && !$user->hasRight('dolivoucher', 'settlement', 'diagnose'))) accessforbidden();
$entity = (int) $conf->entity;
$form = new Form($db);
$service = new DoliVoucherSettlementDiagnosticService($db);
$filters = dolivoucherSettlementDiagnosticFilters();
$configuredMax = DoliVoucherSettlementDiagnosticService::normalizeMaxRows(getDolGlobalInt('DOLIVOUCHER_DIAGNOSTIC_MAX_ROWS', 1000));
$requestedLimit = GETPOSTINT('analysis_limit');
$analysisLimit = $requestedLimit > 0 ? min($requestedLimit, $configuredMax) : $configuredMax;
$result = $service->fetchPage($entity, $filters, $analysisLimit, 0, 's.date_creation', 'DESC', $configuredMax);
$summary = $service->summarize($result['items']);
$param = dolivoucherSettlementDiagnosticParam($filters).'&analysis_limit='.$analysisLimit;
$sourceOptions = array('INVOICE_CARD' => 'INVOICE_CARD', 'TAKEPOS' => 'TAKEPOS');
$eventOptions = array('APPLY' => $langs->trans('SettlementAPPLY'), 'REVERSAL' => $langs->trans('SettlementREVERSAL'));
$stateOptions = array('ACTIVE' => $langs->trans('SettlementStateACTIVE'), 'REVERSED' => $langs->trans('SettlementStateREVERSED'));
$levelOptions = array();
foreach (DoliVoucherSettlementDiagnosticService::levels() as $level) $levelOptions[$level] = $langs->trans('DiagnosticLevel'.$level);
$codeOptions = array();
foreach (DoliVoucherSettlementDiagnosticService::codes() as $code) $codeOptions[$code] = $langs->trans('DiagnosticCode'.$code);

/* Views */
llxHeader('', $langs->trans('SettlementDiagnostic'));
print load_fiche_titre($langs->trans('SettlementDiagnostic'), '', 'search');
print '<div class="info">'.$langs->trans('SettlementDiagnosticReadOnlyNotice').'</div>';
print '<form method="GET" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'"><table class="border centpercent">';
print '<tr><td class="titlefield">'.$langs->trans('Period').'</td><td><span class="nowrapfordate">'.$form->selectDate($filters['date_from'] ?: -1, 'date_from', 0, 0, 1, '', 1, 0, 0, '', '', '', '', 1, '', $langs->trans('From')).'</span> <span class="nowrapfordate">'.$form->selectDate($filters['date_to'] ?: -1, 'date_to', 0, 0, 1, '', 1, 0, 0, '', '', '', '', 1, '', $langs->trans('to')).'</span></td></tr>';
print '<tr><td>'.$langs->trans('SettlementUuid').'</td><td><input name="search_uuid" value="'.dol_escape_htmltag((string) $filters['settlement_uuid']).'"> &nbsp; '.$langs->trans('IdempotencyKey').' <input name="search_idempotency" value="'.dol_escape_htmltag((string) $filters['idempotency_key']).'"></td></tr>';
print '<tr><td>'.$langs->trans('Event').' / '.$langs->trans('SettlementState').' / '.$langs->trans('RequestSource').'</td><td>'.$form->selectarray('search_event', $eventOptions, $filters['event_type'], 1).' '.$form->selectarray('search_state', $stateOptions, $filters['state'], 1).' '.$form->selectarray('search_source', $sourceOptions, $filters['request_source'], 1).'</td></tr>';
print '<tr><td>'.$langs->trans('Voucher').' / '.$langs->trans('Invoice').'</td><td><input name="search_voucher" placeholder="'.$langs->trans('Voucher').'" value="'.dol_escape_htmltag((string) $filters['voucher']).'"> <input name="search_invoice" placeholder="'.$langs->trans('Invoice').'" value="'.dol_escape_htmltag((string) $filters['invoice']).'"></td></tr>';
print '<tr><td>'.$langs->trans('Payment').' / '.$langs->trans('Operation').'</td><td><input name="search_payment" placeholder="'.$langs->trans('Payment').'" value="'.dol_escape_htmltag((string) $filters['payment']).'"> <input name="search_operation" placeholder="'.$langs->trans('Operation').'" value="'.dol_escape_htmltag((string) $filters['operation']).'"></td></tr>';
print '<tr><td>'.$langs->trans('Amount').' / '.$langs->trans('AnalysisLimit').'</td><td><input class="maxwidth100" name="search_amount" value="'.dol_escape_htmltag((string) $filters['amount']).'"> <input type="number" min="1" max="'.$configuredMax.'" name="analysis_limit" value="'.$analysisLimit.'"> / '.$configuredMax.'</td></tr>';
print '<tr><td>'.$langs->trans('DiagnosticStatus').' / '.$langs->trans('DiagnosticCode').'</td><td>'.$form->selectarray('search_level', $levelOptions, $filters['diagnostic_level'], 1).' '.$form->selectarray('search_code', $codeOptions, $filters['diagnostic_code'], 1, 0, 0, '', 0, 0, 0, '', 'maxwidth300').'</td></tr>';
print '</table><div class="center"><input type="submit" class="button button-search" value="'.$langs->trans('Analyze').'"> <a class="button" href="settlement_diagnostic.php">'.$langs->trans('RemoveFilter').'</a> <a class="button" href="settlement_diagnostic_export.php?'.dol_escape_htmltag(ltrim($param, '&')).'">'.$langs->trans('ExportCsv').'</a></div></form>';

print '<div class="fichecenter"><table class="noborder centpercent"><tr class="liste_titre"><th>'.$langs->trans('AnalyzedSettlements').'</th><th>'.$langs->trans('DiagnosticLevelOK').'</th><th>'.$langs->trans('DiagnosticLevelWARNING').'</th><th>'.$langs->trans('DiagnosticLevelERROR').'</th></tr>';
print '<tr class="oddeven"><td class="center">'.$summary['analyzed'].'</td><td class="center">'.$summary['OK'].'</td><td class="center">'.$summary['WARNING'].'</td><td class="center">'.$summary['ERROR'].'</td></tr></table></div>';
if ($result['has_more'] || $result['limited']) print '<div class="warning">'.$langs->trans('DiagnosticAnalysisLimited', $analysisLimit).'</div>';
print '<br>'.load_fiche_titre($langs->trans('AnomaliesByCode'), '', 'list');
print '<div class="div-table-responsive"><table class="tagtable liste centpercent"><tr class="liste_titre"><th>'.$langs->trans('DiagnosticCode').'</th><th>'.$langs->trans('Description').'</th><th class="right">'.$langs->trans('Number').'</th></tr>';
foreach ($summary['codes'] as $code => $count) print '<tr class="oddeven"><td>'.dol_escape_htmltag($code).'</td><td>'.$langs->trans('DiagnosticCode'.$code).'</td><td class="right">'.(int) $count.'</td></tr>';
if (!$summary['codes']) print '<tr class="oddeven"><td colspan="3" class="opacitymedium">'.$langs->trans('None').'</td></tr>';
print '</table></div>';
print '<br>'.load_fiche_titre($langs->trans('AnalyzedSettlements'), '', 'payment');
print '<div class="div-table-responsive"><table class="tagtable liste centpercent"><tr class="liste_titre"><th>'.$langs->trans('Date').'</th><th>'.$langs->trans('SettlementUuid').'</th><th>'.$langs->trans('Voucher').'</th><th>'.$langs->trans('Invoice').'</th><th>'.$langs->trans('DiagnosticStatus').'</th><th>'.$langs->trans('DiagnosticCodes').'</th></tr>';
foreach ($result['items'] as $item) {
	$row = $item['record']; $diagnostic = $item['diagnostic'];
	print '<tr class="oddeven"><td>'.dol_print_date($db->jdate($row->date_creation), 'dayhour').'</td><td><a href="settlement_card.php?id='.(int) $row->rowid.'">'.dol_escape_htmltag($row->settlement_uuid).'</a></td><td>'.dol_escape_htmltag((string) ($row->voucher_ref ?: '#'.$row->fk_voucher)).'</td><td>'.dol_escape_htmltag((string) ($row->invoice_ref ?: $row->invoice_ref_snapshot)).'</td><td>'.dolivoucherDiagnosticBadge($diagnostic['level'], $langs).'</td><td>'.dol_escape_htmltag(implode(', ', array_diff($diagnostic['codes'], array(DoliVoucherSettlementDiagnosticService::CODE_OK)))).'</td></tr>';
}
if (!$result['items']) print '<tr class="oddeven"><td colspan="6" class="opacitymedium">'.$langs->trans('None').'</td></tr>';
print '</table></div>';
llxFooter();
