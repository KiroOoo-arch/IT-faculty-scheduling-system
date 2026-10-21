<?php

namespace App\Console\Commands;

use App\Models\Department;
use App\Models\Section;
use App\Models\Subject;
use Database\Seeders\DepartmentSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The gate between "the classification code is deployed" and "the feature is
 * active".
 *
 * It fails closed whenever the reviewed data cannot support generation: an
 * unapplied migration, a missing department, a listed major that is still
 * unclassified or owned by the wrong department, a listed minor that is stored as
 * anything but a minor, a major whose owner holds no access grant, a minor
 * holding one anyway, or a section holding a subject it may not schedule.
 *
 * It equally refuses to fail when the data is merely incomplete-but-harmless. A
 * subject nobody has reviewed blocks nothing until a section holds it, so an
 * unlisted one is reported as a warning; a code the seeder lists but that has no
 * subject
 * row yet is a note, because the seeder describes curriculum intent. Widening a
 * major's access on purpose is reported as a warning too, since that is a
 * legitimate administrative act rather than a mistake to overrule.
 *
 * The approved mapping is read from Database\Seeders\DepartmentSeeder, so the
 * command and the backfill cannot disagree about what "reviewed" means.
 *
 * Strictly read-only: it only ever selects. Every number printed is derived from
 * the database as it is at that moment, so running it twice cannot change its
 * answer for the same data.
 */
class DepartmentsPreflightCommand extends Command
{
    protected $signature = 'departments:preflight';

    protected $description = 'Report whether the reviewed department and subject classification data is deployed, consistent and safe to generate from.';

    /** How many section/subject assignment(s) were judged, for the summary line. */
    private int $assignmentCount = 0;

    public function handle(): int
    {
        $lines = [];

        $schemaProblems = $this->schemaProblems();

        if ($schemaProblems !== []) {
            foreach ($schemaProblems as $problem) {
                $lines[] = "NOT READY: $problem";
            }

            return $this->report($lines, ready: false);
        }

        $lines[] = 'Schema: the department and classification migrations are applied';

        $departments = Department::orderBy('code')->get()->keyBy('code');

        $ready = $this->departmentLines($lines, $departments);

        $majorCount = Subject::where('classification', Subject::CLASSIFICATION_MAJOR)->count();
        $minorCount = Subject::where('classification', Subject::CLASSIFICATION_MINOR)->count();
        $unclassifiedCount = Subject::count() - $majorCount - $minorCount;

        $lines[] = "Classification coverage: $majorCount major, $minorCount minor / shared and $unclassifiedCount unclassified subject(s)";

        if ($majorCount === 0 && $minorCount === 0) {
            $lines[] = 'NOT READY: No subject is classified yet, so nothing can be scheduled or generated';
            $ready = false;
        }

        [$verifiedMajors, $ownershipProblems] = $this->ownershipReport($departments);

        $ready = $this->section($lines, 'Major subject ownership', $ownershipProblems, [
            "$verifiedMajors major subject(s) classified and owned by the expected department",
        ]) && $ready;

        $ready = $this->section($lines, 'Department access grants', $this->accessProblems($departments), [
            'no minor subject holds a major-specific access grant',
        ]) && $ready;

        $ready = $this->section($lines, 'Section assignments', $this->assignmentProblems(), [
            "{$this->assignmentCount} assignment(s) are consistent with the reviewed classification",
        ]) && $ready;

        if (! $ready) {
            return $this->report($lines, ready: false);
        }

        return $this->report(array_merge($lines, $this->notesAndWarnings($departments, $unclassifiedCount)), ready: true);
    }

    /**
     * The findings that are not refusals: a listed code with no subject row, an
     * unreviewed subject, and access deliberately widened past the mapping.
     *
     * @return list<string>
     */
    private function notesAndWarnings(Collection $departments, int $unclassifiedCount): array
    {
        $lines = [];
        $warnings = 0;

        $absent = $this->listedButAbsent();

        if ($absent !== []) {
            $lines[] = 'NOTE: ' . count($absent) . ' reviewed subject code(s) are listed but not present in this database: '
                . implode(', ', $absent);
        }

        if ($unclassifiedCount > 0) {
            $warnings++;

            $unreviewed = Subject::where(function ($query) {
                $query->whereNull('classification')
                    ->orWhereNotIn('classification', [
                        Subject::CLASSIFICATION_MAJOR,
                        Subject::CLASSIFICATION_MINOR,
                    ]);
            })->orderBy('code')->pluck('code')->all();

            $lines[] = "WARN: $unclassifiedCount subject(s) are unclassified and cannot be scheduled or generated until they are reviewed";
            $lines[] = 'WARN: unreviewed code(s): ' . implode(', ', $unreviewed);
        }

        $extraGrants = $this->extraGrants($departments);

        if ($extraGrants !== []) {
            $warnings++;
            $lines[] = 'WARN: ' . count($extraGrants) . ' access grant(s) beyond the approved mapping';

            foreach ($extraGrants as $grant) {
                $lines[] = "WARN: {$grant['code']} is authorized for {$grant['department']} beyond the approved mapping";
            }
        }

        $lines[] = $warnings === 0
            ? 'READY: the reviewed classification is deployed and consistent'
            : "READY: the reviewed classification is deployed and consistent, with $warnings warning(s)";

        return $lines;
    }

