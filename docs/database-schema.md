# Database schema

All amounts are `DECIMAL(24,8)` and all tables are InnoDB.

## `llx_dolivoucher_portfolio`

Stores the entity, unique entity/reference pair, label, type, optional third party and period, lifecycle status, notes and audit metadata. `available_unallocated_balance` is the materialized amount available for activation or transfer.

## `llx_dolivoucher_voucher`

Stores the entity, portfolio, unique entity/serial pair, optional unique entity/barcode pair, face value, materialized voucher balance, lifecycle status, beneficiary and dates. The portfolio foreign key prevents an orphan.

## `llx_dolivoucher_operation`

Append-only journal with operation UUID, portfolio, optional voucher, positive amount, before/after balances, transfer endpoints, future native-object link, reason, timestamps and optional `reversal_of`. A unique index on `reversal_of` prevents double compensation. Transfer pairs intentionally share a non-unique UUID.

Portfolio reconstruction is:

`FUND_NEW + CARRYOVER_IN + TRANSFER_IN + CANCEL - ACTIVATE - TRANSFER_OUT`

Voucher reconstruction is:

`ACTIVATE + CORRECTION - CONSUME - CANCEL`

`ISSUE`, block, unblock and expiration are audit events with no balance effect. The service compares each reconstruction with its materialized field and reports divergence without updating it.

Exposure values are calculated, not stored:

- immediately redeemable voucher balance: voucher statuses `ACTIVE` and `PARTIALLY_CONSUMED`;
- blocked voucher balance: status `BLOCKED`;
- outstanding voucher balance: `ACTIVE`, `PARTIALLY_CONSUMED` and `BLOCKED`;
- expired unreallocated balance: status `EXPIRED`, retained separately for audit;
- global outstanding balance: `available_unallocated_balance + outstanding_voucher_balance`.

The exposure reconciliation aggregates each voucher's journal reconstruction under its current status. Block and unblock entries have zero monetary effect, so they only move an unchanged balance between the immediately redeemable and blocked reporting categories.

Indexes cover entity/status, references, third party, portfolio, voucher, operation type/UUID, dates, expiration and external reference. Entity compatibility is enforced in the service because cross-table composite foreign keys would conflict with Dolibarr entity-sharing practices.
