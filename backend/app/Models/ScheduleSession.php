<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ScheduleSession extends Model
{
    protected $fillable = [
        'schedule_id', 'subject_id', 'faculty_id', 'room_id',
        'session_type', 'day_of_week', 'start_time', 'end_time',
    ];

    public function subject() { return $this->belongsTo(Subject::class); }
    public function faculty() { return $this->belongsTo(Faculty::class); }
    public function room() { return $this->belongsTo(Room::class); }
    public function schedule() { return $this->belongsTo(Schedule::class); }
}