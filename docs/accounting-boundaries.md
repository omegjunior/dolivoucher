# Accounting and integration boundaries

Phase 1 is a subledger for voucher custody and audit, not a final statutory-accounting implementation. It does not decide SYSCOHADA accounts, revenue recognition or VAT timing.

Phase 2 series generation, PDF printing, physical preparation and delivery are also strictly non-financial. They do not reserve portfolio funds, activate or consume a voucher, recognize revenue or tax, create inventory, or release an obligation. Only the explicit Phase 1 activation service reserves value.

Phase 3A creates a native Dolibarr customer payment and allocation so the invoice remainder, payment lists and mixed settlements stay native. It uses the dedicated `DVOUCH` payment mode and creates no bank line or fictitious bank account. The payment is a representation of voucher coverage, not a second debit of the voucher; the DoliVoucher CONSUME operation remains the subledger movement.

It creates no Dolibarr product for face value, stock movement, quotation discount, negative quotation line, bank account, accounting entry or TakePOS integration. The printed support remains distinct from the voucher face value. No SYSCOHADA account, revenue-recognition rule, VAT timing or accounting export mapping is inferred. Payments already banked, reconciled or exported cannot be reversed by Phase 3A.

Future TakePOS and accounting adapters must reuse the settlement service, remain independently authorized and idempotent, and preserve both append-only journals rather than rewriting them.

Phase 3B is observational only. Its diagnostic service and CSV export never create a payment, allocation, bank line, journal operation, accounting entry, compensation or repair. A diagnostic warning or error is a support signal, not an accounting adjustment instruction.
