<?php
/* Copyright (C) 2026 Fred Omega Junior */

declare(strict_types=1);

$res = @include '../../main.inc.php';
if (!$res) die('Include of main fails');
require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
require_once __DIR__.'/class/dolivouchersettlementdiagnosticservice.class.php';
require_once __DIR__.'/lib/dolivoucher_settlement_diagnostic.lib.php';

$langs->loadLangs(array('bills', 'payments', 'dolivoucher@dolivoucher'));
if (!isModEnabled('dolivoucher') || !$user->hasRight('dolivoucher', 'audit', 'read') || $user->socid > 0) accessforbidden();

$entity = (int) $conf->entity;
$action = GETPOST('action', 'aZ09');
$canDiagnose = !empty($user->admin) || $user->hasRight('dolivoucher', 'settlement', 'diagnose');
$form = new Form($db);
$service = new DoliVoucherSettlementDiagnosticService($db);
$contextpage = 'dolivouchersettlementlist';
$arrayfields = array(
	's.date_creation' => array('label' => 'Date', 'checked' => '1', 'position' => 10),
	's.settlement_uuid' => array('label' => 'SettlementUuid', 'checked' => '1', 'position' => 20),
	's.event_type' => array('label' => 'Event', 'checked' => '1', 'position' => 30),
	's.state' => array('label' => 'SettlementState', 'checked' => '1', 'position' => 40),
	's.request_source' => array('label' => 'RequestSource', 'checked' => '1', 'position' => 50),
	'v.ref' => array('label' => 'Voucher', 'checked' => '1', 'position' => 60),
	'f.ref' => array('label' => 'Invoice', 'checked' => '1', 'position' => 70),
	'p.ref' => array('label' => 'Payment', 'checked' => '1', 'position' => 80),
	'o.operation_uuid' => array('label' => 'Operation', 'checked' => '1', 'position' => 90),
	's.amount' => array('label' => 'Amount', 'checked' => '1', 'position' => 100),
	'u.login' => array('label' => 'User', 'checked' => '0', 'position' => 110),
	'diagnostic.level' => array('label' => 'DiagnosticStatus', 'checked' => '1', 'position' => 120),
	'diagnostic.count' => array('label' => 'AnomalyCount', 'checked' => '1', 'position' => 130),
);
include DOL_DOCUMENT_ROOT.'/core/actions_changeselectedfields.inc.php';
$selectedfields = $form->multiSelectArrayWithCheckbox('selectedfields', $arrayfields, $contextpage);
$filters = dolivoucherSettlementDiagnosticFilters();
if (!$canDiagnose) $filters['idempotency_key'] = '';
$page = max(0, GETPOSTINT('page'));
$limit = max(1, GETPOSTINT('limit') ?: getDolGlobalInt('MAIN_SIZE_LISTE_LIMIT', 25));
$offset = $page * $limit;
$sortFields = array('s.date_creation', 's.settlement_uuid', 's.event_type', 's.request_source', 'v.ref', 'f.ref', 'p.ref', 'o.operation_uuid', 's.amount', 'u.login');
$sortfield = GETPOST('sortfield', 'alphanohtml');
if (!in_array($sortfield, $sortFields, true)) $sortfield = 's.date_creation';
$sortorder = strtoupper(GETPOST('sortorder', 'alpha'));
if (!in_array($sortorder, array('ASC', 'DESC'), true)) $sortorder = 'DESC';
$maxRows = DoliVoucherSettlementDiagnosticService::normalizeMaxRows(getDolGlobalInt('DOLIVOUCHER_DIAGNOSTIC_MAX_ROWS', 1000));
$result = $service->fetchPage($entity, $filters, $limit, $offset, $sortfield, $sortorder, $maxRows);
$param = '&limit='.$limit.dolivoucherSettlementDiagnosticParam($filters);
$eventOptions = array('APPLY' => $langs->trans('SettlementAPPLY'), 'REVERSAL' => $langs->trans('SettlementREVERSAL'));
$stateOptions = array('ACTIVE' => $langs->trans('SettlementStateACTIVE'), 'REVERSED' => $langs->trans('SettlementStateREVERSED'));
$sourceOptions = array('INVOICE_CARD' => 'INVOICE_CARD', 'TAKEPOS' => 'TAKEPOS');
$levelOptions = array();
foreach (DoliVoucherSettlementDiagnosticService::levels() as $level) $levelOptions[$level] = $langs->trans('DiagnosticLevel'.$level);
$codeOptions = array();
foreach (DoliVoucherSettlementDiagnosticService::codes() as $code) $codeOptions[$code] = $langs->trans('DiagnosticCode'.$code);

