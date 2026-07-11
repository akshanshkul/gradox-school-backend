<?php

namespace App\Http\Controllers;

use App\Models\EmailTemplate;
use Illuminate\Http\Request;

class EmailTemplateController extends Controller
{
    /**
     * List all available template events for the school.
     */
    public function index(Request $request)
    {
        $schoolId = $request->user()->school_id;

        // Fetch all system templates (school_id is null) 
        // AND any school-specific overrides.
        $allTemplates = EmailTemplate::whereNull('school_id')
            ->orWhere('school_id', $schoolId)
            ->get();

        // Group by slug and pick school specific if it exists
        $templates = $allTemplates->groupBy('slug')->map(function ($group) use ($schoolId) {
            return $group->where('school_id', $schoolId)->first() ?: $group->first();
        })->values();

        return response()->json($templates);
    }

    /**
     * Update or Create a school-specific override for a template.
     */
    public function update(Request $request, $slug)
    {
        $request->validate([
            'subject' => 'required|string|max:255',
            'content_html' => 'required|string',
        ]);

        $schoolId = $request->user()->school_id;

        // Find the base system template to inherit placeholders if needed
        $baseTemplate = EmailTemplate::where('slug', $slug)->whereNull('school_id')->first();

        $template = EmailTemplate::updateOrCreate(
            ['school_id' => $schoolId, 'slug' => $slug],
            [
                'subject' => $request->subject,
                'content_html' => $request->content_html,
                'name' => $baseTemplate ? $baseTemplate->name : ucfirst(str_replace('_', ' ', $slug)),
                'placeholders' => $baseTemplate ? $baseTemplate->placeholders : [],
                'is_system' => true
            ]
        );

        return response()->json([
            'message' => 'Institutional template synchronized successfully.',
            'template' => $template
        ]);
    }

    /**
     * Reset a school's customized template back to the system default by
     * deleting the school's override row. Subsequent reads fall through to
     * the system row (school_id IS NULL) via index()'s grouping logic.
     *
     * No-ops cleanly if no override exists — useful so the UI can call this
     * blindly without 404 noise.
     */
    public function destroy(Request $request, $slug)
    {
        $schoolId = $request->user()->school_id;

        $deleted = EmailTemplate::where('school_id', $schoolId)
            ->where('slug', $slug)
            ->delete();

        // Return the now-active template (the system default) so the client
        // can rehydrate its form without a second round-trip.
        $current = EmailTemplate::where('slug', $slug)
            ->whereNull('school_id')
            ->first();

        return response()->json([
            'message' => $deleted
                ? 'Template reset to the system default.'
                : 'No override existed — already on the system default.',
            'override_deleted' => $deleted > 0,
            'template' => $current,
        ]);
    }
}
