<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Faculty extends Model
{
    protected $fillable = [
        'user_id',
        'employee_no',
        'faculty_type',
        'max_teaching_load',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function subjects()
    {
        return $this->belongsToMany(Subject::class, 'faculty_subjects');
    }

    // 👇 ADD THIS: relationship to sessions
    public function sessions()
    {
        return $this->hasMany(ScheduleSession::class);
    }

    // 👇 ADD THIS: auto-delete sessions + pivot records when faculty is deleted
    protected static function booted()
    {
        static::deleting(function ($faculty) {
            $faculty->sessions()->delete();          // Delete their schedule sessions
            $faculty->subjects()->detach();           // Clean up pivot table
        });
    }
}
