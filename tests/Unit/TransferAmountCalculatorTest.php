<?php

namespace Tests\Unit;

use App\Support\Transfers\TransferAmountCalculator;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TransferAmountCalculatorTest extends TestCase
{
    #[DataProvider('feeCases')]
    public function test_fee_uses_half_up_rounding_and_minimum(int $amount, int $expected): void
    {
        self::assertSame($expected, (new TransferAmountCalculator())->calculateFee($amount));
    }

    public static function feeCases(): array
    {
        return [
            'minimum fee' => [10000, 3000],
            'fee rounds down below half' => [300049, 3000],
            'fee rounds half up' => [300050, 3001],
            'assignment example' => [500000, 5000],
        ];
    }

    public function test_receive_amount_rounds_to_php_centavos(): void
    {
        $calculator = new TransferAmountCalculator();

        self::assertSame(2060000, $calculator->calculateReceiveAmountMinor(500000));
        self::assertSame(41200, $calculator->calculateReceiveAmountMinor(10000));
    }

    public function test_receive_amount_rejects_integer_overflow(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new TransferAmountCalculator())->calculateReceiveAmountMinor(PHP_INT_MAX);
    }
}
