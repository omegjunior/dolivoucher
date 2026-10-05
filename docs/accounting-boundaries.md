# Accounting and integration boundaries

Phase 1 is a subledger for voucher custody and audit, not a final statutory-accounting implementation. It does not decide SYSCOHADA accounts, revenue recognition or VAT timing.

Phase 2 series generation, PDF printing, physical preparation and delivery are also strictly non-financial. They do not reserve portfolio funds, activate or consume a voucher, recognize revenue or tax, create inventory, or release an obligation. Only the explicit Phase 1 activation service reserves value.

It creates no Dolibarr product for face value, stock movement, quotation discount, negative quotation line, customer invoice mutation, invoice payment, bank account, accounting entry or TakePOS integration. The printed support remains distinct from the voucher face value.

Future phases may consume the operation journal through explicit services or hooks. Invoice/payment, TakePOS and accounting adapters must be independently authorized, idempotent and reversible, and must preserve the phase 1 journal rather than rewriting it.
