<?php

namespace App\Observers;

use App\Models\User;
use App\Models\Faculty;

class UserObserver
{
    public function created(User $user): void
    {
        if ($user->role === 'faculty') {
            Faculty::create([
                'user_id' => $user->id,
                'faculty_type' => 'full_time',
                'max_teaching_load' => 24,
                'is_active' => true,
            ]);
        }
    }

    public function deleted(User $user): void
    {
        if ($user->role === 'faculty' && $user->faculty) {
            $user->faculty->delete();
        }
    }
}


/**
 * UserObserver
 *
 * Reacts to User model events:
 * - created(): if a new user is faculty, create a default Faculty record
 * - deleted(): if a faculty user is deleted, remove the linked Faculty record
 */