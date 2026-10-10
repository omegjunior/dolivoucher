<?php
/* Copyright (C) 2026 Fred Omega Junior */

declare(strict_types=1);

require_once __DIR__.'/dolivouchermoney.class.php';

/** Read-only reconciliation of DoliVoucher settlements and native invoice payments. */
final class DoliVoucherSettlementDiagnosticService
{
	public const LEVEL_OK = 'OK';
	public const LEVEL_WARNING = 'WARNING';
	public const LEVEL_ERROR = 'ERROR';

	public const CODE_OK = 'SETTLEMENT_OK';
	public const CODE_VOUCHER_MISSING = 'VOUCHER_MISSING';
	public const CODE_OPERATION_MISSING = 'OPERATION_MISSING';
	public const CODE_INVOICE_MISSING = 'INVOICE_MISSING';
	public const CODE_PAYMENT_MISSING = 'PAYMENT_MISSING';
	public const CODE_ALLOCATION_MISSING = 'ALLOCATION_MISSING';
	public const CODE_AMOUNT_MISMATCH = 'AMOUNT_MISMATCH';
	public const CODE_ENTITY_MISMATCH = 'ENTITY_MISMATCH';
	public const CODE_UNEXPECTED_BANK_LINE = 'UNEXPECTED_BANK_LINE';
	public const CODE_PAYMENT_MODE_MISMATCH = 'PAYMENT_MODE_MISMATCH';
	public const CODE_REVERSAL_MISSING = 'REVERSAL_MISSING';
	public const CODE_MULTIPLE_REVERSALS = 'MULTIPLE_REVERSALS';
	public const CODE_ORPHAN_REVERSAL = 'ORPHAN_REVERSAL';
	public const CODE_INVALID_REQUEST_SOURCE = 'INVALID_REQUEST_SOURCE';
	public const CODE_GENERIC_COMPENSATION = 'GENERIC_COMPENSATION_DETECTED';
	public const CODE_ALLOCATION_INVOICE_MISMATCH = 'ALLOCATION_INVOICE_MISMATCH';
	public const CODE_OPERATION_LINK_MISMATCH = 'OPERATION_LINK_MISMATCH';
	public const CODE_OPERATION_TYPE_MISMATCH = 'OPERATION_TYPE_MISMATCH';
	public const CODE_COMPENSATION_MISSING = 'COMPENSATION_OPERATION_MISSING';
	public const CODE_PAYMENT_STILL_PRESENT = 'PAYMENT_STILL_PRESENT_AFTER_REVERSAL';
	public const CODE_INVOICE_REFERENCE_CHANGED = 'INVOICE_REFERENCE_CHANGED';
	public const CODE_PAYMENT_REFERENCE_CHANGED = 'PAYMENT_REFERENCE_CHANGED';
	public const CODE_PAYMENT_EXPORTED = 'PAYMENT_EXPORTED';
	public const CODE_PAYMENT_RECONCILED = 'PAYMENT_RECONCILED';

	public const SOURCE_INVOICE_CARD = 'INVOICE_CARD';
	public const SOURCE_TAKEPOS = 'TAKEPOS';
	public const MAX_ROWS_DEFAULT = 1000;
	public const MAX_ROWS_HARD_LIMIT = 10000;

	private DoliDB $db;

	public function __construct(DoliDB $db)
	{
		$this->db = $db;
	}

	/** @return array{record:object,diagnostic:array<string,mixed>}|null */
	public function fetchOne(int $settlementId, int $entity): ?array
	{
		if ($settlementId <= 0 || $entity <= 0) return null;
		$resql = $this->db->query($this->buildQuery($entity, array('settlement_id' => $settlementId), 's.rowid', 'ASC', 1, 0));
		$row = $resql ? $this->db->fetch_object($resql) : false;
		if (!$row) return null;
		return array('record' => $row, 'diagnostic' => $this->diagnoseRow($row, $entity));
	}

