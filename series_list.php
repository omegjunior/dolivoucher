<?php
/* Copyright (C) 2026 Fred Omega Junior */
declare(strict_types=1);
$res = @include '../../main.inc.php';
if (!$res) die('Include of main fails');
require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
require_once __DIR__.'/class/dolivoucherseries.class.php';
require_once __DIR__.'/lib/dolivoucher.lib.php';
$langs->load('dolivoucher@dolivoucher');
if (!isModEnabled('dolivoucher') || !$user->hasRight('dolivoucher', 'series', 'read') || $user->socid > 0) accessforbidden();
$entity = (int) $conf->entity;
$form = new Form($db);
$action = GETPOST('action', 'aZ09');
$contextpage = 'dolivoucherserieslist';
$arrayfields = array(
	's.ref' => array('label' => 'Ref', 'checked' => '1', 'position' => 10),
	'p.ref' => array('label' => 'Portfolio', 'checked' => '1', 'position' => 20),
	's.label' => array('label' => 'Label', 'checked' => '1', 'position' => 30),
	's.quantity' => array('label' => 'Quantity', 'checked' => '1', 'position' => 40),
	's.face_value' => array('label' => 'FaceValue', 'checked' => '1', 'position' => 50),
	's.printed_count' => array('label' => 'PrintCoverage', 'checked' => '1', 'position' => 60),
	's.status' => array('label' => 'Status', 'checked' => '1', 'position' => 70),
	's.date_generation' => array('label' => 'GenerationDate', 'checked' => '1', 'position' => 80),
);
include DOL_DOCUMENT_ROOT.'/core/actions_changeselectedfields.inc.php';
$selectedfields = $form->multiSelectArrayWithCheckbox('selectedfields', $arrayfields, $contextpage);
$sortFields = array_fill_keys(array_keys($arrayfields), true);
$sortfield = GETPOST('sortfield', 'alphanohtml');
if (!isset($sortFields[$sortfield])) $sortfield = 's.rowid';
$sortorder = strtoupper(GETPOST('sortorder', 'alpha'));
if (!in_array($sortorder, array('ASC', 'DESC'), true)) $sortorder = 'DESC';
$searchRef = GETPOST('search_ref', 'alphanohtml');
$searchPortfolio = GETPOSTINT('search_portfolio');
$searchLabel = GETPOST('search_label', 'restricthtml');
$searchStatus = GETPOST('search_status', 'int');
$searchStatus = ((int) $searchStatus === -1) ? '' : $searchStatus;
$searchGenerationDate = GETPOSTINT('search_generation_dateyear') > 0 ? dol_mktime(0, 0, 0, GETPOSTINT('search_generation_datemonth'), GETPOSTINT('search_generation_dateday'), GETPOSTINT('search_generation_dateyear')) : 0;
if (GETPOST('button_removefilter_x', 'alpha') || GETPOST('button_removefilter.x', 'alpha') || GETPOST('button_removefilter', 'alpha')) {
	$searchRef = $searchLabel = $searchStatus = '';
	$searchPortfolio = 0;
	$searchGenerationDate = 0;
}
$page = max(0, GETPOSTINT('page'));
$limit = max(1, GETPOSTINT('limit') ?: getDolGlobalInt('MAIN_SIZE_LISTE_LIMIT', 25));
$offset = $page * $limit;
$param = '&limit='.$limit;
foreach (array('search_ref' => $searchRef, 'search_label' => $searchLabel, 'search_status' => $searchStatus) as $key => $value) if ($value !== '') $param .= '&'.$key.'='.urlencode((string) $value);
if ($searchPortfolio > 0) $param .= '&search_portfolio='.$searchPortfolio;
if ($searchGenerationDate > 0) {
	$param .= '&search_generation_dateday='.dol_print_date($searchGenerationDate, '%d');
	$param .= '&search_generation_datemonth='.dol_print_date($searchGenerationDate, '%m');
	$param .= '&search_generation_dateyear='.dol_print_date($searchGenerationDate, '%Y');
}
$portfolioOptions = array();
$sqlPortfolios = 'SELECT rowid, ref, label FROM '.$db->prefix().'dolivoucher_portfolio WHERE entity='.$entity.' ORDER BY ref ASC';
$resqlPortfolios = $db->query($sqlPortfolios);
while ($resqlPortfolios && ($portfolio = $db->fetch_object($resqlPortfolios))) {
	$portfolioOptions[(int) $portfolio->rowid] = $portfolio->ref.((string) $portfolio->label !== '' ? ' - '.$portfolio->label : '');
}
$sql = 'SELECT s.rowid, s.ref, s.label, s.quantity, s.face_value, s.generated_count, s.printed_count, s.status, s.date_generation, p.rowid AS portfolio_id, p.ref AS portfolio_ref';
$sql .= ' FROM '.$db->prefix().'dolivoucher_series s INNER JOIN '.$db->prefix().'dolivoucher_portfolio p ON p.rowid=s.fk_portfolio AND p.entity=s.entity WHERE s.entity='.$entity;
if ($searchRef !== '') $sql .= " AND s.ref LIKE '%".$db->escape($searchRef)."%'";
if ($searchPortfolio > 0) $sql .= ' AND s.fk_portfolio='.$searchPortfolio;
if ($searchLabel !== '') $sql .= " AND s.label LIKE '%".$db->escape($searchLabel)."%'";
if ($searchStatus !== '') $sql .= ' AND s.status='.(int) $searchStatus;
if ($searchGenerationDate > 0) {
	$sql .= " AND s.date_generation>='".$db->idate($searchGenerationDate)."'";
	$sql .= " AND s.date_generation<='".$db->idate(dol_time_plus_duree($searchGenerationDate, 1, 'd') - 1)."'";
}
$sql .= ' ORDER BY '.$sortfield.' '.$sortorder.$db->plimit($limit + 1, $offset);
$resql = $db->query($sql);
$num = $resql ? $db->num_rows($resql) : 0;
llxHeader('', $langs->trans('VoucherSeries'));
print '<form method="POST" id="searchFormList" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'">';
print '<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="formfilteraction" value="list">';
print '<input type="hidden" name="sortfield" value="'.dol_escape_htmltag($sortfield).'"><input type="hidden" name="sortorder" value="'.dol_escape_htmltag($sortorder).'">';
print_barre_liste($langs->trans('VoucherSeries'), $page, $_SERVER['PHP_SELF'], $param, $sortfield, $sortorder, '', $num, '', 'fontawesome_layer-group', 0, '', '', $limit);
print '<div class="div-table-responsive"><table class="tagtable liste"><tr class="liste_titre_filter">';
if (!empty($arrayfields['s.ref']['checked'])) print '<td class="liste_titre"><input class="flat maxwidth100" name="search_ref" value="'.dol_escape_htmltag($searchRef).'"></td>';
if (!empty($arrayfields['p.ref']['checked'])) print '<td class="liste_titre">'.$form->selectarray('search_portfolio', $portfolioOptions, $searchPortfolio, 1, 0, 0, '', 0, 0, 0, '', 'maxwidth200').'</td>';
if (!empty($arrayfields['s.label']['checked'])) print '<td class="liste_titre"><input class="flat maxwidth150" name="search_label" value="'.dol_escape_htmltag($searchLabel).'"></td>';
if (!empty($arrayfields['s.quantity']['checked'])) print '<td class="liste_titre"></td>';
if (!empty($arrayfields['s.face_value']['checked'])) print '<td class="liste_titre"></td>';
if (!empty($arrayfields['s.printed_count']['checked'])) print '<td class="liste_titre"></td>';
$statusOptions = array(); foreach (dolivoucherSeriesStatuses() as $key => $label) $statusOptions[$key] = $langs->trans($label);
if (!empty($arrayfields['s.status']['checked'])) print '<td class="liste_titre">'.$form->selectarray('search_status', $statusOptions, $searchStatus, 1, 0, 0, '', 0, 0, 0, '', 'maxwidth150').'</td>';
if (!empty($arrayfields['s.date_generation']['checked'])) print '<td class="liste_titre nowrap">'.$form->selectDate($searchGenerationDate ?: -1, 'search_generation_date', 0, 0, 1, '', 1, 0, 0, '', '', '', '', 1, '', $langs->trans('GenerationDate')).'</td>';
print '<td class="liste_titre center maxwidthsearch actioncolumn"><button type="submit" class="liste_titre button_search" name="button_search" value="x"><span class="fas fa-search"></span></button> <button type="submit" class="liste_titre button_removefilter reposition" name="button_removefilter_x" value="x"><span class="fas fa-times"></span></button></td></tr><tr class="liste_titre">';
foreach ($arrayfields as $key => $field) if (!empty($field['checked'])) print_liste_field_titre($langs->trans($field['label']), $_SERVER['PHP_SELF'], $key, '', $param, in_array($key, array('s.quantity', 's.face_value'), true) ? 'align="right"' : '', $sortfield, $sortorder);
print_liste_field_titre($selectedfields, $_SERVER['PHP_SELF'], '', '', $param, '', '', '', 'maxwidthsearch center ');
print '</tr>';
$count = 0;
while ($resql && ($row = $db->fetch_object($resql)) && $count < $limit) {
	$count++;
	print '<tr class="oddeven">';
	if (!empty($arrayfields['s.ref']['checked'])) print '<td><a href="series_card.php?id='.(int) $row->rowid.'">'.dol_escape_htmltag($row->ref).'</a></td>';
	if (!empty($arrayfields['p.ref']['checked'])) print '<td><a href="portfolio_card.php?id='.(int) $row->portfolio_id.'">'.dol_escape_htmltag($row->portfolio_ref).'</a></td>';
	if (!empty($arrayfields['s.label']['checked'])) print '<td>'.dol_escape_htmltag((string) $row->label).'</td>';
	if (!empty($arrayfields['s.quantity']['checked'])) print '<td class="right">'.(int) $row->quantity.'</td>';
	if (!empty($arrayfields['s.face_value']['checked'])) print '<td class="right">'.price($row->face_value, 0, $langs, 1, -1, -1, getDolGlobalString('MAIN_MONNAIE', 'XOF')).'</td>';
	if (!empty($arrayfields['s.printed_count']['checked'])) print '<td>'.(int) $row->printed_count.' / '.(int) $row->generated_count.'</td>';
	if (!empty($arrayfields['s.status']['checked'])) print '<td>'.$langs->trans(dolivoucherSeriesStatuses()[(int) $row->status] ?? 'Unknown').'</td>';
	if (!empty($arrayfields['s.date_generation']['checked'])) print '<td>'.dol_print_date($db->jdate($row->date_generation), 'dayhour').'</td>';
	print '<td></td></tr>';
}
$columnCount = 1; foreach ($arrayfields as $field) if (!empty($field['checked'])) $columnCount++;
if (!$count) print '<tr class="oddeven"><td colspan="'.$columnCount.'" class="opacitymedium">'.$langs->trans('None').'</td></tr>';
print '</table></div></form>';
llxFooter();
