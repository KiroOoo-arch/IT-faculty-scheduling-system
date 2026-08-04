<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ScheduleGenerationLog extends Model
{
    protected $fillable = [
        'section_id',
        'requested_by',
        'status',
        'message',
        'unscheduled_sessions',
    ];

    protected $casts = [
        'unscheduled_sessions' => 'array',
    ];

    public function section()
    {
        return $this->belongsTo(Section::class);
    }

    public function requestedBy()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}

/**
 * ScheduleGenerationLog
 *
 * Tracks schedule generation attempts:
 * - section_id: the section being scheduled
 * - requested_by: the user who started generation
 * - status: success/failure state
 * - message: result or error details
 * - unscheduled_sessions: list of sessions that could not be scheduled
 */