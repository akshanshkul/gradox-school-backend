<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Grade;
use App\Models\Section;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\Classroom;

use App\Traits\ClearsSchoolCache;

class School extends Model
{
    use HasFactory, ClearsSchoolCache;

    protected $fillable = [
        'name',
        'email',
        'address',
        'registration_no',
        'contact_number',
        'slug',
        'custom_domain',
        'logo_path',
        'theme_color',
        'tagline',
        'about_text',
        'admission_form_config',
        'landing_theme_config',
        'site_content',
        'landing_layout',
        'email_settings',
        'plan_name',
        'plan_id',
        // Resolved module-access cache. Written exclusively by
        // ModuleAccessService::recompute — never set by hand from
        // controller code; that would drift from the source of truth.
        'module_cache_bitmap',
        'module_cache_version',
        'subscription_status',
        'subscription_expires_at',
        'trial_extended_until',
        'student_limit_override',
        'grace_days',
        'current_session',
        'latitude',
        'longitude',
        'geofence_radius',
        'onboarding_steps',
    ];

    protected $casts = [
        'admission_form_config' => 'array',
        'landing_theme_config' => 'array',
        'site_content' => 'array',
        'landing_layout' => 'integer',
        'email_settings' => 'array',
        'onboarding_steps' => 'array',
        'working_days' => 'array',
        'subscription_expires_at' => 'datetime',
        'trial_extended_until' => 'date',
        'student_limit_warning_sent_at' => 'datetime',
        'student_limit_override' => 'integer',
        'grace_days' => 'integer',
        'latitude' => 'float',
        'longitude' => 'float',
        'geofence_radius' => 'integer',
    ];

    public function plan()
    {
        return $this->belongsTo(Plan::class, 'plan_id');
    }

    /**
     * Per-school module overrides (gifts and temporary disables).
     * Resolved together with plan defaults by ModuleAccessService.
     */
    public function moduleOverrides()
    {
        return $this->hasMany(SchoolModuleOverride::class);
    }

    /**
     * In-memory cache of decoded module codes, populated lazily on
     * first access in a request. Avoids re-splitting the cached
     * bitmap string on every check.
     */
    protected ?array $_decodedModuleSet = null;

    /**
     * Fast O(1) check: does this school currently have the given
     * module enabled? Reads from the cached bitmap column — no JOIN,
     * no per-request DB query after the first hit.
     */
    public function hasModule(string $code): bool
    {
        if ($this->_decodedModuleSet === null) {
            $bitmap = (string) ($this->module_cache_bitmap ?? '');
            $this->_decodedModuleSet = array_flip(
                $bitmap === '' ? [] : explode(' ', $bitmap)
            );
        }
        return isset($this->_decodedModuleSet[$code]);
    }

    /**
     * Force a reload of the per-request cache. Call after this
     * school's bitmap was just updated mid-request (rare, but happens
     * during plan changes or first-call seeding).
     */
    public function refreshModuleCache(): void
    {
        $this->_decodedModuleSet = null;
    }

    /**
     * Keep `plan_name` (legacy string column) and `plan_id` (FK to plans)
     * in lockstep on every save. Historically the codebase had four
     * different call sites writing schools (self-signup, platform create,
     * platform update, platform assign-plan) and only one of them wrote
     * the FK. That left rows with `plan_name='Premium'` but `plan_id=2`
     * (Free Trial), and the new usage / limit code reads only `plan_id`
     * so the UI showed the wrong cap and admissions got wrongly blocked.
     *
     * Instead of remembering to update both columns in every controller,
     * we sync at the model layer:
     *
     *   - If a caller dirties `plan_id` → set `plan_name` to that plan's name.
     *   - If a caller dirties only `plan_name` → look up a Plan with that
     *     name (case-insensitive) and set `plan_id`. If none matches, leave
     *     `plan_id` untouched so the row still saves — the reconcile
     *     command can be re-run later once the plan is created.
     *
     * Either way, the columns can never drift again from in-app writes.
     */
    protected static function booted()
    {
        static::saving(function (School $school) {
            $dirtyId = $school->isDirty('plan_id');
            $dirtyName = $school->isDirty('plan_name');

            if ($dirtyId && $school->plan_id) {
                $plan = Plan::find($school->plan_id);
                if ($plan) {
                    $school->plan_name = $plan->name;
                }
                return;
            }

            if ($dirtyName && !$dirtyId && $school->plan_name) {
                $plan = Plan::whereRaw('LOWER(name) = ?', [strtolower($school->plan_name)])->first();
                if ($plan) {
                    $school->plan_id = $plan->id;
                    // Also canonicalise the spelling so "premium" becomes "Premium".
                    $school->plan_name = $plan->name;
                }
            }
        });

        static::created(function (School $school) {
            $roles = [
                [
                    'slug' => 'administrator',
                    'name' => 'Administrator',
                    'description' => 'Full administrative access to all institutional modules.',
                    'permissions' => self::getDefaultAdminPermissions(),
                ],
                [
                    'slug' => 'teacher',
                    'name' => 'Teacher',
                    'description' => 'Standard teaching faculty access (restricted administrative view).',
                    'permissions' => [
                        'profile' => ['read' => true, 'update' => true],
                        'attendance' => ['read' => true, 'create' => true],
                    ],
                ],
                [
                    'slug' => 'incharge',
                    'name' => 'Incharge',
                    'description' => 'Department coordinator / academic level supervisor.',
                    'permissions' => [
                        'profile' => ['read' => true, 'update' => true],
                        'academic' => ['read' => true, 'update' => true],
                        'students' => ['read' => true, 'update' => true],
                        'timetable' => ['read' => true, 'create' => true, 'update' => true],
                        'attendance' => ['read' => true, 'create' => true, 'update' => true],
                    ],
                ],
                [
                    'slug' => 'staff',
                    'name' => 'Staff',
                    'description' => 'Standard non-teaching staff / office personnel access.',
                    'permissions' => [
                        'profile' => ['read' => true, 'update' => true],
                        'students' => ['read' => true],
                    ],
                ],
            ];

            foreach ($roles as $r) {
                \App\Models\Role::firstOrCreate(
                    ['school_id' => $school->id, 'slug' => $r['slug']],
                    [
                        'name' => $r['name'],
                        'description' => $r['description'],
                        'permissions' => $r['permissions'],
                    ]
                );
            }
        });

        static::saved(function (School $school) {
            if ($school->wasChanged('name') || $school->wasChanged('logo_path')) {
                if (!$school->isOnboardingStepCompleted('basic-info')) {
                    if (!empty($school->name) && !empty($school->logo_path)) {
                        $school->completeOnboardingStep('basic-info');
                    }
                }
            }
        });
    }

