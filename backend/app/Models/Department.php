<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An academic department — the owning unit for major subjects.
 *
 * A subject with no department is a minor / service subject every department may
 * take; a subject with one belongs to that department alone. Which subjects fall
 * into each group is data, held in the database (see Database\Seeders\
 * DepartmentSeeder), not an assumption in this class or in any controller.
 */
class Department extends Model
{
    protected $fillable = [
        'code',
        'name',
    ];

    public function subjects(): HasMany
    {
        return $this->hasMany(Subject::class);
    }

    public function sections(): HasMany
    {
        return $this->hasMany(Section::class);
    }

    /**
     * The majors this department is authorized to schedule, including any owned
     * by another department (see `subject_department_access`).
     */
    public function subjectAccess(): BelongsToMany
    {
        return $this->belongsToMany(
            Subject::class,
            'subject_department_access',
            'department_id',
            'subject_id'
        );
    }
}
