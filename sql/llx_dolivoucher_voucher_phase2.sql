ALTER TABLE llx_dolivoucher_voucher ADD COLUMN IF NOT EXISTS fk_series INTEGER NULL AFTER fk_portfolio;
ALTER TABLE llx_dolivoucher_voucher ADD INDEX IF NOT EXISTS idx_dolivoucher_voucher_series (fk_series);
