<?php

namespace App\Console\Commands;

use App\Models\Plan;
use App\Models\School;
use Illuminate\Console\Command;

/**
 * Heals schools.plan_id / schools.plan_name drift in one shot.
 *
 * Why this command exists: until the School model started auto-syncing
 * these two columns on save, four different call sites wrote to schools
 * and only one of them set the FK. Many rows in production have
 * plan_name='Premium' but plan_id=2 (Free Trial) — the UI then shows the
 * wrong cap on the dashboard and admissions can get wrongly blocked.
 *
 * Run safely any time:
 *   php artisan plans:reconcile          # dry run, prints what would change
 *   php artisan plans:reconcile --apply  # actually write the fixes
 *
 * The command always reports per-school decisions so the operator can
 * audit before applying.
 */
class ReconcileSchoolPlans extends Command
{
    protected $signature = 'plans:reconcile {--apply : Persist fixes instead of dry-run}';
    protected $description = 'Sync schools.plan_id with schools.plan_name; heal drift created before the model-level sync.';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $schools = School::with('plan')->orderBy('id')->get();

        $fallback = Plan::where('slug', 'free-trial')->orWhere('name', 'Free Trial')->first();

        $fixed = 0;
        $unmatchable = 0;
        $clean = 0;

        foreach ($schools as $school) {
            $name = trim((string) $school->plan_name);
            $idPlan = $school->plan;

            // Case A: plan_id set and matches plan_name (canonically) → clean.
            if ($idPlan && $name !== '' && strtolower($idPlan->name) === strtolower($name)) {
                $clean++;
                continue;
            }

            // Case B: plan_id set but plan_name empty or mismatched → trust the FK,
            // overwrite the legacy string with the canonical plan name.
            if ($idPlan) {
                $this->line("School #{$school->id} {$school->name}: plan_name='{$name}' → '{$idPlan->name}' (canonicalised from FK)");
                if ($apply) {
                    $school->forceFill(['plan_name' => $idPlan->name])->save();
                }
                $fixed++;
                continue;
            }

            // Case C: plan_id NULL but plan_name set → look up by name.
            if ($name !== '') {
                $byName = Plan::whereRaw('LOWER(name) = ?', [strtolower($name)])->first();
                if ($byName) {
                    $this->line("School #{$school->id} {$school->name}: plan_id NULL → {$byName->id} ({$byName->name}) by name lookup");
                    if ($apply) {
                        $school->forceFill([
                            'plan_id' => $byName->id,
                            'plan_name' => $byName->name,
                        ])->save();
                    }
                    $fixed++;
                    continue;
                }
            }

            // Case D: nothing usable → fall back to Free Trial if it exists.
            if ($fallback) {
                $this->warn("School #{$school->id} {$school->name}: no plan info ('{$name}') → defaulting to {$fallback->name}");
                if ($apply) {
                    $school->forceFill([
                        'plan_id' => $fallback->id,
                        'plan_name' => $fallback->name,
                    ])->save();
                }
                $fixed++;
                continue;
            }

            $this->error("School #{$school->id} {$school->name}: cannot resolve a plan and no Free Trial fallback exists.");
            $unmatchable++;
        }

        $this->newLine();
        $this->info(sprintf(
            '%s: %d clean, %d fixed%s, %d unmatchable.',
            $apply ? 'APPLIED' : 'DRY RUN',
            $clean,
            $fixed,
            $apply ? '' : ' (would fix)',
            $unmatchable
        ));

        if (!$apply && $fixed > 0) {
            $this->comment('Re-run with --apply to persist the changes above.');
        }

        return $unmatchable > 0 ? 1 : 0;
    }
}
