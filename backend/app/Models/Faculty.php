<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Faculty extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'employee_no',
        'faculty_type',
        'max_teaching_load',
        'is_active',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function subjects(): BelongsToMany
    {
        return $this->belongsToMany(Subject::class, 'faculty_subjects');
    }

    public function availabilities(): HasMany
    {
        return $this->hasMany(FacultyAvailability::class);
    }

    public function scheduleSessions(): HasMany
    {
        return $this->hasMany(ScheduleSession::class);
    }
}