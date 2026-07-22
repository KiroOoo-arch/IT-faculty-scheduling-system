<?php

use App\Http\Controllers\FacultyController;
use App\Http\Controllers\SubjectController;
use Illuminate\Support\Facades\Route;

Route::get('/faculties', [FacultyController::class, 'index']);
Route::get('/faculties/{faculty}', [FacultyController::class, 'show']);

Route::get('/subjects', [SubjectController::class, 'index']);
Route::get('/subjects/{subject}', [SubjectController::class, 'show']);