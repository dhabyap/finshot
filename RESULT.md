# Part 1 — Captured Results

Tested against local Laravel server at `http://127.0.0.1:8017`. Database started fresh before these scenarios. All requests use JSON.

## S1 — Create C001, 500000 KRW

Request:
```http
POST /api/transfers
Idempotency-Key: finshot-s1-4bd02282-b6d5-4237-9011-d4a8558d06fa
Content-Type: application/json
```
```json
{"customerId":"C001","sendCurrency":"KRW","sendAmount":"500000","receiveCurrency":"PHP","recipientName":"Juan Dela Cruz"}
```
Status: `201`
```json
{"transferId":"37a97af9-1464-4256-9d2c-bd7d3651713a","status":"REQUESTED","sendAmount":"500000","fee":"5000","totalDebit":"505000","receiveAmount":"20600.00","createdAt":"2026-10-16T08:37:28+00:00"}
```

## S2 — Exact retry of S1

Request: same method, path, header, and body as S1.

Status: `201`
```json
{"transferId":"37a97af9-1464-4256-9d2c-bd7d3651713a","status":"REQUESTED","sendAmount":"500000","fee":"5000","totalDebit":"505000","receiveAmount":"20600.00","createdAt":"2026-10-16T08:37:28+00:00"}
```
Same `transferId` returned; no second transfer created.

## S3 — Reuse S1 key with amount 600000

Request:
```http
POST /api/transfers
Idempotency-Key: finshot-s1-4bd02282-b6d5-4237-9011-d4a8558d06fa
Content-Type: application/json
```
```json
{"customerId":"C001","sendCurrency":"KRW","sendAmount":"600000","receiveCurrency":"PHP","recipientName":"Juan Dela Cruz"}
```
Status: `409`
```json
{"error":"CONFLICT","message":"Idempotency-Key was already used with different request data"}
```

## S4 — Create another transfer, then cancel it

Create request:
```http
POST /api/transfers
Idempotency-Key: finshot-s4-3ce34242-009e-4918-8edc-cbfea34bfd2f
Content-Type: application/json
```
```json
{"customerId":"C001","sendCurrency":"KRW","sendAmount":"200000","receiveCurrency":"PHP","recipientName":"Juan Dela Cruz"}
```
Status: `201`
```json
{"transferId":"44ccf98f-384d-4e21-ab84-f0ad24a38787","status":"REQUESTED","sendAmount":"200000","fee":"3000","totalDebit":"203000","receiveAmount":"8240.00","createdAt":"2026-10-16T08:37:29+00:00"}
```

Cancel request:
```http
POST /api/transfers/44ccf98f-384d-4e21-ab84-f0ad24a38787/cancel
```
Status: `200`
```json
{"transferId":"44ccf98f-384d-4e21-ab84-f0ad24a38787","status":"CANCELLED","sendAmount":"200000","fee":"3000","totalDebit":"203000","receiveAmount":"8240.00","createdAt":"2026-10-16T08:37:29+00:00"}
```

## S5 — Cancel same transfer again

Request:
```http
POST /api/transfers/44ccf98f-384d-4e21-ab84-f0ad24a38787/cancel
```
Status: `409`
```json
{"error":"CONFLICT","message":"Only REQUESTED transfers can be cancelled"}
```

## S6 — Create with sendAmount 5000

Request:
```http
POST /api/transfers
Idempotency-Key: finshot-s6-actual-test-5000
Content-Type: application/json
```
```json
{"customerId":"C001","sendCurrency":"KRW","sendAmount":"5000","receiveCurrency":"PHP","recipientName":"Juan Dela Cruz"}
```
Status: `400`
```json
{"error":"VALIDATION_ERROR","message":"sendAmount must be an integer of at least 10000"}
```

## Additional read check

`GET /api/transfers/37a97af9-1464-4256-9d2c-bd7d3651713a` returned `200` with the same S1 transfer data.

## Automated verification

Before Part 2 was added, Part 1 tests passed **10 tests, 22 assertions**. After Part 2 implementation, the full suite passed **17 tests, 81 assertions**, including Seoul-midnight reset, cancellation/failure reservation release, and repeated-send idempotency. `php artisan migrate:fresh --force --seed` completed successfully, and `php artisan route:list --path=api` showed all five routes.

