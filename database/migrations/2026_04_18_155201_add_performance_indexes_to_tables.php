<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Timetable entries indexes
        $this->addIndexIfNotExists('timetable_entries', ['day_of_week']);
        $this->addIndexIfNotExists('timetable_entries', ['school_class_id', 'day_of_week']);

        // Attendances indexes
        $this->addIndexIfNotExists('attendances', ['school_id', 'date']);

        // Users indexes
        $this->addIndexIfNotExists('users', ['role_id']);

        // Students indexes
        $this->addIndexIfNotExists('students', ['status']);
        $this->addIndexIfNotExists('students', ['name']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('timetable_entries', function (Blueprint $table) {
            $table->dropIndex(['day_of_week']);
            $table->dropIndex(['school_class_id', 'day_of_week']);
        });

        Schema::table('attendances', function (Blueprint $table) {
            $table->dropIndex(['school_id', 'date']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['role_id']);
        });

        Schema::table('students', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropIndex(['name']);
        });
    }

    /**
     * Helper to add index only if it doesn't exist
     */
    private function addIndexIfNotExists(string $table, array $columns): void
    {
        $indexName = strtolower($table . '_' . implode('_', $columns) . '_index');
        
        $exists = collect(DB::select("SHOW INDEX FROM {$table}"))->contains('Key_name', $indexName);

        if (!$exists) {
            Schema::table($table, function (Blueprint $table) use ($columns) {
                $table->index($columns);
            });
        }
    }
};
