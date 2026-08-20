<?php
/* Copyright (C) 2026 Fred Omega Junior */

declare(strict_types=1);

$res = @include '../../main.inc.php';
if (!$res) {
	die('Include of main fails');
}
require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
require_once __DIR__.'/class/dolivoucherportfolio.class.php';
require_once __DIR__.'/class/dolivoucherservice.class.php';
require_once __DIR__.'/lib/dolivoucher.lib.php';

$langs->loadLangs(array('dolivoucher@dolivoucher', 'companies'));
if (!isModEnabled('dolivoucher') || !$user->hasRight('dolivoucher', 'portfolio', 'read') || $user->socid > 0) {
	accessforbidden();
}

$id = GETPOSTINT('id');
$action = GETPOST('action', 'aZ09');
$entity = (int) $conf->entity;
$object = new DoliVoucherPortfolio($db);
$service = new DoliVoucherService($db);
$form = new Form($db);
$portfolioTypeOptions = array(
	DoliVoucherPortfolio::TYPE_INSTITUTIONAL => $langs->trans('Institutional'),
	DoliVoucherPortfolio::TYPE_DONATION => $langs->trans('Donation'),
);
$fundingTypeOptions = array(
	'FUND_NEW' => $langs->trans('NewFunding'),
	'CARRYOVER_IN' => $langs->trans('CarryoverContribution'),
);
$mutationActions = array('add', 'update', 'confirm_validate', 'confirm_activate', 'confirm_close', 'confirm_cancel', 'fund', 'transfer');
if (in_array($action, $mutationActions, true) && (!GETPOST('token', 'alpha') || !hash_equals(currentToken(), GETPOST('token', 'alpha')))) {
	accessforbidden('Bad token');
}

/* Actions */
if ($action === 'add') {
	if (!$user->hasRight('dolivoucher', 'portfolio', 'write')) {
		accessforbidden();
	}
	$object->ref = GETPOST('ref', 'alphanohtml');
	$object->label = GETPOST('label', 'restricthtml');
	$object->type = GETPOST('type', 'alpha');
	$object->fk_soc = GETPOSTINT('fk_soc') ?: null;
	$object->period_label = GETPOST('period_label', 'restricthtml');
	$object->date_start = GETPOSTINT('date_startyear') > 0 ? dol_mktime(0, 0, 0, GETPOSTINT('date_startmonth'), GETPOSTINT('date_startday'), GETPOSTINT('date_startyear')) : null;
	$object->date_end = GETPOSTINT('date_endyear') > 0 ? dol_mktime(0, 0, 0, GETPOSTINT('date_endmonth'), GETPOSTINT('date_endday'), GETPOSTINT('date_endyear')) : null;
	$object->description = GETPOST('description', 'restricthtml');
	$result = $service->createPortfolio($object, $user);
	if ($result > 0) {
		header('Location: '.$_SERVER['PHP_SELF'].'?id='.$result);
		exit;
	}
	setEventMessages($langs->trans($object->error ?: 'ErrorPortfolioCreationFailed'), null, 'errors');
	$action = 'create';
} elseif ($id > 0 && $action === 'update') {
	if (!$user->hasRight('dolivoucher', 'portfolio', 'write') || $object->fetch($id) <= 0 || (int) $object->status !== DoliVoucherPortfolio::STATUS_DRAFT) accessforbidden();
	$object->ref = GETPOST('ref', 'alphanohtml');
	$object->label = GETPOST('label', 'restricthtml');
	$object->type = GETPOST('type', 'alpha');
	$object->fk_soc = GETPOSTINT('fk_soc') ?: null;
	$object->period_label = GETPOST('period_label', 'restricthtml');
	$object->date_start = GETPOSTINT('date_startyear') > 0 ? dol_mktime(0, 0, 0, GETPOSTINT('date_startmonth'), GETPOSTINT('date_startday'), GETPOSTINT('date_startyear')) : null;
	$object->date_end = GETPOSTINT('date_endyear') > 0 ? dol_mktime(0, 0, 0, GETPOSTINT('date_endmonth'), GETPOSTINT('date_endday'), GETPOSTINT('date_endyear')) : null;
	$object->description = GETPOST('description', 'restricthtml');
	if ($object->update($user) > 0) setEventMessages($langs->trans('OperationSuccessful'), null, 'mesgs');
	else setEventMessages($langs->trans($object->error ?: 'ErrorPortfolioUpdateFailed'), null, 'errors');
} elseif ($id > 0 && str_starts_with($action, 'confirm_')) {
	if (!$user->hasRight('dolivoucher', 'portfolio', 'validate')) {
		accessforbidden();
	}
	$result = -1;
	if ($action === 'confirm_validate') $result = $service->validatePortfolio($id, $entity, $user);
	if ($action === 'confirm_activate') $result = $service->activatePortfolio($id, $entity, $user);
	if ($action === 'confirm_close') $result = $service->closePortfolio($id, $entity, $user);
	if ($action === 'confirm_cancel') $result = $service->cancelPortfolio($id, $entity, $user);
	if ($result > 0) setEventMessages($langs->trans('OperationSuccessful'), null, 'mesgs');
} elseif ($id > 0 && $action === 'fund') {
	if (!$user->hasRight('dolivoucher', 'portfolio', 'validate')) {
		accessforbidden();
	}
	$kind = GETPOST('funding_type', 'alpha');
	$result = $kind === 'CARRYOVER_IN'
		? $service->addCarryover($id, $entity, GETPOST('amount', 'alphanohtml'), $user, GETPOST('reason', 'restricthtml'), GETPOST('external_ref', 'alphanohtml'))
		: $service->fundPortfolio($id, $entity, GETPOST('amount', 'alphanohtml'), $user, GETPOST('reason', 'restricthtml'), GETPOST('external_ref', 'alphanohtml'));
	if ($result > 0) setEventMessages($langs->trans('OperationSuccessful'), null, 'mesgs');
} elseif ($id > 0 && $action === 'transfer') {
	if (!$user->hasRight('dolivoucher', 'transfer', 'write')) {
		accessforbidden();
	}
	$result = $service->transferRemainder($id, GETPOSTINT('destination_id'), $entity, GETPOST('amount', 'alphanohtml'), $user, GETPOST('reason', 'restricthtml'));
	if ($result > 0) setEventMessages($langs->trans('OperationSuccessful'), null, 'mesgs');
}

