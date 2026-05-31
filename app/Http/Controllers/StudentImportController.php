<?php

namespace App\Http\Controllers;

use App\Models\Grade;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Section;
use App\Models\Session as AcademicSession;
use App\Models\Student;
use App\Models\StudentAcademicRecord;
use App\Services\StudentImport\RowNormalizer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Simple flow:
 *   - The browser parses + validates the spreadsheet entirely client-side
 *     using the xlsx (SheetJS) library + matching JS rules.
 *   - When every row is green, the admin clicks Submit and we receive an
 *     array of already-validated rows.
 *   - We create any missing master data (sessions / grades / sections / classes),
 *     then insert students + academic records inside one DB transaction.
 *   - Per-row success / failure is returned so the UI can highlight what got
 *     in and what didn't.
 *
 * The Phase 18a staging tables are kept around for future workflows but are
 * not touched here.
 */
class StudentImportController extends Controller
{
    /** GET /api/school/imports/template — header-only CSV the admin downloads. */
    public function template(): StreamedResponse
    {
        $headers = [
            'admission_no', 'roll_no', 'student_name', 'gender', 'date_of_birth',
            'class', 'section', 'academic_session', 'admission_date',
            'blood_group', 'aadhaar_no', 'category', 'religion', 'house',
            'father_name', 'father_mobile', 'father_email', 'father_occupation',
            'mother_name', 'mother_mobile', 'mother_email', 'mother_occupation',
            'guardian_name', 'guardian_relation', 'guardian_mobile',
            'address_line1', 'address_line2', 'city', 'state', 'pincode',
            'transport_required', 'transport_route', 'hostel_required',
            'fee_structure', 'fee_category', 'concession', 'email',
        ];
        return response()->streamDownload(function () use ($headers) {
            $h = fopen('php://output', 'w');
            fputcsv($h, $headers);
            fputcsv($h, [
                'ADM2026-001', '01', 'Aarav Sharma', 'Male', '2014-05-12',
                '5', 'A', '2025-2026', '2025-04-01',
                'O+', '123456789012', 'General', 'Hindu', 'Red',
                'Rakesh Sharma', '9876543210', 'rakesh@example.com', 'Engineer',
                'Priya Sharma', '9876543211', 'priya@example.com', 'Doctor',
                '', '', '',
                '12 MG Road', '', 'Delhi', 'DL', '110001',
                'yes', 'Route 3', 'no',
                'Standard', 'General', '0', 'aarav@example.com',
            ]);
            fclose($h);
        }, 'student-import-template.csv', ['Content-Type' => 'text/csv']);
    }

    /**
     * GET /api/school/imports/existing-master
     * Returns the school's existing sessions, grades, sections, classes and
     * admission numbers so the browser can normalize / match against them
     * locally — no per-row DB round-trip during validation.
     *
     * Hardened against large schools:
     *   - bumps PHP execution limit (default 60s on most PHP-FPM configs)
     *   - raw DB::table queries skip Eloquent hydration
     *   - response is cached per school for 60s; second hit is instant
     *   - `?fresh=1` (sent by the page after a successful commit) busts the cache
     */
    public function existingMaster(Request $request)
    {
        $schoolId = $request->user()->school_id;
        if (function_exists('set_time_limit')) @set_time_limit(180);

        $bypass = $request->boolean('fresh');
        $cacheKey = "school_{$schoolId}_imports_existing_master_v2";

        $payload = $bypass ? null : \App\Services\SafeCache::get($cacheKey);
        if (!$payload) {
            $sessions = \DB::table('sessions')
                ->where('school_id', $schoolId)
                ->select('id', 'name')
                ->get()
                ->map(fn($s) => ['id' => (int) $s->id, 'name' => (string) $s->name])
                ->all();

            $grades = \DB::table('grades')
                ->where('school_id', $schoolId)
                ->select('id', 'name')
                ->get()
                ->map(fn($g) => ['id' => (int) $g->id, 'name' => (string) $g->name])
                ->all();

            $sections = \DB::table('sections')
                ->where('school_id', $schoolId)
                ->select('id', 'name')
                ->get()
                ->map(fn($s) => ['id' => (int) $s->id, 'name' => (string) $s->name])
                ->all();

            // Single JOIN instead of with(['grade', 'section']) — avoids N+1
            // hydration overhead for schools with many classes.
            $classes = \DB::table('school_classes')
                ->leftJoin('grades', 'school_classes.grade_id', '=', 'grades.id')
                ->leftJoin('sections', 'school_classes.section_id', '=', 'sections.id')
                ->where('school_classes.school_id', $schoolId)
                ->select(
                    'school_classes.id',
                    'school_classes.grade_id',
                    'school_classes.section_id',
                    'grades.name as grade_name',
                    'sections.name as section_name'
                )
                ->get()
                ->map(fn($c) => [
                    'id' => (int) $c->id,
                    'grade_id' => (int) $c->grade_id,
                    'section_id' => (int) $c->section_id,
                    'label' => (string) $c->grade_name . ' - ' . (string) $c->section_name,
                ])
                ->all();

            // Raw pluck of just one column — fast even at 100k+ rows.
            $admissionNumbers = \DB::table('students')
                ->where('school_id', $schoolId)
                ->whereNotNull('admission_number')
                ->pluck('admission_number')
                ->all();

            $payload = [
                'sessions' => $sessions,
                'grades' => $grades,
                'sections' => $sections,
                'classes' => $classes,
                'admission_numbers' => $admissionNumbers,
            ];

            // Cache for 60s. The page busts it via ?fresh=1 after every commit.
            \App\Services\SafeCache::put($cacheKey, $payload, 60);
        }

        return $this->successResponse($payload);
    }

