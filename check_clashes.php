<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$clashes = Illuminate\Support\Facades\DB::select("
    SELECT user_id, date, start_time, COUNT(*) as count 
    FROM timetable_entries 
    WHERE user_id IS NOT NULL 
    GROUP BY user_id, date, start_time 
    HAVING COUNT(*) > 1
");

echo "TOTAL CLASHES FOUND: " . count($clashes) . "\n";
foreach(array_slice($clashes, 0, 5) as $c) {
    echo "Teacher ID {$c->user_id} on {$c->date} at {$c->start_time}: Assigned to {$c->count} classes.\n";
}
