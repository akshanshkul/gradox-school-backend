<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\TimetableEntry;
use App\Services\MasterScheduler;
use App\Models\School;
use Carbon\Carbon;

$school = School::find(1); // Directly use ID 1 as verified
if (!$school) {
    echo "School not found.\n";
    exit;
}

echo "Purging all existing timetable entries for school: {$school->name}...\n";
TimetableEntry::where('school_id', $school->id)->delete();

echo "Performing Full Month Clean Sync...\n";
$scheduler = new MasterScheduler($school);

$startOfMonth = Carbon::now()->startOfMonth();
$endOfMonth = Carbon::now()->endOfMonth();
$current = $startOfMonth->copy()->startOfWeek(Carbon::MONDAY);

while ($current <= $endOfMonth) {
    $weekStr = $current->toDateString();
    echo "Generating Week starting {$weekStr}...\n";
    $scheduler->syncAllClasses($weekStr);
    $current->addWeek();
}

$clashes = Illuminate\Support\Facades\DB::select("
    SELECT user_id, date, start_time, COUNT(*) as count 
    FROM timetable_entries 
    WHERE user_id IS NOT NULL 
    GROUP BY user_id, date, start_time 
    HAVING COUNT(*) > 1
");

echo "\n--- VALIDATION RESULTS ---\n";
echo "TOTAL CLASHES AFTER SYNC: " . count($clashes) . "\n";
if (count($clashes) == 0) {
    echo "SUCCESS: School timetable is now 100% conflict-free!\n";
} else {
    foreach($clashes as $c) {
        echo "FAIL: Clash for Teacher {$c->user_id} on {$c->date} at {$c->start_time} ({$c->count} classes)\n";
    }
}
