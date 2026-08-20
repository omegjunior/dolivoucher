<?php
/* Copyright (C) 2026 Fred Omega Junior */

declare(strict_types=1);

if (getenv('DOLIVOUCHER_INTEGRATION_SMOKE') !== '1') {
	fwrite(STDERR, "Set DOLIVOUCHER_INTEGRATION_SMOKE=1 to run the isolated transactional smoke test.\n");
	exit(2);
}

require_once dirname(__DIR__, 3).'/master.inc.php';
require_once dirname(__DIR__).'/class/dolivoucherportfolio.class.php';
require_once dirname(__DIR__).'/class/dolivouchervoucher.class.php';
require_once dirname(__DIR__).'/class/dolivoucheroperation.class.php';
require_once dirname(__DIR__).'/class/dolivoucherservice.class.php';

/** @throws RuntimeException */
function dvExpect(bool $condition, string $message): void
{
	if (!$condition) throw new RuntimeException($message);
}

/** @return object */
function dvOne(DoliDB $db, string $sql): object
{
	$result = $db->query($sql);
	$row = $result ? $db->fetch_object($result) : false;
	if (!$row) throw new RuntimeException('Query failed: '.$sql.' / '.$db->lasterror());
	return $row;
}

/** @return int */
function dvPortfolio(DoliDB $db, User $user, string $ref, string $type, ?int $socid): int
{
	$portfolio = new DoliVoucherPortfolio($db);
	$portfolio->ref = $ref;
	$portfolio->label = $ref;
	$portfolio->type = $type;
	$portfolio->fk_soc = $socid;
	return $portfolio->create($user, 1);
}

/** @return int */
function dvVoucher(DoliDB $db, DoliVoucherService $service, User $user, int $portfolio, string $ref, string $amount, ?int $expiration = null): int
{
	$voucher = new DoliVoucherVoucher($db);
	$voucher->fk_portfolio = $portfolio;
	$voucher->ref = $ref;
	$voucher->initial_amount = $amount;
	$voucher->date_expiration = $expiration;
	return $service->createVoucher($voucher, $user, 'integration smoke');
}

global $conf, $db, $user;
if (empty($user->id)) {
	$user->fetch(1);
}
$originalEntity = (int) $conf->entity;
$originalDatabase = (string) dvOne($db, 'SELECT DATABASE() AS name')->name;
$testDatabase = 'dolivoucher_integration_test_'.date('YmdHis').'_'.bin2hex(random_bytes(3));
if (!preg_match('/^dolivoucher_integration_test_[0-9]{14}_[a-f0-9]{6}$/', $testDatabase)) throw new RuntimeException('Unsafe test database name');
$created = false;
$assertions = 0;

