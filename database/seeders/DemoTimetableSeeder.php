<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\TimetableEntry;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\User;
use App\Models\Classroom;
use Carbon\Carbon;

class DemoTimetableSeeder extends Seeder
{
    public function run()
    {
        $schoolId = 1; // Assuming 1 for demo
        $classes = SchoolClass::where('school_id', $schoolId)->get();
        $subjects = Subject::where('school_id', $schoolId)->get();
        $teachers = User::where('role', 'teacher')->where('school_id', $schoolId)->get();
        $classrooms = Classroom::where('school_id', $schoolId)->get();
        $periods = \DB::table('school_periods')->where('school_id', $schoolId)->where('type', 'class')->orderBy('sort_order')->get();

        if ($classes->isEmpty() || $subjects->isEmpty() || $teachers->isEmpty() || $periods->isEmpty()) {
            return;
        }

        $now = Carbon::now();
        $startOfWeek = $now->copy()->startOfWeek(Carbon::MONDAY);
        
        // Create demo data for each class for the entire week
        foreach ($classes as $class) {
            foreach (range(0, 4) as $dayOffset) { // Mon to Fri
                $date = $startOfWeek->copy()->addDays($dayOffset)->toDateString();
                $dayName = $startOfWeek->copy()->addDays($dayOffset)->format('Friday'); // Just for ref

                foreach ($periods as $idx => $period) {
                    // Randomly assign or leave empty
                    if (rand(0, 10) < 2) continue; // 20% free time

                    // For consecutive logic demo:
                    // Force the first two periods to be the same teacher/subject sometimes
                    if ($idx == 1 && rand(0, 1) == 1) {
                         // Copy from previous period
                         $prev = TimetableEntry::where('school_class_id', $class->id)
                            ->where('date', $date)
                            ->where('start_time', $periods[0]->start_time)
                            ->first();
                         
                         if ($prev) {
                             TimetableEntry::create([
                                'school_id' => $schoolId,
                                'school_class_id' => $class->id,
                                'subject_id' => $prev->subject_id,
                                'user_id' => $prev->user_id,
                                'classroom_id' => $prev->classroom_id,
                                'date' => $date,
                                'day_of_week' => Carbon::parse($date)->format('l'),
                                'start_time' => $period->start_time,
                                'end_time' => $period->end_time,
                             ]);
                             continue;
                         }
                    }

                    $teacher = $teachers->random();
                    $subject = $subjects->random();
                    $room = $classrooms->isNotEmpty() ? $classrooms->random() : null;

                    TimetableEntry::create([
                        'school_id' => $schoolId,
                        'school_class_id' => $class->id,
                        'subject_id' => $subject->id,
                        'user_id' => $teacher->id,
                        'classroom_id' => $room ? $room->id : null,
                        'date' => $date,
                        'day_of_week' => Carbon::parse($date)->format('l'),
                        'start_time' => $period->start_time,
                        'end_time' => $period->end_time,
                    ]);
                }
            }
        }
    }
}
