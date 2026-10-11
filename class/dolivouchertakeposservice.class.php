<?php
/* Copyright (C) 2026 Fred Omega Junior */
declare(strict_types=1);

require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
require_once __DIR__.'/dolivouchertakeposintent.class.php';
require_once __DIR__.'/dolivoucherinvoicesettlementservice.class.php';
require_once __DIR__.'/dolivouchermoney.class.php';
require_once __DIR__.'/dolivoucherservice.class.php';

class DoliVoucherTakePosService
{
	private DoliDB $db;
	public string $error = '';
	public function __construct(DoliDB $db) { $this->db = $db; }

	public function inspect(int $invoiceId, string $identifier, string $terminal, User $user, int $entity): ?object
	{
		if (!$this->allowed($user) || !getDolGlobalInt('DOLIVOUCHER_TAKEPOS_ENABLED')) return $this->fail('ErrorTakePosIntegrationDisabled');
		if (getDolGlobalInt('TAKEPOS_DELAYED_TERMS') > 0) return $this->fail('ErrorTakePosDelayedTermsIncompatible');
		$invoice = $this->takePosInvoice($invoiceId, $terminal, $entity);
		if (!$invoice || (int) $invoice->status > Facture::STATUS_VALIDATED || DoliVoucherMoney::compare((string) $invoice->getRemainToPay(), '0') <= 0) return $this->fail('ErrorTakePosInvoiceNotEligible');
		$bridge = new DoliVoucherInvoiceSettlementService($this->db);
		$voucher = $bridge->findVoucher($identifier, $entity);
		if (!$voucher || !in_array((int) $voucher->status, array(2, 3), true) || DoliVoucherMoney::compare((string) $voucher->current_balance, '0') <= 0) return $this->fail('ErrorVoucherNotEligible');
		if (!empty($voucher->date_expiration) && $this->db->jdate($voucher->date_expiration) < dol_now()) return $this->fail('ErrorVoucherExpired');
		$maximum = DoliVoucherMoney::compare((string) $voucher->current_balance, (string) $invoice->getRemainToPay()) <= 0 ? (string) $voucher->current_balance : (string) $invoice->getRemainToPay();
		return (object) array('voucher_ref' => $voucher->ref, 'voucher_status' => (int) $voucher->status, 'voucher_expiration' => $voucher->date_expiration, 'voucher_balance' => DoliVoucherMoney::normalize((string) $voucher->current_balance), 'invoice_remainder' => DoliVoucherMoney::normalize((string) $invoice->getRemainToPay()), 'maximum' => DoliVoucherMoney::normalize($maximum));
	}

