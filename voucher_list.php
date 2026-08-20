<?php
/* Copyright (C) 2026 Fred Omega Junior */
declare(strict_types=1);
$res = @include '../../main.inc.php';
if (!$res) die('Include of main fails');
require_once __DIR__.'/class/dolivouchervoucher.class.php';
require_once __DIR__.'/lib/dolivoucher.lib.php';
$langs->load('dolivoucher@dolivoucher');
if (!isModEnabled('dolivoucher') || !$user->hasRight('dolivoucher', 'portfolio', 'read') || $user->socid > 0) accessforbidden();
$entity = (int) $conf->entity;
$searchRef = GETPOST('search_ref', 'alphanohtml');
$searchBarcode = GETPOST('search_barcode', 'alphanohtml');
$searchPortfolio = GETPOSTINT('search_portfolio');
$searchStatus = GETPOST('search_status', 'int');
$withBalance = GETPOSTINT('with_balance');
$expired = GETPOSTINT('expired');
$dateFrom = GETPOST('date_from', 'date');
$removeFilter = GETPOST('button_removefilter_x', 'alpha') || GETPOST('button_removefilter.x', 'alpha') || GETPOST('button_removefilter', 'alpha');
if ($removeFilter) {
	$searchRef = '';
	$searchBarcode = '';
	$searchPortfolio = 0;
	$searchStatus = '';
	$withBalance = 0;
	$expired = 0;
	$dateFrom = '';
}
$page = max(0, GETPOSTINT('page'));
$limit = min(100, max(1, GETPOSTINT('limit') ?: getDolGlobalInt('MAIN_SIZE_LISTE_LIMIT', 25)));
$sql = 'SELECT v.rowid, v.ref, v.barcode, v.initial_amount, v.current_balance, v.status, v.date_expiration, p.rowid AS portfolio_id, p.ref AS portfolio_ref';
$sql .= ' FROM '.$db->prefix().'dolivoucher_voucher v INNER JOIN '.$db->prefix().'dolivoucher_portfolio p ON p.rowid=v.fk_portfolio AND p.entity=v.entity WHERE v.entity='.$entity;
if ($searchRef !== '') $sql .= " AND v.ref LIKE '%".$db->escape($searchRef)."%'";
if ($searchBarcode !== '') $sql .= " AND v.barcode LIKE '%".$db->escape($searchBarcode)."%'";
if ($searchPortfolio > 0) $sql .= ' AND v.fk_portfolio='.$searchPortfolio;
if ($searchStatus !== '') $sql .= ' AND v.status='.(int) $searchStatus;
if ($withBalance) $sql .= ' AND v.current_balance>0';
if ($expired) $sql .= ' AND v.date_expiration IS NOT NULL AND v.date_expiration<NOW()';
if ($dateFrom !== '') $sql .= " AND v.date_creation>='".$db->escape($dateFrom)." 00:00:00'";
$sql .= ' ORDER BY v.ref ASC'.$db->plimit($limit + 1, $page * $limit);
$resql = $db->query($sql);
llxHeader('', $langs->trans('Vouchers'));
print_barre_liste($langs->trans('Vouchers'), $page, $_SERVER['PHP_SELF'], '', '', '', '', -1, '', 'ticket');
print '<form method="GET" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'"><div class="div-table-responsive"><table class="tagtable liste"><tr class="liste_titre">';
print_liste_field_titre($langs->trans('SerialNumber'), $_SERVER['PHP_SELF'], '', '', '', '', '');
print_liste_field_titre($langs->trans('Barcode'), $_SERVER['PHP_SELF'], '', '', '', '', '');
print_liste_field_titre($langs->trans('Portfolio'), $_SERVER['PHP_SELF'], '', '', '', '', '');
print_liste_field_titre($langs->trans('InitialAmount'), $_SERVER['PHP_SELF'], '', '', '', 'align="right"', '');
print_liste_field_titre($langs->trans('CurrentBalance'), $_SERVER['PHP_SELF'], '', '', '', 'align="right"', '');
print_liste_field_titre($langs->trans('Status'), $_SERVER['PHP_SELF'], '', '', '', '', '');
print_liste_field_titre($langs->trans('ExpirationDate'), $_SERVER['PHP_SELF'], '', '', '', '', '');
print '</tr>';
print '<tr class="liste_titre_filter"><td class="liste_titre"><input class="flat maxwidth100" name="search_ref" value="'.dol_escape_htmltag($searchRef).'"></td><td class="liste_titre"><input class="flat maxwidth100" name="search_barcode" value="'.dol_escape_htmltag($searchBarcode).'"></td><td class="liste_titre"><input class="flat maxwidth75" type="number" name="search_portfolio" value="'.($searchPortfolio ?: '').'"></td><td class="liste_titre" colspan="2"><label><input type="checkbox" name="with_balance" value="1"'.($withBalance ? ' checked' : '').'> '.$langs->trans('WithBalance').'</label></td><td class="liste_titre"><select class="flat" name="search_status"><option value=""></option>';
foreach (dolivoucherVoucherStatuses() as $key => $label) print '<option value="'.$key.'"'.((string) $searchStatus === (string) $key ? ' selected' : '').'>'.$langs->trans($label).'</option>';
print '</select></td><td class="liste_titre nowrap"><label><input type="checkbox" name="expired" value="1"'.($expired ? ' checked' : '').'> '.$langs->trans('Expired').'</label> <input class="flat" type="date" name="date_from" value="'.dol_escape_htmltag($dateFrom).'"> <button type="submit" class="liste_titre button_search" name="button_search" value="x"><span class="fa fa-search"></span></button> <button type="submit" class="liste_titre button_removefilter" name="button_removefilter_x" value="x"><span class="fa fa-remove"></span></button></td></tr>';
$count = 0;
while ($resql && ($row = $db->fetch_object($resql)) && $count < $limit) {
	$count++;
	print '<tr class="oddeven"><td><a href="voucher_card.php?id='.(int) $row->rowid.'">'.dol_escape_htmltag($row->ref).'</a></td><td>'.dol_escape_htmltag((string) $row->barcode).'</td><td><a href="portfolio_card.php?id='.(int) $row->portfolio_id.'">'.dol_escape_htmltag($row->portfolio_ref).'</a></td><td class="right">'.price($row->initial_amount, 0, $langs, 1, -1, -1, 'XOF').'</td><td class="right">'.price($row->current_balance, 0, $langs, 1, -1, -1, 'XOF').'</td><td>'.dolivoucherStatusLabel($row->status, true).'</td><td>'.dol_print_date($db->jdate($row->date_expiration), 'dayhour').'</td></tr>';
}
if (!$count) print '<tr class="oddeven"><td colspan="7" class="opacitymedium">'.$langs->trans('None').'</td></tr>';
print '</table></div></form>';
llxFooter();