	/**
	 * Fetch a bounded diagnostic page. Diagnostic-level filters are evaluated in bounded SQL batches.
	 *
	 * @param array<string,mixed> $filters
	 * @return array{items:array<int,array{record:object,diagnostic:array<string,mixed>}>,has_more:bool,scanned:int,limited:bool}
	 */
	public function fetchPage(int $entity, array $filters, int $limit, int $offset, string $sortField, string $sortOrder, int $maxRows): array
	{
		$maxRows = self::normalizeMaxRows($maxRows);
		$limit = max(1, min($maxRows, $limit));
		$offset = max(0, $offset);
		$level = strtoupper((string) ($filters['diagnostic_level'] ?? ''));
		$code = strtoupper((string) ($filters['diagnostic_code'] ?? ''));
		$needsDiagnosticFilter = in_array($level, self::levels(), true) || in_array($code, self::codes(), true);
		$items = array();
		$scanned = 0;

		if (!$needsDiagnosticFilter) {
			$resql = $this->db->query($this->buildQuery($entity, $filters, $sortField, $sortOrder, $limit + 1, $offset));
			$hasMore = false;
			while ($resql && ($row = $this->db->fetch_object($resql))) {
				if (count($items) >= $limit) { $hasMore = true; break; }
				$items[] = array('record' => $row, 'diagnostic' => $this->diagnoseRow($row, $entity));
			}
			return array('items' => $items, 'has_more' => $hasMore, 'scanned' => count($items), 'limited' => false);
		}

		$wanted = $offset + $limit + 1;
		$batchOffset = 0;
		$this->db->begin();
		try {
			while ($scanned < $maxRows && count($items) < $wanted) {
				$batchSize = min(200, $maxRows - $scanned);
				$resql = $this->db->query($this->buildQuery($entity, $filters, $sortField, $sortOrder, $batchSize, $batchOffset));
				if (!$resql) throw new RuntimeException('ErrorSettlementDiagnosticQueryFailed');
				$batchCount = 0;
				while ($row = $this->db->fetch_object($resql)) {
					$batchCount++;
					$diagnostic = $this->diagnoseRow($row, $entity);
					if ($level !== '' && $diagnostic['level'] !== $level) continue;
					if ($code !== '' && !in_array($code, $diagnostic['codes'], true)) continue;
					$items[] = array('record' => $row, 'diagnostic' => $diagnostic);
				}
				$scanned += $batchCount;
				$batchOffset += $batchCount;
				if ($batchCount < $batchSize) break;
			}
			$this->db->commit();
		} catch (Throwable $e) {
			$this->db->rollback();
			throw $e;
		}
		$pageItems = array_slice($items, $offset, $limit);
		return array('items' => $pageItems, 'has_more' => count($items) > $offset + $limit, 'scanned' => $scanned, 'limited' => $scanned >= $maxRows);
	}

	/** @param array<int,array{record:object,diagnostic:array<string,mixed>}> $items */
	public function summarize(array $items): array
	{
		$summary = array('analyzed' => count($items), self::LEVEL_OK => 0, self::LEVEL_WARNING => 0, self::LEVEL_ERROR => 0, 'codes' => array());
		foreach ($items as $item) {
			$level = (string) $item['diagnostic']['level'];
			$summary[$level]++;
			foreach ($item['diagnostic']['codes'] as $code) {
				if ($code === self::CODE_OK) continue;
				$summary['codes'][$code] = ($summary['codes'][$code] ?? 0) + 1;
			}
		}
		ksort($summary['codes']);
		return $summary;
	}

