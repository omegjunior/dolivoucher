<?php
/* Copyright (C) 2026 Fred Omega Junior */

declare(strict_types=1);

require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/bank/class/account.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/paiement/class/paiement.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/hookmanager.class.php';
require_once __DIR__.'/dolivouchermoney.class.php';
require_once __DIR__.'/dolivoucherservice.class.php';
require_once __DIR__.'/dolivoucherinvoicesettlement.class.php';

/** Atomic bridge between the DoliVoucher subledger and native customer payments. */
class DoliVoucherInvoiceSettlementService
{
	public const PAYMENT_CODE = 'DVOUCH';

	private DoliDB $db;
	private DoliVoucherService $voucherService;
	private static bool $paymentDeletionAuthorized = false;
	public string $error = '';
	/** @var string[] */
	public array $errors = array();

	public function __construct(DoliDB $db)
	{
		$this->db = $db;
		$this->voucherService = new DoliVoucherService($db);
	}

	public static function isPaymentDeletionAuthorized(): bool
	{
		return self::$paymentDeletionAuthorized;
	}

	/** Find one voucher in the current entity by exact serial number or barcode. */
	public function findVoucher(string $identifier, int $entity): ?object
	{
		$identifier = trim($identifier);
		if ($identifier === '') return null;
		$sql = 'SELECT rowid, ref, barcode, initial_amount, current_balance, status, date_expiration, fk_portfolio';
		$sql .= ' FROM '.$this->db->prefix().'dolivoucher_voucher WHERE entity='.(int) $entity;
		$sql .= " AND (ref='".$this->db->escape($identifier)."' OR barcode='".$this->db->escape($identifier)."')";
		$sql .= $this->db->plimit(2, 0);
		$resql = $this->db->query($sql);
		if (!$resql || $this->db->num_rows($resql) !== 1) return null;
		$row = $this->db->fetch_object($resql);
		return $row ?: null;
	}