	public function prepare(int $invoiceId, string $identifier, $requestedAmount, string $key, string $terminal, string $place, User $user, int $entity): ?object
	{
		if (!$this->allowed($user) || !getDolGlobalInt('DOLIVOUCHER_TAKEPOS_ENABLED')) return $this->fail('ErrorTakePosIntegrationDisabled');
		if (getDolGlobalInt('TAKEPOS_DELAYED_TERMS') > 0) return $this->fail('ErrorTakePosDelayedTermsIncompatible');
		$invoice = $this->takePosInvoice($invoiceId, $terminal, $entity);
		if (!$invoice || (int) $invoice->status > Facture::STATUS_VALIDATED || DoliVoucherMoney::compare((string) $invoice->getRemainToPay(), '0') <= 0) return $this->fail('ErrorTakePosInvoiceNotEligible');
		$bridge = new DoliVoucherInvoiceSettlementService($this->db);
		$voucher = $bridge->findVoucher($identifier, $entity);
		if (!$voucher || !in_array((int) $voucher->status, array(2, 3), true) || DoliVoucherMoney::compare((string) $voucher->current_balance, '0') <= 0) return $this->fail('ErrorVoucherNotEligible');
		if (!empty($voucher->date_expiration) && $this->db->jdate($voucher->date_expiration) < dol_now()) return $this->fail('ErrorVoucherExpired');
		$maximum = DoliVoucherMoney::compare((string) $voucher->current_balance, (string) $invoice->getRemainToPay()) <= 0 ? (string) $voucher->current_balance : (string) $invoice->getRemainToPay();
		try {
			$amount = trim((string) $requestedAmount) === '' ? DoliVoucherMoney::normalize($maximum, true) : DoliVoucherMoney::normalize($requestedAmount, true);
		} catch (InvalidArgumentException $e) {
			return $this->fail('ErrorInvalidAmount');
		}
		if (DoliVoucherMoney::compare($amount, $maximum) > 0) return $this->fail('ErrorSettlementAmountExceedsMaximum');
		if (!preg_match('/^[a-zA-Z0-9-]{16,64}$/', $key)) return $this->fail('ErrorInvalidIdempotencyKey');
		$fingerprint = hash('sha256', implode('|', array($entity, $invoiceId, $voucher->rowid, $amount, $terminal, $place, $user->id)));
		$existing = $this->one("SELECT * FROM ".$this->db->prefix()."dolivoucher_takepos_intent WHERE entity=".$entity." AND idempotency_key='".$this->db->escape($key)."'");
		if ($existing) return hash_equals((string) $existing->request_fingerprint, $fingerprint) ? $existing : $this->fail('ErrorIdempotencyConflict');
		$activeIntent = $this->one("SELECT rowid FROM ".$this->db->prefix()."dolivoucher_takepos_intent WHERE entity=".$entity." AND fk_facture=".$invoiceId." AND fk_user_creat=".(int) $user->id." AND status IN ('DRAFT','VALIDATION_REQUESTED','INVOICE_VALIDATED','SETTLEMENT_REQUESTED')".$this->db->plimit(1, 0));
		if ($activeIntent) return $this->fail('ErrorTakePosActiveIntentExists');
		$maxVouchers = max(1, min(100, getDolGlobalInt('DOLIVOUCHER_TAKEPOS_MAX_VOUCHERS_PER_SALE', 10)));
		$count = $this->one("SELECT COUNT(*) AS amount FROM ".$this->db->prefix()."dolivoucher_invoice_settlement s LEFT JOIN ".$this->db->prefix()."dolivoucher_invoice_settlement r ON r.reversal_of=s.rowid AND r.entity=s.entity WHERE s.entity=".$entity." AND s.fk_facture=".$invoiceId." AND s.event_type='APPLY' AND s.request_source='TAKEPOS' AND r.rowid IS NULL");
		if ((int) ($count->amount ?? 0) >= $maxVouchers) return $this->fail('ErrorTakePosVoucherLimitReached');
		$uuid = DoliVoucherService::uuid();
		$settlementKey = DoliVoucherService::uuid();
		$paymentSnapshot = $this->paymentSnapshot($invoiceId);
		$expiry = dol_now() + 60 * max(1, min(1440, getDolGlobalInt('DOLIVOUCHER_TAKEPOS_INTENT_TTL_MINUTES', 30)));
		$sql = 'INSERT INTO '.$this->db->prefix().'dolivoucher_takepos_intent (entity,intent_uuid,idempotency_key,settlement_key,request_fingerprint,fk_facture,invoice_ref_snapshot,fk_voucher,voucher_ref_snapshot,amount,payment_count_before,bank_count_before,payment_terms_before,terminal_id,place_code,fk_user_creat,status,date_creation,date_expiration) VALUES (';
		$sql .= $entity.",'".$this->db->escape($uuid)."','".$this->db->escape($key)."','".$this->db->escape($settlementKey)."','".$fingerprint."',".$invoiceId.",'".$this->db->escape((string) $invoice->ref)."',".(int) $voucher->rowid.",'".$this->db->escape((string) $voucher->ref)."','".$amount."',".$paymentSnapshot['payments'].",".$paymentSnapshot['banks'].",".(int) $invoice->cond_reglement_id.",'".$this->db->escape($terminal)."','".$this->db->escape($place)."',".(int) $user->id.",'DRAFT','".$this->db->idate(dol_now())."','".$this->db->idate($expiry)."')";
		if (!$this->db->query($sql)) return $this->fail('ErrorTakePosIntentCreateFailed');
		return $this->one('SELECT * FROM '.$this->db->prefix().'dolivoucher_takepos_intent WHERE rowid='.(int) $this->db->last_insert_id($this->db->prefix().'dolivoucher_takepos_intent'));
	}

