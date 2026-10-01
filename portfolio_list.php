<?php
/* Copyright (C) 2026 Fred Omega Junior */
declare(strict_types=1);
$res = @include '../../main.inc.php';
if (!$res) die('Include of main fails');
require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
require_once __DIR__.'/class/dolivoucherportfolio.class.php';
require_once __DIR__.'/lib/dolivoucher.lib.php';
$langs->load('dolivoucher@dolivoucher');
if (!isModEnabled('dolivoucher') || !$user->hasRight('dolivoucher', 'portfolio', 'read') || $user->socid > 0) accessforbidden();
$entity = (int) $conf->entity;
$form = new Form($db);
$object = new DoliVoucherPortfolio($db);
$action = GETPOST('action', 'aZ09');
$contextpage = 'dolivoucherportfoliolist';
$arrayfields = array(
	'p.ref' => array('label' => 'Ref', 'checked' => '1', 'position' => 10),
	'p.label' => array('label' => 'Label', 'checked' => '1', 'position' => 20),
	's.nom' => array('label' => 'FundingThirdParty', 'checked' => '1', 'position' => 30),
	'p.type' => array('label' => 'Type', 'checked' => '1', 'position' => 40),
	'p.period_label' => array('label' => 'Period', 'checked' => '1', 'position' => 50),
	'p.status' => array('label' => 'Status', 'checked' => '1', 'position' => 60),
	'p.available_unallocated_balance' => array('label' => 'AvailableUnallocatedBalance', 'checked' => '1', 'position' => 70),
);
include DOL_DOCUMENT_ROOT.'/core/actions_changeselectedfields.inc.php';
$selectedfields = $form->multiSelectArrayWithCheckbox('selectedfields', $arrayfields, $contextpage);
$searchRef = GETPOST('search_ref', 'alphanohtml');
$searchSoc = GETPOSTINT('search_fk_soc');
$searchType = GETPOST('search_type', 'alpha');
$searchPeriod = GETPOST('search_period', 'restricthtml');
$searchStatus = GETPOST('search_status', 'int');
$searchType = $searchType === '-1' ? '' : $searchType;
$searchStatus = ((int) $searchStatus === -1) ? '' : $searchStatus;
$removeFilter = GETPOST('button_removefilter_x', 'alpha') || GETPOST('button_removefilter.x', 'alpha') || GETPOST('button_removefilter', 'alpha');
if ($removeFilter) {
	$searchRef = '';
	$searchSoc = 0;
	$searchType = '';
	$searchPeriod = '';
	$searchStatus = '';
}
$page = max(0, GETPOSTINT('page'));
$limit = max(1, GETPOSTINT('limit') ?: getDolGlobalInt('MAIN_SIZE_LISTE_LIMIT', 25));
$offset = $page * $limit;
$param = '&limit='.$limit;
if ($searchRef !== '') $param .= '&search_ref='.urlencode($searchRef);
if ($searchSoc > 0) $param .= '&search_fk_soc='.$searchSoc;
if ($searchType !== '') $param .= '&search_type='.urlencode($searchType);
if ($searchPeriod !== '') $param .= '&search_period='.urlencode($searchPeriod);
if ($searchStatus !== '') $param .= '&search_status='.(int) $searchStatus;
$sql = 'SELECT p.rowid, p.ref, p.label, p.type, p.fk_soc, p.period_label, p.status, p.available_unallocated_balance, s.nom AS socname';
$sql .= ' FROM '.$db->prefix().'dolivoucher_portfolio p LEFT JOIN '.$db->prefix().'societe s ON s.rowid=p.fk_soc WHERE p.entity='.$entity;
if ($searchRef !== '') $sql .= " AND p.ref LIKE '%".$db->escape($searchRef)."%'";
if ($searchSoc > 0) $sql .= ' AND p.fk_soc='.$searchSoc;
if ($searchType !== '') $sql .= " AND p.type='".$db->escape($searchType)."'";
if ($searchPeriod !== '') $sql .= " AND p.period_label LIKE '%".$db->escape($searchPeriod)."%'";
if ($searchStatus !== '') $sql .= ' AND p.status='.(int) $searchStatus;
$sql .= ' ORDER BY p.ref ASC'.$db->plimit($limit + 1, $offset);
$resql = $db->query($sql);
$num = $resql ? $db->num_rows($resql) : 0;
llxHeader('', $langs->trans('Portfolios'));
print '<form method="POST" id="searchFormList" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="formfilteraction" id="formfilteraction" value="list">';
print_barre_liste($langs->trans('Portfolios'), $page, $_SERVER['PHP_SELF'], $param, '', '', '', $num, '', 'wallet', 0, '', '', $limit);
print '<div class="div-table-responsive"><table class="tagtable liste">';
$portfolioTypeOptions = array(
	DoliVoucherPortfolio::TYPE_INSTITUTIONAL => $langs->trans('Institutional'),
	DoliVoucherPortfolio::TYPE_DONATION => $langs->trans('Donation'),
);
$portfolioStatusOptions = array();
foreach (dolivoucherPortfolioStatuses() as $key => $label) {
	$portfolioStatusOptions[$key] = $langs->trans($label);
}
print '<tr class="liste_titre_filter">';
if (!empty($arrayfields['p.ref']['checked'])) print '<td class="liste_titre"><input class="flat maxwidth100" name="search_ref" value="'.dol_escape_htmltag($searchRef).'"></td>';
if (!empty($arrayfields['p.label']['checked'])) print '<td class="liste_titre"></td>';
if (!empty($arrayfields['s.nom']['checked'])) print '<td class="liste_titre"><input class="flat maxwidth75" type="number" name="search_fk_soc" value="'.($searchSoc ?: '').'"></td>';
if (!empty($arrayfields['p.type']['checked'])) print '<td class="liste_titre">'.$form->selectarray('search_type', $portfolioTypeOptions, $searchType, 1, 0, 0, '', 0, 0, 0, '', 'maxwidth150').'</td>';
if (!empty($arrayfields['p.period_label']['checked'])) print '<td class="liste_titre"><input class="flat maxwidth100" name="search_period" value="'.dol_escape_htmltag($searchPeriod).'"></td>';
if (!empty($arrayfields['p.status']['checked'])) print '<td class="liste_titre">'.$form->selectarray('search_status', $portfolioStatusOptions, $searchStatus, 1, 0, 0, '', 0, 0, 0, '', 'maxwidth150').'</td>';
if (!empty($arrayfields['p.available_unallocated_balance']['checked'])) print '<td class="liste_titre"></td>';
print '<td class="liste_titre center maxwidthsearch actioncolumn"><button type="submit" class="liste_titre button_search" name="button_search" value="x"><span class="fas fa-search"></span></button> <button type="submit" class="liste_titre button_removefilter reposition" name="button_removefilter_x" value="x"><span class="fas fa-times"></span></button></td></tr>';
print '<tr class="liste_titre">';
if (!empty($arrayfields['p.ref']['checked'])) print_liste_field_titre($langs->trans($arrayfields['p.ref']['label']), $_SERVER['PHP_SELF'], '', '', $param, '', '');
if (!empty($arrayfields['p.label']['checked'])) print_liste_field_titre($langs->trans($arrayfields['p.label']['label']), $_SERVER['PHP_SELF'], '', '', $param, '', '');
if (!empty($arrayfields['s.nom']['checked'])) print_liste_field_titre($langs->trans($arrayfields['s.nom']['label']), $_SERVER['PHP_SELF'], '', '', $param, '', '');
if (!empty($arrayfields['p.type']['checked'])) print_liste_field_titre($langs->trans($arrayfields['p.type']['label']), $_SERVER['PHP_SELF'], '', '', $param, '', '');
if (!empty($arrayfields['p.period_label']['checked'])) print_liste_field_titre($langs->trans($arrayfields['p.period_label']['label']), $_SERVER['PHP_SELF'], '', '', $param, '', '');
if (!empty($arrayfields['p.status']['checked'])) print_liste_field_titre($langs->trans($arrayfields['p.status']['label']), $_SERVER['PHP_SELF'], '', '', $param, '', '');
if (!empty($arrayfields['p.available_unallocated_balance']['checked'])) print_liste_field_titre($langs->trans($arrayfields['p.available_unallocated_balance']['label']), $_SERVER['PHP_SELF'], '', '', $param, 'align="right"', '');
print_liste_field_titre($selectedfields, $_SERVER['PHP_SELF'], '', '', $param, '', '', '', 'maxwidthsearch center ');
print '</tr>';
$count = 0;
while ($resql && ($row = $db->fetch_object($resql)) && $count < $limit) {
	$count++;
	print '<tr class="oddeven">';
	if (!empty($arrayfields['p.ref']['checked'])) print '<td><a href="portfolio_card.php?id='.(int) $row->rowid.'">'.dol_escape_htmltag($row->ref).'</a></td>';
	if (!empty($arrayfields['p.label']['checked'])) print '<td>'.dol_escape_htmltag($row->label).'</td>';
	if (!empty($arrayfields['s.nom']['checked'])) print '<td>'.dol_escape_htmltag((string) $row->socname).'</td>';
	if (!empty($arrayfields['p.type']['checked'])) print '<td>'.$langs->trans($row->type === 'INSTITUTIONAL' ? 'Institutional' : 'Donation').'</td>';
	if (!empty($arrayfields['p.period_label']['checked'])) print '<td>'.dol_escape_htmltag((string) $row->period_label).'</td>';
	if (!empty($arrayfields['p.status']['checked'])) print '<td>'.dolivoucherStatusLabel($row->status).'</td>';
	if (!empty($arrayfields['p.available_unallocated_balance']['checked'])) print '<td class="right">'.price($row->available_unallocated_balance, 0, $langs, 1, -1, -1, 'XOF').'</td>';
	print '<td></td></tr>';
}
$columnCount = 1;
foreach ($arrayfields as $field) if (!empty($field['checked'])) $columnCount++;
if (!$count) print '<tr class="oddeven"><td colspan="'.$columnCount.'" class="opacitymedium">'.$langs->trans('None').'</td></tr>';
print '</table></div></form>';
llxFooter();
