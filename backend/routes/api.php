<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\FacultyController;
use App\Http\Controllers\SubjectController;
use App\Http\Controllers\RoomController;
use App\Http\Controllers\SectionController;
use App\Http\Controllers\ScheduleController;
use App\Http\Controllers\ScheduleApprovalController;
use App\Http\Controllers\ScheduleSessionController;
use App\Http\Controllers\ReportController;
use Illuminate\Support\Facades\Route;

// --- Public routes ---
Route::post('/login', [AuthController::class, 'login']);

// --- Protected routes: require a valid Sanctum token ---
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);

    // All routes below are admin-only (EnsureUserIsAdmin middleware).
    // Faculty are records, not users — no faculty accounts exist.
    Route::middleware('admin')->group(function () {
        Route::apiResource('faculties', FacultyController::class);
        Route::apiResource('subjects', SubjectController::class);
        Route::apiResource('rooms', RoomController::class);
        Route::apiResource('sections', SectionController::class);

        Route::post('/schedules/generate/{section}', [ScheduleController::class, 'generate']);
        Route::get('/schedules', [ScheduleApprovalController::class, 'index']);
        Route::get('/schedules/{schedule}', [ScheduleApprovalController::class, 'show']);
        Route::patch('/schedules/{schedule}/approve', [ScheduleApprovalController::class, 'approve']);
        Route::patch('/schedules/{schedule}/publish', [ScheduleApprovalController::class, 'publish']);
        Route::patch('/schedules/{schedule}/unpublish', [ScheduleApprovalController::class, 'unpublish']);
        Route::patch('/schedules/{schedule}/reject', [ScheduleApprovalController::class, 'reject']);
        Route::put('/schedules/sessions/{session}', [ScheduleSessionController::class, 'update']);
        Route::delete('/schedules/{schedule}', [ScheduleApprovalController::class, 'destroy']);

        Route::get('/reports/faculty-workload', [ReportController::class, 'facultyWorkload']);
        Route::get('/reports/room-utilization', [ReportController::class, 'roomUtilization']);
        Route::get('/reports/conflicts', [ReportController::class, 'conflicts']);
        Route::get('/reports/schedule-status', [ReportController::class, 'scheduleStatusOverview']);
        Route::get('/reports/section-summary', [ReportController::class, 'sectionSummary']);

        Route::apiResource('users', \App\Http\Controllers\UserController::class);
        Route::post('/faculties/{faculty}/subjects', [FacultyController::class, 'attachSubjects']);
        Route::delete('/faculties/{faculty}/subjects/{subjectId}', [FacultyController::class, 'detachSubject']);
        Route::post('/faculties/{faculty}/availability', [FacultyController::class, 'updateAvailability']);
        Route::get('/faculties/{faculty}/availability', [FacultyController::class, 'getAvailability']);
        Route::get('/login', function () {
        response()->json(['message' => 'Unauthenticated.'], 401);
        })->name('login');
    });
});
