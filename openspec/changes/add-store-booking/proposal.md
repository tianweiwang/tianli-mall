## Why

Franchise stores need customers to reserve a service slot online and then complete the transaction in the store. The application has no booking capability today: a search for booking, appointment, and reservation terms across `app/` and `crmeb/` returns zero matches, and the tables whose names suggest it (`eb_store_service`, `eb_store_service_record`) are the customer-service chat system, with columns like `online`, `account`, `nickname`, and `notify`.

Three existing capabilities cover most of what booking needs, so this is an additive module rather than a rewrite:

- Order verification codes already exist. `StoreOrderCreateServices::getStoreCode()` generates a collision-checked 12-digit code, and `StoreOrderWriteOffServices::writeOffOrder()` already resolves the verifying staff member through `eb_system_store_staff` with `verify_status = 1` and stamps `store_id` and `clerk_id` onto the record.
- `eb_system_store` already stores per-store business hours (`day_time`, `valid_time`), coordinates, and display fields.
- Mini-program subscribe messages already work through `MiniProgramService::sendSubscribeTemlate()`.

The business rules for this change are fixed: customers book a slot without paying and pay in the store afterwards, and slots are reserved by time window only, with no named technician. That keeps booking entirely out of the payment path, so it does not depend on the WeChat service-provider and profit-sharing work, and it needs no staff rostering model.

## What Changes

- Add a slot inventory model that generates bookable windows per store from that store's business hours, with a capacity per window.
- Add a booking record that holds the customer, the store, the slot, contact details, a verification code, and a status lifecycle.
- Reserve slot capacity with a single conditional `UPDATE` guarded by `booked < capacity`, and treat an affected-row count of zero as "no seats left". The existing product path must not be copied here: `BaseDao::decStockIncSales()` reads stock with `find()` at line 548 and only then applies `dec()` at line 550, with no quantity guard in the `WHERE` clause, so it can oversell under concurrency.
- Reuse the existing verification flow for arrival check-in, so store staff verify a booking with the same code-scanning path they already use for orders.
- Attribute every booking to the store the customer entered from, and store that attribution on the booking record rather than on the customer, so one customer can book at several stores over time.
- Add booking reminder, cancellation, and expiry handling, including a scheduled task that releases capacity held by no-shows.
- Add a store-side booking list and a customer-side booking history.
- **Booking does not create an order and does not touch payment.** Money is collected in the store through the existing order flow after the customer arrives.

## Capabilities

### New Capabilities

- `store-booking`: Per-store bookable slots with safe capacity control, an attributed booking record, arrival verification through the existing staff verification path, and reminder and expiry handling.

### Modified Capabilities

None. Booking adds tables and routes; it does not change order, payment, product, or commission behavior.

## Impact

- New tables: slot definitions, slot inventory, and booking records. No existing table is altered in this change.
- New application code under `app/services/booking/`, `app/dao/booking/`, API controllers for the customer side, and admin controllers for store and brand staff.
- Reused without modification: `eb_system_store`, `eb_system_store_staff`, the verification-code generator, and the subscribe-message sender.
- Deferred: store attribution currently has no carrier. This change defines `store_mer_id` on the booking record and reads it from a session value, but the session mechanism itself is specified by the separate store-attribution change. Until that lands, booking accepts the store identifier directly from the entry parameter.

## Open Questions Blocking Implementation

These are business rules, not technical choices, and each one changes behavior that customers and franchisees will see. Implementation should not start until they are answered.

1. How far ahead can a customer book, and can they book for the same day?
2. How long before a slot starts can a customer cancel, and how many times may they reschedule?
3. What happens to a customer who repeatedly fails to arrive?
4. How many active bookings may one customer hold, per slot and per day?
5. Do stores configure their own slots and capacity, or does the brand configure them centrally?
