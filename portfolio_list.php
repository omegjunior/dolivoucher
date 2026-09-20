<?php
/* Copyright (C) 2026 Fred Omega Junior */
declare(strict_types=1);
$res = @include '../../main.inc.php';
if (!$res) die('Include of main fails');
require_once __DIR__.'/class/dolivoucherportfolio.class.php';
require_once __DIR__.'/lib/dolivoucher.lib.php';
$langs->load('dolivoucher@dolivoucher');
if (!isModEnabled('dolivoucher') || !$user->hasRight('dolivoucher', 'portfolio', 'read') || $user->socid > 0) accessforbidden();
$entity = (int) $conf->entity;
$searchRef = GETPOST('search_ref', 'alphanohtml');
$searchSoc = GETPOSTINT('search_fk_soc');
$searchType = GETPOST('search_type', 'alpha');
$searchPeriod = GETPOST('search_period', 'restricthtml');
$searchStatus = GETPOST('search_status', 'int');
$removeFilter = GETPOST('button_removefilter_x', 'alpha') || GETPOST('button_removefilter.x', 'alpha') || GETPOST('button_removefilter', 'alpha');
if ($removeFilter) {
	$searchRef = '';
	$searchSoc = 0;
	$searchType = '';
	$searchPeriod = '';
	$searchStatus = '';
}
$page = max(0, GETPOSTINT('page'));
$limit = min(100, max(1, GETPOSTINT('limit') ?: getDolGlobalInt('MAIN_SIZE_LISTE_LIMIT', 25)));
$sql = 'SELECT p.rowid, p.ref, p.label, p.type, p.fk_soc, p.period_label, p.status, p.available_unallocated_balance, s.nom AS socname';
$sql .= ' FROM '.$db->prefix().'dolivoucher_portfolio p LEFT JOIN '.$db->prefix().'societe s ON s.rowid=p.fk_soc WHERE p.entity='.$entity;
if ($searchRef !== '') $sql .= " AND p.ref LIKE '%".$db->escape($searchRef)."%'";
if ($searchSoc > 0) $sql .= ' AND p.fk_soc='.$searchSoc;
if ($searchType !== '') $sql .= " AND p.type='".$db->escape($searchType)."'";
if ($searchPeriod !== '') $sql .= " AND p.period_label LIKE '%".$db->escape($searchPeriod)."%'";
if ($searchStatus !== '') $sql .= ' AND p.status='.(int) $searchStatus;
$sql .= ' ORDER BY p.ref ASC'.$db->plimit($limit + 1, $page * $limit);
$resql = $db->query($sql);
llxHeader('', $langs->trans('Portfolios'));
print_barre_liste($langs->trans('Portfolios'), $page, $_SERVER['PHP_SELF'], '', '', '', '', -1, '', 'wallet');
print '<form method="GET" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'">';
print '<div class="div-table-responsive"><table class="tagtable liste">';
print '<tr class="liste_titre">';
print_liste_field_titre($langs->trans('Ref'), $_SERVER['PHP_SELF'], '', '', '', '', '');
print_liste_field_titre($langs->trans('Label'), $_SERVER['PHP_SELF'], '', '', '', '', '');
print_liste_field_titre($langs->trans('FundingThirdParty'), $_SERVER['PHP_SELF'], '', '', '', '', '');
print_liste_field_titre($langs->trans('Type'), $_SERVER['PHP_SELF'], '', '', '', '', '');
print_liste_field_titre($langs->trans('Period'), $_SERVER['PHP_SELF'], '', '', '', '', '');
print_liste_field_titre($langs->trans('Status'), $_SERVER['PHP_SELF'], '', '', '', '', '');
print_liste_field_titre($langs->trans('AvailableUnallocatedBalance'), $_SERVER['PHP_SELF'], '', '', '', 'align="right"', '');
print '</tr>';
print '<tr class="liste_titre_filter"><td class="liste_titre"><input class="flat maxwidth100" name="search_ref" value="'.dol_escape_htmltag($searchRef).'"></td><td class="liste_titre"></td><td class="liste_titre"><input class="flat maxwidth75" type="number" name="search_fk_soc" value="'.($searchSoc ?: '').'"></td><td class="liste_titre"><select class="flat" name="search_type"><option></option><option value="INSTITUTIONAL"'.($searchType === 'INSTITUTIONAL' ? ' selected' : '').'>'.$langs->trans('Institutional').'</option><option value="DONATION"'.($searchType === 'DONATION' ? ' selected' : '').'>'.$langs->trans('Donation').'</option></select></td><td class="liste_titre"><input class="flat maxwidth100" name="search_period" value="'.dol_escape_htmltag($searchPeriod).'"></td><td class="liste_titre"><select class="flat" name="search_status"><option value=""></option>';
foreach (dolivoucherPortfolioStatuses() as $key => $label) print '<option value="'.$key.'"'.((string) $searchStatus === (string) $key ? ' selected' : '').'>'.$langs->trans($label).'</option>';
print '</select></td><td class="liste_titre right"><button type="submit" class="liste_titre button_search" name="button_search" value="x"><span class="fa fa-search"></span></button> <button type="submit" class="liste_titre button_removefilter" name="button_removefilter_x" value="x"><span class="fa fa-remove"></span></button></td></tr>';
$count = 0;
while ($resql && ($row = $db->fetch_object($resql)) && $count < $limit) {
	$count++;
	print '<tr class="oddeven"><td><a href="portfolio_card.php?id='.(int) $row->rowid.'">'.dol_escape_htmltag($row->ref).'</a></td><td>'.dol_escape_htmltag($row->label).'</td><td>'.dol_escape_htmltag((string) $row->socname).'</td><td>'.$langs->trans($row->type === 'INSTITUTIONAL' ? 'Institutional' : 'Donation').'</td><td>'.dol_escape_htmltag((string) $row->period_label).'</td><td>'.dolivoucherStatusLabel($row->status).'</td><td class="right">'.price($row->available_unallocated_balance, 0, $langs, 1, -1, -1, 'XOF').'</td></tr>';
}
if (!$count) print '<tr class="oddeven"><td colspan="7" class="opacitymedium">'.$langs->trans('None').'</td></tr>';
print '</table></div></form>';
llxFooter();
