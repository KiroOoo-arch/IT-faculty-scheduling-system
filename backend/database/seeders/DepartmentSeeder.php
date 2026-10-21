<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\Section;
use App\Models\Subject;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Departments, and which subjects and sections belong to them.
 *
 * The reviewed curriculum, expressed as three lists:
 *
 *   - MAJORS: subjects owned by a department. Being owned is what makes a
 *     subject that department's major; only departments authorized for it may
 *     schedule it into a section.
 *   - SHARED_ACCESS: majors a second department may also schedule. ELEC201 is
 *     the real case — owned by Electronics Engineering, but taken by the BIT
 *     sections, so it is authorized for both ELEC and BIT.
 *   - MINORS: general-education, service and other shared subjects. No owner,
 *     no access rows, open to every department.
 *
 * Anything in the database that is on none of these lists stays
 * `unclassified`: it cannot be scheduled or generated until someone reviews it.
 * The seeder never guesses a classification for an unknown code, because an
 * unreviewed subject must not silently become schedulable.
 *
 * Counts are asserted by DepartmentPreflightTest: 17 majors, 13 minors.
 *
 * Department display names are part of this mapping too (see DEPARTMENTS): the
 * code never changes, so renaming a department is a one-line edit here plus a
 * re-seed, not a data migration.
 *
 * Idempotent by construction: departments are matched on their unique `code`,
 * classification and ownership are written only where the stored value actually
 * differs (so a re-run does not bump `updated_at` on unchanged rows), and access
 * rows are inserted only when missing and deleted only when no longer part of
 * this mapping. Repeated runs therefore converge on the same rows, and no
 * section, session, schedule or generation-log data is ever touched.
 *
 * Sections are matched on their name prefix (BIT-, ELEC-). That mapping is a
 * one-time backfill choice about this institution's section naming, not an
 * assumption baked into the rule: enforcement reads `sections.department_id`,
 * so a section can be re-homed by editing the field.
 */
class DepartmentSeeder extends Seeder
{
    /** Major subjects, keyed by the department code that owns them. */
    public const MAJORS = [
        'BIT' => [
            'PROG1', 'PROG101', 'PROG201', 'PROG3', 'ADVPROG',
            'DSA201', 'DB201', 'NET201', 'IAS',
            'TPCC 311', 'TPCC 312', 'TPCC 313', 'TPCC 314',
            'TACC 311', 'TACC 312',
        ],
        'ELEC' => ['ELEC', 'ELEC201'],
    ];

    /**
     * Extra departments authorized to schedule a major they do not own.
     *
     * @var array<string, list<string>>
     */
    public const SHARED_ACCESS = [
        'ELEC201' => ['BIT'],
    ];

    /**
     * Minor / shared subjects: no owner, no access rows, open to every
     * department.
     *
     * @var list<string>
     */
    public const MINORS = [
        'MATH1', 'FOLA', 'LEC', 'REG-LEC', 'REG-LAB', 'REG-S2',
        'GE101', 'GE102', 'MATH101', 'ELEC101', 'GE 7', 'GEE 3', 'FL 1',
    ];

    /** Section name prefix → owning department code. */
    private const SECTION_PREFIXES = [
        'BIT-' => 'BIT',
        'ELEC-' => 'ELEC',
    ];

    /**
     * Department code → display name.
     *
     * The code is the stable handle everything keys on (ownership, access grants,
     * section prefixes, `?department_id=`); the name is only what a screen prints.
     * Renaming a department is therefore an edit to this map alone — the seeder
     * matches on code and rewrites a name only when the stored one differs, so a
     * later authorized seed renames the existing row instead of adding a second
     * department for the same code.
     */
    public const DEPARTMENTS = [
        'BIT' => 'Industrial Technology',
        'ELEC' => 'BindTech Electronics',
    ];

    /**
     * Subject code → the review outcome this seeder asserts:
     * `['classification' => 'major'|'minor', 'department' => code|null]`.
     *
     * Shared with DepartmentPreflight, so the command and the backfill cannot
     * disagree about what the approved mapping is.
     *
     * @return array<string, array{classification: string, department: ?string}>
     */
    public static function classificationMap(): array
    {
        $map = [];

        foreach (self::MAJORS as $departmentCode => $codes) {
            foreach ($codes as $code) {
                $map[$code] = [
                    'classification' => Subject::CLASSIFICATION_MAJOR,
                    'department' => $departmentCode,
                ];
            }
        }

        foreach (self::MINORS as $code) {
            $map[$code] = [
                'classification' => Subject::CLASSIFICATION_MINOR,
                'department' => null,
            ];
        }

        return $map;
    }

