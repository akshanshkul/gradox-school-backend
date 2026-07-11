<?php

namespace Database\Seeders;

use App\Models\Module;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Seeds the initial Module catalog and the dependency graph.
 *
 * This seeder is idempotent: re-running it upserts catalog rows and
 * re-syncs dependencies without duplicating data. Safe to run on
 * production multiple times.
 *
 * Catalog list and dependency graph mirror the plan document — keep
 * them in sync. When adding a new module:
 *   1. Append a row to MODULES below (set sort_order to keep groups
 *      visually adjacent in saas-admin UIs).
 *   2. If the new module needs other modules, add edges to DEPENDS_ON.
 *   3. Re-seed in staging, smoke test, then prod.
 */
class ModuleCatalogSeeder extends Seeder
{
    /**
     * Each row:
     *   [code, name, category, surfaces[], is_core, is_addon, sort_order, icon, description]
     *
     * Codes are stable forever — they appear in middleware, frontend
     * filter strings, dependency graph, and migration backfills.
     * NEVER rename a code; deprecate and add a new one instead.
     */
    private const MODULES = [
        // ----- Core (always on, exempt from gating) -----
        ['auth',           'Authentication',     'Platform', ['admin_web','teacher_app','student_app','parent_app'], true,  false, 100, 'shield-check', 'Sign in, password reset, OTP — required for the platform to work.'],
        ['dashboard',      'Dashboards',         'Platform', ['admin_web','teacher_app','student_app','parent_app'], true,  false, 110, 'layout-dashboard', 'Per-role landing pages with summary widgets.'],
        ['settings',       'Settings',           'Platform', ['admin_web'], true,  false, 120, 'settings', 'School profile, theme, contact details.'],
        ['profile',        'User Profiles',      'Platform', ['admin_web','teacher_app','student_app','parent_app'], true,  false, 130, 'user', 'View / edit own profile.'],

        // ----- Free Trial tier -----
        ['academic_structure', 'Academic Structure', 'Academic', ['admin_web'],                       false, false, 200, 'layers', 'Grades, sections, classes, subjects.'],
        ['attendance',         'Attendance Tracking', 'Academic', ['admin_web','teacher_app','student_app','parent_app'], false, false, 210, 'clipboard-check', 'Daily student & staff attendance.'],
        ['fees',               'Fees & Payments',     'Finance',  ['admin_web','teacher_app','student_app','parent_app'], false, false, 220, 'wallet',        'Fee assignment, online payments, receipts.'],
        ['documents',          'Document Repository', 'Academic', ['admin_web','student_app','parent_app'],                false, false, 230, 'file-text',     'Birth certs, marksheets, ID proofs.'],
        ['circulars',          'Circulars & Notices', 'Communication', ['admin_web','teacher_app','student_app','parent_app'], false, false, 240, 'bell',     'School-wide announcements.'],

        // ----- Basic tier additions -----
        ['homework',     'Homework',          'Academic',   ['admin_web','teacher_app','student_app','parent_app'], false, false, 300, 'book-marked',   'Assign and submit homework per class.'],
        ['exams',        'Exams & Marks',     'Academic',   ['admin_web','teacher_app'],                  false, false, 310, 'trending-up',  'Exam structure, marks entry, grading.'],
        ['report_cards', 'Report Cards',      'Academic',   ['admin_web','teacher_app','student_app','parent_app'], false, false, 320, 'award',     'Auto-generated term result cards.'],
        ['timetable',    'Timetable',         'Academic',   ['admin_web','teacher_app','student_app','parent_app'], false, false, 330, 'calendar', 'Period schedule, substitutions.'],
        ['student_app',  'Student Mobile App','Mobile Apps',['student_app'],                              false, false, 340, 'smartphone',   'Branded student mobile app access.'],

        // ----- Standard tier additions -----
        ['bulk_import',          'Bulk Data Import',         'Operations',    ['admin_web'],                false, false, 400, 'upload', 'Excel-based onboarding for students, staff, marks.'],
        ['landing_page_widgets', 'Public Landing Page',      'Communication', ['admin_web'],                false, false, 410, 'globe',  'Branded public-facing school website.'],
        ['lesson_plans',         'Lesson Plans',             'Academic',      ['admin_web','teacher_app'],  false, false, 420, 'clipboard-list', 'Per-subject lesson plan editor.'],
        ['parent_app',           'Parent Mobile App',        'Mobile Apps',   ['parent_app'],               false, false, 430, 'users',  'Branded parent mobile app access.'],
        ['teacher_app',          'Teacher Mobile App',       'Mobile Apps',   ['teacher_app'],              false, false, 440, 'graduation-cap', 'Branded teacher mobile app access.'],

        // ----- Premium tier additions -----
        // Only modules with REAL code in the platform today are listed.
        // The following were previously seeded but pointed to features
        // that don't exist yet — they've been REMOVED to keep the
        // catalog honest. Add them back here when you build them:
        //   - online_classes  (no controller / no UI)
        //   - library         (no controller / no UI)
        //   - transport       (no controller / no UI)
        //   - inventory       (no controller / no UI)
        //   - whatsapp_alerts (no integration; alerts run on email + FCM)
        // `advanced_analytics` is KEPT because /exams/analytics exists.
        ['advanced_analytics',  'Advanced Analytics',  'Academic',      ['admin_web'],                              false, false, 540, 'bar-chart', 'School-wide performance dashboards.'],
    ];

