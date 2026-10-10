# Settlement diagnostics

## Purpose and boundaries

Phase 3B provides read-only operational support for the hybrid invoice settlement introduced in Phase 3A. `llx_dolivoucher_invoice_settlement` remains the canonical bridge. The diagnostic compares one bridge event with the voucher, DoliVoucher operation, APPLY/REVERSAL chain, native payment, payment allocation and customer invoice.

It never creates, updates, deletes, compensates or repairs business data. Results describe the observed database state; administrators must investigate the cause before any separately authorized business action.

## Levels

- `OK`: all required objects, entities, amounts, operation types and relationships agree.
- `WARNING`: the chain remains coherent but needs review, for example an accounting-export marker or a native reference that differs from its preserved snapshot.
- `ERROR`: a demonstrated integrity break, such as a missing active payment, amount mismatch, unexpected bank line or invalid reversal chain.

The global level is the highest severity among the settlement's checks.

## Stable controls

Core codes are `SETTLEMENT_OK`, `VOUCHER_MISSING`, `OPERATION_MISSING`, `INVOICE_MISSING`, `PAYMENT_MISSING`, `ALLOCATION_MISSING`, `AMOUNT_MISMATCH`, `ENTITY_MISMATCH`, `UNEXPECTED_BANK_LINE`, `PAYMENT_MODE_MISMATCH`, `REVERSAL_MISSING`, `MULTIPLE_REVERSALS`, `ORPHAN_REVERSAL`, `INVALID_REQUEST_SOURCE` and `GENERIC_COMPENSATION_DETECTED`.

Additional precise codes distinguish an allocation pointing to another invoice, an invalid journal link or type, a missing compensating operation, a payment retained after reversal, changed native references, and exported or reconciled payments. French and English language files provide the user-facing descriptions; pages never duplicate the rules.

## Reversal interpretation

An active APPLY requires its `DVOUCH` payment and one allocation to the canonical invoice. A reversed APPLY requires exactly one REVERSAL, a matching `CORRECTION` operation whose `reversal_of` points to the APPLY consumption, matching voucher/invoice/amount, and no remaining native payment. The REVERSAL row must point back to the same APPLY.

The diagnostic checks journal amounts and before/after deltas. It does not infer reversal correctness from the voucher's current balance alone because later consumptions or corrections may legitimately have changed that balance.

## Interfaces and permissions

The existing audit-read right grants the settlement list and settlement card in the current entity. The new restrictive diagnostic right grants global analysis, technical idempotency details and CSV export; it grants no settlement use or reversal right.

The list supports Dolibarr pagination, sorting, remembered columns and filters for period, UUID, idempotency key when authorized, event, lifecycle, source, voucher reference/barcode, invoice, payment, operation, amount, diagnostic level and code. The global page summarizes levels and groups anomalies by code.

## Performance and multi-entity isolation

`DOLIVOUCHER_DIAGNOSTIC_MAX_ROWS` defaults to 1000 and is restricted to 1–10000. The server always applies the configured ceiling even if a browser submits a larger value. Joins and aggregate derived tables avoid one query per settlement. Existing Phase 3A indexes cover the bounded access paths; Phase 3B adds no redundant index.

Every root query requires `settlement.entity = current entity`. A linked object from another entity produces `ENTITY_MISMATCH`; its label or reference is not returned to the interface or CSV.

## CSV safety

CSV export uses the same filters, entity scope, diagnostic service and configured ceiling. It emits UTF-8 with translated headers. Values beginning with `=`, `+`, `-` or `@` are prefixed with an apostrophe so spreadsheet software does not execute them as formulas.

## TakePOS preparation

`INVOICE_CARD` is the only current functional writer source. `TAKEPOS` is reserved and recognized by diagnostics so a future adapter can reuse the Phase 3A transaction service. Phase 3B does not add a TakePOS hook, button, endpoint or payment path.
