<?php
/* Copyright (C) 2026 Fred Omega Junior */

declare(strict_types=1);

require_once __DIR__.'/dolivouchermoney.class.php';
require_once __DIR__.'/dolivoucherportfolio.class.php';
require_once __DIR__.'/dolivouchervoucher.class.php';
require_once __DIR__.'/dolivoucherseries.class.php';
require_once __DIR__.'/dolivoucherseriesevent.class.php';
require_once __DIR__.'/dolivoucherservice.class.php';
require_once __DIR__.'/../core/modules/dolivoucher/mod_dolivoucherseries_standard.php';
require_once __DIR__.'/../core/modules/dolivoucher/doc/pdf_dolivoucher_a4.modules.php';

/** Transactional service for non-financial series and physical-support events. */
class DoliVoucherSeriesService
{
	private DoliDB $db;

	public function __construct(DoliDB $db)
	{
		$this->db = $db;
	}

	/**
	 * Create one immutable series and all its draft vouchers atomically.
	 * Replaying the same generation key returns the already-created series.
	 *
	 * @param array<string,mixed> $data
	 */
	public function generate(array $data, int $entity, User $user): int
	{
		$generationKey = trim((string) ($data['generation_key'] ?? ''));
		if (!preg_match('/^[a-zA-Z0-9-]{16,64}$/', $generationKey)) return $this->fail(new DomainException('ErrorInvalidGenerationKey'));
		$existing = $this->findByGenerationKey($generationKey, $entity);
		if ($existing > 0) return $existing;

		$this->db->begin();
		try {
			$quantity = (int) ($data['quantity'] ?? 0);
			$maximum = $this->maxVouchersPerSeries();
			if ($quantity < 1 || $quantity > $maximum || $quantity > 999999) throw new DomainException('ErrorSeriesQuantityLimit');
			$faceValue = DoliVoucherMoney::normalize((string) ($data['face_value'] ?? ''), true);
			$portfolioId = (int) ($data['fk_portfolio'] ?? 0);
			$portfolio = $this->one('SELECT rowid, label, status FROM '.$this->db->prefix().'dolivoucher_portfolio WHERE rowid='.$portfolioId.' AND entity='.$entity.' FOR UPDATE');
			if (!in_array((int) $portfolio->status, array(DoliVoucherPortfolio::STATUS_VALIDATED, DoliVoucherPortfolio::STATUS_ACTIVE), true)) throw new DomainException('ErrorPortfolioNotEligible');
			$year = (int) dol_print_date(dol_now(), '%Y');
			$sequence = $this->reserveCounter($entity, 'SERIES', $year, 1);
			$model = new mod_dolivoucherseries_standard();
			$ref = $model->formatValue($year, $sequence);
			$expiration = !empty($data['date_expiration']) ? "'".$this->db->idate((int) $data['date_expiration'])."'" : 'NULL';
			$now = $this->db->idate(dol_now());
			$sql = 'INSERT INTO '.$this->db->prefix().'dolivoucher_series (entity, ref, generation_key, sequence_year, fk_portfolio, label, quantity, face_value, date_expiration, status, generated_count, printed_count, prepared_count, delivered_count, date_generation, date_creation, fk_user_creat) VALUES (';
			$sql .= $entity.", '".$this->db->escape($ref)."', '".$this->db->escape($generationKey)."', ".$year.', '.$portfolioId.", '".$this->db->escape((string) ($data['label'] ?? ''))."', ".$quantity.', '.$this->decimal($faceValue).', '.$expiration.', '.DoliVoucherSeries::STATUS_GENERATED.', '.$quantity.", 0, 0, 0, '".$now."', '".$now."', ".(int) $user->id.')';
			$this->mustQuery($sql, 'ErrorSeriesCreationFailed');
			$seriesId = (int) $this->db->last_insert_id($this->db->prefix().'dolivoucher_series');
			$this->insertVouchers($seriesId, $ref, $portfolioId, $entity, $quantity, $faceValue, $data['date_expiration'] ?? null, $user);
			$vouchers = $this->selectedVouchers($seriesId, $entity, array(), $this->maxVouchersPerSeries());
			$eventId = $this->appendEvent($seriesId, $entity, DoliVoucherSeriesEvent::TYPE_GENERATE, null, 'ALL', '1-'.$quantity, '', '', '', $quantity, '', '', $user);
			$this->appendEventLines($eventId, $seriesId, $entity, DoliVoucherSeriesEvent::TYPE_GENERATE, array_column($vouchers, 'rowid'));
			$this->db->commit();
			return $seriesId;
		} catch (Throwable $e) {
			$this->db->rollback();
			$existing = $this->findByGenerationKey($generationKey, $entity);
			return $existing > 0 ? $existing : $this->fail($e);
		}
	}

