<?php
/* Copyright (C) 2026		SuperAdmin
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \file    dolivoucher/lib/dolivoucher.lib.php
 * \ingroup dolivoucher
 * \brief   Library files with common functions for DoliVoucher
 */

/**
 * Prepare admin pages header
 *
 * @return array<array{string,string,string}>
 */
function dolivoucherAdminPrepareHead()
{
	global $langs, $conf;

	// global $db;
	// $extrafields = new ExtraFields($db);
	// $extrafields->fetch_name_optionals_label('myobject');

	$langs->load("dolivoucher@dolivoucher");

	$h = 0;
	$head = array();

	$head[$h][0] = dol_buildpath("/dolivoucher/admin/setup.php", 1);
	$head[$h][1] = $langs->trans("Settings");
	$head[$h][2] = 'settings';
	$h++;

	/*
	$head[$h][0] = dol_buildpath("/dolivoucher/admin/myobject_extrafields.php", 1);
	$head[$h][1] = $langs->trans("ExtraFields");
	$nbExtrafields = (isset($extrafields->attributes['myobject']['label']) && is_countable($extrafields->attributes['myobject']['label'])) ? count($extrafields->attributes['myobject']['label']) : 0;
	if ($nbExtrafields > 0) {
		$head[$h][1] .= '<span class="badge marginleftonlyshort">' . $nbExtrafields . '</span>';
	}
	$head[$h][2] = 'myobject_extrafields';
	$h++;

	$head[$h][0] = dol_buildpath("/dolivoucher/admin/myobjectline_extrafields.php", 1);
	$head[$h][1] = $langs->trans("ExtraFieldsLines");
	$nbExtrafields = (isset($extrafields->attributes['myobjectline']['label']) && is_countable($extrafields->attributes['myobjectline']['label'])) ? count($extrafields->attributes['myobject']['label']) : 0;
	if ($nbExtrafields > 0) {
		$head[$h][1] .= '<span class="badge marginleftonlyshort">' . $nbExtrafields . '</span>';
	}
	$head[$h][2] = 'myobject_extrafieldsline';
	$h++;
	*/

	$head[$h][0] = dol_buildpath("/dolivoucher/admin/about.php", 1);
	$head[$h][1] = $langs->trans("About");
	$head[$h][2] = 'about';
	$h++;

	// Show more tabs from modules
	// Entries must be declared in modules descriptor with line
	//$this->tabs = array(
	//	'entity:+tabname:Title:@dolivoucher:/dolivoucher/mypage.php?id=__ID__'
	//); // to add new tab
	//$this->tabs = array(
	//	'entity:-tabname:Title:@dolivoucher:/dolivoucher/mypage.php?id=__ID__'
	//); // to remove a tab
	complete_head_from_modules($conf, $langs, null, $head, $h, 'dolivoucher@dolivoucher');

	complete_head_from_modules($conf, $langs, null, $head, $h, 'dolivoucher@dolivoucher', 'remove');

	return $head;
}

/** @return array<int,string> */
function dolivoucherPortfolioStatuses()
{
	return array(0 => 'Draft', 1 => 'Validated', 2 => 'Active', 3 => 'Closed', 9 => 'Canceled');
}

/** @return array<int,string> */
function dolivoucherVoucherStatuses()
{
	return array(0 => 'Draft', 1 => 'Prepared', 2 => 'Active', 3 => 'PartiallyConsumed', 4 => 'Consumed', 6 => 'Blocked', 9 => 'Canceled', 10 => 'Expired');
}

function dolivoucherStatusLabel($status, $voucher = false)
{
	global $langs;
	$statuses = $voucher ? dolivoucherVoucherStatuses() : dolivoucherPortfolioStatuses();
	$key = $statuses[(int) $status] ?? 'Unknown';
	return $langs->trans($key);
}

/** Render the immutable operation history for one scope. */
function dolivoucherPrintOperations(DoliDB $db, $entity, $portfolioId = 0, $voucherId = 0, $limit = 100)
{
	global $langs;
	$sql = 'SELECT rowid, operation_uuid, operation_type, amount, balance_before, balance_after, reason, date_operation, reversal_of';
	$sql .= ' FROM '.$db->prefix().'dolivoucher_operation WHERE entity='.(int) $entity;
	if ((int) $voucherId > 0) {
		$sql .= ' AND fk_voucher='.(int) $voucherId;
	} elseif ((int) $portfolioId > 0) {
		$sql .= ' AND fk_portfolio='.(int) $portfolioId;
	}
	$sql .= ' ORDER BY date_operation DESC, rowid DESC'.$db->plimit(max(1, min((int) $limit, 500)), 0);
	$resql = $db->query($sql);
	print '<div class="div-table-responsive"><table class="noborder centpercent">';
	print '<tr class="liste_titre"><th>'.$langs->trans('OperationDate').'</th><th>'.$langs->trans('OperationType').'</th><th class="right">'.$langs->trans('Amount').'</th><th class="right">'.$langs->trans('BalanceBefore').'</th><th class="right">'.$langs->trans('BalanceAfter').'</th><th>'.$langs->trans('Reason').'</th></tr>';
	$found = false;
	while ($resql && ($row = $db->fetch_object($resql))) {
		$found = true;
		print '<tr class="oddeven"><td>'.dol_print_date($db->jdate($row->date_operation), 'dayhour').'</td>';
		print '<td>'.dol_escape_htmltag($langs->trans('Operation'.$row->operation_type)).($row->reversal_of ? ' #'.(int) $row->reversal_of : '').'</td>';
		print '<td class="right">'.price($row->amount, 0, $langs, 1, -1, -1, 'XOF').'</td>';
		print '<td class="right">'.($row->balance_before === null ? '' : price($row->balance_before, 0, $langs, 1, -1, -1, 'XOF')).'</td>';
		print '<td class="right">'.($row->balance_after === null ? '' : price($row->balance_after, 0, $langs, 1, -1, -1, 'XOF')).'</td>';
		print '<td>'.dol_escape_htmltag((string) $row->reason).'</td></tr>';
	}
	if (!$found) {
		print '<tr class="oddeven"><td colspan="6" class="opacitymedium">'.$langs->trans('None').'</td></tr>';
	}
	print '</table></div>';
}
