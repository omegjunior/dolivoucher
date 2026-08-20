<?php
/* Copyright (C) 2026 Fred Omega Junior */

declare(strict_types=1);

$res = @include '../../main.inc.php';
if (!$res) die('Include of main fails');
require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
require_once __DIR__.'/class/dolivouchervoucher.class.php';
require_once __DIR__.'/class/dolivoucherservice.class.php';
require_once __DIR__.'/lib/dolivoucher.lib.php';

$langs->load('dolivoucher@dolivoucher');
if (!isModEnabled('dolivoucher') || !$user->hasRight('dolivoucher', 'portfolio', 'read') || $user->socid > 0) accessforbidden();
$id = GETPOSTINT('id');
$action = GETPOST('action', 'aZ09');
$entity = (int) $conf->entity;
$object = new DoliVoucherVoucher($db);
$service = new DoliVoucherService($db);
$form = new Form($db);
$mutationActions = array('add', 'update', 'confirm_prepare', 'confirm_activate', 'confirm_cancel', 'confirm_expire', 'consume', 'block', 'unblock', 'compensate');
if (in_array($action, $mutationActions, true) && (!GETPOST('token', 'alpha') || !hash_equals(currentToken(), GETPOST('token', 'alpha')))) accessforbidden('Bad token');

/* Actions */
if ($action === 'add') {
	if (!$user->hasRight('dolivoucher', 'voucher', 'write')) accessforbidden();
	$object->fk_portfolio = GETPOSTINT('fk_portfolio');
	$object->ref = GETPOST('ref', 'alphanohtml');
	$object->barcode = GETPOST('barcode', 'alphanohtml') ?: null;
	$object->label = GETPOST('label', 'restricthtml');
	$object->initial_amount = GETPOST('initial_amount', 'alphanohtml');
	$object->status = GETPOSTINT('prepared') ? DoliVoucherVoucher::STATUS_PREPARED : DoliVoucherVoucher::STATUS_DRAFT;
	$object->beneficiary_name = GETPOST('beneficiary_name', 'restricthtml');
	$object->date_expiration = GETPOSTINT('date_expirationyear') > 0 ? dol_mktime(GETPOSTINT('date_expirationhour'), GETPOSTINT('date_expirationmin'), 0, GETPOSTINT('date_expirationmonth'), GETPOSTINT('date_expirationday'), GETPOSTINT('date_expirationyear'), 'tzuserrel') : null;
	$object->note_private = GETPOST('note_private', 'restricthtml');
	$result = $service->createVoucher($object, $user, GETPOST('reason', 'restricthtml'));
	if ($result > 0) {
		header('Location: '.$_SERVER['PHP_SELF'].'?id='.$result);
		exit;
	}
	setEventMessages($langs->trans($object->error ?: 'ErrorVoucherCreationFailed'), null, 'errors');
	$action = 'create';
} elseif ($id > 0 && $action === 'update') {
	if (!$user->hasRight('dolivoucher', 'voucher', 'write') || $object->fetch($id) <= 0 || !in_array((int) $object->status, array(0,1), true)) accessforbidden();
	$object->fk_portfolio = GETPOSTINT('fk_portfolio');
	$object->ref = GETPOST('ref', 'alphanohtml');
	$object->barcode = GETPOST('barcode', 'alphanohtml') ?: null;
	$object->label = GETPOST('label', 'restricthtml');
	$object->initial_amount = GETPOST('initial_amount', 'alphanohtml');
	$object->beneficiary_name = GETPOST('beneficiary_name', 'restricthtml');
	$object->date_expiration = GETPOSTINT('date_expirationyear') > 0 ? dol_mktime(GETPOSTINT('date_expirationhour'), GETPOSTINT('date_expirationmin'), 0, GETPOSTINT('date_expirationmonth'), GETPOSTINT('date_expirationday'), GETPOSTINT('date_expirationyear'), 'tzuserrel') : null;
	$object->note_private = GETPOST('note_private', 'restricthtml');
	if ($object->update($user) > 0) setEventMessages($langs->trans('OperationSuccessful'), null, 'mesgs');
	else setEventMessages($langs->trans($object->error ?: 'ErrorVoucherUpdateFailed'), null, 'errors');
} elseif ($id > 0 && str_starts_with($action, 'confirm_')) {
	if ($action === 'confirm_cancel') {
		if (!$user->hasRight('dolivoucher', 'voucher', 'cancel')) accessforbidden();
		$result = $service->cancelVoucher($id, $entity, $user, $langs->transnoentitiesnoconv('ManualCancellation'));
	} elseif ($action === 'confirm_expire') {
		if (!$user->hasRight('dolivoucher', 'voucher', 'write')) accessforbidden();
		$result = $service->expireVoucher($id, $entity, $user, $langs->transnoentitiesnoconv('ExpirationRecorded'));
	} else {
		if (!$user->hasRight('dolivoucher', 'voucher', 'write')) accessforbidden();
		$result = $action === 'confirm_prepare' ? $service->prepareVoucher($id, $entity, $user) : $service->activateVoucher($id, $entity, $user);
	}
	if ($result > 0) setEventMessages($langs->trans('OperationSuccessful'), null, 'mesgs');
} elseif ($id > 0 && $action === 'consume') {
	if (!$user->hasRight('dolivoucher', 'voucher', 'consume')) accessforbidden();
	$result = $service->consumeVoucher($id, $entity, GETPOST('amount', 'alphanohtml'), $user, GETPOST('reason', 'restricthtml'), GETPOST('external_ref', 'alphanohtml'));
	if ($result > 0) setEventMessages($langs->trans('OperationSuccessful'), null, 'mesgs');
} elseif ($id > 0 && in_array($action, array('block', 'unblock'), true)) {
	if (!$user->hasRight('dolivoucher', 'voucher', 'block')) accessforbidden();
	$reason = GETPOST('reason', 'restricthtml');
	$result = $action === 'block' ? $service->blockVoucher($id, $entity, $user, $reason) : $service->unblockVoucher($id, $entity, $user, $reason);
	if ($result > 0) setEventMessages($langs->trans('OperationSuccessful'), null, 'mesgs');
} elseif ($id > 0 && $action === 'compensate') {
	if (!$user->hasRight('dolivoucher', 'audit', 'compensate')) accessforbidden();
	$result = $service->compensateConsumption(GETPOSTINT('operation_id'), $entity, $user, GETPOST('reason', 'restricthtml'));
	if ($result > 0) setEventMessages($langs->trans('OperationSuccessful'), null, 'mesgs');
}