	/**
	 * Generate an immutable PDF and append its audit event only after the file exists.
	 *
	 * @param int[] $voucherIds Empty means the whole series.
	 * @return array{event_id:int,file:string,type:string}|array{}
	 */
	public function printSelection(int $seriesId, int $entity, array $voucherIds, string $selectionType, string $reason, User $user, Translate $langs, bool $allowReprint = false): array
	{
		global $conf, $mysoc;
		$file = '';
		$this->db->begin();
		try {
			$series = $this->lockSeries($seriesId, $entity);
			if ((int) $series->generated_count < 1 || (int) $series->status === DoliVoucherSeries::STATUS_CANCELED) throw new DomainException('ErrorSeriesNotPrintable');
			$vouchers = $this->selectedVouchers($seriesId, $entity, $voucherIds);
			if (!$vouchers || count($vouchers) > $this->maxPdfVouchers()) throw new DomainException('ErrorEmptyOrOversizedSelection');
			$selectedIds = array_map('intval', array_column($vouchers, 'rowid'));
			$already = $this->printedVoucherIds($seriesId, $entity, $selectedIds);
			if ($already && count($already) !== count($selectedIds)) throw new DomainException('ErrorMixedPrintSelection');
			$isReprint = count($already) === count($selectedIds);
			if ($isReprint && !$allowReprint) throw new DomainException('ErrorReprintPermissionDenied');
			$reason = $this->cleanReason($reason);
			if ($isReprint && $reason === '') throw new DomainException('ErrorReprintReasonRequired');
			$revision = (int) $this->scalar('SELECT COALESCE(MAX(revision),0)+1 FROM '.$this->db->prefix().'dolivoucher_series_event WHERE entity='.$entity.' AND fk_series='.$seriesId.' AND revision IS NOT NULL');
			$type = $isReprint ? DoliVoucherSeriesEvent::TYPE_REPRINT : ((int) $series->printed_count > 0 ? DoliVoucherSeriesEvent::TYPE_PRINT_COMPLEMENT : DoliVoucherSeriesEvent::TYPE_PRINT);
			$portfolio = $this->one('SELECT label, date_start, date_end FROM '.$this->db->prefix().'dolivoucher_portfolio WHERE rowid='.(int) $series->fk_portfolio.' AND entity='.$entity.' LIMIT 1');
			foreach ($vouchers as $voucher) if (!empty($voucher->date_expiration)) $voucher->date_expiration = $this->db->jdate($voucher->date_expiration);
			$token = str_replace('-', '', DoliVoucherService::uuid());
			$name = dol_sanitizeFileName($series->ref.'_R'.sprintf('%03d', $revision).'_'.$token).'.pdf';
			$baseDir = $conf->dolivoucher->multidir_output[$entity] ?? $conf->dolivoucher->dir_output;
			$dir = rtrim((string) $baseDir, '/\\').'/series/'.dol_sanitizeFileName((string) $series->ref);
			$file = $dir.'/'.$name;
			$pdf = new pdf_dolivoucher_a4();
			$currency = getDolGlobalString('MAIN_MONNAIE', 'XOF');
			$dateStart = empty($portfolio->date_start) ? null : $this->db->jdate($portfolio->date_start);
			$dateEnd = empty($portfolio->date_end) ? null : $this->db->jdate($portfolio->date_end);
			if ($pdf->write_file($series, $vouchers, $langs, $file, (string) $mysoc->name, (string) $portfolio->label, $currency, $revision, $dateStart, $dateEnd) <= 0) throw new RuntimeException($pdf->error ?: 'ErrorPdfGenerationFailed');
			$hash = hash_file('sha256', $file);
			if (!is_string($hash) || strlen($hash) !== 64) throw new RuntimeException('ErrorDocumentHashFailed');
			$summary = $this->selectionSummary($vouchers, $selectionType);
			$relativePath = 'series/'.dol_sanitizeFileName((string) $series->ref).'/'.$name;
			$eventId = $this->appendEvent($seriesId, $entity, $type, $revision, $selectionType, $summary, $name, $relativePath, $hash, count($vouchers), $reason, '', $user);
			$this->appendEventLines($eventId, $seriesId, $entity, $type, $selectedIds);
			$this->refreshCoverage($seriesId, $entity, 'PRINT', $user);
			$this->db->commit();
			return array('event_id' => $eventId, 'file' => $file, 'type' => $type);
		} catch (Throwable $e) {
			$this->db->rollback();
			if ($file !== '' && is_file($file)) @unlink($file);
			$this->fail($e);
			return array();
		}
	}

