<?php
require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\SchoolClass;
use App\Models\ExamTerm;
use App\Models\ExamType;
use App\Models\ExamStructure;
use App\Models\ExamStructureComponent;
use Illuminate\Support\Facades\DB;

// 1. Find the Class
$schoolId = 1;
$class = SchoolClass::where('school_id', $schoolId)
    ->whereHas('grade', fn($q) => $q->where('name', 'LIKE', '%3%'))
    ->whereHas('section', fn($q) => $q->where('name', 'B'))
    ->first();

if (!$class) {
    echo "ERROR: Class 3B not found\n";
    exit;
}

// 2. Find a Term
$term = ExamTerm::where('school_id', $schoolId)->orderBy('id', 'desc')->first();
if (!$term) {
    echo "ERROR: No term found\n";
    exit;
}

// 3. Find an Exam Type
$type = ExamType::where('school_id', $schoolId)->where('name', 'LIKE', '%Mid%')->first();
if (!$type) $type = ExamType::where('school_id', $schoolId)->first();

if (!$type) {
    echo "ERROR: No exam type found\n";
    exit;
}

echo "Setting up Class: " . $class->grade->name . " " . $class->section->name . " (ID: {$class->id})\n";
echo "Term: " . $term->name . " (ID: {$term->id})\n";
echo "Type: " . $type->name . " (ID: {$type->id})\n";

// 4. Get Subjects
$subjects = $class->subjects;
echo "Found " . $subjects->count() . " subjects.\n";

// 5. Create rules (70 Theory + 30 Practical)
DB::transaction(function() use ($class, $term, $type, $subjects) {
    foreach ($subjects as $subject) {
        echo "Creating rule for: " . $subject->name . "\n";
        $struct = ExamStructure::updateOrCreate(
            [
                'exam_term_id'    => $term->id,
                'exam_type_id'    => $type->id,
                'school_class_id' => $class->id,
                'subject_id'      => $subject->id,
            ],
            [
                'scoring_type'    => 'marks',
                'passing_marks'   => 33,
            ]
        );

        $struct->components()->delete();
        $struct->components()->create(['name' => 'Theory', 'max_marks' => 70]);
        $struct->components()->create(['name' => 'Practical', 'max_marks' => 30]);
    }
});

echo "SUCCESS: Class 3B Setup Complete.\n";