if ($id > 0 && $object->fetch($id) <= 0) accessforbidden($langs->trans('ErrorRecordNotFound'));

/* Views */
llxHeader('', $langs->trans('Voucher'), '', '', 0, 0, '', '', '', 'mod-dolivoucher page-voucher-card');
if ($action === 'create' || ($action === 'add' && $id <= 0)) {
	print load_fiche_titre($langs->trans('NewVoucher'), '', 'ticket');
	print '<form method="POST"><input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="add"><table class="border centpercent">';
	$sql = 'SELECT rowid, ref, label FROM '.$db->prefix().'dolivoucher_portfolio WHERE entity='.$entity.' AND status IN (1,2) ORDER BY ref'.$db->plimit(500, 0);
	$resql = $db->query($sql);
	print '<tr><td class="fieldrequired">'.$langs->trans('Portfolio').'</td><td><select name="fk_portfolio" required><option value=""></option>';
	while ($resql && ($portfolio = $db->fetch_object($resql))) print '<option value="'.(int) $portfolio->rowid.'"'.(GETPOSTINT('fk_portfolio') === (int) $portfolio->rowid ? ' selected' : '').'>'.dol_escape_htmltag($portfolio->ref.' - '.$portfolio->label).'</option>';
	print '</select></td></tr>';
	print '<tr><td class="fieldrequired">'.$langs->trans('SerialNumber').'</td><td><input name="ref" required></td></tr><tr><td>'.$langs->trans('Barcode').'</td><td><input name="barcode"></td></tr><tr><td>'.$langs->trans('Label').'</td><td><input name="label"></td></tr>';
	print '<tr><td class="fieldrequired">'.$langs->trans('InitialAmount').'</td><td><input name="initial_amount" required placeholder="0.00"> XOF</td></tr><tr><td>'.$langs->trans('Beneficiary').'</td><td><input name="beneficiary_name"></td></tr>';
	print '<tr><td>'.$langs->trans('ExpirationDate').'</td><td>'.$form->selectDate(-1, 'date_expiration', 1, 1, 1, '', 1, 1).'</td></tr>';
	print '<tr><td>'.$langs->trans('Prepared').'</td><td><input type="checkbox" name="prepared" value="1"></td></tr><tr><td>'.$langs->trans('Reason').'</td><td><input class="minwidth300" name="reason"></td></tr><tr><td>'.$langs->trans('NotePrivate').'</td><td><textarea name="note_private"></textarea></td></tr>';
	print '</table><div class="center"><input type="submit" class="button button-save" value="'.$langs->trans('Create').'"></div></form>';
	llxFooter(); exit;
}

