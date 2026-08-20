<?php
/* Copyright (C) 2026 Fred Omega Junior */

declare(strict_types=1);

require_once DOL_DOCUMENT_ROOT.'/core/class/commonobject.class.php';

class DoliVoucherPortfolio extends CommonObject
{
	public const TYPE_INSTITUTIONAL = 'INSTITUTIONAL';
	public const TYPE_DONATION = 'DONATION';
	public const STATUS_DRAFT = 0;
	public const STATUS_VALIDATED = 1;
	public const STATUS_ACTIVE = 2;
	public const STATUS_CLOSED = 3;
	public const STATUS_CANCELED = 9;

	public $module = 'dolivoucher';
	public $element = 'dolivoucher_portfolio';
	public $table_element = 'dolivoucher_portfolio';
	public $picto = 'wallet';
	public $ismultientitymanaged = 1;
	public $isextrafieldmanaged = 0;

	public $fields = array(
		'rowid' => array('type' => 'integer', 'label' => 'Id', 'notnull' => 1, 'visible' => -2),
		'entity' => array('type' => 'integer', 'label' => 'Entity', 'notnull' => 1, 'visible' => -2),
		'ref' => array('type' => 'varchar(128)', 'label' => 'Ref', 'notnull' => 1, 'visible' => 1, 'index' => 1),
		'label' => array('type' => 'varchar(255)', 'label' => 'Label', 'notnull' => 1, 'visible' => 1),
		'type' => array('type' => 'varchar(32)', 'label' => 'Type', 'notnull' => 1, 'visible' => 1),
		'fk_soc' => array('type' => 'integer:Societe:societe/class/societe.class.php', 'label' => 'FundingThirdParty', 'visible' => 1),
		'period_label' => array('type' => 'varchar(128)', 'label' => 'Period', 'visible' => 1),
		'date_start' => array('type' => 'date', 'label' => 'DateStart', 'visible' => 1),
		'date_end' => array('type' => 'date', 'label' => 'DateEnd', 'visible' => 1),
		'status' => array('type' => 'integer', 'label' => 'Status', 'notnull' => 1, 'visible' => 1),
		'available_unallocated_balance' => array('type' => 'price', 'label' => 'AvailableUnallocatedBalance', 'notnull' => 1, 'visible' => 1),
		'description' => array('type' => 'text', 'label' => 'Description', 'visible' => 3),
		'note_public' => array('type' => 'html', 'label' => 'NotePublic', 'visible' => 0),
		'note_private' => array('type' => 'html', 'label' => 'NotePrivate', 'visible' => 0),
		'date_creation' => array('type' => 'datetime', 'label' => 'DateCreation', 'notnull' => 1, 'visible' => -2),
		'tms' => array('type' => 'timestamp', 'label' => 'DateModification', 'visible' => -2),
		'fk_user_creat' => array('type' => 'integer:User:user/class/user.class.php', 'label' => 'UserAuthor', 'notnull' => 1, 'visible' => -2),
		'fk_user_modif' => array('type' => 'integer:User:user/class/user.class.php', 'label' => 'UserModif', 'visible' => -2),
		'import_key' => array('type' => 'varchar(14)', 'label' => 'ImportId', 'visible' => -2),
	);

	public $rowid;
	public $entity;
	public $ref;
	public $label;
	public $type;
	public $fk_soc;
	public $period_label;
	public $date_start;
	public $date_end;
	public $status;
	public $available_unallocated_balance;
	public $description;
	public $note_public;
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
		$this->entity = (int) $conf->entity;
		$this->status = self::STATUS_DRAFT;
		$this->available_unallocated_balance = '0.00000000';
		if (!$this->validateBusinessRules()) {
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
		if ((int) $stored->status !== self::STATUS_DRAFT && ($stored->type !== $this->type || (int) $stored->fk_soc !== (int) $this->fk_soc)) {
			$this->error = 'ErrorValidatedPortfolioIdentityLocked';
			return -1;
		}
		if (!$this->validateBusinessRules()) {
			return -1;
		}
		return $this->updateCommon($user, $notrigger);
	}

	public function delete(User $user, $notrigger = 0)
	{
		$sql = 'SELECT (SELECT COUNT(*) FROM '.$this->db->prefix().'dolivoucher_voucher WHERE fk_portfolio = '.(int) $this->id.')';
		$sql .= ' + (SELECT COUNT(*) FROM '.$this->db->prefix().'dolivoucher_operation WHERE fk_portfolio = '.(int) $this->id.') AS linked_count';
		$resql = $this->db->query($sql);
		$record = $resql ? $this->db->fetch_object($resql) : false;
		if (!$record || (int) $record->linked_count > 0) {
			$this->error = 'ErrorPortfolioHasHistory';
			return -1;
		}
		return $this->deleteCommon($user, $notrigger);
	}

