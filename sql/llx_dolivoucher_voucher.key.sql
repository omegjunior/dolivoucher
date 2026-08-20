ALTER TABLE llx_dolivoucher_voucher ADD UNIQUE INDEX uk_dolivoucher_voucher_ref_entity (entity, ref);
ALTER TABLE llx_dolivoucher_voucher ADD UNIQUE INDEX uk_dolivoucher_voucher_barcode_entity (entity, barcode);
ALTER TABLE llx_dolivoucher_voucher ADD INDEX idx_dolivoucher_voucher_portfolio (fk_portfolio);
ALTER TABLE llx_dolivoucher_voucher ADD INDEX idx_dolivoucher_voucher_entity_status (entity, status);
ALTER TABLE llx_dolivoucher_voucher ADD INDEX idx_dolivoucher_voucher_expiration (date_expiration);
ALTER TABLE llx_dolivoucher_voucher ADD CONSTRAINT fk_dolivoucher_voucher_portfolio FOREIGN KEY (fk_portfolio) REFERENCES llx_dolivoucher_portfolio(rowid);
