<?php
/* Copyright (C) 2026 Fred Omega Junior */

declare(strict_types=1);

$res = @include '../../main.inc.php';
if (!$res) die('Include of main fails');
require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
require_once __DIR__.'/class/dolivoucheroperation.class.php';
require_once __DIR__.'/lib/dolivoucher.lib.php';

$langs->load('dolivoucher@dolivoucher');
if (!isModEnabled('dolivoucher') || !$user->hasRight('dolivoucher', 'audit', 'read') || $user->socid > 0) accessforbidden();

$entity = (int) $conf->entity;
$form = new Form($db);
$action = GETPOST('action', 'aZ09');
$contextpage = 'dolivoucheroperationlist';
$arrayfields = array(
	'o.date_operation' => array('label' => 'OperationDate', 'checked' => '1', 'position' => 10),
	'o.operation_uuid' => array('label' => 'OperationUuid', 'checked' => '1', 'position' => 20),
	'o.operation_type' => array('label' => 'OperationType', 'checked' => '1', 'position' => 30),
	'o.fk_portfolio' => array('label' => 'Portfolio', 'checked' => '1', 'position' => 40),
	'o.fk_voucher' => array('label' => 'Voucher', 'checked' => '1', 'position' => 50),
	'o.amount' => array('label' => 'Amount', 'checked' => '1', 'position' => 60),
	'o.reason' => array('label' => 'Reason', 'checked' => '1', 'position' => 70),
);
include DOL_DOCUMENT_ROOT.'/core/actions_changeselectedfields.inc.php';
$selectedfields = $form->multiSelectArrayWithCheckbox('selectedfields', $arrayfields, $contextpage);

$operationTypes = array(
	DoliVoucherOperation::TYPE_FUND_NEW => $langs->trans('Operation'.DoliVoucherOperation::TYPE_FUND_NEW),
	DoliVoucherOperation::TYPE_CARRYOVER_IN => $langs->trans('Operation'.DoliVoucherOperation::TYPE_CARRYOVER_IN),
	DoliVoucherOperation::TYPE_ISSUE => $langs->trans('Operation'.DoliVoucherOperation::TYPE_ISSUE),
	DoliVoucherOperation::TYPE_ACTIVATE => $langs->trans('Operation'.DoliVoucherOperation::TYPE_ACTIVATE),
	DoliVoucherOperation::TYPE_CONSUME => $langs->trans('Operation'.DoliVoucherOperation::TYPE_CONSUME),
	DoliVoucherOperation::TYPE_BLOCK => $langs->trans('Operation'.DoliVoucherOperation::TYPE_BLOCK),
	DoliVoucherOperation::TYPE_UNBLOCK => $langs->trans('Operation'.DoliVoucherOperation::TYPE_UNBLOCK),
	DoliVoucherOperation::TYPE_CANCEL => $langs->trans('Operation'.DoliVoucherOperation::TYPE_CANCEL),
	DoliVoucherOperation::TYPE_EXPIRE => $langs->trans('Operation'.DoliVoucherOperation::TYPE_EXPIRE),
	DoliVoucherOperation::TYPE_TRANSFER_OUT => $langs->trans('Operation'.DoliVoucherOperation::TYPE_TRANSFER_OUT),
	DoliVoucherOperation::TYPE_TRANSFER_IN => $langs->trans('Operation'.DoliVoucherOperation::TYPE_TRANSFER_IN),
	DoliVoucherOperation::TYPE_CORRECTION => $langs->trans('Operation'.DoliVoucherOperation::TYPE_CORRECTION),
);

$searchType = GETPOST('search_type', 'alpha');
$searchPortfolio = GETPOSTINT('search_portfolio');
$searchVoucher = GETPOSTINT('search_voucher');
$searchUuid = GETPOST('search_uuid', 'alphanohtml');
$searchAmount = GETPOST('search_amount', 'alphanohtml');
$searchReason = GETPOST('search_reason', 'restricthtml');
$searchType = $searchType === '-1' ? '' : $searchType;
$dateFrom = GETPOSTINT('date_fromyear') > 0 ? dol_mktime(0, 0, 0, GETPOSTINT('date_frommonth'), GETPOSTINT('date_fromday'), GETPOSTINT('date_fromyear')) : 0;
$dateTo = GETPOSTINT('date_toyear') > 0 ? dol_mktime(23, 59, 59, GETPOSTINT('date_tomonth'), GETPOSTINT('date_today'), GETPOSTINT('date_toyear')) : 0;
$removeFilter = GETPOST('button_removefilter_x', 'alpha') || GETPOST('button_removefilter.x', 'alpha') || GETPOST('button_removefilter', 'alpha');
if ($removeFilter) {
	$searchType = '';
	$searchPortfolio = 0;
	$searchVoucher = 0;
	$searchUuid = '';
	$searchAmount = '';
	$searchReason = '';
	$dateFrom = 0;
	$dateTo = 0;
}

