<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Faculty extends Model
{
    protected $fillable = [
        'user_id',
        'name',
        'employee_no',
        'faculty_type',
        'max_teaching_load',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * Optional link to a login account. Faculty are records, not users —
     * only the Admin/Department Head logs into the system.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /** Name of this faculty member (stored directly on the record). */
    public function getDisplayNameAttribute(): string
    {
        return $this->name ?? $this->user?->name ?? 'Unknown Faculty';
    }

    public function subjects()
    {
        return $this->belongsToMany(Subject::class, 'faculty_subjects');
    }

    public function sessions()
    {
        return $this->hasMany(ScheduleSession::class);
    }

    // 👇 ADD THIS: relationship to availabilities
    public function availabilities()
    {
        return $this->hasMany(FacultyAvailability::class);
    }

    protected static function booted()
    {
        static::deleting(function ($faculty) {
            $faculty->sessions()->delete();
            $faculty->subjects()->detach();
        });
    }
}
