<?php

namespace App\Services;

final class PartnerClient
{
    public function submit(string $partnerRef): bool
    {
        // Simulate network latency; result depends only on the generated reference.
        usleep(200_000);
        $lastDigit = substr($partnerRef, -1);

        return !in_array($lastDigit, ['0', '1', '2'], true);
    }
}
