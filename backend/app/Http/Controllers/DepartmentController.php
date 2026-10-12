<?php

namespace App\Http\Controllers;

use App\Models\Department;

/**
 * Read-only list of departments.
 *
 * The Subject and Section screens need the vocabulary to choose an owner from;
 * creating or editing a department is deliberately not exposed yet, so the set of
 * departments stays whatever the ownership seeder put in the database.
 */
class DepartmentController extends Controller
{
    public function index()
    {
        return response()->json(
            Department::orderBy('code')->get(['id', 'code', 'name'])
        );
    }
}
