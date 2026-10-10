<?php
/* Copyright (C) 2026 Fred Omega Junior */

declare(strict_types=1);

/** @return array<string,mixed> */
function dolivoucherSettlementDiagnosticFilters(): array
{
	$dateFrom = GETPOSTINT('date_fromyear') > 0 ? dol_mktime(0, 0, 0, GETPOSTINT('date_frommonth'), GETPOSTINT('date_fromday'), GETPOSTINT('date_fromyear')) : 0;
	$dateTo = GETPOSTINT('date_toyear') > 0 ? dol_mktime(23, 59, 59, GETPOSTINT('date_tomonth'), GETPOSTINT('date_today'), GETPOSTINT('date_toyear')) : 0;
	$filters = array(
		'date_from' => $dateFrom,
		'date_to' => $dateTo,
		'settlement_uuid' => GETPOST('search_uuid', 'alphanohtml'),
		'idempotency_key' => GETPOST('search_idempotency', 'alphanohtml'),
		'event_type' => GETPOST('search_event', 'alpha'),
		'state' => GETPOST('search_state', 'alpha'),
		'request_source' => GETPOST('search_source', 'alpha'),
		'voucher' => GETPOST('search_voucher', 'alphanohtml'),
		'invoice' => GETPOST('search_invoice', 'alphanohtml'),
		'payment' => GETPOST('search_payment', 'alphanohtml'),
		'operation' => GETPOST('search_operation', 'alphanohtml'),
		'amount' => GETPOST('search_amount', 'alphanohtml'),
		'diagnostic_level' => strtoupper(GETPOST('search_level', 'alpha')),
		'diagnostic_code' => strtoupper(GETPOST('search_code', 'alphanohtml')),
	);
	if (GETPOST('button_removefilter_x', 'alpha') || GETPOST('button_removefilter.x', 'alpha') || GETPOST('button_removefilter', 'alpha')) {
		foreach ($filters as $key => $value) $filters[$key] = in_array($key, array('date_from', 'date_to'), true) ? 0 : '';
	}
	return $filters;
}

/** @param array<string,mixed> $filters */
function dolivoucherSettlementDiagnosticParam(array $filters): string
{
	$map = array(
		'settlement_uuid' => 'search_uuid', 'idempotency_key' => 'search_idempotency', 'event_type' => 'search_event',
		'state' => 'search_state', 'request_source' => 'search_source', 'voucher' => 'search_voucher',
		'invoice' => 'search_invoice', 'payment' => 'search_payment', 'operation' => 'search_operation',
		'amount' => 'search_amount', 'diagnostic_level' => 'search_level', 'diagnostic_code' => 'search_code',
	);
	$param = '';
	foreach ($map as $key => $name) if ((string) ($filters[$key] ?? '') !== '') $param .= '&'.$name.'='.urlencode((string) $filters[$key]);
	if (!empty($filters['date_from'])) {
		$param .= '&date_fromday='.dol_print_date((int) $filters['date_from'], '%d').'&date_frommonth='.dol_print_date((int) $filters['date_from'], '%m').'&date_fromyear='.dol_print_date((int) $filters['date_from'], '%Y');
	}
	if (!empty($filters['date_to'])) {
		$param .= '&date_today='.dol_print_date((int) $filters['date_to'], '%d').'&date_tomonth='.dol_print_date((int) $filters['date_to'], '%m').'&date_toyear='.dol_print_date((int) $filters['date_to'], '%Y');
	}
	return $param;
}

function dolivoucherDiagnosticBadge(string $level, Translate $langs): string
{
	$class = $level === 'ERROR' ? 'badge-status8' : ($level === 'WARNING' ? 'badge-status1' : 'badge-status4');
	$icon = $level === 'ERROR' ? 'fa-times-circle' : ($level === 'WARNING' ? 'fa-exclamation-triangle' : 'fa-check-circle');
	return '<span class="badge badge-status '.$class.'"><span class="fas '.$icon.' paddingright"></span>'.$langs->trans('DiagnosticLevel'.$level).'</span>';
}

/** @param object $row */
function dolivoucherSettlementState(object $row): string
{
	if ((string) $row->event_type === 'REVERSAL') return 'REVERSAL';
	return (int) ($row->linked_reversal_count ?? 0) > 0 ? 'REVERSED' : 'ACTIVE';
}

/** Return a same-entity label or an identifier snapshot without disclosing another entity. */
function dolivoucherDiagnosticObjectDisplay(?string $label, int $id, int $objectEntity, int $currentEntity, string $url = ''): string
{
	if ($objectEntity > 0 && $objectEntity !== $currentEntity) return '#'.$id;
	$text = $label !== null && $label !== '' ? $label : '#'.$id;
	$text = dol_escape_htmltag($text);
	return $url !== '' && $objectEntity === $currentEntity ? '<a href="'.dol_escape_htmltag($url).'">'.$text.'</a>' : $text;
}
