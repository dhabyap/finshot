# Design

## Q1. Tables, indexes, constraints

`customers` uses `customer_id` as its primary key and contains the supplied seed customers. `transfers` stores each transfer, integer KRW amounts, PHP minor units, status, request hash, optional partner reference, and Seoul limit date. Its primary key identifies a transfer; a foreign key prevents unknown customers. Unique `idempotency_key` prevents duplicate creates; unique nullable `partner_ref` prevents duplicate partner references. `(customer_id, created_at)` supports customer/day lookups; status and limit-date indexes support operations. `daily_limit_usage` has a composite `(customer_id, limit_date)` primary key and holds atomic daily reservations. `partner_callbacks.event_id` is the callback idempotency key; its transfer foreign key prevents orphan events.

## Q2. Idempotency

The client supplies `Idempotency-Key`; the server stores it with a SHA-256 hash of canonical request fields. The unique constraint is the final arbiter, not an application-only check. Same key and same data returns the existing transfer; same key with different data returns 409. Missing key returns 400. If two identical requests arrive simultaneously, both may initially see no row, but only one insert can satisfy the unique constraint. The losing request reads the winner's row and returns it. This relies on the database's atomic unique index.

## Q3. Money

Request amounts arrive as integer KRW strings and are validated as whole positive integers. KRW amounts and fee are stored as integers. Fee is `max(3000, HALF_UP(sendAmount × 0.01))`; integer arithmetic implements HALF_UP without binary floating point. The fixed FX rate is 0.0412 PHP per KRW. `sendAmount × 0.0412` is rounded HALF_UP to two decimals in integer arithmetic; the result is stored in PHP centavos and formatted with two decimal places. Fee is not subtracted from the FX principal. This avoids floating-point representation errors at every layer.

## Q4. Daily limit concurrency (Part 2)

Submitting a transfer reserves its KRW amount in `daily_limit_usage`, keyed by customer and the `Asia/Seoul` calendar date. A single conditional SQL update increments usage only when `used_amount_krw + sendAmount <= 3000000`; the reservation and transition from REQUESTED to SENDING run in one database transaction. The counter row's primary key handles first-use races, and the guarded update serializes concurrent writers (SQLite serializes writes; MySQL locks the updated row). A rejected or rolled-back transaction leaves both status and usage unchanged. FAILED attempts release their reservation; CANCELLED transfers never reserve because cancellation is only allowed before submission. This small counter ledger is preferred over a read-sum-write race.

## Q5. Callback timing (Part 2)

The transfer and generated `partnerRef` are committed in SENDING before the deterministic client call begins. A callback arriving while that call is in progress can therefore find the reference and complete the transfer. The callback transaction inserts the unique `eventId` and changes status only from SENDING to COMPLETED; duplicate event IDs return the existing result without applying a second transition. If the partner reports failure after an early callback, the failure update is also conditional on SENDING, so it cannot overwrite COMPLETED. This is a synchronous simulation, not an outbox/queue implementation; production delivery would need durable jobs and reconciliation for process crashes.