	public function prepareAll(int $seriesId, int $entity, string $note, User $user): int
	{
		return $this->materialTransition($seriesId, $entity, DoliVoucherSeriesEvent::TYPE_PREPARE, $note, $user);
	}

	public function deliverAll(int $seriesId, int $entity, string $note, User $user): int
	{
		return $this->materialTransition($seriesId, $entity, DoliVoucherSeriesEvent::TYPE_DELIVER, $note, $user);
	}

	private function materialTransition(int $seriesId, int $entity, string $type, string $note, User $user): int
	{
		$this->db->begin();
		try {
			$series = $this->lockSeries($seriesId, $entity);
			if ((int) $series->generated_count < 1 || (int) $series->status === DoliVoucherSeries::STATUS_CANCELED) throw new DomainException('ErrorInvalidStatusTransition');
			if ($type === DoliVoucherSeriesEvent::TYPE_PREPARE && (int) $series->printed_count !== (int) $series->generated_count) throw new DomainException('ErrorSeriesNotFullyPrinted');
			if ($type === DoliVoucherSeriesEvent::TYPE_DELIVER && (int) $series->prepared_count !== (int) $series->generated_count) throw new DomainException('ErrorSeriesNotFullyPrepared');
			if ($type === DoliVoucherSeriesEvent::TYPE_PREPARE && (int) $series->prepared_count >= (int) $series->generated_count) throw new DomainException('ErrorSeriesAlreadyPrepared');
			if ($type === DoliVoucherSeriesEvent::TYPE_DELIVER && (int) $series->delivered_count >= (int) $series->generated_count) throw new DomainException('ErrorSeriesAlreadyDelivered');
			$vouchers = $this->selectedVouchers($seriesId, $entity, array(), $this->maxVouchersPerSeries());
			$ids = array_map('intval', array_column($vouchers, 'rowid'));
			$eventId = $this->appendEvent($seriesId, $entity, $type, null, 'ALL', '1-'.count($ids), '', '', '', count($ids), '', $this->cleanNote($note), $user);
			$this->appendEventLines($eventId, $seriesId, $entity, $type, $ids);
			$this->refreshCoverage($seriesId, $entity, $type, $user);
			$this->db->commit();
			return $eventId;
		} catch (Throwable $e) {
			$this->db->rollback();
			return $this->fail($e);
		}
	}

