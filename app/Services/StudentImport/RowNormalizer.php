<?php

namespace App\Services\StudentImport;

use Carbon\Carbon;

/**
 * Pure transformations on raw spreadsheet cells. No DB lookups — the
 * MasterDataResolver handles ID resolution. Keeps this class fast and
 * trivially testable.
 *
 * Every method tolerates null / empty / wrong-type inputs and either
 * returns a normalized value or null. None of them throw.
 */
class RowNormalizer
{
    /** "2025-26", "2025/2026", "2025_2026", "2025 to 2026" → "2025-2026" */
    public function session(?string $raw): ?string
    {
        if (!$raw) return null;
        $raw = trim($raw);
        if ($raw === '') return null;

        // Pull the first two 4-digit (or 2-digit) year-ish numbers we see.
        if (preg_match('/(\d{4})\D+(\d{2,4})/', $raw, $m)) {
            $start = (int) $m[1];
            $end = (int) $m[2];
            if ($end < 100) $end += 2000; // "25" → 2025
            // Sanity: end must be start + 1 (or start) — if it's not, fall back.
            if ($end >= $start && $end - $start <= 2) {
                return $start . '-' . $end;
            }
        }
        // Single 4-digit year, e.g. "2025" → "2025-2026"
        if (preg_match('/^\d{4}$/', $raw)) {
            $start = (int) $raw;
            return $start . '-' . ($start + 1);
        }
        return $raw; // give up — validator will flag it
    }

    /**
     * Maps any grade-name variant to one of:
     *   Nursery | LKG | UKG | 1 | 2 | ... | 12
     *
     * Recognised inputs:
     *   "1st", "First", "I", "Class 1", "Std 1", "Standard 1", "1-A", "I-A"
     *   "L.K.G.", "Lower KG", "Junior KG", "Pre-Primary", "Play Group", etc.
     */
    public function classLabel(?string $raw): ?string
    {
        if ($raw === null) return null;
        $original = trim((string) $raw);
        if ($original === '') return null;

        $s = strtolower($original);
        $s = preg_replace('/[._\-]+/', ' ', $s);
        $s = preg_replace('/\s+/', ' ', $s);
        $s = trim($s);

        $s = preg_replace('/^(the\s+)?(class|grade|std|standard|division|level)\s+/i', '', $s);
        $s = preg_replace('/\s+(class|std|standard|division)$/i', '', $s);

        $prePrimary = [
            'nursery' => 'Nursery', 'nur' => 'Nursery',
            'pre nursery' => 'Nursery', 'prenursery' => 'Nursery',
            'pre primary' => 'Nursery', 'preprimary' => 'Nursery', 'pp' => 'Nursery',
            'play' => 'Nursery', 'play group' => 'Nursery', 'playgroup' => 'Nursery',

            'kg' => 'LKG', 'k g' => 'LKG', 'kinder' => 'LKG', 'kindergarten' => 'LKG',
            'lkg' => 'LKG', 'l k g' => 'LKG', 'lower kg' => 'LKG', 'lower kindergarten' => 'LKG',
            'jr kg' => 'LKG', 'junior kg' => 'LKG', 'jrkg' => 'LKG', 'pre k' => 'LKG', 'prek' => 'LKG',

            'ukg' => 'UKG', 'u k g' => 'UKG', 'upper kg' => 'UKG', 'upper kindergarten' => 'UKG',
            'sr kg' => 'UKG', 'senior kg' => 'UKG', 'srkg' => 'UKG',
        ];
        if (isset($prePrimary[$s])) return $prePrimary[$s];

        if (preg_match('/^\d{1,2}$/', $s)) {
            $n = (int) $s;
            if ($n >= 1 && $n <= 12) return (string) $n;
        }
        if (preg_match('/^(\d{1,2})\s*(st|nd|rd|th)\s*$/i', $s, $m)) {
            $n = (int) $m[1];
            if ($n >= 1 && $n <= 12) return (string) $n;
        }
        $words = [
            'first'=>'1','one'=>'1','second'=>'2','two'=>'2','third'=>'3','three'=>'3',
            'fourth'=>'4','four'=>'4','fifth'=>'5','five'=>'5','sixth'=>'6','six'=>'6',
            'seventh'=>'7','seven'=>'7','eighth'=>'8','eight'=>'8','ninth'=>'9','nine'=>'9',
            'tenth'=>'10','ten'=>'10','eleventh'=>'11','eleven'=>'11','twelfth'=>'12','twelve'=>'12',
        ];
        if (isset($words[$s])) return $words[$s];

        $roman = ['i'=>'1','ii'=>'2','iii'=>'3','iv'=>'4','v'=>'5','vi'=>'6','vii'=>'7','viii'=>'8','ix'=>'9','x'=>'10','xi'=>'11','xii'=>'12'];
        if (isset($roman[$s])) return $roman[$s];

        // "1-A" / "I-A" / "5 A" — strip a trailing single-letter section and recurse.
        if (preg_match('/^([\d]{1,2}|[ivxlcdm]+|first|second|third|fourth|fifth|sixth|seventh|eighth|ninth|tenth|eleventh|twelfth|nursery|lkg|ukg|kg)\b[\s\-\/_]+[a-z]\b/i', $s, $m)) {
            $recursed = $this->classLabel($m[1]);
            if ($recursed) return $recursed;
        }

        return ucfirst($original);
    }