	/**
	 * Apply a voucher to one validated customer invoice.
	 *
	 * @return int Settlement APPLY row ID, or -1 on error
	 */
	public function apply(int $invoiceId, int $voucherId, int $entity, $requestedAmount, string $idempotencyKey, User $user, string $reason = '', string $externalRef = '', string $requestSource = DoliVoucherInvoiceSettlement::SOURCE_INVOICE_CARD): int
	{
		global $conf;
		if (!$this->canApply($user)) return $this->fail('ErrorSettlementPermissionDenied');
		$idempotencyKey = $this->validateKey($idempotencyKey);
		$requestSource = $this->validateSource($requestSource);
		if ($idempotencyKey === '') return $this->fail('ErrorInvalidIdempotencyKey');

		$existing = $this->findByIdempotency($entity, $idempotencyKey);
		if ($existing) return $this->sameApplyRequest($existing, $invoiceId, $voucherId, $requestedAmount) ? (int) $existing->rowid : $this->fail('ErrorIdempotencyConflict');

		$this->db->begin();
		try {
			$this->lockIdempotencyKey($entity, $idempotencyKey);
			$this->checkpoint('after_idempotency_lock');
			$existing = $this->findByIdempotency($entity, $idempotencyKey);
			if ($existing) {
				if (!$this->sameApplyRequest($existing, $invoiceId, $voucherId, $requestedAmount)) throw new DomainException('ErrorIdempotencyConflict');
				$this->db->commit();
				return (int) $existing->rowid;
			}

			$invoiceRow = $this->lockInvoice($invoiceId, $entity);
			$this->lockInvoicePayments($invoiceId);
			$this->checkpoint('after_invoice_lock');
			$invoice = new Facture($this->db);
			if ($invoice->fetch($invoiceId) <= 0 || (int) $invoice->entity !== $entity) throw new DomainException('ErrorRecordNotFound');
			$this->assertInvoiceEligible($invoice, $invoiceRow);
			$remain = $this->lockedInvoiceRemainToPay($invoiceId, $entity);

			$voucher = $this->lockVoucher($voucherId, $entity);
			$this->assertVoucherEligible($voucher);
			$balance = DoliVoucherMoney::normalize((string) $voucher->current_balance, true);
			$maximum = DoliVoucherMoney::compare($balance, $remain) <= 0 ? $balance : $remain;
			$amount = ($requestedAmount === null || trim((string) $requestedAmount) === '') ? $maximum : DoliVoucherMoney::normalize($requestedAmount, true);
			if (DoliVoucherMoney::compare($amount, $maximum) > 0) throw new DomainException('ErrorSettlementAmountExceedsMaximum');
			$this->assertExtensionRestrictions($invoice, $voucher, $amount, $entity, $user);

			$paymentModeId = $this->paymentModeId($entity);
			$payment = new Paiement($this->db);
			$payment->datepaye = dol_now();
			$payment->amounts = array($invoiceId => $amount);
			$payment->paiementid = $paymentModeId;
			$payment->paiementcode = self::PAYMENT_CODE;
			$payment->num_payment = (string) $voucher->ref;
			$payment->note_private = 'DoliVoucher '.$voucher->ref.($reason !== '' ? ' - '.$reason : '');
			$payment->fk_account = 0;
			$paymentId = $this->createPaymentWithoutDocument($payment, $user);
			if ($paymentId <= 0) throw new RuntimeException($payment->error ?: 'ErrorNativePaymentCreationFailed');
			$this->checkpoint('after_native_payment');

			$operationId = $this->voucherService->consumeVoucherInTransaction($voucherId, $entity, $amount, $user, $reason, $externalRef, 'facture', $invoiceId);
			$this->checkpoint('after_voucher_operation');
			$settlementId = $this->appendSettlement($entity, DoliVoucherInvoiceSettlement::EVENT_APPLY, $voucherId, $operationId, $invoiceId, $paymentId, $amount, null, (string) $invoice->ref, (string) $payment->ref, $requestSource, $idempotencyKey, $user, $externalRef);
			$this->checkpoint('after_apply_link');

			$invoice->fetch($invoiceId);
			$this->checkpoint('before_invoice_close');
			if (DoliVoucherMoney::compare(DoliVoucherMoney::normalize((string) $invoice->getRemainToPay()), '0.00000000') === 0 && (int) $invoice->status === Facture::STATUS_VALIDATED) {
				if ($invoice->setPaid($user) < 0) throw new RuntimeException($invoice->error ?: (!empty($invoice->errors) ? implode(' | ', $invoice->errors) : ($this->db->lasterror() ?: 'ErrorInvoiceCloseFailed')));
			}
			$this->assertPaymentHasNoBankLine($paymentId, $entity);
			$this->checkpoint('before_commit');
			$this->db->commit();
			$this->regenerateInvoiceDocument($invoiceId);
			return $settlementId;
		} catch (Throwable $e) {
			$this->db->rollback();
			$existing = $this->findByIdempotency($entity, $idempotencyKey);
			if ($existing && $this->sameApplyRequest($existing, $invoiceId, $voucherId, $requestedAmount)) return (int) $existing->rowid;
			return $this->fail($e->getMessage());
		}
	}