if ($action === 'edit' && in_array((int) $object->status, array(0,1), true) && $user->hasRight('dolivoucher', 'voucher', 'write')) {
	print load_fiche_titre($langs->trans('EditVoucher'), '', 'ticket');
	print '<form method="POST"><input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="update"><input type="hidden" name="id" value="'.(int) $object->id.'"><table class="border centpercent">';
	$sql = 'SELECT rowid, ref, label FROM '.$db->prefix().'dolivoucher_portfolio WHERE entity='.$entity.' AND status IN (1,2) ORDER BY ref'.$db->plimit(500, 0);
	$resql = $db->query($sql);
	print '<tr><td>'.$langs->trans('Portfolio').'</td><td><select name="fk_portfolio">';
	while ($resql && ($portfolio = $db->fetch_object($resql))) print '<option value="'.(int) $portfolio->rowid.'"'.((int) $object->fk_portfolio === (int) $portfolio->rowid ? ' selected' : '').'>'.dol_escape_htmltag($portfolio->ref.' - '.$portfolio->label).'</option>';
	print '</select></td></tr><tr><td>'.$langs->trans('SerialNumber').'</td><td><input name="ref" value="'.dol_escape_htmltag($object->ref).'" required></td></tr><tr><td>'.$langs->trans('Barcode').'</td><td><input name="barcode" value="'.dol_escape_htmltag((string) $object->barcode).'"></td></tr><tr><td>'.$langs->trans('Label').'</td><td><input name="label" value="'.dol_escape_htmltag((string) $object->label).'"></td></tr>';
	print '<tr><td>'.$langs->trans('InitialAmount').'</td><td><input name="initial_amount" value="'.dol_escape_htmltag((string) $object->initial_amount).'" required></td></tr><tr><td>'.$langs->trans('Beneficiary').'</td><td><input name="beneficiary_name" value="'.dol_escape_htmltag((string) $object->beneficiary_name).'"></td></tr>';
	print '<tr><td>'.$langs->trans('ExpirationDate').'</td><td>'.$form->selectDate($object->date_expiration ?: -1, 'date_expiration', 1, 1, 1, '', 1, 1).'</td></tr>';
	print '<tr><td>'.$langs->trans('NotePrivate').'</td><td><textarea name="note_private">'.dol_escape_htmltag((string) $object->note_private).'</textarea></td></tr>';
	print '</table><div class="center"><button class="button button-save">'.$langs->trans('Save').'</button></div></form>';
	llxFooter(); exit;
}

print load_fiche_titre($object->getNomUrl(1), '', 'ticket');
$check = $service->checkVoucherBalance((int) $object->id, $entity);
if (!$check['consistent']) setEventMessages($langs->trans('ErrorBalanceReconciliation', $check['materialized'], $check['reconstructed']), null, 'warnings');
print '<div class="fichecenter"><table class="border centpercent">';
$rows = array('SerialNumber' => $object->ref, 'Barcode' => $object->barcode, 'Portfolio' => '<a href="portfolio_card.php?id='.(int) $object->fk_portfolio.'">#'.(int) $object->fk_portfolio.'</a>', 'InitialAmount' => price($object->initial_amount, 0, $langs, 1, -1, -1, 'XOF'), 'CurrentBalance' => price($object->current_balance, 0, $langs, 1, -1, -1, 'XOF'), 'Status' => dolivoucherStatusLabel($object->status, true), 'Beneficiary' => $object->beneficiary_name, 'IssueDate' => dol_print_date($object->date_issue, 'dayhour'), 'ActivationDate' => dol_print_date($object->date_activation, 'dayhour'), 'ExpirationDate' => dol_print_date($object->date_expiration, 'dayhour'));
foreach ($rows as $label => $value) print '<tr><td>'.$langs->trans($label).'</td><td>'.($label === 'Portfolio' ? $value : dol_escape_htmltag((string) $value)).'</td></tr>';
print '</table></div>';

$portfolioState = $db->fetch_object($db->query('SELECT status, available_unallocated_balance FROM '.$db->prefix().'dolivoucher_portfolio WHERE rowid='.(int) $object->fk_portfolio.' AND entity='.$entity.' LIMIT 1'));
$hasConsumption = (int) $db->fetch_object($db->query("SELECT COUNT(*) AS count_value FROM ".$db->prefix()."dolivoucher_operation WHERE fk_voucher=".(int) $object->id." AND entity=".$entity." AND operation_type='CONSUME'"))->count_value > 0;
$compensable = $db->fetch_object($db->query("SELECT o.rowid FROM ".$db->prefix()."dolivoucher_operation o LEFT JOIN ".$db->prefix()."dolivoucher_operation r ON r.reversal_of=o.rowid WHERE o.fk_voucher=".(int) $object->id." AND o.entity=".$entity." AND o.operation_type='CONSUME' AND r.rowid IS NULL ORDER BY o.rowid DESC".$db->plimit(1, 0)));
$notExpired = empty($object->date_expiration) || (int) $object->date_expiration > dol_now();
$canActivate = $portfolioState && in_array((int) $portfolioState->status, array(1,2), true) && $notExpired && DoliVoucherMoney::compare((string) $portfolioState->available_unallocated_balance, (string) $object->initial_amount) >= 0;

