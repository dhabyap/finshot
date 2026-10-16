# Design

## Q1. Tables, indexes, constraints

`customers` uses `customer_id` as its primary key and contains the supplied seed customers. `transfers` stores each transfer, integer KRW amounts, PHP minor units, status, and a request hash. Its primary key identifies a transfer; the foreign key prevents transfers for unknown customers. The unique `idempotency_key` prevents duplicate transfers, while unique nullable `partner_ref` is reserved for Part 2. Currency, amount, recipient, and status checks reject invalid persisted values. `(customer_id, created_at)` supports customer/day queries; `status` supports operational filtering. `partner_callbacks.event_id` is a primary key to deduplicate callback events in Part 2.

## Q2. Idempotency

The client supplies `Idempotency-Key`; the server stores it with a SHA-256 hash of canonical request fields. The unique constraint is the final arbiter, not an application-only check. Same key and same data returns the existing transfer; same key with different data returns 409. Missing key returns 400. If two identical requests arrive simultaneously, both may initially see no row, but only one insert can satisfy the unique constraint. The losing request reads the winner's row and returns it. This relies on the database's atomic unique index.

## Q3. Money

Request amounts arrive as integer KRW strings and are validated as whole positive integers. KRW amounts and fee are stored as integers. Fee is `max(3000, HALF_UP(sendAmount × 0.01))`; integer arithmetic implements HALF_UP without binary floating point. The fixed FX rate is 0.0412 PHP per KRW. The converted amount is rounded HALF_UP to two decimal places once, immediately after multiplication. The database stores PHP minor units (centavos) as an integer, and the API formats this integer with exactly two decimal places. This avoids floating-point representation errors at every layer.
