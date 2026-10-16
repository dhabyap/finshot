<?php

namespace App\Services;

use App\Enums\TransferStatus;
use App\Support\Transfers\TransferAmountCalculator;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class CreateTransfer
{
    public function __construct(private readonly TransferAmountCalculator $calculator)
    {
    }

    public function handle(array $data, string $idempotencyKey): object
    {
        $amount = filter_var($data['sendAmount'], FILTER_VALIDATE_INT);
        if ($amount === false || $amount < 10000) {
            throw new \InvalidArgumentException('sendAmount must be an integer of at least 10000');
        }

        $fee = $this->calculator->calculateFee($amount);
        if ($amount > PHP_INT_MAX - $fee) {
            throw new \InvalidArgumentException('sendAmount is too large');
        }

        $canonical = [
            'customerId' => $data['customerId'],
            'sendCurrency' => $data['sendCurrency'],
            'sendAmount' => (string) $amount,
            'receiveCurrency' => $data['receiveCurrency'],
            'recipientName' => $data['recipientName'],
        ];
        $hash = hash('sha256', json_encode($canonical, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $existing = $this->findExisting($idempotencyKey);
        if ($existing) {
            return $this->assertSameRequest($existing, $hash);
        }

        $id = (string) Str::uuid();
        $now = now();

        try {
            DB::table('transfers')->insert([
                'transfer_id' => $id,
                'customer_id' => $canonical['customerId'],
                'idempotency_key' => $idempotencyKey,
                'request_hash' => $hash,
                'send_currency' => $canonical['sendCurrency'],
                'send_amount_krw' => $amount,
                'fee_krw' => $fee,
                'total_debit_krw' => $amount + $fee,
                'receive_amount_minor' => $this->calculator->calculateReceiveAmountMinor($amount),
                'receive_currency' => $canonical['receiveCurrency'],
                'recipient_name' => $canonical['recipientName'],
                'status' => TransferStatus::Requested->value,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } catch (QueryException $exception) {
            $existing = $this->findExisting($idempotencyKey);
            if (!$existing) {
                throw $exception;
            }

            return $this->assertSameRequest($existing, $hash);
        }

        return $this->findById($id);
    }

    private function findExisting(string $key): ?object
    {
        return DB::table('transfers')->where('idempotency_key', $key)->first();
    }

    private function findById(string $id): object
    {
        return DB::table('transfers')->where('transfer_id', $id)->first();
    }

    private function assertSameRequest(object $transfer, string $hash): object
    {
        if (!hash_equals($transfer->request_hash, $hash)) {
            throw new ConflictHttpException('Idempotency-Key was already used with different request data');
        }

        return $transfer;
    }
}
