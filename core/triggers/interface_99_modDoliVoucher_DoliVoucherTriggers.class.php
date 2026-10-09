<?php
/* Copyright (C) 2026 Fred Omega Junior */

declare(strict_types=1);

require_once DOL_DOCUMENT_ROOT.'/core/triggers/dolibarrtriggers.class.php';
require_once __DIR__.'/../../class/dolivoucherinvoicesettlementservice.class.php';

/** Protect native payments linked to active DoliVoucher settlements. */
class InterfaceDoliVoucherTriggers extends DolibarrTriggers
{
	public function __construct($db)
	{
		$this->db = $db;
		$this->name = preg_replace('/^Interface/i', '', get_class($this));
		$this->family = 'financial';
		$this->description = 'DoliVoucher invoice settlement integrity triggers';
		$this->version = '0.3.0';
		$this->picto = 'ticket';
	}

	public function runTrigger($action, $object, User $user, Translate $langs, Conf $conf)
	{
		if (!isModEnabled('dolivoucher') || $action !== 'PAYMENT_CUSTOMER_DELETE' || DoliVoucherInvoiceSettlementService::isPaymentDeletionAuthorized()) return 0;
		$paymentId = (int) ($object->id ?? 0);
		if ($paymentId <= 0) return 0;
		$sql = 'SELECT s.rowid FROM '.$this->db->prefix().'dolivoucher_invoice_settlement s';
		$sql .= ' LEFT JOIN '.$this->db->prefix().'dolivoucher_invoice_settlement r ON r.reversal_of=s.rowid';
		$sql .= ' WHERE s.entity='.(int) $conf->entity.' AND s.fk_paiement='.$paymentId." AND s.event_type='APPLY' AND r.rowid IS NULL";
		$sql .= $this->db->plimit(1, 0);
		$resql = $this->db->query($sql);
		if ($resql && $this->db->fetch_object($resql)) {
			$this->error = $langs->trans('ErrorDoliVoucherPaymentDeletionForbidden');
			$this->errors[] = $this->error;
			return -1;
		}
		return 0;
	}
}
