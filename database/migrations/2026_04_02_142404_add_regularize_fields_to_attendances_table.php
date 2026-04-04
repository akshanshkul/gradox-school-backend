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
        Schema::table('attendances', function (Blueprint $table) {
            $table->boolean('is_regularized')->default(false)->after('remarks');
            $table->text('regularize_remark')->nullable()->after('is_regularized');
            $table->foreignId('regularized_by')->nullable()->constrained('users')->onDelete('set null')->after('regularize_remark');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropForeign(['regularized_by']);
            $table->dropColumn(['is_regularized', 'regularize_remark', 'regularized_by']);
        });
    }
};
