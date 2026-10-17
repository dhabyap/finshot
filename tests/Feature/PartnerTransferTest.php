<?php

namespace Tests\Feature;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class PartnerTransferTest extends TestCase
{
    use RefreshDatabase;

    public function test_daily_limit_is_enforced_when_transfers_are_submitted(): void
    {
        $first = $this->createTransfer('C002', '1500003', 'daily-a');
        $this->postJson('/api/transfers/'.$first['transferId'].'/send')
            ->assertOk()->assertJsonPath('status', 'SENDING');

        $second = $this->createTransfer('C002', '1499997', 'daily-b');
        $this->postJson('/api/transfers/'.$second['transferId'].'/send')
            ->assertOk()->assertJsonPath('status', 'SENDING');

        $third = $this->createTransfer('C002', '10000', 'daily-c');
        $this->postJson('/api/transfers/'.$third['transferId'].'/send')
            ->assertStatus(422)
            ->assertJsonPath('error', 'LIMIT_EXCEEDED');

        $this->assertDatabaseHas('daily_limit_usage', [
            'customer_id' => 'C002',
            'used_amount_krw' => 3_000_000,
        ]);
        $this->assertDatabaseHas('transfers', [
            'transfer_id' => $third['transferId'],
            'status' => 'REQUESTED',
        ]);
    }

    public function test_cancelled_request_does_not_consume_daily_limit(): void
    {
        $cancelled = $this->createTransfer('C001', '2999999', 'cancel-before-send');
        $this->postJson('/api/transfers/'.$cancelled['transferId'].'/cancel')
            ->assertOk()->assertJsonPath('status', 'CANCELLED');

        $sendable = $this->createTransfer('C001', '2999993', 'limit-after-cancel');
        $this->postJson('/api/transfers/'.$sendable['transferId'].'/send')
            ->assertOk()->assertJsonPath('status', 'SENDING');

        $this->assertDatabaseHas('daily_limit_usage', [
            'customer_id' => 'C001',
            'used_amount_krw' => 2_999_993,
        ]);
    }

    public function test_daily_limit_resets_at_seoul_midnight(): void
    {
        try {
            Carbon::setTestNow(Carbon::parse('2026-10-16 23:59:59 Asia/Seoul'));
            $beforeMidnight = $this->createTransfer('C001', '1999993', 'seoul-day-one');
            $this->postJson('/api/transfers/'.$beforeMidnight['transferId'].'/send')
                ->assertOk()->assertJsonPath('status', 'SENDING');

            Carbon::setTestNow(Carbon::parse('2026-10-17 00:00:00 Asia/Seoul'));
            $afterMidnight = $this->createTransfer('C001', '2999999', 'seoul-day-two');
            $this->postJson('/api/transfers/'.$afterMidnight['transferId'].'/send')
                ->assertOk()->assertJsonPath('status', 'SENDING');

            $this->assertDatabaseHas('daily_limit_usage', [
                'customer_id' => 'C001', 'limit_date' => '2026-10-16', 'used_amount_krw' => 1_999_993,
            ]);
            $this->assertDatabaseHas('daily_limit_usage', [
                'customer_id' => 'C001', 'limit_date' => '2026-10-17', 'used_amount_krw' => 2_999_999,
            ]);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_replayed_send_is_idempotent_and_does_not_call_partner_twice(): void
    {
        Log::spy();
        $created = $this->createTransfer('C001', '500003', 'send-replay');
        $first = $this->postJson('/api/transfers/'.$created['transferId'].'/send')
            ->assertOk()->assertJsonPath('status', 'SENDING')->json();
        $second = $this->postJson('/api/transfers/'.$created['transferId'].'/send')
            ->assertOk()->assertJsonPath('status', 'SENDING')->json();

        $this->assertSame($first['partnerRef'], $second['partnerRef']);
        $this->assertDatabaseHas('daily_limit_usage', [
            'customer_id' => 'C001',
            'used_amount_krw' => 500_003,
        ]);
        Log::shouldHaveReceived('info')->once();
    }

    public function test_callback_is_idempotent_and_completes_only_sending_transfer(): void
    {
        $created = $this->createTransfer('C001', '500003', 'callback-key');
        $sending = $this->postJson('/api/transfers/'.$created['transferId'].'/send')
            ->assertOk()->assertJsonPath('status', 'SENDING');
        $partnerRef = $sending->json('partnerRef');
        $callback = ['partnerRef' => $partnerRef, 'eventId' => 'evt-0001', 'status' => 'COMPLETED'];

        $this->postJson('/api/callbacks/partner', $callback)
            ->assertOk()->assertJsonPath('status', 'COMPLETED');
        $this->postJson('/api/callbacks/partner', $callback)
            ->assertOk()->assertJsonPath('status', 'COMPLETED');

        $this->assertDatabaseCount('partner_callbacks', 1);
        $this->assertDatabaseHas('transfers', [
            'transfer_id' => $created['transferId'],
            'status' => 'COMPLETED',
        ]);
    }

    public function test_deterministic_partner_failure_retries_three_times_and_releases_daily_usage(): void
    {
        Log::spy();
        $created = $this->createTransfer('C003', '10000', 'failure-key');
        $this->postJson('/api/transfers/'.$created['transferId'].'/send')
            ->assertOk()
            ->assertJsonPath('status', 'FAILED')
            ->assertJsonPath('failureReason', 'Partner rejected all 3 attempts');
        Log::shouldHaveReceived('info')->times(3);

        $this->assertDatabaseHas('daily_limit_usage', [
            'customer_id' => 'C003',
            'used_amount_krw' => 0,
        ]);
        $this->assertDatabaseHas('transfers', [
            'transfer_id' => $created['transferId'],
            'status' => 'FAILED',
        ]);

        $afterFailure = $this->createTransfer('C003', '2999999', 'after-failure-key');
        $this->postJson('/api/transfers/'.$afterFailure['transferId'].'/send')
            ->assertOk()->assertJsonPath('status', 'SENDING');
    }

    public function test_callback_rejects_unknown_reference_and_non_sending_transfer(): void
    {
        $this->postJson('/api/callbacks/partner', [
            'partnerRef' => 'unknown-ref', 'eventId' => 'evt-x', 'status' => 'COMPLETED',
        ])->assertNotFound()->assertJsonPath('error', 'NOT_FOUND');

        $created = $this->createTransfer('C001', '500003', 'callback-state');
        $this->postJson('/api/callbacks/partner', [
            'partnerRef' => 'unused', 'eventId' => 'evt-before-send', 'status' => 'COMPLETED',
        ])->assertNotFound();

        $this->assertDatabaseCount('partner_callbacks', 0);
        $this->assertDatabaseHas('transfers', ['transfer_id' => $created['transferId'], 'status' => 'REQUESTED']);
    }

    private function createTransfer(string $customerId, string $amount, string $key): array
    {
        return $this->withHeader('Idempotency-Key', $key)->postJson('/api/transfers', [
            'customerId' => $customerId,
            'sendCurrency' => 'KRW',
            'sendAmount' => $amount,
            'receiveCurrency' => 'PHP',
            'recipientName' => 'Test Recipient',
        ])->assertCreated()->json();
    }
}
