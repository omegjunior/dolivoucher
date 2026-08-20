# Developer guide

## Architecture

- `DoliVoucherPortfolio` and `DoliVoucherVoucher` extend `CommonObject` and enforce creation, update and deletion invariants.
- `DoliVoucherOperation` exposes journal entries as read-only objects and rejects create, update and delete.
- `DoliVoucherService` is the only journal writer and owns all balance-changing transactions.
- `DoliVoucherMoney` normalizes and compares fixed-scale decimal strings without float decisions.

All record access is scoped to `$conf->entity`. Mutation pages check a dedicated right and a Dolibarr CSRF token. SQL identifiers are fixed; identifiers are cast and text is escaped through `DoliDB`.

## Transaction order

Activation and cancellation resolve the voucher's portfolio, then lock the portfolio before the voucher. Transfers lock both portfolio row IDs in ascending order. Each operation follows `begin`, row reload with `FOR UPDATE`, invariant checks, journal append, balance update and `commit`; every exception executes `rollback`.

`ISSUE` has no financial effect. `ACTIVATE` debits the portfolio and initializes the voucher. `CONSUME` debits only the voucher. `CANCEL` zeros the voucher and credits the portfolio. Block/unblock/expiration preserve balances. Transfer creates `TRANSFER_OUT` and `TRANSFER_IN` with one UUID.

Reporting separates operational availability from financial exposure. Statuses active and partially consumed feed `immediately_redeemable_voucher_balance`; those statuses plus blocked feed `outstanding_voucher_balance`. `global_outstanding_balance` adds the latter to `available_unallocated_balance`. Expired balances are excluded from current outstanding exposure and reported as `expired_unreallocated_balance`. `checkPortfolioExposureBalances()` reconstructs each aggregate from the journal and reports, but never repairs, divergence.

## Extension points

Future invoice, payment, TakePOS and accounting integrations must call the service rather than changing balances directly. They should populate `object_type`, `fk_object`, `external_ref` and use idempotency rules before automatic posting is introduced. No core override or native-object trigger is installed in phase 1.