The full S1–S6 flow was also repeated over HTTP against the refactored app at `http://127.0.0.1:8022`:

- S1 create, 500000 KRW: `201`; fee `5000`, total debit `505000`, receive `20600.00`, status `REQUESTED`.
- S2 retry with same key and body: `201`; same `transferId` and `createdAt` as S1.
- S3 same key with 600000 KRW: `409 CONFLICT`.
- S4 create, 200000 KRW: `201`; GET returned `200`; cancellation returned `200 CANCELLED`.
- S5 repeated cancellation: `409 CONFLICT`.
- S6 create, 5000 KRW: `400 VALIDATION_ERROR`.

These are fresh HTTP checks on the final refactored code; the complete requests and captured response bodies for the original S1–S6 run are recorded above.

## S7 — Daily limit (Part 2)

All three transfers use customer `C002` on the same `Asia/Seoul` date. Submit first two; total reservation reaches exactly 3,000,000 KRW.

- Create #1: `Idempotency-Key: final-s7-a`, `sendAmount: 1500003` → `201`, transfer `20df0427-ec65-4847-a782-2d50a09c6b2a`, status `REQUESTED`. Submit via `POST /api/transfers/20df0427-ec65-4847-a782-2d50a09c6b2a/send` → `200 SENDING`, reference suffix `3`.
- Create #2: `Idempotency-Key: final-s7-b`, `sendAmount: 1499997` → `201`, transfer `976cc32b-d6ec-4293-be91-32f6ea57f79e`, status `REQUESTED`. Submit via `POST /api/transfers/976cc32b-d6ec-4293-be91-32f6ea57f79e/send` → `200 SENDING`, reference suffix `7`.
- Create #3: `Idempotency-Key: final-s7-c`, `sendAmount: 10000` → `201`, transfer `35caeae6-8b88-4414-927b-71149446e325`, status `REQUESTED`. Submit → `422`:
```json
{"error":"LIMIT_EXCEEDED","message":"Daily transfer limit of 3000000 KRW exceeded"}
```

## S8 — Idempotent completion callback (Part 2)

Create `500003` KRW with `Idempotency-Key: final-s8` → `201`, transfer `801ace3c-34d4-4c46-a4f0-d3e71a8a638a`. Submit it → `200`, status `SENDING`, partnerRef `pt_aebf769f-7039-4e2e-a273-ba48c8f950973`.

Send this exact callback twice:
```http
POST /api/callbacks/partner
Content-Type: application/json
```
```json
{"partnerRef":"pt_aebf769f-7039-4e2e-a273-ba48c8f950973","eventId":"final-evt-s8","status":"COMPLETED"}
```
Both requests returned `200` with status `COMPLETED`. `GET /api/transfers/801ace3c-34d4-4c46-a4f0-d3e71a8a638a` returned `200`, `COMPLETED`; one callback event row was stored.

## S9 — Deterministic partner failure (Part 2)

Create `10000` KRW with `Idempotency-Key: final-s9` → `201`, transfer `ec876c6c-ed72-4fdf-92ef-336307f6894c`. Its generated partnerRef `pt_70052aff-d9bb-4b94-802a-879fce119a550` ends in `0`.

Submit: `POST /api/transfers/ec876c6c-ed72-4fdf-92ef-336307f6894c/send` → `200`:
```json
{"transferId":"ec876c6c-ed72-4fdf-92ef-336307f6894c","status":"FAILED","sendAmount":"10000","fee":"3000","totalDebit":"13000","receiveAmount":"412.00","createdAt":"2026-10-17T00:00:23+00:00","partnerRef":"pt_70052aff-d9bb-4b94-802a-879fce119a550","failureReason":"Partner rejected all 3 attempts"}
```
The application log recorded exactly attempts 1, 2, and 3 as `failed` for that same partnerRef. Failed transfer released its daily-limit reservation.

## Final automated verification

`php artisan test` passed **17 tests, 81 assertions**. `php artisan migrate:fresh --force --seed` completed successfully, and `php artisan route:list --path=api` listed all five API routes.