<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Section extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'year_level', 'academic_year', 'semester_name',
        'preferred_days', 'preferred_start_time', 'preferred_end_time',
    ];

    protected $casts = [
        'preferred_days' => 'array',
    ];

    public function subjects(): BelongsToMany
    {
        return $this->belongsToMany(Subject::class, 'section_subjects');
    }
}