    /**
     * POST /api/school/imports/materialize-master
     * Body: { sessions: ['2026-2027'], grades: ['11'], sections: ['F'],
     *         classes: [{ grade: '11', section: 'F' }] }
     *
     * Creates missing sessions / grades / sections / classes for this school,
     * reusing anything that canonically already exists. Returns counts of
     * what was created so the UI can render "Sessions done ✓" etc.
     *
     * Idempotent — calling twice with the same payload creates nothing new.
     */
    public function materializeMaster(Request $request)
    {
        $payload = $request->validate([
            'sessions' => 'sometimes|array',
            'grades' => 'sometimes|array',
            'sections' => 'sometimes|array',
            'classes' => 'sometimes|array',
        ]);

        $user = $request->user();
        $school = $user->school;
        if (!$school) {
            return $this->errorResponse('No school context for this user', 422);
        }

        if (function_exists('set_time_limit')) @set_time_limit(180);

        $beforeSessions = AcademicSession::where('school_id', $school->id)->count();
        $beforeGrades = Grade::where('school_id', $school->id)->count();
        $beforeSections = Section::where('school_id', $school->id)->count();
        $beforeClasses = SchoolClass::where('school_id', $school->id)->count();

        try {
            DB::transaction(function () use ($school, $payload) {
                $g = $this->materializeGrades($school->id, $payload['grades'] ?? []);
                $sec = $this->materializeSections($school->id, $payload['sections'] ?? []);
                $this->materializeSessions($school->id, $payload['sessions'] ?? []);
                $this->materializeClasses($school->id, $payload['classes'] ?? [], $g, $sec);
            });
        } catch (\Throwable $e) {
            Log::error('Master materialization failed', ['error' => $e->getMessage()]);
            return $this->errorResponse('Could not materialize master data: ' . $e->getMessage(), 500);
        }

        \App\Services\SafeCache::forget("school_{$school->id}_imports_existing_master_v2");

        return $this->successResponse([
            'sessions_created' => AcademicSession::where('school_id', $school->id)->count() - $beforeSessions,
            'grades_created' => Grade::where('school_id', $school->id)->count() - $beforeGrades,
            'sections_created' => Section::where('school_id', $school->id)->count() - $beforeSections,
            'classes_created' => SchoolClass::where('school_id', $school->id)->count() - $beforeClasses,
        ], 'Master data ready');
    }