	private function reserveCounter(int $entity, string $type, int $year, int $size): int
	{
		$sql = 'INSERT IGNORE INTO '.$this->db->prefix()."dolivoucher_sequence (entity, sequence_type, sequence_year, next_value) VALUES (".$entity.", '".$this->db->escape($type)."', ".$year.', 1)';
		$this->mustQuery($sql, 'ErrorSequenceInitializationFailed');
		$row = $this->one('SELECT rowid, next_value FROM '.$this->db->prefix()."dolivoucher_sequence WHERE entity=".$entity." AND sequence_type='".$this->db->escape($type)."' AND sequence_year=".$year.' FOR UPDATE');
		$start = (int) $row->next_value;
		if ($start < 1 || $start + $size - 1 > 999999) throw new DomainException('ErrorSequenceExhausted');
		$this->mustQuery('UPDATE '.$this->db->prefix().'dolivoucher_sequence SET next_value='.($start + $size).' WHERE rowid='.(int) $row->rowid, 'ErrorSequenceUpdateFailed');
		return $start;
	}

	private function insertVouchers(int $seriesId, string $seriesRef, int $portfolioId, int $entity, int $quantity, string $amount, $expiration, User $user): void
	{
		$now = $this->db->idate(dol_now());
		$expirationSql = !empty($expiration) ? "'".$this->db->idate((int) $expiration)."'" : 'NULL';
		for ($offset = 1; $offset <= $quantity; $offset += 250) {
			$values = array();
			$end = min($quantity, $offset + 249);
			for ($number = $offset; $number <= $end; $number++) {
				$ref = $seriesRef.'-'.sprintf('%06d', $number);
				$values[] = '('.$entity.', '.$portfolioId.', '.$seriesId.", '".$this->db->escape($ref)."', '".$this->db->escape($ref)."', ".$this->decimal($amount).', 0, '.DoliVoucherVoucher::STATUS_DRAFT.', '.$expirationSql.", '".$now."', ".(int) $user->id.')';
			}
			$sql = 'INSERT INTO '.$this->db->prefix().'dolivoucher_voucher (entity, fk_portfolio, fk_series, ref, barcode, initial_amount, current_balance, status, date_expiration, date_creation, fk_user_creat) VALUES '.implode(', ', $values);
			$this->mustQuery($sql, 'ErrorSeriesVoucherCreationFailed');
		}
		// ISSUE is an administrative, non-financial event retained for compatibility with unit creation.
		$rows = $this->selectedVouchers($seriesId, $entity, array(), $this->maxVouchersPerSeries());
		for ($offset = 0, $count = count($rows); $offset < $count; $offset += 250) {
			$values = array();
			foreach (array_slice($rows, $offset, 250) as $voucher) {
				$values[] = '('.$entity.", '".$this->db->escape(DoliVoucherService::uuid())."', ".$portfolioId.', '.(int) $voucher->rowid.", 'ISSUE', ".$this->decimal($amount).", NULL, NULL, '', '".$this->db->escape('Series '.$seriesRef)."', '".$now."', '".$now."', ".(int) $user->id.')';
			}
			$sql = 'INSERT INTO '.$this->db->prefix().'dolivoucher_operation (entity, operation_uuid, fk_portfolio, fk_voucher, operation_type, amount, balance_before, balance_after, external_ref, reason, date_operation, date_creation, fk_user_creat) VALUES '.implode(', ', $values);
			$this->mustQuery($sql, 'ErrorJournalAppendFailed');
		}
	}

	/** @param int[] $ids @return array<int,object> */
	private function selectedVouchers(int $seriesId, int $entity, array $ids, ?int $maximum = null): array
	{
		$ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
		$sql = 'SELECT rowid, ref, barcode, initial_amount, date_expiration FROM '.$this->db->prefix().'dolivoucher_voucher WHERE entity='.$entity.' AND fk_series='.$seriesId;
		if ($ids) $sql .= ' AND rowid IN ('.implode(',', $ids).')';
		$sql .= ' ORDER BY ref ASC LIMIT '.(($maximum ?? $this->maxPdfVouchers()) + 1);
		$resql = $this->db->query($sql);
		if (!$resql) throw new RuntimeException('ErrorVoucherSelectionFailed');
		$result = array();
		while ($row = $this->db->fetch_object($resql)) $result[] = $row;
		if ($ids && count($result) !== count($ids)) throw new DomainException('ErrorInvalidVoucherSelection');
		return $result;
	}

