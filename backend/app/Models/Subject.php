<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Subject extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'title',
        'year_level',
        'semester_name',
        'lecture_hours',
        'lab_hours',
        'lab_room_type',
        'is_active',
    ];

    public function faculties(): BelongsToMany
    {
        return $this->belongsToMany(Faculty::class, 'faculty_subjects');
    }

    public function sections(): BelongsToMany
    {
        return $this->belongsToMany(Section::class, 'section_subjects');
    }
}