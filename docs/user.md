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
