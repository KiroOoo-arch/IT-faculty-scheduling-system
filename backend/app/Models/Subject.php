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

    public function sessions()
    {
        return $this->hasMany(ScheduleSession::class);
    }

    /**
     * schedule_sessions.subject_id is a RESTRICT foreign key, so a subject
     * that is already placed in a schedule cannot be deleted until those
     * sessions are gone. Clear them first, then detach the subject from every
     * faculty and section it was linked to. This mirrors the deleting hooks
     * that Faculty and Room already use.
     */
    protected static function booted()
    {
        static::deleting(function ($subject) {
            $subject->sessions()->delete();
            $subject->faculties()->detach();
            $subject->sections()->detach();
        });
    }
}