	/** Reverse an APPLY event, delete its native payment and restore the voucher. */
	public function reverse(int $applySettlementId, int $entity, string $idempotencyKey, User $user, string $reason, string $externalRef = ''): int
	{
		if (!$this->canReverse($user)) return $this->fail('ErrorSettlementReversalPermissionDenied');
		$idempotencyKey = $this->validateKey($idempotencyKey);
		if ($idempotencyKey === '' || trim($reason) === '') return $this->fail('ErrorSettlementReversalReasonRequired');
		$existing = $this->findByIdempotency($entity, $idempotencyKey);
		if ($existing) return $existing->event_type === DoliVoucherInvoiceSettlement::EVENT_REVERSAL && (int) $existing->reversal_of === $applySettlementId ? (int) $existing->rowid : $this->fail('ErrorIdempotencyConflict');

		$hint = $this->fetchApply($applySettlementId, $entity);
		if (!$hint) return $this->fail('ErrorSettlementNotFound');
		$this->db->begin();
		try {
			$this->lockIdempotencyKey($entity, $idempotencyKey);
			$existing = $this->findByIdempotency($entity, $idempotencyKey);
			if ($existing) {
				if (!$this->sameReversalRequest($existing, $applySettlementId)) throw new DomainException('ErrorIdempotencyConflict');
				$this->db->commit();
				return (int) $existing->rowid;
			}
			$invoiceRow = $this->lockInvoice((int) $hint->fk_facture, $entity);
			$this->lockInvoicePayments((int) $hint->fk_facture);
			$this->lockVoucher((int) $hint->fk_voucher, $entity);
			$apply = $this->one('SELECT * FROM '.$this->db->prefix().'dolivoucher_invoice_settlement WHERE rowid='.$applySettlementId.' AND entity='.$entity." AND event_type='APPLY' FOR UPDATE", 'ErrorSettlementNotFound');
			$reversal = $this->queryOne('SELECT rowid FROM '.$this->db->prefix().'dolivoucher_invoice_settlement WHERE reversal_of='.$applySettlementId.' LIMIT 1');
			if ($reversal) throw new DomainException('ErrorSettlementAlreadyReversed');
			$paymentRow = $this->one('SELECT rowid, ref, fk_bank, fk_export_compta FROM '.$this->db->prefix().'paiement WHERE rowid='.(int) $apply->fk_paiement.' AND entity='.$entity.' FOR UPDATE', 'ErrorSettlementPaymentMissing');
			if ((int) $paymentRow->fk_bank > 0 || (int) $paymentRow->fk_export_compta > 0) throw new DomainException('ErrorSettlementPaymentNotDeletable');

			$invoice = new Facture($this->db);
			if ($invoice->fetch((int) $apply->fk_facture) <= 0 || (int) $invoice->entity !== $entity) throw new DomainException('ErrorRecordNotFound');
			if ((int) $invoice->status === Facture::STATUS_CLOSED && $invoice->setUnpaid($user) < 0) throw new RuntimeException($invoice->error ?: 'ErrorInvoiceReopenFailed');
			if ((int) $invoice->status === Facture::STATUS_ABANDONED) throw new DomainException('ErrorSettlementInvoiceNotReopenable');
			$this->checkpoint('reversal_after_invoice_reopen');

			$payment = new Paiement($this->db);
			if ($payment->fetch((int) $apply->fk_paiement) <= 0 || $payment->isReconciled()) throw new DomainException('ErrorSettlementPaymentNotDeletable');
			self::$paymentDeletionAuthorized = true;
			try {
				if ($payment->delete($user) < 0) throw new RuntimeException($payment->error ?: 'ErrorSettlementPaymentDeleteFailed');
			} finally {
				self::$paymentDeletionAuthorized = false;
			}
			$this->checkpoint('reversal_after_payment_delete');

			$operationId = $this->voucherService->compensateConsumptionInTransaction((int) $apply->fk_operation, $entity, $user, $reason, 'facture', (int) $apply->fk_facture);
			$this->checkpoint('reversal_after_voucher_compensation');
			$reversalId = $this->appendSettlement($entity, DoliVoucherInvoiceSettlement::EVENT_REVERSAL, (int) $apply->fk_voucher, $operationId, (int) $apply->fk_facture, (int) $apply->fk_paiement, (string) $apply->amount, $applySettlementId, (string) $apply->invoice_ref_snapshot, (string) $paymentRow->ref, (string) $apply->request_source, $idempotencyKey, $user, $externalRef);
			$this->checkpoint('reversal_after_link');
			$invoice->fetch((int) $apply->fk_facture);
			$remainingAfterReversal = (string) price2num((string) $invoice->getRemainToPay(), 'MT');
			if ($this->isZeroOrNegative($remainingAfterReversal) && (int) $invoice->status === Facture::STATUS_VALIDATED && $invoice->setPaid($user) < 0) {
				throw new RuntimeException($invoice->error ?: (!empty($invoice->errors) ? implode(' | ', $invoice->errors) : ($this->db->lasterror() ?: 'ErrorInvoiceCloseFailed')));
			}
			$this->checkpoint('reversal_before_commit');
			$this->db->commit();
			$this->regenerateInvoiceDocument((int) $apply->fk_facture);
			return $reversalId;
		} catch (Throwable $e) {
			self::$paymentDeletionAuthorized = false;
			$this->db->rollback();
			$existing = $this->findByIdempotency($entity, $idempotencyKey);
			if ($existing && $this->sameReversalRequest($existing, $applySettlementId)) return (int) $existing->rowid;
			return $this->fail($e->getMessage());
		}
	}

