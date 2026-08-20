<?php
/* Copyright (C) 2026 Fred Omega Junior */

declare(strict_types=1);

require_once __DIR__.'/dolivouchermoney.class.php';
require_once __DIR__.'/dolivoucherportfolio.class.php';
require_once __DIR__.'/dolivouchervoucher.class.php';
require_once __DIR__.'/dolivoucheroperation.class.php';

/** Transactional application service. Audit entries are inserted only here. */
class DoliVoucherService
{
	private DoliDB $db;

	public function __construct(DoliDB $db)
	{
		$this->db = $db;
	}

	public function createPortfolio(DoliVoucherPortfolio $portfolio, User $user): int
	{
		return $portfolio->create($user);
	}

	public function validatePortfolio(int $portfolioId, int $entity, User $user): int
	{
		return $this->portfolioTransition($portfolioId, $entity, DoliVoucherPortfolio::STATUS_DRAFT, DoliVoucherPortfolio::STATUS_VALIDATED, $user);
	}

	public function activatePortfolio(int $portfolioId, int $entity, User $user): int
	{
		return $this->portfolioTransition($portfolioId, $entity, DoliVoucherPortfolio::STATUS_VALIDATED, DoliVoucherPortfolio::STATUS_ACTIVE, $user);
	}

	public function closePortfolio(int $portfolioId, int $entity, User $user): int
	{
		$this->db->begin();
		try {
			$portfolio = $this->lockPortfolio($portfolioId, $entity);
			if (!in_array((int) $portfolio->status, array(DoliVoucherPortfolio::STATUS_VALIDATED, DoliVoucherPortfolio::STATUS_ACTIVE), true)) {
				throw new DomainException('ErrorInvalidStatusTransition');
			}
			$this->updatePortfolioStatus($portfolioId, $entity, DoliVoucherPortfolio::STATUS_CLOSED, $user);
			$this->db->commit();
			return 1;
		} catch (Throwable $e) {
			$this->db->rollback();
			return $this->fail($e);
		}
	}

	public function cancelPortfolio(int $portfolioId, int $entity, User $user): int
	{
		$this->db->begin();
		try {
			$portfolio = $this->lockPortfolio($portfolioId, $entity);
			if ((int) $portfolio->status !== DoliVoucherPortfolio::STATUS_DRAFT) {
				throw new DomainException('ErrorOnlyDraftPortfolioCanBeCanceled');
			}
			$sql = 'SELECT COUNT(*) AS linked_count FROM '.$this->db->prefix().'dolivoucher_voucher WHERE fk_portfolio='.$portfolioId;
			$row = $this->one($sql);
			if ((int) $row->linked_count !== 0) {
				throw new DomainException('ErrorPortfolioHasVouchers');
			}
			$this->updatePortfolioStatus($portfolioId, $entity, DoliVoucherPortfolio::STATUS_CANCELED, $user);
			$this->db->commit();
			return 1;
		} catch (Throwable $e) {
			$this->db->rollback();
			return $this->fail($e);
		}
	}

	public function fundPortfolio(int $portfolioId, int $entity, $amount, User $user, string $reason = '', string $externalRef = ''): int
	{
		return $this->creditPortfolio($portfolioId, $entity, $amount, DoliVoucherOperation::TYPE_FUND_NEW, $user, $reason, $externalRef);
	}

	public function addCarryover(int $portfolioId, int $entity, $amount, User $user, string $reason = '', string $externalRef = ''): int
	{
		return $this->creditPortfolio($portfolioId, $entity, $amount, DoliVoucherOperation::TYPE_CARRYOVER_IN, $user, $reason, $externalRef);
	}

	public function createVoucher(DoliVoucherVoucher $voucher, User $user, string $reason = ''): int
	{
		$this->db->begin();
		try {
			if ((int) $voucher->status === DoliVoucherVoucher::STATUS_PREPARED && empty($voucher->date_issue)) {
				$voucher->date_issue = dol_now();
			}
			$id = $voucher->create($user, 1);
			if ($id <= 0) {
				throw new DomainException($voucher->error ?: 'ErrorVoucherCreationFailed');
			}
			$this->appendOperation((int) $voucher->entity, (int) $voucher->fk_portfolio, $id, DoliVoucherOperation::TYPE_ISSUE, (string) $voucher->initial_amount, null, null, $user, $reason);
			$this->db->commit();
			return $id;
		} catch (Throwable $e) {
			$this->db->rollback();
			return $this->fail($e);
		}
	}

