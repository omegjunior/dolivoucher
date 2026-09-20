ALTER TABLE llx_dolivoucher_portfolio ADD UNIQUE INDEX uk_dolivoucher_portfolio_ref_entity (entity, ref);
ALTER TABLE llx_dolivoucher_portfolio ADD INDEX idx_dolivoucher_portfolio_entity_status (entity, status);
ALTER TABLE llx_dolivoucher_portfolio ADD INDEX idx_dolivoucher_portfolio_entity_type (entity, type);
ALTER TABLE llx_dolivoucher_portfolio ADD INDEX idx_dolivoucher_portfolio_fk_soc (fk_soc);
ALTER TABLE llx_dolivoucher_portfolio ADD INDEX idx_dolivoucher_portfolio_dates (date_start, date_end);
