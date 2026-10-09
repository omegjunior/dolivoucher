# DoliVoucher

DoliVoucher is a Dolibarr 22 module for managing funded voucher portfolios, serialized vouchers, series and their immutable financial and material histories. It targets PHP 8.1+, MariaDB 10.11 and XOF without modifying Dolibarr core or adding dependencies.

## Concepts

A physical support is only a carrier. A voucher is a serialized financial entitlement with its own balance. A portfolio is the funded envelope from which voucher value is reserved at activation.

- `available_unallocated_balance`: funded money not yet reserved for a voucher; this alone can fund activation or a remainder transfer.
- `immediately_redeemable_voucher_balance`: sum of `current_balance` for active and partially consumed vouchers; blocked vouchers are excluded from immediate use.
- `outstanding_voucher_balance`: sum of balances for active, partially consumed and blocked vouchers; blocking never extinguishes LND's obligation.
- `global_outstanding_balance`: unallocated balance plus outstanding voucher balance; calculated, not stored.
- expired unreallocated balance: expired voucher balances, reported separately for audit and never credited automatically.

Drafting or preparing a voucher has no financial effect. Activation reserves its face value. Consumption reduces only the voucher. Eligible cancellation restores the reservation. Expiration never restores it automatically.

## Installation

Prerequisites: You must have Dolibarr ERP & CRM software installed. You can download it from [Dolistore.org](https://www.dolibarr.org). You can also get a ready-to-use instance in the cloud from <https://saas.dolibarr.org>.

Place this directory at `htdocs/custom/dolivoucher`, then:

1. Log into Dolibarr as a super-administrator.
2. Go to **Setup > Modules**.
3. Enable DoliVoucher and allocate its nineteen permissions, including the restrictive invoice-use, settlement-reversal, reprint and delivery rights.

The provisional module ID is `501116` and must be reserved or replaced before public distribution.

See [user documentation](docs/user.md), [developer documentation](docs/developer.md), [database schema](docs/database-schema.md), [accounting boundaries](docs/accounting-boundaries.md), and [tests](docs/tests.md).

## Series and physical supports

Phase 2 generates a series atomically with references `DVS-YYYY-NNNNNN` and voucher numbers `DVS-YYYY-NNNNNN-NNNNNN`. Each entity has an independent yearly series counter. Generated vouchers remain financial drafts with a zero balance: printing, preparation and delivery never reserve or move money.

The A4 portrait PDF contains four approximately A6 vouchers per page and native Code 128 barcodes whose payload is exactly the immutable voucher reference. Whole-series, continuous-range and explicit-list printing are supported. Print coverage is calculated per voucher; a series becomes printed only after every generated voucher has appeared in a successful document. Reprints require a dedicated right and a reason, and are stored with revision, file name, SHA-256 and user/date metadata in a separate append-only material journal.

The administrator can configure `DOLIVOUCHER_MAX_VOUCHERS_PER_SERIES` and `DOLIVOUCHER_MAX_VOUCHERS_PER_PDF` (defaults: 1000; technical local-counter ceiling: 999999).

## Customer invoice settlement

Phase 3A applies an active voucher to a validated standard customer invoice in XOF. One operation atomically creates an append-only DoliVoucher consumption, a native Dolibarr customer payment using the autonomous `DVOUCH` payment mode, and an immutable link between voucher, operation, invoice and payment. The native payment controls the invoice remainder; the DoliVoucher journal controls the voucher balance. No bank line is created.

Partial use, several vouchers on one invoice, reuse of a remaining voucher balance on another invoice and combination with other payment methods are supported. Multicurrency, deposit and situation invoices are excluded. A controlled reversal deletes only the linked unbanked/unreconciled/unexported native payment, restores the voucher by compensation and appends a reversal link. Direct deletion of an active linked payment is blocked.

## Boundaries

No product, stock movement, discount, TakePOS integration, bank movement, accounting entry, VAT decision or SYSCOHADA mapping is created. Phase 3A creates a native invoice payment but deliberately leaves accounting mapping to a later validated phase. Material preparation or delivery is not financial activation or consumption.
