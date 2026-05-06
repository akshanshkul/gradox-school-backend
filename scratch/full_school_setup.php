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

$schoolId = 1;

// 1. Get Term & Type
$term = ExamTerm::where('school_id', $schoolId)->orderBy('id', 'desc')->first();
$type = ExamType::where('school_id', $schoolId)->first();

if (!$term || !$type) {
    echo "ERROR: Please create at least one Term and one Exam Type first.\n";
    exit;
}

echo "Starting Full School Setup...\n";
echo "Active Term: {$term->name}\n";
echo "Default Exam Type: {$type->name}\n";

// 2. Get All Classes
$classes = SchoolClass::where('school_id', $schoolId)->with('subjects')->get();

DB::transaction(function() use ($classes, $term, $type) {
    foreach ($classes as $class) {
        echo "Configuring Class: {$class->id} (" . ($class->grade->name ?? 'Class') . " " . ($class->section->name ?? '') . ")\n";
        
        foreach ($class->subjects as $subject) {
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

            // Standard 70/30 Split
            $struct->components()->delete();
            $struct->components()->create(['name' => 'Theory', 'max_marks' => 70]);
            $struct->components()->create(['name' => 'Internal', 'max_marks' => 30]);
            
            echo "  > Added 70/30 rule for {$subject->name}\n";
        }
    }
});

echo "\nCOMPLETED: Your entire school database is now configured for examinations.\n";
echo "You can now view Mark Entry sheets for any class and subject.\n";
