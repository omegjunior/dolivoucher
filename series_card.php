<?php
/* Copyright (C) 2026 Fred Omega Junior */
declare(strict_types=1);
$res = @include '../../main.inc.php';
if (!$res) die('Include of main fails');
require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
require_once __DIR__.'/class/dolivoucherseries.class.php';
require_once __DIR__.'/class/dolivoucherseriesservice.class.php';
require_once __DIR__.'/lib/dolivoucher.lib.php';
$langs->loadLangs(array('dolivoucher@dolivoucher', 'companies'));
if (!isModEnabled('dolivoucher') || !$user->hasRight('dolivoucher', 'series', 'read') || $user->socid > 0) accessforbidden();
$id = GETPOSTINT('id');
$action = GETPOST('action', 'aZ09');
$entity = (int) $conf->entity;
$object = new DoliVoucherSeries($db);
$service = new DoliVoucherSeriesService($db);
$form = new Form($db);
$portfolioOptions = array();
$sqlPortfolios = 'SELECT rowid, ref, label FROM '.$db->prefix().'dolivoucher_portfolio WHERE entity='.$entity.' AND status IN (1,2) ORDER BY ref ASC';
$resqlPortfolios = $db->query($sqlPortfolios);
while ($resqlPortfolios && ($portfolio = $db->fetch_object($resqlPortfolios))) {
	$portfolioOptions[(int) $portfolio->rowid] = $portfolio->ref.((string) $portfolio->label !== '' ? ' - '.$portfolio->label : '');
}
$mutations = array('confirm_generate', 'confirm_print', 'confirm_prepare', 'confirm_deliver');
if (in_array($action, $mutations, true) && (!GETPOST('token', 'alpha') || !hash_equals(currentToken(), GETPOST('token', 'alpha')))) accessforbidden('Bad token');

/** @return int[] */
function dolivoucherSelectedVoucherIds(DoliDB $db, int $seriesId, int $entity, string $mode, int $start, int $end, string $voucherRefs): array
{
	if ($mode === 'ALL') return array();
	if ($mode === 'RANGE') {
		if ($start < 1 || $end < $start || $end > 999999) return array(-1);
		$sql = 'SELECT rowid FROM '.$db->prefix().'dolivoucher_voucher WHERE entity='.$entity.' AND fk_series='.$seriesId.' ORDER BY ref ASC LIMIT '.($end - $start + 1).' OFFSET '.($start - 1);
	} else {
		$refs = preg_split('/[\s,;]+/', trim($voucherRefs), -1, PREG_SPLIT_NO_EMPTY);
		$refs = array_values(array_unique(array_filter(array_map('trim', $refs ?: array()))));
		if (!$refs || count($refs) > getDolGlobalInt('DOLIVOUCHER_MAX_VOUCHERS_PER_PDF', 1000)) return array(-1);
		$quoted = array(); foreach ($refs as $ref) $quoted[] = "'".$db->escape($ref)."'";
		$sql = 'SELECT rowid FROM '.$db->prefix().'dolivoucher_voucher WHERE entity='.$entity.' AND fk_series='.$seriesId.' AND ref IN ('.implode(',', $quoted).') ORDER BY ref ASC LIMIT '.(count($refs) + 1);
	}
	$resql = $db->query($sql); $ids = array();
	while ($resql && ($row = $db->fetch_object($resql))) $ids[] = (int) $row->rowid;
	$expected = $mode === 'RANGE' ? ($end - $start + 1) : count($refs);
	return count($ids) === $expected ? $ids : array(-1);
}

