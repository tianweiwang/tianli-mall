## ADDED Requirements

### Requirement: Per-store bookable slots
Each store SHALL publish bookable windows derived from a repeating rule, and each window SHALL carry its own capacity.

#### Scenario: Store publishes a booking rule
- **WHEN** a store defines a rule with weekdays, a start and end time, and a capacity
- **THEN** concrete bookable windows SHALL be generated for that store for the configured horizon
- **THEN** each generated window SHALL carry the rule's capacity and a held count of zero

#### Scenario: Slot generation runs more than once
- **WHEN** slot generation runs again for a date range it has already covered
- **THEN** no duplicate window SHALL be created for the same store, date, and start time
- **THEN** existing held counts SHALL NOT be altered

#### Scenario: A rule changes after windows exist
- **WHEN** a store edits or disables a rule
- **THEN** windows not yet generated SHALL follow the new rule
- **THEN** already generated windows and their bookings SHALL remain unchanged

#### Scenario: Generation horizon falls behind
- **WHEN** the furthest generated date drops below the configured threshold
- **THEN** the condition SHALL be reported rather than silently presenting an empty calendar

### Requirement: Safe capacity control
A window SHALL NOT hold more confirmed bookings than its capacity, including under concurrent requests.

#### Scenario: Concurrent requests for the last seat
- **WHEN** multiple booking requests for a window with one remaining seat arrive at the same time
- **THEN** exactly one request SHALL succeed
- **THEN** every other request SHALL fail reporting no remaining capacity
- **THEN** the held count SHALL equal the capacity and SHALL NOT exceed it

#### Scenario: Reservation is attempted on a full window
- **WHEN** a customer requests a window whose held count already equals its capacity
- **THEN** the request SHALL fail
- **THEN** no booking record SHALL be created and the held count SHALL NOT change

#### Scenario: Reservation is attempted on a closed window
- **WHEN** a customer requests a window whose status is closed
- **THEN** the request SHALL fail and the held count SHALL NOT change

#### Scenario: Booking creation fails after capacity is held
- **WHEN** capacity has been held but the booking record cannot be written
- **THEN** the held capacity SHALL be returned to the window
- **THEN** no partial booking SHALL remain

### Requirement: Attributed booking record
Every booking SHALL record the store it was made through, and that attribution SHALL be fixed at creation.

#### Scenario: Customer books through a store entry
- **WHEN** a customer books after entering through a specific store
- **THEN** the booking SHALL record that store
- **THEN** the booking SHALL carry a verification code and a pending status

#### Scenario: The same customer books at a different store
- **WHEN** a customer who has previously booked at one store books through another store
- **THEN** the new booking SHALL be attributed to the store it was made through
- **THEN** the earlier booking's attribution SHALL remain unchanged

#### Scenario: An unknown or disabled store is supplied
- **WHEN** a booking request names a store that does not exist or is disabled
- **THEN** the request SHALL fail and no capacity SHALL be held

#### Scenario: Booking does not create an order
- **WHEN** a booking is created
- **THEN** no order SHALL be created and no payment SHALL be initiated
- **THEN** order, commission, and distribution records SHALL be unaffected

### Requirement: Arrival verification by store staff
A booking SHALL be verifiable only by staff of the store that holds it.

#### Scenario: Store staff verifies an arriving customer
- **WHEN** an authorized staff member of the holding store verifies a pending booking's code
- **THEN** the booking SHALL become arrived
- **THEN** the verifying staff member and the verification time SHALL be recorded
- **THEN** the held capacity SHALL NOT be released

#### Scenario: Staff from another store attempts verification
- **WHEN** a staff member whose store differs from the booking's store attempts verification
- **THEN** the attempt SHALL be refused
- **THEN** the booking SHALL remain pending

#### Scenario: An unauthorized user attempts verification
- **WHEN** a user who is not enabled for verification attempts to verify a booking
- **THEN** the attempt SHALL be refused

#### Scenario: A code is verified twice
- **WHEN** an already arrived booking is verified again
- **THEN** the attempt SHALL be refused
- **THEN** the recorded verifying staff member and time SHALL NOT change

#### Scenario: Purchase after arrival
- **WHEN** the customer buys something in the store after arriving
- **THEN** the order SHALL be created through the existing order flow
- **THEN** the booking SHALL reference that order for reporting

### Requirement: Cancellation and expiry release capacity
Capacity held by a booking that will not be honoured SHALL be returned to its window.

#### Scenario: Customer cancels within the allowed period
- **WHEN** a customer cancels a pending booking while cancellation is permitted
- **THEN** the booking SHALL become cancelled
- **THEN** the window's held count SHALL decrease by one

#### Scenario: Cancellation is attempted too late
- **WHEN** a customer attempts to cancel after the cancellation deadline
- **THEN** the attempt SHALL be refused and the booking SHALL remain pending

#### Scenario: Customer does not arrive
- **WHEN** a booking is still pending after the grace period following its window
- **THEN** the booking SHALL become expired
- **THEN** the window's held count SHALL decrease by one

#### Scenario: Release is attempted twice
- **WHEN** a cancellation or expiry transition is applied to a booking that has already released its capacity
- **THEN** the held count SHALL NOT change
- **THEN** the held count SHALL never fall below zero

#### Scenario: Expiry task overlaps itself
- **WHEN** the expiry task runs while a previous run is still processing the same bookings
- **THEN** each booking SHALL release its capacity at most once

### Requirement: Booking visibility
Customers, stores, and the brand SHALL each see the bookings that concern them.

#### Scenario: Customer reviews their bookings
- **WHEN** a customer opens their booking history
- **THEN** they SHALL see their own bookings with store, window, status, and verification code
- **THEN** they SHALL NOT see another customer's bookings

#### Scenario: Store reviews its bookings
- **WHEN** store staff open the booking list
- **THEN** they SHALL see only bookings attributed to their own store

#### Scenario: Brand reviews bookings across stores
- **WHEN** brand staff open the booking list
- **THEN** they SHALL see bookings across all stores and SHALL be able to filter by store, date, and status

### Requirement: Booking notifications
A customer SHALL be told when a booking is made, when it is due, and when it is cancelled or expired.

#### Scenario: Booking confirmed
- **WHEN** a booking is created
- **THEN** a confirmation carrying the store, window, and verification code SHALL be sent

#### Scenario: Booking due soon
- **WHEN** a pending booking's window approaches within the configured reminder lead time
- **THEN** a reminder SHALL be sent once

#### Scenario: Notification delivery fails
- **WHEN** a notification cannot be delivered
- **THEN** the booking and its held capacity SHALL be unaffected
- **THEN** the failure SHALL be recorded rather than surfaced to the customer as a booking error