	private function canApply(User $user): bool
	{
		return !empty($user->admin) || ($user->hasRight('dolivoucher', 'settlement', 'use') && $user->hasRight('facture', 'paiement'));
	}

	private function canReverse(User $user): bool
	{
		return !empty($user->admin) || ($user->hasRight('dolivoucher', 'settlement', 'reverse') && $user->hasRight('facture', 'paiement'));
	}

	private function assertInvoiceEligible(Facture $invoice, object $row): void
	{
		global $conf;
		if ((int) $row->fk_statut !== Facture::STATUS_VALIDATED || (int) $row->paye === 1 || !in_array((int) $row->type, array(Facture::TYPE_STANDARD, Facture::TYPE_REPLACEMENT), true)) throw new DomainException('ErrorInvoiceNotEligibleForVoucher');
		if ((int) $invoice->type === Facture::TYPE_REPLACEMENT && !empty($invoice->fk_facture_source)) {
			// Replacement invoices are accepted only through the same standard positive-balance path.
		}
		$currency = empty($row->multicurrency_code) ? (string) $conf->currency : (string) $row->multicurrency_code;
		if ($currency !== (string) $conf->currency || (!empty($row->multicurrency_tx) && DoliVoucherMoney::compare(DoliVoucherMoney::normalize((string) $row->multicurrency_tx), '1.00000000') !== 0)) throw new DomainException('ErrorMulticurrencyInvoiceExcluded');
		try {
			$remain = DoliVoucherMoney::normalize((string) $invoice->getRemainToPay());
		} catch (InvalidArgumentException $e) {
			throw new DomainException('ErrorInvoiceHasNoRemainToPay');
		}
		if (DoliVoucherMoney::compare($remain, '0.00000000') <= 0) throw new DomainException('ErrorInvoiceHasNoRemainToPay');
	}

	private function assertVoucherEligible(object $voucher): void
	{
		if (!in_array((int) $voucher->status, array(DoliVoucherVoucher::STATUS_ACTIVE, DoliVoucherVoucher::STATUS_PARTIALLY_CONSUMED), true)) throw new DomainException('ErrorVoucherNotConsumable');
		if (!empty($voucher->date_expiration) && $this->db->jdate($voucher->date_expiration) < dol_now()) throw new DomainException('ErrorVoucherExpired');
	}

	private function createPaymentWithoutDocument(Paiement $payment, User $user): int
	{
		global $conf;
		$hadValue = isset($conf->global->MAIN_DISABLE_PDF_AUTOUPDATE);
		$oldValue = $hadValue ? $conf->global->MAIN_DISABLE_PDF_AUTOUPDATE : null;
		$conf->global->MAIN_DISABLE_PDF_AUTOUPDATE = 1;
		try {
			return (int) $payment->create($user, 0);
		} finally {
			if ($hadValue) $conf->global->MAIN_DISABLE_PDF_AUTOUPDATE = $oldValue;
			else unset($conf->global->MAIN_DISABLE_PDF_AUTOUPDATE);
		}
	}

	/** Run validation-only extension hooks before any native or voucher write. */
	private function assertExtensionRestrictions(Facture $invoice, object $voucher, string $amount, int $entity, User $user): void
	{
		global $hookmanager;
		if (!is_object($hookmanager)) $hookmanager = new HookManager($this->db);
		$hookmanager->initHooks(array('dolivoucherinvoice'));
		$parameters = array('voucher' => $voucher, 'amount' => $amount, 'entity' => $entity, 'user' => $user);
		$action = 'apply';
		$result = $hookmanager->executeHooks('beforeDoliVoucherInvoiceSettlement', $parameters, $invoice, $action);
		if ($result < 0) throw new DomainException($hookmanager->error ?: 'ErrorSettlementRestrictionRefused');
	}

	private function regenerateInvoiceDocument(int $invoiceId): void
	{
		global $langs;
		$invoice = new Facture($this->db);
		if ($invoice->fetch($invoiceId) <= 0 || empty($invoice->model_pdf)) return;
		if ($invoice->generateDocument($invoice->model_pdf, $langs) < 0) {
			dol_syslog(__METHOD__.': '.$invoice->error, LOG_WARNING);
			setEventMessages($langs->trans('WarningSettlementSavedPdfRegenerationFailed'), $invoice->errors, 'warnings');
		}
	}

