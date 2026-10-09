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
		$this->version = '0.3.0-dev';
		$this->const_name = 'MAIN_MODULE_'.strtoupper($this->name);
		$this->picto = 'fa-gavel';
		$this->module_parts = array('triggers' => 1, 'login' => 0, 'substitutions' => 0, 'menus' => 0, 'tpl' => 0, 'barcode' => 0, 'models' => 1, 'printing' => 1, 'theme' => 0, 'css' => array(), 'js' => array(), 'hooks' => array('invoicecard'), 'moduleforexternal' => 0);
		$this->dirs = array('/dolivoucher/temp', '/dolivoucher/series');
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
		$this->const = array(
			array('DOLIVOUCHER_MAX_VOUCHERS_PER_SERIES', 'chaine', '1000', 'DoliVoucherMaxVouchersPerSeries', 0, 'current', 1),
			array('DOLIVOUCHER_MAX_VOUCHERS_PER_PDF', 'chaine', '1000', 'DoliVoucherMaxVouchersPerPdf', 0, 'current', 1),
		);
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
			array(50111612, 'DoliVoucherPermissionSeriesRead', 'series', 'read'),
			array(50111613, 'DoliVoucherPermissionSeriesGenerate', 'series', 'generate'),
			array(50111614, 'DoliVoucherPermissionSeriesPrint', 'series', 'print'),
			array(50111615, 'DoliVoucherPermissionSeriesReprint', 'series', 'reprint'),
			array(50111616, 'DoliVoucherPermissionSeriesPrepare', 'series', 'prepare'),
			array(50111617, 'DoliVoucherPermissionSeriesDeliver', 'series', 'deliver'),
			array(50111618, 'DoliVoucherPermissionInvoiceSettlementUse', 'settlement', 'use'),
			array(50111619, 'DoliVoucherPermissionInvoiceSettlementReverse', 'settlement', 'reverse'),
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
		$this->menu[$r++] = $this->menuEntry('', 'top', 'GiftVouchers', 'dolivoucher', '', '/dolivoucher/dolivoucherindex.php', '$user->hasRight("dolivoucher", "portfolio", "read")', $r, $this->picto);
		$this->menu[$r++] = $this->menuEntry('fk_mainmenu=dolivoucher', 'left', 'Portfolios', 'dolivoucher', 'portfolios', '/dolivoucher/portfolio_list.php', '$user->hasRight("dolivoucher", "portfolio", "read")', $r, 'fa-wallet');
		$this->menu[$r++] = $this->menuEntry('fk_mainmenu=dolivoucher,fk_leftmenu=portfolios', 'left', 'NewPortfolio', 'dolivoucher', 'portfolio_new', '/dolivoucher/portfolio_card.php?action=create', '$user->hasRight("dolivoucher", "portfolio", "write")', $r);
		$this->menu[$r++] = $this->menuEntry('fk_mainmenu=dolivoucher', 'left', 'Vouchers', 'dolivoucher', 'vouchers', '/dolivoucher/voucher_list.php', '$user->hasRight("dolivoucher", "portfolio", "read")', $r, 'fa-ticket-alt');
		$this->menu[$r++] = $this->menuEntry('fk_mainmenu=dolivoucher,fk_leftmenu=vouchers', 'left', 'NewVoucher', 'dolivoucher', 'voucher_new', '/dolivoucher/voucher_card.php?action=create', '$user->hasRight("dolivoucher", "voucher", "write")', $r);
		$this->menu[$r++] = $this->menuEntry('fk_mainmenu=dolivoucher', 'left', 'VoucherSeries', 'dolivoucher', 'series', '/dolivoucher/series_list.php', '$user->hasRight("dolivoucher", "series", "read")', $r, 'fa-layer-group');
		$this->menu[$r++] = $this->menuEntry('fk_mainmenu=dolivoucher,fk_leftmenu=series', 'left', 'NewVoucherSeries', 'dolivoucher', 'series_new', '/dolivoucher/series_card.php?action=create', '$user->hasRight("dolivoucher", "series", "generate")', $r);
		$this->menu[$r++] = $this->menuEntry('fk_mainmenu=dolivoucher', 'left', 'OperationJournal', 'dolivoucher', 'operation_journal', '/dolivoucher/operation_list.php', '$user->hasRight("dolivoucher", "audit", "read")', $r, 'fa-list-alt');
	}

	/** @return array<string,mixed> */
	private function menuEntry(string $parent, string $type, string $title, string $main, string $left, string $url, string $permission, int $position, string $picto = ''): array
	{
		$entry = array('fk_menu' => $parent, 'type' => $type, 'titre' => $title, 'mainmenu' => $main, 'leftmenu' => $left, 'url' => $url, 'langs' => 'dolivoucher@dolivoucher', 'position' => 1000 + $position, 'enabled' => 'isModEnabled("dolivoucher")', 'perms' => $permission, 'target' => '', 'user' => 0);
		if ($picto !== '') {
			$entry['prefix'] = img_picto('', $picto, 'class="paddingright pictofixedwidth em092"');
		}

		return $entry;
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