	/** @return array<string,string|int> */
	public function getFinancialSummary(): array
	{
		$sql = 'SELECT p.available_unallocated_balance,';
		$sql .= " (SELECT COALESCE(SUM(amount),0) FROM ".$this->db->prefix()."dolivoucher_operation WHERE fk_portfolio=p.rowid AND operation_type='FUND_NEW') AS total_funding,";
		$sql .= " (SELECT COALESCE(SUM(amount),0) FROM ".$this->db->prefix()."dolivoucher_operation WHERE fk_portfolio=p.rowid AND operation_type IN ('CARRYOVER_IN','TRANSFER_IN')) AS incoming_remainders,";
		$sql .= " (SELECT COALESCE(SUM(amount),0) FROM ".$this->db->prefix()."dolivoucher_operation WHERE fk_portfolio=p.rowid AND operation_type='TRANSFER_OUT') AS outgoing_remainders,";
		$sql .= " (SELECT COALESCE(SUM(amount),0) FROM ".$this->db->prefix()."dolivoucher_operation WHERE fk_portfolio=p.rowid AND operation_type='ACTIVATE') AS activated_value,";
		$sql .= " (SELECT COALESCE(SUM(CASE WHEN operation_type='CONSUME' THEN amount WHEN operation_type='CORRECTION' THEN -amount ELSE 0 END),0) FROM ".$this->db->prefix()."dolivoucher_operation WHERE fk_portfolio=p.rowid) AS consumed_amount,";
		$sql .= ' (SELECT COALESCE(SUM(current_balance),0) FROM '.$this->db->prefix().'dolivoucher_voucher WHERE fk_portfolio=p.rowid AND status IN (2,3)) AS immediately_redeemable_voucher_balance,';
		$sql .= ' (SELECT COALESCE(SUM(current_balance),0) FROM '.$this->db->prefix().'dolivoucher_voucher WHERE fk_portfolio=p.rowid AND status=6) AS blocked_voucher_balance,';
		$sql .= ' (SELECT COALESCE(SUM(current_balance),0) FROM '.$this->db->prefix().'dolivoucher_voucher WHERE fk_portfolio=p.rowid AND status=10) AS expired_unreallocated_balance,';
		$sql .= ' (SELECT COALESCE(SUM(current_balance),0) FROM '.$this->db->prefix().'dolivoucher_voucher WHERE fk_portfolio=p.rowid AND status IN (2,3,6)) AS outstanding_voucher_balance,';
		$sql .= ' CAST(p.available_unallocated_balance + (SELECT COALESCE(SUM(current_balance),0) FROM '.$this->db->prefix().'dolivoucher_voucher WHERE fk_portfolio=p.rowid AND status IN (2,3,6)) AS DECIMAL(24,8)) AS global_outstanding_balance,';
		$sql .= ' (SELECT COUNT(*) FROM '.$this->db->prefix().'dolivoucher_voucher WHERE fk_portfolio=p.rowid) AS voucher_count,';
		$sql .= ' (SELECT COUNT(*) FROM '.$this->db->prefix().'dolivoucher_voucher WHERE fk_portfolio=p.rowid AND status IN (2,3)) AS active_count,';
		$sql .= ' (SELECT COUNT(*) FROM '.$this->db->prefix().'dolivoucher_voucher WHERE fk_portfolio=p.rowid AND status=4) AS consumed_count';
		$sql .= ' FROM '.$this->db->prefix().'dolivoucher_portfolio p WHERE p.rowid='.(int) $this->id.' AND p.entity='.(int) $this->entity.' LIMIT 1';
		$resql = $this->db->query($sql);
		$row = $resql ? $this->db->fetch_object($resql) : false;
		return $row ? (array) $row : array();
	}

	public function getNomUrl($withpicto = 0, $option = '', $notooltip = 0, $morecss = '')
	{
		$link = '<a href="'.dol_buildpath('/dolivoucher/portfolio_card.php', 1).'?id='.(int) $this->id.'">';
		$link .= $withpicto ? img_object('', $this->picto, 'class="pictofixedwidth"') : '';
		return $link.dol_escape_htmltag((string) $this->ref).'</a>';
	}

	private function validateBusinessRules(): bool
	{
		if (!empty($this->date_start) && !empty($this->date_end) && (int) $this->date_end < (int) $this->date_start) {
			$this->error = 'ErrorInvalidPortfolioDates';
			return false;
		}
		if (!in_array($this->type, array(self::TYPE_INSTITUTIONAL, self::TYPE_DONATION), true)) {
			$this->error = 'ErrorInvalidPortfolioType';
			return false;
		}
		if ($this->type === self::TYPE_INSTITUTIONAL && (int) $this->fk_soc <= 0) {
			$this->error = 'ErrorInstitutionalThirdPartyRequired';
			return false;
		}
		if ((int) $this->fk_soc > 0) {
			$sql = 'SELECT rowid FROM '.$this->db->prefix().'societe WHERE rowid='.(int) $this->fk_soc.' AND entity IN ('.getEntity('societe').') LIMIT 1';
			if (!($resql = $this->db->query($sql)) || !$this->db->fetch_object($resql)) {
				$this->error = 'ErrorInvalidFundingThirdParty';
				return false;
			}
		}
		return trim((string) $this->ref) !== '' && trim((string) $this->label) !== '';
	}
}
