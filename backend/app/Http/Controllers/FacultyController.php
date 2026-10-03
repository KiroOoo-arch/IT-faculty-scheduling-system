<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\GuardsPublishedReferences;
use App\Models\Faculty;
use App\Models\ScheduleSession;
use Illuminate\Http\Request;

class FacultyController extends Controller
{
    use GuardsPublishedReferences;

    public function index()
    {
        $faculties = Faculty::with(['subjects'])->get();
        return response()->json($faculties);
    }

    public function show(Faculty $faculty)
    {
        $faculty->load(['subjects']);
        return response()->json($faculty);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'              => 'required|string',
            'user_id'           => 'nullable|exists:users,id',
            'faculty_type'      => 'required|string|in:full_time,part_time',
            'max_teaching_load' => 'required|integer|min:1',
        ]);

        $faculty = Faculty::create($validated);
        return response()->json($faculty, 201);
    }

    public function update(Request $request, Faculty $faculty)
    {
        $validated = $request->validate([
            'name'              => 'required|string',
            'user_id'           => 'nullable|exists:users,id',
            'faculty_type'      => 'required|string|in:full_time,part_time',
            'max_teaching_load' => 'required|integer|min:1',
        ]);

        $faculty->update($validated);
        return response()->json($faculty);
    }

    public function destroy(Request $request, Faculty $faculty)
    {
        $published = ScheduleSession::where('faculty_id', $faculty->id)
            ->whereHas('schedule', fn ($query) => $query->where('status', 'published'))
            ->get(['schedule_id']);

        $conflict = $this->publishedReferenceConflict(
            $request,
            $published->pluck('schedule_id')->unique()->values()->all(),
            ScheduleSession::where('faculty_id', $faculty->id)->count(),
            $published->count()
        );

        if ($conflict) {
            return $conflict;
        }

        $faculty->delete();
        return response()->json(['message' => 'Faculty deleted successfully']);
    }

    public function attachSubjects(Request $request, Faculty $faculty)
    {
        $request->validate([
            'subject_ids' => 'required|array',
            'subject_ids.*' => 'exists:subjects,id',
        ]);

        $faculty->subjects()->syncWithoutDetaching($request->subject_ids);
        return response()->json(['message' => 'Subjects assigned successfully']);
    }

    public function detachSubject(Faculty $faculty, $subjectId)
    {
        $faculty->subjects()->detach($subjectId);
        return response()->json(['message' => 'Subject removed']);
    }

        public function updateAvailability(Request $request, Faculty $faculty)
    {
        // Stored windows are `HH:MM` or `HH:MM:SS`; anything else (a stray
        // "7:30 am", a bare "morning") reaches the engine's hour parser as
        // garbage. Only the clock shape is enforced here, so both forms and
        // nulls (an undeclared window) keep working.
        $clock = '/^(?:[01]?[0-9]|2[0-3]):[0-5][0-9](?::[0-5][0-9])?$/';

        $validated = $request->validate([
            // `present`, not `required`: an empty list is meaningful — it is how
            // the Faculty page clears every day and hands the faculty back to
            // the section's own days ("No days selected — faculty will be
            // available on all section days"). `required` treats [] as missing
            // and made that documented state unreachable.
            'availability' => 'present|array',
            'availability.*.day_of_week' => 'required|integer|min:1|max:7',
            'availability.*.start_time' => ['nullable', 'regex:' . $clock],
            'availability.*.end_time' => ['nullable', 'regex:' . $clock],
        ]);

        // A window that ends before it starts is not a harmless typo: the
        // engine drops it, leaves the day with no usable window, and then treats
        // that faculty as unable to teach at all — so the section silently comes
        // back PARTIAL or INFEASIBLE instead of reporting the bad data. Half a
        // window is refused for the same reason: it reads as "no hours".
        $errors = [];
        foreach ($validated['availability'] as $index => $row) {
            $start = $row['start_time'] ?? null;
            $end = $row['end_time'] ?? null;

            if (($start === null) !== ($end === null)) {
                $errors["availability.{$index}.end_time"] = [
                    'A window needs both a start and an end time, or neither.',
                ];
                continue;
            }

            if ($start !== null && substr($end, 0, 5) <= substr($start, 0, 5)) {
                $errors["availability.{$index}.end_time"] = [
                    'The end time must be later than the start time.',
                ];
            }
        }

        if (!empty($errors)) {
            return response()->json([
                'message' => 'Some availability windows are invalid: '
                    . implode(' ', array_map(fn ($e) => $e[0], $errors)),
                'errors' => $errors,
            ], 422);
        }

        // Delete old availability only once every submitted row is known good,
        // so a rejected save leaves the faculty's existing windows intact.
        $faculty->availabilities()->delete();

        // Insert new
        foreach ($request->availability as $avail) {
            $faculty->availabilities()->create([
                'day_of_week' => $avail['day_of_week'],
                'start_time' => $avail['start_time'] ?? null,
                'end_time' => $avail['end_time'] ?? null,
            ]);
        }

        return response()->json(['message' => 'Availability updated successfully']);
    }

    public function getAvailability(Faculty $faculty)
    {
        return response()->json($faculty->availabilities);
    }

}


/**
 * FacultyController
 *
 * Manages faculty API operations. Faculty are records (not login accounts) —
 * only the Admin/Department Head authenticates with the system.
 * - index(): list all faculties with subjects
 * - show(): get one faculty with subjects
 * - store(): create a faculty record (name is stored directly on the record)
 * - update(): update a faculty record
 * - destroy(): delete a faculty record
 * - attachSubjects(): assign subjects to a faculty
 * - detachSubject(): remove a subject from a faculty
 * - updateAvailability(): set faculty availability
 * - getAvailability(): get faculty availability
 */
