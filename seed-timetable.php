<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

try {
    $school = \App\Models\School::first();
    if (!$school) {
        die("No school found.\n");
    }

    $days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];
    $subjects = \App\Models\Subject::where('school_id', $school->id)->get();
    $teachers = \App\Models\User::where('school_id', $school->id)->get();
    $classrooms = \App\Models\Classroom::where('school_id', $school->id)->get();
    $classes = \App\Models\SchoolClass::where('school_id', $school->id)->get();

    if ($subjects->isEmpty() || $teachers->isEmpty() || $classes->isEmpty()) {
        die("Missing subjects, teachers, or classes for seeding.\n");
    }

    $periods = [
        ['name' => '1st Period', 'start' => '08:30:00', 'end' => '09:20:00'],
        ['name' => '2nd Period', 'start' => '09:20:00', 'end' => '10:10:00'],
        ['name' => 'Break',      'start' => '10:10:00', 'end' => '10:30:00', 'is_break' => true],
        ['name' => '3rd Period', 'start' => '10:30:00', 'end' => '11:20:00'],
        ['name' => '4th Period', 'start' => '11:20:00', 'end' => '12:10:00'],
        ['name' => 'Lunch',      'start' => '12:10:00', 'end' => '13:00:00', 'is_break' => true],
        ['name' => '5th Period', 'start' => '13:00:00', 'end' => '13:50:00'],
        ['name' => '6th Period', 'start' => '13:50:00', 'end' => '14:40:00'],
    ];

    // Clear existing for this school to avoid duplicates
    \App\Models\TimetableEntry::where('school_id', $school->id)->delete();

    foreach ($classes as $class) {
        foreach ($days as $day) {
            foreach ($periods as $p) {
                if (isset($p['is_break'])) continue; // Only seed subject periods

                \App\Models\TimetableEntry::create([
                    'school_id' => $school->id,
                    'school_class_id' => $class->id,
                    'subject_id' => $subjects->random()->id,
                    'user_id' => $teachers->random()->id,
                    'classroom_id' => $classrooms->random()->id ?? null,
                    'day_of_week' => $day,
                    'start_time' => $p['start'],
                    'end_time' => $p['end'],
                    'is_active' => true,
                ]);
            }
        }
    }

    echo "Demo timetable (6 periods per day) seeded for " . $classes->count() . " classes.\n";

} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