$confirmActions = array('prepare' => 'Prepare', 'activate' => 'Activate', 'cancel' => 'Cancel', 'expire' => 'RecordExpiration');
if (isset($confirmActions[$action])) print $form->formconfirm($_SERVER['PHP_SELF'].'?id='.(int) $object->id, $langs->trans($confirmActions[$action]), $langs->trans('ConfirmAction'), 'confirm_'.$action, '', 0, 1);
print '<div class="tabsAction">';
if ($user->hasRight('dolivoucher', 'voucher', 'write') && in_array((int) $object->status, array(0,1), true)) print '<a class="butAction" href="?id='.(int) $object->id.'&action=edit">'.$langs->trans('Modify').'</a>';
if ($user->hasRight('dolivoucher', 'voucher', 'write') && (int) $object->status === 0) print '<a class="butAction" href="?id='.(int) $object->id.'&action=prepare">'.$langs->trans('Prepare').'</a>';
if ($user->hasRight('dolivoucher', 'voucher', 'write') && in_array((int) $object->status, array(0,1), true) && $canActivate) print '<a class="butAction" href="?id='.(int) $object->id.'&action=activate">'.$langs->trans('Activate').'</a>';
if ($user->hasRight('dolivoucher', 'voucher', 'cancel') && in_array((int) $object->status, array(2,6), true) && !$hasConsumption && DoliVoucherMoney::compare((string) $object->current_balance, (string) $object->initial_amount) === 0) print '<a class="butActionDelete" href="?id='.(int) $object->id.'&action=cancel">'.$langs->trans('Cancel').'</a>';
if ($user->hasRight('dolivoucher', 'voucher', 'write') && in_array((int) $object->status, array(2,3,6), true) && !empty($object->date_expiration) && $object->date_expiration <= dol_now()) print '<a class="butAction" href="?id='.(int) $object->id.'&action=expire">'.$langs->trans('RecordExpiration').'</a>';
print '</div>';

if ($user->hasRight('dolivoucher', 'voucher', 'consume') && in_array((int) $object->status, array(2,3), true) && $notExpired) {
	print '<form method="POST"><input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="id" value="'.(int) $object->id.'"><input type="hidden" name="action" value="consume"><table class="border centpercent"><tr><td>'.$langs->trans('ManualConsumption').'</td><td><input name="amount" required placeholder="0.00"></td><td><input name="external_ref" placeholder="'.$langs->trans('ExternalRef').'"></td><td><input name="reason" required placeholder="'.$langs->trans('Reason').'"></td><td><button class="button">'.$langs->trans('ConfirmConsumption').'</button></td></tr></table></form>';
}
if ($user->hasRight('dolivoucher', 'voucher', 'block') && in_array((int) $object->status, array(2,3,6), true)) {
	$nextAction = (int) $object->status === 6 ? 'unblock' : 'block';
	print '<form method="POST"><input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="id" value="'.(int) $object->id.'"><input type="hidden" name="action" value="'.$nextAction.'"><table class="border centpercent"><tr><td>'.$langs->trans(ucfirst($nextAction)).'</td><td><input class="minwidth300" name="reason" required placeholder="'.$langs->trans('Reason').'"></td><td><button class="button">'.$langs->trans('ConfirmAction').'</button></td></tr></table></form>';
}
if ($user->hasRight('dolivoucher', 'audit', 'compensate') && $compensable && !in_array((int) $object->status, array(9,10), true)) {
	print '<form method="POST"><input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="id" value="'.(int) $object->id.'"><input type="hidden" name="action" value="compensate"><table class="border centpercent"><tr><td>'.$langs->trans('AdministrativeCompensation').'</td><td><input type="number" name="operation_id" value="'.(int) $compensable->rowid.'" readonly></td><td><input name="reason" required placeholder="'.$langs->trans('Reason').'"></td><td><button class="button">'.$langs->trans('ConfirmCompensation').'</button></td></tr></table></form>';
}
print '<br>'.load_fiche_titre($langs->trans('OperationJournal'), '', 'list');
dolivoucherPrintOperations($db, $entity, 0, (int) $object->id);
llxFooter();