	public function prepareVoucher(int $voucherId, int $entity, User $user, string $reason = ''): int
	{
		$this->db->begin();
		try {
			$voucher = $this->lockVoucher($voucherId, $entity);
			if ((int) $voucher->status !== DoliVoucherVoucher::STATUS_DRAFT) {
				throw new DomainException('ErrorInvalidStatusTransition');
			}
			$sql = 'UPDATE '.$this->db->prefix().'dolivoucher_voucher SET status='.DoliVoucherVoucher::STATUS_PREPARED;
			$sql .= ", date_issue='".$this->db->idate(dol_now())."', fk_user_modif=".(int) $user->id.' WHERE rowid='.$voucherId.' AND entity='.$entity;
			$this->mustQuery($sql, 'ErrorVoucherUpdateFailed');
			$this->db->commit();
			return 1;
		} catch (Throwable $e) {
			$this->db->rollback();
			return $this->fail($e);
		}
	}

	public function activateVoucher(int $voucherId, int $entity, User $user, string $reason = ''): int
	{
		$this->db->begin();
		try {
			$hint = $this->voucherHint($voucherId, $entity);
			$portfolio = $this->lockPortfolio((int) $hint->fk_portfolio, $entity);
			$voucher = $this->lockVoucher($voucherId, $entity);
			if (!in_array((int) $voucher->status, array(DoliVoucherVoucher::STATUS_DRAFT, DoliVoucherVoucher::STATUS_PREPARED), true)) {
				throw new DomainException('ErrorVoucherNotActivatable');
			}
			if (!empty($voucher->date_expiration) && $this->db->jdate($voucher->date_expiration) < dol_now()) {
				throw new DomainException('ErrorVoucherExpired');
			}
			if (!in_array((int) $portfolio->status, array(DoliVoucherPortfolio::STATUS_VALIDATED, DoliVoucherPortfolio::STATUS_ACTIVE), true)) {
				throw new DomainException('ErrorPortfolioNotEligible');
			}
			$amount = DoliVoucherMoney::normalize((string) $voucher->initial_amount, true);
			$before = DoliVoucherMoney::normalize((string) $portfolio->available_unallocated_balance);
			if (DoliVoucherMoney::compare($before, $amount) < 0) {
				throw new DomainException('ErrorInsufficientUnallocatedBalance');
			}
			$sql = 'UPDATE '.$this->db->prefix().'dolivoucher_portfolio SET available_unallocated_balance=available_unallocated_balance-'.$this->decimal($amount);
			$sql .= ', fk_user_modif='.(int) $user->id.' WHERE rowid='.(int) $portfolio->rowid.' AND entity='.$entity.' AND available_unallocated_balance >= '.$this->decimal($amount);
			$this->mustQuery($sql, 'ErrorPortfolioBalanceUpdateFailed');
			$after = (string) $this->one('SELECT available_unallocated_balance FROM '.$this->db->prefix().'dolivoucher_portfolio WHERE rowid='.(int) $portfolio->rowid)->available_unallocated_balance;
			$sql = 'UPDATE '.$this->db->prefix().'dolivoucher_voucher SET current_balance='.$this->decimal($amount).', status='.DoliVoucherVoucher::STATUS_ACTIVE;
			$sql .= ", date_issue=COALESCE(date_issue, '".$this->db->idate(dol_now())."'), date_activation='".$this->db->idate(dol_now())."', fk_user_modif=".(int) $user->id.' WHERE rowid='.$voucherId.' AND entity='.$entity;
			$this->mustQuery($sql, 'ErrorVoucherActivationFailed');
			$this->appendOperation($entity, (int) $portfolio->rowid, $voucherId, DoliVoucherOperation::TYPE_ACTIVATE, $amount, $before, $after, $user, $reason);
			$this->db->commit();
			return 1;
		} catch (Throwable $e) {
			$this->db->rollback();
			return $this->fail($e);
		}
	}

