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
// Phase 1 deliberately has no mutable setting.
/* Views */
llxHeader('', $langs->trans('DoliVoucherSetup'));
print load_fiche_titre($langs->trans('DoliVoucherSetup'), '', 'title_setup');
$head = dolivoucherAdminPrepareHead();
print dol_get_fiche_head($head, 'settings', $langs->trans('Settings'), -1, 'ticket');
print '<div class="opacitymedium">'.$langs->trans('NoPhaseOneSetting').'</div>';
print dol_get_fiche_end();
llxFooter();
