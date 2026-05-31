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
        'email_settings',
        'plan_name',
        'subscription_status',
        'subscription_expires_at',
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
        'email_settings' => 'array',
        'onboarding_steps' => 'array',
        'working_days' => 'array',
        'subscription_expires_at' => 'datetime',
        'grace_days' => 'integer',
        'latitude' => 'float',
        'longitude' => 'float',
        'geofence_radius' => 'integer',
    ];

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
}