	public function consumeVoucher(int $voucherId, int $entity, $amount, User $user, string $reason = '', string $externalRef = ''): int
	{
		$this->db->begin();
		try {
			$amount = DoliVoucherMoney::normalize($amount, true);
			$voucher = $this->lockVoucher($voucherId, $entity);
			if (!in_array((int) $voucher->status, array(DoliVoucherVoucher::STATUS_ACTIVE, DoliVoucherVoucher::STATUS_PARTIALLY_CONSUMED), true)) {
				throw new DomainException('ErrorVoucherNotConsumable');
			}
			if (!empty($voucher->date_expiration) && $this->db->jdate($voucher->date_expiration) < dol_now()) {
				throw new DomainException('ErrorVoucherExpired');
			}
			$before = DoliVoucherMoney::normalize((string) $voucher->current_balance);
			if (DoliVoucherMoney::compare($before, $amount) < 0) {
				throw new DomainException('ErrorInsufficientVoucherBalance');
			}
			$sql = 'UPDATE '.$this->db->prefix().'dolivoucher_voucher SET status=CASE WHEN current_balance-'.$this->decimal($amount).'=0 THEN '.DoliVoucherVoucher::STATUS_CONSUMED.' ELSE '.DoliVoucherVoucher::STATUS_PARTIALLY_CONSUMED.' END';
			$sql .= ', current_balance=current_balance-'.$this->decimal($amount);
			$sql .= ', fk_user_modif='.(int) $user->id.' WHERE rowid='.$voucherId.' AND entity='.$entity.' AND current_balance >= '.$this->decimal($amount);
			$this->mustQuery($sql, 'ErrorVoucherConsumptionFailed');
			$after = (string) $this->one('SELECT current_balance FROM '.$this->db->prefix().'dolivoucher_voucher WHERE rowid='.$voucherId)->current_balance;
			$this->appendOperation($entity, (int) $voucher->fk_portfolio, $voucherId, DoliVoucherOperation::TYPE_CONSUME, $amount, $before, $after, $user, $reason, $externalRef);
			$this->db->commit();
			return 1;
		} catch (Throwable $e) {
			$this->db->rollback();
			return $this->fail($e);
		}
	}

	public function blockVoucher(int $voucherId, int $entity, User $user, string $reason): int
	{
		return $this->voucherStateOperation($voucherId, $entity, array(DoliVoucherVoucher::STATUS_ACTIVE, DoliVoucherVoucher::STATUS_PARTIALLY_CONSUMED), DoliVoucherVoucher::STATUS_BLOCKED, DoliVoucherOperation::TYPE_BLOCK, $user, $reason);
	}

	public function unblockVoucher(int $voucherId, int $entity, User $user, string $reason): int
	{
		return $this->voucherStateOperation($voucherId, $entity, array(DoliVoucherVoucher::STATUS_BLOCKED), null, DoliVoucherOperation::TYPE_UNBLOCK, $user, $reason);
	}

	public function expireVoucher(int $voucherId, int $entity, User $user, string $reason = ''): int
	{
		$this->db->begin();
		try {
			$voucher = $this->lockVoucher($voucherId, $entity);
			if (!in_array((int) $voucher->status, array(DoliVoucherVoucher::STATUS_ACTIVE, DoliVoucherVoucher::STATUS_PARTIALLY_CONSUMED, DoliVoucherVoucher::STATUS_BLOCKED), true)
				|| empty($voucher->date_expiration) || $this->db->jdate($voucher->date_expiration) > dol_now()) {
				throw new DomainException('ErrorVoucherNotExpired');
			}
			$this->setVoucherStatus($voucherId, $entity, DoliVoucherVoucher::STATUS_EXPIRED, $user);
			$this->appendOperation($entity, (int) $voucher->fk_portfolio, $voucherId, DoliVoucherOperation::TYPE_EXPIRE, (string) $voucher->current_balance, (string) $voucher->current_balance, (string) $voucher->current_balance, $user, $reason);
			$this->db->commit();
			return 1;
		} catch (Throwable $e) {
			$this->db->rollback();
			return $this->fail($e);
		}
	}

	public function cancelVoucher(int $voucherId, int $entity, User $user, string $reason): int
	{
		$this->db->begin();
		try {
			$hint = $this->voucherHint($voucherId, $entity);
			$portfolio = $this->lockPortfolio((int) $hint->fk_portfolio, $entity);
			$voucher = $this->lockVoucher($voucherId, $entity);
			if (!in_array((int) $voucher->status, array(DoliVoucherVoucher::STATUS_ACTIVE, DoliVoucherVoucher::STATUS_BLOCKED), true)) {
				throw new DomainException('ErrorVoucherNotCancelable');
			}
			$amount = DoliVoucherMoney::normalize((string) $voucher->initial_amount, true);
			if (DoliVoucherMoney::compare((string) $voucher->current_balance, $amount) !== 0) {
				throw new DomainException('ErrorConsumedVoucherCannotBeCanceled');
			}
			$row = $this->one("SELECT COUNT(*) AS consumed FROM ".$this->db->prefix()."dolivoucher_operation WHERE fk_voucher=".$voucherId." AND operation_type='CONSUME'");
			if ((int) $row->consumed > 0) {
				throw new DomainException('ErrorConsumedVoucherCannotBeCanceled');
			}
			$before = (string) $portfolio->available_unallocated_balance;
			$this->mustQuery('UPDATE '.$this->db->prefix().'dolivoucher_portfolio SET available_unallocated_balance=available_unallocated_balance+'.$this->decimal($amount).', fk_user_modif='.(int) $user->id.' WHERE rowid='.(int) $portfolio->rowid.' AND entity='.$entity, 'ErrorPortfolioBalanceUpdateFailed');
			$after = (string) $this->one('SELECT available_unallocated_balance FROM '.$this->db->prefix().'dolivoucher_portfolio WHERE rowid='.(int) $portfolio->rowid)->available_unallocated_balance;
			$sql = 'UPDATE '.$this->db->prefix().'dolivoucher_voucher SET current_balance=0, status='.DoliVoucherVoucher::STATUS_CANCELED.', fk_user_modif='.(int) $user->id.' WHERE rowid='.$voucherId.' AND entity='.$entity;
			$this->mustQuery($sql, 'ErrorVoucherCancellationFailed');
			$this->appendOperation($entity, (int) $portfolio->rowid, $voucherId, DoliVoucherOperation::TYPE_CANCEL, $amount, $before, $after, $user, $reason);
			$this->db->commit();
			return 1;
		} catch (Throwable $e) {
			$this->db->rollback();
			return $this->fail($e);
		}
	}