	/** @return array{level:string,codes:array<int,string>,checks:array<int,array{code:string,level:string,message_key:string}>} */
	public function diagnoseRow(object $row, int $entity): array
	{
		$checks = array();
		$eventType = (string) ($row->event_type ?? '');
		$isApply = $eventType === 'APPLY';
		$isReversal = $eventType === 'REVERSAL';
		$reversalCount = (int) ($row->linked_reversal_count ?? 0);
		$isReversedApply = $isApply && $reversalCount > 0;

		if (!in_array((string) ($row->request_source ?? ''), array(self::SOURCE_INVOICE_CARD, self::SOURCE_TAKEPOS), true)) {
			$this->addCheck($checks, self::CODE_INVALID_REQUEST_SOURCE, self::LEVEL_ERROR);
		}
		if ($isApply && $reversalCount > 1) $this->addCheck($checks, self::CODE_MULTIPLE_REVERSALS, self::LEVEL_ERROR);
		if ($isReversal && empty($row->parent_settlement_exists)) $this->addCheck($checks, self::CODE_ORPHAN_REVERSAL, self::LEVEL_ERROR);

		$this->checkObject($checks, $row, 'voucher_exists', 'voucher_entity', self::CODE_VOUCHER_MISSING, $entity);
		$this->checkObject($checks, $row, 'operation_exists', 'operation_entity', self::CODE_OPERATION_MISSING, $entity);
		$this->checkObject($checks, $row, 'invoice_exists', 'invoice_entity', self::CODE_INVOICE_MISSING, $entity);

		if (!empty($row->operation_exists)) {
			$expectedType = $isReversal ? 'CORRECTION' : 'CONSUME';
			if ((string) ($row->operation_type ?? '') !== $expectedType) $this->addCheck($checks, self::CODE_OPERATION_TYPE_MISMATCH, self::LEVEL_ERROR);
			if ((int) ($row->operation_voucher ?? 0) !== (int) ($row->fk_voucher ?? 0)
				|| (string) ($row->operation_object_type ?? '') !== 'facture'
				|| (int) ($row->operation_object_id ?? 0) !== (int) ($row->fk_facture ?? 0)) {
				$this->addCheck($checks, self::CODE_OPERATION_LINK_MISMATCH, self::LEVEL_ERROR);
			}
			if (!$this->sameAmount($row->amount ?? '', $row->operation_amount ?? '')) $this->addCheck($checks, self::CODE_AMOUNT_MISMATCH, self::LEVEL_ERROR);
			if (isset($row->operation_balance_delta) && !$this->sameAmount($row->amount ?? '', $row->operation_balance_delta)) $this->addCheck($checks, self::CODE_AMOUNT_MISMATCH, self::LEVEL_ERROR);
		}

		if ($isReversal && !empty($row->parent_settlement_exists)) {
			if ((int) ($row->parent_entity ?? 0) !== $entity) $this->addCheck($checks, self::CODE_ENTITY_MISMATCH, self::LEVEL_ERROR);
			if ((string) ($row->parent_event_type ?? '') !== 'APPLY'
				|| (int) ($row->parent_voucher_id ?? 0) !== (int) ($row->fk_voucher ?? 0)
				|| (int) ($row->parent_invoice_id ?? 0) !== (int) ($row->fk_facture ?? 0)
				|| !$this->sameAmount($row->amount ?? '', $row->parent_amount ?? '')
				|| (int) ($row->operation_reversal_of ?? 0) !== (int) ($row->parent_operation_id ?? 0)) {
				$this->addCheck($checks, self::CODE_OPERATION_LINK_MISMATCH, self::LEVEL_ERROR);
			}
		}

		if ($isApply && (int) ($row->generic_compensation_count ?? 0) > 0) {
			$validLinkedCompensation = $isReversedApply
				&& (int) ($row->linked_reversal_operation_id ?? 0) === (int) ($row->generic_compensation_id ?? 0);
			if (!$validLinkedCompensation) {
				$this->addCheck($checks, self::CODE_GENERIC_COMPENSATION, self::LEVEL_ERROR);
				$this->addCheck($checks, self::CODE_REVERSAL_MISSING, self::LEVEL_ERROR);
			}
		}

		$paymentExpected = $isApply && !$isReversedApply;
		if ($paymentExpected && empty($row->payment_exists)) $this->addCheck($checks, self::CODE_PAYMENT_MISSING, self::LEVEL_ERROR);
		if (($isReversedApply || $isReversal) && !empty($row->payment_exists)) $this->addCheck($checks, self::CODE_PAYMENT_STILL_PRESENT, self::LEVEL_ERROR);
		if (!empty($row->payment_exists)) {
			if ((int) ($row->payment_entity ?? 0) !== $entity) $this->addCheck($checks, self::CODE_ENTITY_MISMATCH, self::LEVEL_ERROR);
			if ((string) ($row->payment_mode_code ?? '') !== 'DVOUCH') $this->addCheck($checks, self::CODE_PAYMENT_MODE_MISMATCH, self::LEVEL_ERROR);
			if ((int) ($row->payment_bank_id ?? 0) > 0) $this->addCheck($checks, self::CODE_UNEXPECTED_BANK_LINE, self::LEVEL_ERROR);
			if ((int) ($row->payment_export_id ?? 0) > 0) $this->addCheck($checks, self::CODE_PAYMENT_EXPORTED, self::LEVEL_WARNING);
			if ((int) ($row->payment_reconciled ?? 0) > 0) $this->addCheck($checks, self::CODE_PAYMENT_RECONCILED, self::LEVEL_WARNING);
			if ((string) ($row->payment_ref_snapshot ?? '') !== '' && (string) ($row->payment_ref ?? '') !== '' && (string) $row->payment_ref_snapshot !== (string) $row->payment_ref) {
				$this->addCheck($checks, self::CODE_PAYMENT_REFERENCE_CHANGED, self::LEVEL_WARNING);
			}
		}

		if ($paymentExpected) {
			if ((int) ($row->allocation_count ?? 0) < 1) {
				$this->addCheck($checks, self::CODE_ALLOCATION_MISSING, self::LEVEL_ERROR);
			} else {
				if ((int) ($row->allocation_count ?? 0) !== 1 || (int) ($row->allocation_invoice_min ?? 0) !== (int) ($row->fk_facture ?? 0) || (int) ($row->allocation_invoice_max ?? 0) !== (int) ($row->fk_facture ?? 0)) {
					$this->addCheck($checks, self::CODE_ALLOCATION_INVOICE_MISMATCH, self::LEVEL_ERROR);
				}
				if (!$this->sameAmount($row->amount ?? '', $row->allocation_amount ?? '')) $this->addCheck($checks, self::CODE_AMOUNT_MISMATCH, self::LEVEL_ERROR);
			}
		}

		if (!empty($row->invoice_exists) && (string) ($row->invoice_ref_snapshot ?? '') !== '' && (string) ($row->invoice_ref ?? '') !== '' && (string) $row->invoice_ref_snapshot !== (string) $row->invoice_ref) {
			$this->addCheck($checks, self::CODE_INVOICE_REFERENCE_CHANGED, self::LEVEL_WARNING);
		}

		if ($isReversedApply) {
			if (empty($row->linked_reversal_operation_id)) $this->addCheck($checks, self::CODE_COMPENSATION_MISSING, self::LEVEL_ERROR);
			if ((int) ($row->linked_reversal_entity ?? 0) !== $entity) $this->addCheck($checks, self::CODE_ENTITY_MISMATCH, self::LEVEL_ERROR);
			if ((int) ($row->linked_reversal_voucher_id ?? 0) !== (int) ($row->fk_voucher ?? 0)
				|| (int) ($row->linked_reversal_invoice_id ?? 0) !== (int) ($row->fk_facture ?? 0)
				|| !$this->sameAmount($row->amount ?? '', $row->linked_reversal_amount ?? '')
				|| (string) ($row->linked_reversal_operation_type ?? '') !== 'CORRECTION'
				|| (int) ($row->linked_reversal_operation_reversal_of ?? 0) !== (int) ($row->fk_operation ?? 0)
				|| !$this->sameAmount($row->amount ?? '', $row->linked_reversal_operation_amount ?? '')
				|| (isset($row->linked_reversal_balance_delta) && !$this->sameAmount($row->amount ?? '', $row->linked_reversal_balance_delta))) {
				$this->addCheck($checks, self::CODE_OPERATION_LINK_MISMATCH, self::LEVEL_ERROR);
			}
		}

		if (!$isApply && !$isReversal) $this->addCheck($checks, self::CODE_OPERATION_LINK_MISMATCH, self::LEVEL_ERROR);
		if (!$checks) $this->addCheck($checks, self::CODE_OK, self::LEVEL_OK);

		$level = self::LEVEL_OK;
		foreach ($checks as $check) {
			if ($check['level'] === self::LEVEL_ERROR) { $level = self::LEVEL_ERROR; break; }
			if ($check['level'] === self::LEVEL_WARNING) $level = self::LEVEL_WARNING;
		}
		return array('level' => $level, 'codes' => array_values(array_unique(array_column($checks, 'code'))), 'checks' => $checks);
	}