    /**
     * Subject code → the department codes authorized to schedule it. A minor maps
     * to an empty list; a major always includes its owner.
     *
     * @return array<string, list<string>>
     */
    public static function accessMap(): array
    {
        $map = [];

        foreach (self::MAJORS as $departmentCode => $codes) {
            foreach ($codes as $code) {
                $map[$code] = array_values(array_unique(array_merge(
                    [$departmentCode],
                    self::SHARED_ACCESS[$code] ?? []
                )));
            }
        }

        foreach (self::MINORS as $code) {
            $map[$code] = [];
        }

        return $map;
    }

    public function run(): void
    {
        $ids = $this->seedDepartments();

        $this->classifySubjects($ids);
        $this->syncAccess($ids);
        $this->claimSections($ids);
    }

    /**
     * Department rows, written only when the stored name differs. Re-running with
     * the same name leaves `updated_at` alone.
     *
     * @return array<string, int>
     */
    private function seedDepartments(): array
    {
        $ids = [];

        foreach (self::DEPARTMENTS as $code => $name) {
            $department = Department::firstOrNew(['code' => $code], ['name' => $name]);

            if (! $department->exists || $department->name !== $name) {
                $department->name = $name;
                $department->save();
            }

            $ids[$code] = (int) $department->id;
        }

        return $ids;
    }

    /**
     * Apply the approved mapping to the subjects that are actually present.
     *
     * A code with no subject row is skipped — the lists describe the curriculum's
     * intent, and a subject that does not exist yet has no classification to get
     * wrong. A subject whose stored classification and owner already match is
     * left untouched, so repeated seeding does not churn timestamps.
     *
     * @param  array<string, int>  $ids
     */
    private function classifySubjects(array $ids): void
    {
        $map = self::classificationMap();

        foreach ($this->managedSubjects($map) as $subject) {
            $target = $map[$subject->code];
            $departmentId = $target['department'] === null
                ? null
                : $ids[$target['department']];

            $storedDepartmentId = $subject->department_id === null
                ? null
                : (int) $subject->department_id;

            if ($subject->classification === $target['classification']
                && $storedDepartmentId === $departmentId) {
                continue;
            }

            $subject->forceFill([
                'classification' => $target['classification'],
                'department_id' => $departmentId,
            ])->save();
        }
    }

    /**
     * Make `subject_department_access` match the approved mapping, for the
     * managed codes only: the owner's row plus any granted department for a
     * major, and no rows at all for a minor.
     *
     * The delete is scoped to managed subjects and to departments outside their
     * approved set, so a row this seeder granted and later withdrew disappears,
     * while access on an unmanaged subject is left for a human to review rather
     * than silently removed.
     *
     * @param  array<string, int>  $ids
     */
    private function syncAccess(array $ids): void
    {
        $map = self::classificationMap();
        $access = self::accessMap();

        foreach ($this->managedSubjects($map) as $subject) {
            $expected = array_map(
                fn (string $code) => $ids[$code],
                $access[$subject->code] ?? []
            );

            $existing = DB::table('subject_department_access')
                ->where('subject_id', $subject->id)
                ->pluck('department_id')
                ->map(fn ($id) => (int) $id)
                ->all();

            $missing = array_values(array_diff($expected, $existing));
            $stale = array_values(array_diff($existing, $expected));

            if ($missing !== []) {
                // insertOrIgnore keeps an existing row untouched instead of
                // re-writing it, so a re-run adds nothing and duplicates nothing.
                DB::table('subject_department_access')->insertOrIgnore(
                    array_map(
                        fn (int $departmentId) => [
                            'subject_id' => $subject->id,
                            'department_id' => $departmentId,
                        ],
                        $missing
                    )
                );
            }

            if ($stale !== []) {
                DB::table('subject_department_access')
                    ->where('subject_id', $subject->id)
                    ->whereIn('department_id', $stale)
                    ->delete();
            }
        }
    }

    /**
     * The managed subject rows, keyed by code, fetched once.
     *
     * @param  array<string, array{classification: string, department: ?string}>  $map
     * @return \Illuminate\Database\Eloquent\Collection<int, Subject>
     */
    private function managedSubjects(array $map)
    {
        return Subject::whereIn('code', array_keys($map))->get();
    }

    /** @param array<string, int> $ids */
    private function claimSections(array $ids): void
    {
        foreach (self::SECTION_PREFIXES as $prefix => $code) {
            Section::where('name', 'like', $prefix.'%')
                ->where(function ($query) use ($ids, $code) {
                    $query->whereNull('department_id')
                        ->orWhere('department_id', '!=', $ids[$code]);
                })
                // Query-builder update: it moves the rows that need moving and
                // leaves the rest, timestamps included.
                ->update(['department_id' => $ids[$code]]);
        }
    }
}
