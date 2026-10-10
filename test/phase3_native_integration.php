<?php
/* Copyright (C) 2026 Fred Omega Junior */

declare(strict_types=1);

if (getenv('DOLIVOUCHER_PHASE3_NATIVE') !== '1') {
	fwrite(STDERR, "Set DOLIVOUCHER_PHASE3_NATIVE=1 to create and remove a complete disposable Dolibarr database.\n");
	exit(2);
}

if (!defined('SYSLOG_FILE_NO_ERROR')) define('SYSLOG_FILE_NO_ERROR', true);

require_once dirname(__DIR__, 3).'/master.inc.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/paiement/class/paiement.class.php';
require_once dirname(__DIR__).'/core/modules/modDoliVoucher.class.php';
require_once dirname(__DIR__).'/class/dolivoucherportfolio.class.php';
require_once dirname(__DIR__).'/class/dolivouchervoucher.class.php';
require_once dirname(__DIR__).'/class/dolivoucherservice.class.php';
require_once dirname(__DIR__).'/class/dolivoucherinvoicesettlementservice.class.php';
require_once dirname(__DIR__).'/class/dolivouchersettlementdiagnosticservice.class.php';

/** @throws RuntimeException */
function p3Expect(bool $condition, string $message): void
{
	if (!$condition) throw new RuntimeException($message);
}

/** @return object */
function p3One(DoliDB $database, string $sql): object
{
	$result = $database->query($sql);
	$row = $result ? $database->fetch_object($result) : false;
	if (!$row) throw new RuntimeException($sql.' / '.$database->lasterror());
	return $row;
}

function p3ConfigureRuntime(): void
{
	global $conf;
	$conf->entity = 1;
	$conf->currency = 'XOF';
	// Isolate native triggers from unrelated custom modules enabled in the developer database.
	$conf->modules = array('facture' => 1, 'dolivoucher' => 1);
	$conf->dolivoucher = new stdClass();
	$conf->dolivoucher->enabled = 1;
	$conf->global->MAIN_MODULE_DOLIVOUCHER = 1;
	$conf->global->MAIN_DISABLE_PDF_AUTOUPDATE = 0;
}

/** Load one official installer SQL file while preserving newlines required by inline SQL comments. */
function p3RunSqlFile(DoliDB $database, string $file, int $entity = 1): void
{
	$lines = file($file);
	p3Expect(is_array($lines), 'Cannot read SQL file '.$file);
	$buffer = '';
	foreach ($lines as $line) {
		if (preg_match('/^--\s+VMYSQL([0-9.]*)\s+(.*)$/i', rtrim($line), $matches)) {
			if ($matches[1] === '' || version_compare($database->getVersion(), $matches[1], '>=')) $buffer .= $matches[2]."\n";
			continue;
		}
		if (preg_match('/^\s*--/', $line)) continue;
		$line = preg_replace('/\s+--.*$/', '', $line)."\n";
		$buffer .= $line;
	}
	$statements = array();
	$statement = '';
	$quote = '';
	$escaped = false;
	for ($index = 0, $length = strlen($buffer); $index < $length; $index++) {
		$character = $buffer[$index];
		if ($escaped) {
			$statement .= $character;
			$escaped = false;
			continue;
		}
		if ($character === '\\' && $quote !== '') {
			$statement .= $character;
			$escaped = true;
			continue;
		}
		if (($character === "'" || $character === '"')) {
			if ($quote === '') $quote = $character;
			elseif ($quote === $character) {
				if ($index + 1 < $length && $buffer[$index + 1] === $character) {
					$statement .= $character.$character;
					$index++;
					continue;
				}
				$quote = '';
			}
		}
		if ($character === ';' && $quote === '') {
			if (trim($statement) !== '') $statements[] = trim($statement);
			$statement = '';
			continue;
		}
		$statement .= $character;
	}
	if (trim($statement) !== '') $statements[] = trim($statement);
	foreach ($statements as $statement) {
		$statement = str_replace('__ENTITY__', (string) $entity, $statement);
		if (!$database->query($statement)) {
			$accepted = array('DB_ERROR_TABLE_ALREADY_EXISTS', 'DB_ERROR_COLUMN_ALREADY_EXISTS', 'DB_ERROR_KEY_NAME_ALREADY_EXISTS', 'DB_ERROR_TABLE_OR_KEY_ALREADY_EXISTS', 'DB_ERROR_RECORD_ALREADY_EXISTS', 'DB_ERROR_CANNOT_CREATE', 'DB_ERROR_PRIMARY_KEY_ALREADY_EXISTS');
			p3Expect(in_array($database->errno(), $accepted, true), basename($file).': '.$database->lasterror());
		}
	}
}

/** @return int */
function p3Invoice(DoliDB $database, int $socid, int $userId, string $ref, string $amount, int $status = Facture::STATUS_VALIDATED, int $type = Facture::TYPE_STANDARD, string $currency = 'XOF'): int
{
	$now = $database->idate(dol_now());
	$sql = "INSERT INTO ".$database->prefix()."facture (ref,entity,type,fk_soc,datec,datef,date_valid,paye,total_ht,total_ttc,fk_statut,fk_user_author,fk_user_valid,fk_cond_reglement,multicurrency_code,multicurrency_tx,multicurrency_total_ht,multicurrency_total_ttc) VALUES ('".$database->escape($ref)."',1,".$type.",".$socid.",'".$now."','".$now."','".$now."',0,".$database->escape($amount).",".$database->escape($amount).",".$status.",".$userId.",".$userId.",1,'".$database->escape($currency)."',1,".$database->escape($amount).",".$database->escape($amount).")";
	p3Expect((bool) $database->query($sql), 'Cannot create invoice '.$ref.': '.$database->lasterror());
	return (int) $database->last_insert_id($database->prefix().'facture');
}

