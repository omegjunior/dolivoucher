# Developer guide

## Architecture

- `DoliVoucherPortfolio` and `DoliVoucherVoucher` extend `CommonObject` and enforce creation, update and deletion invariants.
- `DoliVoucherOperation` exposes journal entries as read-only objects and rejects create, update and delete.
- `DoliVoucherService` is the only journal writer and owns all balance-changing transactions.
- `DoliVoucherMoney` normalizes and compares fixed-scale decimal strings without float decisions.
- `DoliVoucherSeries` represents the immutable administrative batch; its writes are reserved to `DoliVoucherSeriesService`.
- `DoliVoucherSeriesEvent` is the read-only view of the separate append-only material journal.
- `DoliVoucherSeriesService` owns yearly counter allocation, atomic batch insertion, PDF/event consistency and material coverage.
- `DoliVoucherInvoiceSettlement` is the read-only append-only bridge event between a voucher operation and native invoice/payment objects.
- `DoliVoucherInvoiceSettlementService` is the only Phase 3A writer. It orchestrates a native `Paiement`, the existing consumption primitives and the bridge table inside one outer DoliDB transaction.

All record access is scoped to `$conf->entity`. Mutation pages check a dedicated right and a Dolibarr CSRF token. SQL identifiers are fixed; identifiers are cast and text is escaped through `DoliDB`.

## Transaction order

Activation and cancellation resolve the voucher's portfolio, then lock the portfolio before the voucher. Transfers lock both portfolio row IDs in ascending order. Each operation follows `begin`, row reload with `FOR UPDATE`, invariant checks, journal append, balance update and `commit`; every exception executes `rollback`.

`ISSUE` has no financial effect. `ACTIVATE` debits the portfolio and initializes the voucher. `CONSUME` debits only the voucher. `CANCEL` zeros the voucher and credits the portfolio. Block/unblock/expiration preserve balances. Transfer creates `TRANSFER_OUT` and `TRANSFER_IN` with one UUID.

Reporting separates operational availability from financial exposure. Statuses active and partially consumed feed `immediately_redeemable_voucher_balance`; those statuses plus blocked feed `outstanding_voucher_balance`. `global_outstanding_balance` adds the latter to `available_unallocated_balance`. Expired balances are excluded from current outstanding exposure and reported as `expired_unreallocated_balance`. `checkPortfolioExposureBalances()` reconstructs each aggregate from the journal and reports, but never repairs, divergence.

## Invoice settlement transaction

The hybrid architecture has two explicit truths: `llx_dolivoucher_operation` is authoritative for voucher value and `llx_paiement` plus `llx_paiement_facture` are authoritative for the invoice remainder. `llx_dolivoucher_invoice_settlement` is immutable evidence tying both domains together.

Application locks are acquired in this order: idempotency key range, invoice row, invoice payment allocations, voucher row. A request then rechecks entity, invoice eligibility, remainder, voucher status/expiration/balance and caps the amount at the lower balance. The remainder is recomputed with a locking current read, including native payment allocations and applied credit/deposit amounts; using only `Facture::getRemainToPay()` here is unsafe under MariaDB `REPEATABLE READ` after a concurrent wait because it may use an older consistent snapshot. The service creates one native payment for one voucher/invoice operation, consumes through `DoliVoucherService::consumeVoucherInTransaction()`, appends the APPLY link, closes a zero-remainder invoice and commits. The nested transaction used by `Paiement::create()` remains controlled by the outer DoliDB transaction. No bank method is called and `fk_bank` is asserted to remain zero.

The unique `(entity, idempotency_key)` constraint makes successful HTTP replay return the existing result. Unique `fk_operation` and `reversal_of` prevent ambiguous links and double reversal. The deletion trigger refuses `PAYMENT_CUSTOMER_DELETE` for an unreversed APPLY event. Controlled reversal uses a private `try/finally` context, rejects banked, reconciled or accounting-exported payments, reopens the invoice when appropriate, deletes the native payment, calls the existing compensation primitive, appends REVERSAL and commits atomically.

The validation-only hook `beforeDoliVoucherInvoiceSettlement` in context `dolivoucherinvoice` runs after both locked objects and the maximum amount have been checked but before any write. Future portfolio/product/customer restrictions may refuse the operation through this hook; they must not write data or implement a parallel consumption path.

## Extension points

Future TakePOS integration must call the Phase 3A settlement service with a future controlled request source rather than implementing another consumption engine. Accounting integration may map the `DVOUCH` payment mode only after explicit SYSCOHADA/TVA decisions. No core override is installed; invoice UI integration uses the native `invoicecard` hook and deletion integrity uses a module trigger.

## Read-only settlement diagnostics

`DoliVoucherSettlementDiagnosticService` is the sole definition of Phase 3B reconciliation rules. It joins the canonical settlement with voucher, operation, invoice, payment mode, payment allocation, reversal and compensating operation in bounded SQL batches. Pages only render its structured checks; they do not redefine anomaly rules. Cross-entity rows expose only identifiers and an `ENTITY_MISMATCH`, never the other entity's labels.

The service emits stable `OK`, `WARNING` and `ERROR` levels and technical codes documented in `settlement-diagnostics.md`. Active APPLY events require one live `DVOUCH` payment and one matching allocation. Controlled reversals require exactly one REVERSAL, a matching CORRECTION operation and the absence of the deleted native payment. Journal balance deltas are checked without comparing a single settlement directly to the voucher's current balance, which may include later operations.

`TAKEPOS` is recognized as a reserved diagnostic source, but Phase 3B contains no writer or TakePOS hook. The Phase 3A writer continues to accept only `INVOICE_CARD`. CSV output shares the same service, entity scope, filters and configured row ceiling as the global page.

## Series invariants

The standard numbering model only formats a number reserved by the service. The service initializes the `(entity, sequence_type, sequence_year)` counter row, locks it with `FOR UPDATE`, reserves the value and creates the series and vouchers in one SQL transaction. `generation_key` makes a successful HTTP replay idempotent. No `MAX()+1` allocation is used.

Generated vouchers reuse `ref` as the business serial, copy it into `barcode`, keep `fk_series` nullable for unit and historical vouchers, and start as `DRAFT` with `current_balance = 0`. Series references, serials, barcodes, face values and portfolio links are immutable. Financial aggregates never inspect a material status.

PDF generation uses Dolibarr's bundled TCPDF with Code 128. The series row remains locked while the revision is allocated, the file is produced and hashed, the event and voucher-event lines are inserted, and coverage is refreshed. The event is inserted only after a non-empty file exists. Any SQL failure rolls back and removes that exact newly-created file. Document downloads recheck entity and rights and verify that the resolved path stays under the module output directory.

First print, first-print complement and reprint are distinct events. A request mixing previously printed and never-printed vouchers is rejected. Preparation and delivery currently apply to the complete series only, which provides exact traceability without pretending to support partial delivery.