	/** @param int[] $ids @return int[] */
	private function printedVoucherIds(int $seriesId, int $entity, array $ids): array
	{
		$sql = 'SELECT DISTINCT fk_voucher FROM '.$this->db->prefix().'dolivoucher_series_event_voucher WHERE entity='.$entity.' AND fk_series='.$seriesId;
		$sql .= " AND event_type IN ('PRINT','PRINT_COMPLEMENT','REPRINT') AND fk_voucher IN (".implode(',', $ids).')';
		$resql = $this->db->query($sql);
		if (!$resql) throw new RuntimeException('ErrorPrintCoverageReadFailed');
		$result = array();
		while ($row = $this->db->fetch_object($resql)) $result[] = (int) $row->fk_voucher;
		return $result;
	}

	private function refreshCoverage(int $seriesId, int $entity, string $type, User $user): void
	{
		if ($type === 'PRINT') {
			$types = "'PRINT','PRINT_COMPLEMENT','REPRINT'";
			$field = 'printed_count';
			$dateField = 'date_printed';
			$status = DoliVoucherSeries::STATUS_PRINTED;
		} elseif ($type === DoliVoucherSeriesEvent::TYPE_PREPARE) {
			$types = "'PREPARE'";
			$field = 'prepared_count';
			$dateField = 'date_prepared';
			$status = DoliVoucherSeries::STATUS_PREPARED;
		} else {
			$types = "'DELIVER'";
			$field = 'delivered_count';
			$dateField = 'date_delivered';
			$status = DoliVoucherSeries::STATUS_DELIVERED;
		}
		$count = (int) $this->scalar('SELECT COUNT(DISTINCT fk_voucher) FROM '.$this->db->prefix().'dolivoucher_series_event_voucher WHERE entity='.$entity.' AND fk_series='.$seriesId.' AND event_type IN ('.$types.')');
		$sql = 'UPDATE '.$this->db->prefix().'dolivoucher_series SET '.$field.'='.$count.', fk_user_modif='.(int) $user->id;
		$sql .= ', '.$dateField.'=CASE WHEN '.$count.'>=generated_count THEN COALESCE('.$dateField.", '".$this->db->idate(dol_now())."') ELSE ".$dateField.' END';
		$sql .= ', status=CASE WHEN '.$count.'>=generated_count AND status<'.$status.' THEN '.$status.' ELSE status END WHERE rowid='.$seriesId.' AND entity='.$entity;
		$this->mustQuery($sql, 'ErrorSeriesCoverageUpdateFailed');
	}

	private function appendEvent(int $seriesId, int $entity, string $type, ?int $revision, string $selectionType, string $summary, string $name, string $path, string $hash, int $count, string $reason, string $note, User $user): int
	{
		$now = $this->db->idate(dol_now());
		$sql = 'INSERT INTO '.$this->db->prefix().'dolivoucher_series_event (entity, fk_series, event_uuid, event_type, revision, selection_type, selection_summary, document_name, document_path, document_sha256, voucher_count, reason, note_private, date_event, date_creation, fk_user_creat) VALUES (';
		$sql .= $entity.', '.$seriesId.", '".$this->db->escape(DoliVoucherService::uuid())."', '".$this->db->escape($type)."', ".($revision === null ? 'NULL' : $revision).", '".$this->db->escape($selectionType)."', '".$this->db->escape($summary)."', '".$this->db->escape($name)."', '".$this->db->escape($path)."', ".($hash === '' ? 'NULL' : "'".$this->db->escape($hash)."'").', '.$count.", '".$this->db->escape($reason)."', '".$this->db->escape($note)."', '".$now."', '".$now."', ".(int) $user->id.')';
		$this->mustQuery($sql, 'ErrorMaterialJournalAppendFailed');
		return (int) $this->db->last_insert_id($this->db->prefix().'dolivoucher_series_event');
	}

