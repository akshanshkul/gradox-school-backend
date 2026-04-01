<?php

namespace App\Http\Controllers;

use App\Models\LandingBanner;
use App\Models\LandingSection;
use App\Models\LandingSectionCard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class LandingPageController extends Controller
{
    public function getCMSData(Request $request)
    {
        $school = $request->user()->school;
        return response()->json([
            'banners' => $school->landingBanners,
            'sections' => $school->landingSections()->with('cards')->get(),
        ]);
    }

    public function addBanner(Request $request)
    {
        $request->validate([
            'title' => 'nullable|string',
            'subtitle' => 'nullable|string',
            'image' => 'required|image|max:5120',
        ]);

        $path = $request->file('image')->store('landing/banner', ['disk' => 's3']);
        $url = Storage::disk('s3')->url($path);

        $banner = LandingBanner::create([
            'school_id' => $request->user()->school_id,
            'title' => $request->title,
            'subtitle' => $request->subtitle,
            'image_path' => $url,
            'sort_order' => LandingBanner::where('school_id', $request->user()->school_id)->count(),
        ]);

        return response()->json($banner);
    }

    public function deleteBanner(Request $request, $id)
    {
        $banner = LandingBanner::where('id', $id)->where('school_id', $request->user()->school_id)->firstOrFail();
        $banner->delete();
        return response()->json(['success' => true]);
    }

    public function addSection(Request $request)
    {
        $request->validate([
            'title' => 'required|string',
            'type' => 'required|in:grid,text',
        ]);

        $section = LandingSection::create([
            'school_id' => $request->user()->school_id,
            'title' => $request->title,
            'type' => $request->type,
            'sort_order' => LandingSection::where('school_id', $request->user()->school_id)->count(),
        ]);

        return response()->json($section);
    }

    public function deleteSection(Request $request, $id)
    {
        $section = LandingSection::where('id', $id)->where('school_id', $request->user()->school_id)->firstOrFail();
        $section->delete();
        return response()->json(['success' => true]);
    }

    public function addSectionCard(Request $request, $sectionId)
    {
        $section = LandingSection::where('id', $sectionId)->where('school_id', $request->user()->school_id)->firstOrFail();

        $request->validate([
            'title' => 'required|string',
            'description' => 'nullable|string',
            'image' => 'nullable|image|max:2048',
        ]);

        $url = null;
        if ($request->hasFile('image')) {
            $path = $request->file('image')->store('landing/content', ['disk' => 's3']);
            $url = Storage::disk('s3')->url($path);
        }

        $card = LandingSectionCard::create([
            'landing_section_id' => $section->id,
            'title' => $request->title,
            'description' => $request->description,
            'image_path' => $url,
            'sort_order' => $section->cards()->count(),
        ]);

        return response()->json($card);
    }

    public function deleteSectionCard(Request $request, $sectionId, $cardId)
    {
        $section = LandingSection::where('id', $sectionId)->where('school_id', $request->user()->school_id)->firstOrFail();
        $card = LandingSectionCard::where('id', $cardId)->where('landing_section_id', $section->id)->firstOrFail();
        $card->delete();
        return response()->json(['success' => true]);
    }
}
