<?php

namespace App\Services;

use App\Enums\TransferStatus;
use App\Exceptions\TransferApiException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class StartTransfer
{
    private const DAILY_LIMIT_KRW = 3_000_000;

    public function __construct(private readonly PartnerClient $partner)
    {
    }

    public function handle(string $transferId): object
    {
        $date = now('Asia/Seoul')->toDateString();
        $partnerRef = null;

        $initial = DB::table('transfers')->where('transfer_id', $transferId)->first();
        if (!$initial) {
            throw new TransferApiException('NOT_FOUND', 'Transfer not found', 404);
        }

        // The first statement inside this transaction is a write, avoiding SQLite
        // read-to-write lock upgrades when two submissions race for the same day.
        DB::transaction(function () use ($transferId, $date, $initial, &$partnerRef): void {
            DB::table('daily_limit_usage')->insertOrIgnore([
                'customer_id' => $initial->customer_id,
                'limit_date' => $date,
                'used_amount_krw' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $transfer = DB::table('transfers')->where('transfer_id', $transferId)->first();
            if (!$transfer) {
                throw new TransferApiException('NOT_FOUND', 'Transfer not found', 404);
            }
            if (in_array($transfer->status, [
                TransferStatus::Sending->value,
                TransferStatus::Completed->value,
                TransferStatus::Failed->value,
            ], true)) {
                // Replayed submit request must not reserve funds or call partner twice.
                return;
            }
            if ($transfer->status !== TransferStatus::Requested->value) {
                throw new TransferApiException('CONFLICT', 'Only REQUESTED transfers can be sent', 409);
            }

            $reserved = DB::table('daily_limit_usage')
                ->where('customer_id', $transfer->customer_id)
                ->where('limit_date', $date)
                ->whereRaw('used_amount_krw <= ?', [self::DAILY_LIMIT_KRW - (int) $transfer->send_amount_krw])
                ->update([
                    'used_amount_krw' => DB::raw('used_amount_krw + '.(int) $transfer->send_amount_krw),
                    'updated_at' => now(),
                ]);
            if ($reserved !== 1) {
                throw new TransferApiException('LIMIT_EXCEEDED', 'Daily transfer limit of 3000000 KRW exceeded', 422);
            }

            // Suffix derived from amount makes S9 reproducible: amounts ending 0-2 fail.
            $partnerRef = 'pt_'.Str::uuid().((int) $transfer->send_amount_krw % 10);
            $changed = DB::table('transfers')->where('transfer_id', $transferId)
                ->where('status', TransferStatus::Requested->value)
                ->update([
                    'status' => TransferStatus::Sending->value,
                    'partner_ref' => $partnerRef,
                    'limit_date' => $date,
                    'updated_at' => now(),
                ]);
            if ($changed !== 1) {
                throw new TransferApiException('CONFLICT', 'Transfer is already being processed', 409);
            }
        });

        if ($partnerRef === null) {
            return $this->find($transferId);
        }

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $accepted = $this->partner->submit($partnerRef);
            Log::info('Remittance partner attempt', [
                'attempt' => $attempt,
                'partnerRef' => $partnerRef,
                'outcome' => $accepted ? 'accepted' : 'failed',
            ]);

            if ($accepted) {
                return $this->find($transferId);
            }

            if ($attempt < 3) {
                usleep(100_000 * $attempt);
            }
        }

        DB::transaction(function () use ($transferId): void {
            $transfer = DB::table('transfers')->where('transfer_id', $transferId)->first();
            $changed = DB::table('transfers')->where('transfer_id', $transferId)
                ->where('status', TransferStatus::Sending->value)
                ->update([
                    'status' => TransferStatus::Failed->value,
                    'failure_reason' => 'Partner rejected all 3 attempts',
                    'updated_at' => now(),
                ]);
            if ($changed === 1) {
                DB::table('daily_limit_usage')->where('customer_id', $transfer->customer_id)
                    ->where('limit_date', $transfer->limit_date)
                    ->decrement('used_amount_krw', (int) $transfer->send_amount_krw, ['updated_at' => now()]);
            }
        });

        return $this->find($transferId);
    }

    private function find(string $transferId): object
    {
        return DB::table('transfers')->where('transfer_id', $transferId)->first();
    }
}