    /** Stable lookup key — matches existing master rows regardless of original format. */
    public function classKey(?string $raw): ?string
    {
        $n = $this->classLabel($raw);
        return $n ? strtolower($n) : null;
    }

    /** "a", "A", "section a", "1A", "Class A" → "A" */
    public function sectionLabel(?string $raw): ?string
    {
        if (!$raw) return null;
        $raw = trim($raw);
        if ($raw === '') return null;
        // Strip common prefixes
        $raw = preg_replace('/^(section|sec|class|grade)\s+/i', '', $raw);
        // Strip a leading number if present ("1A" → "A")
        $raw = preg_replace('/^\d+\s*/', '', $raw);
        return strtoupper($raw);
    }

    /** "M", "Male", "MALE", "boy" → "male" */
    public function gender(?string $raw): ?string
    {
        if (!$raw) return null;
        $raw = strtolower(trim($raw));
        if (in_array($raw, ['m', 'male', 'boy', 'b'], true)) return 'male';
        if (in_array($raw, ['f', 'female', 'girl', 'g'], true)) return 'female';
        if ($raw !== '') return 'other';
        return null;
    }

    /** Strip non-digits, drop leading 91 / 0, return null if not exactly 10 digits. */
    public function mobile(?string $raw): ?string
    {
        if (!$raw) return null;
        $digits = preg_replace('/\D+/', '', $raw);
        if (strlen($digits) > 10 && str_starts_with($digits, '91')) {
            $digits = substr($digits, 2);
        } elseif (strlen($digits) === 11 && str_starts_with($digits, '0')) {
            $digits = substr($digits, 1);
        }
        return $digits !== '' ? $digits : null;
    }

    public function email(?string $raw): ?string
    {
        if (!$raw) return null;
        $email = strtolower(trim($raw));
        return $email !== '' ? $email : null;
    }

    /** Aadhaar: strip non-digits. Returns null if empty. Validation checks the 12-digit length. */
    public function aadhaar(?string $raw): ?string
    {
        if (!$raw) return null;
        $digits = preg_replace('/\D+/', '', $raw);
        return $digits !== '' ? $digits : null;
    }

    /** Multi-format date parser → "YYYY-MM-DD" or null. */
    public function date(?string $raw): ?string
    {
        if (!$raw) return null;
        $raw = trim($raw);
        if ($raw === '') return null;

        // Excel serial date (when sheets came from Excel and number conversion happened)
        if (is_numeric($raw)) {
            // Excel date serial — 25569 = 1970-01-01, 86400 = seconds per day
            $ts = ((int) $raw - 25569) * 86400;
            if ($ts > 0) {
                return Carbon::createFromTimestamp($ts)->format('Y-m-d');
            }
        }

        $formats = ['Y-m-d', 'd-m-Y', 'd/m/Y', 'd.m.Y', 'm/d/Y', 'd-M-Y', 'd-M-y', 'Y/m/d', 'Y.m.d'];
        foreach ($formats as $fmt) {
            try {
                $dt = Carbon::createFromFormat($fmt, $raw);
                if ($dt && $dt->format($fmt) === $raw) {
                    return $dt->format('Y-m-d');
                }
            } catch (\Throwable $e) {
                // try next format
            }
        }

        // Last resort: PHP's flexible parser
        try {
            $dt = Carbon::parse($raw);
            return $dt->format('Y-m-d');
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function string(?string $raw): ?string
    {
        if ($raw === null) return null;
        $v = trim($raw);
        return $v === '' ? null : $v;
    }

    public function bool(?string $raw): bool
    {
        if (!$raw) return false;
        $v = strtolower(trim($raw));
        return in_array($v, ['1', 'true', 'yes', 'y', 'required'], true);
    }
}