if ($id > 0 && $object->fetch($id) <= 0) {
	accessforbidden($langs->trans('ErrorRecordNotFound'));
}

/* Views */
llxHeader('', $langs->trans('Portfolio'), '', '', 0, 0, '', '', '', 'mod-dolivoucher page-portfolio-card');

if ($action === 'create' || ($action === 'add' && $id <= 0)) {
	print load_fiche_titre($langs->trans('NewPortfolio'), '', 'wallet');
	print '<form method="POST" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'">';
	print '<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="add">';
	print '<table class="border centpercent">';
	print '<tr><td class="fieldrequired">'.$langs->trans('Ref').'</td><td><input class="minwidth300" name="ref" value="'.dol_escape_htmltag(GETPOST('ref', 'alphanohtml')).'" required></td></tr>';
	print '<tr><td class="fieldrequired">'.$langs->trans('Label').'</td><td><input class="minwidth300" name="label" value="'.dol_escape_htmltag(GETPOST('label', 'restricthtml')).'" required></td></tr>';
	$selectedType = GETPOST('type', 'alpha') ?: DoliVoucherPortfolio::TYPE_INSTITUTIONAL;
	print '<tr><td class="fieldrequired">'.$langs->trans('Type').'</td><td>'.$form->selectarray('type', $portfolioTypeOptions, $selectedType, 0, 0, 0, '', 0, 0, 0, '', 'minwidth200').'</td></tr>';
	print '<tr><td>'.$langs->trans('FundingThirdParty').'</td><td>'.$form->select_company(GETPOSTINT('fk_soc'), 'fk_soc', '', 'SelectThirdParty', 0, 0, array(), 0, 'minwidth300').'</td></tr>';
	print '<tr><td>'.$langs->trans('Period').'</td><td><input name="period_label" value="'.dol_escape_htmltag(GETPOST('period_label', 'restricthtml')).'"></td></tr>';
	print '<tr><td>'.$langs->trans('DateStart').'</td><td>'.$form->selectDate(-1, 'date_start', 0, 0, 1, '', 1, 1).'</td></tr>';
	print '<tr><td>'.$langs->trans('DateEnd').'</td><td>'.$form->selectDate(-1, 'date_end', 0, 0, 1, '', 1, 1).'</td></tr>';
	print '<tr><td>'.$langs->trans('Description').'</td><td><textarea name="description" class="quatrevingtpercent"></textarea></td></tr>';
	print '</table><div class="center"><input class="button button-save" type="submit" value="'.$langs->trans('Create').'"></div></form>';
	llxFooter();
	exit;
}

