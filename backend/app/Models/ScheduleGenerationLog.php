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
        return $this->belongsTo(\App\Models\Section::class);
    }

    public function requestedBy()
    {
        return $this->belongsTo(\App\Models\User::class, 'requested_by');
    }
}
