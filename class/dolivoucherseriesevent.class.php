<?php
/* Copyright (C) 2026 Fred Omega Junior */

declare(strict_types=1);

require_once DOL_DOCUMENT_ROOT.'/core/class/commonobject.class.php';

/** Read-only representation of the append-only material audit journal. */
class DoliVoucherSeriesEvent extends CommonObject
{
	public const TYPE_GENERATE = 'GENERATE';
	public const TYPE_PRINT = 'PRINT';
	public const TYPE_PRINT_COMPLEMENT = 'PRINT_COMPLEMENT';
	public const TYPE_REPRINT = 'REPRINT';
	public const TYPE_PREPARE = 'PREPARE';
	public const TYPE_DELIVER = 'DELIVER';
	public const TYPE_NOTE = 'NOTE';

	public $module = 'dolivoucher';
	public $element = 'dolivoucher_series_event';
	public $table_element = 'dolivoucher_series_event';
	public $ismultientitymanaged = 1;
	public $isextrafieldmanaged = 0;

	public $fields = array(
		'rowid' => array('type' => 'integer', 'label' => 'Id', 'notnull' => 1, 'visible' => -2),
		'entity' => array('type' => 'integer', 'label' => 'Entity', 'notnull' => 1, 'visible' => -2),
		'fk_series' => array('type' => 'integer', 'label' => 'Series', 'notnull' => 1, 'visible' => 1),
		'event_uuid' => array('type' => 'varchar(36)', 'label' => 'EventUuid', 'notnull' => 1, 'visible' => 1),
		'event_type' => array('type' => 'varchar(32)', 'label' => 'EventType', 'notnull' => 1, 'visible' => 1),
		'revision' => array('type' => 'integer', 'label' => 'Revision', 'visible' => 1),
		'selection_type' => array('type' => 'varchar(16)', 'label' => 'SelectionType', 'visible' => 1),
		'selection_summary' => array('type' => 'varchar(255)', 'label' => 'Selection', 'visible' => 1),
		'document_name' => array('type' => 'varchar(255)', 'label' => 'Document', 'visible' => 1),
		'document_path' => array('type' => 'varchar(512)', 'label' => 'DocumentPath', 'visible' => -1),
		'document_sha256' => array('type' => 'varchar(64)', 'label' => 'Sha256', 'visible' => 1),
		'voucher_count' => array('type' => 'integer', 'label' => 'VoucherCount', 'notnull' => 1, 'visible' => 1),
		'reason' => array('type' => 'varchar(500)', 'label' => 'Reason', 'visible' => 1),
		'note_private' => array('type' => 'text', 'label' => 'NotePrivate', 'visible' => 1),
		'date_event' => array('type' => 'datetime', 'label' => 'EventDate', 'notnull' => 1, 'visible' => 1),
		'date_creation' => array('type' => 'datetime', 'label' => 'DateCreation', 'notnull' => 1, 'visible' => -2),
		'fk_user_creat' => array('type' => 'integer', 'label' => 'UserAuthor', 'notnull' => 1, 'visible' => 1),
		'reversal_of' => array('type' => 'integer', 'label' => 'ReversalOf', 'visible' => 1),
	);

	public function __construct(DoliDB $db)
	{
		$this->db = $db;
	}

	public function fetch($id, $ref = null, $noextrafields = 1)
	{
		global $conf;
		$result = $this->fetchCommon($id, $ref, '', $noextrafields);
		if ($result > 0 && (int) $this->entity !== (int) $conf->entity) return 0;
		return $result;
	}

	public function create(User $user, $notrigger = 0) { $this->error = 'ErrorMaterialJournalReadOnly'; return -1; }
	public function update(User $user, $notrigger = 0) { $this->error = 'ErrorMaterialJournalReadOnly'; return -1; }
	public function delete(User $user, $notrigger = 0) { $this->error = 'ErrorMaterialJournalReadOnly'; return -1; }
	public function updateCommon(User $user, $notrigger = 0) { $this->error = 'ErrorMaterialJournalReadOnly'; return -1; }
	public function deleteCommon(User $user, $notrigger = 0, $forcechilddeletion = 0) { $this->error = 'ErrorMaterialJournalReadOnly'; return -1; }
}
