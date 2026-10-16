<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TransferApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_is_idempotent_and_calculates_amounts_exactly(): void
    {
        $body = [
            'customerId' => 'C001', 'sendCurrency' => 'KRW', 'sendAmount' => '500000',
            'receiveCurrency' => 'PHP', 'recipientName' => 'Juan Dela Cruz',
        ];

        $first = $this->withHeader('Idempotency-Key', 'test-key-1')->postJson('/api/transfers', $body);
        $first->assertCreated()
            ->assertJsonPath('fee', '5000')
            ->assertJsonPath('totalDebit', '505000')
            ->assertJsonPath('receiveAmount', '20600.00');

        $second = $this->withHeader('Idempotency-Key', 'test-key-1')->postJson('/api/transfers', $body);
        $second->assertCreated()->assertJsonPath('transferId', $first->json('transferId'));
        $this->assertDatabaseCount('transfers', 1);

        $changed = $this->withHeader('Idempotency-Key', 'test-key-1')
            ->postJson('/api/transfers', [...$body, 'sendAmount' => '600000']);
        $changed->assertConflict();
    }

    public function test_cancel_only_succeeds_once_and_amount_below_minimum_is_rejected(): void
    {
        $body = [
            'customerId' => 'C001', 'sendCurrency' => 'KRW', 'sendAmount' => '200000',
            'receiveCurrency' => 'PHP', 'recipientName' => 'Juan Dela Cruz',
        ];
        $created = $this->withHeader('Idempotency-Key', 'test-key-2')->postJson('/api/transfers', $body);
        $id = $created->json('transferId');

        $this->postJson("/api/transfers/{$id}/cancel")->assertOk()->assertJsonPath('status', 'CANCELLED');
        $this->postJson("/api/transfers/{$id}/cancel")->assertConflict();

        $low = $this->withHeader('Idempotency-Key', 'test-key-3')
            ->postJson('/api/transfers', [...$body, 'sendAmount' => '5000']);
        $low->assertBadRequest()->assertJsonPath('error', 'VALIDATION_ERROR');
    }
}