/* Views */
llxHeader('', $langs->trans('DoliVoucherSettlements'));
print '<form method="POST" id="searchFormList" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'">';
print '<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="formfilteraction" value="list">';
print '<input type="hidden" name="sortfield" value="'.dol_escape_htmltag($sortfield).'"><input type="hidden" name="sortorder" value="'.dol_escape_htmltag($sortorder).'">';
print_barre_liste($langs->trans('DoliVoucherSettlements'), $page, $_SERVER['PHP_SELF'], $param, $sortfield, $sortorder, '', count($result['items']) + ($result['has_more'] ? 1 : 0), '', 'payment', 0, '', '', $limit);
if ($result['limited']) print '<div class="warning">'.$langs->trans('DiagnosticAnalysisLimited', $maxRows).'</div>';
print '<div class="div-table-responsive"><table class="tagtable liste centpercent">';
print '<tr class="liste_titre_filter">';
foreach ($arrayfields as $key => $field) {
	if (empty($field['checked'])) continue;
	print '<td class="liste_titre'.($key === 's.date_creation' ? ' center' : '').'">';
	if ($key === 's.date_creation') {
		print '<div class="nowrapfordate">'.$form->selectDate($filters['date_from'] ?: -1, 'date_from', 0, 0, 1, '', 1, 0, 0, '', '', '', '', 1, '', $langs->trans('From')).'</div>';
		print '<div class="nowrapfordate">'.$form->selectDate($filters['date_to'] ?: -1, 'date_to', 0, 0, 1, '', 1, 0, 0, '', '', '', '', 1, '', $langs->trans('to')).'</div>';
	} elseif ($key === 's.settlement_uuid') print '<input class="flat maxwidth150" name="search_uuid" value="'.dol_escape_htmltag((string) $filters['settlement_uuid']).'">'.($canDiagnose ? '<br><input class="flat maxwidth150" name="search_idempotency" placeholder="'.$langs->trans('IdempotencyKey').'" value="'.dol_escape_htmltag((string) $filters['idempotency_key']).'">' : '');
	elseif ($key === 's.event_type') print $form->selectarray('search_event', $eventOptions, $filters['event_type'], 1, 0, 0, '', 0, 0, 0, '', 'maxwidth125');
	elseif ($key === 's.state') print $form->selectarray('search_state', $stateOptions, $filters['state'], 1, 0, 0, '', 0, 0, 0, '', 'maxwidth125');
	elseif ($key === 's.request_source') print $form->selectarray('search_source', $sourceOptions, $filters['request_source'], 1, 0, 0, '', 0, 0, 0, '', 'maxwidth125');
	elseif ($key === 'v.ref') print '<input class="flat maxwidth125" name="search_voucher" value="'.dol_escape_htmltag((string) $filters['voucher']).'">';
	elseif ($key === 'f.ref') print '<input class="flat maxwidth125" name="search_invoice" value="'.dol_escape_htmltag((string) $filters['invoice']).'">';
	elseif ($key === 'p.ref') print '<input class="flat maxwidth100" name="search_payment" value="'.dol_escape_htmltag((string) $filters['payment']).'">';
	elseif ($key === 'o.operation_uuid') print '<input class="flat maxwidth125" name="search_operation" value="'.dol_escape_htmltag((string) $filters['operation']).'">';
	elseif ($key === 's.amount') print '<input class="flat maxwidth75" name="search_amount" value="'.dol_escape_htmltag((string) $filters['amount']).'">';
	elseif ($key === 'diagnostic.level') print $form->selectarray('search_level', $levelOptions, $filters['diagnostic_level'], 1, 0, 0, '', 0, 0, 0, '', 'maxwidth125');
	elseif ($key === 'diagnostic.count') print $form->selectarray('search_code', $codeOptions, $filters['diagnostic_code'], 1, 0, 0, '', 0, 0, 0, '', 'maxwidth200');
	print '</td>';
}
print '<td class="liste_titre center maxwidthsearch"><button type="submit" class="liste_titre button_search" name="button_search" value="x"><span class="fas fa-search"></span></button> <button type="submit" class="liste_titre button_removefilter reposition" name="button_removefilter_x" value="x"><span class="fas fa-times"></span></button></td></tr>';
print '<tr class="liste_titre">';
foreach ($arrayfields as $key => $field) {
	if (empty($field['checked'])) continue;
	if (in_array($key, $sortFields, true)) print_liste_field_titre($langs->trans($field['label']), $_SERVER['PHP_SELF'], $key, '', $param, $key === 's.amount' ? 'align="right"' : '', $sortfield, $sortorder);
	else print '<th>'.$langs->trans($field['label']).'</th>';
}
print_liste_field_titre($selectedfields, $_SERVER['PHP_SELF'], '', '', $param, '', '', '', 'maxwidthsearch center');
print '</tr>';
foreach ($result['items'] as $item) {
	$row = $item['record']; $diagnostic = $item['diagnostic'];
	print '<tr class="oddeven">';
	foreach ($arrayfields as $key => $field) {
		if (empty($field['checked'])) continue;
		if ($key === 's.date_creation') print '<td class="nowrap">'.dol_print_date($db->jdate($row->date_creation), 'dayhour').'</td>';
		elseif ($key === 's.settlement_uuid') print '<td><a href="settlement_card.php?id='.(int) $row->rowid.'">'.dol_escape_htmltag($row->settlement_uuid).'</a></td>';
		elseif ($key === 's.event_type') print '<td>'.$langs->trans('Settlement'.$row->event_type).'</td>';
		elseif ($key === 's.state') print '<td>'.$langs->trans('SettlementState'.dolivoucherSettlementState($row)).'</td>';
		elseif ($key === 's.request_source') print '<td>'.dol_escape_htmltag($row->request_source).'</td>';
		elseif ($key === 'v.ref') print '<td>'.dolivoucherDiagnosticObjectDisplay($row->voucher_ref, (int) $row->fk_voucher, (int) $row->voucher_entity, $entity, 'voucher_card.php?id='.(int) $row->fk_voucher).'</td>';
		elseif ($key === 'f.ref') print '<td>'.dolivoucherDiagnosticObjectDisplay($row->invoice_ref ?: $row->invoice_ref_snapshot, (int) $row->fk_facture, (int) $row->invoice_entity, $entity, DOL_URL_ROOT.'/compta/facture/card.php?facid='.(int) $row->fk_facture).'</td>';
		elseif ($key === 'p.ref') print '<td>'.dolivoucherDiagnosticObjectDisplay($row->payment_ref ?: $row->payment_ref_snapshot, (int) $row->fk_paiement, (int) $row->payment_entity, $entity, DOL_URL_ROOT.'/compta/paiement/card.php?id='.(int) $row->fk_paiement).'</td>';
		elseif ($key === 'o.operation_uuid') print '<td>'.(!empty($row->operation_exists) && (int) $row->operation_entity === $entity ? '<a href="operation_list.php?search_uuid='.urlencode($row->operation_uuid).'">'.dol_escape_htmltag($row->operation_uuid).'</a>' : '#'.(int) $row->fk_operation).'</td>';
		elseif ($key === 's.amount') print '<td class="right nowrap">'.price($row->amount, 0, $langs, 1, -1, -1, $conf->currency).'</td>';
		elseif ($key === 'u.login') print '<td>'.dol_escape_htmltag(trim((string) $row->user_firstname.' '.(string) $row->user_lastname).' ('.(string) $row->user_login.')').'</td>';
		elseif ($key === 'diagnostic.level') print '<td>'.dolivoucherDiagnosticBadge($diagnostic['level'], $langs).'</td>';
		elseif ($key === 'diagnostic.count') print '<td class="center">'.(count($diagnostic['codes']) - (in_array(DoliVoucherSettlementDiagnosticService::CODE_OK, $diagnostic['codes'], true) ? 1 : 0)).'</td>';
	}
	print '<td></td></tr>';
}
if (!$result['items']) print '<tr class="oddeven"><td colspan="'.(count($arrayfields) + 1).'" class="opacitymedium">'.$langs->trans('None').'</td></tr>';
print '</table></div></form>';
llxFooter();
