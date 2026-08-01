<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\FacultyController;
use App\Http\Controllers\SubjectController;
use App\Http\Controllers\RoomController;
use App\Http\Controllers\SectionController;
use App\Http\Controllers\ScheduleController;
use App\Http\Controllers\ScheduleApprovalController;
use App\Http\Controllers\ScheduleSessionController;
use App\Http\Controllers\MyScheduleController;
use App\Http\Controllers\ReportController;
use Illuminate\Support\Facades\Route;

// --- Public routes ---
Route::post('/login', [AuthController::class, 'login']);

// --- Protected routes: require a valid Sanctum token ---
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);
    Route::get('/my-schedule', [MyScheduleController::class, 'index']);

    // Read access — any authenticated user (admin or faculty)
    Route::apiResource('faculties', FacultyController::class)->only(['index', 'show']);
    Route::apiResource('subjects', SubjectController::class)->only(['index', 'show']);
    Route::apiResource('rooms', RoomController::class)->only(['index', 'show']);
    Route::apiResource('sections', SectionController::class)->only(['index', 'show']);
    Route::get('/schedules', [ScheduleApprovalController::class, 'index']);
    Route::get('/schedules/{schedule}', [ScheduleApprovalController::class, 'show']);

    // Write access + schedule generation/approval/editing/reports — admin only
    Route::middleware('admin')->group(function () {
        Route::apiResource('faculties', FacultyController::class)->only(['store', 'update', 'destroy']);
        Route::apiResource('subjects', SubjectController::class)->only(['store', 'update', 'destroy']);
        Route::apiResource('rooms', RoomController::class)->only(['store', 'update', 'destroy']);
        Route::apiResource('sections', SectionController::class)->only(['store', 'update', 'destroy']);

        Route::post('/schedules/generate/{section}', [ScheduleController::class, 'generate'])->middleware('admin');
        Route::patch('/schedules/{schedule}/approve', [ScheduleApprovalController::class, 'approve']);
        Route::patch('/schedules/{schedule}/publish', [ScheduleApprovalController::class, 'publish']);
        Route::patch('/schedules/{schedule}/reject', [ScheduleApprovalController::class, 'reject']);
        Route::put('/schedules/sessions/{session}', [ScheduleSessionController::class, 'update']);

        Route::get('/reports/faculty-workload', [ReportController::class, 'facultyWorkload']);
        Route::get('/reports/room-utilization', [ReportController::class, 'roomUtilization']);
        Route::get('/reports/conflicts', [ReportController::class, 'conflicts']);
        Route::apiResource('users', \App\Http\Controllers\UserController::class)->middleware('admin');
        Route::post('/admin/create-faculty', [\App\Http\Controllers\UserController::class, 'createFaculty'])->middleware('admin');
        Route::post('/faculties/{faculty}/subjects', [FacultyController::class, 'attachSubjects']);
        Route::delete('/faculties/{faculty}/subjects/{subjectId}', [FacultyController::class, 'detachSubject']);
        Route::post('/faculties/{faculty}/availability', [FacultyController::class, 'updateAvailability']);
        Route::get('/faculties/{faculty}/availability', [FacultyController::class, 'getAvailability']);
        Route::delete('/schedules/{schedule}', [ScheduleApprovalController::class, 'destroy']);
        Route::get('/login', function () {
        response()->json(['message' => 'Unauthenticated.'], 401);
        })->name('login');



    });
});