    /**
     * POST /api/school/imports/commit
     * Body: { rows: [...] }   (capped at 25 rows per call)
     *
     * Inserts students + academic records for a *batch* of pre-validated rows.
     * Caller is expected to have invoked /materialize-master first so the
     * lookup of sessions / grades / sections / classes is fast and complete.
     * Returns per-row outcomes; the caller drives batching.
     */
    public function commit(Request $request)
    {
        $payload = $request->validate([
            // Capped at 25 so each call stays well under PHP execution + DB
            // timeout limits. The caller batches large uploads into many
            // sequential commits.
            'rows' => 'required|array|min:1|max:25',
            'rows.*' => 'array',
            // approval is now optional — materialize-master should have been
            // called separately, but we still accept it for backwards-compat
            // / robustness when invoked standalone.
            'approval' => 'sometimes|array',
            'approval.new_sessions' => 'sometimes|array',
            'approval.new_grades' => 'sometimes|array',
            'approval.new_sections' => 'sometimes|array',
            'approval.new_classes' => 'sometimes|array',
        ]);

        $user = $request->user();
        /** @var School $school */
        $school = $user->school;
        if (!$school) {
            return $this->errorResponse('No school context for this user', 422);
        }

        $approval = $payload['approval'] ?? [];
        $rows = $payload['rows'];

        // Gather every master record actually needed by the uploaded rows.
        // We then union with the explicit approval list, so the import works
        // whether or not the client sent a perfect delta. Anything missing
        // gets auto-created in the same transaction as the students.
        $neededSessions = [];
        $neededGrades = [];
        $neededSections = [];
        $neededClasses = [];
        foreach ($rows as $r) {
            if (!empty($r['academic_session'])) $neededSessions[] = (string) $r['academic_session'];
            if (!empty($r['class'])) $neededGrades[] = (string) $r['class'];
            if (!empty($r['section'])) $neededSections[] = (string) $r['section'];
            if (!empty($r['class']) && !empty($r['section'])) {
                $neededClasses[] = ['grade' => (string) $r['class'], 'section' => (string) $r['section']];
            }
        }
        $neededSessions = array_values(array_unique(array_merge($neededSessions, $approval['new_sessions'] ?? [])));
        $neededGrades = array_values(array_unique(array_merge($neededGrades, $approval['new_grades'] ?? [])));
        $neededSections = array_values(array_unique(array_merge($neededSections, $approval['new_sections'] ?? [])));
        $neededClasses = array_values(array_unique(
            array_merge($neededClasses, $approval['new_classes'] ?? []),
            SORT_REGULAR
        ));

        $created = 0;
        $failures = [];

        try {
            DB::transaction(function () use ($school, $rows, $neededSessions, $neededGrades, $neededSections, $neededClasses, &$created, &$failures) {
                // 1. Materialise master data — reuses existing rows where they
                //    canonically match, creates anything truly new.
                $sessionByName = $this->materializeSessions($school->id, $neededSessions);
                $gradeByName = $this->materializeGrades($school->id, $neededGrades);
                $sectionByName = $this->materializeSections($school->id, $neededSections);
                $classByPair = $this->materializeClasses($school->id, $neededClasses, $gradeByName, $sectionByName);

                // 2. Insert each row as a student + academic record. Rows are
                //    validated client-side but we still defend against bad data here.
                foreach ($rows as $i => $r) {
                    $rowNumber = $r['_row_number'] ?? ($i + 1);
                    try {
                        $this->insertOne($school->id, $r, $sessionByName, $gradeByName, $sectionByName, $classByPair);
                        $created++;
                    } catch (\Throwable $e) {
                        $failures[] = [
                            'row_number' => $rowNumber,
                            'admission_no' => $r['admission_no'] ?? null,
                            'error' => $e->getMessage(),
                        ];
                        Log::warning('Student import row failed', [
                            'row' => $rowNumber,
                            'error' => $e->getMessage(),
                            'school_id' => $school->id,
                        ]);
                    }
                }
            });
        } catch (\Throwable $e) {
            Log::error('Student import commit aborted', ['error' => $e->getMessage()]);
            return $this->errorResponse('Import aborted: ' . $e->getMessage(), 500);
        }

        // Newly-created students need to appear in the cache for subsequent uploads
        \App\Services\SafeCache::forget("school_{$school->id}_imports_existing_master_v2");

        return $this->successResponse([
            'created' => $created,
            'failed_count' => count($failures),
            'failures' => $failures,
        ], "Imported {$created} student(s)");
    }

    // ------------------------------------------------------------------
    // helpers
    // ------------------------------------------------------------------

