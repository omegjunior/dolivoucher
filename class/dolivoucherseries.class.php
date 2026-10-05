<?php
/* Copyright (C) 2026 Fred Omega Junior */

declare(strict_types=1);

require_once DOL_DOCUMENT_ROOT.'/core/class/commonobject.class.php';
require_once __DIR__.'/dolivouchermoney.class.php';

/** Administrative batch used to generate and trace physical vouchers. */
class DoliVoucherSeries extends CommonObject
{
	public const STATUS_DRAFT = 0;
	public const STATUS_GENERATED = 1;
	public const STATUS_PRINTED = 2;
	public const STATUS_PREPARED = 3;
	public const STATUS_DELIVERED = 4;
	public const STATUS_CANCELED = 9;

	public $module = 'dolivoucher';
	public $element = 'dolivoucher_series';
	public $table_element = 'dolivoucher_series';
	public $picto = 'fontawesome_layer-group';
	public $ismultientitymanaged = 1;
	public $isextrafieldmanaged = 0;

	public $fields = array(
		'rowid' => array('type' => 'integer', 'label' => 'Id', 'notnull' => 1, 'visible' => -2),
		'entity' => array('type' => 'integer', 'label' => 'Entity', 'notnull' => 1, 'visible' => -2),
		'ref' => array('type' => 'varchar(32)', 'label' => 'Ref', 'notnull' => 1, 'visible' => 1),
		'generation_key' => array('type' => 'varchar(64)', 'label' => 'GenerationKey', 'notnull' => 1, 'visible' => -2),
		'sequence_year' => array('type' => 'integer', 'label' => 'Year', 'notnull' => 1, 'visible' => 1),
		'fk_portfolio' => array('type' => 'integer', 'label' => 'Portfolio', 'notnull' => 1, 'visible' => 1),
		'label' => array('type' => 'varchar(255)', 'label' => 'Label', 'visible' => 1),
		'quantity' => array('type' => 'integer', 'label' => 'Quantity', 'notnull' => 1, 'visible' => 1),
		'face_value' => array('type' => 'price', 'label' => 'FaceValue', 'notnull' => 1, 'visible' => 1),
		'date_expiration' => array('type' => 'datetime', 'label' => 'ExpirationDate', 'visible' => 1),
		'status' => array('type' => 'integer', 'label' => 'Status', 'notnull' => 1, 'visible' => 1),
		'generated_count' => array('type' => 'integer', 'label' => 'GeneratedCount', 'notnull' => 1, 'visible' => 1),
		'printed_count' => array('type' => 'integer', 'label' => 'PrintedCount', 'notnull' => 1, 'visible' => 1),
		'prepared_count' => array('type' => 'integer', 'label' => 'PreparedCount', 'notnull' => 1, 'visible' => 1),
		'delivered_count' => array('type' => 'integer', 'label' => 'DeliveredCount', 'notnull' => 1, 'visible' => 1),
		'note_private' => array('type' => 'html', 'label' => 'NotePrivate', 'visible' => 0),
		'date_generation' => array('type' => 'datetime', 'label' => 'GenerationDate', 'visible' => 1),
		'date_printed' => array('type' => 'datetime', 'label' => 'PrintedDate', 'visible' => 1),
		'date_prepared' => array('type' => 'datetime', 'label' => 'PreparedDate', 'visible' => 1),
		'date_delivered' => array('type' => 'datetime', 'label' => 'DeliveredDate', 'visible' => 1),
		'date_creation' => array('type' => 'datetime', 'label' => 'DateCreation', 'notnull' => 1, 'visible' => -2),
		'tms' => array('type' => 'timestamp', 'label' => 'DateModification', 'visible' => -2),
		'fk_user_creat' => array('type' => 'integer:User:user/class/user.class.php', 'label' => 'UserAuthor', 'notnull' => 1, 'visible' => -2),
		'fk_user_modif' => array('type' => 'integer:User:user/class/user.class.php', 'label' => 'UserModif', 'visible' => -2),
		'import_key' => array('type' => 'varchar(14)', 'label' => 'ImportId', 'visible' => -2),
	);

	public $rowid;
	public $entity;
	public $ref;
	public $generation_key;
	public $sequence_year;
	public $fk_portfolio;
	public $label;
	public $quantity;
	public $face_value;
	public $date_expiration;
	public $status;
	public $generated_count;
	public $printed_count;
	public $prepared_count;
	public $delivered_count;
	public $note_private;
	public $date_generation;
	public $date_printed;
	public $date_prepared;
	public $date_delivered;
	public $date_creation;
	public $tms;
	public $fk_user_creat;
	public $fk_user_modif;
	public $import_key;

	public function __construct(DoliDB $db)
	{
		$this->db = $db;
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

	/** Series writes are orchestrated by DoliVoucherSeriesService. */
	public function create(User $user, $notrigger = 0)
	{
		$this->error = 'ErrorSeriesServiceRequired';
		return -1;
	}

	public function update(User $user, $notrigger = 0)
	{
		$this->error = 'ErrorSeriesServiceRequired';
		return -1;
	}

	public function delete(User $user, $notrigger = 0)
	{
		$this->error = 'ErrorSeriesDeletionForbidden';
		return -1;
	}

	public function getNomUrl($withpicto = 0, $option = '', $notooltip = 0, $morecss = '')
	{
		$link = '<a href="'.dol_buildpath('/dolivoucher/series_card.php', 1).'?id='.(int) $this->id.'">';
		$link .= $withpicto ? img_object('', $this->picto, 'class="pictofixedwidth"') : '';
		return $link.dol_escape_htmltag((string) $this->ref).'</a>';
	}
}