	private function paymentModeId(int $entity): int
	{
		$row = $this->queryOne('SELECT id FROM '.$this->db->prefix().'c_paiement WHERE entity='.$entity." AND code='".self::PAYMENT_CODE."' AND active=1 LIMIT 1");
		if (!$row) throw new DomainException('ErrorDoliVoucherPaymentModeMissing');
		return (int) $row->id;
	}

	private function lockInvoice(int $invoiceId, int $entity): object
	{
		return $this->one('SELECT rowid, entity, ref, fk_statut, paye, type, multicurrency_code, multicurrency_tx FROM '.$this->db->prefix().'facture WHERE rowid='.$invoiceId.' AND entity='.$entity.' FOR UPDATE', 'ErrorRecordNotFound');
	}

	private function lockInvoicePayments(int $invoiceId): void
	{
		$resql = $this->db->query('SELECT rowid FROM '.$this->db->prefix().'paiement_facture WHERE fk_facture='.$invoiceId.' ORDER BY rowid FOR UPDATE');
		if (!$resql) throw new RuntimeException('ErrorInvoicePaymentLockFailed');
		while ($this->db->fetch_object($resql)) { /* Lock the indexed range and every existing allocation. */ }
	}

	/** Return the current native remainder using a locking read, not a repeatable-read snapshot. */
	private function lockedInvoiceRemainToPay(int $invoiceId, int $entity): string
	{
		$sql = 'SELECT CAST(f.total_ttc-COALESCE((SELECT SUM(pf.amount) FROM '.$this->db->prefix().'paiement_facture pf WHERE pf.fk_facture=f.rowid),0)';
		$sql .= '-COALESCE((SELECT SUM(rc.amount_ttc) FROM '.$this->db->prefix().'societe_remise_except rc INNER JOIN '.$this->db->prefix().'facture fs ON fs.rowid=rc.fk_facture_source WHERE rc.fk_facture=f.rowid AND fs.type IN ('.Facture::TYPE_STANDARD.','.Facture::TYPE_CREDIT_NOTE.','.Facture::TYPE_DEPOSIT.','.Facture::TYPE_SITUATION.')),0) AS DECIMAL(24,8)) AS remain';
		$sql .= ' FROM '.$this->db->prefix().'facture f WHERE f.rowid='.$invoiceId.' AND f.entity='.$entity.' FOR UPDATE';
		$row = $this->one($sql, 'ErrorRecordNotFound');
		try {
			return DoliVoucherMoney::normalize((string) $row->remain, true);
		} catch (InvalidArgumentException $e) {
			throw new DomainException('ErrorInvoiceHasNoRemainToPay');
		}
	}

	private function lockVoucher(int $voucherId, int $entity): object
	{
		return $this->one('SELECT * FROM '.$this->db->prefix().'dolivoucher_voucher WHERE rowid='.$voucherId.' AND entity='.$entity.' FOR UPDATE', 'ErrorRecordNotFound');
	}

	private function lockIdempotencyKey(int $entity, string $key): void
	{
		$resql = $this->db->query('SELECT rowid FROM '.$this->db->prefix().'dolivoucher_invoice_settlement WHERE entity='.$entity." AND idempotency_key='".$this->db->escape($key)."' FOR UPDATE");
		if (!$resql) throw new RuntimeException('ErrorIdempotencyLockFailed');
	}

