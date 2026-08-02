## Context

Franchise stores need online reservations that bring customers into the store, where the transaction then happens through the existing order flow. Two business rules were fixed before this design and they remove most of the complexity a general booking system would carry:

- **The customer does not pay to book.** Payment happens in the store after arrival. Booking therefore never touches the payment path, the WeChat sub-merchant configuration, profit sharing, or refunds. It does not depend on the service-provider work and can ship independently.
- **Slots are time windows only, with no named technician.** There is no roster, no per-person availability, no leave calendar. Capacity is a single number per window.

The application has no booking code today. Searching `app/` and `crmeb/` for booking, appointment, and reservation terms returns zero matches. The `eb_store_service*` tables are the customer-service chat system, not bookable services.

Three existing capabilities are reused rather than rebuilt:

- `StoreOrderCreateServices::getStoreCode()` produces a 12-digit code and re-rolls on collision.
- `StoreOrderWriteOffServices::writeOffOrder()` already looks up the verifying user in `eb_system_store_staff` requiring `verify_status = 1` and `status = 1`, then records `store_id` and `clerk_id`.
- `MiniProgramService::sendSubscribeTemlate()` sends mini-program subscribe messages.

## Goals / Non-Goals

**Goals:**

- Let a customer reserve a bookable window at a specific store without paying.
- Never allow more confirmed bookings in a window than its capacity, including under concurrent requests.
- Let store staff verify arrival with the code-scanning path they already use for orders.
- Attribute each booking to the store the customer entered from, on the booking record.
- Release capacity that no-shows are holding, automatically.
- Give stores a view of their own bookings, and the brand a view across stores.

**Non-Goals:**

- Any payment, deposit, prepayment, refund, or profit-sharing behavior.
- Named technician selection, rostering, shift planning, or leave management.
- Changing order, product, commission, or distribution behavior.
- Defining the store-attribution session mechanism, which belongs to the separate attribution change.
- Queue management or in-store flow after arrival.

## Decisions

### Separate slot definition from slot inventory

Two tables, not one. A definition describes a repeating pattern ("this store, weekdays, 10:00-11:00, capacity 5"); an inventory row is one concrete bookable window on one date with a live counter.

The alternative, generating rows only on demand, was rejected because the reservation guard described below needs a row to lock against. Creating that row lazily inside the reservation path reintroduces the race the guard exists to prevent.

Inventory rows are generated ahead of time by a scheduled task, for a rolling horizon. Generation is idempotent: a unique key on (store, date, start time) means re-running it cannot duplicate windows, and changing a definition affects only windows not yet generated.

### Reserve capacity with a guarded conditional update

Reservation must be a single statement whose `WHERE` clause carries the capacity test:

```sql
UPDATE eb_store_booking_slot
   SET booked = booked + 1
 WHERE id = :slot_id
   AND status = 1
   AND booked < capacity
```

An affected-row count of one means the seat is held; zero means the window is full or closed, and the request fails with no seat taken. The booking record is then inserted inside the same transaction, and the counter is decremented if that insert fails.

**The existing product-stock path must not be copied.** `BaseDao::decStockIncSales()` reads the row with `find()` at line 548 and only then applies `dec()` at line 550. There is no quantity guard in the `WHERE` clause, so two concurrent callers can both pass the read and both decrement. That is tolerable-looking for product stock only because oversell there is recovered commercially; for booking it would hand two customers the same seat. This design deliberately diverges.

Redis counters were considered and rejected as the source of truth: capacity would then live outside the database that stores the bookings, and a cache eviction or restart would silently desynchronize the two. The database row is authoritative.

### Keep booking out of the order tables

A booking is not an order. It has no money, no items, no delivery, and no refund path, and it is created and cancelled under rules that have nothing to do with order state. Modelling it as an order with a zero total would push booking-specific status values and rules into `eb_store_order`, which already carries order, delivery, verification, commission, and division concerns.

Booking gets its own table and its own status lifecycle. When the customer arrives and buys something, a normal order is created through the existing flow, and the booking records that order's id for reporting. That link is one nullable column on the booking, not a shared lifecycle.

### Attribute the booking to the entry store, on the booking record

The booking stores `store_mer_id` for the store the customer entered from. It is written at booking time and never recalculated.

This deliberately does not reuse the `division_id` / `agent_id` / `staff_id` fields that `StoreOrderCreateServices` copies from the customer at lines 281-283. Those describe a permanent agent hierarchy inherited from the customer record, so once a customer is bound to one store they stay bound. A customer must be able to book at store A this month and store B next month, with each booking counted for the store that received it.

Until the separate attribution change provides a session carrier, booking reads the store identifier from the entry request parameter and validates it against the store table.

