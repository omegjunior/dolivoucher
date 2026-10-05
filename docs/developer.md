# Developer guide

## Architecture

- `DoliVoucherPortfolio` and `DoliVoucherVoucher` extend `CommonObject` and enforce creation, update and deletion invariants.
- `DoliVoucherOperation` exposes journal entries as read-only objects and rejects create, update and delete.
- `DoliVoucherService` is the only journal writer and owns all balance-changing transactions.
- `DoliVoucherMoney` normalizes and compares fixed-scale decimal strings without float decisions.
- `DoliVoucherSeries` represents the immutable administrative batch; its writes are reserved to `DoliVoucherSeriesService`.
- `DoliVoucherSeriesEvent` is the read-only view of the separate append-only material journal.
- `DoliVoucherSeriesService` owns yearly counter allocation, atomic batch insertion, PDF/event consistency and material coverage.

All record access is scoped to `$conf->entity`. Mutation pages check a dedicated right and a Dolibarr CSRF token. SQL identifiers are fixed; identifiers are cast and text is escaped through `DoliDB`.

## Transaction order

Activation and cancellation resolve the voucher's portfolio, then lock the portfolio before the voucher. Transfers lock both portfolio row IDs in ascending order. Each operation follows `begin`, row reload with `FOR UPDATE`, invariant checks, journal append, balance update and `commit`; every exception executes `rollback`.

`ISSUE` has no financial effect. `ACTIVATE` debits the portfolio and initializes the voucher. `CONSUME` debits only the voucher. `CANCEL` zeros the voucher and credits the portfolio. Block/unblock/expiration preserve balances. Transfer creates `TRANSFER_OUT` and `TRANSFER_IN` with one UUID.

Reporting separates operational availability from financial exposure. Statuses active and partially consumed feed `immediately_redeemable_voucher_balance`; those statuses plus blocked feed `outstanding_voucher_balance`. `global_outstanding_balance` adds the latter to `available_unallocated_balance`. Expired balances are excluded from current outstanding exposure and reported as `expired_unreallocated_balance`. `checkPortfolioExposureBalances()` reconstructs each aggregate from the journal and reports, but never repairs, divergence.

## Extension points

Future invoice, payment, TakePOS and accounting integrations must call the service rather than changing balances directly. They should populate `object_type`, `fk_object`, `external_ref` and use idempotency rules before automatic posting is introduced. No core override or native-object trigger is installed in phase 1.

## Series invariants

The standard numbering model only formats a number reserved by the service. The service initializes the `(entity, sequence_type, sequence_year)` counter row, locks it with `FOR UPDATE`, reserves the value and creates the series and vouchers in one SQL transaction. `generation_key` makes a successful HTTP replay idempotent. No `MAX()+1` allocation is used.

Generated vouchers reuse `ref` as the business serial, copy it into `barcode`, keep `fk_series` nullable for unit and historical vouchers, and start as `DRAFT` with `current_balance = 0`. Series references, serials, barcodes, face values and portfolio links are immutable. Financial aggregates never inspect a material status.

PDF generation uses Dolibarr's bundled TCPDF with Code 128. The series row remains locked while the revision is allocated, the file is produced and hashed, the event and voucher-event lines are inserted, and coverage is refreshed. The event is inserted only after a non-empty file exists. Any SQL failure rolls back and removes that exact newly-created file. Document downloads recheck entity and rights and verify that the resolved path stays under the module output directory.

First print, first-print complement and reprint are distinct events. A request mixing previously printed and never-printed vouchers is rejected. Preparation and delivery currently apply to the complete series only, which provides exact traceability without pretending to support partial delivery.
