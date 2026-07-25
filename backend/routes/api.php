<?php

use App\Http\Controllers\FacultyController;
use App\Http\Controllers\SubjectController;
use App\Http\Controllers\RoomController;
use App\Http\Controllers\SectionController;
use App\Http\Controllers\ScheduleController;
use Illuminate\Support\Facades\Route;

Route::apiResource('faculties', FacultyController::class);
Route::apiResource('subjects', SubjectController::class);
Route::apiResource('rooms', RoomController::class);
Route::apiResource('sections', SectionController::class);

Route::post('/schedules/generate/{section}', [ScheduleController::class, 'generate']);