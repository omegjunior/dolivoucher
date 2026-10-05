ALTER TABLE llx_dolivoucher_series_event ADD UNIQUE INDEX uk_dolivoucher_series_event_uuid (entity, event_uuid);
ALTER TABLE llx_dolivoucher_series_event ADD UNIQUE INDEX uk_dolivoucher_series_revision (entity, fk_series, revision);
ALTER TABLE llx_dolivoucher_series_event ADD INDEX idx_dolivoucher_series_event_series (fk_series);
ALTER TABLE llx_dolivoucher_series_event ADD INDEX idx_dolivoucher_series_event_type_date (entity, event_type, date_event);
ALTER TABLE llx_dolivoucher_series_event ADD CONSTRAINT fk_dolivoucher_series_event_series FOREIGN KEY (fk_series) REFERENCES llx_dolivoucher_series(rowid);
