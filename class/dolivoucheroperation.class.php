<?php
/* Copyright (C) 2026 Fred Omega Junior */

declare(strict_types=1);

require_once DOL_DOCUMENT_ROOT.'/core/class/commonobject.class.php';

/** Read-only representation of an append-only audit entry. */
class DoliVoucherOperation extends CommonObject
{
	public const TYPE_FUND_NEW = 'FUND_NEW';
	public const TYPE_CARRYOVER_IN = 'CARRYOVER_IN';
	public const TYPE_ISSUE = 'ISSUE';
	public const TYPE_ACTIVATE = 'ACTIVATE';
	public const TYPE_CONSUME = 'CONSUME';
	public const TYPE_BLOCK = 'BLOCK';
	public const TYPE_UNBLOCK = 'UNBLOCK';
	public const TYPE_CANCEL = 'CANCEL';
	public const TYPE_EXPIRE = 'EXPIRE';
	public const TYPE_TRANSFER_OUT = 'TRANSFER_OUT';
	public const TYPE_TRANSFER_IN = 'TRANSFER_IN';
	public const TYPE_CORRECTION = 'CORRECTION';

	public $module = 'dolivoucher';
	public $element = 'dolivoucher_operation';
	public $table_element = 'dolivoucher_operation';
	public $ismultientitymanaged = 1;
	public $isextrafieldmanaged = 0;
	public $fields = array(
		'rowid' => array('type' => 'integer', 'label' => 'Id', 'notnull' => 1),
		'entity' => array('type' => 'integer', 'label' => 'Entity', 'notnull' => 1),
		'operation_uuid' => array('type' => 'varchar(36)', 'label' => 'OperationUuid', 'notnull' => 1),
		'fk_portfolio' => array('type' => 'integer', 'label' => 'Portfolio', 'notnull' => 1),
		'fk_voucher' => array('type' => 'integer', 'label' => 'Voucher'),
		'operation_type' => array('type' => 'varchar(32)', 'label' => 'OperationType', 'notnull' => 1),
		'amount' => array('type' => 'price', 'label' => 'Amount', 'notnull' => 1),
		'balance_before' => array('type' => 'price', 'label' => 'BalanceBefore'),
		'balance_after' => array('type' => 'price', 'label' => 'BalanceAfter'),
		'source_portfolio_id' => array('type' => 'integer', 'label' => 'SourcePortfolio'),
		'destination_portfolio_id' => array('type' => 'integer', 'label' => 'DestinationPortfolio'),
		'object_type' => array('type' => 'varchar(64)', 'label' => 'ObjectType'),
		'fk_object' => array('type' => 'integer', 'label' => 'ObjectId'),
		'external_ref' => array('type' => 'varchar(128)', 'label' => 'ExternalRef'),
		'reason' => array('type' => 'text', 'label' => 'Reason'),
		'date_operation' => array('type' => 'datetime', 'label' => 'OperationDate', 'notnull' => 1),
		'date_creation' => array('type' => 'datetime', 'label' => 'DateCreation', 'notnull' => 1),
		'fk_user_creat' => array('type' => 'integer', 'label' => 'UserAuthor', 'notnull' => 1),
		'reversal_of' => array('type' => 'integer', 'label' => 'ReversalOf'),
		'import_key' => array('type' => 'varchar(14)', 'label' => 'ImportId'),
	);

	public function __construct(DoliDB $db)
	{
		$this->db = $db;
	}

	public function fetch($id, $ref = null, $noextrafields = 1)
	{
		global $conf;
		$result = $this->fetchCommon($id, $ref, '', $noextrafields);
		if ($result > 0 && (int) $this->entity !== (int) $conf->entity) {
			return 0;
		}
		return $result;
	}

	public function create(User $user, $notrigger = 0)
	{
		$this->error = 'ErrorAppendOnlyOperation';
		return -1;
	}

	public function update(User $user, $notrigger = 0)
	{
		$this->error = 'ErrorAppendOnlyOperation';
		return -1;
	}

	public function delete(User $user, $notrigger = 0)
	{
		$this->error = 'ErrorAppendOnlyOperation';
		return -1;
	}

	public function updateCommon(User $user, $notrigger = 0)
	{
		$this->error = 'ErrorAppendOnlyOperation';
		return -1;
	}

	public function deleteCommon(User $user, $notrigger = 0, $forcechilddeletion = 0)
	{
		$this->error = 'ErrorAppendOnlyOperation';
		return -1;
	}

	public function setValueFrom($field, $value, $table = '', $id = null, $format = '', $id_field = '', $fuser = null, $trigkey = '', $fk_user_field = 'fk_user_modif')
	{
		$this->error = 'ErrorAppendOnlyOperation';
		return -1;
	}

	public function update_ref_ext($ref_ext)
	{
		$this->error = 'ErrorAppendOnlyOperation';
		return -1;
	}

	public function update_note($note, $suffix = '', $notrigger = 0)
	{
		$this->error = 'ErrorAppendOnlyOperation';
		return -1;
	}

	public function setStatut($status, $elementId = null, $elementType = '', $trigkey = '', $fieldstatus = 'fk_statut')
	{
		$this->error = 'ErrorAppendOnlyOperation';
		return -1;
	}

	public function setStatusCommon($user, $status, $notrigger = 0, $triggercode = '')
	{
		$this->error = 'ErrorAppendOnlyOperation';
		return -1;
	}
}
