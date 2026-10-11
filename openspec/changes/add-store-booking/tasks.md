## 0. Business rules and prerequisites

> **PAUSED（2026-10-11）**：路线 A 范围外，不随多商家改造执行。保留原内容与勾选状态。见 docs/multi-merchant/README.md 决策 D8。

- [ ] 0.1 Answer the five open questions in the proposal (booking horizon and same-day booking, cancellation deadline and reschedule limit, no-show handling, per-customer booking limits, whether stores or the brand configure slots). Implementation must not start before these are settled, because each one changes customer-visible behavior.
- [ ] 0.2 Confirm that the four PHP 8.2 business defects tracked by `harden-runtime-acceptance` are fixed, in particular the `DiyDao::getDiyInfo` callback failure and the `xaboy/form-builder` return-type exception that blocks the admin settings page. Booking cannot be verified end to end on a storefront whose home layout API returns `status=400` and whose admin settings page will not open.
- [ ] 0.3 Decide whether booking ships before the store-attribution change. If it does, booking reads the store identifier from the entry request parameter, which is correct for in-store QR scanning but does not survive a customer reopening the mini program from history.

## 1. Failing tests first

- [ ] 1.1 Add a concurrency test that fires parallel reservation requests at a window with one remaining seat and asserts exactly one success, with the held count equal to capacity. This test is the acceptance criterion for the capacity guard, and it must fail before the guard exists.
- [ ] 1.2 Add tests for capacity release on cancellation and on expiry, including a repeated release attempt that must not change the held count and must never allow it below zero.
- [ ] 1.3 Add a cross-store verification test proving staff of one store cannot verify another store's booking, and that an unauthorized user cannot verify at all.
- [ ] 1.4 Add an idempotency test for slot generation over an already covered date range, asserting no duplicate window and no altered held count.

## 2. Slot model

- [ ] 2.1 Add the booking rule, slot, and booking tables with a unique key on slot (`store_mer_id`, `date`, `start_time`) so generation is idempotent. Alter no existing table.
- [ ] 2.2 Add slot generation from rules over a rolling horizon, derived from each store's `eb_system_store` business hours, and register it with the existing Supervisor-managed timer process.
- [ ] 2.3 Report when the furthest generated date falls below the configured threshold, so a stalled generator cannot silently present an empty calendar.
- [ ] 2.4 Confirm editing or disabling a rule leaves already generated windows and their bookings untouched.

## 3. Reservation with a capacity guard

- [ ] 3.1 Hold capacity with a single conditional `UPDATE ... SET booked = booked + 1 WHERE id = ? AND status = 1 AND booked < capacity`, treating zero affected rows as no remaining capacity. Do not reuse `BaseDao::decStockIncSales()`, which reads with `find()` at line 548 and applies `dec()` at line 550 with no quantity guard and can oversell.
- [ ] 3.2 Create the booking inside the same transaction as the capacity hold, and return the held seat if the insert fails.
- [ ] 3.3 Validate the named store exists and is enabled before holding any capacity.
- [ ] 3.4 Record `store_mer_id` at creation and never recalculate it, so the same customer can book at different stores over time without rewriting earlier attributions.
- [ ] 3.5 Issue a verification code using the existing collision-checked generator, following `StoreOrderCreateServices::getStoreCode()`.
- [ ] 3.6 Confirm booking creates no order and initiates no payment, and leaves order, commission, and distribution records unaffected.

## 4. Arrival verification

- [ ] 4.1 Resolve the verifying staff member through `eb_system_store_staff` requiring `verify_status = 1` and `status = 1`, following `StoreOrderWriteOffServices::writeOffOrder()`.
- [ ] 4.2 Require the staff member's `store_id` to equal the booking's store, and refuse otherwise.
- [ ] 4.3 Record the verifying staff member and verification time, keep the seat consumed, and refuse a second verification without changing the recorded values.
- [ ] 4.4 Link an order created after arrival to the booking for reporting, without coupling the two lifecycles.

## 5. Cancellation and expiry

- [ ] 5.1 Allow customer cancellation within the agreed deadline, releasing capacity with a decrement guarded by `booked > 0`.
- [ ] 5.2 Refuse cancellation after the deadline, leaving the booking pending.
- [ ] 5.3 Expire pending bookings after the grace period following their window and release capacity, registered with the existing timer process.
- [ ] 5.4 Make release idempotent so an overlapping or repeated expiry run releases each booking at most once and never drives the held count negative.

## 6. Surfaces

- [ ] 6.1 Add customer booking creation, cancellation, and history, exposing only that customer's own bookings.
- [ ] 6.2 Add a store booking list scoped to the staff member's own store.
- [ ] 6.3 Add a brand booking list across stores, filterable by store, date, and status.
- [ ] 6.4 Add slot rule and capacity management for whichever party task 0.1 assigns it to.

## 7. Notifications

- [ ] 7.1 Send booking confirmation carrying store, window, and verification code through `MiniProgramService::sendSubscribeTemlate()`.
- [ ] 7.2 Send a single reminder at the configured lead time before the window.
- [ ] 7.3 Confirm a notification failure records the error and leaves the booking and its held capacity intact, rather than surfacing as a booking error.

## 8. Verification

- [ ] 8.1 Confirm the concurrency, release-idempotency, cross-store verification, and generation-idempotency tests from group 1 all pass.
- [ ] 8.2 Confirm application code reports zero PHP 8.2 parse failures and zero runtime deprecations, using the one-file-per-process lint harness rather than `find | xargs php -l`, which aborts the whole batch on the first fatal error.
- [ ] 8.3 Exercise the full customer path against the running stack, asserting business `status=200` in the JSON body rather than accepting HTTP 200 alone. Transport-only acceptance is what let the storefront ship blank once already.
- [ ] 8.4 Confirm the held count equals the number of pending and arrived bookings for every window touched by the test run.
- [ ] 8.5 Scan logs after the exercised workflows for fatals, type errors, and unexpected deprecations.

## 9. Specification and handoff

- [ ] 9.1 Run `openspec validate add-store-booking --strict` and ensure CodeGraph is synchronized.
- [ ] 9.2 Document slot configuration, the booking lifecycle, and the verification flow for store staff.
- [ ] 9.3 Record that booking deliberately holds no money, and that any future deposit or prepayment model would pull booking into the payment path and invalidate the independence this design relies on.