/* Actions */
if ($action === 'confirm_generate' && GETPOST('confirm', 'alpha') === 'yes') {
	if (!$user->hasRight('dolivoucher', 'series', 'generate')) accessforbidden();
	$dateExpiration = GETPOSTINT('date_expirationyear') > 0 ? dol_mktime(23, 59, 59, GETPOSTINT('date_expirationmonth'), GETPOSTINT('date_expirationday'), GETPOSTINT('date_expirationyear')) : null;
	$result = $service->generate(array('generation_key' => GETPOST('generation_key', 'alphanohtml'), 'fk_portfolio' => GETPOSTINT('fk_portfolio'), 'label' => GETPOST('label', 'restricthtml'), 'quantity' => GETPOSTINT('quantity'), 'face_value' => GETPOST('face_value', 'alphanohtml'), 'date_expiration' => $dateExpiration), $entity, $user);
	if ($result > 0) { setEventMessages($langs->trans('SeriesGeneratedSuccessfully'), null, 'mesgs'); header('Location: '.$_SERVER['PHP_SELF'].'?id='.$result); exit; }
	$action = 'create';
} elseif ($id > 0 && $action === 'confirm_print' && GETPOST('confirm', 'alpha') === 'yes') {
	if (!$user->hasRight('dolivoucher', 'series', 'print')) accessforbidden();
	$selectionKey = GETPOST('selection_key', 'alphanohtml');
	$printRequest = $_SESSION['dolivoucher_print_selection'][$selectionKey] ?? null;
	unset($_SESSION['dolivoucher_print_selection'][$selectionKey]);
	if (!is_array($printRequest) || (int) ($printRequest['user_id'] ?? 0) !== (int) $user->id || (int) ($printRequest['entity'] ?? 0) !== $entity || (int) ($printRequest['series_id'] ?? 0) !== $id || (int) ($printRequest['created_at'] ?? 0) < dol_now() - 900) accessforbidden($langs->trans('ErrorPrintRequestExpired'));
	$mode = (string) ($printRequest['selection_type'] ?? '');
	if (!in_array($mode, array('ALL', 'RANGE', 'LIST'), true)) $mode = 'ALL';
	$ids = dolivoucherSelectedVoucherIds($db, $id, $entity, $mode, (int) ($printRequest['range_start'] ?? 0), (int) ($printRequest['range_end'] ?? 0), (string) ($printRequest['voucher_refs'] ?? ''));
	if ($ids === array(-1)) {
		setEventMessages($langs->trans('ErrorInvalidVoucherSelection'), null, 'errors');
		header('Location: '.$_SERVER['PHP_SELF'].'?id='.$id); exit;
	}
	$result = $service->printSelection($id, $entity, $ids, $mode, (string) ($printRequest['reason'] ?? ''), $user, $langs, (bool) $user->hasRight('dolivoucher', 'series', 'reprint'));
	if ($result) setEventMessages($langs->trans('SeriesDocumentGenerated'), null, 'mesgs');
	header('Location: '.$_SERVER['PHP_SELF'].'?id='.$id); exit;
} elseif ($id > 0 && $action === 'confirm_prepare' && GETPOST('confirm', 'alpha') === 'yes') {
	if (!$user->hasRight('dolivoucher', 'series', 'prepare')) accessforbidden();
	$materialKey = GETPOST('material_key', 'alphanohtml');
	$materialRequest = $_SESSION['dolivoucher_material_request'][$materialKey] ?? null;
	unset($_SESSION['dolivoucher_material_request'][$materialKey]);
	if (!is_array($materialRequest) || ($materialRequest['type'] ?? '') !== 'PREPARE' || (int) ($materialRequest['user_id'] ?? 0) !== (int) $user->id || (int) ($materialRequest['entity'] ?? 0) !== $entity || (int) ($materialRequest['series_id'] ?? 0) !== $id || (int) ($materialRequest['created_at'] ?? 0) < dol_now() - 900) accessforbidden($langs->trans('ErrorMaterialRequestExpired'));
	if ($service->prepareAll($id, $entity, (string) ($materialRequest['note'] ?? ''), $user) > 0) setEventMessages($langs->trans('SeriesPreparedSuccessfully'), null, 'mesgs');
	header('Location: '.$_SERVER['PHP_SELF'].'?id='.$id); exit;
} elseif ($id > 0 && $action === 'confirm_deliver' && GETPOST('confirm', 'alpha') === 'yes') {
	if (!$user->hasRight('dolivoucher', 'series', 'deliver')) accessforbidden();
	$materialKey = GETPOST('material_key', 'alphanohtml');
	$materialRequest = $_SESSION['dolivoucher_material_request'][$materialKey] ?? null;
	unset($_SESSION['dolivoucher_material_request'][$materialKey]);
	if (!is_array($materialRequest) || ($materialRequest['type'] ?? '') !== 'DELIVER' || (int) ($materialRequest['user_id'] ?? 0) !== (int) $user->id || (int) ($materialRequest['entity'] ?? 0) !== $entity || (int) ($materialRequest['series_id'] ?? 0) !== $id || (int) ($materialRequest['created_at'] ?? 0) < dol_now() - 900) accessforbidden($langs->trans('ErrorMaterialRequestExpired'));
	if ($service->deliverAll($id, $entity, (string) ($materialRequest['note'] ?? ''), $user) > 0) setEventMessages($langs->trans('SeriesDeliveredSuccessfully'), null, 'mesgs');
	header('Location: '.$_SERVER['PHP_SELF'].'?id='.$id); exit;
}
if ($id > 0 && $object->fetch($id) <= 0) accessforbidden($langs->trans('ErrorRecordNotFound'));

