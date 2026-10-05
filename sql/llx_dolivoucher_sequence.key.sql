ALTER TABLE llx_dolivoucher_sequence ADD UNIQUE INDEX uk_dolivoucher_sequence_scope (entity, sequence_type, sequence_year);