    private static function getDefaultAdminPermissions(): array
    {
        $resources = ['academic', 'students', 'timetable', 'staff', 'system', 'blogs', 'courses', 'reports', 'attendance'];
        $actions = ['read', 'create', 'update', 'delete', 'export', 'import', 'publish', 'approve', 'archive', 'reject', 'restore'];
        
        $perms = [];
        foreach ($resources as $res) {
            foreach ($actions as $act) {
                $perms[$res][$act] = true;
            }
        }
        return $perms;
    }

    public function completeOnboardingStep(string $step): void
    {
        $steps = $this->onboarding_steps ?? [];
        if (!in_array($step, $steps, true)) {
            $steps[] = $step;
            $this->onboarding_steps = $steps;
            self::withoutEvents(function () {
                $this->save();
            });
        }
    }

    public function isOnboardingStepCompleted(string $step): bool
    {
        $steps = $this->onboarding_steps ?? [];
        return in_array($step, $steps, true);
    }

    public function students()
    {
        return $this->hasMany(Student::class);
    }

    /**
     * The student-cap that actually applies to this school. plan.max_students
     * + any per-school bump the platform admin granted. Null means unlimited
     * (the Premium plan has max_students = null, and we treat that as "no cap").
     */
    public function effectiveStudentLimit(): ?int
    {
        $plan = $this->plan;
        $base = $plan?->max_students;
        if ($base === null) return null; // unlimited
        return (int) $base + (int) ($this->student_limit_override ?? 0);
    }

    /**
     * Count of active students in this school — the figure compared against
     * effectiveStudentLimit() everywhere usage matters.
     */
    public function currentStudentCount(): int
    {
        return (int) Student::where('school_id', $this->id)
            ->where('status', 'active')
            ->count();
    }

    /**
     * 0-100+ percent. Unlimited plans always return 0 (no warning to surface).
     */
    public function studentUsagePercent(): int
    {
        $limit = $this->effectiveStudentLimit();
        if (!$limit) return 0;
        return (int) round(($this->currentStudentCount() / $limit) * 100);
    }

    public function landingBanners()
    {
        return $this->hasMany(LandingBanner::class)->orderBy('sort_order');
    }

    public function landingSections()
    {
        return $this->hasMany(LandingSection::class)->orderBy('sort_order');
    }

    public function admissionApplications()
    {
        return $this->hasMany(AdmissionApplication::class);
    }

    public function inquiries()
    {
        return $this->hasMany(Inquiry::class);
    }

    public function users()
    {
        return $this->hasMany(User::class);
    }

    public function classes()
    {
        return $this->hasMany(SchoolClass::class);
    }

    public function grades()
    {
        return $this->hasMany(Grade::class);
    }

    public function sections()
    {
        return $this->hasMany(Section::class);
    }

    public function subjects()
    {
        return $this->hasMany(Subject::class);
    }

    public function classrooms()
    {
        return $this->hasMany(Classroom::class);
    }

    public function sessions()
    {
        return $this->hasMany(Session::class);
    }

