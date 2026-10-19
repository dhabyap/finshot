<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('transfers', 'limit_date')) {
            Schema::table('transfers', function (Blueprint $table): void {
                $table->date('limit_date')->nullable()->index();
            });
        }

        if (!Schema::hasTable('daily_limit_usage')) {
            Schema::create('daily_limit_usage', function (Blueprint $table): void {
                $table->string('customer_id', 10);
                $table->date('limit_date');
                $table->unsignedBigInteger('used_amount_krw')->default(0);
                $table->timestamps();
                $table->primary(['customer_id', 'limit_date']);
                $table->foreign('customer_id')->references('customer_id')->on('customers');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_limit_usage');

        if (Schema::hasColumn('transfers', 'limit_date')) {
            Schema::table('transfers', function (Blueprint $table): void {
                $table->dropIndex(['limit_date']);
                $table->dropColumn('limit_date');
            });
        }
    }
};