/** @return int */
function p3Voucher(DoliDB $database, DoliVoucherService $service, User $user, int $portfolioId, string $ref, string $amount, bool $activate = true): int
{
	$voucher = new DoliVoucherVoucher($database);
	$voucher->fk_portfolio = $portfolioId;
	$voucher->ref = $ref;
	$voucher->barcode = $ref.'-BAR';
	$voucher->initial_amount = $amount;
	$id = $service->createVoucher($voucher, $user, 'Phase 3 native integration');
	p3Expect($id > 0, 'Cannot create voucher '.$ref);
	if ($activate) p3Expect($service->activateVoucher($id, 1, $user) > 0, 'Cannot activate voucher '.$ref);
	return $id;
}

/** @return array{resource,resource,resource,resource} */
function p3StartWorker(string $databaseName, int $invoiceId, int $voucherId, string $amount, string $key): array
{
	$command = escapeshellarg(PHP_BINARY).' '.escapeshellarg(__FILE__).' worker '.escapeshellarg($databaseName).' '.$invoiceId.' '.$voucherId.' '.escapeshellarg($amount).' '.escapeshellarg($key);
	$descriptors = array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w'));
	$process = proc_open($command, $descriptors, $pipes, dirname(__DIR__));
	p3Expect(is_resource($process), 'Cannot start concurrent worker');
	fclose($pipes[0]);
	return array($process, $pipes[1], $pipes[2], $pipes[0] ?? null);
}

/** @param array{resource,resource,resource,mixed} $worker */
function p3FinishWorker(array $worker): array
{
	$output = stream_get_contents($worker[1]);
	$error = stream_get_contents($worker[2]);
	fclose($worker[1]);
	fclose($worker[2]);
	$exit = proc_close($worker[0]);
	return array('exit' => $exit, 'output' => trim((string) $output), 'error' => trim((string) $error));
}

if (($argv[1] ?? '') === 'worker') {
	$workerDatabase = (string) ($argv[2] ?? '');
	if (!preg_match('/^dolivoucher_phase3_native_[0-9]{14}_[a-f0-9]{6}$/', $workerDatabase)) exit(91);
	p3Expect((bool) $db->query('USE `'.$workerDatabase.'`'), 'Worker cannot select database');
	p3ConfigureRuntime();
	$user->fetch(1);
	$user->admin = 1;
	$workerService = new DoliVoucherInvoiceSettlementService($db);
	$result = $workerService->apply((int) $argv[3], (int) $argv[4], 1, (string) $argv[5], (string) $argv[6], $user, 'concurrent worker');
	echo json_encode(array('result' => $result, 'error' => $workerService->error));
	exit($result > 0 ? 0 : 3);
}

/** Test-only service with an inert production checkpoint override. */
class DoliVoucherFailingSettlementService extends DoliVoucherInvoiceSettlementService
{
	private string $failurePoint;

	public function __construct(DoliDB $database, string $failurePoint)
	{
		parent::__construct($database);
		$this->failurePoint = $failurePoint;
	}

	protected function checkpoint(string $point): void
	{
		if ($point === $this->failurePoint) throw new RuntimeException('TEST_FAILURE_'.$point);
	}
}

global $conf, $db, $user;
$originalDatabase = (string) p3One($db, 'SELECT DATABASE() AS name')->name;
$originalEntity = (int) $conf->entity;
$originalCurrency = (string) $conf->currency;
$databaseName = 'dolivoucher_phase3_native_'.date('YmdHis').'_'.bin2hex(random_bytes(3));
if (!preg_match('/^dolivoucher_phase3_native_[0-9]{14}_[a-f0-9]{6}$/', $databaseName)) throw new RuntimeException('Unsafe disposable database name');
$created = false;
$assertions = 0;

