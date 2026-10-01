<?php
/* Copyright (C) 2026 Fred Omega Junior */
declare(strict_types=1);
$res = @include '../../main.inc.php';
if (!$res) die('Include of main fails');
require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
require_once __DIR__.'/class/dolivouchervoucher.class.php';
require_once __DIR__.'/lib/dolivoucher.lib.php';
$langs->load('dolivoucher@dolivoucher');
if (!isModEnabled('dolivoucher') || !$user->hasRight('dolivoucher', 'portfolio', 'read') || $user->socid > 0) accessforbidden();
$entity = (int) $conf->entity;
$form = new Form($db);
$object = new DoliVoucherVoucher($db);
$action = GETPOST('action', 'aZ09');
$contextpage = 'dolivouchervoucherlist';
$arrayfields = array(
	'v.ref' => array('label' => 'SerialNumber', 'checked' => '1', 'position' => 10),
	'v.barcode' => array('label' => 'Barcode', 'checked' => '1', 'position' => 20),
	'p.ref' => array('label' => 'Portfolio', 'checked' => '1', 'position' => 30),
	'v.initial_amount' => array('label' => 'InitialAmount', 'checked' => '1', 'position' => 40),
	'v.current_balance' => array('label' => 'CurrentBalance', 'checked' => '1', 'position' => 50),
	'v.status' => array('label' => 'Status', 'checked' => '1', 'position' => 60),
	'v.date_expiration' => array('label' => 'ExpirationDate', 'checked' => '1', 'position' => 70),
);
include DOL_DOCUMENT_ROOT.'/core/actions_changeselectedfields.inc.php';
$selectedfields = $form->multiSelectArrayWithCheckbox('selectedfields', $arrayfields, $contextpage);
$searchRef = GETPOST('search_ref', 'alphanohtml');
$searchBarcode = GETPOST('search_barcode', 'alphanohtml');
$searchPortfolio = GETPOSTINT('search_portfolio');
$searchStatus = GETPOST('search_status', 'int');
$searchBalance = GETPOST('search_balance', 'alphanohtml');
$withBalance = GETPOSTINT('with_balance');
$expired = GETPOSTINT('expired');
$searchStatus = ((int) $searchStatus === -1) ? '' : $searchStatus;
$searchExpirationDate = GETPOSTINT('search_expiration_dateyear') > 0 ? dol_mktime(0, 0, 0, GETPOSTINT('search_expiration_datemonth'), GETPOSTINT('search_expiration_dateday'), GETPOSTINT('search_expiration_dateyear')) : 0;
$removeFilter = GETPOST('button_removefilter_x', 'alpha') || GETPOST('button_removefilter.x', 'alpha') || GETPOST('button_removefilter', 'alpha');
if ($removeFilter) {
	$searchRef = '';
	$searchBarcode = '';
	$searchPortfolio = 0;
	$searchStatus = '';
	$searchBalance = '';
	$withBalance = 0;
	$expired = 0;
	$searchExpirationDate = 0;
}
$page = max(0, GETPOSTINT('page'));
$limit = max(1, GETPOSTINT('limit') ?: getDolGlobalInt('MAIN_SIZE_LISTE_LIMIT', 25));
$offset = $page * $limit;
$param = '&limit='.$limit;
if ($searchRef !== '') $param .= '&search_ref='.urlencode($searchRef);
if ($searchBarcode !== '') $param .= '&search_barcode='.urlencode($searchBarcode);
if ($searchPortfolio > 0) $param .= '&search_portfolio='.$searchPortfolio;
if ($searchStatus !== '') $param .= '&search_status='.(int) $searchStatus;
if ($searchBalance !== '') $param .= '&search_balance='.urlencode($searchBalance);
if ($withBalance) $param .= '&with_balance=1';
if ($expired) $param .= '&expired=1';
if ($searchExpirationDate > 0) {
	$param .= '&search_expiration_dateday='.dol_print_date($searchExpirationDate, '%d');
	$param .= '&search_expiration_datemonth='.dol_print_date($searchExpirationDate, '%m');
	$param .= '&search_expiration_dateyear='.dol_print_date($searchExpirationDate, '%Y');
}
$sql = 'SELECT v.rowid, v.ref, v.barcode, v.initial_amount, v.current_balance, v.status, v.date_expiration, p.rowid AS portfolio_id, p.ref AS portfolio_ref';
$sql .= ' FROM '.$db->prefix().'dolivoucher_voucher v INNER JOIN '.$db->prefix().'dolivoucher_portfolio p ON p.rowid=v.fk_portfolio AND p.entity=v.entity WHERE v.entity='.$entity;
if ($searchRef !== '') $sql .= " AND v.ref LIKE '%".$db->escape($searchRef)."%'";
if ($searchBarcode !== '') $sql .= " AND v.barcode LIKE '%".$db->escape($searchBarcode)."%'";
if ($searchPortfolio > 0) $sql .= ' AND v.fk_portfolio='.$searchPortfolio;
if ($searchStatus !== '') $sql .= ' AND v.status='.(int) $searchStatus;
if ($searchBalance !== '') $sql .= natural_search('v.current_balance', $searchBalance, 1);
if ($withBalance) $sql .= ' AND v.current_balance>0';
if ($expired) $sql .= ' AND v.date_expiration IS NOT NULL AND v.date_expiration<NOW()';
if ($searchExpirationDate > 0) {
	$sql .= " AND v.date_expiration>='".$db->idate($searchExpirationDate)."'";
	$sql .= " AND v.date_expiration<='".$db->idate(dol_time_plus_duree($searchExpirationDate, 1, 'd') - 1)."'";
}
$sql .= ' ORDER BY v.ref ASC'.$db->plimit($limit + 1, $offset);
$resql = $db->query($sql);
$num = $resql ? $db->num_rows($resql) : 0;
llxHeader('', $langs->trans('Vouchers'));
print '<form method="POST" id="searchFormList" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="formfilteraction" id="formfilteraction" value="list">';
print_barre_liste($langs->trans('Vouchers'), $page, $_SERVER['PHP_SELF'], $param, '', '', '', $num, '', 'ticket', 0, '', '', $limit);
print '<div class="div-table-responsive"><table class="tagtable liste">';
$voucherStatusOptions = array();
foreach (dolivoucherVoucherStatuses() as $key => $label) {
	$voucherStatusOptions[$key] = $langs->trans($label);
}
print '<tr class="liste_titre_filter">';
if (!empty($arrayfields['v.ref']['checked'])) print '<td class="liste_titre"><input class="flat maxwidth100" name="search_ref" value="'.dol_escape_htmltag($searchRef).'"></td>';
if (!empty($arrayfields['v.barcode']['checked'])) print '<td class="liste_titre"><input class="flat maxwidth100" name="search_barcode" value="'.dol_escape_htmltag($searchBarcode).'"></td>';
if (!empty($arrayfields['p.ref']['checked'])) print '<td class="liste_titre"><input class="flat maxwidth75" type="number" name="search_portfolio" value="'.($searchPortfolio ?: '').'"></td>';
if (!empty($arrayfields['v.initial_amount']['checked'])) print '<td class="liste_titre"></td>';
if (!empty($arrayfields['v.current_balance']['checked'])) print '<td class="liste_titre nowrap"><input class="flat maxwidth75" type="text" name="search_balance" value="'.dol_escape_htmltag($searchBalance).'"> <label><input type="checkbox" name="with_balance" value="1"'.($withBalance ? ' checked' : '').'> '.$langs->trans('WithBalance').'</label></td>';
if (!empty($arrayfields['v.status']['checked'])) print '<td class="liste_titre">'.$form->selectarray('search_status', $voucherStatusOptions, $searchStatus, 1, 0, 0, '', 0, 0, 0, '', 'maxwidth150').'</td>';
if (!empty($arrayfields['v.date_expiration']['checked'])) print '<td class="liste_titre nowrap"><label><input type="checkbox" name="expired" value="1"'.($expired ? ' checked' : '').'> '.$langs->trans('Expired').'</label> '.$form->selectDate($searchExpirationDate ?: -1, 'search_expiration_date', 0, 0, 1, '', 1, 0, 0, '', '', '', '', 1, '', $langs->trans('ExpirationDate')).'</td>';
print '<td class="liste_titre center maxwidthsearch actioncolumn"><button type="submit" class="liste_titre button_search" name="button_search" value="x"><span class="fas fa-search"></span></button> <button type="submit" class="liste_titre button_removefilter reposition" name="button_removefilter_x" value="x"><span class="fas fa-times"></span></button></td></tr>';
print '<tr class="liste_titre">';
if (!empty($arrayfields['v.ref']['checked'])) print_liste_field_titre($langs->trans($arrayfields['v.ref']['label']), $_SERVER['PHP_SELF'], '', '', $param, '', '');
if (!empty($arrayfields['v.barcode']['checked'])) print_liste_field_titre($langs->trans($arrayfields['v.barcode']['label']), $_SERVER['PHP_SELF'], '', '', $param, '', '');
if (!empty($arrayfields['p.ref']['checked'])) print_liste_field_titre($langs->trans($arrayfields['p.ref']['label']), $_SERVER['PHP_SELF'], '', '', $param, '', '');
if (!empty($arrayfields['v.initial_amount']['checked'])) print_liste_field_titre($langs->trans($arrayfields['v.initial_amount']['label']), $_SERVER['PHP_SELF'], '', '', $param, 'align="right"', '');
if (!empty($arrayfields['v.current_balance']['checked'])) print_liste_field_titre($langs->trans($arrayfields['v.current_balance']['label']), $_SERVER['PHP_SELF'], '', '', $param, 'align="right"', '');
if (!empty($arrayfields['v.status']['checked'])) print_liste_field_titre($langs->trans($arrayfields['v.status']['label']), $_SERVER['PHP_SELF'], '', '', $param, '', '');
if (!empty($arrayfields['v.date_expiration']['checked'])) print_liste_field_titre($langs->trans($arrayfields['v.date_expiration']['label']), $_SERVER['PHP_SELF'], '', '', $param, '', '');
print_liste_field_titre($selectedfields, $_SERVER['PHP_SELF'], '', '', $param, '', '', '', 'maxwidthsearch center ');
print '</tr>';
$count = 0;
while ($resql && ($row = $db->fetch_object($resql)) && $count < $limit) {
	$count++;
	print '<tr class="oddeven">';
	if (!empty($arrayfields['v.ref']['checked'])) print '<td><a href="voucher_card.php?id='.(int) $row->rowid.'">'.dol_escape_htmltag($row->ref).'</a></td>';
	if (!empty($arrayfields['v.barcode']['checked'])) print '<td>'.dol_escape_htmltag((string) $row->barcode).'</td>';
	if (!empty($arrayfields['p.ref']['checked'])) print '<td><a href="portfolio_card.php?id='.(int) $row->portfolio_id.'">'.dol_escape_htmltag($row->portfolio_ref).'</a></td>';
	if (!empty($arrayfields['v.initial_amount']['checked'])) print '<td class="right">'.price($row->initial_amount, 0, $langs, 1, -1, -1, 'XOF').'</td>';
	if (!empty($arrayfields['v.current_balance']['checked'])) print '<td class="right">'.price($row->current_balance, 0, $langs, 1, -1, -1, 'XOF').'</td>';
	if (!empty($arrayfields['v.status']['checked'])) print '<td>'.dolivoucherStatusLabel($row->status, true).'</td>';
	if (!empty($arrayfields['v.date_expiration']['checked'])) print '<td>'.dol_print_date($db->jdate($row->date_expiration), 'dayhour').'</td>';
	print '<td></td></tr>';
}
$columnCount = 1;
foreach ($arrayfields as $field) if (!empty($field['checked'])) $columnCount++;
if (!$count) print '<tr class="oddeven"><td colspan="'.$columnCount.'" class="opacitymedium">'.$langs->trans('None').'</td></tr>';
print '</table></div></form>';
llxFooter();
