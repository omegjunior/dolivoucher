# DoliVoucher changelog

## 0.5.0-dev

- Add an opt-in, module-only TakePOS voucher-payment saga using native delayed validation.
- Persist idempotent technical intentions so interrupted payments remain visible and recoverable.
- Reuse the Phase 3A settlement engine with `TAKEPOS` as the controlled request source.
- Add TakePOS configuration, rights-aware hooks, a dedicated panel and server-side safeguards.
- Keep `DVOUCH` inactive in native payment selectors so cashiers cannot bypass voucher identification or the saga.
- Keep TakePOS stock handling native and create no bank line or accounting entry.

## 0.4.0-dev

- Add read-only settlement list, detail and cross-domain reconciliation diagnostics.
- Add stable diagnostic levels/codes, bounded entity-scoped analysis and formula-safe CSV export.
- Add a restrictive diagnostic permission, dedicated menus and configurable analysis ceiling.
- Expose controlled reversal management from paid invoice cards and display reversal reasons in invoice and voucher histories.

## 0.3.0-dev

- Add atomic voucher settlement of eligible customer invoices through native Dolibarr payments.
- Add the autonomous `DVOUCH` payment mode without a bank entry.
- Add an append-only invoice settlement link, request idempotence and multi-entity controls.
- Add invoice-card integration, reciprocal audit links and controlled reversal.
- Block direct deletion of native payments while an active DoliVoucher settlement exists.

## 0.2.0-dev

- Add entity/year-safe series numbering and atomic draft voucher generation.
- Add native Code 128 A4 PDF documents with whole, range and explicit selections.
- Add append-only material events, document revisions, SHA-256 and exact coverage.
- Add restrictive print/reprint/preparation/delivery rights, UI, tests and documentation.

## 0.1.0-dev

- Add institutional and donation portfolios with exact unallocated balances.
- Add serialized voucher lifecycle and manual consumption.
- Add append-only audit journal, compensation and atomic remainder transfers.
- Add multi-entity controls, dedicated permissions, user interfaces, tests and documentation.