    public function events()
    {
        return $this->hasMany(SchoolEvent::class);
    }

    public function periods()
    {
        return $this->hasMany(SchoolPeriod::class);
    }

    public function roleWorkloadConfigs()
    {
        return $this->hasMany(RoleWorkloadConfig::class);
    }

    /**
     * Get the currently active academic session.
     *
     * Fallback: if no session is active, prefer activating an existing
     * inactive session that matches the current academic year *canonically*
     * — "2026-27" and "2026-2027" are the same year — and only create a
     * brand-new row when nothing matches. Without this guard, every page
     * that called this method after the admin deactivated all sessions
     * would silently spawn a duplicate "YYYY-YY" row.
     */
    public function getActiveSession()
    {
        $active = $this->sessions()->where('is_active', true)->first();
        if ($active) return $active;

        $year = (int) date('Y');
        $wantName = $year . '-' . substr((string) ($year + 1), -2); // e.g. "2026-27"
        $wantKey = $year . '-' . ($year + 1); // canonical "2026-2027"

        // Look for an existing session for this same canonical year.
        foreach ($this->sessions()->get() as $s) {
            if ($this->canonicalYearKey((string) $s->name) === $wantKey) {
                $s->update(['is_active' => true]);
                return $s;
            }
        }

        return $this->sessions()->create([
            'name' => $wantName,
            'start_date' => $year . '-04-01',
            'end_date' => ($year + 1) . '-03-31',
            'is_active' => true,
        ]);
    }

    /**
     * Mirrors StudentImportController::sessionKey so getActiveSession and
     * the bulk importer agree on what counts as "the same year".
     */
    private function canonicalYearKey(string $name): string
    {
        $name = trim($name);
        if ($name === '') return '';
        if (preg_match('/(\d{2,4})\D+(\d{2,4})/', $name, $m)) {
            $a = (int) $m[1]; $b = (int) $m[2];
            if ($a < 100) $a += 2000;
            if ($b < 100) $b += ($b < $a % 100 ? 2100 : 2000);
            return $a . '-' . $b;
        }
        return strtolower(preg_replace('/\s+/', '', $name));
    }

    /**
     * Admission / enquiry form config in ONE canonical shape for every
     * consumer (layout 1, layout 2, admin editor):
     *
     *   fields: { <key>: { active, required, label, type, placeholder } }
     *
     * Older schools stored `fields` as a list — [{id, type, label,
     * placeholder, required}] — which the object-based UIs silently broke
     * on. Student name and class are always part of the form, so those ids
     * are dropped from the optional-field map.
     */
    public static function normalizeAdmissionConfig($config): array
    {
        $config = is_array($config) ? $config : [];
        $raw = $config['fields'] ?? [];
        $fields = [];

        if (is_array($raw) && array_is_list($raw)) {
            foreach ($raw as $f) {
                $key = is_array($f) ? ($f['id'] ?? $f['key'] ?? null) : null;
                if (!$key || in_array($key, ['name', 'student_name', 'class', 'school_class_id'], true)) {
                    continue;
                }
                $fields[$key] = [
                    'active' => true,
                    'required' => (bool) ($f['required'] ?? false),
                    'label' => $f['label'] ?? ucwords(str_replace('_', ' ', $key)),
                    'type' => $f['type'] ?? null,
                    'placeholder' => $f['placeholder'] ?? null,
                ];
            }
        } elseif (is_array($raw)) {
            foreach ($raw as $key => $f) {
                if (!is_array($f)) {
                    continue;
                }
                $fields[$key] = array_merge($f, [
                    'active' => (bool) ($f['active'] ?? true),
                    'required' => (bool) ($f['required'] ?? false),
                    'label' => $f['label'] ?? ucwords(str_replace('_', ' ', (string) $key)),
                ]);
            }
        }

        // Oldest format: boolean require_* flags. Fold them into fields.
        $legacy = [
            'require_parent_name' => ['parent_name', 'Parent / Guardian Name'],
            'require_email' => ['email', 'Email Address'],
            'require_phone' => ['phone', 'Phone Number'],
            'require_previous_school' => ['previous_school', 'Previous School Attended'],
            'require_address' => ['residential_address', 'Residential Address'],
            'require_occupation' => ['parent_occupation', 'Parent Occupation'],
            'require_photo' => ['student_photo', 'Student Passport Photo'],
        ];
        foreach ($legacy as $flag => [$key, $label]) {
            if (array_key_exists($flag, $config)) {
                if (!isset($fields[$key]) && $config[$flag]) {
                    $fields[$key] = ['active' => true, 'required' => true, 'label' => $label];
                }
                unset($config[$flag]);
            }
        }

        return array_merge([
            'enable_admission' => true,
            'enable_inquiry' => true,
            'form_position' => 'hero',
            'form_heading' => 'Apply for Admission',
            'button_text' => 'Submit Official Application',
        ], $config, ['fields' => (object) $fields]);
    }
}