	/** @return string[] */
	public static function levels(): array
	{
		return array(self::LEVEL_OK, self::LEVEL_WARNING, self::LEVEL_ERROR);
	}

	/** @return string[] */
	public static function codes(): array
	{
		return array(
			self::CODE_OK, self::CODE_VOUCHER_MISSING, self::CODE_OPERATION_MISSING, self::CODE_INVOICE_MISSING,
			self::CODE_PAYMENT_MISSING, self::CODE_ALLOCATION_MISSING, self::CODE_AMOUNT_MISMATCH, self::CODE_ENTITY_MISMATCH,
			self::CODE_UNEXPECTED_BANK_LINE, self::CODE_PAYMENT_MODE_MISMATCH, self::CODE_REVERSAL_MISSING,
			self::CODE_MULTIPLE_REVERSALS, self::CODE_ORPHAN_REVERSAL, self::CODE_INVALID_REQUEST_SOURCE,
			self::CODE_GENERIC_COMPENSATION, self::CODE_ALLOCATION_INVOICE_MISMATCH, self::CODE_OPERATION_LINK_MISMATCH,
			self::CODE_OPERATION_TYPE_MISMATCH, self::CODE_COMPENSATION_MISSING, self::CODE_PAYMENT_STILL_PRESENT,
			self::CODE_INVOICE_REFERENCE_CHANGED, self::CODE_PAYMENT_REFERENCE_CHANGED, self::CODE_PAYMENT_EXPORTED,
			self::CODE_PAYMENT_RECONCILED,
		);
	}

