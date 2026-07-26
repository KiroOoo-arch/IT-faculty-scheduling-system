<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\FacultyController;
use App\Http\Controllers\SubjectController;
use App\Http\Controllers\RoomController;
use App\Http\Controllers\SectionController;
use App\Http\Controllers\ScheduleController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);

    // Read access — any authenticated user (admin or faculty)
    Route::apiResource('faculties', FacultyController::class)->only(['index', 'show']);
    Route::apiResource('subjects', SubjectController::class)->only(['index', 'show']);
    Route::apiResource('rooms', RoomController::class)->only(['index', 'show']);
    Route::apiResource('sections', SectionController::class)->only(['index', 'show']);

    // Write access + schedule generation — admin only
    Route::middleware('admin')->group(function () {
        Route::apiResource('faculties', FacultyController::class)->only(['store', 'update', 'destroy']);
        Route::apiResource('subjects', SubjectController::class)->only(['store', 'update', 'destroy']);
        Route::apiResource('rooms', RoomController::class)->only(['store', 'update', 'destroy']);
        Route::apiResource('sections', SectionController::class)->only(['store', 'update', 'destroy']);

        Route::post('/schedules/generate/{section}', [ScheduleController::class, 'generate']);
    });
});