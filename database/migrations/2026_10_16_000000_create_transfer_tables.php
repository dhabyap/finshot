<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table): void {
            $table->string('customer_id', 10)->primary();
            $table->string('name', 100);
        });

        Schema::create('transfers', function (Blueprint $table): void {
            $table->string('transfer_id', 36)->primary();
            $table->string('customer_id', 10);
            $table->string('idempotency_key', 100)->unique();
            $table->char('request_hash', 64);
            $table->string('send_currency', 3);
            $table->unsignedBigInteger('send_amount_krw');
            $table->unsignedBigInteger('fee_krw');
            $table->unsignedBigInteger('total_debit_krw');
            $table->unsignedBigInteger('receive_amount_minor');
            $table->string('receive_currency', 3);
            $table->string('recipient_name', 100);
            $table->string('status', 20)->default('REQUESTED');
            $table->string('failure_reason')->nullable();
            $table->string('partner_ref', 100)->nullable()->unique();
            $table->timestamps();
            $table->foreign('customer_id')->references('customer_id')->on('customers');
            $table->index(['customer_id', 'created_at']);
            $table->index('status');
        });

        Schema::create('partner_callbacks', function (Blueprint $table): void {
            $table->string('event_id', 100)->primary();
            $table->string('transfer_id', 36);
            $table->timestamps();
            $table->foreign('transfer_id')->references('transfer_id')->on('transfers');
        });

        DB::table('customers')->insert([
            ['customer_id' => 'C001', 'name' => 'Kim'],
            ['customer_id' => 'C002', 'name' => 'Lee'],
            ['customer_id' => 'C003', 'name' => 'Park'],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_callbacks');
        Schema::dropIfExists('transfers');
        Schema::dropIfExists('customers');
    }
};
