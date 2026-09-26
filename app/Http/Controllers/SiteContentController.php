<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Structured public-website content for the school (Layout 2 site).
 *
 * The whole document lives in `schools.site_content` (JSON) and follows the
 * schema edited in Landing Page → Website Content. The admin can also
 * import / export it as a single JSON file.
 *
 * A few values overlap with older landing fields (tagline, session, SEO,
 * social links). On save we mirror them back so Layout 1 and the login
 * screens stay in sync with what the admin typed here.
 */
class SiteContentController extends Controller
{
    /** Hard cap on the stored document — keeps a runaway import from bloating the row. */
    private const MAX_BYTES = 1_500_000;

    public function show(Request $request)
    {
        $school = $request->user()->school;
        $theme = $school->landing_theme_config ?? [];

        return $this->successResponse([
            'content' => $school->site_content,
            // Existing data the editor uses to pre-fill an empty document,
            // so the admin doesn't retype what the system already knows.
            'existing' => [
                'name' => $school->name,
                'tagline' => $school->tagline,
                'about_text' => $school->about_text,
                'current_session' => $school->current_session,
                'contact_number' => $school->contact_number,
                'email' => $school->email,
                'address' => $school->address,
                'custom_domain' => $school->custom_domain,
                'logo_path' => $school->logo_path,
                'seo_title' => $theme['seo_title'] ?? null,
                'seo_description' => $theme['seo_description'] ?? null,
                'social_facebook' => $theme['social_facebook'] ?? null,
                'social_instagram' => $theme['social_instagram'] ?? null,
                'social_twitter' => $theme['social_twitter'] ?? null,
                'stats' => $theme['stats'] ?? null,
            ],
        ]);
    }

    public function update(Request $request)
    {
        $request->validate(['content' => 'required|array']);

        $content = $request->input('content');
        if (strlen(json_encode($content)) > self::MAX_BYTES) {
            return $this->errorResponse('Website content is too large (max 1.5 MB). Remove unused items and try again.', 422);
        }

        $school = $request->user()->school;
        $theme = $school->landing_theme_config ?? [];

        $identity = $content['school']['identity'] ?? [];
        $social = $content['school']['social'] ?? [];
        $seo = $content['seo'] ?? [];

        // Mirror overlapping fields into the legacy columns (only when set,
        // so clearing a field here never wipes the older value).
        $mirror = fn ($v) => is_string($v) && trim($v) !== '' ? trim($v) : null;
        foreach ([
            'seo_title' => $seo['title'] ?? null,
            'seo_description' => $seo['description'] ?? null,
            'social_facebook' => $social['facebook'] ?? null,
            'social_instagram' => $social['instagram'] ?? null,
            'social_twitter' => $social['twitter'] ?? null,
        ] as $key => $value) {
            if ($v = $mirror($value)) {
                $theme[$key] = $v;
            }
        }

        $updates = ['site_content' => $content, 'landing_theme_config' => $theme];
        if ($v = $mirror($identity['tagline'] ?? null)) {
            $updates['tagline'] = $v;
        }
        if ($v = $mirror($identity['session'] ?? null)) {
            $updates['current_session'] = $v;
        }

        $school->update($updates);

        try {
            \App\Services\SafeCache::forgetPrefix("school_{$school->id}_url_cache");
        } catch (\Throwable $e) {
            // best effort
        }

        return $this->successResponse(['content' => $school->site_content], 'Website content saved');
    }

    /**
     * Active staff in the website "team" shape, so the admin can import the
     * staff directory into the public Our Team page with one click (nothing
     * is published until the admin saves the website content).
     */
    public function staff(Request $request)
    {
        $school = $request->user()->school;

        $users = $school->users()
            ->where('status', 'active')
            ->with('role_relation:id,name,slug')
            ->orderByDesc('is_teaching')
            ->orderBy('name')
            ->get(['id', 'name', 'role_id', 'is_teaching', 'staff_subtype', 'profile_picture', 'teacher_details']);

        $subjectIds = $users->flatMap(fn ($u) => collect($u->teacher_details['specializations'] ?? [])->pluck('subject_id'))
            ->filter()->unique()->values();
        $subjects = $subjectIds->isEmpty()
            ? collect()
            : \App\Models\Subject::whereIn('id', $subjectIds)->pluck('name', 'id');

        $management = ['administrator', 'admin', 'super-admin', 'principal', 'incharge', 'accountant'];

        $staff = $users->map(function ($u) use ($subjects, $management) {
            $roleSlug = $u->role_relation->slug ?? '';
            $subjectNames = collect($u->teacher_details['specializations'] ?? [])
                ->pluck('subject_id')->map(fn ($id) => $subjects[$id] ?? null)->filter()->unique()->implode(', ');

            return [
                'name' => $u->name,
                'role' => $u->role_relation->name ?? ($u->staff_subtype ? ucwords(str_replace('_', ' ', $u->staff_subtype)) : 'Staff'),
                'subject' => $subjectNames ?: null,
                'type' => in_array($roleSlug, $management, true) ? 'management' : ($u->is_teaching ? 'academic' : 'management'),
                'image' => $u->profile_picture ?: null,
            ];
        })->values();

        return $this->successResponse(['staff' => $staff]);
    }

    /** Image / PDF upload for gallery, team photos, disclosure documents, etc. */
    public function upload(Request $request)
    {
        $request->validate([
            'file' => 'required|file|max:10240|mimes:jpg,jpeg,png,webp,gif,pdf',
        ]);

        $school = $request->user()->school;
        $file = $request->file('file');
        $path = $file->store("school/{$school->id}/site", ['disk' => 's3']);

        if (!$path) {
            return $this->errorResponse('Upload failed. Please try again.', 500);
        }

        return $this->successResponse([
            'url' => Storage::disk('s3')->url($path),
            'name' => $file->getClientOriginalName(),
            'size' => $file->getSize(),
            'mime' => $file->getMimeType(),
        ], 'File uploaded');
    }
}
