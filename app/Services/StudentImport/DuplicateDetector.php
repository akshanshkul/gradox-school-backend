<?php

namespace App\Services\StudentImport;

use App\Models\Student;
use Illuminate\Support\Collection;

/**
 * Identifies whether a normalized row already corresponds to an existing
 * student in the school. Used by both per-row validation (to warn the
 * admin) and by the commit step (to skip / update).
 *
 * Matching strategies, in order of confidence:
 *   1. admission_no === admission_no   (highest confidence)
 *   2. name + DOB + father_mobile      (fallback, useful when admission_no missing)
 */
class DuplicateDetector
{
    /** [string admission_no_lowercase => Student] */
    private Collection $byAdmission;
    /** [string composite_key => Student] */
    private Collection $byNameDob;

    public function __construct(int $schoolId)
    {
        $students = Student::where('school_id', $schoolId)
            ->select('id', 'school_id', 'name', 'admission_number', 'date_of_birth', 'father_mobile')
            ->get();

        $this->byAdmission = $students
            ->filter(fn($s) => !empty($s->admission_number))
            ->keyBy(fn($s) => strtolower(trim((string) $s->admission_number)));

        $this->byNameDob = $students->keyBy(function ($s) {
            return strtolower(trim((string) $s->name))
                . '|' . (string) $s->date_of_birth
                . '|' . (string) $s->father_mobile;
        });
    }

    /**
     * @return array|null { student_id, reason }
     */
    public function match(array $normalized): ?array
    {
        $adm = strtolower(trim((string) ($normalized['admission_no'] ?? '')));
        if ($adm !== '' && isset($this->byAdmission[$adm])) {
            return [
                'student_id' => $this->byAdmission[$adm]->id,
                'reason' => 'admission_no',
            ];
        }

        $name = strtolower(trim((string) ($normalized['student_name'] ?? '')));
        $dob = (string) ($normalized['date_of_birth'] ?? '');
        $fm = (string) ($normalized['father_mobile'] ?? '');
        if ($name !== '' && $dob !== '' && $fm !== '') {
            $key = $name . '|' . $dob . '|' . $fm;
            if (isset($this->byNameDob[$key])) {
                return [
                    'student_id' => $this->byNameDob[$key]->id,
                    'reason' => 'name_dob_father_mobile',
                ];
            }
        }
        return null;
    }
}
