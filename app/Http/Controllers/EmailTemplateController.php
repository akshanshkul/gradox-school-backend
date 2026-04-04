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
}
