<?php

namespace App\Services\StudentImport;

/**
 * Builds the list of errors + warnings for a single normalized row.
 * Errors block import; warnings don't. Each issue is a small dict:
 *   { field, code, message, suggestion? }
 *
 * Validation is pure — no DB lookups (those are done by the resolver and
 * the duplicate detector). Idempotent so we can re-run after edits.
 */
class RowValidator
{
    private const REQUIRED = [
        'admission_no'      => 'Admission number',
        'student_name'      => 'Student name',
        'class'             => 'Class',
        'section'           => 'Section',
        'academic_session'  => 'Academic session',
        'gender'            => 'Gender',
        'date_of_birth'     => 'Date of birth',
    ];

    /**
     * @param array $raw         The original cells (for error messages)
     * @param array $normalized  Cleaned values from RowNormalizer
     * @return array{errors: array, warnings: array, status: string}
     */
    public function validate(array $raw, array $normalized): array
    {
        $errors = [];
        $warnings = [];

        // ---- Required fields ----
        foreach (self::REQUIRED as $field => $label) {
            $value = $normalized[$field] ?? null;
            if ($value === null || $value === '') {
                $errors[] = [
                    'field' => $field,
                    'code' => 'required',
                    'message' => "{$label} is required",
                ];
            }
        }

        // ---- Date of birth ----
        if (!empty($normalized['date_of_birth'])) {
            $dob = $normalized['date_of_birth'];
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dob)) {
                $errors[] = ['field' => 'date_of_birth', 'code' => 'date_format', 'message' => 'Could not parse date of birth', 'suggestion' => 'Use DD-MM-YYYY, DD/MM/YYYY or YYYY-MM-DD'];
            } elseif (strtotime($dob) > time()) {
                $errors[] = ['field' => 'date_of_birth', 'code' => 'date_future', 'message' => 'Date of birth is in the future'];
            }
        }

        // ---- Admission date ----
        if (!empty($normalized['admission_date'])) {
            $ad = $normalized['admission_date'];
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $ad)) {
                $warnings[] = ['field' => 'admission_date', 'code' => 'date_format', 'message' => 'Could not parse admission date', 'suggestion' => 'Use DD-MM-YYYY, DD/MM/YYYY or YYYY-MM-DD'];
            } elseif (strtotime($ad) > time()) {
                $warnings[] = ['field' => 'admission_date', 'code' => 'date_future', 'message' => 'Admission date is in the future'];
            }
        }

        // ---- Gender ----
        if (!empty($normalized['gender']) && !in_array($normalized['gender'], ['male', 'female', 'other'], true)) {
            $errors[] = ['field' => 'gender', 'code' => 'gender_invalid', 'message' => "Gender must be Male, Female or Other (got: '" . ($raw['gender'] ?? '') . "')"];
        }

        // ---- Mobile numbers ----
        foreach (['father_mobile', 'mother_mobile', 'guardian_mobile'] as $field) {
            $val = $normalized[$field] ?? null;
            if ($val !== null && $val !== '' && !preg_match('/^\d{10}$/', $val)) {
                $errors[] = ['field' => $field, 'code' => 'mobile_format', 'message' => 'Mobile must be 10 digits', 'suggestion' => substr(preg_replace('/\D+/', '', $raw[$field] ?? ''), -10)];
            }
        }

        // ---- Email addresses ----
        foreach (['email', 'father_email', 'mother_email'] as $field) {
            $val = $normalized[$field] ?? null;
            if ($val !== null && $val !== '' && !filter_var($val, FILTER_VALIDATE_EMAIL)) {
                $errors[] = ['field' => $field, 'code' => 'email_format', 'message' => 'Invalid email format'];
            }
        }

        // ---- Aadhaar ----
        if (!empty($normalized['aadhaar_no']) && !preg_match('/^\d{12}$/', $normalized['aadhaar_no'])) {
            $errors[] = ['field' => 'aadhaar_no', 'code' => 'aadhaar_format', 'message' => 'Aadhaar must be exactly 12 digits'];
        }

        // ---- Pincode (soft check) ----
        if (!empty($normalized['pincode']) && !preg_match('/^\d{6}$/', $normalized['pincode'])) {
            $warnings[] = ['field' => 'pincode', 'code' => 'pincode_format', 'message' => 'Indian PIN code should be 6 digits'];
        }

        // ---- Parent details warning (need at least one contactable parent) ----
        $haveFather = !empty($normalized['father_name']) && !empty($normalized['father_mobile']);
        $haveMother = !empty($normalized['mother_name']) && !empty($normalized['mother_mobile']);
        $haveGuardian = !empty($normalized['guardian_name']) && !empty($normalized['guardian_mobile']);
        if (!$haveFather && !$haveMother && !$haveGuardian) {
            $warnings[] = ['field' => 'father_mobile', 'code' => 'no_contactable_parent', 'message' => 'No contactable parent or guardian on file'];
        }

        $status = !empty($errors) ? 'error' : (!empty($warnings) ? 'warning' : 'valid');
        return ['errors' => $errors, 'warnings' => $warnings, 'status' => $status];
    }
}
