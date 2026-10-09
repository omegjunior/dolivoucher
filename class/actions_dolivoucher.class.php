<?php
/* Copyright (C) 2026 Fred Omega Junior */

declare(strict_types=1);

require_once __DIR__.'/dolivoucherinvoicesettlement.class.php';
require_once __DIR__.'/dolivouchermoney.class.php';

/** Invoice-card hooks for DoliVoucher settlements. */
class ActionsDoliVoucher
{
	public $resprints ;
	/** @var string[] */
	public array $errors = array();

	public function addMoreActionsButtons($parameters, &$object, &$action, $hookmanager): int
	{
		global $conf, $langs, $user;
		if (strpos((string) ($parameters['currentcontext'] ?? ''), 'invoicecard') === false || !is_object($object) || ($object->element ?? '') !== 'facture') return 0;
		$canUse = !empty($user->admin) || ($user->hasRight('dolivoucher', 'settlement', 'use') && $user->hasRight('facture', 'paiement'));
		$invoiceCurrency = empty($object->multicurrency_code) ? (string) $conf->currency : (string) $object->multicurrency_code;
		$eligibleType = in_array((int) $object->type, array(Facture::TYPE_STANDARD, Facture::TYPE_REPLACEMENT), true);
		$positiveRemainder = false;
		try {
			$positiveRemainder = DoliVoucherMoney::compare(DoliVoucherMoney::normalize((string) $object->getRemainToPay()), '0.00000000') > 0;
		} catch (InvalidArgumentException $e) {
			$positiveRemainder = false;
		}
		if ((int) $object->status === Facture::STATUS_VALIDATED && $eligibleType && $invoiceCurrency === (string) $conf->currency && $positiveRemainder) {
			$url = dol_buildpath('/dolivoucher/invoice_voucher.php', 1).'?facid='.(int) $object->id;
			print dolGetButtonAction($langs->trans('UseVoucherOnInvoice'), '', 'default', $url, '', $canUse);
		}
		return 0;
	}

	public function formObjectOptions($parameters, &$object, &$action, $hookmanager): int
	{
		global $conf, $db, $langs, $user;
		$canRead = !empty($user->admin) || $user->hasRight('dolivoucher', 'audit', 'read') || $user->hasRight('dolivoucher', 'settlement', 'use') || $user->hasRight('dolivoucher', 'settlement', 'reverse');
		if (strpos((string) ($parameters['currentcontext'] ?? ''), 'invoicecard') === false || !is_object($object) || ($object->element ?? '') !== 'facture' || !$canRead) return 0;
		$sql = 'SELECT s.rowid, s.amount, s.date_creation, v.rowid AS voucher_id, v.ref AS voucher_ref';
		$sql .= ' FROM '.$db->prefix().'dolivoucher_invoice_settlement s INNER JOIN '.$db->prefix().'dolivoucher_voucher v ON v.rowid=s.fk_voucher AND v.entity=s.entity';
		$sql .= ' LEFT JOIN '.$db->prefix().'dolivoucher_invoice_settlement r ON r.reversal_of=s.rowid AND r.entity=s.entity';
		$sql .= ' WHERE s.entity='.(int) $conf->entity.' AND s.fk_facture='.(int) $object->id." AND s.event_type='APPLY' AND r.rowid IS NULL ORDER BY s.date_creation DESC, s.rowid DESC";
		$resql = $db->query($sql);
		if (!$resql || $db->num_rows($resql) === 0) return 0;
		$colspan = !empty($parameters['colspan']) ? (string) $parameters['colspan'] : ' colspan="3"';
		$output = '<tr class="dolivoucher-invoice-settlements"><td class="tdtop">'.$langs->trans('DoliVoucherSettlements').'</td><td'.$colspan.'>';
		$output .= '<div class="div-table-responsive"><table class="noborder centpercent">';
		while ($row = $db->fetch_object($resql)) {
			$output .= '<tr class="oddeven"><td class="nowrap">'.dol_print_date($db->jdate($row->date_creation), 'day').'</td>';
			$output .= '<td><a href="'.dol_buildpath('/dolivoucher/voucher_card.php', 1).'?id='.(int) $row->voucher_id.'">'.dol_escape_htmltag($row->voucher_ref).'</a></td>';
			$output .= '<td class="right nowrap">'.price($row->amount, 0, $langs, 1, -1, -1, $conf->currency).'</td></tr>';
		}
		$output .= '</table></div></td></tr>';
		$this->resprints = $output;
		return 0;
	}
}
