<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'admin' => \App\Http\Middleware\EnsureUserIsAdmin::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // The SPA talks to this API over fetch(); several call sites do not
        // send an "Accept: application/json" header. Without this, Laravel
        // answers such requests the way it would answer a browser form post:
        // an unauthenticated call is redirected to the `login` route (which
        // lives behind auth itself, producing an endless redirect loop) and a
        // ValidationException is redirected `back()` to the SPA's own origin,
        // which the browser then blocks as a cross-origin redirect. Both
        // surface in the UI as an unhelpful "Failed to fetch" instead of a
        // 401/422 the frontend can read.
        $exceptions->shouldRenderJsonWhen(
            fn ($request, $e) => $request->is('api/*') || $request->expectsJson()
        );
    })->create();


/**
 * bootstrap/app.php
 *
 * Configures the application bootstrapping:
 * - sets the app base path
 * - loads web, api, console routes
 * - defines middleware aliases like 'admin'
 */
