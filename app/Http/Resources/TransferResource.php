<?php

namespace App\Http\Resources;

final class TransferResource
{
    public static function make(object $transfer): array
    {
        $receiveMinor = (int) $transfer->receive_amount_minor;

        $resource = [
            'transferId' => $transfer->transfer_id,
            'status' => $transfer->status,
            'sendAmount' => (string) $transfer->send_amount_krw,
            'fee' => (string) $transfer->fee_krw,
            'totalDebit' => (string) $transfer->total_debit_krw,
            'receiveAmount' => intdiv($receiveMinor, 100).'.'.str_pad((string) ($receiveMinor % 100), 2, '0', STR_PAD_LEFT),
            'createdAt' => date(DATE_ATOM, strtotime($transfer->created_at)),
        ];

        if (!empty($transfer->partner_ref) && !str_starts_with((string) $transfer->partner_ref, 'pending_')) {
            $resource['partnerRef'] = $transfer->partner_ref;
        }
        if (!empty($transfer->failure_reason)) {
            $resource['failureReason'] = $transfer->failure_reason;
        }

        return $resource;
    }
}