### Reuse the existing verification path for arrival

Arrival check-in issues a code from the same generator the order flow uses, and verification resolves the staff member exactly as `writeOffOrder()` does: look up `eb_system_store_staff` by the acting user with `verify_status = 1` and `status = 1`, then confirm that the staff member's `store_id` matches the booking's store.

That last check matters and is stated explicitly because staff from one franchise must not be able to verify another franchise's bookings.

### Expire no-shows on a schedule, and release their capacity

A booking that is neither verified nor cancelled by a grace period after its window ends becomes expired, and the slot counter is decremented. Without this, capacity leaks permanently and a store's calendar silently fills with abandoned reservations.

The release is a conditional decrement guarded by `booked > 0`, so a repeated or overlapping run of the task cannot drive the counter negative.

The project already runs a timer process under Supervisor, so this needs a task, not new infrastructure.

## Data Model

Three new tables. No existing table is altered.

**`eb_store_booking_slot_rule`** — the repeating pattern a store publishes.

| Column | Purpose |
| --- | --- |
| `id` | key |
| `store_mer_id` | owning store |
| `weekdays` | which days the pattern applies to |
| `start_time`, `end_time` | window within a day |
| `capacity` | seats per generated window |
| `status`, `add_time` | enable flag, audit |

**`eb_store_booking_slot`** — one concrete window, and the authoritative counter.

| Column | Purpose |
| --- | --- |
| `id` | key |
| `store_mer_id` | owning store |
| `rule_id` | generating rule, nullable for manually added windows |
| `date`, `start_time`, `end_time` | the window |
| `capacity` | seats |
| `booked` | seats held; only ever changed by guarded conditional update |
| `status` | open or closed |

Unique key on (`store_mer_id`, `date`, `start_time`) makes generation idempotent.

**`eb_store_booking`** — the reservation.

| Column | Purpose |
| --- | --- |
| `id` | key |
| `booking_no` | customer-facing reference |
| `uid` | customer |
| `store_mer_id` | attributed store, fixed at creation |
| `slot_id`, `booking_date`, `start_time` | the reserved window, denormalized for listing |
| `contact_name`, `phone`, `remark` | contact details |
| `status` | pending, arrived, cancelled, expired |
| `verify_code` | arrival code |
| `verify_time`, `clerk_id` | who checked the customer in, and when |
| `order_id` | nullable link to the order created after arrival |
| `add_time`, `cancel_time` | audit |

## Status Lifecycle

```
pending ──verify──> arrived
   │
   ├──customer or staff cancels──> cancelled   (releases capacity)
   └──grace period after window ends──> expired (releases capacity)
```

Capacity is released on exactly two transitions, `cancelled` and `expired`. `arrived` consumes the seat and never releases it. Every transition is guarded so that a repeated request cannot release the same seat twice.

## Risks / Trade-offs

**Oversell is the failure that matters.** Two customers holding one seat is visible, embarrassing, and happens exactly when the business is busiest. Mitigation: the guarded conditional update above, plus a concurrency test that fires parallel requests at a single-seat window and asserts that exactly one succeeds. That test is the acceptance criterion, not a nice-to-have.

**Double release drives the counter negative and silently creates phantom capacity.** Mitigation: release is guarded by `booked > 0` and by a status transition that only fires once.

**Cross-franchise verification.** A store's staff verifying another store's booking is a trust breach between franchisees. Mitigation: verification asserts the staff member's `store_id` equals the booking's store, and this is covered by a test with staff from a second store.

**Slot generation depends on a healthy timer process.** If generation stops, the calendar quietly runs out of bookable windows and customers see nothing, with no error anywhere. Mitigation: generate a horizon well ahead of the booking window, and alert when the furthest generated date falls below a threshold.

**No first-party test suite exists.** The project has no maintained automated tests, so this change carries its own tests for concurrency, capacity release, and cross-store verification. Those three are the parts that cannot be verified by clicking through the UI.

**Attribution carrier is not yet available.** Booking depends on knowing which store the customer came from, and that mechanism is specified in the separate attribution change. Until then booking takes the identifier from the request, which is correct for in-store QR scanning but does not survive a customer reopening the mini program from history.

## Migration and Rollback

Additive only. Three new tables, new routes, new services. No existing table, column, or behavior changes, so rollback is dropping the new tables and removing the new routes; nothing in the order, payment, or commission paths needs reverting.

## Deferred to Other Changes

- The store-attribution session mechanism, including entry QR codes, session lifetime, and the cross-store and shared-link edge cases.
- WeChat service-provider onboarding, per-store sub-merchant payment, and profit sharing.
- Any prepayment or deposit model, which would pull booking into the payment path and invalidate the independence this design relies on.
