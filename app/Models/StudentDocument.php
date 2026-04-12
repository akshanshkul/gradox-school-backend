<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StudentDocument extends Model
{
    use HasFactory;

    protected $fillable = [
        'student_id',
        'document_type_id',
        'file_path',
        'file_type',
        'status',
        'verification_note',
        'verified_by',
        'verified_at'
    ];

    protected $casts = [
        'verified_at' => 'datetime'
    ];

    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    public function type()
    {
        return $this->belongsTo(DocumentType::class, 'document_type_id');
    }

    public function verifier()
    {
        return $this->belongsTo(User::class, 'verified_by');
    }
}
