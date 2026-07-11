<?php

namespace App\Services;

use App\Models\School;

/**
 * Single source of truth for "is this school under or over its student cap?".
 *
 * The model already exposes effectiveStudentLimit() / currentStudentCount() /
 * studentUsagePercent() — this service wraps them in product-level concepts:
 *   - canAddStudents(school, n=1)   → boolean
 *   - assertCanAddStudent(school)   → throws OverLimitException on fail
 *   - snapshot(school)              → DTO-ish array used by /school/usage and
 *                                      the platform admin SchoolDetail page
 *
 * Centralising this keeps the limit logic in one place — controllers shouldn't
 * be doing their own math, and the warning/upgrade emails will use exactly
 * the same numbers the UI shows.
 */
class StudentLimitService
{
    /** Thresholds used by both the email cron and the school-side UI. */
    public const WARN_PERCENT = 80;
    public const CRITICAL_PERCENT = 100;

    public function canAddStudents(School $school, int $n = 1): bool
    {
        $limit = $school->effectiveStudentLimit();
        if ($limit === null) return true; // unlimited plan
        return ($school->currentStudentCount() + $n) <= $limit;
    }

    public function assertCanAddStudent(School $school): void
    {
        if (!$this->canAddStudents($school)) {
            throw new OverLimitException(
                'Student cap reached for the ' . ($school->plan?->name ?? 'current') . ' plan. '
                . 'Current usage: ' . $school->currentStudentCount() . ' / ' . $school->effectiveStudentLimit() . '. '
                . 'Upgrade the plan or request an additional-student bump from the platform admin.'
            );
        }
    }

    /**
     * The structured response object the school-side dashboard and the
     * platform admin's SchoolDetail page both consume. Keeping the shape
     * stable means the React side never has to do its own math either.
     */
    public function snapshot(School $school): array
    {
        $plan = $school->plan;
        $count = $school->currentStudentCount();
        $limit = $school->effectiveStudentLimit();
        $percent = $school->studentUsagePercent();
        $bump = (int) ($school->student_limit_override ?? 0);

        return [
            'plan' => $plan ? [
                'id' => $plan->id,
                'name' => $plan->name,
                'slug' => $plan->slug,
                'pricing_model' => $plan->pricing_model,
                'price_per_student' => (float) $plan->price_per_student,
                'price' => (float) $plan->price,
                'currency' => $plan->currency,
                'billing_cycle' => $plan->billing_cycle,
                'max_students' => $plan->max_students,
                'effective_price' => $plan->effectivePriceFor($count),
            ] : null,
            'students' => [
                'count' => $count,
                'plan_limit' => $plan?->max_students,
                'override_bump' => $bump,
                'effective_limit' => $limit,
                'remaining' => $limit === null ? null : max(0, $limit - $count),
                'percent' => $percent,
                'state' => $this->stateFor($percent),
            ],
            'trial' => [
                'extended_until' => $school->trial_extended_until?->toDateString(),
                'is_trial' => $school->subscription_status === 'trialing',
            ],
        ];
    }

    /**
     * Bucketise the raw percent into a state the UI can switch on:
     *   - 'ok'        : under 80%
     *   - 'warn'      : 80–99% — show yellow banner
     *   - 'critical'  : 100%+ — show red modal, block new students
     */
    public function stateFor(int $percent): string
    {
        if ($percent >= self::CRITICAL_PERCENT) return 'critical';
        if ($percent >= self::WARN_PERCENT) return 'warn';
        return 'ok';
    }
}

class OverLimitException extends \RuntimeException
{
    // Marker subclass so controllers can catch this specifically and return
    // a structured 402 / 422 response.
}