/* Views */
llxHeader('', $langs->trans('VoucherSeries'), '', '', 0, 0, '', '', '', 'mod-dolivoucher page-series-card');
if ($action === 'create' || $action === 'ask_generate') {
	if (!$user->hasRight('dolivoucher', 'series', 'generate')) accessforbidden();
	print load_fiche_titre($langs->trans('NewVoucherSeries'), '', 'layer-group');
	print '<form method="POST" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'">';
	$generationKey = GETPOST('generation_key', 'alphanohtml') ?: DoliVoucherService::uuid();
	print '<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="ask_generate"><input type="hidden" name="generation_key" value="'.dol_escape_htmltag($generationKey).'">';
	print '<table class="border centpercent"><tr><td class="fieldrequired">'.$langs->trans('Portfolio').'</td><td>'.$form->selectarray('fk_portfolio', $portfolioOptions, GETPOSTINT('fk_portfolio'), 1, 0, 0, 'required', 0, 0, 0, '', 'minwidth300').'</td></tr>';
	print '<tr><td>'.$langs->trans('Label').'</td><td><input class="minwidth300" name="label" value="'.dol_escape_htmltag(GETPOST('label', 'restricthtml')).'"></td></tr>';
	print '<tr><td class="fieldrequired">'.$langs->trans('Quantity').'</td><td><input type="number" min="1" max="'.getDolGlobalInt('DOLIVOUCHER_MAX_VOUCHERS_PER_SERIES', 1000).'" name="quantity" required></td></tr>';
	print '<tr><td class="fieldrequired">'.$langs->trans('FaceValue').'</td><td><input name="face_value" required> '.dol_escape_htmltag(getDolGlobalString('MAIN_MONNAIE', 'XOF')).'</td></tr>';
	print '<tr><td>'.$langs->trans('ExpirationDate').'</td><td>'.$form->selectDate(-1, 'date_expiration', 0, 0, 1, '', 1, 1).'</td></tr></table>';
	print '<div class="center"><input type="submit" class="button button-save" value="'.$langs->trans('GenerateSeries').'"></div></form>';
	if ($action === 'ask_generate') {
		$generationParameters = http_build_query(array('action' => 'confirm_generate', 'generation_key' => $generationKey, 'fk_portfolio' => GETPOSTINT('fk_portfolio'), 'label' => GETPOST('label', 'restricthtml'), 'quantity' => GETPOSTINT('quantity'), 'face_value' => GETPOST('face_value', 'alphanohtml'), 'date_expirationday' => GETPOSTINT('date_expirationday'), 'date_expirationmonth' => GETPOSTINT('date_expirationmonth'), 'date_expirationyear' => GETPOSTINT('date_expirationyear')));
		print $form->formconfirm($_SERVER['PHP_SELF'].'?'.$generationParameters, $langs->trans('GenerateSeries'), $langs->trans('ConfirmGenerateSeries'), 'confirm_generate', '', 0, 1);
	}
	llxFooter(); exit;
}
$object->next_prev_filter = 'te.entity:=:'.$entity;
$linkback = '<a href="series_list.php?restore_lastsearch_values=1">'.$langs->trans('BackToList').'</a>';
$morehtmlref = empty($object->label) ? '' : '<div class="refidno opacitymedium">'.dol_escape_htmltag((string) $object->label).'</div>';
dol_banner_tab($object, 'id', $linkback, 1, 'rowid', 'ref', $morehtmlref, '', 0, '', dolivoucherStatusBadge($object->status, 'series'));
print '<div class="fichecenter"><table class="border centpercent">';
$linkedPortfolio = $db->fetch_object($db->query('SELECT ref, label FROM '.$db->prefix().'dolivoucher_portfolio WHERE rowid='.(int) $object->fk_portfolio.' AND entity='.$entity.' LIMIT 1'));
$portfolioLink = $linkedPortfolio ? '<a href="portfolio_card.php?id='.(int) $object->fk_portfolio.'" title="'.dol_escape_htmltag((string) ($linkedPortfolio->label ?: $linkedPortfolio->ref)).'">'.dol_escape_htmltag((string) $linkedPortfolio->ref).'</a>' : '';
$fields = array('Ref' => $object->ref, 'Portfolio' => $portfolioLink, 'Label' => dol_escape_htmltag((string) $object->label), 'Quantity' => (int) $object->quantity, 'FaceValue' => price($object->face_value, 0, $langs, 1, -1, -1, getDolGlobalString('MAIN_MONNAIE', 'XOF')), 'GenerationDate' => dol_print_date($object->date_generation, 'dayhour'), 'ExpirationDate' => dol_print_date($object->date_expiration, 'day'), 'PrintCoverage' => (int) $object->printed_count.' / '.(int) $object->generated_count, 'PreparationCoverage' => (int) $object->prepared_count.' / '.(int) $object->generated_count, 'DeliveryCoverage' => (int) $object->delivered_count.' / '.(int) $object->generated_count);
foreach ($fields as $label => $value) print '<tr><td>'.$langs->trans($label).'</td><td>'.$value.'</td></tr>';
print '</table></div>';
if ($action === 'ask_print') {
	if (!$user->hasRight('dolivoucher', 'series', 'print')) accessforbidden();
	foreach (($_SESSION['dolivoucher_print_selection'] ?? array()) as $storedKey => $storedRequest) {
		if (!is_array($storedRequest) || (int) ($storedRequest['created_at'] ?? 0) < dol_now() - 900) unset($_SESSION['dolivoucher_print_selection'][$storedKey]);
	}
	$selectionKey = bin2hex(random_bytes(16));
	$_SESSION['dolivoucher_print_selection'][$selectionKey] = array('user_id' => (int) $user->id, 'entity' => $entity, 'series_id' => $id, 'created_at' => dol_now(), 'selection_type' => GETPOST('selection_type', 'alpha'), 'range_start' => GETPOSTINT('range_start'), 'range_end' => GETPOSTINT('range_end'), 'voucher_refs' => GETPOST('voucher_refs', 'restricthtml'), 'reason' => GETPOST('reason', 'restricthtml'));
	print $form->formconfirm($_SERVER['PHP_SELF'].'?id='.$id.'&selection_key='.$selectionKey, $langs->trans('PrintVoucherSeries'), $langs->trans('ConfirmSeriesPrint'), 'confirm_print', '', 0, 1);
}
if (in_array($action, array('ask_prepare', 'ask_deliver'), true)) {
	$isPrepare = $action === 'ask_prepare';
	$requiredRight = $isPrepare ? 'prepare' : 'deliver';
	if (!$user->hasRight('dolivoucher', 'series', $requiredRight)) accessforbidden();
	foreach (($_SESSION['dolivoucher_material_request'] ?? array()) as $storedKey => $storedRequest) {
		if (!is_array($storedRequest) || (int) ($storedRequest['created_at'] ?? 0) < dol_now() - 900) unset($_SESSION['dolivoucher_material_request'][$storedKey]);
	}
	$materialKey = bin2hex(random_bytes(16));
	$_SESSION['dolivoucher_material_request'][$materialKey] = array('type' => $isPrepare ? 'PREPARE' : 'DELIVER', 'user_id' => (int) $user->id, 'entity' => $entity, 'series_id' => $id, 'created_at' => dol_now(), 'note' => GETPOST('note', 'restricthtml'));
	$confirmAction = $isPrepare ? 'confirm_prepare' : 'confirm_deliver';
	print $form->formconfirm($_SERVER['PHP_SELF'].'?id='.$id.'&material_key='.$materialKey, $langs->trans($isPrepare ? 'PrepareSeries' : 'DeliverSeries'), $langs->trans($isPrepare ? 'ConfirmPrepareSeries' : 'ConfirmDeliverSeries'), $confirmAction, '', 0, 1);
}
if ($user->hasRight('dolivoucher', 'series', 'print')) {
	print '<br><form method="POST"><input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="id" value="'.$id.'"><input type="hidden" name="action" value="ask_print">';
	print '<table class="border centpercent"><tr><td>'.$langs->trans('PrintSelection').'</td><td>'.$form->selectarray('selection_type', array('ALL' => $langs->trans('WholeSeries'), 'RANGE' => $langs->trans('ContinuousRange'), 'LIST' => $langs->trans('ExplicitList')), 'ALL', 0).'</td></tr>';
	print '<tr><td>'.$langs->trans('RangeBounds').'</td><td><input type="number" min="1" name="range_start" class="maxwidth75"> - <input type="number" min="1" name="range_end" class="maxwidth75"></td></tr>';
	print '<tr><td>'.$langs->trans('VoucherReferenceList').'</td><td><textarea name="voucher_refs" class="quatrevingtpercent"></textarea></td></tr>';
	print '<tr><td>'.$langs->trans('ReprintReason').'</td><td><input maxlength="500" name="reason" class="quatrevingtpercent"></td></tr></table><div class="center"><input type="submit" class="button" value="'.$langs->trans('GeneratePdf').'"></div></form>';
}
if ($user->hasRight('dolivoucher', 'series', 'prepare') && (int) $object->printed_count === (int) $object->generated_count && (int) $object->prepared_count < (int) $object->generated_count) {
	print '<form method="POST"><input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="id" value="'.$id.'"><input type="hidden" name="action" value="ask_prepare"><table class="border centpercent"><tr><td>'.$langs->trans('PreparationInternalNote').'</td><td><input maxlength="2000" class="quatrevingtpercent" name="note"></td><td class="right"><input type="submit" class="button" value="'.$langs->trans('PrepareSeries').'"></td></tr></table></form>';
}
if ($user->hasRight('dolivoucher', 'series', 'deliver') && (int) $object->prepared_count === (int) $object->generated_count && (int) $object->delivered_count < (int) $object->generated_count) {
	print '<form method="POST"><input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="id" value="'.$id.'"><input type="hidden" name="action" value="ask_deliver"><table class="border centpercent"><tr><td>'.$langs->trans('DeliveryInternalNote').'</td><td><input maxlength="2000" class="quatrevingtpercent" name="note"></td><td class="right"><input type="submit" class="button" value="'.$langs->trans('DeliverSeries').'"></td></tr></table></form>';
}
print load_fiche_titre($langs->trans('MaterialJournal'), '', 'list');
$sql = 'SELECT rowid, event_type, revision, selection_summary, document_name, document_sha256, voucher_count, reason, note_private, date_event, fk_user_creat FROM '.$db->prefix().'dolivoucher_series_event WHERE entity='.$entity.' AND fk_series='.$id.' ORDER BY rowid DESC LIMIT 200';
$resql = $db->query($sql); print '<div class="div-table-responsive"><table class="tagtable liste"><tr class="liste_titre"><th>'.$langs->trans('EventDate').'</th><th>'.$langs->trans('EventType').'</th><th>'.$langs->trans('Selection').'</th><th class="right">'.$langs->trans('VoucherCount').'</th><th>'.$langs->trans('Revision').'</th><th>'.$langs->trans('Document').'</th><th>'.$langs->trans('Reason').'</th></tr>';
$found = false; while ($resql && ($row = $db->fetch_object($resql))) { $found = true; print '<tr class="oddeven"><td>'.dol_print_date($db->jdate($row->date_event), 'dayhour').'</td><td>'.$langs->trans('MaterialEvent'.$row->event_type).'</td><td>'.dol_escape_htmltag((string) $row->selection_summary).'</td><td class="right">'.(int) $row->voucher_count.'</td><td>'.($row->revision ? 'R'.(int) $row->revision : '').'</td><td>'.($row->document_name ? '<a href="series_document.php?event_id='.(int) $row->rowid.'">'.dol_escape_htmltag($row->document_name).'</a>' : '').'</td><td>'.dol_escape_htmltag((string) ($row->reason ?: $row->note_private)).'</td></tr>'; }
if (!$found) print '<tr class="oddeven"><td colspan="7" class="opacitymedium">'.$langs->trans('None').'</td></tr>';
print '</table></div>';
llxFooter();
