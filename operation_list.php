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
$type = GETPOST('search_type', 'alpha');
$portfolio = GETPOSTINT('search_portfolio');
$voucher = GETPOSTINT('search_voucher');
$uuid = GETPOST('search_uuid', 'alphanohtml');
$dateFrom = GETPOSTINT('date_fromyear') > 0 ? dol_mktime(0, 0, 0, GETPOSTINT('date_frommonth'), GETPOSTINT('date_fromday'), GETPOSTINT('date_fromyear')) : 0;
$dateTo = GETPOSTINT('date_toyear') > 0 ? dol_mktime(23, 59, 59, GETPOSTINT('date_tomonth'), GETPOSTINT('date_today'), GETPOSTINT('date_toyear')) : 0;
$removeFilter = GETPOST('button_removefilter_x', 'alpha') || GETPOST('button_removefilter.x', 'alpha') || GETPOST('button_removefilter', 'alpha');
if ($removeFilter) {
	$type = '';
	$portfolio = 0;
	$voucher = 0;
	$uuid = '';
	$dateFrom = 0;
	$dateTo = 0;
}
$page = max(0, GETPOSTINT('page'));
$limit = min(100, max(1, GETPOSTINT('limit') ?: getDolGlobalInt('MAIN_SIZE_LISTE_LIMIT', 25)));
$sql = 'SELECT o.* FROM '.$db->prefix().'dolivoucher_operation o WHERE o.entity='.$entity;
if ($type !== '') $sql .= " AND o.operation_type='".$db->escape($type)."'";
if ($portfolio > 0) $sql .= ' AND o.fk_portfolio='.$portfolio;
if ($voucher > 0) $sql .= ' AND o.fk_voucher='.$voucher;
if ($uuid !== '') $sql .= " AND o.operation_uuid='".$db->escape($uuid)."'";
if ($dateFrom > 0) $sql .= " AND o.date_operation>='".$db->idate($dateFrom)."'";
if ($dateTo > 0) $sql .= " AND o.date_operation<='".$db->idate($dateTo)."'";
$sql .= ' ORDER BY o.date_operation DESC, o.rowid DESC'.$db->plimit($limit + 1, $page * $limit);
$resql = $db->query($sql);
llxHeader('', $langs->trans('OperationJournal'));
print_barre_liste($langs->trans('OperationJournal'), $page, $_SERVER['PHP_SELF'], '', '', '', '', -1, '', 'list');
print '<form method="GET" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'"><div class="div-table-responsive"><table class="tagtable liste"><tr class="liste_titre">';
print_liste_field_titre($langs->trans('OperationDate'), $_SERVER['PHP_SELF'], '', '', '', '', '');
print_liste_field_titre($langs->trans('OperationUuid'), $_SERVER['PHP_SELF'], '', '', '', '', '');
print_liste_field_titre($langs->trans('OperationType'), $_SERVER['PHP_SELF'], '', '', '', '', '');
print_liste_field_titre($langs->trans('Portfolio'), $_SERVER['PHP_SELF'], '', '', '', '', '');
print_liste_field_titre($langs->trans('Voucher'), $_SERVER['PHP_SELF'], '', '', '', '', '');
print_liste_field_titre($langs->trans('Amount'), $_SERVER['PHP_SELF'], '', '', '', 'align="right"', '');
print_liste_field_titre($langs->trans('Reason'), $_SERVER['PHP_SELF'], '', '', '', '', '');
print '</tr>';
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
print '<tr class="liste_titre_filter"><td class="liste_titre nowrap">';
print $form->selectDate($dateFrom ?: -1, 'date_from', 0, 0, 1, '', 1, 0, 0, '', '', '', '', 1, '', $langs->trans('From'));
print $form->selectDate($dateTo ?: -1, 'date_to', 0, 0, 1, '', 1, 0, 0, '', '', '', '', 1, '', $langs->trans('to'));
print '</td><td class="liste_titre"><input class="flat maxwidth150" name="search_uuid" value="'.dol_escape_htmltag($uuid).'"></td><td class="liste_titre">'.$form->selectarray('search_type', $operationTypes, $type, 1, 0, 0, '', 0, 0, 0, '', 'maxwidth150').'</td><td class="liste_titre"><input class="flat maxwidth75" type="number" name="search_portfolio" value="'.($portfolio ?: '').'"></td><td class="liste_titre"><input class="flat maxwidth75" type="number" name="search_voucher" value="'.($voucher ?: '').'"></td><td class="liste_titre"></td><td class="liste_titre nowrap"><button type="submit" class="liste_titre button_search" name="button_search" value="x"><span class="fa fa-search"></span></button> <button type="submit" class="liste_titre button_removefilter" name="button_removefilter_x" value="x"><span class="fa fa-remove"></span></button></td></tr>';
$count = 0;
while ($resql && ($row = $db->fetch_object($resql)) && $count < $limit) {
	$count++;
	print '<tr class="oddeven"><td>'.dol_print_date($db->jdate($row->date_operation), 'dayhour').'</td><td>'.dol_escape_htmltag($row->operation_uuid).'</td><td>'.$langs->trans('Operation'.$row->operation_type).'</td><td><a href="portfolio_card.php?id='.(int) $row->fk_portfolio.'">#'.(int) $row->fk_portfolio.'</a></td><td>'.($row->fk_voucher ? '<a href="voucher_card.php?id='.(int) $row->fk_voucher.'">#'.(int) $row->fk_voucher.'</a>' : '').'</td><td class="right">'.price($row->amount, 0, $langs, 1, -1, -1, 'XOF').'</td><td>'.dol_escape_htmltag((string) $row->reason).'</td></tr>';
}
if (!$count) print '<tr class="oddeven"><td colspan="7" class="opacitymedium">'.$langs->trans('None').'</td></tr>';
print '</table></div></form>';
llxFooter();
