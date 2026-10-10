# User guide

## Portfolio and voucher lifecycle

Create a portfolio as `INSTITUTIONAL` (a valid funding third party is mandatory) or `DONATION`. Validate it, register new funding or a prior-period remainder, and optionally activate it.

Create a voucher in a validated or active portfolio. Draft and prepared vouchers reserve nothing. Activation reserves the full face value from the portfolio's unallocated balance and makes that value available on the voucher. A voucher may then be partially or fully consumed, blocked and unblocked.

An active or blocked voucher can be canceled only if it has never been consumed and its balance still equals its initial amount. Cancellation returns the reservation to the portfolio. Expiration does not return funds automatically.

## Availability and financial exposure

- Unallocated balance: funding that has not been reserved. It can activate vouchers or be transferred.
- Immediately redeemable voucher balance: balances of active and partially consumed vouchers.
- Blocked voucher balance: temporarily unusable balances that remain owed by LND.
- Outstanding unsettled voucher balance: active, partially consumed and blocked balances.
- Global portfolio outstanding balance: unallocated balance plus outstanding unsettled voucher balance.
- Expired unreallocated balance: expired balances excluded from current outstanding amounts but retained separately for audit because expiration does not credit the portfolio automatically.

Draft and prepared vouchers are absent because no value has been reserved. Consumed, canceled and expired vouchers are excluded from immediately redeemable and unsettled balances. These values appear separately on the portfolio card. A reconciliation warning means a materialized balance or exposure aggregate differs from the journal; contact an administrator because the module never silently repairs it.

## Remainder transfer

Only unallocated value can be transferred. Portfolios must be in the same entity and have the same type; institutional portfolios must have the same third party. Both journal movements share one UUID and commit atomically. Active vouchers remain attached to their original portfolio.

## Corrections

Journal entries cannot be edited or deleted. Phase 1 can compensate one consumption once, crediting the voucher through a linked correction. Transfers are corrected by an explicit controlled reverse transfer.

## Voucher series and printing

Open **Voucher series > New series**, select a validated or active portfolio, a quantity, a face value and an optional expiration date. Generation creates all vouchers as financial drafts. It does not reserve the portfolio and does not activate any voucher.

Series references use `DVS-YYYY-NNNNNN`; each voucher adds its local six-digit number. Its Code 128 barcode contains only that immutable serial. The PDF is A4 portrait with four vouchers per page, issuer information, value/currency, series, portfolio label when present, expiration when present and the human-readable barcode.

Printing can cover the whole series, a continuous local-number range, or an explicit list of complete voucher references. A partial first print shows its coverage but does not mark the series fully printed. Never-printed vouchers and reprints must be submitted separately. A reprint requires the dedicated permission and a mandatory reason.

After full print coverage, authorized users may record full physical preparation and then full delivery or availability. These material actions do not activate vouchers, move balances, consume value or change the portfolio. Partial preparation/delivery is deliberately not offered in this phase.

Every material event is immutable and visible on the series card. PDF events show their revision and downloadable document; each stored file is tied to its SHA-256 audit fingerprint.

## Paying a customer invoice with a voucher

On an eligible validated standard customer invoice in XOF, use **Use a voucher**, then enter or scan the exact serial number or Code 128 barcode. The barcode identifies the voucher but does not replace user permission or confirmation. The proposed amount is the lower of the voucher balance and invoice remainder; an authorized user may enter a smaller positive amount.

One voucher may be used on several invoices while it has a usable balance, and an invoice may receive several vouchers or other native payments. Each confirmed use appears as a native Dolibarr payment and in the immutable DoliVoucher histories on both the invoice and voucher. Draft, prepared, blocked, consumed, canceled or expired vouchers are refused, as are non-XOF, deposit and situation invoices.

Do not delete the native payment directly. An authorized user must use **Reverse settlement** with a mandatory reason. The controlled action removes an eligible unbanked/unreconciled/unexported payment, restores the voucher through a compensating journal operation and retains the APPLY/REVERSAL audit chain. The same settlement cannot be reversed twice.

The invoice card keeps a **Manage DoliVoucher settlements** action even after the invoice is fully paid. Use it to open the detailed history, enter the mandatory reversal reason and confirm the controlled action. The reversal reason is also displayed in the detailed invoice and voucher histories.

## Settlement diagnostics

Users with the audit permission can open **DoliVoucher settlements**, search by period, event, source, voucher, invoice, payment, operation or amount, and inspect one settlement. The card shows the preserved snapshots and every reconciliation control. A missing object is displayed by its stored identifier or snapshot without a broken link.

The separate restrictive diagnostic permission opens **Settlement diagnostics** and CSV export. The summary separates consistent rows, warnings and demonstrated errors. A warning, such as a changed native reference, requires review but does not necessarily mean the financial chain is broken. An error means an expected object, entity, amount or canonical relationship is demonstrably inconsistent.

The tool is read-only: it never repairs, deletes, compensates or recreates anything. Exported cells that could be interpreted as spreadsheet formulas are neutralized. Results are limited by the configured maximum and never include another entity's confidential labels.
