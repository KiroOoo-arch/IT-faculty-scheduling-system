<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // UserObserver removed: faculty are records, not users.
        // Faculty profiles are created directly via Faculty Management.
    }
}
