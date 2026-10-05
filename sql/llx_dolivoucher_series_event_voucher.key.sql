ALTER TABLE llx_dolivoucher_series_event_voucher ADD UNIQUE INDEX uk_dolivoucher_event_voucher (fk_event, fk_voucher);
ALTER TABLE llx_dolivoucher_series_event_voucher ADD INDEX idx_dolivoucher_coverage (entity, fk_series, event_type, fk_voucher);
ALTER TABLE llx_dolivoucher_series_event_voucher ADD CONSTRAINT fk_dolivoucher_eventline_event FOREIGN KEY (fk_event) REFERENCES llx_dolivoucher_series_event(rowid);
ALTER TABLE llx_dolivoucher_series_event_voucher ADD CONSTRAINT fk_dolivoucher_eventline_voucher FOREIGN KEY (fk_voucher) REFERENCES llx_dolivoucher_voucher(rowid);
