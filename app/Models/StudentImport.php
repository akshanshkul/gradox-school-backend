<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StudentImport extends Model
{
    use HasFactory;

    protected $fillable = [
        'school_id',
        'uploaded_by_user_id',
        'original_filename',
        'stored_path',
        'file_size',
        'mime_type',
        'status',
        'total_rows',
        'valid_rows',
        'warning_rows',
        'error_rows',
        'duplicate_rows',
        'master_data_delta',
        'distribution',
        'commit_meta',
        'committed_at',
    ];

    protected $casts = [
        'master_data_delta' => 'array',
        'distribution' => 'array',
        'commit_meta' => 'array',
        'committed_at' => 'datetime',
    ];

    public function rows()
    {
        return $this->hasMany(StudentImportRow::class)->orderBy('row_number');
    }

    public function school()
    {
        return $this->belongsTo(School::class);
    }

    public function uploadedBy()
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }
}
