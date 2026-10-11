<?php
/* Copyright (C) 2026 Fred Omega Junior */
declare(strict_types=1);
if (!defined('NOREQUIREMENU')) define('NOREQUIREMENU', '1');
if (!defined('NOREQUIREHTML')) define('NOREQUIREHTML', '1');
$res = @include '../../../main.inc.php';
if (!$res) { http_response_code(500); exit; }
require_once __DIR__.'/../class/dolivouchertakeposservice.class.php';
$langs->load('dolivoucher@dolivoucher');
header('Content-Type: application/json; charset=UTF-8');
function dvTakePosResponse(bool $success, array $data = array(), string $error = ''): void { echo json_encode(array('success' => $success, 'data' => $data, 'error' => $error), JSON_UNESCAPED_UNICODE); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); dvTakePosResponse(false, array(), 'METHOD_NOT_ALLOWED'); }
if (empty($user->id) || !isModEnabled('dolivoucher')) { http_response_code(403); dvTakePosResponse(false, array(), 'ACCESS_DENIED'); }
$terminal = (string) ($_SESSION['takeposterminal'] ?? '');
if ($terminal === '') { http_response_code(403); dvTakePosResponse(false, array(), 'TAKEPOS_CONTEXT_REQUIRED'); }
$service = new DoliVoucherTakePosService($db);
$action = GETPOST('action', 'aZ09');
$intent = null;
if ($action === 'search') {
	$result = $service->inspect(GETPOSTINT('invoice_id'), GETPOST('identifier', 'alphanohtml'), $terminal, $user, (int) $conf->entity);
	if (!$result) { http_response_code(422); dvTakePosResponse(false, array(), $langs->transnoentities($service->error)); }
	dvTakePosResponse(true, get_object_vars($result));
} elseif ($action === 'prepare') $intent = $service->prepare(GETPOSTINT('invoice_id'), GETPOST('identifier', 'alphanohtml'), GETPOST('amount', 'alphanohtml'), GETPOST('idempotency_key', 'alphanohtml'), $terminal, GETPOST('place', 'alphanohtml'), $user, (int) $conf->entity);
elseif ($action === 'authorize') $intent = $service->authorize(GETPOST('intent_uuid', 'alphanohtml'), $terminal, $user, (int) $conf->entity);
elseif ($action === 'observe') $intent = $service->observeValidation(GETPOST('intent_uuid', 'alphanohtml'), $terminal, $user, (int) $conf->entity);
elseif ($action === 'settle') $intent = $service->settle(GETPOST('intent_uuid', 'alphanohtml'), $terminal, $user, (int) $conf->entity);
elseif ($action === 'state') $intent = $service->state(GETPOST('intent_uuid', 'alphanohtml'), $terminal, $user, (int) $conf->entity);
elseif ($action === 'cancel') $intent = $service->cancel(GETPOST('intent_uuid', 'alphanohtml'), $terminal, $user, (int) $conf->entity);
else dvTakePosResponse(false, array(), 'INVALID_ACTION');
if (!$intent) { http_response_code(422); dvTakePosResponse(false, array(), $langs->transnoentities($service->error)); }
$response = array('intent_uuid' => $intent->intent_uuid, 'status' => $intent->status, 'invoice_id' => (int) $intent->fk_facture, 'voucher_ref' => $intent->voucher_ref_snapshot, 'amount' => $intent->amount, 'place' => $intent->place_code, 'native_validation_required' => !empty($intent->native_validation_required), 'settlement_id' => (int) ($intent->fk_settlement ?? 0));
if ($action === 'settle' && (int) ($intent->fk_settlement ?? 0) > 0) {
	$invoice = new Facture($db);
	if ($invoice->fetch((int) $intent->fk_facture) > 0 && (int) $invoice->entity === (int) $conf->entity) {
		$response['invoice_remainder'] = DoliVoucherMoney::normalize((string) $invoice->getRemainToPay());
		$response['invoice_paid'] = DoliVoucherMoney::compare($response['invoice_remainder'], '0') <= 0;
	}
}
dvTakePosResponse(true, $response);