if ($action === 'edit' && (int) $object->status === DoliVoucherPortfolio::STATUS_DRAFT && $user->hasRight('dolivoucher', 'portfolio', 'write')) {
	print load_fiche_titre($langs->trans('EditPortfolio'), '', 'wallet');
	print '<form method="POST"><input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="update"><input type="hidden" name="id" value="'.(int) $object->id.'"><table class="border centpercent">';
	print '<tr><td class="fieldrequired">'.$langs->trans('Ref').'</td><td><input name="ref" value="'.dol_escape_htmltag($object->ref).'" required></td></tr><tr><td class="fieldrequired">'.$langs->trans('Label').'</td><td><input name="label" value="'.dol_escape_htmltag($object->label).'" required></td></tr>';
	print '<tr><td>'.$langs->trans('Type').'</td><td>'.$form->selectarray('type', $portfolioTypeOptions, $object->type, 0, 0, 0, '', 0, 0, 0, '', 'minwidth200').'</td></tr>';
	print '<tr><td>'.$langs->trans('FundingThirdParty').'</td><td>'.$form->select_company((int) $object->fk_soc, 'fk_soc', '', 'SelectThirdParty', 0, 0, array(), 0, 'minwidth300').'</td></tr>';
	print '<tr><td>'.$langs->trans('Period').'</td><td><input name="period_label" value="'.dol_escape_htmltag((string) $object->period_label).'"></td></tr>';
	print '<tr><td>'.$langs->trans('DateStart').'</td><td>'.$form->selectDate($object->date_start ?: -1, 'date_start', 0, 0, 1, '', 1, 1).'</td></tr>';
	print '<tr><td>'.$langs->trans('DateEnd').'</td><td>'.$form->selectDate($object->date_end ?: -1, 'date_end', 0, 0, 1, '', 1, 1).'</td></tr>';
	print '<tr><td>'.$langs->trans('Description').'</td><td><textarea name="description">'.dol_escape_htmltag((string) $object->description).'</textarea></td></tr></table><div class="center"><button class="button button-save">'.$langs->trans('Save').'</button></div></form>';
	llxFooter(); exit;
}

print load_fiche_titre($object->getNomUrl(1), '', 'wallet');
$summary = $object->getFinancialSummary();
$balanceCheck = $service->checkPortfolioBalance((int) $object->id, $entity);
if (!$balanceCheck['consistent']) {
	setEventMessages($langs->trans('ErrorBalanceReconciliation', $balanceCheck['materialized'], $balanceCheck['reconstructed']), null, 'warnings');
}
$exposureCheck = $service->checkPortfolioExposureBalances((int) $object->id, $entity);
if (!$exposureCheck['consistent']) {
	setEventMessages($langs->trans('ErrorExposureReconciliation'), null, 'warnings');
}
print '<div class="fichecenter"><div class="fichehalfleft"><table class="border centpercent">';
$fields = array('Ref' => $object->ref, 'Label' => $object->label, 'Type' => $langs->trans($object->type === 'INSTITUTIONAL' ? 'Institutional' : 'Donation'), 'Period' => $object->period_label, 'DateStart' => dol_print_date($object->date_start, 'day'), 'DateEnd' => dol_print_date($object->date_end, 'day'), 'Status' => dolivoucherStatusLabel($object->status));
foreach ($fields as $label => $value) print '<tr><td>'.$langs->trans($label).'</td><td>'.dol_escape_htmltag((string) $value).'</td></tr>';
print '<tr><td>'.$langs->trans('FundingThirdParty').'</td><td>'.((int) $object->fk_soc > 0 ? '<a href="'.DOL_URL_ROOT.'/societe/card.php?socid='.(int) $object->fk_soc.'">#'.(int) $object->fk_soc.'</a>' : '').'</td></tr>';
print '</table></div><div class="fichehalfright"><table class="border centpercent">';
$money = array(
	'TotalFunding' => 'total_funding',
	'IncomingRemainders' => 'incoming_remainders',
	'OutgoingRemainders' => 'outgoing_remainders',
	'AvailableUnallocatedBalance' => 'available_unallocated_balance',
	'ActivatedVoucherValue' => 'activated_value',
	'ConsumedAmount' => 'consumed_amount',
	'ImmediatelyRedeemableVoucherBalance' => 'immediately_redeemable_voucher_balance',
	'BlockedVoucherBalance' => 'blocked_voucher_balance',
	'ExpiredUnreallocatedBalance' => 'expired_unreallocated_balance',
	'OutstandingVoucherBalance' => 'outstanding_voucher_balance',
	'GlobalOutstandingBalance' => 'global_outstanding_balance',
);
foreach ($money as $label => $key) print '<tr><td>'.$langs->trans($label).'</td><td class="right">'.price($summary[$key] ?? 0, 0, $langs, 1, -1, -1, 'XOF').'</td></tr>';
print '<tr><td>'.$langs->trans('VoucherCount').'</td><td class="right">'.(int) ($summary['voucher_count'] ?? 0).'</td></tr><tr><td>'.$langs->trans('ActiveVoucherCount').'</td><td class="right">'.(int) ($summary['active_count'] ?? 0).'</td></tr><tr><td>'.$langs->trans('ConsumedVoucherCount').'</td><td class="right">'.(int) ($summary['consumed_count'] ?? 0).'</td></tr>';
print '</table></div></div>';

