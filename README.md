# DoliVoucher

DoliVoucher is a Dolibarr 22 module for managing funded voucher portfolios, serialized vouchers and their immutable operation history. Phase 1 targets PHP 8.1+, MariaDB 10.11 and XOF without modifying Dolibarr core or adding dependencies.

## Concepts

A physical support is only a carrier. A voucher is a serialized financial entitlement with its own balance. A portfolio is the funded envelope from which voucher value is reserved at activation.

- `available_unallocated_balance`: funded money not yet reserved for a voucher; this alone can fund activation or a remainder transfer.
- `immediately_redeemable_voucher_balance`: sum of `current_balance` for active and partially consumed vouchers; blocked vouchers are excluded from immediate use.
- `outstanding_voucher_balance`: sum of balances for active, partially consumed and blocked vouchers; blocking never extinguishes LND's obligation.
- `global_outstanding_balance`: unallocated balance plus outstanding voucher balance; calculated, not stored.
- expired unreallocated balance: expired voucher balances, reported separately for audit and never credited automatically.

Drafting or preparing a voucher has no financial effect. Activation reserves its face value. Consumption reduces only the voucher. Eligible cancellation restores the reservation. Expiration never restores it automatically.

## Installation

Place this directory at `htdocs/custom/dolivoucher`, enable it from Dolibarr's module administration and allocate its eleven permissions. The provisional module ID is `501116` and must be reserved or replaced before public distribution.

See [user documentation](docs/user.md), [developer documentation](docs/developer.md), [database schema](docs/database-schema.md), [accounting boundaries](docs/accounting-boundaries.md), and [tests](docs/tests.md).

## Phase 1 boundaries

No product, stock movement, invoice payment, discount, TakePOS integration, accounting entry, VAT decision or SYSCOHADA mapping is created.
