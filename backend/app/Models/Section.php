<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Section extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'year_level', 'academic_year', 'semester_name',
        'preferred_days', 'preferred_start_time', 'preferred_end_time',
        'student_count', 'department_id',
    ];

    protected $casts = [
        'preferred_days' => 'array',
    ];

    /**
     * The department this section belongs to. It decides which major subjects
     * the section may be assigned; minor / shared subjects are allowed either
     * way. A null department means the section may only take shared subjects.
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function subjects(): BelongsToMany
    {
        return $this->belongsToMany(Subject::class, 'section_subjects');
    }
}