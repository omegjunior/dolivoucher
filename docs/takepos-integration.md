# TakePOS integration

## Architecture

Phase 4 is an opt-in module-only saga. DoliVoucher prepares an entity-scoped intention without reserving money, authorizes native TakePOS validation with `pay=delayed&amount=0`, verifies the persisted invoice, and finally calls the Phase 3A settlement engine with `request_source=TAKEPOS`.

Native TakePOS owns invoice validation, stock, batches, warehouse selection and its triggers. DoliVoucher never copies those treatments. The second transaction atomically creates the unbanked native `DVOUCH` payment and allocation, voucher consumption, append-only operation and canonical settlement.

## Configuration and permissions

- `DOLIVOUCHER_TAKEPOS_ENABLED=0` by default.
- `DOLIVOUCHER_TAKEPOS_MAX_VOUCHERS_PER_SALE=10`, server range 1 to 100.
- `DOLIVOUCHER_TAKEPOS_INTENT_TTL_MINUTES=30`, server range 1 to 1440.
- `TAKEPOS_DELAYED_TERMS` must be zero or absent. DoliVoucher never changes it.
- Operators need native TakePOS execution, native customer-payment and DoliVoucher settlement-use rights. Reversal keeps its separate restrictive right.

## Recovery and safety

The stable states are `DRAFT`, `VALIDATION_REQUESTED`, `INVOICE_VALIDATED`, `SETTLEMENT_REQUESTED`, `SETTLED`, `SETTLEMENT_FAILED`, `CANCELLED` and `EXPIRED`. A repeated idempotency key with another fingerprint is refused. A second authorization cannot blindly repeat delayed validation while the first request is in progress. A settled intention returns its existing settlement.

If native validation fails, no settlement runs. If settlement fails after validation, the invoice remains validated and payable and the voucher is unchanged. Retrying rechecks invoice and voucher under the Phase 3A locking discipline. Cancelling an unresolved intention changes only its technical state. Lazy expiry concerns only abandoned drafts and performs no financial or stock mutation.

## DoliLnd, tickets and closing

The hook is available in the native popup and in DoliLnd's hook-compatible popup. The **Bon DoliVoucher** action remains separate from `BONACH`; no DoliLnd endpoint, JavaScript, account or journal is reused. This phase relies on the native `DVOUCH` payment label on tickets. A masked voucher reference is deferred because no reliable hook covers every ticket model. Cash-closing totals are also deferred because a trustworthy cash-session dimension is not available without extending the data model.

The `DVOUCH` dictionary entry is deliberately inactive: native TakePOS and DoliLnd therefore cannot expose it as a conventional bank-backed payment button. Only the DoliVoucher panel and settlement service may create a `DVOUCH` payment. Existing `CASHDESK_ID_BANKACCOUNT_DVOUCH*` constants are ignored and should be removed from configuration because DoliVoucher never uses them.

## Browser acceptance guide

Test both native TakePOS and TakePOS with DoliLnd enabled. For each configuration, verify exact Code 128 scan, total and partial use, multiple vouchers, voucher plus cash/card/mobile money, refresh between validation and settlement, retry after a simulated lost response, double-click protection, a voucher becoming unavailable, technical cancellation, controlled reversal, diagnostic filtering by `TAKEPOS`, ticket display and profiles with/without each required right. Confirm database absence of DoliVoucher bank rows and accounting entries after every scenario.

## Safe disablement

Turning off `DOLIVOUCHER_TAKEPOS_ENABLED` hides the action and refuses new endpoint operations. Existing intentions and settlements remain auditable; disabling never deletes or reverses them.
