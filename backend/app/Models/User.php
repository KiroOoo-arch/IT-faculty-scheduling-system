<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password', 'role'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;
    
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function faculty()
    {
        return $this->hasOne(Faculty::class);
    }

    /**
     * schedules.approved_by is a NO ACTION foreign key onto users, so deleting
     * a user who is credited with an approval fails at the database level.
     * Clear the attribution instead of the schedule: the approval genuinely
     * happened and the timetable should survive, we simply no longer have an
     * account to name. This mirrors the deleting hooks on Faculty, Room and
     * Subject.
     */
    protected static function booted()
    {
        static::deleting(function ($user) {
            Schedule::where('approved_by', $user->id)->update(['approved_by' => null]);
        });
    }
}