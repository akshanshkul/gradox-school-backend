<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_broadcasts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('sent_by_admin_id');
            $table->string('subject');
            $table->text('body');
            $table->string('type')->default('info'); // info, success, warning, danger
            $table->string('audience')->default('all'); // all | active | trialing | suspended | expired
            $table->string('channel')->default('in_app'); // in_app | email | both
            $table->unsignedInteger('sent_to_count')->default(0);
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index('sent_by_admin_id');
            $table->index('sent_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_broadcasts');
    }
};
