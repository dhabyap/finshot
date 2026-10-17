# Finshot Remittance Transfer API

Backend-only take-home, implemented with Laravel 13 / PHP 8.4 and SQLite. Part 1 and Part 2 are implemented. No UI, authentication, or real external partner integration.

## Run (one command)

From the repository root, with PHP 8.4+ and Composer installed:

```bash
composer install && php -r "file_exists('.env') || copy('.env.example', '.env'); file_exists('database/database.sqlite') || touch('database/database.sqlite');" && php artisan key:generate && php artisan migrate:fresh --seed && php artisan serve
```

SQLite is stored at `database/database.sqlite`. No MySQL installation required.

## API

- `POST /api/transfers` — create transfer
- `GET /api/transfers/{transferId}` — read transfer
- `POST /api/transfers/{transferId}/cancel` — cancel only while status is `REQUESTED`
- `POST /api/transfers/{transferId}/send` — reserve daily limit, enter `SENDING`, and call deterministic simulated partner
- `POST /api/callbacks/partner` — accept idempotent partner completion callback (`partnerRef`, `eventId`, `status: COMPLETED`)

### Safe client retries

Every create request must include an `Idempotency-Key` header (1–100 characters). Generate a new UUID for each logical transfer, persist it on the client before sending, and reuse that exact key and request body for every retry. The server stores the key under a database UNIQUE constraint with a SHA-256 hash of the canonical request. Same key and same data returns the original transfer; same key with different data returns 409. Missing key returns 400. The database constraint arbitrates concurrent requests.

Example:

```http
POST /api/transfers
Idempotency-Key: 70bc2034-41e0-4a76-9254-ae384cce174d
Content-Type: application/json
```

```json
{"customerId":"C001","sendCurrency":"KRW","sendAmount":"500000","receiveCurrency":"PHP","recipientName":"Juan Dela Cruz"}
```

## Money, states, and Part 2 behavior

KRW values are stored as integer KRW. PHP receive amount is stored as integer minor units (centavos); API serializes it with exactly two decimal places. Fee is rounded to whole KRW using HALF_UP with 3,000 KRW minimum. FX is hard-coded to 0.0412 PHP per KRW and rounded HALF_UP to two decimal places. Create leaves transfers in `REQUESTED`; `send` moves them to `SENDING`. Partner acceptance leaves status `SENDING`; only callback can set `COMPLETED`. Three deterministic failures set `FAILED`; cancellation is allowed only from `REQUESTED`.

`POST /api/transfers/{transferId}/send` reserves send amount against the 3,000,000 KRW per-customer limit for the current `Asia/Seoul` day. `FAILED` transfers release reservation; cancelled requests never reserve. `PartnerClient` is local simulation only, sleeps at least 200 ms per attempt, and succeeds unless generated `partnerRef` ends in 0, 1, or 2. Its suffix derives from the send amount's last digit, making failure scenarios reproducible without randomness. Up to three attempts are logged. Callback `eventId` is unique and replay-safe.

Create-request validation lives in `StoreTransferRequest`; transfer creation and idempotency live in `CreateTransfer`; fee and FX calculations live in `TransferAmountCalculator`; submission and daily limit handling live in `StartTransfer`; callback transitions live in `ReceivePartnerCallback`; response formatting lives in `TransferResource`. Inputs too large for exact integer calculations are rejected with `VALIDATION_ERROR`.

## AI tools disclosure

AI tools: All assignment code was generated with GPT Luna via 9Router, with Hermes Agent coordinating the workflow.
Used for: assignment analysis, Laravel implementation, documentation, and test planning.
The application and test suite were run locally; reported test results reflect the observed execution.

## Scope limits

No real partner service, authentication, UI, Docker, OpenAPI, or admin endpoints. Part 2 partner behavior is deterministic local simulation only.
