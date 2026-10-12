<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\DB;

class Subject extends Model
{
    use HasFactory;

    /** A reviewed major: it has an owning department and is schedulable only by the departments authorized for it. */
    public const CLASSIFICATION_MAJOR = 'major';

    /** A reviewed minor / service subject: no owner, open to every department. */
    public const CLASSIFICATION_MINOR = 'minor';

    /** Not reviewed. The database default, and the state that grants nothing. */
    public const CLASSIFICATION_UNCLASSIFIED = 'unclassified';

    /** Every value the classification column accepts. @var list<string> */
    public const CLASSIFICATIONS = [
        self::CLASSIFICATION_MAJOR,
        self::CLASSIFICATION_MINOR,
        self::CLASSIFICATION_UNCLASSIFIED,
    ];

    protected $fillable = [
        'code',
        'title',
        'year_level',
        'semester_name',
        'lecture_hours',
        'lab_hours',
        'lab_room_type',
        'is_active',
        'department_id',
        'classification',
    ];

    /**
     * The department that owns this subject. Non-null only for a major, and then
     * it is always one of the departments authorized for it.
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * The departments authorized to schedule this subject into their sections.
     *
     * For a major this includes its owner; `ELEC201` is the case where it also
     * includes a department that does not own it. A minor has no rows here —
     * being open to everyone is its classification, not a list of every
     * department.
     */
    public function departmentAccess(): BelongsToMany
    {
        return $this->belongsToMany(
            Department::class,
            'subject_department_access',
            'subject_id',
            'department_id'
        );
    }

    public function faculties(): BelongsToMany
    {
        return $this->belongsToMany(Faculty::class, 'faculty_subjects');
    }

    public function sections(): BelongsToMany
    {
        return $this->belongsToMany(Section::class, 'section_subjects');
    }

    public function sessions()
    {
        return $this->hasMany(ScheduleSession::class);
    }

    public function isMajor(): bool
    {
        return $this->classification === self::CLASSIFICATION_MAJOR;
    }

    public function isMinor(): bool
    {
        return $this->classification === self::CLASSIFICATION_MINOR;
    }

    public function isUnclassified(): bool
    {
        return ! in_array($this->classification, [
            self::CLASSIFICATION_MAJOR,
            self::CLASSIFICATION_MINOR,
        ], true);
    }

    /** The department ids authorized for this subject, as integers. @return list<int> */
    public function authorizedDepartmentIds(): array
    {
        return $this->departmentAccess()
            ->pluck('departments.id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Make this subject's access grants exactly the given departments.
     *
     * Used when the subject itself is edited (the owner's grant is mandatory, a
     * minor or unclassified subject holds none). Grants are only inserted when
     * missing and deleted when withdrawn, so a no-op call touches nothing.
     *
     * @param  list<int>  $departmentIds
     */
    public function replaceDepartmentAccess(array $departmentIds): void
    {
        $desired = array_values(array_unique(array_map('intval', $departmentIds)));
        $existing = $this->authorizedDepartmentIds();

        $missing = array_values(array_diff($desired, $existing));
        $stale = array_values(array_diff($existing, $desired));

        if ($missing !== []) {
            DB::table('subject_department_access')->insertOrIgnore(
                array_map(
                    fn (int $departmentId) => [
                        'subject_id' => $this->id,
                        'department_id' => $departmentId,
                    ],
                    $missing
                )
            );
        }

        if ($stale !== []) {
            DB::table('subject_department_access')
                ->where('subject_id', $this->id)
                ->whereIn('department_id', $stale)
                ->delete();
        }

        $this->unsetRelation('departmentAccess');
    }

    /**
     * The subjects a department may see and schedule: every reviewed minor, plus
     * the majors that department is authorized for (its own, and any shared with
     * it through `subject_department_access`).
     *
     * This is the whole ownership rule, written once so the section editor, the
     * pre-generation gate, the session validator and the subject list cannot
     * drift apart. A null department sees only minors — the conservative
     * direction, since a unit that belongs to no department has no claim on
     * anyone's majors.
     *
     * An unclassified subject is deliberately absent from both branches: it
     * grants nothing until someone classifies it.
     */
    public function scopeOwnedByOrSharedWith(Builder $query, ?int $departmentId): Builder
    {
        return $query->where(function (Builder $allowed) use ($departmentId) {
            $allowed->where('subjects.classification', self::CLASSIFICATION_MINOR);

            if ($departmentId === null) {
                return;
            }

            $allowed->orWhere(function (Builder $major) use ($departmentId) {
                $major->where('subjects.classification', self::CLASSIFICATION_MAJOR)
                    ->whereExists(function ($access) use ($departmentId) {
                        $access->select(DB::raw(1))
                            ->from('subject_department_access')
                            ->whereColumn('subject_department_access.subject_id', 'subjects.id')
                            ->where('subject_department_access.department_id', $departmentId);
                    });
            });
        });
    }

    /** The subjects a given section may be assigned, per the authorization rule. */
    public function scopeSchedulableBySection(Builder $query, Section $section): Builder
    {
        return $query->ownedByOrSharedWith(
            $section->department_id === null ? null : (int) $section->department_id
        );
    }

    /**
     * Whether this subject may be attached to the given section.
     *
     *  - a minor is allowed anywhere;
     *  - a major is allowed only where the section's department is authorized
     *    for it (its owner, or a department granted access);
     *  - an unclassified subject is allowed nowhere: it has not been reviewed,
     *    so nothing may be scheduled from it.
     */
    public function isSchedulableBySection(Section $section): bool
    {
        if ($this->isMinor()) {
            return true;
        }

        if (! $this->isMajor()) {
            return false;
        }

        if ($section->department_id === null) {
            return false;
        }

        return in_array((int) $section->department_id, $this->authorizedDepartmentIds(), true);
    }

    /**
     * Why this subject may not be scheduled for this section, or null when it
     * may. One sentence, produced here so the section editor, the generation
     * gate and the session validator all refuse with the same words — and so an
     * unclassified subject is reported as unclassified rather than mislabelled as
     * someone's major.
     */
    public function assignmentConflictReason(Section $section): ?string
    {
        if ($this->isSchedulableBySection($section)) {
            return null;
        }

        if ($this->isUnclassified()) {
            return sprintf(
                '%s has not been classified yet, so it cannot be scheduled. Classify it as a major '
                    .'(with its owning department) or as a minor / shared subject first.',
                $this->code
            );
        }

        $owner = $this->department?->name
            ?? ($this->department_id === null ? 'another department' : "department #{$this->department_id}");

        if ($section->department_id === null) {
            $target = "section {$section->name}, which has no department";
        } else {
            $sectionDepartmentName = $section->department?->name
                ?? "department #{$section->department_id}";

            $target = "section {$section->name} ({$sectionDepartmentName})";
        }

        return sprintf(
            '%s is a major subject of %s and cannot be scheduled for %s.',
            $this->code,
            $owner,
            $target
        );
    }

    /**
     * schedule_sessions.subject_id is a RESTRICT foreign key, so a subject
     * that is already placed in a schedule cannot be deleted until those
     * sessions are gone. Clear them first, then detach the subject from every
     * faculty and section it was linked to. This mirrors the deleting hooks
     * that Faculty and Room already use.
     */
    protected static function booted()
    {
        static::deleting(function ($subject) {
            $subject->sessions()->delete();
            $subject->faculties()->detach();
            $subject->sections()->detach();
        });
    }
}