    /**
     * @return array<string,int> Map of EVERY raw session-name variant the
     * caller might use ⇒ the canonical session id. Both the original sheet
     * value and any canonical form land in the map so insertOne() can do a
     * direct lookup with whatever string it has.
     */
    private function materializeSessions(int $schoolId, array $newNames): array
    {
        $existing = AcademicSession::where('school_id', $schoolId)->get(['id', 'name']);

        // Canonical-key → session id, plus exposed name → id for every name we see.
        $byCanonical = [];
        $map = [];
        foreach ($existing as $s) {
            $key = $this->sessionKey((string) $s->name);
            // If the school already had duplicates ("2026-27" + "2026-2027"),
            // collapse to the lowest id deterministically — the importer
            // doesn't fix existing dupes here, but at least won't make the
            // mismatch worse.
            if (!isset($byCanonical[$key])) {
                $byCanonical[$key] = $s->id;
            } else {
                $byCanonical[$key] = min($byCanonical[$key], $s->id);
            }
            $map[(string) $s->name] = $byCanonical[$key];
        }

        foreach ($newNames as $name) {
            $name = (string) $name;
            if ($name === '') continue;
            $key = $this->sessionKey($name);

            // Reuse the existing session for this canonical year if any —
            // prevents "2026-27" and "2026-2027" from spawning twin rows.
            if (isset($byCanonical[$key])) {
                $map[$name] = $byCanonical[$key];
                continue;
            }

            [$start, $end] = $this->sessionDateRange($name);
            $s = AcademicSession::create([
                'school_id' => $schoolId,
                'name' => $name,
                'start_date' => $start,
                'end_date' => $end,
                'is_active' => false,
            ]);
            $byCanonical[$key] = $s->id;
            $map[$name] = $s->id;
        }
        return $map;
    }

    /**
     * Canonical key for an academic-session label. "2026-27", "2026-2027",
     * "26-27", "2026 to 2027", "2026/27" all collapse to "2026-2027" so the
     * importer treats them as the same year.
     */
    private function sessionKey(string $name): string
    {
        $name = trim($name);
        if ($name === '') return '';
        if (preg_match('/(\d{2,4})\D+(\d{2,4})/', $name, $m)) {
            $a = (int) $m[1]; $b = (int) $m[2];
            // Expand 2-digit years assuming current century (so "26" → 2026,
            // "99" → 2099 — fine for school sessions, no Y2K reruns).
            if ($a < 100) $a += 2000;
            if ($b < 100) $b += ($b < $a % 100 ? 2100 : 2000);
            return $a . '-' . $b;
        }
        return strtolower(preg_replace('/\s+/', '', $name));
    }

    private function sessionDateRange(string $name): array
    {
        if (preg_match('/(\d{4})\D+(\d{4})/', $name, $m)) {
            return [$m[1] . '-04-01', $m[2] . '-03-31'];
        }
        $now = now()->year;
        return [$now . '-04-01', ($now + 1) . '-03-31'];
    }

    /**
     * Builds a map of canonical grade key (e.g. "1", "lkg") => grade id.
     * Existing grades are normalized to the same canonical key so a school's
     * "Class 1" matches an uploaded "1" / "1st" / "I".
     * Approved-new grades only create a row when nothing canonically maps.
     */
    private function materializeGrades(int $schoolId, array $newNames): array
    {
        $normalizer = new RowNormalizer();
        $existing = Grade::where('school_id', $schoolId)->get(['id', 'name']);

        $byKey = [];
        foreach ($existing as $g) {
            $key = $normalizer->classKey($g->name) ?? strtolower((string) $g->name);
            // Keep the lowest id if the school has duplicates already.
            if (!isset($byKey[$key])) $byKey[$key] = $g->id;
        }

        foreach ($newNames as $name) {
            $name = (string) $name;
            if ($name === '') continue;
            $key = $normalizer->classKey($name) ?? strtolower($name);
            if (isset($byKey[$key])) continue;
            $canonical = $normalizer->classLabel($name) ?? $name;
            $g = Grade::create(['school_id' => $schoolId, 'name' => $canonical]);
            $byKey[$key] = $g->id;
        }
        return $byKey;
    }

    /** Returns map keyed by UPPERCASE section name → id. */
    private function materializeSections(int $schoolId, array $newNames): array
    {
        $byKey = [];
        foreach (Section::where('school_id', $schoolId)->get(['id', 'name']) as $s) {
            $byKey[strtoupper(trim((string) $s->name))] = $s->id;
        }
        foreach ($newNames as $name) {
            $key = strtoupper(trim((string) $name));
            if ($key === '' || isset($byKey[$key])) continue;
            $s = Section::create(['school_id' => $schoolId, 'name' => $key]);
            $byKey[$key] = $s->id;
        }
        return $byKey;
    }