$page = max(0, GETPOSTINT('page'));
$limit = max(1, GETPOSTINT('limit') ?: getDolGlobalInt('MAIN_SIZE_LISTE_LIMIT', 25));
$offset = $page * $limit;
$param = '&limit='.$limit;
if ($searchType !== '') $param .= '&search_type='.urlencode($searchType);
if ($searchPortfolio > 0) $param .= '&search_portfolio='.$searchPortfolio;
if ($searchVoucher > 0) $param .= '&search_voucher='.$searchVoucher;
if ($searchUuid !== '') $param .= '&search_uuid='.urlencode($searchUuid);
if ($searchAmount !== '') $param .= '&search_amount='.urlencode($searchAmount);
if ($searchReason !== '') $param .= '&search_reason='.urlencode($searchReason);
if ($dateFrom > 0) {
	$param .= '&date_fromday='.dol_print_date($dateFrom, '%d');
	$param .= '&date_frommonth='.dol_print_date($dateFrom, '%m');
	$param .= '&date_fromyear='.dol_print_date($dateFrom, '%Y');
}
if ($dateTo > 0) {
	$param .= '&date_today='.dol_print_date($dateTo, '%d');
	$param .= '&date_tomonth='.dol_print_date($dateTo, '%m');
	$param .= '&date_toyear='.dol_print_date($dateTo, '%Y');
}

$sql = 'SELECT o.* FROM '.$db->prefix().'dolivoucher_operation o WHERE o.entity='.$entity;
if ($searchType !== '') $sql .= " AND o.operation_type='".$db->escape($searchType)."'";
if ($searchPortfolio > 0) $sql .= ' AND o.fk_portfolio='.$searchPortfolio;
if ($searchVoucher > 0) $sql .= ' AND o.fk_voucher='.$searchVoucher;
if ($searchUuid !== '') $sql .= " AND o.operation_uuid LIKE '%".$db->escape($searchUuid)."%'";
if ($searchAmount !== '') $sql .= natural_search('o.amount', $searchAmount, 1);
if ($searchReason !== '') $sql .= " AND o.reason LIKE '%".$db->escape($searchReason)."%'";
if ($dateFrom > 0) $sql .= " AND o.date_operation>='".$db->idate($dateFrom)."'";
if ($dateTo > 0) $sql .= " AND o.date_operation<='".$db->idate($dateTo)."'";
$sql .= ' ORDER BY o.date_operation DESC, o.rowid DESC'.$db->plimit($limit + 1, $offset);
$resql = $db->query($sql);
$num = $resql ? $db->num_rows($resql) : 0;

llxHeader('', $langs->trans('OperationJournal'));
print '<form method="POST" id="searchFormList" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="formfilteraction" id="formfilteraction" value="list">';
print_barre_liste($langs->trans('OperationJournal'), $page, $_SERVER['PHP_SELF'], $param, '', '', '', $num, '', 'list', 0, '', '', $limit);
print '<div class="div-table-responsive"><table class="tagtable liste">';

print '<tr class="liste_titre_filter">';
if (!empty($arrayfields['o.date_operation']['checked'])) {
	print '<td class="liste_titre nowrap">';
	print $form->selectDate($dateFrom ?: -1, 'date_from', 0, 0, 1, '', 1, 0, 0, '', '', '', '', 1, '', $langs->trans('From'));
	print $form->selectDate($dateTo ?: -1, 'date_to', 0, 0, 1, '', 1, 0, 0, '', '', '', '', 1, '', $langs->trans('to'));
	print '</td>';
}
if (!empty($arrayfields['o.operation_uuid']['checked'])) print '<td class="liste_titre"><input class="flat maxwidth150" name="search_uuid" value="'.dol_escape_htmltag($searchUuid).'"></td>';
if (!empty($arrayfields['o.operation_type']['checked'])) print '<td class="liste_titre">'.$form->selectarray('search_type', $operationTypes, $searchType, 1, 0, 0, '', 0, 0, 0, '', 'maxwidth150').'</td>';
if (!empty($arrayfields['o.fk_portfolio']['checked'])) print '<td class="liste_titre"><input class="flat maxwidth75" type="number" name="search_portfolio" value="'.($searchPortfolio ?: '').'"></td>';
if (!empty($arrayfields['o.fk_voucher']['checked'])) print '<td class="liste_titre"><input class="flat maxwidth75" type="number" name="search_voucher" value="'.($searchVoucher ?: '').'"></td>';
if (!empty($arrayfields['o.amount']['checked'])) print '<td class="liste_titre"><input class="flat maxwidth75" name="search_amount" value="'.dol_escape_htmltag($searchAmount).'"></td>';
if (!empty($arrayfields['o.reason']['checked'])) print '<td class="liste_titre"><input class="flat maxwidth150" name="search_reason" value="'.dol_escape_htmltag($searchReason).'"></td>';
print '<td class="liste_titre center maxwidthsearch actioncolumn"><button type="submit" class="liste_titre button_search" name="button_search" value="x"><span class="fas fa-search"></span></button> <button type="submit" class="liste_titre button_removefilter reposition" name="button_removefilter_x" value="x"><span class="fas fa-times"></span></button></td></tr>';

