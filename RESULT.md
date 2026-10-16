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

On the final refactored code, `php artisan test` passed **10 tests, 22 assertions**. `php artisan migrate:fresh --force --seed` completed successfully, and `php artisan route:list --path=api` showed all three required endpoints.

The full S1–S6 flow was also repeated over HTTP against the refactored app at `http://127.0.0.1:8022`:

- S1 create, 500000 KRW: `201`; fee `5000`, total debit `505000`, receive `20600.00`, status `REQUESTED`.
- S2 retry with same key and body: `201`; same `transferId` and `createdAt` as S1.
- S3 same key with 600000 KRW: `409 CONFLICT`.
- S4 create, 200000 KRW: `201`; GET returned `200`; cancellation returned `200 CANCELLED`.
- S5 repeated cancellation: `409 CONFLICT`.
- S6 create, 5000 KRW: `400 VALIDATION_ERROR`.

These are fresh HTTP checks on the final refactored code; the complete requests and captured response bodies for the original S1–S6 run are recorded above.