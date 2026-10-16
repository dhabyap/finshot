<?php

namespace App\Support\Transfers;

final class TransferAmountCalculator
{
    private const MINIMUM_FEE_KRW = 3000;
    private const PHP_CENTAVOS_PER_KRW_RATIO = 412;

    public function calculateFee(int $sendAmountKrw): int
    {
        $percentageFee = intdiv($sendAmountKrw, 100)
            + (($sendAmountKrw % 100) >= 50 ? 1 : 0);

        return max(self::MINIMUM_FEE_KRW, $percentageFee);
    }

    public function calculateReceiveAmountMinor(int $sendAmountKrw): int
    {
        $wholeAmount = intdiv($sendAmountKrw, 100);
        $roundedRemainder = intdiv((($sendAmountKrw % 100) * self::PHP_CENTAVOS_PER_KRW_RATIO) + 50, 100);

        if ($wholeAmount > intdiv(PHP_INT_MAX - $roundedRemainder, self::PHP_CENTAVOS_PER_KRW_RATIO)) {
            throw new \InvalidArgumentException('sendAmount is too large');
        }

        return $wholeAmount * self::PHP_CENTAVOS_PER_KRW_RATIO + $roundedRemainder;
    }
}