	public static function normalizeMaxRows(int $value): int
	{
		if ($value < 1) return self::MAX_ROWS_DEFAULT;
		return min($value, self::MAX_ROWS_HARD_LIMIT);
	}

	public static function neutralizeCsvValue(string $value): string
	{
		$value = str_replace(array("\r\n", "\r"), "\n", $value);
		return preg_match('/^[=+\-@]/', ltrim($value)) ? "'".$value : $value;
	}

	/** @param array<int,array{code:string,level:string,message_key:string}> $checks */
	private function addCheck(array &$checks, string $code, string $level): void
	{
		foreach ($checks as $check) if ($check['code'] === $code) return;
		$checks[] = array('code' => $code, 'level' => $level, 'message_key' => 'DiagnosticCode'.$code);
	}

	/** @param array<int,array{code:string,level:string,message_key:string}> $checks */
	private function checkObject(array &$checks, object $row, string $existsField, string $entityField, string $missingCode, int $entity): void
	{
		if (empty($row->{$existsField})) {
			$this->addCheck($checks, $missingCode, self::LEVEL_ERROR);
		} elseif ((int) ($row->{$entityField} ?? 0) !== $entity) {
			$this->addCheck($checks, self::CODE_ENTITY_MISMATCH, self::LEVEL_ERROR);
		}
	}

	private function sameAmount($left, $right): bool
	{
		try {
			return DoliVoucherMoney::compare((string) $left, (string) $right) === 0;
		} catch (InvalidArgumentException $e) {
			return false;
		}
	}

