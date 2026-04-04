<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            $table->string('plan_name')->default('Grow')->after('name');
            $table->string('subscription_status')->default('trialing')->after('plan_name');
            $table->timestamp('subscription_expires_at')->nullable()->after('subscription_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            $table->dropColumn(['plan_name', 'subscription_status', 'subscription_expires_at']);
        });
    }
};