print '<tr class="liste_titre">';
if (!empty($arrayfields['o.date_operation']['checked'])) print_liste_field_titre($langs->trans($arrayfields['o.date_operation']['label']), $_SERVER['PHP_SELF'], '', '', $param, '', '');
if (!empty($arrayfields['o.operation_uuid']['checked'])) print_liste_field_titre($langs->trans($arrayfields['o.operation_uuid']['label']), $_SERVER['PHP_SELF'], '', '', $param, '', '');
if (!empty($arrayfields['o.operation_type']['checked'])) print_liste_field_titre($langs->trans($arrayfields['o.operation_type']['label']), $_SERVER['PHP_SELF'], '', '', $param, '', '');
if (!empty($arrayfields['o.fk_portfolio']['checked'])) print_liste_field_titre($langs->trans($arrayfields['o.fk_portfolio']['label']), $_SERVER['PHP_SELF'], '', '', $param, '', '');
if (!empty($arrayfields['o.fk_voucher']['checked'])) print_liste_field_titre($langs->trans($arrayfields['o.fk_voucher']['label']), $_SERVER['PHP_SELF'], '', '', $param, '', '');
if (!empty($arrayfields['o.amount']['checked'])) print_liste_field_titre($langs->trans($arrayfields['o.amount']['label']), $_SERVER['PHP_SELF'], '', '', $param, 'align="right"', '');
if (!empty($arrayfields['o.reason']['checked'])) print_liste_field_titre($langs->trans($arrayfields['o.reason']['label']), $_SERVER['PHP_SELF'], '', '', $param, '', '');
print_liste_field_titre($selectedfields, $_SERVER['PHP_SELF'], '', '', $param, '', '', '', 'maxwidthsearch center ');
print '</tr>';

$count = 0;
while ($resql && ($row = $db->fetch_object($resql)) && $count < $limit) {
	$count++;
	print '<tr class="oddeven">';
	if (!empty($arrayfields['o.date_operation']['checked'])) print '<td>'.dol_print_date($db->jdate($row->date_operation), 'dayhour').'</td>';
	if (!empty($arrayfields['o.operation_uuid']['checked'])) print '<td>'.dol_escape_htmltag($row->operation_uuid).'</td>';
	if (!empty($arrayfields['o.operation_type']['checked'])) print '<td>'.$langs->trans('Operation'.$row->operation_type).'</td>';
	if (!empty($arrayfields['o.fk_portfolio']['checked'])) print '<td><a href="portfolio_card.php?id='.(int) $row->fk_portfolio.'">#'.(int) $row->fk_portfolio.'</a></td>';
	if (!empty($arrayfields['o.fk_voucher']['checked'])) print '<td>'.($row->fk_voucher ? '<a href="voucher_card.php?id='.(int) $row->fk_voucher.'">#'.(int) $row->fk_voucher.'</a>' : '').'</td>';
	if (!empty($arrayfields['o.amount']['checked'])) print '<td class="right">'.price($row->amount, 0, $langs, 1, -1, -1, 'XOF').'</td>';
	if (!empty($arrayfields['o.reason']['checked'])) print '<td>'.dol_escape_htmltag((string) $row->reason).'</td>';
	print '<td></td></tr>';
}
$columnCount = 1;
foreach ($arrayfields as $field) if (!empty($field['checked'])) $columnCount++;
if (!$count) print '<tr class="oddeven"><td colspan="'.$columnCount.'" class="opacitymedium">'.$langs->trans('None').'</td></tr>';
print '</table></div></form>';
llxFooter();
