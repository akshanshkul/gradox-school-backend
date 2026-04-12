<?php 
namespace App\Http\Controllers;

use App\Models\DocumentType;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class DocumentTypeController extends Controller
{
    public function index(Request $request)
    {
        $schoolId = $request->user()->school_id;
        $types = DocumentType::where('school_id', $schoolId)->get();

        // If no types exist, auto-initialize defaults
        if ($types->isEmpty()) {
            $this->initializeDefaults($schoolId);
            $types = DocumentType::where('school_id', $schoolId)->get();
        }

        return response()->json($types);
    }

    private function initializeDefaults($schoolId)
    {
        $defaults = [
            ['name' => 'Aadhaar Card', 'is_required' => true],
            ['name' => 'Transfer Certificate (TC)', 'is_required' => true],
            ['name' => 'Birth Certificate', 'is_required' => false],
            ['name' => 'Previous Year Marksheet', 'is_required' => false],
            ['name' => 'Medical Fitness Report', 'is_required' => false],
        ];

        foreach ($defaults as $data) {
            DocumentType::create([
                'school_id' => $schoolId,
                'name' => $data['name'],
                'slug' => Str::slug($data['name']),
                'is_required' => $data['is_required']
            ]);
        }
    }
}
