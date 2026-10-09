CREATE TABLE IF NOT EXISTS llx_dolivoucher_invoice_settlement (
	rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
	entity INTEGER DEFAULT 1 NOT NULL,
	settlement_uuid CHAR(36) NOT NULL,
	idempotency_key VARCHAR(128) NOT NULL,
	event_type VARCHAR(16) NOT NULL,
	fk_voucher INTEGER NOT NULL,
	fk_operation INTEGER NOT NULL,
	fk_facture INTEGER NOT NULL,
	fk_paiement INTEGER NOT NULL,
	amount DECIMAL(24,8) NOT NULL,
	reversal_of INTEGER NULL,
	invoice_ref_snapshot VARCHAR(30) NOT NULL,
	payment_ref_snapshot VARCHAR(30) NULL,
	request_source VARCHAR(32) NOT NULL,
	date_creation DATETIME NOT NULL,
	fk_user_creat INTEGER NOT NULL,
	external_ref VARCHAR(128) NULL
) ENGINE=innodb;