	/** @param array<string,mixed> $filters */
	private function buildQuery(int $entity, array $filters, string $sortField, string $sortOrder, int $limit, int $offset): string
	{
		$sortMap = array(
			's.date_creation' => 's.date_creation', 's.settlement_uuid' => 's.settlement_uuid', 's.event_type' => 's.event_type',
			's.request_source' => 's.request_source', 'v.ref' => 'v.ref', 'f.ref' => 'f.ref', 'p.ref' => 'p.ref',
			'o.operation_uuid' => 'o.operation_uuid', 's.amount' => 's.amount', 'u.login' => 'u.login',
		);
		$orderBy = $sortMap[$sortField] ?? 's.date_creation';
		$sortOrder = strtoupper($sortOrder) === 'ASC' ? 'ASC' : 'DESC';
		$sql = 'SELECT s.*, v.rowid AS voucher_exists, v.entity AS voucher_entity, CASE WHEN v.entity=s.entity THEN v.ref ELSE NULL END AS voucher_ref, CASE WHEN v.entity=s.entity THEN v.barcode ELSE NULL END AS voucher_barcode';
		$sql .= ', o.rowid AS operation_exists, o.entity AS operation_entity, o.operation_uuid, o.operation_type, o.amount AS operation_amount, o.fk_voucher AS operation_voucher, o.object_type AS operation_object_type, o.fk_object AS operation_object_id, o.reversal_of AS operation_reversal_of, o.reason AS operation_reason, CAST(CASE WHEN o.operation_type=\'CONSUME\' THEN o.balance_before-o.balance_after ELSE o.balance_after-o.balance_before END AS DECIMAL(24,8)) AS operation_balance_delta';
		$sql .= ', f.rowid AS invoice_exists, f.entity AS invoice_entity, CASE WHEN f.entity=s.entity THEN f.ref ELSE NULL END AS invoice_ref';
		$sql .= ', p.rowid AS payment_exists, p.entity AS payment_entity, CASE WHEN p.entity=s.entity THEN p.ref ELSE NULL END AS payment_ref, p.fk_bank AS payment_bank_id, p.fk_export_compta AS payment_export_id, cp.code AS payment_mode_code, COALESCE(b.rappro,0) AS payment_reconciled';
		$sql .= ', a.allocation_count, a.allocation_amount, a.allocation_invoice_min, a.allocation_invoice_max';
		$sql .= ', ra.linked_reversal_count, rr.rowid AS linked_reversal_id, rr.entity AS linked_reversal_entity, rr.fk_operation AS linked_reversal_operation_id, rr.fk_voucher AS linked_reversal_voucher_id, rr.fk_facture AS linked_reversal_invoice_id, rr.amount AS linked_reversal_amount, ro.reason AS linked_reversal_reason, ro.operation_type AS linked_reversal_operation_type, ro.reversal_of AS linked_reversal_operation_reversal_of, ro.amount AS linked_reversal_operation_amount, CAST(ro.balance_after-ro.balance_before AS DECIMAL(24,8)) AS linked_reversal_balance_delta';
		$sql .= ', c.generic_compensation_count, c.generic_compensation_id';
		$sql .= ', ps.rowid AS parent_settlement_exists, ps.entity AS parent_entity, ps.event_type AS parent_event_type, ps.fk_operation AS parent_operation_id, ps.fk_voucher AS parent_voucher_id, ps.fk_facture AS parent_invoice_id, ps.amount AS parent_amount';
		$sql .= ', u.login AS user_login, u.firstname AS user_firstname, u.lastname AS user_lastname';
		$sql .= ' FROM '.$this->db->prefix().'dolivoucher_invoice_settlement s';
		$sql .= ' LEFT JOIN '.$this->db->prefix().'dolivoucher_voucher v ON v.rowid=s.fk_voucher';
		$sql .= ' LEFT JOIN '.$this->db->prefix().'dolivoucher_operation o ON o.rowid=s.fk_operation';
		$sql .= ' LEFT JOIN '.$this->db->prefix().'facture f ON f.rowid=s.fk_facture';
		$sql .= ' LEFT JOIN '.$this->db->prefix().'paiement p ON p.rowid=s.fk_paiement';
		$sql .= ' LEFT JOIN '.$this->db->prefix().'c_paiement cp ON cp.id=p.fk_paiement';
		$sql .= ' LEFT JOIN '.$this->db->prefix().'bank b ON b.rowid=p.fk_bank';
		$sql .= ' LEFT JOIN (SELECT pf.fk_paiement, COUNT(*) AS allocation_count, SUM(pf.amount) AS allocation_amount, MIN(pf.fk_facture) AS allocation_invoice_min, MAX(pf.fk_facture) AS allocation_invoice_max FROM '.$this->db->prefix().'paiement_facture pf INNER JOIN (SELECT DISTINCT fk_paiement FROM '.$this->db->prefix().'dolivoucher_invoice_settlement WHERE entity='.$entity.') sx ON sx.fk_paiement=pf.fk_paiement GROUP BY pf.fk_paiement) a ON a.fk_paiement=s.fk_paiement';
		$sql .= ' LEFT JOIN (SELECT reversal_of, COUNT(*) AS linked_reversal_count, MIN(rowid) AS linked_reversal_id FROM '.$this->db->prefix().'dolivoucher_invoice_settlement WHERE entity='.$entity.' AND reversal_of IS NOT NULL GROUP BY reversal_of) ra ON ra.reversal_of=s.rowid';
		$sql .= ' LEFT JOIN '.$this->db->prefix().'dolivoucher_invoice_settlement rr ON rr.rowid=ra.linked_reversal_id';
		$sql .= ' LEFT JOIN '.$this->db->prefix().'dolivoucher_operation ro ON ro.rowid=rr.fk_operation';
		$sql .= ' LEFT JOIN (SELECT co.reversal_of, COUNT(*) AS generic_compensation_count, MIN(co.rowid) AS generic_compensation_id FROM '.$this->db->prefix().'dolivoucher_operation co INNER JOIN '.$this->db->prefix().'dolivoucher_invoice_settlement sx ON sx.fk_operation=co.reversal_of AND sx.entity='.$entity.' WHERE co.reversal_of IS NOT NULL GROUP BY co.reversal_of) c ON c.reversal_of=s.fk_operation';
		$sql .= ' LEFT JOIN '.$this->db->prefix().'dolivoucher_invoice_settlement ps ON ps.rowid=s.reversal_of';
		$sql .= ' LEFT JOIN '.$this->db->prefix().'user u ON u.rowid=s.fk_user_creat';
		$sql .= ' WHERE s.entity='.$entity;
		$sql .= $this->filterSql($filters);
		$sql .= ' ORDER BY '.$orderBy.' '.$sortOrder.', s.rowid '.$sortOrder;
		return $sql.$this->db->plimit(max(1, $limit), max(0, $offset));
	}

