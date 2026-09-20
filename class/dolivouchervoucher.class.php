<?php
/* Copyright (C) 2026 Fred Omega Junior */

declare(strict_types=1);

require_once DOL_DOCUMENT_ROOT.'/core/class/commonobject.class.php';
require_once __DIR__.'/dolivouchermoney.class.php';
require_once __DIR__.'/dolivoucherportfolio.class.php';

class DoliVoucherVoucher extends CommonObject
{
	public const STATUS_DRAFT = 0;
	public const STATUS_PREPARED = 1;
	public const STATUS_ACTIVE = 2;
	public const STATUS_PARTIALLY_CONSUMED = 3;
	public const STATUS_CONSUMED = 4;
	public const STATUS_BLOCKED = 6;
	public const STATUS_CANCELED = 9;
	public const STATUS_EXPIRED = 10;

	public $module = 'dolivoucher';
	public $element = 'dolivoucher_voucher';
	public $table_element = 'dolivoucher_voucher';
	public $picto = 'ticket';
	public $ismultientitymanaged = 1;
	public $isextrafieldmanaged = 0;

	public $fields = array(
		'rowid' => array('type' => 'integer', 'label' => 'Id', 'notnull' => 1, 'visible' => -2),
		'entity' => array('type' => 'integer', 'label' => 'Entity', 'notnull' => 1, 'visible' => -2),
		'fk_portfolio' => array('type' => 'integer', 'label' => 'Portfolio', 'notnull' => 1, 'visible' => 1),
		'ref' => array('type' => 'varchar(128)', 'label' => 'SerialNumber', 'notnull' => 1, 'visible' => 1),
		'barcode' => array('type' => 'varchar(128)', 'label' => 'Barcode', 'visible' => 1),
		'label' => array('type' => 'varchar(255)', 'label' => 'Label', 'visible' => 1),
		'initial_amount' => array('type' => 'price', 'label' => 'InitialAmount', 'notnull' => 1, 'visible' => 1),
		'current_balance' => array('type' => 'price', 'label' => 'CurrentBalance', 'notnull' => 1, 'visible' => 1),
		'status' => array('type' => 'integer', 'label' => 'Status', 'notnull' => 1, 'visible' => 1),
		'date_issue' => array('type' => 'datetime', 'label' => 'IssueDate', 'visible' => 1),
		'date_activation' => array('type' => 'datetime', 'label' => 'ActivationDate', 'visible' => 1),
		'date_expiration' => array('type' => 'datetime', 'label' => 'ExpirationDate', 'visible' => 1),
		'beneficiary_name' => array('type' => 'varchar(255)', 'label' => 'Beneficiary', 'visible' => 1),
		'note_private' => array('type' => 'html', 'label' => 'NotePrivate', 'visible' => 0),
		'date_creation' => array('type' => 'datetime', 'label' => 'DateCreation', 'notnull' => 1, 'visible' => -2),
		'tms' => array('type' => 'timestamp', 'label' => 'DateModification', 'visible' => -2),
		'fk_user_creat' => array('type' => 'integer:User:user/class/user.class.php', 'label' => 'UserAuthor', 'notnull' => 1, 'visible' => -2),
		'fk_user_modif' => array('type' => 'integer:User:user/class/user.class.php', 'label' => 'UserModif', 'visible' => -2),
		'import_key' => array('type' => 'varchar(14)', 'label' => 'ImportId', 'visible' => -2),
	);

	public $rowid;
	public $entity;
	public $fk_portfolio;
	public $ref;
	public $barcode;
	public $label;
	public $initial_amount;
	public $current_balance;
	public $status;
	public $date_issue;
	public $date_activation;
	public $date_expiration;
	public $beneficiary_name;
	public $note_private;
	public $date_creation;
	public $tms;
	public $fk_user_creat;
	public $fk_user_modif;
	public $import_key;

	public function __construct(DoliDB $db)
	{
		$this->db = $db;
	}

	public function create(User $user, $notrigger = 0)
	{
		global $conf;
		try {
			$this->initial_amount = DoliVoucherMoney::normalize($this->initial_amount, true);
		} catch (InvalidArgumentException $e) {
			$this->error = 'ErrorAmountMustBePositive';
			return -1;
		}
		$this->entity = (int) $conf->entity;
		$this->status = in_array((int) $this->status, array(self::STATUS_DRAFT, self::STATUS_PREPARED), true) ? (int) $this->status : self::STATUS_DRAFT;
		$this->current_balance = '0.00000000';
		if (!$this->portfolioAcceptsVoucher()) {
			return -1;
		}
		return $this->createCommon($user, $notrigger);
	}

	public function fetch($id, $ref = null, $noextrafields = 1)
	{
		global $conf;
		$result = $this->fetchCommon($id, $ref, '', $noextrafields);
		if ($result > 0 && (int) $this->entity !== (int) $conf->entity) {
			$this->error = 'ErrorRecordNotFound';
			return 0;
		}
		return $result;
	}

	public function update(User $user, $notrigger = 0)
	{
		$stored = new self($this->db);
		if ($stored->fetch((int) $this->id) <= 0) {
			$this->error = 'ErrorRecordNotFound';
			return -1;
		}
		if (!in_array((int) $stored->status, array(self::STATUS_DRAFT, self::STATUS_PREPARED), true)) {
			$this->error = 'ErrorActivatedVoucherImmutable';
			return -1;
		}
		$this->current_balance = '0.00000000';
		try {
			$this->initial_amount = DoliVoucherMoney::normalize($this->initial_amount, true);
		} catch (InvalidArgumentException $e) {
			$this->error = 'ErrorAmountMustBePositive';
			return -1;
		}
		return $this->portfolioAcceptsVoucher() ? $this->updateCommon($user, $notrigger) : -1;
	}

	public function delete(User $user, $notrigger = 0)
	{
		if (!in_array((int) $this->status, array(self::STATUS_DRAFT, self::STATUS_PREPARED), true)) {
			$this->error = 'ErrorVoucherDeletionForbidden';
			return -1;
		}
		$sql = 'SELECT COUNT(*) AS operation_count FROM '.$this->db->prefix().'dolivoucher_operation WHERE fk_voucher='.(int) $this->id;
		$resql = $this->db->query($sql);
		$row = $resql ? $this->db->fetch_object($resql) : false;
		if (!$row || (int) $row->operation_count > 0) {
			$this->error = 'ErrorVoucherHasHistory';
			return -1;
		}
		return $this->deleteCommon($user, $notrigger);
	}

	public function getNomUrl($withpicto = 0, $option = '', $notooltip = 0, $morecss = '')
	{
		$link = '<a href="'.dol_buildpath('/dolivoucher/voucher_card.php', 1).'?id='.(int) $this->id.'">';
		$link .= $withpicto ? img_object('', $this->picto, 'class="pictofixedwidth"') : '';
		return $link.dol_escape_htmltag((string) $this->ref).'</a>';
	}

	private function portfolioAcceptsVoucher(): bool
	{
		$sql = 'SELECT rowid FROM '.$this->db->prefix().'dolivoucher_portfolio WHERE rowid='.(int) $this->fk_portfolio;
		$sql .= ' AND entity='.(int) $this->entity.' AND status IN ('.DoliVoucherPortfolio::STATUS_VALIDATED.','.DoliVoucherPortfolio::STATUS_ACTIVE.') LIMIT 1';
		$resql = $this->db->query($sql);
		if (!$resql || !$this->db->fetch_object($resql)) {
			$this->error = 'ErrorPortfolioNotEligible';
			return false;
		}
		return trim((string) $this->ref) !== '';
	}
}
