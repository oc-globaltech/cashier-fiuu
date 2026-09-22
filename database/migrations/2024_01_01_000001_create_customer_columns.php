<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('fiuu_token')->nullable()->index();
            $table->string('fiuu_card_brand')->nullable();
            $table->string('fiuu_card_last_four', 4)->nullable();
            $table->timestamp('trial_ends_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // SQLite refuses to drop a column an index still points at.
            $table->dropIndex(['fiuu_token']);

            $table->dropColumn([
                'fiuu_token',
                'fiuu_card_brand',
                'fiuu_card_last_four',
                'trial_ends_at',
            ]);
        });
    }
};