	public function authorize(string $uuid, string $terminal, User $user, int $entity): ?object
	{
		if (!$this->allowed($user) || !getDolGlobalInt('DOLIVOUCHER_TAKEPOS_ENABLED')) return $this->fail('ErrorTakePosIntegrationDisabled');
		return $this->withIntent($uuid, $entity, function ($intent) use ($terminal, $user, $entity) {
			if ((int) $intent->fk_user_creat !== (int) $user->id || (string) $intent->terminal_id !== $terminal) throw new DomainException('ErrorTakePosIntentAccessDenied');
			$invoice = $this->takePosInvoice((int) $intent->fk_facture, $terminal, $entity);
			if (!$invoice) throw new DomainException('ErrorTakePosInvoiceNotEligible');
			if ((int) $invoice->status === Facture::STATUS_DRAFT) {
				if ($intent->status === DoliVoucherTakePosIntent::STATUS_VALIDATION_REQUESTED) {
					$intent->native_validation_required = false;
					return $intent;
				}
				$this->transition($intent, DoliVoucherTakePosIntent::STATUS_VALIDATION_REQUESTED);
			}
			elseif ((int) $invoice->status === Facture::STATUS_VALIDATED && DoliVoucherMoney::compare((string) $invoice->getRemainToPay(), '0') > 0) $this->transition($intent, DoliVoucherTakePosIntent::STATUS_INVOICE_VALIDATED);
			else throw new DomainException('ErrorTakePosInvoiceNotEligible');
			$intent->native_validation_required = (int) $invoice->status === Facture::STATUS_DRAFT;
			return $intent;
		});
	}

	public function settle(string $uuid, string $terminal, User $user, int $entity): ?object
	{
		if (!$this->allowed($user) || !getDolGlobalInt('DOLIVOUCHER_TAKEPOS_ENABLED')) return $this->fail('ErrorTakePosIntegrationDisabled');
		$intent = $this->withIntent($uuid, $entity, function ($intent) use ($terminal, $user, $entity) {
			if ((int) $intent->fk_user_creat !== (int) $user->id || (string) $intent->terminal_id !== $terminal) throw new DomainException('ErrorTakePosIntentAccessDenied');
			if ($intent->status === DoliVoucherTakePosIntent::STATUS_SETTLED && (int) $intent->fk_settlement > 0) return $intent;
			$existing = $this->one("SELECT rowid FROM ".$this->db->prefix()."dolivoucher_invoice_settlement WHERE entity=".$entity." AND idempotency_key='".$this->db->escape((string) $intent->settlement_key)."' AND event_type='APPLY' AND request_source='TAKEPOS'");
			if ($existing) {
				$this->finalizeIntent($intent, (int) $existing->rowid, $entity);
				return $intent;
			}
			$invoice = $this->takePosInvoice((int) $intent->fk_facture, $terminal, $entity);
			if (!$invoice || (int) $invoice->status === Facture::STATUS_DRAFT) throw new DomainException('INVOICE_STILL_DRAFT');
			if ((int) $invoice->status !== Facture::STATUS_VALIDATED || DoliVoucherMoney::compare((string) $invoice->getRemainToPay(), '0') <= 0) throw new DomainException('ErrorTakePosInvoiceNotEligible');
			$snapshot = $this->paymentSnapshot((int) $intent->fk_facture);
			if ($snapshot['payments'] !== (int) $intent->payment_count_before) throw new DomainException('UNEXPECTED_NATIVE_PAYMENT');
			if ($snapshot['banks'] !== (int) $intent->bank_count_before) throw new DomainException('UNEXPECTED_BANK_LINE');
			if ((int) $invoice->cond_reglement_id !== (int) $intent->payment_terms_before) throw new DomainException('TAKEPOS_PAYMENT_TERMS_CHANGED');
			if ($intent->status === DoliVoucherTakePosIntent::STATUS_VALIDATION_REQUESTED) $this->transition($intent, DoliVoucherTakePosIntent::STATUS_INVOICE_VALIDATED);
			$this->transition($intent, DoliVoucherTakePosIntent::STATUS_SETTLEMENT_REQUESTED);
			$this->checkpoint('before_apply');
			return $intent;
		});
		if (!$intent) {
			$failed = $this->one("SELECT rowid,fk_user_creat,terminal_id FROM ".$this->db->prefix()."dolivoucher_takepos_intent WHERE entity=".$entity." AND intent_uuid='".$this->db->escape($uuid)."'");
			if ($failed && (int) $failed->fk_user_creat === (int) $user->id && (string) $failed->terminal_id === $terminal) $this->markFailure((int) $failed->rowid, $this->error ?: 'SETTLEMENT_FAILED');
			return null;
		}
		if ($intent->status === DoliVoucherTakePosIntent::STATUS_SETTLED && (int) $intent->fk_settlement > 0) return $intent;
		try {
			$service = $this->settlementService();
			$id = $service->apply((int) $intent->fk_facture, (int) $intent->fk_voucher, $entity, (string) $intent->amount, (string) $intent->settlement_key, $user, 'TakePOS', '', DoliVoucherInvoiceSettlement::SOURCE_TAKEPOS);
			if ($id <= 0) { $this->markFailure((int) $intent->rowid, $service->error ?: 'SETTLEMENT_FAILED'); return $this->fail($service->error ?: 'SETTLEMENT_FAILED'); }
			$this->checkpoint('after_apply');
			$this->finalizeIntent($intent, $id, $entity);
			$this->checkpoint('after_finalize');
			return $this->one('SELECT * FROM '.$this->db->prefix().'dolivoucher_takepos_intent WHERE rowid='.(int) $intent->rowid);
		} catch (Throwable $e) {
			$this->markFailure((int) $intent->rowid, $e->getMessage() ?: 'SETTLEMENT_FAILED');
			return $this->fail($e->getMessage() ?: 'SETTLEMENT_FAILED');
		}
	}