$confirmActions = array('validate' => 'Validate', 'activate' => 'Activate', 'close' => 'Close', 'cancel' => 'Cancel');
if (isset($confirmActions[$action])) {
	print $form->formconfirm($_SERVER['PHP_SELF'].'?id='.(int) $object->id, $langs->trans($confirmActions[$action]), $langs->trans('ConfirmAction'), 'confirm_'.$action, '', 0, 1);
}
print '<div class="tabsAction">';
if ($user->hasRight('dolivoucher', 'portfolio', 'write') && (int) $object->status === DoliVoucherPortfolio::STATUS_DRAFT) print '<a class="butAction" href="?id='.(int) $object->id.'&action=edit">'.$langs->trans('Modify').'</a>';
if ($user->hasRight('dolivoucher', 'portfolio', 'validate') && (int) $object->status === DoliVoucherPortfolio::STATUS_DRAFT) {
	print '<a class="butAction" href="'.$_SERVER['PHP_SELF'].'?id='.(int) $object->id.'&action=validate">'.$langs->trans('Validate').'</a>';
	print '<a class="butActionDelete" href="'.$_SERVER['PHP_SELF'].'?id='.(int) $object->id.'&action=cancel">'.$langs->trans('Cancel').'</a>';
}
if ($user->hasRight('dolivoucher', 'portfolio', 'validate') && (int) $object->status === DoliVoucherPortfolio::STATUS_VALIDATED) print '<a class="butAction" href="'.$_SERVER['PHP_SELF'].'?id='.(int) $object->id.'&action=activate">'.$langs->trans('Activate').'</a>';
if ($user->hasRight('dolivoucher', 'portfolio', 'validate') && in_array((int) $object->status, array(1,2), true)) print '<a class="butAction" href="'.$_SERVER['PHP_SELF'].'?id='.(int) $object->id.'&action=close">'.$langs->trans('Close').'</a>';
if ($user->hasRight('dolivoucher', 'voucher', 'write') && in_array((int) $object->status, array(1,2), true)) print '<a class="butAction" href="voucher_card.php?action=create&fk_portfolio='.(int) $object->id.'">'.$langs->trans('NewVoucher').'</a>';
print '</div>';

if ($user->hasRight('dolivoucher', 'portfolio', 'validate') && in_array((int) $object->status, array(1,2), true)) {
	print '<br><form method="POST"><input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="fund"><input type="hidden" name="id" value="'.(int) $object->id.'">';
	print '<table class="border centpercent"><tr><td>'.$langs->trans('FundingType').'</td><td>'.$form->selectarray('funding_type', $fundingTypeOptions, 'FUND_NEW', 0, 0, 0, '', 0, 0, 0, '', 'minwidth200').'</td><td><input name="amount" required placeholder="0.00"></td><td><input name="external_ref" placeholder="'.$langs->trans('ExternalRef').'"></td><td><input name="reason" required placeholder="'.$langs->trans('Reason').'"></td><td class="center"><input type="submit" class="button button-save" value="'.$langs->trans('ConfirmFunding').'"></td></tr></table></form>';
}
if ($user->hasRight('dolivoucher', 'transfer', 'write') && in_array((int) $object->status, array(1,2,3), true) && DoliVoucherMoney::compare((string) $object->available_unallocated_balance, '0') > 0) {
	$destinationOptions = array();
	$sqlDestinations = 'SELECT rowid, ref, label FROM '.$db->prefix().'dolivoucher_portfolio WHERE entity='.$entity.' AND rowid<>'.(int) $object->id.' AND status IN (1,2) ORDER BY ref';
	$resqlDestinations = $db->query($sqlDestinations);
	while ($resqlDestinations && ($destination = $db->fetch_object($resqlDestinations))) {
		$destinationOptions[(int) $destination->rowid] = $destination->ref.' - '.$destination->label;
	}
	print '<br><form method="POST"><input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="transfer"><input type="hidden" name="id" value="'.(int) $object->id.'">';
	print '<table class="border centpercent"><tr><td>'.$langs->trans('TransferRemainder').'</td><td>'.$form->selectarray('destination_id', $destinationOptions, 0, $langs->trans('DestinationPortfolio'), 0, 0, '', 0, 0, 0, '', 'minwidth300').'</td><td><input name="amount" required placeholder="0.00"></td><td><input name="reason" required placeholder="'.$langs->trans('Reason').'"></td><td><button class="button">'.$langs->trans('ConfirmTransfer').'</button></td></tr></table></form>';
}
print '<br>'.load_fiche_titre($langs->trans('OperationJournal'), '', 'list');
dolivoucherPrintOperations($db, $entity, (int) $object->id);
llxFooter();
