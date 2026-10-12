<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\GuardsPublishedReferences;
use App\Models\ScheduleSession;
use App\Models\Subject;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SubjectController extends Controller
{
    use GuardsPublishedReferences;

    /**
     * Canonical lab room types a subject's lab sessions can require.
     * Mirrors frontend/src/constants/roomTypes.ts (LAB_ROOM_TYPES) so both
     * sides of the room-type-matching constraint share one vocabulary.
     */
    private const LAB_ROOM_TYPES = ['computer_lab', 'science_lab', 'electronics_lab'];

    /**
     * Validation rules shared by store() and update().
     */
    private function subjectRules(?Subject $subject = null): array
    {
        $uniqueCode = $subject
            ? 'required|string|unique:subjects,code,' . $subject->id
            : 'required|string|unique:subjects';

        return [
            'code'          => $uniqueCode,
            'title'         => 'required|string',
            'year_level'    => 'required|integer',
            'semester_name' => 'required|string',
            'lecture_hours' => 'required|integer|min:0',
            'lab_hours'     => 'required|integer|min:0',
            'lab_room_type' => [
                'nullable',
                'string',
                Rule::in(self::LAB_ROOM_TYPES),
            ],
            'is_active'     => 'boolean',
            // Review status. Omitted on create means `unclassified` — the column
            // default — because an unreviewed subject must grant nothing.
            'classification' => [
                'sometimes',
                'string',
                Rule::in(Subject::CLASSIFICATIONS),
            ],
            // Owning department of a major. Required for `major`, forbidden for
            // `minor` and `unclassified`; see classificationErrors().
            'department_id' => 'nullable|integer|exists:departments,id',
            // Departments authorized to schedule this major, beyond its owner.
            // Only meaningful for a major; ELEC201 is granted BIT this way.
            'access_department_ids' => 'sometimes|array',
            'access_department_ids.*' => 'integer|exists:departments,id',
        ];
    }

    /**
     * Fill in a classification the caller left out but plainly implied.
     *
     * Naming a department is an explicit statement that the subject is a major,
     * so it is recorded as one rather than refused — an existing client that only
     * ever sent `department_id` keeps working, and the stored row is explicit
     * instead of relying on a null doing double duty. The inference runs one way
     * only: a null or absent department is never read as "minor", because that is
     * the silent conversion the classification column exists to prevent. A caller
     * who wants a subject to be minor says so.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalizeClassification(array $data): array
    {
        if (array_key_exists('classification', $data)) {
            return $data;
        }

        if (($data['department_id'] ?? null) !== null) {
            $data['classification'] = Subject::CLASSIFICATION_MAJOR;
        }

        return $data;
    }

    /**
     * The classification / ownership pairs the database enforces, checked here so
     * a client gets a 422 with a readable reason instead of a constraint error,
     * and so an invalid combination never reaches the table.
     *
     * - `major` needs an owning department;
     * - `minor` must have no owner (open to every department, claimed by none);
     * - `unclassified` must have no owner either: it is not reviewed, so a
     *   half-claim would suggest a decision nobody has made.
     */
    private function classificationErrors(array $data, ?Subject $subject): array
    {
        $classification = $data['classification']
            ?? $subject?->classification
            ?? Subject::CLASSIFICATION_UNCLASSIFIED;

        $hasDepartment = array_key_exists('department_id', $data)
            ? $data['department_id'] !== null
            : ($subject !== null && $subject->department_id !== null);

        if ($classification === Subject::CLASSIFICATION_MAJOR && ! $hasDepartment) {
            return ['department_id' => [
                'A major subject needs an owning department. Choose the department that owns this subject.',
            ]];
        }

        if ($classification === Subject::CLASSIFICATION_MINOR && $hasDepartment) {
            return ['department_id' => [
                'A minor / shared subject cannot have an owning department: it is open to every department. '
                    .'Clear the department, or classify the subject as a major.',
            ]];
        }

        if ($classification === Subject::CLASSIFICATION_UNCLASSIFIED && $hasDepartment) {
            return ['department_id' => [
                'An unclassified subject cannot have an owning department yet. Classify it as a major first.',
            ]];
        }

        return [];
    }

    /**
     * The departments that will be authorized for the subject once the proposed
     * change is applied.
     *
     * The owner is always included; grants for other departments are preserved,
     * because those are deliberate (ELEC201 → BIT) and a change of owner should
     * not quietly revoke an unrelated department's access. A minor or an
     * unclassified subject ends with no grants at all.
     *
     * @return list<int>
     */
    private function resultingAccessIds(Subject $subject, array $data): array
    {
        $classification = $data['classification'] ?? $subject->classification;
        $departmentId = array_key_exists('department_id', $data)
            ? ($data['department_id'] === null ? null : (int) $data['department_id'])
            : ($subject->department_id === null ? null : (int) $subject->department_id);

        if ($classification !== Subject::CLASSIFICATION_MAJOR || $departmentId === null) {
            return [];
        }

        $existing = $subject->authorizedDepartmentIds();
        $previousOwner = $subject->department_id === null ? null : (int) $subject->department_id;

        $preserved = array_values(array_filter(
            $existing,
            fn (int $id) => $id !== $previousOwner
        ));

        if (array_key_exists('access_department_ids', $data)) {
            $preserved = array_map('intval', $data['access_department_ids']);
        }

        return array_values(array_unique(array_merge([$departmentId], $preserved)));
    }

    /**
     * Sections this subject is attached to that the proposed change would strand
     * — i.e. sections that could no longer schedule it.
     *
     * Without this, the authorization rule could be sidestepped by editing the
     * subject instead of the section: move a major to another department (or
     * unclassify it) and the sections still holding it would silently become
     * unschedulable, taking their existing schedules with them.
     *
     * @param  list<int>  $resultingAccess
     * @return list<string>
     */
    private function strandedSectionNames(Subject $subject, array $data, array $resultingAccess): array
    {
        $classification = $data['classification'] ?? $subject->classification;

        if ($classification === Subject::CLASSIFICATION_MINOR) {
            // Becoming a minor is compatible with every existing section.
            return [];
        }

        if ($resultingAccess === []) {
            // No department will be authorized — an unclassified subject — so
            // every section currently holding it becomes unschedulable.
            return $subject->sections()->pluck('sections.name')->all();
        }

        return $subject->sections()
            ->where(function ($query) use ($resultingAccess) {
                $query->whereNull('sections.department_id')
                    ->orWhereNotIn('sections.department_id', $resultingAccess);
            })
            ->pluck('sections.name')
            ->all();
    }

    /**
     * Lab-hours / lab-room-type consistency check. Returns error strings
     * keyed to lab_room_type / lab_hours for the validation error bag:
     * - lab_hours > 0 requires a valid canonical lab_room_type;
     * - lab_hours = 0 requires lab_room_type to be null (lecture-only),
     *   matching the existing data model (see seeder MATH1) and the
     *   Subjects page rule.
     */
    private function labConsistencyErrors(array $data): array
    {
        $errors = [];

        if (($data['lab_hours'] ?? 0) > 0 && empty($data['lab_room_type'])) {
            $errors['lab_room_type'][] =
                'Lab room type is required when lab hours are greater than 0.';
        }

        if (($data['lab_hours'] ?? 0) == 0 && !empty($data['lab_room_type'])) {
            $errors['lab_hours'][] =
                'Lab room type must be empty when lab hours are 0 '
                . '(this subject has no laboratory component).';
        }

        return $errors;
    }

    /**
     * The 422 payload for a set of keyed errors, so the shape is the same
     * everywhere. `message` is the first sentence — a string, never the error bag
     * — because clients render it directly.
     */
    private function rejected(array $errors)
    {
        $first = reset($errors);

        return response()->json([
            'message' => is_array($first) ? ($first[0] ?? 'Validation failed.') : $first,
            'errors'  => $errors,
        ], 422);
    }

    /**
     * List subjects, optionally scoped to what one department may schedule.
     *
     * `?department_id=N` returns that department's authorized majors plus every
     * minor / shared subject, using the same rule the assignment and generation
     * gates apply — so the section editor can ask for exactly the list it is
     * allowed to offer instead of filtering afterwards. Omitting the parameter
     * returns everything, which is what the Subjects management screen needs.
     */
    public function index(Request $request)
    {
        $query = Subject::with(['faculties', 'department', 'departmentAccess']);

        if ($request->has('department_id')) {
            $query->ownedByOrSharedWith(
                $request->filled('department_id')
                    ? (int) $request->input('department_id')
                    : null
            );
        }

        return response()->json($query->get());
    }

    public function show(Subject $subject)
    {
        $subject->load(['faculties', 'department', 'departmentAccess']);
        return response()->json($subject);
    }

    public function store(Request $request)
    {
        $validated = $this->normalizeClassification($request->validate($this->subjectRules()));

        $errors = $this->labConsistencyErrors($validated)
            + $this->classificationErrors($validated, null);

        if (!empty($errors)) {
            return $this->rejected($errors);
        }

        // A classification is always stored explicitly: absent means
        // `unclassified`, which is the column default this keeps visible to the
        // client rather than implicit.
        $validated['classification'] ??= Subject::CLASSIFICATION_UNCLASSIFIED;

        $accessIds = $validated['access_department_ids'] ?? [];
        unset($validated['access_department_ids']);

        $subject = Subject::create($validated);

        // The owner's grant is mandatory for a major; extra grants are the
        // caller's. A minor or unclassified subject gets none.
        $subject->replaceDepartmentAccess(
            $subject->isMajor()
                ? array_merge([(int) $subject->department_id], array_map('intval', $accessIds))
                : []
        );

        return response()->json($subject->load(['department', 'departmentAccess']), 201);
    }

    public function update(Request $request, Subject $subject)
    {
        $validated = $this->normalizeClassification($request->validate($this->subjectRules($subject)));

        $errors = $this->labConsistencyErrors($validated)
            + $this->classificationErrors($validated, $subject);

        if (!empty($errors)) {
            return $this->rejected($errors);
        }

        // An ownership or classification change must not strand an existing
        // section assignment.
        $resultingAccess = $this->resultingAccessIds($subject, $validated);

        $classificationChanged = array_key_exists('classification', $validated)
            && $validated['classification'] !== $subject->classification;

        $newDepartmentId = array_key_exists('department_id', $validated)
            ? ($validated['department_id'] === null ? null : (int) $validated['department_id'])
            : ($subject->department_id === null ? null : (int) $subject->department_id);

        $ownershipChanged = $newDepartmentId
            !== ($subject->department_id === null ? null : (int) $subject->department_id);

        if ($classificationChanged || $ownershipChanged) {
            $stranded = $this->strandedSectionNames($subject, $validated, $resultingAccess);

            if (!empty($stranded)) {
                $message = sprintf(
                    '%s is still assigned to %s, which this change would leave unschedulable. '
                        . 'Remove it from those sections first, then change the classification or owner.',
                    $subject->code,
                    implode(', ', $stranded)
                );

                return $this->rejected(['department_id' => [$message]]);
            }
        }

        $accessIds = $validated['access_department_ids'] ?? null;
        unset($validated['access_department_ids']);

        $subject->update($validated);

        // Always re-synced, not only when the owner moved: reclassifying a major
        // as a minor (or unclassified) has to clear its grants, and the owner's
        // mandatory row has to exist for a major.
        $desired = $subject->isMajor()
            ? ($accessIds === null
                ? $resultingAccess
                : array_merge([(int) $subject->department_id], array_map('intval', $accessIds)))
            : [];

        $subject->replaceDepartmentAccess($desired);

        return response()->json($subject->load(['department', 'departmentAccess']));
    }

    public function destroy(Request $request, Subject $subject)
    {
        $published = ScheduleSession::where('subject_id', $subject->id)
            ->whereHas('schedule', fn ($query) => $query->where('status', 'published'))
            ->get(['schedule_id']);

        $conflict = $this->publishedReferenceConflict(
            $request,
            $published->pluck('schedule_id')->unique()->values()->all(),
            ScheduleSession::where('subject_id', $subject->id)->count(),
            $published->count()
        );

        if ($conflict) {
            return $conflict;
        }

        $subject->delete();
        return response()->json(['message' => 'Subject deleted successfully']);
    }
}


/**
 * SubjectController
 *
 * This controller manages subject API operations:
 * - index(): list all subjects with their faculties, owner and access grants
 * - show(): get one subject with the same
 * - store(): create a new subject (validates lab_hours/lab_room_type consistency)
 * - update(): edit an existing subject (same validation)
 * - destroy(): delete a subject
 *
 * Lab consistency rule: lab_hours > 0 requires one of the canonical lab
 * room types (computer_lab, science_lab, electronics_lab); lab_hours = 0
 * requires lab_room_type to be null. Violations return HTTP 422.
 *
 * Subject classification: every subject is `major`, `minor` or `unclassified`.
 * A major carries one owning department and holds at least that department's
 * access grant; a minor is open to every department and holds no grants; an
 * unclassified subject is schedulable nowhere. A change that would leave a
 * section holding a subject it may no longer schedule is refused with 422 rather
 * than quietly stranding the assignment (see App\Models\Subject
 * ::isSchedulableBySection, which is the rule every layer reads).
 */
