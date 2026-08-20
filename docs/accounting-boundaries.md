# Accounting and integration boundaries

Phase 1 is a subledger for voucher custody and audit, not a final statutory-accounting implementation. It does not decide SYSCOHADA accounts, revenue recognition or VAT timing.

It creates no Dolibarr product for face value, stock movement, quotation discount, negative quotation line, customer invoice mutation, invoice payment, bank account, accounting entry or TakePOS integration. Printed supports, if managed later, must remain distinct from voucher face value.

Future phases may consume the operation journal through explicit services or hooks. Invoice/payment, TakePOS and accounting adapters must be independently authorized, idempotent and reversible, and must preserve the phase 1 journal rather than rewriting it.