    /**
     * Returns map keyed by "<grade_id>:<section_id>" => school_class_id, with
     * approved new classes materialized.
     */
    /**
     * Resolves each requested (grade, section) pair into a school_classes row,
     * creating it if missing. Uses the same canonical-key lookup as insertOne
     * so the lookup matches what materializeGrades / materializeSections
     * actually stored.
     */
    private function materializeClasses(int $schoolId, array $newClassPairs, array $gradeByName, array $sectionByName): array
    {
        $normalizer = new RowNormalizer();
        $map = [];
        foreach (SchoolClass::where('school_id', $schoolId)->get(['id', 'grade_id', 'section_id']) as $c) {
            $map[$c->grade_id . ':' . $c->section_id] = $c->id;
        }
        foreach ($newClassPairs as $pair) {
            $rawGrade = $pair['grade'] ?? '';
            $rawSection = $pair['section'] ?? '';
            // Mirror the keys that materializeGrades / materializeSections used.
            $gradeKey = $normalizer->classKey($rawGrade) ?? strtolower((string) $rawGrade);
            $sectionKey = strtoupper(trim((string) $rawSection));
            $gradeId = $gradeByName[$gradeKey] ?? null;
            $sectionId = $sectionByName[$sectionKey] ?? null;
            if (!$gradeId || !$sectionId) continue;
            $key = $gradeId . ':' . $sectionId;
            if (isset($map[$key])) continue;
            $c = SchoolClass::create([
                'school_id' => $schoolId,
                'grade_id' => $gradeId,
                'section_id' => $sectionId,
            ]);
            $map[$key] = $c->id;
        }
        return $map;
    }

    private function insertOne(int $schoolId, array $r, array $sessions, array $grades, array $sections, array $classes): void
    {
        $normalizer = new RowNormalizer();
        $sessionName = (string) ($r['academic_session'] ?? '');
        $gradeName = (string) ($r['class'] ?? '');
        $sectionName = (string) ($r['section'] ?? '');

        $sessionId = $sessions[$sessionName] ?? null;
        if (!$sessionId && $sessionName !== '') {
            // Sheet value not in map directly — match by canonical year so
            // "2026-2027" still resolves when the school has "2026-27".
            $wantKey = $this->sessionKey($sessionName);
            foreach ($sessions as $k => $id) {
                if ($this->sessionKey((string) $k) === $wantKey) {
                    $sessionId = $id;
                    break;
                }
            }
        }
        $gradeKey = $normalizer->classKey($gradeName) ?? strtolower($gradeName);
        $sectionKey = strtoupper(trim($sectionName));
        $gradeId = $grades[$gradeKey] ?? null;
        $sectionId = $sections[$sectionKey] ?? null;
        $classId = ($gradeId && $sectionId) ? ($classes[$gradeId . ':' . $sectionId] ?? null) : null;

        if (!$sessionId) throw new \RuntimeException("Session '{$sessionName}' was not approved or could not be created");
        if (!$classId) throw new \RuntimeException("Class '{$gradeName}-{$sectionName}' was not approved or could not be created");

        // Skip if student already exists with this admission number in this school.
        if (Student::where('school_id', $schoolId)->where('admission_number', $r['admission_no'])->exists()) {
            throw new \RuntimeException('Student with this admission number already exists');
        }

        $student = Student::create([
            'school_id' => $schoolId,
            'admission_number' => (string) ($r['admission_no'] ?? ''),
            'name' => (string) ($r['student_name'] ?? ''),
            'email' => $r['email'] ?? '',
            'phone' => $r['father_mobile'] ?? null,
            'gender' => $r['gender'] ?? null,
            'date_of_birth' => $r['date_of_birth'] ?? null,
            'admission_date' => $r['admission_date'] ?? null,
            'parent_name' => $r['father_name'] ?: ($r['guardian_name'] ?? null),
            'parent_phone' => $r['father_mobile'] ?: ($r['guardian_mobile'] ?? null),
            'parent_occupation' => $r['father_occupation'] ?? null,
            'address' => trim(($r['address_line1'] ?? '') . ' ' . ($r['address_line2'] ?? '')) ?: null,
            'aadhaar_number' => $r['aadhaar_no'] ?? null,
            'parent_email' => $r['father_email'] ?: ($r['mother_email'] ?? null),
            'status' => 'active',
        ]);

        // Link student to the academic session + class via the academic record.
        StudentAcademicRecord::create([
            'student_id' => $student->id,
            'school_class_id' => $classId,
            'academic_year' => $sessionId,
            'roll_number' => $r['roll_no'] ?? null,
        ]);
    }
}
