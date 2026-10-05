CREATE TABLE IF NOT EXISTS llx_dolivoucher_series_event_voucher (
	rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
	entity INTEGER DEFAULT 1 NOT NULL,
	fk_event INTEGER NOT NULL,
	fk_series INTEGER NOT NULL,
	fk_voucher INTEGER NOT NULL,
	event_type VARCHAR(32) NOT NULL,
	date_creation DATETIME NOT NULL
) ENGINE=innodb;