	public function transferRemainder(int $sourceId, int $destinationId, int $entity, $amount, User $user, string $reason): int
	{
		if ($sourceId === $destinationId) {
			return $this->fail(new DomainException('ErrorTransferSamePortfolio'));
		}
		$this->db->begin();
		try {
			$amount = DoliVoucherMoney::normalize($amount, true);
			$first = min($sourceId, $destinationId);
			$second = max($sourceId, $destinationId);
			$lockedFirst = $this->lockPortfolio($first, $entity);
			$lockedSecond = $this->lockPortfolio($second, $entity);
			$source = $sourceId === $first ? $lockedFirst : $lockedSecond;
			$destination = $destinationId === $first ? $lockedFirst : $lockedSecond;
			if ((int) $source->status === DoliVoucherPortfolio::STATUS_CANCELED
				|| !in_array((int) $destination->status, array(DoliVoucherPortfolio::STATUS_VALIDATED, DoliVoucherPortfolio::STATUS_ACTIVE), true)) {
				throw new DomainException('ErrorTransferPortfolioStatus');
			}
			if ($source->type !== $destination->type || ($source->type === DoliVoucherPortfolio::TYPE_INSTITUTIONAL && (int) $source->fk_soc !== (int) $destination->fk_soc)) {
				throw new DomainException('ErrorTransferIncompatiblePortfolios');
			}
			$sourceBefore = DoliVoucherMoney::normalize((string) $source->available_unallocated_balance);
			if (DoliVoucherMoney::compare($sourceBefore, $amount) < 0) {
				throw new DomainException('ErrorInsufficientUnallocatedBalance');
			}
			$destinationBefore = DoliVoucherMoney::normalize((string) $destination->available_unallocated_balance);
			$this->mustQuery('UPDATE '.$this->db->prefix().'dolivoucher_portfolio SET available_unallocated_balance=available_unallocated_balance-'.$this->decimal($amount).', fk_user_modif='.(int) $user->id.' WHERE rowid='.$sourceId.' AND entity='.$entity.' AND available_unallocated_balance >= '.$this->decimal($amount), 'ErrorTransferDebitFailed');
			$this->mustQuery('UPDATE '.$this->db->prefix().'dolivoucher_portfolio SET available_unallocated_balance=available_unallocated_balance+'.$this->decimal($amount).', fk_user_modif='.(int) $user->id.' WHERE rowid='.$destinationId.' AND entity='.$entity, 'ErrorTransferCreditFailed');
			$sourceAfter = (string) $this->one('SELECT available_unallocated_balance FROM '.$this->db->prefix().'dolivoucher_portfolio WHERE rowid='.$sourceId)->available_unallocated_balance;
			$destinationAfter = (string) $this->one('SELECT available_unallocated_balance FROM '.$this->db->prefix().'dolivoucher_portfolio WHERE rowid='.$destinationId)->available_unallocated_balance;
			$uuid = self::uuid();
			$this->appendOperation($entity, $sourceId, null, DoliVoucherOperation::TYPE_TRANSFER_OUT, $amount, $sourceBefore, $sourceAfter, $user, $reason, '', $sourceId, $destinationId, null, $uuid);
			$this->appendOperation($entity, $destinationId, null, DoliVoucherOperation::TYPE_TRANSFER_IN, $amount, $destinationBefore, $destinationAfter, $user, $reason, '', $sourceId, $destinationId, null, $uuid);
			$this->db->commit();
			return 1;
		} catch (Throwable $e) {
			$this->db->rollback();
			return $this->fail($e);
		}
	}

