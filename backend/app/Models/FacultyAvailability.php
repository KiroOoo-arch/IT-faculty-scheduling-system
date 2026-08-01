<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FacultyAvailability extends Model
{
    protected $fillable = [
        'faculty_id',
        'day_of_week',
        'start_time',
        'end_time',
    ];

    protected $casts = [
        'day_of_week' => 'integer',
    ];

    public function faculty()
    {
        return $this->belongsTo(Faculty::class);
    }
}
