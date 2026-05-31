<?php

namespace App\Services\StudentImport;

use App\Models\Grade;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Section;
use App\Models\Session as AcademicSession;

/**
 * Resolves normalized session / class / section labels to existing master-data
 * IDs for a given school. Records anything that doesn't exist yet in a delta
 * list so the admin can approve creating new master rows at commit time.
 *
 * IMPORTANT: this class never writes to the master tables. It only reads and
 * records "would need to create" entries. The commit step is responsible
 * for actually creating approved master rows.
 */
class MasterDataResolver
{
    /** ['2025-2026' => session_id|null, ...] */
    private array $sessionCache = [];
    /** ['Class 1' => grade_id|null, ...] */
    private array $gradeCache = [];
    /** ['A' => section_id|null, ...] */
    private array $sectionCache = [];
    /** [grade_id . ':' . section_id => school_class_id|null] */
    private array $classCache = [];

    public array $newSessions = [];   // ['2025-2026', ...]
    public array $newGrades = [];     // ['11', ...]
    public array $newSections = [];   // ['F', ...]
    public array $newClasses = [];    // [['grade'=>'11','section'=>'F']]

    public function __construct(private School $school)
    {
        $this->primeCaches();
    }

    private function primeCaches(): void
    {
        foreach (AcademicSession::where('school_id', $this->school->id)->get(['id', 'name']) as $s) {
            $this->sessionCache[strtolower($s->name)] = $s->id;
        }
        foreach (Grade::where('school_id', $this->school->id)->get(['id', 'name']) as $g) {
            $this->gradeCache[strtolower($g->name)] = $g->id;
        }
        foreach (Section::where('school_id', $this->school->id)->get(['id', 'name']) as $sec) {
            $this->sectionCache[strtolower($sec->name)] = $sec->id;
        }
        foreach (SchoolClass::where('school_id', $this->school->id)->get(['id', 'grade_id', 'section_id']) as $c) {
            $this->classCache[$c->grade_id . ':' . $c->section_id] = $c->id;
        }
    }

    public function resolveSessionId(?string $normalized): ?int
    {
        if (!$normalized) return null;
        $key = strtolower($normalized);
        if (isset($this->sessionCache[$key])) return $this->sessionCache[$key];
        if (!in_array($normalized, $this->newSessions, true)) {
            $this->newSessions[] = $normalized;
        }
        return null;
    }

    public function resolveGradeId(?string $normalized): ?int
    {
        if (!$normalized) return null;
        $key = strtolower($normalized);
        if (isset($this->gradeCache[$key])) return $this->gradeCache[$key];
        if (!in_array($normalized, $this->newGrades, true)) {
            $this->newGrades[] = $normalized;
        }
        return null;
    }

    public function resolveSectionId(?string $normalized): ?int
    {
        if (!$normalized) return null;
        $key = strtolower($normalized);
        if (isset($this->sectionCache[$key])) return $this->sectionCache[$key];
        if (!in_array($normalized, $this->newSections, true)) {
            $this->newSections[] = $normalized;
        }
        return null;
    }

    public function resolveClassId(?int $gradeId, ?int $sectionId, ?string $gradeLabel, ?string $sectionLabel): ?int
    {
        if (!$gradeId || !$sectionId) {
            // Even if we can't form a key yet, record the future class so the
            // admin can see "Class 1-A will be created" in the delta.
            if ($gradeLabel && $sectionLabel) {
                $key = $gradeLabel . '|' . $sectionLabel;
                if (!isset(array_flip(array_map(fn($c) => $c['grade'].'|'.$c['section'], $this->newClasses))[$key])) {
                    $this->newClasses[] = ['grade' => $gradeLabel, 'section' => $sectionLabel];
                }
            }
            return null;
        }
        $key = $gradeId . ':' . $sectionId;
        if (isset($this->classCache[$key])) return $this->classCache[$key];
        $payload = ['grade' => $gradeLabel, 'section' => $sectionLabel];
        if (!in_array($payload, $this->newClasses, true)) {
            $this->newClasses[] = $payload;
        }
        return null;
    }

    public function delta(): array
    {
        return [
            'new_sessions' => array_values(array_unique($this->newSessions)),
            'new_grades' => array_values(array_unique($this->newGrades)),
            'new_sections' => array_values(array_unique($this->newSections)),
            'new_classes' => array_values($this->newClasses),
        ];
    }
}