	/** @param array<string,mixed> $filters */
	private function filterSql(array $filters): string
	{
		$sql = '';
		if (!empty($filters['settlement_id'])) $sql .= ' AND s.rowid='.(int) $filters['settlement_id'];
		if (!empty($filters['date_from'])) $sql .= " AND s.date_creation>='".$this->db->idate((int) $filters['date_from'])."'";
		if (!empty($filters['date_to'])) $sql .= " AND s.date_creation<='".$this->db->idate((int) $filters['date_to'])."'";
		foreach (array('settlement_uuid' => 's.settlement_uuid', 'idempotency_key' => 's.idempotency_key') as $key => $field) {
			if (!empty($filters[$key])) $sql .= " AND ".$field." LIKE '%".$this->db->escape((string) $filters[$key])."%'";
		}
		if (in_array((string) ($filters['event_type'] ?? ''), array('APPLY', 'REVERSAL'), true)) $sql .= " AND s.event_type='".$this->db->escape((string) $filters['event_type'])."'";
		if (in_array((string) ($filters['request_source'] ?? ''), array(self::SOURCE_INVOICE_CARD, self::SOURCE_TAKEPOS), true)) $sql .= " AND s.request_source='".$this->db->escape((string) $filters['request_source'])."'";
		if (($filters['state'] ?? '') === 'ACTIVE') $sql .= " AND s.event_type='APPLY' AND ra.linked_reversal_count IS NULL";
		if (($filters['state'] ?? '') === 'REVERSED') $sql .= " AND (s.event_type='REVERSAL' OR ra.linked_reversal_count>0)";
		if (!empty($filters['voucher'])) $sql .= " AND (v.ref LIKE '%".$this->db->escape((string) $filters['voucher'])."%' OR v.barcode LIKE '%".$this->db->escape((string) $filters['voucher'])."%')";
		if (!empty($filters['invoice'])) $sql .= " AND (f.ref LIKE '%".$this->db->escape((string) $filters['invoice'])."%' OR s.invoice_ref_snapshot LIKE '%".$this->db->escape((string) $filters['invoice'])."%')";
		if (!empty($filters['payment'])) {
			$value = (string) $filters['payment'];
			$sql .= " AND (p.ref LIKE '%".$this->db->escape($value)."%' OR s.payment_ref_snapshot LIKE '%".$this->db->escape($value)."%'".(ctype_digit($value) ? ' OR s.fk_paiement='.(int) $value : '').')';
		}
		if (!empty($filters['operation'])) {
			$value = (string) $filters['operation'];
			$sql .= " AND (o.operation_uuid LIKE '%".$this->db->escape($value)."%'".(ctype_digit($value) ? ' OR s.fk_operation='.(int) $value : '').')';
		}
		if (!empty($filters['amount'])) $sql .= natural_search('s.amount', (string) $filters['amount'], 1);
		return $sql;
	}
}
