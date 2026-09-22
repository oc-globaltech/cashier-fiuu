<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id');
            $table->string('type');
            $table->string('plan');
            $table->string('fiuu_status');
            $table->string('fiuu_token')->nullable();
            $table->unsignedBigInteger('amount');
            $table->string('currency', 3);
            $table->string('interval');
            $table->unsignedInteger('interval_count')->default(1);
            $table->unsignedInteger('quantity')->default(1);
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamp('next_billing_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'fiuu_status']);
            $table->index('next_billing_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