try {
	dvExpect((bool) $db->query('CREATE DATABASE `'.$testDatabase.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'), 'Cannot create isolated database');
	$created = true;
	dvExpect((bool) $db->query('USE `'.$testDatabase.'`'), 'Cannot select isolated database');
	foreach (array('llx_dolivoucher_operation.sql', 'llx_dolivoucher_portfolio.sql', 'llx_dolivoucher_voucher.sql', 'llx_dolivoucher_operation.key.sql', 'llx_dolivoucher_portfolio.key.sql', 'llx_dolivoucher_voucher.key.sql') as $file) {
		$sql = (string) file_get_contents(dirname(__DIR__).'/sql/'.$file);
		foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) dvExpect((bool) $db->query($statement), $file.': '.$db->lasterror());
	}
	dvExpect((bool) $db->query('CREATE TABLE llx_societe (rowid INTEGER PRIMARY KEY, entity INTEGER NOT NULL) ENGINE=innodb'), 'Cannot create test third-party table');
	dvExpect((bool) $db->query('INSERT INTO llx_societe(rowid,entity) VALUES (10,1),(20,1)'), 'Cannot seed third parties');
	$conf->entity = 1;
	$service = new DoliVoucherService($db);

	dvExpect(dvPortfolio($db, $user, 'BAD-INST', DoliVoucherPortfolio::TYPE_INSTITUTIONAL, null) < 0, 'Institution without third party accepted'); $assertions++;
	$donation = dvPortfolio($db, $user, 'DON-1', DoliVoucherPortfolio::TYPE_DONATION, null);
	dvExpect($donation > 0, 'Donation without third party refused'); $assertions++;
	$source = dvPortfolio($db, $user, 'INST-1', DoliVoucherPortfolio::TYPE_INSTITUTIONAL, 10);
	$destination = dvPortfolio($db, $user, 'INST-2', DoliVoucherPortfolio::TYPE_INSTITUTIONAL, 10);
	$otherThirdParty = dvPortfolio($db, $user, 'INST-3', DoliVoucherPortfolio::TYPE_INSTITUTIONAL, 20);
	$failureDestination = dvPortfolio($db, $user, 'INST-ROLLBACK', DoliVoucherPortfolio::TYPE_INSTITUTIONAL, 10);
	dvExpect($source > 0 && $destination > 0 && $otherThirdParty > 0 && $failureDestination > 0, 'Valid institutional portfolio refused'); $assertions++;
	dvExpect(dvPortfolio($db, $user, 'INST-1', DoliVoucherPortfolio::TYPE_INSTITUTIONAL, 10) < 0, 'Duplicate portfolio reference accepted'); $assertions++;
	foreach (array($source, $destination, $otherThirdParty, $failureDestination) as $portfolioId) dvExpect($service->validatePortfolio($portfolioId, 1, $user) > 0, 'Portfolio validation failed');
	dvExpect($service->fundPortfolio($source, 1, '1000', $user, 'initial funding') > 0, 'Funding failed'); $assertions++;
	dvExpect($service->fundPortfolio($source, 2, '100', $user, 'wrong entity') < 0, 'Cross-entity funding accepted'); $assertions++;

	dvExpect(dvVoucher($db, $service, $user, $source, 'BAD-ZERO', '0') < 0, 'Zero voucher accepted'); $assertions++;
	$v1 = dvVoucher($db, $service, $user, $source, 'V-1', '300');
	dvExpect($v1 > 0, 'Valid voucher refused'); $assertions++;
	dvExpect(dvVoucher($db, $service, $user, $source, 'V-1', '10') < 0, 'Duplicate serial accepted'); $assertions++;
	dvExpect($service->activateVoucher($v1, 1, $user) > 0, 'Voucher activation failed'); $assertions++;
	dvExpect((string) dvOne($db, 'SELECT available_unallocated_balance AS amount FROM llx_dolivoucher_portfolio WHERE rowid='.$source)->amount === '700.00000000', 'Activation did not reserve value'); $assertions++;
	dvExpect($service->consumeVoucher($v1, 1, '100', $user, 'partial') > 0, 'Partial consumption failed'); $assertions++;
	$v1row = dvOne($db, 'SELECT current_balance,status FROM llx_dolivoucher_voucher WHERE rowid='.$v1);
	dvExpect((string) $v1row->current_balance === '200.00000000' && (int) $v1row->status === DoliVoucherVoucher::STATUS_PARTIALLY_CONSUMED, 'Partial balance/status invalid'); $assertions++;
	dvExpect($service->consumeVoucher($v1, 1, '201', $user, 'too much') < 0, 'Over-consumption accepted'); $assertions++;
	dvExpect($service->blockVoucher($v1, 1, $user, 'manual block') > 0, 'Block failed'); $assertions++;
	dvExpect($service->consumeVoucher($v1, 1, '1', $user, 'blocked') < 0, 'Blocked consumption accepted'); $assertions++;
	dvExpect($service->unblockVoucher($v1, 1, $user, 'manual unblock') > 0, 'Unblock failed'); $assertions++;
	dvExpect($service->cancelVoucher($v1, 1, $user, 'partial must fail') < 0, 'Partially consumed voucher canceled'); $assertions++;
	$consumeOperation = (int) dvOne($db, "SELECT rowid FROM llx_dolivoucher_operation WHERE fk_voucher=".$v1." AND operation_type='CONSUME' LIMIT 1")->rowid;
	dvExpect($service->compensateConsumption($consumeOperation, 1, $user, 'admin correction') > 0, 'Compensation failed'); $assertions++;
	dvExpect($service->compensateConsumption($consumeOperation, 1, $user, 'duplicate') < 0, 'Double compensation accepted'); $assertions++;
	dvExpect($service->cancelVoucher($v1, 1, $user, 'must fail') < 0, 'Historically consumed voucher canceled'); $assertions++;

	$v2 = dvVoucher($db, $service, $user, $source, 'V-2', '200');
	dvExpect($service->activateVoucher($v2, 1, $user) > 0 && $service->consumeVoucher($v2, 1, '200', $user, 'total') > 0, 'Total consumption failed'); $assertions++;
	dvExpect((int) dvOne($db, 'SELECT status FROM llx_dolivoucher_voucher WHERE rowid='.$v2)->status === DoliVoucherVoucher::STATUS_CONSUMED, 'Consumed status not applied'); $assertions++;
	$v3 = dvVoucher($db, $service, $user, $source, 'V-3', '100');
	dvExpect($service->activateVoucher($v3, 1, $user) > 0 && $service->cancelVoucher($v3, 1, $user, 'unused return') > 0, 'Eligible cancellation failed'); $assertions++;
	$expired = dvVoucher($db, $service, $user, $source, 'V-EXP', '50', dol_now() - 86400);
	dvExpect($expired > 0 && $service->activateVoucher($expired, 1, $user) < 0, 'Expired voucher activated'); $assertions++;
	$expiring = dvVoucher($db, $service, $user, $source, 'V-EXP-ACTIVE', '50', dol_now() + 86400);
	dvExpect($service->activateVoucher($expiring, 1, $user) > 0, 'Future-dated voucher activation failed'); $assertions++;
	dvExpect((bool) $db->query("UPDATE llx_dolivoucher_voucher SET date_expiration='2000-01-01 00:00:00' WHERE rowid=".$expiring), 'Cannot age voucher for expiration test');
	dvExpect($service->consumeVoucher($expiring, 1, '1', $user, 'expired refusal') < 0, 'Expired voucher consumption accepted'); $assertions++;

	$exposurePortfolioId = dvPortfolio($db, $user, 'DON-EXPOSURE', DoliVoucherPortfolio::TYPE_DONATION, null);
	dvExpect($exposurePortfolioId > 0 && $service->validatePortfolio($exposurePortfolioId, 1, $user) > 0 && $service->fundPortfolio($exposurePortfolioId, 1, '20000', $user, 'exposure funding') > 0, 'Exposure portfolio setup failed'); $assertions++;
	$exposureVoucherId = dvVoucher($db, $service, $user, $exposurePortfolioId, 'V-EXPOSURE-10000', '10000');
	dvExpect($exposureVoucherId > 0 && $service->activateVoucher($exposureVoucherId, 1, $user) > 0, 'Exposure voucher activation failed'); $assertions++;
	$exposurePortfolio = new DoliVoucherPortfolio($db);
	dvExpect($exposurePortfolio->fetch($exposurePortfolioId) > 0, 'Exposure portfolio fetch failed');
	$activeExposure = $exposurePortfolio->getFinancialSummary();
	dvExpect((string) $activeExposure['immediately_redeemable_voucher_balance'] === '10000.00000000', 'Active voucher is not immediately redeemable at 10000'); $assertions++;
	dvExpect((string) $activeExposure['outstanding_voucher_balance'] === '10000.00000000', 'Active voucher outstanding balance is not 10000'); $assertions++;
	$globalBeforeBlock = (string) $activeExposure['global_outstanding_balance'];
	$voucherBalanceBeforeBlock = (string) dvOne($db, 'SELECT current_balance AS amount FROM llx_dolivoucher_voucher WHERE rowid='.$exposureVoucherId)->amount;
	$unallocatedBeforeBlock = (string) $activeExposure['available_unallocated_balance'];
	dvExpect($service->blockVoucher($exposureVoucherId, 1, $user, 'exposure block') > 0, 'Exposure voucher block failed'); $assertions++;
	$blockedExposure = $exposurePortfolio->getFinancialSummary();
	dvExpect((string) $blockedExposure['immediately_redeemable_voucher_balance'] === '0.00000000', 'Blocked voucher remained immediately redeemable'); $assertions++;
	dvExpect((string) $blockedExposure['blocked_voucher_balance'] === '10000.00000000' && (string) $blockedExposure['outstanding_voucher_balance'] === '10000.00000000', 'Blocked voucher was removed from outstanding exposure'); $assertions++;
	dvExpect((string) $blockedExposure['global_outstanding_balance'] === $globalBeforeBlock, 'Global outstanding changed during block'); $assertions++;
	dvExpect((string) $blockedExposure['available_unallocated_balance'] === $unallocatedBeforeBlock && (string) dvOne($db, 'SELECT current_balance AS amount FROM llx_dolivoucher_voucher WHERE rowid='.$exposureVoucherId)->amount === $voucherBalanceBeforeBlock, 'Block changed a monetary balance'); $assertions++;
	dvExpect($service->checkPortfolioExposureBalances($exposurePortfolioId, 1)['consistent'], 'Blocked exposure differs from journal reconstruction'); $assertions++;
	dvExpect($service->unblockVoucher($exposureVoucherId, 1, $user, 'exposure unblock') > 0, 'Exposure voucher unblock failed'); $assertions++;
	$unblockedExposure = $exposurePortfolio->getFinancialSummary();
	dvExpect((string) $unblockedExposure['immediately_redeemable_voucher_balance'] === '10000.00000000' && (string) $unblockedExposure['outstanding_voucher_balance'] === '10000.00000000', 'Unblocked voucher exposure was not restored'); $assertions++;
	dvExpect((string) $unblockedExposure['global_outstanding_balance'] === $globalBeforeBlock && $service->checkPortfolioExposureBalances($exposurePortfolioId, 1)['consistent'], 'Unblocked exposure or journal reconstruction is inconsistent'); $assertions++;
	dvExpect((bool) $db->query("UPDATE llx_dolivoucher_voucher SET date_expiration='2000-01-01 00:00:00' WHERE rowid=".$exposureVoucherId), 'Cannot age exposure voucher');
	dvExpect($service->expireVoucher($exposureVoucherId, 1, $user, 'exposure expiration') > 0, 'Exposure voucher expiration failed'); $assertions++;
	$expiredExposure = $exposurePortfolio->getFinancialSummary();
	dvExpect((string) $expiredExposure['immediately_redeemable_voucher_balance'] === '0.00000000' && (string) $expiredExposure['outstanding_voucher_balance'] === '0.00000000', 'Expired voucher remains redeemable or unsettled'); $assertions++;
	dvExpect((string) $expiredExposure['expired_unreallocated_balance'] === '10000.00000000', 'Expired unreallocated balance is not auditable'); $assertions++;
	dvExpect($service->checkPortfolioExposureBalances($exposurePortfolioId, 1)['consistent'], 'Expired exposure differs from journal reconstruction'); $assertions++;

	$beforeTransfer = (string) dvOne($db, 'SELECT available_unallocated_balance AS amount FROM llx_dolivoucher_portfolio WHERE rowid='.$source)->amount;
	dvExpect($service->transferRemainder($source, $destination, 1, '200', $user, 'period transfer') > 0, 'Valid transfer failed'); $assertions++;
	$pair = dvOne($db, "SELECT COUNT(*) AS movements, COUNT(DISTINCT operation_uuid) AS uuids FROM llx_dolivoucher_operation WHERE source_portfolio_id=".$source.' AND destination_portfolio_id='.$destination);
	dvExpect((int) $pair->movements === 2 && (int) $pair->uuids === 1, 'Transfer pair is not atomic/linked'); $assertions++;
	$afterTransfer = (string) dvOne($db, 'SELECT available_unallocated_balance AS amount FROM llx_dolivoucher_portfolio WHERE rowid='.$source)->amount;
	dvExpect($service->transferRemainder($source, $otherThirdParty, 1, '10', $user, 'wrong funder') < 0, 'Cross-funder transfer accepted'); $assertions++;
	dvExpect($service->transferRemainder($source, $destination, 1, '999999', $user, 'too much') < 0, 'Over-transfer accepted'); $assertions++;
	dvExpect((string) dvOne($db, 'SELECT available_unallocated_balance AS amount FROM llx_dolivoucher_portfolio WHERE rowid='.$source)->amount === $afterTransfer, 'Failed transfer changed balance'); $assertions++;
	dvExpect($beforeTransfer !== $afterTransfer, 'Valid transfer did not change balance'); $assertions++;
	$faultSql = "CREATE TRIGGER trg_dolivoucher_forced_rollback BEFORE UPDATE ON llx_dolivoucher_portfolio FOR EACH ROW BEGIN IF NEW.rowid=".$failureDestination." AND NEW.available_unallocated_balance>OLD.available_unallocated_balance THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='forced rollback'; END IF; END";
	dvExpect((bool) $db->query($faultSql), 'Cannot install rollback fault: '.$db->lasterror());
	$beforeForcedFailure = (string) dvOne($db, 'SELECT available_unallocated_balance AS amount FROM llx_dolivoucher_portfolio WHERE rowid='.$source)->amount;
	dvExpect($service->transferRemainder($source, $failureDestination, 1, '1', $user, 'forced SQL failure') < 0, 'Forced destination failure did not fail'); $assertions++;
	dvExpect((string) dvOne($db, 'SELECT available_unallocated_balance AS amount FROM llx_dolivoucher_portfolio WHERE rowid='.$source)->amount === $beforeForcedFailure, 'Debit survived a failed destination credit'); $assertions++;

	$portfolioCheck = $service->checkPortfolioBalance($source, 1);
	$voucherCheck = $service->checkVoucherBalance($v2, 1);
	dvExpect($portfolioCheck['consistent'] && $voucherCheck['consistent'], 'Journal reconciliation failed'); $assertions++;
	$operation = new DoliVoucherOperation($db);
	dvExpect($operation->update($user) < 0 && $operation->delete($user) < 0 && $operation->setValueFrom('amount', '1') < 0, 'Append-only API exposed mutation'); $assertions++;

	echo 'DoliVoucher transactional integration smoke test: OK ('.$assertions." assertions).\n";
} finally {
	$conf->entity = $originalEntity;
	$db->query('USE `'.$db->escape($originalDatabase).'`');
	if ($created) $db->query('DROP DATABASE `'.$testDatabase.'`');
}
