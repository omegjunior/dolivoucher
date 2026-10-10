<?php
/* Copyright (C) 2026 Fred Omega Junior */

declare(strict_types=1);

if (!defined('NOREQUIREMENU')) define('NOREQUIREMENU', '1');
if (!defined('NOREQUIREHTML')) define('NOREQUIREHTML', '1');
$res = @include '../../main.inc.php';
if (!$res) die('Include of main fails');
require_once __DIR__.'/class/dolivouchersettlementdiagnosticservice.class.php';
require_once __DIR__.'/lib/dolivoucher_settlement_diagnostic.lib.php';

$langs->load('dolivoucher@dolivoucher');
if (!isModEnabled('dolivoucher') || (!empty($user->socid)) || (empty($user->admin) && !$user->hasRight('dolivoucher', 'settlement', 'diagnose'))) accessforbidden();
$entity = (int) $conf->entity;
$filters = dolivoucherSettlementDiagnosticFilters();
$configuredMax = DoliVoucherSettlementDiagnosticService::normalizeMaxRows(getDolGlobalInt('DOLIVOUCHER_DIAGNOSTIC_MAX_ROWS', 1000));
$requestedLimit = GETPOSTINT('analysis_limit');
$analysisLimit = $requestedLimit > 0 ? min($requestedLimit, $configuredMax) : $configuredMax;
$service = new DoliVoucherSettlementDiagnosticService($db);
$result = $service->fetchPage($entity, $filters, $analysisLimit, 0, 's.date_creation', 'DESC', $configuredMax);

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="dolivoucher-settlement-diagnostic-'.date('Ymd-His').'.csv"');
header('X-Content-Type-Options: nosniff');
$output = fopen('php://output', 'wb');
if ($output === false) exit;
fwrite($output, "\xEF\xBB\xBF");
fputcsv($output, array($langs->transnoentities('SettlementUuid'), $langs->transnoentities('Date'), $langs->transnoentities('Event'), $langs->transnoentities('RequestSource'), $langs->transnoentities('Voucher'), $langs->transnoentities('Invoice'), $langs->transnoentities('Payment'), $langs->transnoentities('Operation'), $langs->transnoentities('Amount'), $langs->transnoentities('DiagnosticStatus'), $langs->transnoentities('DiagnosticCodes')), ';');
foreach ($result['items'] as $item) {
	$row = $item['record']; $diagnostic = $item['diagnostic'];
	$values = array(
		(string) $row->settlement_uuid, dol_print_date($db->jdate($row->date_creation), 'standard'), (string) $row->event_type, (string) $row->request_source,
		(string) ($row->voucher_ref ?: '#'.$row->fk_voucher), (string) ($row->invoice_ref ?: $row->invoice_ref_snapshot),
		(string) ($row->payment_ref ?: $row->payment_ref_snapshot ?: '#'.$row->fk_paiement), (string) ($row->operation_uuid ?: '#'.$row->fk_operation),
		(string) $row->amount, (string) $diagnostic['level'], implode('|', $diagnostic['codes']),
	);
	$values = array_map(static fn($value): string => DoliVoucherSettlementDiagnosticService::neutralizeCsvValue((string) $value), $values);
	fputcsv($output, $values, ';');
}
fclose($output);
exit;
