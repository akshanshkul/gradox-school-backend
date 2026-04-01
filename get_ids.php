<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$data = [
    'subjects' => \App\Models\Subject::take(5)->get(['id', 'name']),
    'teachers' => \App\Models\User::whereHas('roles', function($q){ $q->where('name', 'teacher'); })->take(5)->get(['id', 'name']),
    'classrooms' => \App\Models\Classroom::take(5)->get(['id', 'name']),
    'schools' => \App\Models\School::take(2)->get(['id', 'name']),
    'classes' => \App\Models\SchoolClass::take(5)->get(['id']),
];

echo json_encode($data, JSON_PRETTY_PRINT);
