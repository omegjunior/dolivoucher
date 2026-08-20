<?php
/* Copyright (C) 2026 Fred Omega Junior */
declare(strict_types=1);
$res = @include '../../main.inc.php';
if (!$res) die('Include of main fails');
$langs->load('dolivoucher@dolivoucher');
if (!isModEnabled('dolivoucher') || !$user->hasRight('dolivoucher', 'portfolio', 'read') || $user->socid > 0) accessforbidden();
$entity = (int) $conf->entity;
$sql = 'SELECT (SELECT COUNT(*) FROM '.$db->prefix().'dolivoucher_portfolio WHERE entity='.$entity.') AS portfolios,';
$sql .= ' (SELECT COUNT(*) FROM '.$db->prefix().'dolivoucher_voucher WHERE entity='.$entity.') AS vouchers,';
$sql .= ' (SELECT COUNT(*) FROM '.$db->prefix().'dolivoucher_voucher WHERE entity='.$entity.' AND status IN (2,3)) AS usable_vouchers,';
$sql .= ' (SELECT COUNT(*) FROM '.$db->prefix().'dolivoucher_operation WHERE entity='.$entity.') AS operations';
$resql = $db->query($sql);
$stats = $resql ? $db->fetch_object($resql) : null;
llxHeader('', $langs->trans('DoliVoucherArea'));
print load_fiche_titre($langs->trans('DoliVoucherArea'), '', 'ticket');
print '<div class="fichecenter"><table class="noborder centpercent"><tr class="liste_titre"><th>'.$langs->trans('Portfolios').'</th><th>'.$langs->trans('Vouchers').'</th><th>'.$langs->trans('UsableVouchers').'</th><th>'.$langs->trans('Operations').'</th></tr>';
print '<tr class="oddeven"><td><a href="portfolio_list.php">'.(int) ($stats->portfolios ?? 0).'</a></td><td><a href="voucher_list.php">'.(int) ($stats->vouchers ?? 0).'</a></td><td>'.(int) ($stats->usable_vouchers ?? 0).'</td><td><a href="operation_list.php">'.(int) ($stats->operations ?? 0).'</a></td></tr></table></div>';
llxFooter();