    /**
     * Dependency edges: [dependent_code, depends_on_code].
     * A module appearing on the LEFT can only be enabled if everything
     * on the RIGHT it's paired with is also enabled.
     *
     * Keep this list small and accurate. Spurious dependencies make
     * SaaS-admin module-management much harder.
     */
    private const DEPENDS_ON = [
        ['attendance',         'academic_structure'],
        ['fees',               'academic_structure'],
        ['homework',           'academic_structure'],
        ['exams',              'academic_structure'],
        ['report_cards',       'exams'],
        ['timetable',          'academic_structure'],
        ['lesson_plans',       'academic_structure'],
        ['bulk_import',        'academic_structure'],
        ['advanced_analytics', 'attendance'],
        ['advanced_analytics', 'exams'],
        ['advanced_analytics', 'fees'],
        ['student_app',        'profile'],
        ['parent_app',         'profile'],
        ['teacher_app',        'profile'],
        // Removed edges referencing modules that no longer exist:
        //   online_classes -> timetable
        //   whatsapp_alerts -> circulars
    ];

    public function run(): void
    {
        // ----- Upsert catalog rows -----
        foreach (self::MODULES as $row) {
            [$code, $name, $category, $surfaces, $isCore, $isAddon, $sort, $icon, $desc] = $row;
            Module::updateOrCreate(
                ['code' => $code],
                [
                    'name'        => $name,
                    'description' => $desc,
                    'category'    => $category,
                    'icon'        => $icon,
                    'surfaces'    => $surfaces,
                    'is_addon'    => $isAddon,
                    'is_core'     => $isCore,
                    'sort_order'  => $sort,
                ]
            );
        }

        // ----- Cleanup orphans -----
        // Any module that existed in a previous catalog version but is
        // no longer in self::MODULES is a vapor / stub module. Remove
        // its rows from every reference table so saas-admin UI stops
        // showing them and the cached bitmaps drop their codes on the
        // next per-school recompute.
        $canonicalCodes = collect(self::MODULES)->pluck(0)->all();
        $orphanIds = Module::whereNotIn('code', $canonicalCodes)->pluck('id')->all();
        if (!empty($orphanIds)) {
            DB::table('module_dependencies')
                ->whereIn('module_id', $orphanIds)
                ->orWhereIn('depends_on_module_id', $orphanIds)
                ->delete();
            DB::table('plan_modules')->whereIn('module_id', $orphanIds)->delete();
            DB::table('school_module_overrides')->whereIn('module_id', $orphanIds)->delete();
            // Hard delete (not soft) — vapor modules should disappear
            // completely, not linger as soft-deleted catalog clutter.
            Module::whereIn('id', $orphanIds)->forceDelete();
            $this->command?->info('Removed ' . count($orphanIds) . ' orphan module(s) from catalog.');
        }

        // ----- Sync dependencies -----
        // Wipe-and-rewrite is safe here because the catalog is the
        // source of truth and the table is tiny.
        DB::table('module_dependencies')->delete();
        $idByCode = Module::pluck('id', 'code')->all();
        $now = now();
        $deps = [];
        foreach (self::DEPENDS_ON as [$dependentCode, $dependsOnCode]) {
            if (!isset($idByCode[$dependentCode]) || !isset($idByCode[$dependsOnCode])) {
                $this->command?->warn("Missing module for dependency: {$dependentCode} → {$dependsOnCode}");
                continue;
            }
            $deps[] = [
                'module_id'            => $idByCode[$dependentCode],
                'depends_on_module_id' => $idByCode[$dependsOnCode],
                'created_at'           => $now,
                'updated_at'           => $now,
            ];
        }
        if (!empty($deps)) {
            DB::table('module_dependencies')->insert($deps);
        }

        // ----- Recompute every school's bitmap -----
        // Cached bitmaps still contain the removed codes from the
        // previous seeding. Recompute resolves them all in a single
        // pass so saas-admin and apps see the trimmed set immediately.
        \App\Models\School::chunkById(100, function ($schools) {
            foreach ($schools as $school) {
                \App\Services\ModuleAccessService::recompute($school);
            }
        });
    }
}