	/** Phase 1 administrative compensation: reverse one consumption, once. */
	public function compensateConsumption(int $operationId, int $entity, User $user, string $reason): int
	{
		$this->db->begin();
		try {
			$hint = $this->one('SELECT fk_voucher FROM '.$this->db->prefix().'dolivoucher_operation WHERE rowid='.$operationId.' AND entity='.$entity.' LIMIT 1');
			if ((int) $hint->fk_voucher <= 0) {
				throw new DomainException('ErrorOperationNotCompensable');
			}
			$voucher = $this->lockVoucher((int) $hint->fk_voucher, $entity);
			$operation = $this->one('SELECT * FROM '.$this->db->prefix().'dolivoucher_operation WHERE rowid='.$operationId.' AND entity='.$entity.' FOR UPDATE');
			if ($operation->operation_type !== DoliVoucherOperation::TYPE_CONSUME) {
				throw new DomainException('ErrorOperationNotCompensable');
			}
			$existing = $this->one('SELECT COUNT(*) AS reversal_count FROM '.$this->db->prefix().'dolivoucher_operation WHERE reversal_of='.$operationId);
			if ((int) $existing->reversal_count > 0) {
				throw new DomainException('ErrorOperationAlreadyCompensated');
			}
			$amount = DoliVoucherMoney::normalize((string) $operation->amount, true);
			$before = DoliVoucherMoney::normalize((string) $voucher->current_balance);
			$sql = 'SELECT CAST('.$this->decimal($before).'+'.$this->decimal($amount).' AS DECIMAL(24,8)) AS candidate';
			$after = (string) $this->one($sql)->candidate;
			if (DoliVoucherMoney::compare($after, (string) $voucher->initial_amount) > 0 || in_array((int) $voucher->status, array(DoliVoucherVoucher::STATUS_CANCELED, DoliVoucherVoucher::STATUS_EXPIRED), true)) {
				throw new DomainException('ErrorCompensationWouldInvalidateVoucher');
			}
			$status = (int) $voucher->status === DoliVoucherVoucher::STATUS_BLOCKED ? DoliVoucherVoucher::STATUS_BLOCKED : (DoliVoucherMoney::compare($after, (string) $voucher->initial_amount) === 0 ? DoliVoucherVoucher::STATUS_ACTIVE : DoliVoucherVoucher::STATUS_PARTIALLY_CONSUMED);
			$this->mustQuery('UPDATE '.$this->db->prefix().'dolivoucher_voucher SET current_balance='.$this->decimal($after).', status='.$status.', fk_user_modif='.(int) $user->id.' WHERE rowid='.(int) $voucher->rowid.' AND entity='.$entity, 'ErrorCompensationFailed');
			$this->appendOperation($entity, (int) $voucher->fk_portfolio, (int) $voucher->rowid, DoliVoucherOperation::TYPE_CORRECTION, $amount, $before, $after, $user, $reason, '', null, null, $operationId);
			$this->db->commit();
			return 1;
		} catch (Throwable $e) {
			$this->db->rollback();
			return $this->fail($e);
		}
	}

	/** @return array{materialized:string,reconstructed:string,consistent:bool} */
	public function checkPortfolioBalance(int $portfolioId, int $entity): array
	{
		$sql = 'SELECT available_unallocated_balance FROM '.$this->db->prefix().'dolivoucher_portfolio WHERE rowid='.$portfolioId.' AND entity='.$entity.' LIMIT 1';
		$materialized = DoliVoucherMoney::normalize((string) $this->one($sql)->available_unallocated_balance);
		$sql = "SELECT CAST(COALESCE(SUM(CASE WHEN operation_type IN ('FUND_NEW','CARRYOVER_IN','TRANSFER_IN','CANCEL') THEN amount WHEN operation_type IN ('ACTIVATE','TRANSFER_OUT') THEN -amount ELSE 0 END),0) AS DECIMAL(24,8)) AS reconstructed";
		$sql .= ' FROM '.$this->db->prefix().'dolivoucher_operation WHERE fk_portfolio='.$portfolioId.' AND entity='.$entity;
		$raw = (string) $this->one($sql)->reconstructed;
		$reconstructed = str_starts_with($raw, '-') ? $raw : DoliVoucherMoney::normalize($raw);
		return array('materialized' => $materialized, 'reconstructed' => $reconstructed, 'consistent' => $materialized === $reconstructed);
	}

