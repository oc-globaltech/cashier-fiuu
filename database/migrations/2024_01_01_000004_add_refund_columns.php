<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            // A refund is a transaction in its own right, hanging off the
            // payment it gives back, so its status can be settled separately.
            $table->unsignedBigInteger('parent_id')->nullable()->after('subscription_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex(['parent_id']);

            $table->dropColumn('parent_id');
        });
    }
};
