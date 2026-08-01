<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Room extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'type', 'capacity', 'status'];

    // 👇 ADD THIS
    public function sessions()
    {
        return $this->hasMany(ScheduleSession::class);
    }

    // 👇 ADD THIS
    protected static function booted()
    {
        static::deleting(function ($room) {
            $room->sessions()->delete();
        });
    }
}
