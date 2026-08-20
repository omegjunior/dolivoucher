<?php
/* Copyright (C) 2026 Fred Omega Junior */

declare(strict_types=1);

include_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';

/** DoliVoucher module descriptor. */
class modDoliVoucher extends DolibarrModules
{
	public function __construct($db)
	{
		global $conf;
		$this->db = $db;
		$this->numero = 501116; // Provisional ModuleBuilder identifier; reserve before publication.
		$this->rights_class = 'dolivoucher';
		$this->family = 'Fred Omega Junior';
		$this->module_position = '90';
		$this->name = preg_replace('/^mod/i', '', get_class($this));
		$this->description = 'ModuleDoliVoucherDesc';
		$this->descriptionlong = 'DoliVoucherDescription';
		$this->editor_name = 'Fred Omega Junior';
		$this->editor_url = 'https://www.linkedin.com/in/frédéric-h-887621160';
		$this->version = '0.1.0-dev';
		$this->const_name = 'MAIN_MODULE_'.strtoupper($this->name);
		$this->picto = 'fa-ticket-alt';
		$this->module_parts = array('triggers' => 0, 'login' => 0, 'substitutions' => 0, 'menus' => 0, 'tpl' => 0, 'barcode' => 0, 'models' => 0, 'printing' => 0, 'theme' => 0, 'css' => array(), 'js' => array(), 'hooks' => array(), 'moduleforexternal' => 0);
		$this->dirs = array('/dolivoucher/temp');
		$this->config_page_url = array('setup.php@dolivoucher');
		$this->hidden = getDolGlobalInt('MODULE_DOLIVOUCHER_DISABLED');
		$this->depends = array();
		$this->requiredby = array();
		$this->conflictwith = array();
		$this->langfiles = array('dolivoucher@dolivoucher');
		$this->phpmin = array(8, 1);
		$this->need_dolibarr_version = array(22, 0);
		$this->need_javascript_ajax = 0;
		$this->warnings_activation = array();
		$this->warnings_activation_ext = array();
		$this->const = array();
		if (!isModEnabled('dolivoucher')) {
			$conf->dolivoucher = new stdClass();
			$conf->dolivoucher->enabled = 0;
		}
		$this->tabs = array();
		$this->dictionaries = array();
		$this->boxes = array();
		$this->cronjobs = array();

		$this->rights = array();
		$permissions = array(
			array(50111601, 'DoliVoucherPermissionRead', 'portfolio', 'read'),
			array(50111602, 'DoliVoucherPermissionPortfolioWrite', 'portfolio', 'write'),
			array(50111603, 'DoliVoucherPermissionPortfolioValidate', 'portfolio', 'validate'),
			array(50111604, 'DoliVoucherPermissionVoucherWrite', 'voucher', 'write'),
			array(50111605, 'DoliVoucherPermissionConsume', 'voucher', 'consume'),
			array(50111606, 'DoliVoucherPermissionBlock', 'voucher', 'block'),
			array(50111607, 'DoliVoucherPermissionCancel', 'voucher', 'cancel'),
			array(50111608, 'DoliVoucherPermissionTransfer', 'transfer', 'write'),
			array(50111609, 'DoliVoucherPermissionCompensate', 'audit', 'compensate'),
			array(50111610, 'DoliVoucherPermissionAuditRead', 'audit', 'read'),
			array(50111611, 'DoliVoucherPermissionConfigure', 'config', 'write'),
		);
		foreach ($permissions as $permission) {
			$r = count($this->rights);
			$this->rights[$r][0] = $permission[0];
			$this->rights[$r][1] = $permission[1];
			$this->rights[$r][3] = 0;
			$this->rights[$r][4] = $permission[2];
			$this->rights[$r][5] = $permission[3];
		}

		$this->menu = array();
		$r = 0;
		$this->menu[$r++] = $this->menuEntry('', 'top', 'GiftVouchers', 'dolivoucher', '', '/dolivoucher/dolivoucherindex.php', '$user->hasRight("dolivoucher", "portfolio", "read")', $r);
		$this->menu[$r++] = $this->menuEntry('fk_mainmenu=dolivoucher', 'left', 'Portfolios', 'dolivoucher', 'portfolios', '/dolivoucher/portfolio_list.php', '$user->hasRight("dolivoucher", "portfolio", "read")', $r);
		$this->menu[$r++] = $this->menuEntry('fk_mainmenu=dolivoucher,fk_leftmenu=portfolios', 'left', 'NewPortfolio', 'dolivoucher', 'portfolio_new', '/dolivoucher/portfolio_card.php?action=create', '$user->hasRight("dolivoucher", "portfolio", "write")', $r);
		$this->menu[$r++] = $this->menuEntry('fk_mainmenu=dolivoucher', 'left', 'Vouchers', 'dolivoucher', 'vouchers', '/dolivoucher/voucher_list.php', '$user->hasRight("dolivoucher", "portfolio", "read")', $r);
		$this->menu[$r++] = $this->menuEntry('fk_mainmenu=dolivoucher,fk_leftmenu=vouchers', 'left', 'NewVoucher', 'dolivoucher', 'voucher_new', '/dolivoucher/voucher_card.php?action=create', '$user->hasRight("dolivoucher", "voucher", "write")', $r);
		$this->menu[$r++] = $this->menuEntry('fk_mainmenu=dolivoucher', 'left', 'OperationJournal', 'dolivoucher', 'operation_journal', '/dolivoucher/operation_list.php', '$user->hasRight("dolivoucher", "audit", "read")', $r);
	}

	/** @return array<string,mixed> */
	private function menuEntry(string $parent, string $type, string $title, string $main, string $left, string $url, string $permission, int $position): array
	{
		return array('fk_menu' => $parent, 'type' => $type, 'titre' => $title, 'mainmenu' => $main, 'leftmenu' => $left, 'url' => $url, 'langs' => 'dolivoucher@dolivoucher', 'position' => 1000 + $position, 'enabled' => 'isModEnabled("dolivoucher")', 'perms' => $permission, 'target' => '', 'user' => 0);
	}

	public function init($options = '')
	{
		$result = $this->_load_tables('/dolivoucher/sql/');
		if ($result < 0) {
			return -1;
		}
		$this->remove($options);
		return $this->_init(array(), $options);
	}

	public function remove($options = '')
	{
		return $this->_remove(array(), $options);
	}
}
