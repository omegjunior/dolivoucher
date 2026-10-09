# Database schema

All amounts are `DECIMAL(24,8)` and all tables are InnoDB.

## `llx_dolivoucher_portfolio`

Stores the entity, unique entity/reference pair, label, type, optional third party and period, lifecycle status, notes and audit metadata. `available_unallocated_balance` is the materialized amount available for activation or transfer.

## `llx_dolivoucher_voucher`

Stores the entity, portfolio, nullable series, unique entity/serial pair, optional unique entity/barcode pair, face value, materialized voucher balance, lifecycle status, beneficiary and dates. `fk_series` is nullable so existing and unit-created vouchers remain valid. Series/portfolio/entity consistency is enforced by the service.

## `llx_dolivoucher_series`

Stores the entity, immutable reference, idempotency key, frozen sequence year, portfolio, label, requested quantity and face value, optional expiration, administrative status and calculated material coverage counters. References and generation keys are unique per entity.

## `llx_dolivoucher_sequence`

Transactional counter isolated by entity, sequence type and year. The unique scope and `SELECT ... FOR UPDATE` prevent concurrent duplication. A rollback restores the counter reservation.

## `llx_dolivoucher_series_event`

Append-only material journal for generation, first print, complement, reprint, preparation and delivery. Print events retain revision, controlled relative file path, SHA-256, selection summary, count, reason, user and timestamp. Its application object rejects create, update and delete; writes are private to the service.

## `llx_dolivoucher_series_event_voucher`

Append-only event detail linking each material event to its exact vouchers. It provides unambiguous print/preparation/delivery coverage for whole, range and explicit selections without parsing textual lists. A unique event/voucher index prevents duplicates.

## `llx_dolivoucher_operation`

Append-only journal with operation UUID, portfolio, optional voucher, positive amount, before/after balances, transfer endpoints, future native-object link, reason, timestamps and optional `reversal_of`. A unique index on `reversal_of` prevents double compensation. Transfer pairs intentionally share a non-unique UUID.

Portfolio reconstruction is:

`FUND_NEW + CARRYOVER_IN + TRANSFER_IN + CANCEL - ACTIVATE - TRANSFER_OUT`

Voucher reconstruction is:

`ACTIVATE + CORRECTION - CONSUME - CANCEL`

`ISSUE`, block, unblock and expiration are audit events with no balance effect. The service compares each reconstruction with its materialized field and reports divergence without updating it.

## `llx_dolivoucher_invoice_settlement`

Append-only Phase 3A bridge between one DoliVoucher operation, one voucher, one customer invoice and one native payment. APPLY and REVERSAL are separate rows. It stores a UUID, an entity-scoped idempotency key, exact covered amount, source, user/date and invoice/payment reference snapshots so audit remains readable after an authorized reversal removes the native payment.

Unique indexes enforce `(entity, settlement_uuid)`, `(entity, idempotency_key)`, one settlement per `fk_operation`, and one reversal per `reversal_of`. Entity-prefixed indexes cover invoice, payment, voucher and creation date. Foreign keys are limited to module-owned voucher, operation and self-reversal rows; native invoice/payment identifiers deliberately remain durable audit references without cascade deletion.

The `DVOUCH` row in `llx_c_paiement` is an autonomous type-2 payment mode owned by the module. It creates no bank entry and is distinct from any payment code owned by another module.

Exposure values are calculated, not stored:

- immediately redeemable voucher balance: voucher statuses `ACTIVE` and `PARTIALLY_CONSUMED`;
- blocked voucher balance: status `BLOCKED`;
- outstanding voucher balance: `ACTIVE`, `PARTIALLY_CONSUMED` and `BLOCKED`;
- expired unreallocated balance: status `EXPIRED`, retained separately for audit;
- global outstanding balance: `available_unallocated_balance + outstanding_voucher_balance`.

The exposure reconciliation aggregates each voucher's journal reconstruction under its current status. Block and unblock entries have zero monetary effect, so they only move an unchanged balance between the immediately redeemable and blocked reporting categories.

Indexes cover entity/status, references, third party, portfolio, voucher, operation type/UUID, dates, expiration and external reference. Entity compatibility is enforced in the service because cross-table composite foreign keys would conflict with Dolibarr entity-sharing practices.

Phase 2 additionally indexes series references, generation keys, portfolio/status/year, sequence scope, material event UUID/revision/type/date, and event coverage. The replayable `llx_dolivoucher_voucher_phase2.sql` migration adds nullable `fk_series` without rewriting historical records or replacing Phase 1 unique constraints.
