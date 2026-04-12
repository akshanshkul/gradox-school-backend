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
    ];

    protected $casts = [
        'admission_form_config' => 'array',
        'landing_theme_config' => 'array',
        'email_settings' => 'array',
        'working_days' => 'array',
        'subscription_expires_at' => 'datetime',
        'grace_days' => 'integer',
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
}