	/** @return array{materialized:string,reconstructed:string,consistent:bool} */
	public function checkVoucherBalance(int $voucherId, int $entity): array
	{
		$voucher = $this->one('SELECT current_balance FROM '.$this->db->prefix().'dolivoucher_voucher WHERE rowid='.$voucherId.' AND entity='.$entity.' LIMIT 1');
		$materialized = DoliVoucherMoney::normalize((string) $voucher->current_balance);
		$sql = "SELECT CAST(COALESCE(SUM(CASE WHEN operation_type IN ('ACTIVATE','CORRECTION') THEN amount WHEN operation_type IN ('CONSUME','CANCEL') THEN -amount ELSE 0 END),0) AS DECIMAL(24,8)) AS reconstructed";
		$sql .= ' FROM '.$this->db->prefix().'dolivoucher_operation WHERE fk_voucher='.$voucherId.' AND entity='.$entity;
		$raw = (string) $this->one($sql)->reconstructed;
		$reconstructed = str_starts_with($raw, '-') ? $raw : DoliVoucherMoney::normalize($raw);
		return array('materialized' => $materialized, 'reconstructed' => $reconstructed, 'consistent' => $materialized === $reconstructed);
	}

	/**
	 * Reconcile operational availability and financial exposure aggregates with the journal.
	 *
	 * @return array{materialized:array<string,string>,reconstructed:array<string,string>,consistent:bool}
	 */
	public function checkPortfolioExposureBalances(int $portfolioId, int $entity): array
	{
		$voucherLedger = 'SELECT fk_voucher, CAST(COALESCE(SUM(CASE';
		$voucherLedger .= " WHEN operation_type IN ('ACTIVATE','CORRECTION') THEN amount";
		$voucherLedger .= " WHEN operation_type IN ('CONSUME','CANCEL') THEN -amount ELSE 0 END),0) AS DECIMAL(24,8)) AS reconstructed_balance";
		$voucherLedger .= ' FROM '.$this->db->prefix().'dolivoucher_operation WHERE entity='.$entity.' AND fk_portfolio='.$portfolioId.' AND fk_voucher IS NOT NULL GROUP BY fk_voucher';
		$portfolioLedger = "SELECT COALESCE(SUM(CASE WHEN operation_type IN ('FUND_NEW','CARRYOVER_IN','TRANSFER_IN','CANCEL') THEN amount WHEN operation_type IN ('ACTIVATE','TRANSFER_OUT') THEN -amount ELSE 0 END),0)";
		$portfolioLedger .= ' FROM '.$this->db->prefix().'dolivoucher_operation WHERE entity='.$entity.' AND fk_portfolio='.$portfolioId;

		$sql = 'SELECT exposure.*, CAST(exposure.materialized_available + exposure.materialized_outstanding AS DECIMAL(24,8)) AS materialized_global,';
		$sql .= ' CAST(exposure.reconstructed_available + exposure.reconstructed_outstanding AS DECIMAL(24,8)) AS reconstructed_global FROM (';
		$sql .= 'SELECT CAST(p.available_unallocated_balance AS DECIMAL(24,8)) AS materialized_available,';
		$sql .= ' CAST(('.$portfolioLedger.') AS DECIMAL(24,8)) AS reconstructed_available,';
		$sql .= ' CAST(COALESCE(SUM(CASE WHEN v.status IN (2,3) THEN v.current_balance ELSE 0 END),0) AS DECIMAL(24,8)) AS materialized_immediately_redeemable,';
		$sql .= ' CAST(COALESCE(SUM(CASE WHEN v.status IN (2,3) THEN vl.reconstructed_balance ELSE 0 END),0) AS DECIMAL(24,8)) AS reconstructed_immediately_redeemable,';
		$sql .= ' CAST(COALESCE(SUM(CASE WHEN v.status=6 THEN v.current_balance ELSE 0 END),0) AS DECIMAL(24,8)) AS materialized_blocked,';
		$sql .= ' CAST(COALESCE(SUM(CASE WHEN v.status=6 THEN vl.reconstructed_balance ELSE 0 END),0) AS DECIMAL(24,8)) AS reconstructed_blocked,';
		$sql .= ' CAST(COALESCE(SUM(CASE WHEN v.status=10 THEN v.current_balance ELSE 0 END),0) AS DECIMAL(24,8)) AS materialized_expired,';
		$sql .= ' CAST(COALESCE(SUM(CASE WHEN v.status=10 THEN vl.reconstructed_balance ELSE 0 END),0) AS DECIMAL(24,8)) AS reconstructed_expired,';
		$sql .= ' CAST(COALESCE(SUM(CASE WHEN v.status IN (2,3,6) THEN v.current_balance ELSE 0 END),0) AS DECIMAL(24,8)) AS materialized_outstanding,';
		$sql .= ' CAST(COALESCE(SUM(CASE WHEN v.status IN (2,3,6) THEN vl.reconstructed_balance ELSE 0 END),0) AS DECIMAL(24,8)) AS reconstructed_outstanding';
		$sql .= ' FROM '.$this->db->prefix().'dolivoucher_portfolio p';
		$sql .= ' LEFT JOIN '.$this->db->prefix().'dolivoucher_voucher v ON v.fk_portfolio=p.rowid AND v.entity=p.entity';
		$sql .= ' LEFT JOIN ('.$voucherLedger.') vl ON vl.fk_voucher=v.rowid';
		$sql .= ' WHERE p.rowid='.$portfolioId.' AND p.entity='.$entity.' GROUP BY p.rowid, p.available_unallocated_balance) exposure';
		$row = $this->one($sql);
		$materialized = array();
		$reconstructed = array();
		foreach (array('available', 'immediately_redeemable', 'blocked', 'expired', 'outstanding', 'global') as $key) {
			$materialized[$key] = $this->auditDecimal((string) $row->{'materialized_'.$key});
			$reconstructed[$key] = $this->auditDecimal((string) $row->{'reconstructed_'.$key});
		}
		return array('materialized' => $materialized, 'reconstructed' => $reconstructed, 'consistent' => $materialized === $reconstructed);
	}

