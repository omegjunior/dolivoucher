ALTER TABLE llx_dolivoucher_series ADD UNIQUE INDEX uk_dolivoucher_series_ref_entity (entity, ref);
ALTER TABLE llx_dolivoucher_series ADD UNIQUE INDEX uk_dolivoucher_series_generation (entity, generation_key);
ALTER TABLE llx_dolivoucher_series ADD INDEX idx_dolivoucher_series_portfolio (fk_portfolio);
ALTER TABLE llx_dolivoucher_series ADD INDEX idx_dolivoucher_series_entity_status (entity, status);
ALTER TABLE llx_dolivoucher_series ADD INDEX idx_dolivoucher_series_year (entity, sequence_year);
ALTER TABLE llx_dolivoucher_series ADD CONSTRAINT fk_dolivoucher_series_portfolio FOREIGN KEY (fk_portfolio) REFERENCES llx_dolivoucher_portfolio(rowid);
