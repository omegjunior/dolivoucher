<?php
/* Copyright (C) 2026 Fred Omega Junior */
declare(strict_types=1);
$res = @include '../../../main.inc.php';
if (!$res) die('Include of main fails');
require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once __DIR__.'/../lib/dolivoucher.lib.php';
$langs->loadLangs(array('admin', 'dolivoucher@dolivoucher'));
if (!$user->admin && !$user->hasRight('dolivoucher', 'config', 'write')) accessforbidden();
/* Actions */
$action = GETPOST('action', 'aZ09');
if ($action === 'save') {
	if (!GETPOST('token', 'alpha') || !hash_equals(currentToken(), GETPOST('token', 'alpha'))) accessforbidden('Bad token');
	$maxSeries = GETPOSTINT('DOLIVOUCHER_MAX_VOUCHERS_PER_SERIES');
	$maxPdf = GETPOSTINT('DOLIVOUCHER_MAX_VOUCHERS_PER_PDF');
	if ($maxSeries < 1 || $maxSeries > 999999 || $maxPdf < 1 || $maxPdf > $maxSeries) {
		setEventMessages($langs->trans('ErrorInvalidSeriesLimits'), null, 'errors');
	} else {
		dolibarr_set_const($db, 'DOLIVOUCHER_MAX_VOUCHERS_PER_SERIES', (string) $maxSeries, 'chaine', 0, '', (int) $conf->entity);
		dolibarr_set_const($db, 'DOLIVOUCHER_MAX_VOUCHERS_PER_PDF', (string) $maxPdf, 'chaine', 0, '', (int) $conf->entity);
		setEventMessages($langs->trans('SetupSaved'), null, 'mesgs');
	}
}
/* Views */
llxHeader('', $langs->trans('DoliVoucherSetup'));
$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans('BackToModuleList').'</a>';
print load_fiche_titre($langs->trans('DoliVoucherSetup'), $linkback, 'title_setup');
$head = dolivoucherAdminPrepareHead();
print dol_get_fiche_head($head, 'settings', $langs->trans('Settings'), -1, 'ticket');
print '<form method="POST" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'">';
print '<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="save">';
print '<table class="noborder centpercent"><tr class="liste_titre"><th colspan="2">'.$langs->trans('SeriesGenerationLimits').'</th></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('DoliVoucherMaxVouchersPerSeries').'</td><td><input type="number" min="1" max="999999" name="DOLIVOUCHER_MAX_VOUCHERS_PER_SERIES" value="'.getDolGlobalInt('DOLIVOUCHER_MAX_VOUCHERS_PER_SERIES', 1000).'"></td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('DoliVoucherMaxVouchersPerPdf').'</td><td><input type="number" min="1" max="999999" name="DOLIVOUCHER_MAX_VOUCHERS_PER_PDF" value="'.getDolGlobalInt('DOLIVOUCHER_MAX_VOUCHERS_PER_PDF', 1000).'"></td></tr></table>';
print '<div class="center"><input class="button button-save" type="submit" value="'.$langs->trans('Save').'"></div></form>';
print dol_get_fiche_end();
llxFooter();
