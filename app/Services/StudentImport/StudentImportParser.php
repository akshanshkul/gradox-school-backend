<?php

namespace App\Services\StudentImport;

use App\Models\School;
use App\Models\StudentImport;
use App\Models\StudentImportRow;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Reads an uploaded spreadsheet, normalizes + validates every row, persists
 * the whole job into student_imports / student_import_rows tables, and
 * returns the StudentImport model populated with rows + delta + distribution.
 *
 * Does NOT touch the real students table. The commit step does that.
 */
class StudentImportParser
{
    /** Canonical column keys the rest of the pipeline expects. */
    public const COLUMNS = [
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

    public function __construct(
        private RowNormalizer $normalizer,
        private RowValidator $validator,
    ) {}

    public function parse(School $school, int $userId, UploadedFile $file): StudentImport
    {
        $storedPath = $file->store('student-imports/' . $school->id);

        $import = StudentImport::create([
            'school_id' => $school->id,
            'uploaded_by_user_id' => $userId,
            'original_filename' => $file->getClientOriginalName(),
            'stored_path' => $storedPath,
            'file_size' => $file->getSize(),
            'mime_type' => $file->getMimeType(),
            'status' => 'parsed',
        ]);

        $rows = Excel::toArray([], $file)[0] ?? [];
        if (empty($rows)) {
            $import->update(['status' => 'failed', 'commit_meta' => ['error' => 'File contains no rows']]);
            return $import;
        }

        $headerRow = array_shift($rows);
        $headerMap = $this->buildHeaderMap($headerRow);

        $resolver = new MasterDataResolver($school);
        $dupDetector = new DuplicateDetector($school->id);

        DB::transaction(function () use ($rows, $headerMap, $import, $resolver, $dupDetector) {
            $batch = [];
            $rowNumber = 1;
            foreach ($rows as $rawRow) {
                $rowNumber++;
                if ($this->isEmptyRow($rawRow)) continue;

                $raw = $this->extractRaw($rawRow, $headerMap);
                $normalized = $this->normalize($raw, $resolver);
                $validation = $this->validator->validate($raw, $normalized);
                $dupe = $dupDetector->match($normalized);

                $batch[] = [
                    'student_import_id' => $import->id,
                    'row_number' => $rowNumber,
                    'raw_data' => json_encode($raw),
                    'normalized_data' => json_encode($normalized),
                    'errors' => json_encode($validation['errors']),
                    'warnings' => json_encode($validation['warnings']),
                    'duplicate_match' => $dupe ? json_encode($dupe) : null,
                    'duplicate_of_student_id' => $dupe['student_id'] ?? null,
                    'status' => $dupe ? 'warning' : $validation['status'],
                    'action' => $dupe ? 'skip' : 'create',
                    'created_at' => now(),
                    'updated_at' => now(),
                ];

                // Flush periodically to avoid an oversize insert
                if (count($batch) >= 500) {
                    StudentImportRow::insert($batch);
                    $batch = [];
                }
            }
            if (!empty($batch)) StudentImportRow::insert($batch);
        });

        $this->recomputeAggregates($import, $resolver);
        return $import->fresh('rows');
    }

    /** Re-run validation on already-staged rows (e.g. after admin edits). */
    public function revalidate(StudentImport $import): StudentImport
    {
        $school = $import->school;
        $resolver = new MasterDataResolver($school);
        $dupDetector = new DuplicateDetector($school->id);

        foreach ($import->rows()->cursor() as $row) {
            $raw = $row->raw_data ?? [];
            $normalized = $this->normalize($raw, $resolver);
            $validation = $this->validator->validate($raw, $normalized);
            $dupe = $dupDetector->match($normalized);
            $row->update([
                'normalized_data' => $normalized,
                'errors' => $validation['errors'],
                'warnings' => $validation['warnings'],
                'duplicate_match' => $dupe,
                'duplicate_of_student_id' => $dupe['student_id'] ?? null,
                'status' => $dupe ? 'warning' : $validation['status'],
                'action' => $row->action === 'skip' ? 'skip' : ($dupe ? 'skip' : 'create'),
            ]);
        }
        $this->recomputeAggregates($import, $resolver);
        return $import->fresh('rows');
    }

    public function recomputeAggregates(StudentImport $import, MasterDataResolver $resolver): void
    {
        $total = $import->rows()->count();
        $valid = $import->rows()->where('status', 'valid')->count();
        $warning = $import->rows()->where('status', 'warning')->count();
        $error = $import->rows()->where('status', 'error')->count();
        $dupes = $import->rows()->whereNotNull('duplicate_of_student_id')->count();

        $import->update([
            'total_rows' => $total,
            'valid_rows' => $valid,
            'warning_rows' => $warning,
            'error_rows' => $error,
            'duplicate_rows' => $dupes,
            'master_data_delta' => $resolver->delta(),
            'distribution' => $this->computeDistribution($import),
        ]);
    }

    private function computeDistribution(StudentImport $import): array
    {
        $byClass = [];
        $bySection = [];
        $byGender = [];
        $byFeeCategory = [];
        $byTransportRoute = [];

        foreach ($import->rows()->cursor() as $row) {
            $n = $row->normalized_data ?? [];
            $byClass[$n['class'] ?? '—'] = ($byClass[$n['class'] ?? '—'] ?? 0) + 1;
            $bySection[$n['section'] ?? '—'] = ($bySection[$n['section'] ?? '—'] ?? 0) + 1;
            $byGender[$n['gender'] ?? 'unspecified'] = ($byGender[$n['gender'] ?? 'unspecified'] ?? 0) + 1;
            $byFeeCategory[$n['fee_category'] ?? '—'] = ($byFeeCategory[$n['fee_category'] ?? '—'] ?? 0) + 1;
            $byTransportRoute[$n['transport_route'] ?? '—'] = ($byTransportRoute[$n['transport_route'] ?? '—'] ?? 0) + 1;
        }

        return compact('byClass', 'bySection', 'byGender', 'byFeeCategory', 'byTransportRoute');
    }

    private function normalize(array $raw, MasterDataResolver $resolver): array
    {
        $session = $this->normalizer->session($raw['academic_session'] ?? null);
        $class = $this->normalizer->classLabel($raw['class'] ?? null);
        $section = $this->normalizer->sectionLabel($raw['section'] ?? null);

        $n = [
            'admission_no'      => $this->normalizer->string($raw['admission_no'] ?? null),
            'roll_no'           => $this->normalizer->string($raw['roll_no'] ?? null),
            'student_name'      => $this->normalizer->string($raw['student_name'] ?? null),
            'gender'            => $this->normalizer->gender($raw['gender'] ?? null),
            'date_of_birth'     => $this->normalizer->date($raw['date_of_birth'] ?? null),
            'class'             => $class,
            'section'           => $section,
            'academic_session'  => $session,
            'admission_date'    => $this->normalizer->date($raw['admission_date'] ?? null),
            'blood_group'       => $this->normalizer->string($raw['blood_group'] ?? null),
            'aadhaar_no'        => $this->normalizer->aadhaar($raw['aadhaar_no'] ?? null),
            'category'          => $this->normalizer->string($raw['category'] ?? null),
            'religion'          => $this->normalizer->string($raw['religion'] ?? null),
            'house'             => $this->normalizer->string($raw['house'] ?? null),
            'father_name'       => $this->normalizer->string($raw['father_name'] ?? null),
            'father_mobile'     => $this->normalizer->mobile($raw['father_mobile'] ?? null),
            'father_email'      => $this->normalizer->email($raw['father_email'] ?? null),
            'father_occupation' => $this->normalizer->string($raw['father_occupation'] ?? null),
            'mother_name'       => $this->normalizer->string($raw['mother_name'] ?? null),
            'mother_mobile'     => $this->normalizer->mobile($raw['mother_mobile'] ?? null),
            'mother_email'      => $this->normalizer->email($raw['mother_email'] ?? null),
            'mother_occupation' => $this->normalizer->string($raw['mother_occupation'] ?? null),
            'guardian_name'     => $this->normalizer->string($raw['guardian_name'] ?? null),
            'guardian_relation' => $this->normalizer->string($raw['guardian_relation'] ?? null),
            'guardian_mobile'   => $this->normalizer->mobile($raw['guardian_mobile'] ?? null),
            'address_line1'     => $this->normalizer->string($raw['address_line1'] ?? null),
            'address_line2'     => $this->normalizer->string($raw['address_line2'] ?? null),
            'city'              => $this->normalizer->string($raw['city'] ?? null),
            'state'             => $this->normalizer->string($raw['state'] ?? null),
            'pincode'           => $this->normalizer->string($raw['pincode'] ?? null),
            'transport_required' => $this->normalizer->bool($raw['transport_required'] ?? null),
            'transport_route'   => $this->normalizer->string($raw['transport_route'] ?? null),
            'hostel_required'   => $this->normalizer->bool($raw['hostel_required'] ?? null),
            'fee_structure'     => $this->normalizer->string($raw['fee_structure'] ?? null),
            'fee_category'      => $this->normalizer->string($raw['fee_category'] ?? null),
            'concession'        => $this->normalizer->string($raw['concession'] ?? null),
            'email'             => $this->normalizer->email($raw['email'] ?? null),
        ];

        // Resolve master IDs (these populate the delta when no match found).
        $sessionId = $resolver->resolveSessionId($session);
        $gradeId = $resolver->resolveGradeId($class);
        $sectionId = $resolver->resolveSectionId($section);
        $n['_session_id'] = $sessionId;
        $n['_grade_id'] = $gradeId;
        $n['_section_id'] = $sectionId;
        $n['_school_class_id'] = $resolver->resolveClassId($gradeId, $sectionId, $class, $section);

        return $n;
    }

    private function buildHeaderMap(array $headerRow): array
    {
        $map = [];
        foreach ($headerRow as $i => $name) {
            $key = $this->canonicalizeHeader((string) $name);
            if (in_array($key, self::COLUMNS, true)) {
                $map[$key] = $i;
            }
        }
        return $map;
    }

    private function canonicalizeHeader(string $name): string
    {
        $k = strtolower(trim($name));
        $k = preg_replace('/[\s\-]+/', '_', $k);
        // Common aliases
        $aliases = [
            'admission_number' => 'admission_no',
            'admno' => 'admission_no',
            'roll_number' => 'roll_no',
            'rollno' => 'roll_no',
            'name' => 'student_name',
            'dob' => 'date_of_birth',
            'session' => 'academic_session',
            'class_name' => 'class',
            'section_name' => 'section',
            'mobile' => 'father_mobile',
            'phone' => 'father_mobile',
            'aadhar' => 'aadhaar_no',
            'aadhaar' => 'aadhaar_no',
            'pin' => 'pincode',
            'pin_code' => 'pincode',
            'address' => 'address_line1',
        ];
        return $aliases[$k] ?? $k;
    }

    private function extractRaw(array $rowCells, array $headerMap): array
    {
        $out = [];
        foreach (self::COLUMNS as $col) {
            $idx = $headerMap[$col] ?? null;
            $out[$col] = $idx === null ? null : ($rowCells[$idx] ?? null);
            if (is_string($out[$col])) $out[$col] = trim($out[$col]);
        }
        return $out;
    }

    private function isEmptyRow(array $cells): bool
    {
        foreach ($cells as $c) {
            if ($c !== null && trim((string) $c) !== '') return false;
        }
        return true;
    }
}
