<?php

namespace App\Services;

use App\Enums\TransferStatus;
use App\Exceptions\TransferApiException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final class ReceivePartnerCallback
{
    public function handle(string $partnerRef, string $eventId): object
    {
        try {
            return DB::transaction(function () use ($partnerRef, $eventId): object {
                $duplicate = DB::table('partner_callbacks')->where('event_id', $eventId)->first();
                if ($duplicate) {
                    return $this->existingEventResult($duplicate, $partnerRef);
                }

                $transfer = DB::table('transfers')->where('partner_ref', $partnerRef)->first();
                if (!$transfer || str_starts_with((string) $transfer->partner_ref, 'pending_')) {
                    throw new TransferApiException('NOT_FOUND', 'Unknown partnerRef', 404);
                }
                if ($transfer->status !== TransferStatus::Sending->value) {
                    throw new TransferApiException('CONFLICT', 'Callback is valid only for SENDING transfers', 409);
                }

                DB::table('partner_callbacks')->insert([
                    'event_id' => $eventId,
                    'transfer_id' => $transfer->transfer_id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $changed = DB::table('transfers')->where('transfer_id', $transfer->transfer_id)
                    ->where('status', TransferStatus::Sending->value)
                    ->update([
                        'status' => TransferStatus::Completed->value,
                        'updated_at' => now(),
                    ]);
                if ($changed !== 1) {
                    throw new TransferApiException('CONFLICT', 'Callback is valid only for SENDING transfers', 409);
                }

                return DB::table('transfers')->where('transfer_id', $transfer->transfer_id)->first();
            });
        } catch (QueryException $exception) {
            // Resolve unique eventId races after the losing transaction rolled back,
            // so a REPEATABLE READ snapshot cannot hide the winner's committed row.
            $duplicate = DB::table('partner_callbacks')->where('event_id', $eventId)->first();
            if (!$duplicate) {
                throw $exception;
            }

            return $this->existingEventResult($duplicate, $partnerRef);
        }
    }

    private function existingEventResult(object $event, string $partnerRef): object
    {
        $transfer = DB::table('transfers')->where('transfer_id', $event->transfer_id)->first();
        if ($transfer && hash_equals((string) $transfer->partner_ref, $partnerRef)) {
            return $transfer;
        }

        throw new TransferApiException('CONFLICT', 'eventId was already used for another callback', 409);
    }
}