	/** @param int[] $voucherIds */
	private function appendEventLines(int $eventId, int $seriesId, int $entity, string $type, array $voucherIds): void
	{
		$now = $this->db->idate(dol_now());
		foreach (array_chunk($voucherIds, 250) as $chunk) {
			$values = array();
			foreach ($chunk as $voucherId) $values[] = '('.$entity.', '.$eventId.', '.$seriesId.', '.(int) $voucherId.", '".$this->db->escape($type)."', '".$now."')";
			$this->mustQuery('INSERT INTO '.$this->db->prefix().'dolivoucher_series_event_voucher (entity, fk_event, fk_series, fk_voucher, event_type, date_creation) VALUES '.implode(', ', $values), 'ErrorMaterialJournalAppendFailed');
		}
	}

	/** @param array<int,object> $vouchers */
	private function selectionSummary(array $vouchers, string $selectionType): string
	{
		$refs = array_map(static fn ($voucher): string => (string) $voucher->ref, $vouchers);
		if ($selectionType === 'ALL') return 'ALL';
		if ($selectionType === 'RANGE') return reset($refs).'..'.end($refs);
		$summary = implode(',', $refs);
		return strlen($summary) <= 255 ? $summary : count($refs).' vouchers: '.reset($refs).'..'.end($refs);
	}

	private function findByGenerationKey(string $key, int $entity): int
	{
		$sql = 'SELECT rowid FROM '.$this->db->prefix()."dolivoucher_series WHERE entity=".$entity." AND generation_key='".$this->db->escape($key)."' LIMIT 1";
		$resql = $this->db->query($sql);
		$row = $resql ? $this->db->fetch_object($resql) : false;
		return $row ? (int) $row->rowid : 0;
	}

	private function lockSeries(int $id, int $entity): object
	{
		return $this->one('SELECT * FROM '.$this->db->prefix().'dolivoucher_series WHERE rowid='.$id.' AND entity='.$entity.' FOR UPDATE');
	}

	private function maxVouchersPerSeries(): int
	{
		return max(1, min(999999, getDolGlobalInt('DOLIVOUCHER_MAX_VOUCHERS_PER_SERIES', 1000)));
	}

	private function maxPdfVouchers(): int
	{
		return max(1, min($this->maxVouchersPerSeries(), getDolGlobalInt('DOLIVOUCHER_MAX_VOUCHERS_PER_PDF', 1000)));
	}

	private function cleanReason(string $reason): string
	{
		$reason = trim(strip_tags($reason));
		if (mb_strlen($reason) > 500) throw new DomainException('ErrorReasonTooLong');
		return $reason;
	}

	private function cleanNote(string $note): string
	{
		$note = trim(strip_tags($note));
		if (mb_strlen($note) > 2000) throw new DomainException('ErrorNoteTooLong');
		return $note;
	}

	private function decimal(string $amount): string
	{
		return "CAST('".$this->db->escape(DoliVoucherMoney::normalize($amount))."' AS DECIMAL(24,8))";
	}

	private function one(string $sql): object
	{
		$resql = $this->db->query($sql);
		$row = $resql ? $this->db->fetch_object($resql) : false;
		if (!$row) throw new RuntimeException('ErrorRecordNotFound');
		return $row;
	}

	private function scalar(string $sql): string
	{
		$resql = $this->db->query($sql);
		$row = $resql ? $this->db->fetch_row($resql) : false;
		if (!$row) throw new RuntimeException('ErrorRecordNotFound');
		return (string) $row[0];
	}

	private function mustQuery(string $sql, string $error): void
	{
		if (!$this->db->query($sql)) throw new RuntimeException($error);
	}

	private function fail(Throwable $e): int
	{
		global $langs;
		$message = $e->getMessage();
		setEventMessages(is_object($langs) ? $langs->trans($message) : $message, null, 'errors');
		dol_syslog(__METHOD__.': '.$message, LOG_WARNING);
		return -1;
	}
}
