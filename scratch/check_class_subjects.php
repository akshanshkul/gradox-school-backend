<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$response = $kernel->handle(
    Illuminate\Http\Request::capture()
);

$schoolId = 9; // Assuming school ID 9

$classesList = \DB::table('school_classes')
    ->where('school_classes.school_id', $schoolId)
    ->leftJoin('grades', 'school_classes.grade_id', '=', 'grades.id')
    ->leftJoin('sections', 'school_classes.section_id', '=', 'sections.id')
    ->leftJoin('users as teachers', 'school_classes.class_teacher_id', '=', 'teachers.id')
    ->leftJoin('classrooms', 'school_classes.default_classroom_id', '=', 'classrooms.id')
    ->select(
        'school_classes.*',
        'grades.name as grade_name',
        'sections.name as section_name',
        'teachers.name as teacher_name',
        'classrooms.name as classroom_name'
    )
    ->get();

$classSubjects = \DB::table('class_subject')
    ->join('subjects', 'class_subject.subject_id', '=', 'subjects.id')
    ->select(
        'class_subject.id as class_subject_id',
        'class_subject.school_class_id',
        'class_subject.subject_id as id',
        'subjects.name',
        'subjects.code',
        'class_subject.periods_per_week',
        'class_subject.teacher_id'
    )
    ->get();

$classSubjectIds = $classSubjects->pluck('class_subject_id')->toArray();

$notes = \DB::table('class_subject_notes')
    ->whereIn('class_subject_id', $classSubjectIds)
    ->get()
    ->groupBy('class_subject_id');

$syllabus = \DB::table('class_subject_syllabus')
    ->whereIn('class_subject_id', $classSubjectIds)
    ->get()
    ->groupBy('class_subject_id');

$classSubjectsGrouped = $classSubjects->map(function($sub) use ($notes, $syllabus) {
    $subNotes = isset($notes[$sub->class_subject_id])
        ? $notes[$sub->class_subject_id]->map(function($n) {
            return [
                'id' => $n->id,
                'class_subject_id' => $n->class_subject_id,
                'title' => $n->title,
                'file_url' => $n->file_url,
                'description' => $n->description,
                'created_at' => $n->created_at,
                'updated_at' => $n->updated_at,
            ];
        })->toArray()
        : [];

    $subSyllabus = isset($syllabus[$sub->class_subject_id])
        ? $syllabus[$sub->class_subject_id]->map(function($s) {
            return [
                'id' => $s->id,
                'class_subject_id' => $s->class_subject_id,
                'topic' => $s->topic,
                'description' => $s->description,
                'status' => $s->status,
                'created_at' => $s->created_at,
                'updated_at' => $s->updated_at,
            ];
        })->toArray()
        : [];

    return [
        'id' => $sub->id,
        'school_class_id' => $sub->school_class_id, // Added this key!
        'name' => $sub->name,
        'code' => $sub->code,
        'pivot' => [
            'id' => $sub->class_subject_id,
            'periods_per_week' => $sub->periods_per_week,
            'teacher_id' => $sub->teacher_id,
            'notes' => $subNotes,
            'syllabus' => $subSyllabus,
        ]
    ];
})->groupBy('school_class_id');

echo "ISSET CHECK FOR CLASS 39 IN GROUPED:\n";
var_dump(isset($classSubjectsGrouped[39]));

echo "CLASSES LIST:\n";
$classes = $classesList->map(function ($cls) use ($classSubjectsGrouped) {
    return [
        'id' => $cls->id,
        'subjects' => isset($classSubjectsGrouped[$cls->id]) ? $classSubjectsGrouped[$cls->id]->values()->toArray() : [],
    ];
})->toArray();

print_r($classes);