	private function creditPortfolio(int $portfolioId, int $entity, $amount, string $type, User $user, string $reason, string $externalRef): int
	{
		$this->db->begin();
		try {
			$amount = DoliVoucherMoney::normalize($amount, true);
			$portfolio = $this->lockPortfolio($portfolioId, $entity);
			if (!in_array((int) $portfolio->status, array(DoliVoucherPortfolio::STATUS_VALIDATED, DoliVoucherPortfolio::STATUS_ACTIVE), true)) {
				throw new DomainException('ErrorPortfolioNotEligible');
			}
			$before = (string) $portfolio->available_unallocated_balance;
			$this->mustQuery('UPDATE '.$this->db->prefix().'dolivoucher_portfolio SET available_unallocated_balance=available_unallocated_balance+'.$this->decimal($amount).', fk_user_modif='.(int) $user->id.' WHERE rowid='.$portfolioId.' AND entity='.$entity, 'ErrorPortfolioBalanceUpdateFailed');
			$after = (string) $this->one('SELECT available_unallocated_balance FROM '.$this->db->prefix().'dolivoucher_portfolio WHERE rowid='.$portfolioId)->available_unallocated_balance;
			$id = $this->appendOperation($entity, $portfolioId, null, $type, $amount, $before, $after, $user, $reason, $externalRef);
			$this->db->commit();
			return $id;
		} catch (Throwable $e) {
			$this->db->rollback();
			return $this->fail($e);
		}
	}

	private function portfolioTransition(int $portfolioId, int $entity, int $from, int $to, User $user): int
	{
		$this->db->begin();
		try {
			$portfolio = $this->lockPortfolio($portfolioId, $entity);
			if ((int) $portfolio->status !== $from || ($portfolio->type === DoliVoucherPortfolio::TYPE_INSTITUTIONAL && (int) $portfolio->fk_soc <= 0)) {
				throw new DomainException('ErrorInvalidStatusTransition');
			}
			$this->updatePortfolioStatus($portfolioId, $entity, $to, $user);
			$this->db->commit();
			return 1;
		} catch (Throwable $e) {
			$this->db->rollback();
			return $this->fail($e);
		}
	}

	private function voucherStateOperation(int $voucherId, int $entity, array $allowed, ?int $target, string $type, User $user, string $reason): int
	{
		$this->db->begin();
		try {
			$voucher = $this->lockVoucher($voucherId, $entity);
			if (!in_array((int) $voucher->status, $allowed, true)) {
				throw new DomainException('ErrorInvalidStatusTransition');
			}
			if ($target === null) {
				$target = DoliVoucherMoney::compare((string) $voucher->current_balance, (string) $voucher->initial_amount) === 0 ? DoliVoucherVoucher::STATUS_ACTIVE : DoliVoucherVoucher::STATUS_PARTIALLY_CONSUMED;
			}
			$this->setVoucherStatus($voucherId, $entity, $target, $user);
			$this->appendOperation($entity, (int) $voucher->fk_portfolio, $voucherId, $type, (string) $voucher->current_balance, (string) $voucher->current_balance, (string) $voucher->current_balance, $user, $reason);
			$this->db->commit();
			return 1;
		} catch (Throwable $e) {
			$this->db->rollback();
			return $this->fail($e);
		}
	}

