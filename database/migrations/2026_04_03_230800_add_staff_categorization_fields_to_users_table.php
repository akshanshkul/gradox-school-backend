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
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_teaching')->default(true)->after('role')->index();
            $table->string('staff_subtype')->nullable()->after('is_teaching')->index();
        });

        // Data migration: Move existing JSON data to the new columns
        $users = DB::table('users')->whereNotNull('teacher_details')->get();
        
        foreach ($users as $user) {
            $details = json_decode($user->teacher_details, true);
            if ($details && isset($details['is_teaching'])) {
                DB::table('users')->where('id', $user->id)->update([
                    'is_teaching' => $details['is_teaching'],
                    'staff_subtype' => $details['staff_subtype'] ?? null,
                ]);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['is_teaching']);
            $table->dropIndex(['staff_subtype']);
            $table->dropColumn(['is_teaching', 'staff_subtype']);
        });
    }
};