	private function appendSettlement(int $entity, string $eventType, int $voucherId, int $operationId, int $invoiceId, int $paymentId, string $amount, ?int $reversalOf, string $invoiceRef, string $paymentRef, string $source, string $idempotencyKey, User $user, string $externalRef): int
	{
		$amount = DoliVoucherMoney::normalize($amount, true);
		$sql = 'INSERT INTO '.$this->db->prefix().'dolivoucher_invoice_settlement (entity, settlement_uuid, idempotency_key, event_type, fk_voucher, fk_operation, fk_facture, fk_paiement, amount, reversal_of, invoice_ref_snapshot, payment_ref_snapshot, request_source, date_creation, fk_user_creat, external_ref) VALUES (';
		$sql .= $entity.", '".$this->db->escape(DoliVoucherService::uuid())."', '".$this->db->escape($idempotencyKey)."', '".$this->db->escape($eventType)."', ".$voucherId.', '.$operationId.', '.$invoiceId.', '.$paymentId.", CAST('".$this->db->escape($amount)."' AS DECIMAL(24,8)), ".($reversalOf === null ? 'NULL' : $reversalOf).", '".$this->db->escape($invoiceRef)."', '".$this->db->escape($paymentRef)."', '".$this->db->escape($source)."', '".$this->db->idate(dol_now())."', ".(int) $user->id.", '".$this->db->escape($externalRef)."')";
		if (!$this->db->query($sql)) throw new RuntimeException('ErrorSettlementAppendFailed');
		return (int) $this->db->last_insert_id($this->db->prefix().'dolivoucher_invoice_settlement');
	}

	private function assertPaymentHasNoBankLine(int $paymentId, int $entity): void
	{
		$row = $this->one('SELECT fk_bank FROM '.$this->db->prefix().'paiement WHERE rowid='.$paymentId.' AND entity='.$entity, 'ErrorSettlementPaymentMissing');
		if ((int) $row->fk_bank !== 0) throw new RuntimeException('ErrorSettlementUnexpectedBankLine');
	}

	private function findByIdempotency(int $entity, string $key): ?object
	{
		return $this->queryOne('SELECT * FROM '.$this->db->prefix().'dolivoucher_invoice_settlement WHERE entity='.$entity." AND idempotency_key='".$this->db->escape($key)."' LIMIT 1");
	}

	private function fetchApply(int $id, int $entity): ?object
	{
		return $this->queryOne('SELECT * FROM '.$this->db->prefix().'dolivoucher_invoice_settlement WHERE rowid='.$id.' AND entity='.$entity." AND event_type='APPLY' LIMIT 1");
	}

	private function sameApplyRequest(object $row, int $invoiceId, int $voucherId, $amount): bool
	{
		if ($row->event_type !== DoliVoucherInvoiceSettlement::EVENT_APPLY || (int) $row->fk_facture !== $invoiceId || (int) $row->fk_voucher !== $voucherId) return false;
		return $amount === null || trim((string) $amount) === '' || DoliVoucherMoney::compare((string) $row->amount, DoliVoucherMoney::normalize($amount, true)) === 0;
	}

	private function sameReversalRequest(object $row, int $applySettlementId): bool
	{
		return $row->event_type === DoliVoucherInvoiceSettlement::EVENT_REVERSAL && (int) $row->reversal_of === $applySettlementId;
	}

	private function validateKey(string $key): string
	{
		$key = trim($key);
		return preg_match('/^[A-Za-z0-9._:-]{16,128}$/', $key) ? $key : '';
	}

	private function validateSource(string $source): string
	{
		return in_array($source, array(DoliVoucherInvoiceSettlement::SOURCE_INVOICE_CARD), true) ? $source : DoliVoucherInvoiceSettlement::SOURCE_INVOICE_CARD;
	}

	private function isZeroOrNegative(string $amount): bool
	{
		$amount = trim($amount);
		if (str_starts_with($amount, '-')) return true;
		try {
			return DoliVoucherMoney::compare(DoliVoucherMoney::normalize($amount), '0.00000000') === 0;
		} catch (InvalidArgumentException $e) {
			return false;
		}
	}

	/** Inert production checkpoint. Integration tests may override it to force a rollback. */
	protected function checkpoint(string $point): void
	{
	}

	private function queryOne(string $sql): ?object
	{
		$resql = $this->db->query($sql);
		if (!$resql) throw new RuntimeException($this->db->lasterror());
		$row = $this->db->fetch_object($resql);
		return $row ?: null;
	}

	private function one(string $sql, string $error): object
	{
		$row = $this->queryOne($sql);
		if (!$row) throw new DomainException($error);
		return $row;
	}

	private function fail(string $message): int
	{
		global $langs;
		$this->error = $message;
		$this->errors[] = $message;
		setEventMessages(is_object($langs) ? $langs->trans($message) : $message, null, 'errors');
		dol_syslog(__METHOD__.': '.$message, LOG_WARNING);
		return -1;
	}
}