	private function appendOperation(int $entity, int $portfolioId, ?int $voucherId, string $type, string $amount, ?string $before, ?string $after, User $user, string $reason = '', string $externalRef = '', ?int $sourceId = null, ?int $destinationId = null, ?int $reversalOf = null, ?string $uuid = null): int
	{
		$amount = DoliVoucherMoney::normalize($amount, true);
		$sql = 'INSERT INTO '.$this->db->prefix().'dolivoucher_operation (entity, operation_uuid, fk_portfolio, fk_voucher, operation_type, amount, balance_before, balance_after, source_portfolio_id, destination_portfolio_id, external_ref, reason, date_operation, date_creation, fk_user_creat, reversal_of) VALUES (';
		$sql .= $entity.", '".$this->db->escape($uuid ?? self::uuid())."', ".$portfolioId.', '.($voucherId === null ? 'NULL' : $voucherId).", '".$this->db->escape($type)."', ".$this->decimal($amount).', ';
		$sql .= $before === null ? 'NULL, ' : $this->decimal($before).', ';
		$sql .= $after === null ? 'NULL, ' : $this->decimal($after).', ';
		$sql .= ($sourceId === null ? 'NULL' : $sourceId).', '.($destinationId === null ? 'NULL' : $destinationId).", '".$this->db->escape($externalRef)."', '".$this->db->escape($reason)."', '".$this->db->idate(dol_now())."', '".$this->db->idate(dol_now())."', ".(int) $user->id.', '.($reversalOf === null ? 'NULL' : $reversalOf).')';
		$this->mustQuery($sql, 'ErrorJournalAppendFailed');
		return (int) $this->db->last_insert_id($this->db->prefix().'dolivoucher_operation');
	}

	private function lockPortfolio(int $id, int $entity): object
	{
		return $this->one('SELECT * FROM '.$this->db->prefix().'dolivoucher_portfolio WHERE rowid='.$id.' AND entity='.$entity.' FOR UPDATE');
	}

	private function voucherHint(int $id, int $entity): object
	{
		return $this->one('SELECT fk_portfolio FROM '.$this->db->prefix().'dolivoucher_voucher WHERE rowid='.$id.' AND entity='.$entity.' LIMIT 1');
	}

	private function lockVoucher(int $id, int $entity): object
	{
		return $this->one('SELECT * FROM '.$this->db->prefix().'dolivoucher_voucher WHERE rowid='.$id.' AND entity='.$entity.' FOR UPDATE');
	}

	private function updatePortfolioStatus(int $id, int $entity, int $status, User $user): void
	{
		$this->mustQuery('UPDATE '.$this->db->prefix().'dolivoucher_portfolio SET status='.$status.', fk_user_modif='.(int) $user->id.' WHERE rowid='.$id.' AND entity='.$entity, 'ErrorPortfolioUpdateFailed');
	}

	private function setVoucherStatus(int $id, int $entity, int $status, User $user): void
	{
		$this->mustQuery('UPDATE '.$this->db->prefix().'dolivoucher_voucher SET status='.$status.', fk_user_modif='.(int) $user->id.' WHERE rowid='.$id.' AND entity='.$entity, 'ErrorVoucherUpdateFailed');
	}

	private function decimal(string $amount): string
	{
		return "CAST('".$this->db->escape(DoliVoucherMoney::normalize($amount))."' AS DECIMAL(24,8))";
	}

	private function auditDecimal(string $amount): string
	{
		return str_starts_with($amount, '-') ? $amount : DoliVoucherMoney::normalize($amount);
	}

	private function one(string $sql): object
	{
		$resql = $this->db->query($sql);
		$record = $resql ? $this->db->fetch_object($resql) : false;
		if (!$record) {
			throw new RuntimeException('ErrorRecordNotFound');
		}
		return $record;
	}

	private function mustQuery(string $sql, string $message): void
	{
		if (!$this->db->query($sql)) {
			throw new RuntimeException($message);
		}
	}

	private function fail(Throwable $e): int
	{
		global $langs;
		$message = $e->getMessage();
		setEventMessages(is_object($langs) ? $langs->trans($message) : $message, null, 'errors');
		dol_syslog(__METHOD__.': '.$message, LOG_WARNING);
		return -1;
	}

	public static function uuid(): string
	{
		$data = random_bytes(16);
		$data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
		$data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
		$hex = bin2hex($data);
		return substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-'.substr($hex, 12, 4).'-'.substr($hex, 16, 4).'-'.substr($hex, 20);
	}
}
