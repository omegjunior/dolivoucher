ALTER TABLE llx_dolivoucher_takepos_intent ADD UNIQUE INDEX uk_dolivoucher_takepos_uuid (entity, intent_uuid);
ALTER TABLE llx_dolivoucher_takepos_intent ADD UNIQUE INDEX uk_dolivoucher_takepos_idempotency (entity, idempotency_key);
ALTER TABLE llx_dolivoucher_takepos_intent ADD INDEX idx_dolivoucher_takepos_invoice (entity, fk_facture);
ALTER TABLE llx_dolivoucher_takepos_intent ADD INDEX idx_dolivoucher_takepos_voucher (entity, fk_voucher);
ALTER TABLE llx_dolivoucher_takepos_intent ADD INDEX idx_dolivoucher_takepos_settlement (entity, fk_settlement);
ALTER TABLE llx_dolivoucher_takepos_intent ADD INDEX idx_dolivoucher_takepos_status (entity, status);
ALTER TABLE llx_dolivoucher_takepos_intent ADD INDEX idx_dolivoucher_takepos_date (entity, date_creation);
