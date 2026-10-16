<?php

namespace App\Http\Controllers;

use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TransferController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $key = trim((string) $request->header('Idempotency-Key', ''));
        if ($key === '' || strlen($key) > 100) {
            return $this->error('VALIDATION_ERROR', 'Idempotency-Key header is required (1-100 characters)', 400);
        }

        try {
            $data = $request->validate([
                'customerId' => ['required', 'string', 'max:10', 'exists:customers,customer_id'],
                'sendCurrency' => ['required', 'string', 'in:KRW'],
                'sendAmount' => ['required', 'regex:/^[0-9]+$/'],
                'receiveCurrency' => ['required', 'string', 'in:PHP'],
                'recipientName' => ['required', 'string', 'max:100'],
            ]);
        } catch (ValidationException $e) {
            $message = collect($e->errors())->flatten()->first() ?? 'Invalid request';
            return $this->error('VALIDATION_ERROR', $message, 400);
        }

        $amount = filter_var($data['sendAmount'], FILTER_VALIDATE_INT);
        if ($amount === false || $amount < 10000) {
            return $this->error('VALIDATION_ERROR', 'sendAmount must be an integer of at least 10000', 400);
        }

        $canonical = [
            'customerId' => $data['customerId'],
            'sendCurrency' => $data['sendCurrency'],
            'sendAmount' => (string) $amount,
            'receiveCurrency' => $data['receiveCurrency'],
            'recipientName' => $data['recipientName'],
        ];
        $hash = hash('sha256', json_encode($canonical, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $existing = DB::table('transfers')->where('idempotency_key', $key)->first();
        if ($existing) {
            if (!hash_equals($existing->request_hash, $hash)) {
                return $this->error('CONFLICT', 'Idempotency-Key was already used with different request data', 409);
            }
            return response()->json($this->serialize($existing), 201);
        }

        $fee = max(3000, intdiv($amount, 100) + (($amount % 100) >= 50 ? 1 : 0)); // 1%, whole KRW, HALF_UP
        $receiveMinor = intdiv($amount, 100) * 412
            + intdiv((($amount % 100) * 412) + 50, 100); // PHP cents, HALF_UP; avoid intermediate overflow.
        $now = now();
        $id = (string) Str::uuid();

        try {
            DB::table('transfers')->insert([
                'transfer_id' => $id,
                'customer_id' => $canonical['customerId'],
                'idempotency_key' => $key,
                'request_hash' => $hash,
                'send_currency' => 'KRW',
                'send_amount_krw' => $amount,
                'fee_krw' => $fee,
                'total_debit_krw' => $amount + $fee,
                'receive_amount_minor' => $receiveMinor,
                'receive_currency' => 'PHP',
                'recipient_name' => $canonical['recipientName'],
                'status' => 'REQUESTED',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } catch (QueryException $e) {
            // Unique idempotency constraint arbitrates simultaneous identical requests.
            $existing = DB::table('transfers')->where('idempotency_key', $key)->first();
            if (!$existing) {
                throw $e;
            }
            if (!hash_equals($existing->request_hash, $hash)) {
                return $this->error('CONFLICT', 'Idempotency-Key was already used with different request data', 409);
            }
            return response()->json($this->serialize($existing), 201);
        }

        return response()->json($this->serialize(DB::table('transfers')->where('transfer_id', $id)->first()), 201);
    }

    public function show(string $transferId): JsonResponse
    {
        $transfer = DB::table('transfers')->where('transfer_id', $transferId)->first();
        return $transfer
            ? response()->json($this->serialize($transfer))
            : $this->error('NOT_FOUND', 'Transfer not found', 404);
    }

    public function cancel(string $transferId): JsonResponse
    {
        $changed = DB::table('transfers')
            ->where('transfer_id', $transferId)
            ->where('status', 'REQUESTED')
            ->update(['status' => 'CANCELLED', 'updated_at' => now()]);

        if (!$changed) {
            $exists = DB::table('transfers')->where('transfer_id', $transferId)->exists();
            return $exists
                ? $this->error('CONFLICT', 'Only REQUESTED transfers can be cancelled', 409)
                : $this->error('NOT_FOUND', 'Transfer not found', 404);
        }

        return response()->json($this->serialize(DB::table('transfers')->where('transfer_id', $transferId)->first()));
    }

    private function serialize(object $t): array
    {
        return [
            'transferId' => $t->transfer_id,
            'status' => $t->status,
            'sendAmount' => (string) $t->send_amount_krw,
            'fee' => (string) $t->fee_krw,
            'totalDebit' => (string) $t->total_debit_krw,
            'receiveAmount' => intdiv((int) $t->receive_amount_minor, 100).'.'.str_pad((string) ((int) $t->receive_amount_minor % 100), 2, '0', STR_PAD_LEFT),
            'createdAt' => date(DATE_ATOM, strtotime($t->created_at)),
        ];
    }

    private function error(string $error, string $message, int $status): JsonResponse
    {
        return response()->json(['error' => $error, 'message' => $message], $status);
    }
}
