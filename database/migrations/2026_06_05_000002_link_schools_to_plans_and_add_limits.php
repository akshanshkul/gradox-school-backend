<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One migration, three concerns:
 *
 *   1. schools <-> plans relation. Until now `schools.plan_name` was a free
 *      string, which made "which plan is this school on?" a fragile name
 *      lookup. Add a proper `plan_id` FK + backfill from name.
 *
 *   2. Per-school overrides on the plan:
 *        - student_limit_override : extra students beyond plan's max_students
 *          (negative would be silly so we treat null/0 as no override and
 *          any positive value as an additive bump)
 *        - trial_extended_until   : when set, treat trial as valid through
 *          this date even though subscription_status='trialing' originally
 *          expired earlier. Lets platform admins extend a trial without
 *          flipping the school to a real paid plan.
 *        - student_limit_warning_sent_at : debounce flag — we email the
 *          admin once per 7 days when usage crosses 90 %, not every cron run.
 *
 *   3. Per-student pricing model on plans:
 *        - pricing_model        : 'fixed' (default) or 'per_student'
 *        - price_per_student    : rupees-per-active-student-per-billing-cycle
 *
 *      For a per_student plan, the effective monthly bill is
 *        student_count × price_per_student.
 *      `plans.price` remains the "advertised base" — for per_student plans
 *      it can be set to 0 (we show "₹X per student" instead).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            // Default `fixed` keeps existing rows unchanged in meaning.
            $table->string('pricing_model', 16)->default('fixed')->after('price');
            $table->decimal('price_per_student', 10, 2)->nullable()->after('pricing_model');
        });

        Schema::table('schools', function (Blueprint $table) {
            // No FK constraint on purpose — keeps a row from being dropped
            // accidentally if a plan is deleted. Application code refuses to
            // delete a plan that's in use anyway.
            $table->unsignedBigInteger('plan_id')->nullable()->after('plan_name');
            $table->index('plan_id');

            $table->integer('student_limit_override')->default(0)->after('grace_days');
            $table->date('trial_extended_until')->nullable()->after('subscription_expires_at');
            $table->timestamp('student_limit_warning_sent_at')->nullable()->after('trial_extended_until');
        });

        // Backfill plan_id from existing plan_name. Case-insensitive match.
        // Schools whose name doesn't match any catalog row stay on plan_id=null
        // and will be treated as "no plan" by application logic (e.g. limit
        // checks fall back to the platform default).
        $plans = DB::table('plans')->get(['id', 'name', 'slug']);
        foreach ($plans as $p) {
            DB::table('schools')
                ->whereNull('plan_id')
                ->whereRaw('LOWER(plan_name) = ?', [strtolower($p->name)])
                ->update(['plan_id' => $p->id]);
        }
    }

    public function down(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            $table->dropIndex(['plan_id']);
            $table->dropColumn(['plan_id', 'student_limit_override', 'trial_extended_until', 'student_limit_warning_sent_at']);
        });
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn(['pricing_model', 'price_per_student']);
        });
    }
};