	/** Confirm from the database that the native TakePOS validation succeeded. */
	public function observeValidation(string $uuid, string $terminal, User $user, int $entity): ?object
	{
		if (!$this->allowed($user) || !getDolGlobalInt('DOLIVOUCHER_TAKEPOS_ENABLED')) return $this->fail('ErrorTakePosIntegrationDisabled');
		return $this->withIntent($uuid, $entity, function ($intent) use ($terminal, $user, $entity) {
			if ((int) $intent->fk_user_creat !== (int) $user->id || (string) $intent->terminal_id !== $terminal) throw new DomainException('ErrorTakePosIntentAccessDenied');
			$invoice = $this->takePosInvoice((int) $intent->fk_facture, $terminal, $entity);
			if (!$invoice) throw new DomainException('ErrorTakePosInvoiceNotEligible');
			if ((int) $invoice->status === Facture::STATUS_DRAFT) throw new DomainException('INVOICE_STILL_DRAFT');
			if ((int) $invoice->status !== Facture::STATUS_VALIDATED || DoliVoucherMoney::compare((string) $invoice->getRemainToPay(), '0') <= 0) throw new DomainException('ErrorTakePosInvoiceNotEligible');
			$snapshot = $this->paymentSnapshot((int) $intent->fk_facture);
			if ($snapshot['payments'] !== (int) $intent->payment_count_before) throw new DomainException('UNEXPECTED_NATIVE_PAYMENT');
			if ($snapshot['banks'] !== (int) $intent->bank_count_before) throw new DomainException('UNEXPECTED_BANK_LINE');
			if ((int) $invoice->cond_reglement_id !== (int) $intent->payment_terms_before) throw new DomainException('TAKEPOS_PAYMENT_TERMS_CHANGED');
			if ($intent->status === DoliVoucherTakePosIntent::STATUS_VALIDATION_REQUESTED) $this->transition($intent, DoliVoucherTakePosIntent::STATUS_INVOICE_VALIDATED);
			elseif ($intent->status !== DoliVoucherTakePosIntent::STATUS_INVOICE_VALIDATED && $intent->status !== DoliVoucherTakePosIntent::STATUS_SETTLEMENT_FAILED) throw new DomainException('INVALID_INTENT_TRANSITION');
			$intent->invoice_ref_snapshot = (string) $invoice->ref;
			if (!$this->db->query("UPDATE ".$this->db->prefix()."dolivoucher_takepos_intent SET invoice_ref_snapshot='".$this->db->escape((string) $invoice->ref)."' WHERE rowid=".(int) $intent->rowid)) throw new RuntimeException('NATIVE_VALIDATION_FAILED');
			return $intent;
		});
	}

	public function state(string $uuid, string $terminal, User $user, int $entity): ?object
	{
		if (!$this->allowed($user)) return $this->fail('ErrorTakePosIntentAccessDenied');
		$intent = $this->one("SELECT * FROM ".$this->db->prefix()."dolivoucher_takepos_intent WHERE entity=".$entity." AND intent_uuid='".$this->db->escape($uuid)."'");
		if (!$intent || (int) $intent->fk_user_creat !== (int) $user->id || (string) $intent->terminal_id !== $terminal) return $this->fail('ErrorTakePosIntentNotFound');
		return $intent;
	}

	public function cancel(string $uuid, string $terminal, User $user, int $entity): ?object
	{
		if (!$this->allowed($user)) return $this->fail('ErrorTakePosIntentAccessDenied');
		return $this->withIntent($uuid, $entity, function ($intent) use ($terminal, $user) {
			if ((int) $intent->fk_user_creat !== (int) $user->id || (string) $intent->terminal_id !== $terminal) throw new DomainException('ErrorTakePosIntentAccessDenied');
			if ((int) $intent->fk_settlement > 0 || $intent->status === DoliVoucherTakePosIntent::STATUS_SETTLED) throw new DomainException('INVALID_INTENT_TRANSITION');
			$this->transition($intent, DoliVoucherTakePosIntent::STATUS_CANCELLED);
			return $intent;
		});
	}