try {
	p3Expect((bool) $db->query('CREATE DATABASE `'.$databaseName.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'), 'Cannot create disposable database');
	$created = true;
	p3Expect((bool) $db->query('USE `'.$databaseName.'`'), 'Cannot select disposable database');

	$tableDirectory = DOL_DOCUMENT_ROOT.'/install/mysql/tables';
	$tableFiles = array_values(array_filter(scandir($tableDirectory), static fn (string $file): bool => preg_match('/^llx_.*\.sql$/i', $file) === 1 && strpos($file, '-') === false));
	sort($tableFiles);
	foreach ($tableFiles as $file) {
		if (str_ends_with($file, '.key.sql')) continue;
		p3RunSqlFile($db, $tableDirectory.'/'.$file);
	}
	foreach ($tableFiles as $file) {
		if (!str_ends_with($file, '.key.sql')) continue;
		p3RunSqlFile($db, $tableDirectory.'/'.$file);
	}
	$dataDirectory = DOL_DOCUMENT_ROOT.'/install/mysql/data';
	$dataFiles = array_values(array_filter(scandir($dataDirectory), static fn (string $file): bool => preg_match('/^llx_.*\.sql$/i', $file) === 1 && strpos($file, '-') === false && !str_starts_with($file, 'llx_accounting_account_')));
	sort($dataFiles);
	foreach ($dataFiles as $file) p3RunSqlFile($db, $dataDirectory.'/'.$file);

	p3ConfigureRuntime();
	p3Expect((bool) $db->query("INSERT INTO llx_user (rowid,entity,admin,datec,login,lastname,firstname,statut) VALUES (1,1,1,'".$db->idate(dol_now())."','phase3-admin','Phase3','Admin',1),(2,1,0,'".$db->idate(dol_now())."','phase3-noright','Phase3','NoRight',1)"), 'Cannot seed users');
	p3Expect((bool) $db->query("INSERT INTO llx_societe (rowid,nom,entity,status,statut,client,code_client,datec,fk_user_creat,multicurrency_code) VALUES (10,'PHASE3 CLIENT',1,1,1,1,'P3CLIENT','".$db->idate(dol_now())."',1,'XOF')"), 'Cannot seed customer');
	$user->fetch(1);
	$user->admin = 1;

	$module = new modDoliVoucher($db);
	p3Expect($module->init('') > 0, 'DoliVoucher normal installation failed');
	p3Expect($module->init('') > 0, 'DoliVoucher replay installation failed');
	$tableCount = (int) p3One($db, "SELECT COUNT(*) AS amount FROM information_schema.tables WHERE table_schema='".$db->escape($databaseName)."' AND table_name LIKE 'llx_dolivoucher_%'")->amount;
	p3Expect($tableCount === 8, 'Expected eight DoliVoucher tables, got '.$tableCount); $assertions++;
	p3Expect((int) p3One($db, "SELECT COUNT(*) AS amount FROM llx_c_paiement WHERE entity=1 AND code='DVOUCH'")->amount === 1, 'DVOUCH missing or duplicated'); $assertions++;
	p3Expect((int) p3One($db, "SELECT COUNT(*) AS amount FROM llx_c_paiement WHERE code='BONACH'")->amount === 0, 'Unexpected BONACH collision'); $assertions++;

	$voucherService = new DoliVoucherService($db);
	$portfolio = new DoliVoucherPortfolio($db);
	$portfolio->ref = 'P3-PORTFOLIO';
	$portfolio->label = 'Phase 3 portfolio';
	$portfolio->type = DoliVoucherPortfolio::TYPE_DONATION;
	$portfolioId = $portfolio->create($user, 1);
	p3Expect($portfolioId > 0 && $voucherService->validatePortfolio($portfolioId, 1, $user) > 0 && $voucherService->fundPortfolio($portfolioId, 1, '1000000', $user, 'Phase 3 funding') > 0, 'Portfolio setup failed');

	$settlementService = new DoliVoucherInvoiceSettlementService($db);
	$totalInvoice = p3Invoice($db, 10, 1, 'P3-TOTAL', '10000');
	$totalVoucher = p3Voucher($db, $voucherService, $user, $portfolioId, 'P3-V-TOTAL', '10000');
	$totalSettlement = $settlementService->apply($totalInvoice, $totalVoucher, 1, null, DoliVoucherService::uuid(), $user, 'total settlement');
	p3Expect($totalSettlement > 0, 'Total settlement failed: '.$settlementService->error.' / '.implode(' | ', $settlementService->errors)); $assertions++;
	$totalLink = p3One($db, 'SELECT * FROM llx_dolivoucher_invoice_settlement WHERE rowid='.$totalSettlement);
	p3Expect($totalLink->event_type === 'APPLY' && (string) $totalLink->amount === '10000.00000000', 'Total APPLY link invalid'); $assertions++;
	p3Expect((int) p3One($db, 'SELECT COUNT(*) AS amount FROM llx_paiement WHERE rowid='.(int) $totalLink->fk_paiement.' AND fk_bank=0')->amount === 1, 'Native unbanked payment missing'); $assertions++;
	p3Expect(DoliVoucherMoney::compare((string) p3One($db, 'SELECT amount FROM llx_paiement_facture WHERE fk_paiement='.(int) $totalLink->fk_paiement)->amount, '10000') === 0, 'Native allocation invalid'); $assertions++;
	$totalFacture = new Facture($db); $totalFacture->fetch($totalInvoice);
	p3Expect((string) price2num((string) $totalFacture->getRemainToPay(), 'MT') === '0' && (int) $totalFacture->status === Facture::STATUS_CLOSED, 'Invoice not natively closed'); $assertions++;
	p3Expect((string) p3One($db, 'SELECT current_balance AS amount FROM llx_dolivoucher_voucher WHERE rowid='.$totalVoucher)->amount === '0.00000000', 'Total voucher balance invalid'); $assertions++;
	p3Expect((int) p3One($db, 'SELECT COUNT(*) AS amount FROM llx_bank')->amount === 0 && (int) p3One($db, 'SELECT COUNT(*) AS amount FROM llx_bank_url')->amount === 0, 'A bank line was created'); $assertions++;

	$partialInvoice = p3Invoice($db, 10, 1, 'P3-PARTIAL', '20000');
	$partialVoucher = p3Voucher($db, $voucherService, $user, $portfolioId, 'P3-V-PARTIAL', '15000');
	p3Expect($settlementService->apply($partialInvoice, $partialVoucher, 1, '7000', DoliVoucherService::uuid(), $user, 'partial settlement') > 0, 'Partial settlement failed'); $assertions++;
	$partialFacture = new Facture($db); $partialFacture->fetch($partialInvoice);
	p3Expect((string) price2num((string) $partialFacture->getRemainToPay(), 'MT') === '13000' && (int) $partialFacture->status === Facture::STATUS_VALIDATED, 'Partial invoice remainder/status invalid'); $assertions++;
	p3Expect((string) p3One($db, 'SELECT current_balance AS amount FROM llx_dolivoucher_voucher WHERE rowid='.$partialVoucher)->amount === '8000.00000000', 'Partial voucher remainder invalid'); $assertions++;

	$multiInvoice = p3Invoice($db, 10, 1, 'P3-MULTI', '12000');
	$multiVoucherA = p3Voucher($db, $voucherService, $user, $portfolioId, 'P3-V-MULTI-A', '5000');
	$multiVoucherB = p3Voucher($db, $voucherService, $user, $portfolioId, 'P3-V-MULTI-B', '10000');
	p3Expect($settlementService->apply($multiInvoice, $multiVoucherA, 1, null, DoliVoucherService::uuid(), $user) > 0, 'First multi-voucher settlement failed'); $assertions++;
	p3Expect($settlementService->apply($multiInvoice, $multiVoucherB, 1, '7000', DoliVoucherService::uuid(), $user) > 0, 'Second multi-voucher settlement failed'); $assertions++;
	p3Expect($settlementService->apply($multiInvoice, $multiVoucherB, 1, '1', DoliVoucherService::uuid(), $user) < 0, 'Settlement accepted on paid invoice'); $assertions++;
	p3Expect((int) p3One($db, 'SELECT COUNT(DISTINCT fk_paiement) AS amount FROM llx_dolivoucher_invoice_settlement WHERE fk_facture='.$multiInvoice." AND event_type='APPLY'")->amount === 2, 'Payments were not distinct by voucher operation'); $assertions++;

	$splitVoucher = p3Voucher($db, $voucherService, $user, $portfolioId, 'P3-V-SPLIT', '9000');
	$splitInvoiceA = p3Invoice($db, 10, 1, 'P3-SPLIT-A', '4000');
	$splitInvoiceB = p3Invoice($db, 10, 1, 'P3-SPLIT-B', '6000');
	p3Expect($settlementService->apply($splitInvoiceA, $splitVoucher, 1, null, DoliVoucherService::uuid(), $user) > 0 && $settlementService->apply($splitInvoiceB, $splitVoucher, 1, '3000', DoliVoucherService::uuid(), $user) > 0, 'One voucher across invoices failed'); $assertions++;
	p3Expect((string) p3One($db, 'SELECT current_balance AS amount FROM llx_dolivoucher_voucher WHERE rowid='.$splitVoucher)->amount === '2000.00000000', 'Split voucher final balance invalid'); $assertions++;

	$combinedInvoice = p3Invoice($db, 10, 1, 'P3-COMBINED', '10000');
	$classicMode = (int) p3One($db, "SELECT id FROM llx_c_paiement WHERE entity IN (0,1) AND active=1 AND code<>'DVOUCH' ORDER BY entity DESC,id LIMIT 1")->id;
	$classicPayment = new Paiement($db);
	$classicPayment->datepaye = dol_now(); $classicPayment->amounts = array($combinedInvoice => '2500'); $classicPayment->paiementid = $classicMode;
	$conf->global->MAIN_DISABLE_PDF_AUTOUPDATE = 1;
	p3Expect($classicPayment->create($user, 0) > 0, 'Classic payment setup failed: '.$classicPayment->error.' / '.implode(' | ', $classicPayment->errors));
	$conf->global->MAIN_DISABLE_PDF_AUTOUPDATE = 0;
	$combinedVoucher = p3Voucher($db, $voucherService, $user, $portfolioId, 'P3-V-COMBINED', '10000');
	p3Expect($settlementService->apply($combinedInvoice, $combinedVoucher, 1, null, DoliVoucherService::uuid(), $user) > 0, 'Combined settlement failed'); $assertions++;
	p3Expect((string) p3One($db, 'SELECT current_balance AS amount FROM llx_dolivoucher_voucher WHERE rowid='.$combinedVoucher)->amount === '2500.00000000', 'Combined maximum was not native remainder'); $assertions++;

	$draftInvoice = p3Invoice($db, 10, 1, 'P3-DRAFT', '1000', Facture::STATUS_DRAFT);
	$canceledInvoice = p3Invoice($db, 10, 1, 'P3-CANCELED', '1000', Facture::STATUS_ABANDONED);
	$refusalVoucher = p3Voucher($db, $voucherService, $user, $portfolioId, 'P3-V-REFUSAL', '5000');
	p3Expect($settlementService->apply($draftInvoice, $refusalVoucher, 1, '1', DoliVoucherService::uuid(), $user) < 0, 'Draft invoice accepted'); $assertions++;
	p3Expect($settlementService->apply($canceledInvoice, $refusalVoucher, 1, '1', DoliVoucherService::uuid(), $user) < 0, 'Canceled invoice accepted'); $assertions++;
	p3Expect($settlementService->apply($partialInvoice, $refusalVoucher, 1, '0', DoliVoucherService::uuid(), $user) < 0 && $settlementService->apply($partialInvoice, $refusalVoucher, 1, '-1', DoliVoucherService::uuid(), $user) < 0, 'Zero or negative amount accepted'); $assertions++;
	p3Expect($settlementService->apply($partialInvoice, $refusalVoucher, 1, '999999', DoliVoucherService::uuid(), $user) < 0, 'Amount above limits accepted'); $assertions++;
	$draftVoucher = p3Voucher($db, $voucherService, $user, $portfolioId, 'P3-V-DRAFT', '1000', false);
	p3Expect($settlementService->apply($partialInvoice, $draftVoucher, 1, '1', DoliVoucherService::uuid(), $user) < 0, 'Draft voucher accepted'); $assertions++;
	p3Expect($voucherService->blockVoucher($refusalVoucher, 1, $user, 'test block') > 0 && $settlementService->apply($partialInvoice, $refusalVoucher, 1, '1', DoliVoucherService::uuid(), $user) < 0, 'Blocked voucher accepted'); $assertions++;
	p3Expect($voucherService->unblockVoucher($refusalVoucher, 1, $user, 'test unblock') > 0, 'Cannot unblock refusal voucher');
	$noRight = new User($db); $noRight->fetch(2); $noRight->admin = 0;
	p3Expect($settlementService->apply($partialInvoice, $refusalVoucher, 1, '1', DoliVoucherService::uuid(), $noRight) < 0, 'User without rights accepted'); $assertions++;
	p3Expect($settlementService->apply($partialInvoice, $refusalVoucher, 2, '1', DoliVoucherService::uuid(), $user) < 0, 'Cross-entity settlement accepted'); $assertions++;

	$idempotentInvoice = p3Invoice($db, 10, 1, 'P3-IDEMPOTENT', '5000');
	$idempotentVoucher = p3Voucher($db, $voucherService, $user, $portfolioId, 'P3-V-IDEMPOTENT', '5000');
	$idempotencyKey = DoliVoucherService::uuid();
	$firstIdempotent = $settlementService->apply($idempotentInvoice, $idempotentVoucher, 1, '2000', $idempotencyKey, $user);
	$secondIdempotent = $settlementService->apply($idempotentInvoice, $idempotentVoucher, 1, '2000', $idempotencyKey, $user);
	p3Expect($firstIdempotent > 0 && $secondIdempotent === $firstIdempotent, 'Identical replay did not return existing settlement'); $assertions++;
	p3Expect($settlementService->apply($idempotentInvoice, $idempotentVoucher, 1, '1000', $idempotencyKey, $user) < 0, 'Divergent amount reused idempotency key'); $assertions++;
	p3Expect($settlementService->apply($partialInvoice, $idempotentVoucher, 1, '2000', $idempotencyKey, $user) < 0, 'Different invoice reused idempotency key'); $assertions++;
	p3Expect($settlementService->apply($idempotentInvoice, $refusalVoucher, 1, '2000', $idempotencyKey, $user) < 0, 'Different voucher reused idempotency key'); $assertions++;
	p3Expect((int) p3One($db, "SELECT COUNT(*) AS amount FROM llx_dolivoucher_invoice_settlement WHERE idempotency_key='".$db->escape($idempotencyKey)."'")->amount === 1, 'Idempotent replay duplicated link'); $assertions++;

	foreach (array('after_idempotency_lock', 'after_invoice_lock', 'after_native_payment', 'after_voucher_operation', 'after_apply_link', 'before_invoice_close', 'before_commit') as $failurePoint) {
		$failureInvoice = p3Invoice($db, 10, 1, 'P3-FAIL-'.strtoupper($failurePoint), '1000');
		$failureVoucher = p3Voucher($db, $voucherService, $user, $portfolioId, 'P3-V-FAIL-'.strtoupper($failurePoint), '1000');
		$failureKey = DoliVoucherService::uuid();
		$failingService = new DoliVoucherFailingSettlementService($db, $failurePoint);
		p3Expect($failingService->apply($failureInvoice, $failureVoucher, 1, '500', $failureKey, $user) < 0, 'Injected failure did not fail at '.$failurePoint);
		p3Expect((int) p3One($db, "SELECT COUNT(*) AS amount FROM llx_dolivoucher_invoice_settlement WHERE idempotency_key='".$db->escape($failureKey)."'")->amount === 0, 'Settlement survived '.$failurePoint);
		p3Expect((int) p3One($db, 'SELECT COUNT(*) AS amount FROM llx_paiement p INNER JOIN llx_paiement_facture pf ON pf.fk_paiement=p.rowid WHERE pf.fk_facture='.$failureInvoice)->amount === 0, 'Native payment survived '.$failurePoint);
		p3Expect((int) p3One($db, 'SELECT COUNT(*) AS amount FROM llx_paiement_facture WHERE fk_facture='.$failureInvoice)->amount === 0, 'Payment allocation survived '.$failurePoint);
		p3Expect((int) p3One($db, "SELECT COUNT(*) AS amount FROM llx_dolivoucher_operation WHERE fk_voucher=".$failureVoucher." AND operation_type='CONSUME'")->amount === 0, 'Voucher operation survived '.$failurePoint);
		p3Expect((string) p3One($db, 'SELECT current_balance AS amount FROM llx_dolivoucher_voucher WHERE rowid='.$failureVoucher)->amount === '1000.00000000', 'Voucher debit survived '.$failurePoint);
		$failureFacture = new Facture($db); $failureFacture->fetch($failureInvoice);
		p3Expect(DoliVoucherMoney::compare((string) $failureFacture->getRemainToPay(), '1000') === 0 && (int) $failureFacture->status === Facture::STATUS_VALIDATED, 'Invoice changed after '.$failurePoint);
		$assertions++;
	}

	$reverseInvoice = p3Invoice($db, 10, 1, 'P3-REVERSE', '6000');
	$reverseVoucher = p3Voucher($db, $voucherService, $user, $portfolioId, 'P3-V-REVERSE', '6000');
	$applyId = $settlementService->apply($reverseInvoice, $reverseVoucher, 1, null, DoliVoucherService::uuid(), $user, 'to reverse');
	p3Expect($applyId > 0, 'Reversal setup failed');
	$applyRow = p3One($db, 'SELECT * FROM llx_dolivoucher_invoice_settlement WHERE rowid='.$applyId);
	$nativePayment = new Paiement($db); $nativePayment->fetch((int) $applyRow->fk_paiement);
	p3Expect($nativePayment->delete($user) < 0, 'Native payment deletion was not blocked'); $assertions++;
	p3Expect((int) p3One($db, 'SELECT COUNT(*) AS amount FROM llx_paiement WHERE rowid='.(int) $applyRow->fk_paiement)->amount === 1, 'Blocked deletion removed payment'); $assertions++;
	$reversalKey = DoliVoucherService::uuid();
	$reversalId = $settlementService->reverse($applyId, 1, $reversalKey, $user, 'controlled reversal');
	p3Expect($reversalId > 0, 'Controlled reversal failed: '.$settlementService->error); $assertions++;
	p3Expect((int) p3One($db, 'SELECT COUNT(*) AS amount FROM llx_paiement WHERE rowid='.(int) $applyRow->fk_paiement)->amount === 0, 'Controlled reversal retained payment'); $assertions++;
	p3Expect((string) p3One($db, 'SELECT current_balance AS amount FROM llx_dolivoucher_voucher WHERE rowid='.$reverseVoucher)->amount === '6000.00000000', 'Controlled reversal did not restore voucher'); $assertions++;
	p3Expect($settlementService->reverse($applyId, 1, DoliVoucherService::uuid(), $user, 'duplicate reversal') < 0, 'Double reversal accepted'); $assertions++;
	p3Expect($settlementService->reverse($applyId, 1, DoliVoucherService::uuid(), $user, '') < 0, 'Empty reversal reason accepted'); $assertions++;
	p3Expect(!DoliVoucherInvoiceSettlementService::isPaymentDeletionAuthorized(), 'Internal deletion context leaked'); $assertions++;
	p3Expect($voucherService->compensateConsumption((int) $applyRow->fk_operation, 1, $user, 'generic compensation') < 0, 'Generic compensation accepted invoice consumption'); $assertions++;

	$partialReverseInvoice = p3Invoice($db, 10, 1, 'P3-REVERSE-PARTIAL', '8000');
	$partialReverseVoucher = p3Voucher($db, $voucherService, $user, $portfolioId, 'P3-V-REVERSE-PARTIAL', '5000');
	$partialApplyId = $settlementService->apply($partialReverseInvoice, $partialReverseVoucher, 1, '3000', DoliVoucherService::uuid(), $user);
	p3Expect($partialApplyId > 0 && $settlementService->reverse($partialApplyId, 1, DoliVoucherService::uuid(), $user, 'partial reversal') > 0, 'Partial reversal failed');
	p3Expect((string) p3One($db, 'SELECT current_balance AS amount FROM llx_dolivoucher_voucher WHERE rowid='.$partialReverseVoucher)->amount === '5000.00000000', 'Partial reversal did not restore voucher'); $assertions++;

	$rollbackReverseInvoice = p3Invoice($db, 10, 1, 'P3-REVERSE-ROLLBACK', '4000');
	$rollbackReverseVoucher = p3Voucher($db, $voucherService, $user, $portfolioId, 'P3-V-REVERSE-ROLLBACK', '4000');
	$rollbackApplyId = $settlementService->apply($rollbackReverseInvoice, $rollbackReverseVoucher, 1, null, DoliVoucherService::uuid(), $user);
	$rollbackApply = p3One($db, 'SELECT * FROM llx_dolivoucher_invoice_settlement WHERE rowid='.$rollbackApplyId);
	$failingReversal = new DoliVoucherFailingSettlementService($db, 'reversal_before_commit');
	p3Expect($failingReversal->reverse($rollbackApplyId, 1, DoliVoucherService::uuid(), $user, 'forced reversal rollback') < 0, 'Injected reversal failure did not fail');
	p3Expect((int) p3One($db, 'SELECT COUNT(*) AS amount FROM llx_paiement WHERE rowid='.(int) $rollbackApply->fk_paiement)->amount === 1, 'Reversal rollback lost payment');
	p3Expect((string) p3One($db, 'SELECT current_balance AS amount FROM llx_dolivoucher_voucher WHERE rowid='.$rollbackReverseVoucher)->amount === '0.00000000', 'Reversal rollback restored voucher');
	p3Expect((int) p3One($db, 'SELECT COUNT(*) AS amount FROM llx_dolivoucher_invoice_settlement WHERE reversal_of='.$rollbackApplyId)->amount === 0, 'Reversal rollback retained REVERSAL'); $assertions++;
	p3Expect(!DoliVoucherInvoiceSettlementService::isPaymentDeletionAuthorized(), 'Deletion context leaked after reversal exception'); $assertions++;

	$bankedInvoice = p3Invoice($db, 10, 1, 'P3-NONREV-BANK', '2000');
	$bankedVoucher = p3Voucher($db, $voucherService, $user, $portfolioId, 'P3-V-NONREV-BANK', '2000');
	$bankedApplyId = $settlementService->apply($bankedInvoice, $bankedVoucher, 1, '1000', DoliVoucherService::uuid(), $user);
	$bankedApply = p3One($db, 'SELECT * FROM llx_dolivoucher_invoice_settlement WHERE rowid='.$bankedApplyId);
	p3Expect((bool) $db->query('UPDATE llx_paiement SET fk_bank=123 WHERE rowid='.(int) $bankedApply->fk_paiement), 'Cannot simulate banked payment');
	p3Expect($settlementService->reverse($bankedApplyId, 1, DoliVoucherService::uuid(), $user, 'must refuse banked') < 0, 'Banked payment reversal accepted');
	p3Expect((string) p3One($db, 'SELECT current_balance AS amount FROM llx_dolivoucher_voucher WHERE rowid='.$bankedVoucher)->amount === '1000.00000000', 'Banked refusal changed voucher'); $assertions++;

	$exportedInvoice = p3Invoice($db, 10, 1, 'P3-NONREV-EXPORT', '2000');
	$exportedVoucher = p3Voucher($db, $voucherService, $user, $portfolioId, 'P3-V-NONREV-EXPORT', '2000');
	$exportedApplyId = $settlementService->apply($exportedInvoice, $exportedVoucher, 1, '1000', DoliVoucherService::uuid(), $user);
	$exportedApply = p3One($db, 'SELECT * FROM llx_dolivoucher_invoice_settlement WHERE rowid='.$exportedApplyId);
	p3Expect((bool) $db->query('UPDATE llx_paiement SET fk_export_compta=123 WHERE rowid='.(int) $exportedApply->fk_paiement), 'Cannot simulate exported payment');
	p3Expect($settlementService->reverse($exportedApplyId, 1, DoliVoucherService::uuid(), $user, 'must refuse exported') < 0, 'Exported payment reversal accepted');
	p3Expect((string) p3One($db, 'SELECT current_balance AS amount FROM llx_dolivoucher_voucher WHERE rowid='.$exportedVoucher)->amount === '1000.00000000', 'Exported refusal changed voucher'); $assertions++;

	$concurrentInvoice = p3Invoice($db, 10, 1, 'P3-CONCURRENT', '5000');
	$concurrentVoucher = p3Voucher($db, $voucherService, $user, $portfolioId, 'P3-V-CONCURRENT', '5000');
	$concurrentKey = DoliVoucherService::uuid();
	$workerA = p3StartWorker($databaseName, $concurrentInvoice, $concurrentVoucher, '5000', $concurrentKey);
	$workerB = p3StartWorker($databaseName, $concurrentInvoice, $concurrentVoucher, '5000', $concurrentKey);
	$resultA = p3FinishWorker($workerA);
	$resultB = p3FinishWorker($workerB);
	$successfulWorkers = ($resultA['exit'] === 0 ? 1 : 0) + ($resultB['exit'] === 0 ? 1 : 0);
	p3Expect($successfulWorkers >= 1 && (int) p3One($db, "SELECT COUNT(*) AS amount FROM llx_dolivoucher_invoice_settlement WHERE idempotency_key='".$db->escape($concurrentKey)."'")->amount === 1, 'Concurrent idempotency did not converge'); $assertions++;
	p3Expect((int) p3One($db, 'SELECT COUNT(*) AS amount FROM llx_paiement_facture WHERE fk_facture='.$concurrentInvoice)->amount === 1, 'Concurrent idempotency duplicated native allocation'); $assertions++;

	$sameVoucher = p3Voucher($db, $voucherService, $user, $portfolioId, 'P3-V-CONCURRENT-TWO-INVOICES', '5000');
	$sameVoucherInvoiceA = p3Invoice($db, 10, 1, 'P3-CONCURRENT-VOUCHER-A', '5000');
	$sameVoucherInvoiceB = p3Invoice($db, 10, 1, 'P3-CONCURRENT-VOUCHER-B', '5000');
	$workerA = p3StartWorker($databaseName, $sameVoucherInvoiceA, $sameVoucher, '5000', DoliVoucherService::uuid());
	$workerB = p3StartWorker($databaseName, $sameVoucherInvoiceB, $sameVoucher, '5000', DoliVoucherService::uuid());
	$resultA = p3FinishWorker($workerA); $resultB = p3FinishWorker($workerB);
	p3Expect(($resultA['exit'] === 0 ? 1 : 0) + ($resultB['exit'] === 0 ? 1 : 0) === 1, 'Same voucher concurrency did not admit exactly one request');
	p3Expect((string) p3One($db, 'SELECT current_balance AS amount FROM llx_dolivoucher_voucher WHERE rowid='.$sameVoucher)->amount === '0.00000000', 'Same voucher concurrency overspent or retained balance'); $assertions++;

	$sameInvoice = p3Invoice($db, 10, 1, 'P3-CONCURRENT-TWO-VOUCHERS', '5000');
	$sameInvoiceVoucherA = p3Voucher($db, $voucherService, $user, $portfolioId, 'P3-V-CONCURRENT-INVOICE-A', '5000');
	$sameInvoiceVoucherB = p3Voucher($db, $voucherService, $user, $portfolioId, 'P3-V-CONCURRENT-INVOICE-B', '5000');
	$workerA = p3StartWorker($databaseName, $sameInvoice, $sameInvoiceVoucherA, '5000', DoliVoucherService::uuid());
	$workerB = p3StartWorker($databaseName, $sameInvoice, $sameInvoiceVoucherB, '5000', DoliVoucherService::uuid());
	$resultA = p3FinishWorker($workerA); $resultB = p3FinishWorker($workerB);
	p3Expect(($resultA['exit'] === 0 ? 1 : 0) + ($resultB['exit'] === 0 ? 1 : 0) === 1, 'Same invoice concurrency did not admit exactly one request: '.json_encode(array($resultA, $resultB)));
	p3Expect(DoliVoucherMoney::compare((string) p3One($db, 'SELECT COALESCE(SUM(amount),0) AS amount FROM llx_paiement_facture WHERE fk_facture='.$sameInvoice)->amount, '5000') === 0, 'Same invoice concurrency overpaid invoice'); $assertions++;

	$diagnosticService = new DoliVoucherSettlementDiagnosticService($db);
	$mutationBefore = p3One($db, 'SELECT (SELECT COUNT(*) FROM llx_dolivoucher_operation) AS operations, (SELECT COUNT(*) FROM llx_paiement) AS payments, (SELECT COUNT(*) FROM llx_paiement_facture) AS allocations');
	$totalDiagnostic = $diagnosticService->fetchOne($totalSettlement, 1);
	p3Expect($totalDiagnostic !== null && $totalDiagnostic['diagnostic']['level'] === DoliVoucherSettlementDiagnosticService::LEVEL_OK, 'Active settlement diagnostic is not coherent'); $assertions++;
	$partialSettlementId = (int) p3One($db, "SELECT rowid FROM llx_dolivoucher_invoice_settlement WHERE fk_facture=".$partialInvoice." AND event_type='APPLY'")->rowid;
	p3Expect($diagnosticService->fetchOne($partialSettlementId, 1)['diagnostic']['level'] === DoliVoucherSettlementDiagnosticService::LEVEL_OK, 'Partial settlement diagnostic is not coherent'); $assertions++;
	$multiDiagnostics = $diagnosticService->fetchPage(1, array('invoice' => 'P3-MULTI'), 10, 0, 's.date_creation', 'DESC', 1000);
	p3Expect(count($multiDiagnostics['items']) === 2 && $multiDiagnostics['items'][0]['diagnostic']['level'] === DoliVoucherSettlementDiagnosticService::LEVEL_OK && $multiDiagnostics['items'][1]['diagnostic']['level'] === DoliVoucherSettlementDiagnosticService::LEVEL_OK, 'Multiple vouchers on one invoice are not coherent'); $assertions++;
	$splitDiagnostics = $diagnosticService->fetchPage(1, array('voucher' => 'P3-V-SPLIT'), 10, 0, 's.date_creation', 'DESC', 1000);
	p3Expect(count($splitDiagnostics['items']) === 2, 'One voucher across invoices filter failed'); $assertions++;
	$pagedDiagnostics = $diagnosticService->fetchPage(1, array(), 1, 0, 's.date_creation', 'DESC', 1000);
	p3Expect(count($pagedDiagnostics['items']) === 1 && $pagedDiagnostics['has_more'], 'Diagnostic pagination failed'); $assertions++;
	$reversedDiagnostic = $diagnosticService->fetchOne($applyId, 1);
	p3Expect($reversedDiagnostic !== null && $reversedDiagnostic['diagnostic']['level'] === DoliVoucherSettlementDiagnosticService::LEVEL_OK, 'Reversed settlement diagnostic is not coherent: '.json_encode($reversedDiagnostic)); $assertions++;
	$exportedDiagnostic = $diagnosticService->fetchOne($exportedApplyId, 1);
	p3Expect($exportedDiagnostic !== null && $exportedDiagnostic['diagnostic']['level'] === DoliVoucherSettlementDiagnosticService::LEVEL_WARNING && in_array(DoliVoucherSettlementDiagnosticService::CODE_PAYMENT_EXPORTED, $exportedDiagnostic['diagnostic']['codes'], true), 'Exported payment warning missing'); $assertions++;
	$warningDiagnostics = $diagnosticService->fetchPage(1, array('diagnostic_level' => 'WARNING', 'diagnostic_code' => 'PAYMENT_EXPORTED'), 10, 0, 's.date_creation', 'DESC', 1000);
	p3Expect(count($warningDiagnostics['items']) === 1 && (int) $warningDiagnostics['items'][0]['record']->rowid === $exportedApplyId, 'Diagnostic level/code filters failed'); $assertions++;
	$bankedDiagnostic = $diagnosticService->fetchOne($bankedApplyId, 1);
	p3Expect($bankedDiagnostic !== null && $bankedDiagnostic['diagnostic']['level'] === DoliVoucherSettlementDiagnosticService::LEVEL_ERROR && in_array(DoliVoucherSettlementDiagnosticService::CODE_UNEXPECTED_BANK_LINE, $bankedDiagnostic['diagnostic']['codes'], true), 'Unexpected bank line error missing'); $assertions++;
	$filteredDiagnostics = $diagnosticService->fetchPage(1, array('voucher' => 'P3-V-TOTAL', 'request_source' => 'INVOICE_CARD'), 10, 0, 's.date_creation', 'DESC', 1000);
	p3Expect(count($filteredDiagnostics['items']) === 1 && (int) $filteredDiagnostics['items'][0]['record']->rowid === $totalSettlement, 'Voucher/source diagnostic filters failed'); $assertions++;
	$mutationAfter = p3One($db, 'SELECT (SELECT COUNT(*) FROM llx_dolivoucher_operation) AS operations, (SELECT COUNT(*) FROM llx_paiement) AS payments, (SELECT COUNT(*) FROM llx_paiement_facture) AS allocations');
	p3Expect((string) $mutationBefore->operations === (string) $mutationAfter->operations && (string) $mutationBefore->payments === (string) $mutationAfter->payments && (string) $mutationBefore->allocations === (string) $mutationAfter->allocations, 'Read-only diagnostics mutated financial tables'); $assertions++;
	p3Expect($diagnosticService->fetchOne($totalSettlement, 2) === null, 'Cross-entity diagnostic access succeeded'); $assertions++;

	p3Expect((int) p3One($db, 'SELECT COUNT(*) AS amount FROM llx_bank')->amount === 0 && (int) p3One($db, 'SELECT COUNT(*) AS amount FROM llx_bank_url')->amount === 0, 'Phase 3A created bank data'); $assertions++;
	p3Expect((int) p3One($db, 'SELECT COUNT(*) AS amount FROM llx_accounting_bookkeeping')->amount === 0, 'Phase 3A created accounting entries'); $assertions++;
	echo 'DoliVoucher Phase 3 native integration: OK ('.$assertions." assertion groups, database ".$databaseName.").\n";
} finally {
	$db->query('USE `'.$db->escape($originalDatabase).'`');
	$conf->entity = $originalEntity;
	$conf->currency = $originalCurrency;
	if ($created && preg_match('/^dolivoucher_phase3_native_[0-9]{14}_[a-f0-9]{6}$/', $databaseName)) $db->query('DROP DATABASE `'.$databaseName.'`');
}
