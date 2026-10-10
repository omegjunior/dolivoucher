<?php
/* Copyright (C) 2026 Fred Omega Junior */

declare(strict_types=1);

require_once DOL_DOCUMENT_ROOT.'/core/class/commonobject.class.php';

/** Read-only representation of an append-only invoice settlement event. */
class DoliVoucherInvoiceSettlement extends CommonObject
{
	public const EVENT_APPLY = 'APPLY';
	public const EVENT_REVERSAL = 'REVERSAL';
	public const SOURCE_INVOICE_CARD = 'INVOICE_CARD';
	/** Reserved for the future TakePOS adapter; Phase 3B does not write this source. */
	public const SOURCE_TAKEPOS = 'TAKEPOS';

	public $module = 'dolivoucher';
	public $element = 'dolivoucher_invoice_settlement';
	public $table_element = 'dolivoucher_invoice_settlement';
	public $ismultientitymanaged = 1;
	public $isextrafieldmanaged = 0;
	public $fields = array(
		'rowid' => array('type' => 'integer', 'label' => 'Id', 'notnull' => 1),
		'entity' => array('type' => 'integer', 'label' => 'Entity', 'notnull' => 1),
		'settlement_uuid' => array('type' => 'varchar(36)', 'label' => 'SettlementUuid', 'notnull' => 1),
		'idempotency_key' => array('type' => 'varchar(128)', 'label' => 'IdempotencyKey', 'notnull' => 1),
		'event_type' => array('type' => 'varchar(16)', 'label' => 'SettlementEventType', 'notnull' => 1),
		'fk_voucher' => array('type' => 'integer', 'label' => 'Voucher', 'notnull' => 1),
		'fk_operation' => array('type' => 'integer', 'label' => 'Operation', 'notnull' => 1),
		'fk_facture' => array('type' => 'integer', 'label' => 'Invoice', 'notnull' => 1),
		'fk_paiement' => array('type' => 'integer', 'label' => 'Payment', 'notnull' => 1),
		'amount' => array('type' => 'price', 'label' => 'Amount', 'notnull' => 1),
		'reversal_of' => array('type' => 'integer', 'label' => 'ReversalOf'),
		'invoice_ref_snapshot' => array('type' => 'varchar(30)', 'label' => 'InvoiceRef', 'notnull' => 1),
		'payment_ref_snapshot' => array('type' => 'varchar(30)', 'label' => 'PaymentRef'),
		'request_source' => array('type' => 'varchar(32)', 'label' => 'RequestSource', 'notnull' => 1),
		'date_creation' => array('type' => 'datetime', 'label' => 'DateCreation', 'notnull' => 1),
		'fk_user_creat' => array('type' => 'integer', 'label' => 'UserAuthor', 'notnull' => 1),
		'external_ref' => array('type' => 'varchar(128)', 'label' => 'ExternalRef'),
	);

	public function __construct(DoliDB $db)
	{
		$this->db = $db;
	}

	public function fetch($id, $ref = null, $noextrafields = 1)
	{
		global $conf;
		$result = $this->fetchCommon($id, $ref, '', $noextrafields);
		return $result > 0 && (int) $this->entity !== (int) $conf->entity ? 0 : $result;
	}

	public function create(User $user, $notrigger = 0) { $this->error = 'ErrorAppendOnlySettlement'; return -1; }
	public function update(User $user, $notrigger = 0) { $this->error = 'ErrorAppendOnlySettlement'; return -1; }
	public function delete(User $user, $notrigger = 0) { $this->error = 'ErrorAppendOnlySettlement'; return -1; }
	public function updateCommon(User $user, $notrigger = 0) { $this->error = 'ErrorAppendOnlySettlement'; return -1; }
	public function deleteCommon(User $user, $notrigger = 0, $forcechilddeletion = 0) { $this->error = 'ErrorAppendOnlySettlement'; return -1; }
	public function setValueFrom($field, $value, $table = '', $id = null, $format = '', $id_field = '', $fuser = null, $trigkey = '', $fk_user_field = 'fk_user_modif') { $this->error = 'ErrorAppendOnlySettlement'; return -1; }
	public function update_ref_ext($ref_ext) { $this->error = 'ErrorAppendOnlySettlement'; return -1; }
	public function update_note($note, $suffix = '', $notrigger = 0) { $this->error = 'ErrorAppendOnlySettlement'; return -1; }
	public function setStatut($status, $elementId = null, $elementType = '', $trigkey = '', $fieldstatus = 'fk_statut') { $this->error = 'ErrorAppendOnlySettlement'; return -1; }
	public function setStatusCommon($user, $status, $notrigger = 0, $triggercode = '') { $this->error = 'ErrorAppendOnlySettlement'; return -1; }
}
