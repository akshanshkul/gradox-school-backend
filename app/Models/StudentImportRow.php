<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StudentImportRow extends Model
{
    use HasFactory;

    protected $fillable = [
        'student_import_id',
        'row_number',
        'raw_data',
        'normalized_data',
        'errors',
        'warnings',
        'duplicate_match',
        'duplicate_of_student_id',
        'status',
        'action',
        'committed_student_id',
        'committed_at',
    ];

    protected $casts = [
        'raw_data' => 'array',
        'normalized_data' => 'array',
        'errors' => 'array',
        'warnings' => 'array',
        'duplicate_match' => 'array',
        'committed_at' => 'datetime',
    ];

    public function import()
    {
        return $this->belongsTo(StudentImport::class, 'student_import_id');
    }
}