	public function expireDrafts(int $entity): int
	{
		$sql = "UPDATE ".$this->db->prefix()."dolivoucher_takepos_intent SET status='EXPIRED',failure_code='INTENT_EXPIRED',date_failure='".$this->db->idate(dol_now())."'";
		$sql .= " WHERE entity=".$entity." AND status='DRAFT' AND fk_settlement IS NULL AND date_expiration IS NOT NULL AND date_expiration < '".$this->db->idate(dol_now())."'";
		$res = $this->db->query($sql);
		return $res ? $this->db->affected_rows($res) : -1;
	}

	private function withIntent(string $uuid, int $entity, callable $callback): ?object
	{
		$this->db->begin();
		try { $intent = $this->one("SELECT * FROM ".$this->db->prefix()."dolivoucher_takepos_intent WHERE entity=".$entity." AND intent_uuid='".$this->db->escape($uuid)."' FOR UPDATE"); if (!$intent) throw new DomainException('ErrorTakePosIntentNotFound'); $result = $callback($intent); $this->db->commit(); return $result; }
		catch (Throwable $e) { $this->db->rollback(); return $this->fail($e->getMessage()); }
	}
	private function transition(object $intent, string $to): void { if ($intent->status === $to) return; if (!DoliVoucherTakePosIntent::canTransition((string) $intent->status, $to)) throw new DomainException('INVALID_INTENT_TRANSITION'); $this->db->query("UPDATE ".$this->db->prefix()."dolivoucher_takepos_intent SET status='".$this->db->escape($to)."'".($to === DoliVoucherTakePosIntent::STATUS_INVOICE_VALIDATED ? ",date_validation='".$this->db->idate(dol_now())."'" : '')." WHERE rowid=".(int) $intent->rowid); $intent->status = $to; }
	private function finalizeIntent(object $intent, int $settlementId, int $entity): void { if (!$this->db->query("UPDATE ".$this->db->prefix()."dolivoucher_takepos_intent SET status='SETTLED',fk_settlement=".$settlementId.",date_settlement='".$this->db->idate(dol_now())."',failure_code=NULL,failure_message=NULL WHERE rowid=".(int) $intent->rowid.' AND entity='.$entity)) throw new RuntimeException('SETTLEMENT_FAILED'); $intent->status = DoliVoucherTakePosIntent::STATUS_SETTLED; $intent->fk_settlement = $settlementId; }
	protected function settlementService(): DoliVoucherInvoiceSettlementService { return new DoliVoucherInvoiceSettlementService($this->db); }
	protected function checkpoint(string $point): void { }
	private function takePosInvoice(int $id, string $terminal, int $entity): ?Facture { $invoice = new Facture($this->db); if ($invoice->fetch($id) <= 0 || (int) $invoice->entity !== $entity || (string) $invoice->module_source !== 'takepos' || (string) $invoice->pos_source !== $terminal) return null; return $invoice; }
	/** @return array{payments:int,banks:int} */
	private function paymentSnapshot(int $invoiceId): array
	{
		$row = $this->one('SELECT COUNT(DISTINCT p.rowid) AS payments, COUNT(DISTINCT CASE WHEN p.fk_bank>0 THEN p.rowid END) AS banks FROM '.$this->db->prefix().'paiement_facture pf INNER JOIN '.$this->db->prefix().'paiement p ON p.rowid=pf.fk_paiement WHERE pf.fk_facture='.$invoiceId);
		return array('payments' => (int) ($row->payments ?? 0), 'banks' => (int) ($row->banks ?? 0));
	}
	private function allowed(User $user): bool { return !empty($user->admin) || ($user->hasRight('takepos', 'run') && $user->hasRight('dolivoucher', 'settlement', 'use') && $user->hasRight('facture', 'paiement') && $user->hasRight('facture', 'creer')); }
	private function one(string $sql): ?object { $res = $this->db->query($sql); $row = $res ? $this->db->fetch_object($res) : false; return $row ?: null; }
	private function markFailure(int $id, string $code): void { $safe = substr((string) preg_replace('/[^A-Z0-9_]/', '_', strtoupper($code)), 0, 64); $this->db->query("UPDATE ".$this->db->prefix()."dolivoucher_takepos_intent SET status='SETTLEMENT_FAILED',failure_code='".$this->db->escape($safe)."',date_failure='".$this->db->idate(dol_now())."' WHERE rowid=".$id); }
	private function fail(string $error) { $this->error = $error; dol_syslog(__METHOD__.' '.$error, LOG_WARNING); return null; }
}
