<?php
/* Copyright (C) 2026 Fred Omega Junior */
declare(strict_types=1);

require_once DOL_DOCUMENT_ROOT.'/core/class/commonobject.class.php';

class DoliVoucherTakePosIntent extends CommonObject
{
	public const STATUS_DRAFT = 'DRAFT';
	public const STATUS_VALIDATION_REQUESTED = 'VALIDATION_REQUESTED';
	public const STATUS_INVOICE_VALIDATED = 'INVOICE_VALIDATED';
	public const STATUS_SETTLEMENT_REQUESTED = 'SETTLEMENT_REQUESTED';
	public const STATUS_SETTLED = 'SETTLED';
	public const STATUS_SETTLEMENT_FAILED = 'SETTLEMENT_FAILED';
	public const STATUS_CANCELLED = 'CANCELLED';
	public const STATUS_EXPIRED = 'EXPIRED';
	public $element = 'dolivouchertakeposintent';
	public $table_element = 'dolivoucher_takepos_intent';
	public $entity = 1;
	public string $intent_uuid = '';
	public string $idempotency_key = '';
	public $status = self::STATUS_DRAFT;

	public function fetchByUuid(string $uuid, int $entity): int
	{
		$sql = 'SELECT * FROM '.$this->db->prefix().$this->table_element.' WHERE entity='.(int) $entity." AND intent_uuid='".$this->db->escape($uuid)."'".$this->db->plimit(1, 0);
		$res = $this->db->query($sql);
		$row = $res ? $this->db->fetch_object($res) : false;
		if (!$row) return 0;
		foreach (get_object_vars($row) as $key => $value) $this->$key = $value;
		$this->id = (int) $row->rowid;
		return 1;
	}

	public static function canTransition(string $from, string $to): bool
	{
		$allowed = array(
			self::STATUS_DRAFT => array(self::STATUS_VALIDATION_REQUESTED, self::STATUS_INVOICE_VALIDATED, self::STATUS_CANCELLED, self::STATUS_EXPIRED),
			self::STATUS_VALIDATION_REQUESTED => array(self::STATUS_INVOICE_VALIDATED, self::STATUS_SETTLEMENT_FAILED, self::STATUS_CANCELLED, self::STATUS_EXPIRED),
			self::STATUS_INVOICE_VALIDATED => array(self::STATUS_SETTLEMENT_REQUESTED, self::STATUS_CANCELLED, self::STATUS_EXPIRED),
			self::STATUS_SETTLEMENT_REQUESTED => array(self::STATUS_SETTLED, self::STATUS_SETTLEMENT_FAILED),
			self::STATUS_SETTLEMENT_FAILED => array(self::STATUS_SETTLEMENT_REQUESTED, self::STATUS_CANCELLED, self::STATUS_EXPIRED),
		);
		return in_array($to, $allowed[$from] ?? array(), true);
	}

	public function delete(User $user, int $notrigger = 0): int
	{
		$this->error = 'ErrorTakePosIntentDeleteForbidden';
		return -1;
	}

	public function deleteCommon(User $user, $notrigger = 0, $forcechilddeletion = 0)
	{
		$this->error = 'ErrorTakePosIntentDeleteForbidden';
		return -1;
	}
}