    /** Write the report and turn it into an exit code. */
    private function report(array $lines, bool $ready): int
    {
        $this->line(implode("\n", $lines));

        return $ready ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Emit one report area: its problems as refusals, or its summary line when it
     * passed. Returns whether the area passed.
     */
    private function section(array &$lines, string $heading, array $problems, array $summaries): bool
    {
        if ($problems === []) {
            foreach ($summaries as $summary) {
                $lines[] = "$heading: $summary";
            }

            return true;
        }

        foreach ($problems as $problem) {
            $lines[] = "$heading: NOT READY: $problem";
        }

        return false;
    }

    /** The migrations the feature needs, in the order a deploy applies them. */
    private function schemaProblems(): array
    {
        $problems = [];

        if (! Schema::hasTable('departments')) {
            $problems[] = 'the departments table is missing';
        }

        if (! Schema::hasTable('subject_department_access')) {
            $problems[] = 'the subject_department_access table is missing';
        }

        if (Schema::hasTable('subjects') && ! Schema::hasColumn('subjects', 'classification')) {
            $problems[] = 'subjects.classification is missing';
        }

        if ($problems === []) {
            return [];
        }

        $problems[] = 'the department and classification migrations have not been applied';
        $problems[] = 'run php artisan migrate to apply them';

        return $problems;
    }

    /** Every department the reviewed mapping requires, present or not. */
    private function departmentLines(array &$lines, Collection $departments): bool
    {
        $missing = [];

        foreach (DepartmentSeeder::DEPARTMENTS as $code => $name) {
            $department = $departments->get($code);

            if ($department === null) {
                $missing[] = $code;
                $lines[] = "Departments: NOT READY: Required department record missing: $code";

                continue;
            }

            $lines[] = "Departments: $code is recorded as {$department->name}";
        }

        if ($missing !== []) {
            $lines[] = 'Departments: NOT READY: record the reviewed departments with php artisan db:seed --class=DepartmentSeeder';

            return false;
        }

        return true;
    }

    /**
     * Both directions of the reviewed classification, for every listed code that
     * exists: a listed major must be classified as a major and owned by the
     * department the seeder lists it under, and a listed minor must be classified
     * as a minor.
     *
     * A listed major stored as a minor or as unclassified is schedulable in fewer
     * places than the review intended — nowhere, for an unclassified one. A listed
     * minor stored as a major is the mirror image: it stops being open to every
     * department and becomes schedulable only where the access table allows. Both
     * are refusals rather than warnings.
     *
     * A listed code with no subject row yet is not judged here — the seeder
     * describes curriculum intent, and an absent subject has no classification to
     * get wrong.
     *
     * @return array{0: int, 1: list<string>}
     */
    private function ownershipReport(Collection $departments): array
    {
        $problems = [];
        $verified = 0;
        $map = DepartmentSeeder::classificationMap();
        $subjects = Subject::whereIn('code', array_keys($map))->get()->keyBy('code');

        foreach ($map as $code => $target) {
            $subject = $subjects->get($code);

            if ($subject === null) {
                // No subject row yet: noted as "listed but not present", not a problem.
                continue;
            }

            if ($target['classification'] === Subject::CLASSIFICATION_MINOR) {
                if ($subject->classification !== Subject::CLASSIFICATION_MINOR) {
                    $problems[] = "$code is listed as a minor / shared subject but is classified as "
                        . "'{$subject->classification}', so it is schedulable only by the departments "
                        . 'authorized for it instead of by every department';
                }

                continue;
            }

            $ownerCode = $target['department'];

            if ($subject->classification !== Subject::CLASSIFICATION_MAJOR) {
                $problems[] = "$code is listed as a $ownerCode major but is classified as '{$subject->classification}', "
                    . 'so it is schedulable nowhere until it is reviewed';

                continue;
            }

            $owner = $departments->firstWhere('code', $ownerCode);

            if ($owner === null) {
                // The absent department is already reported, and there is nothing to compare an owner against.
                continue;
            }

            if ((int) $subject->department_id !== (int) $owner->id) {
                $problems[] = "$code is listed as a $ownerCode major but its owning department is "
                    . $this->departmentLabel($subject->department_id)
                    . ', so it is not the major of that department';

                continue;
            }

            $verified++;
        }

        return [$verified, $problems];
    }

    /**
     * A major's owner must hold its access row — enforcement reads the access
     * table alone, so a missing row is a major nobody can schedule, the owner
     * included. That holds for every major, whether or not the reviewed mapping
     * lists its code: a listed major is measured against the mapping, an unlisted
     * one — reviewed through the editor rather than backfilled by the seeder —
     * against its own owning department. A minor must hold none: being open to
     * everyone is its classification, not a list of every department.
     *
     * @return list<string>
     */
    private function accessProblems(Collection $departments): array
    {
        $problems = [];
        $map = DepartmentSeeder::accessMap();

        $subjects = Subject::whereIn('classification', [
            Subject::CLASSIFICATION_MAJOR,
            Subject::CLASSIFICATION_MINOR,
        ])->orderBy('id')->get();

        foreach ($subjects as $subject) {
            $actualIds = $this->accessIds($subject->id);

            if ($subject->classification === Subject::CLASSIFICATION_MINOR) {
                if ($actualIds !== []) {
                    $problems[] = "{$subject->code} is classified as 'minor' but holds " . count($actualIds)
                        . ' access grant(s); a minor subject must hold none';
                }

                continue;
            }

            $expectedCodes = $map[$subject->code] ?? null;

            if ($expectedCodes === null) {
                // A major outside the reviewed mapping: one a human reviewed through
                // the subject editor or the bulk review rather than through the
                // seeder, so the mapping cannot say which departments should hold a
                // row. Its owner's row is still mandatory — enforcement reads this
                // table alone, and the editor keeps the owner's grant in place — so
                // it is measured against its own owning department instead.
                $ownerId = $subject->department_id === null ? null : (int) $subject->department_id;

                if ($ownerId === null) {
                    $problems[] = "$subject->code is classified as 'major' but has no owning department, "
                        . 'so no department can schedule it';
                } elseif (! in_array($ownerId, $actualIds, true)) {
                    $problems[] = "$subject->code is a major outside the reviewed mapping and its owning department "
                        . $this->departmentLabel($ownerId)
                        . ' holds no subject_department_access row for it, so that department cannot schedule it';
                }

                continue;
            }

            foreach ($expectedCodes as $expectedCode) {
                $expected = $departments->firstWhere('code', $expectedCode);

                if ($expected === null || in_array((int) $expected->id, $actualIds, true)) {
                    continue;
                }

                $problems[] = "$subject->code is listed as a major authorized for $expectedCode but has no "
                    . 'subject_department_access row for it, so that department cannot schedule it';
            }
        }

        return $problems;
    }

    /**
     * Every section/subject pair the database currently holds, judged by the same
     * rule the editor and the generation gate use, so the three cannot disagree.
     *
     * @return list<string>
     */
    private function assignmentProblems(): array
    {
        $conflicts = [];
        $this->assignmentCount = 0;

        foreach (Section::with(['department', 'subjects'])->orderBy('id')->get() as $section) {
            foreach ($section->subjects as $subject) {
                $this->assignmentCount++;

                $reason = $subject->assignmentConflictReason($section);

                if ($reason !== null) {
                    $conflicts[] = "section {$section->name} holds {$subject->code}: $reason";
                }
            }
        }

        if ($conflicts === []) {
            return [];
        }

        return array_merge([
            count($conflicts) . " of {$this->assignmentCount} assignment(s) pair a section with a subject it may not "
                . 'schedule; generation for those sections is refused',
        ], $conflicts);
    }

    /** Reviewed codes the seeder lists that have no subject row in this database. */
    private function listedButAbsent(): array
    {
        $codes = array_keys(DepartmentSeeder::classificationMap());
        $present = Subject::whereIn('code', $codes)->pluck('code')->all();

        return array_values(array_diff($codes, $present));
    }

    /**
     * Access a major holds beyond its reviewed mapping. Deliberate widening is an
     * administrative act, so it is reported rather than overruled.
     *
     * @return list<array{code: string, department: string}>
     */
    private function extraGrants(Collection $departments): array
    {
        $grants = [];
        $map = DepartmentSeeder::accessMap();

        $subjects = Subject::where('classification', Subject::CLASSIFICATION_MAJOR)->orderBy('id')->get();

        foreach ($subjects as $subject) {
            $expectedCodes = $map[$subject->code] ?? null;

            if ($expectedCodes === null) {
                continue;
            }

            $expectedIds = [];

            foreach ($expectedCodes as $expectedCode) {
                $expected = $departments->firstWhere('code', $expectedCode);

                if ($expected !== null) {
                    $expectedIds[] = (int) $expected->id;
                }
            }

            foreach (array_diff($this->accessIds($subject->id), $expectedIds) as $extraId) {
                $grants[] = [
                    'code' => $subject->code,
                    'department' => $this->departmentLabel((int) $extraId),
                ];
            }
        }

        return $grants;
    }

    /** The department ids authorized for a subject, as integers. @return list<int> */
    private function accessIds(int $subjectId): array
    {
        return DB::table('subject_department_access')
            ->where('subject_id', $subjectId)
            ->pluck('department_id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    /** A department's code for display, falling back to its id when the row is gone. */
    private function departmentLabel(?int $departmentId): string
    {
        if ($departmentId === null) {
            return 'no department';
        }

        return Department::where('id', $departmentId)->value('code') ?? "department #$departmentId";
    }
}
