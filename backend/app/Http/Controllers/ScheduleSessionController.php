<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\FormatsTimes;
use App\Models\Faculty;
use App\Models\Room;
use App\Models\Schedule;
use App\Models\ScheduleSession;
use App\Models\Section;
use App\Models\Setting;
use App\Models\Subject;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ScheduleSessionController extends Controller
{
    use FormatsTimes;

    /**
     * The solver's scheduling granularity: ai-engine/solver/time_slots.py
     * declares `SLOT_MINUTES = 30` and draws every start from that grid
     * (scheduler.py filters candidate starts through `allowed_starts(...,
     * SLOT_MINUTES)`). Meetings entered by hand are held to the same grid, so a
     * hand-built timetable is never something the generator could not have
     * produced.
     */
    private const SLOT_MINUTES = 30;

    /**
     * The schedule statuses that make up the term's working timetable.
     *
     * Reading is open across all three because there is nothing to protect here:
     * `GET /api/schedules/{schedule}` already returns a published schedule with
     * every session attached. Refusing the narrower read would only have made
     * the editor unable to display what the dashboard can.
     *
     * `archived` and `rejected` are left out deliberately — they are superseded
     * or discarded attempts, not a timetable anyone works from.
     */
    private const READABLE_STATUSES = ['draft', 'approved', 'published'];

    /**
     * The legacy single-session edit also accepts `approved`: it has always been
     * able to nudge a meeting on a schedule that is awaiting publish, and that
     * is unchanged here.
     */
    private const EDITABLE_STATUSES = ['draft', 'approved'];

    /**
     * Writing is confined to a draft. Once a schedule is approved it is the
     * version that was signed off, so changing it silently would make the
     * approval meaningless; it has to be unpublished back to draft first.
     */
    private const WRITABLE_STATUSES = ['draft'];

    /**
     * The schedule statuses whose faculty/room bookings are live for the same
     * academic term. Drafts count: a sibling draft is a booking someone is
     * actively planning, and the generator refuses to clash with it, so a hand
     * edit must not be able to create the clash the generator forbids.
     */
    private const LIVE_STATUSES = ['draft', 'approved', 'published'];

    /**
     * The two meeting types a subject can require, paired with the column that
     * holds how many hours of it the subject needs.
     *
     * They are kept apart everywhere in this controller. The solver builds one
     * session per type straight from `lecture_hours` and `lab_hours`
     * (scheduler.py), so a 2-hour lecture and a 2-hour lab are two independent
     * budgets — combining them would let a 4-hour lecture stand in for a lab the
     * subject still needs.
     */
    private const HOUR_BUDGETS = [
        'lecture' => 'lecture_hours',
        'laboratory' => 'lab_hours',
    ];

    /** -----------------------------------------------------------------------
     * Manually update a single session's day/time/room/faculty. Admin only.
     *
     * PUT /api/schedules/sessions/{session}
     *
     * The legacy single-session edit. Request and response shape are unchanged:
     * a partial body is merged onto the stored values, the full resulting state
     * is validated, and a placement conflict is still reported as 422 with the
     * same `conflicts` list it always returned.
     *
     * Two checks now run on the merged times that this path did not always make.
     * The first is the 30-minute grid the batch endpoints already enforce, which
     * this path could previously step off. The second is that end still has to
     * fall after start: the request's `after:start_time` rule only compares the
     * two fields of the body, and Laravel passes it outright when the field it
     * compares against is absent — so a body carrying only `end_time` cleared it
     * whatever the stored `start_time` was. Both report through the same
     * `errors`/`conflicts` shape the batch reviewer uses for a row it cannot
     * accept.
     *
     * What is also new is the hour budget. This endpoint cannot add or remove a
     * meeting or move one to a different subject, but it CAN lengthen one, which
     * made it the only way a hand edit could push a subject past the hours it
     * requires. A change that does so is now refused with 422, its reasons in a
     * separate `hours` list rather than folded into `conflicts`.
     *
     * Only a NEW or WORSENED excess is refused, so an unrelated edit — or one
     * that moves an already over-long meeting without lengthening it — still
     * saves. Rejecting those would wedge a schedule holding a row written before
     * the budget existed, with no endpoint able to fix it.
     * ----------------------------------------------------------------------- */
    public function update(Request $request, ScheduleSession $session): JsonResponse
    {
        $this->authorizeEditor($request);

        $schedule = $session->schedule;
        if (! in_array($schedule->status, self::EDITABLE_STATUSES, true)) {
            return response()->json([
                'message' => "Cannot edit a session on a '{$schedule->status}' schedule.",
            ], 422);
        }

        $validated = $request->validate([
            'day_of_week' => 'sometimes|integer|between:1,7',
            'start_time' => 'sometimes|date_format:H:i',
            'end_time' => 'sometimes|date_format:H:i|after:start_time',
            'room_id' => 'sometimes|exists:rooms,id',
            'faculty_id' => 'sometimes|exists:faculties,id',
        ]);

        // Merge proposed changes onto current values, so we validate the FULL
        // resulting state, not just the fields that were sent.
        $proposed = [
            'day_of_week' => $validated['day_of_week'] ?? $session->day_of_week,
            'start_time' => $validated['start_time'] ?? $session->start_time,
            'end_time' => $validated['end_time'] ?? $session->end_time,
            'room_id' => $validated['room_id'] ?? $session->room_id,
            'faculty_id' => $validated['faculty_id'] ?? $session->faculty_id,
        ];

        $conflicts = $this->findConflicts($session, $proposed);
        if (! empty($conflicts)) {
            return response()->json([
                'message' => 'This change would create a conflict.',
                'conflicts' => $conflicts,
            ], 422);
        }

        // The times this would leave behind — read after the merge, so a partial
        // body is judged against the stored values it inherits.
        //
        // Placement is settled first, deliberately: a change that both clashes
        // and lands off the grid answers with the conflict it always did. The
        // grid lives in its own `errors` key rather than in `conflicts`, which
        // is a placement list and stays one.
        $slotErrors = $this->slotErrors($proposed['start_time'], $proposed['end_time']);

        if (! empty($slotErrors)) {
            return $this->rejection(
                'This change would not be saved: the resulting meeting times are not valid.',
                $slotErrors,
                [],
            );
        }

        // The hour budget. Placement is settled first so a change that is
        // illegal for two reasons still reports the conflict it always did, and
        // the totals below are measured on an otherwise-legal timetable.
        //
        // The whole resulting schedule is measured, with this meeting standing
        // in for its own stored row: `hourExcessDetails()` compares the excess
        // before the change against the excess after it, so only a request that
        // creates or worsens one is refused.
        $hours = $this->hourExcessDetails(
            $schedule,
            [array_merge($proposed, [
                'subject_id' => $session->subject_id,
                'session_type' => $session->session_type,
            ])],
            [$session->id],
        );

        if (! empty($hours)) {
            return response()->json([
                'message' => $this->hourExcessMessage($hours),
                'hours' => $hours,
                // Kept present and empty so a client that reads `conflicts` sees
                // no placement clash: the two kinds of refusal stay distinct.
                'conflicts' => [],
            ], 422);
        }

        $session->update($proposed);

        return response()->json($session->load(['subject', 'faculty', 'room']));
    }

    /** -----------------------------------------------------------------------
     * List every meeting in a schedule, chronologically, with the per-subject
     * hour accounting the editor displays.
     *
     * GET /api/schedules/{schedule}/sessions
     * ----------------------------------------------------------------------- */
    public function index(Request $request, Schedule $schedule): JsonResponse
    {
        $this->authorizeEditor($request, 'view');

        if (! in_array($schedule->status, self::READABLE_STATUSES, true)) {
            return response()->json([
                'message' => "Cannot view sessions on a '{$schedule->status}' schedule.",
            ], 422);
        }

        return response()->json([
            'sessions' => $this->orderedSessions($schedule),
            'hours' => $this->subjectHours($schedule),
        ]);
    }

    /** -----------------------------------------------------------------------
     * Add one or more meetings to a draft schedule. Admin only.
     *
     * POST /api/schedules/{schedule}/sessions
     *
     * This is what splits a subject's single generated block across several
     * days: the generator places one `lecture_hours`-long session, and the
     * editor replaces that intent with, say, two one-hour lectures.
     * ----------------------------------------------------------------------- */
    public function store(Request $request, Schedule $schedule): JsonResponse
    {
        $this->authorizeEditor($request);

        if (! in_array($schedule->status, self::WRITABLE_STATUSES, true)) {
            return response()->json([
                'message' => "Cannot add sessions to a '{$schedule->status}' schedule. Only a draft schedule can be edited.",
            ], 422);
        }

        $validated = $request->validate([
            'sessions' => 'required|array|min:1',
            'sessions.*.subject_id' => 'required|integer|exists:subjects,id',
            'sessions.*.session_type' => 'required|string|in:lecture,laboratory',
            'sessions.*.day_of_week' => 'required|integer|between:1,7',
            'sessions.*.start_time' => 'required|date_format:H:i',
            'sessions.*.end_time' => 'required|date_format:H:i',
            'sessions.*.room_id' => 'required|integer|exists:rooms,id',
            'sessions.*.faculty_id' => 'required|integer|exists:faculties,id',
        ]);

        // The whole batch is reviewed against the state the schedule WOULD have
        // before the first INSERT. A rejected batch therefore writes nothing at
        // all, and the transaction below only ever wraps writes that survived
        // review — so there is no half-applied schedule to roll back from.
        $rejection = $this->reviewBatch($schedule, $validated['sessions']);
        if ($rejection !== null) {
            return $rejection;
        }

        $created = DB::transaction(function () use ($schedule, $validated) {
            $created = [];

            foreach ($validated['sessions'] as $data) {
                $created[] = ScheduleSession::create([
                    'schedule_id' => $schedule->id,
                    'subject_id' => $data['subject_id'],
                    'session_type' => $data['session_type'],
                    'day_of_week' => $data['day_of_week'],
                    'start_time' => $data['start_time'].':00',
                    'end_time' => $data['end_time'].':00',
                    'room_id' => $data['room_id'],
                    'faculty_id' => $data['faculty_id'],
                ])->load(['subject', 'faculty', 'room']);
            }

            return $created;
        });

        return $this->saved($schedule, $created, 201);
    }

    /** -----------------------------------------------------------------------
     * Update one or more meetings of a draft schedule in place. Admin only.
     *
     * PUT /api/schedules/{schedule}/sessions
     * ----------------------------------------------------------------------- */
    public function updateBatch(Request $request, Schedule $schedule): JsonResponse
    {
        $this->authorizeEditor($request);

        if (! in_array($schedule->status, self::WRITABLE_STATUSES, true)) {
            return response()->json([
                'message' => "Cannot edit sessions on a '{$schedule->status}' schedule. Only a draft schedule can be edited.",
            ], 422);
        }

        $validated = $request->validate([
            'sessions' => 'required|array|min:1',
            'sessions.*.id' => 'required|integer|exists:schedule_sessions,id',
            'sessions.*.subject_id' => 'sometimes|integer|exists:subjects,id',
            'sessions.*.session_type' => 'sometimes|string|in:lecture,laboratory',
            'sessions.*.day_of_week' => 'sometimes|integer|between:1,7',
            'sessions.*.start_time' => 'sometimes|date_format:H:i',
            'sessions.*.end_time' => 'sometimes|date_format:H:i',
            'sessions.*.room_id' => 'sometimes|integer|exists:rooms,id',
            'sessions.*.faculty_id' => 'sometimes|integer|exists:faculties,id',
        ]);

        $targets = [];

        foreach ($validated['sessions'] as $i => $data) {
            $session = ScheduleSession::find($data['id']);

            // Ownership is checked for every entry before anything is written:
            // a batch naming a session from another schedule must not apply the
            // entries it got right and then fail on the one it got wrong.
            if ($session === null || (int) $session->schedule_id !== (int) $schedule->id) {
                $reason = 'Session #'.$data['id'].' is not part of schedule #'.$schedule->id.'.';

                return $this->rejection(
                    'One or more sessions do not belong to this schedule.',
                    [$i => ['id' => $reason]],
                    [$reason],
                );
            }

            // Merge onto the stored values, so the FULL resulting state is what
            // gets reviewed rather than only the fields that were sent.
            $targets[$i] = [
                'id' => $session->id,
                'subject_id' => $data['subject_id'] ?? $session->subject_id,
                'session_type' => $data['session_type'] ?? $session->session_type,
                'day_of_week' => $data['day_of_week'] ?? (int) $session->day_of_week,
                'start_time' => substr((string) ($data['start_time'] ?? $session->start_time), 0, 5),
                'end_time' => substr((string) ($data['end_time'] ?? $session->end_time), 0, 5),
                'room_id' => $data['room_id'] ?? $session->room_id,
                'faculty_id' => $data['faculty_id'] ?? $session->faculty_id,
            ];
        }

        $rejection = $this->reviewBatch($schedule, $targets, array_column($targets, 'id'));
        if ($rejection !== null) {
            return $rejection;
        }

        DB::transaction(function () use ($targets) {
            foreach ($targets as $target) {
                ScheduleSession::whereKey($target['id'])->update([
                    'subject_id' => $target['subject_id'],
                    'session_type' => $target['session_type'],
                    'day_of_week' => $target['day_of_week'],
                    'start_time' => $target['start_time'].':00',
                    'end_time' => $target['end_time'].':00',
                    'room_id' => $target['room_id'],
                    'faculty_id' => $target['faculty_id'],
                ]);
            }
        });

        return $this->saved($schedule, null, 200);
    }

    /** -----------------------------------------------------------------------
     * Delete one or more meetings from a draft schedule. Admin only.
     *
     * DELETE /api/schedules/{schedule}/sessions
     * ----------------------------------------------------------------------- */
    public function destroy(Request $request, Schedule $schedule): JsonResponse
    {
        $this->authorizeEditor($request);

        if (! in_array($schedule->status, self::WRITABLE_STATUSES, true)) {
            return response()->json([
                'message' => "Cannot delete sessions from a '{$schedule->status}' schedule. Only a draft schedule can be edited.",
            ], 422);
        }

        $validated = $request->validate([
            'session_ids' => 'required|array|min:1',
            'session_ids.*' => 'required|integer|exists:schedule_sessions,id',
        ]);

        // Ownership is settled for the whole list up front. Deleting as the list
        // is walked would have removed the ids that were valid before noticing
        // the one that was not.
        $owned = $schedule->sessions()->whereIn('id', $validated['session_ids'])->pluck('id')->all();
        $foreign = array_values(array_diff($validated['session_ids'], $owned));

        if (! empty($foreign)) {
            $reason = 'Session #'.implode(', #', $foreign)
                .' is not part of schedule #'.$schedule->id.'.';

            return $this->rejection(
                'One or more session IDs do not belong to this schedule.',
                ['session_ids' => [$reason]],
                [$reason],
            );
        }

        DB::transaction(function () use ($schedule, $validated) {
            $schedule->sessions()->whereIn('id', $validated['session_ids'])->delete();
        });

        return $this->saved($schedule, null, 200, ['deleted' => array_values($validated['session_ids'])]);
    }

    /** -----------------------------------------------------------------------

     * Validate a proposed set of changes against the state the schedule would
     * be left in, as one unit.
     *
     * Each rule below mirrors one the solver enforces at generation time, so
     * hand-editing can never produce a timetable the generator would have
     * refused. Two entries in the same request that clash with each other are
     * rejected exactly as if the first had already been stored.
     *
     * @param  list<array<string,mixed>>  $proposed  Meetings that would exist after this request.
     * @param  list<int>  $replacedIds  Stored meetings those entries supersede (empty for a create).
     * @return JsonResponse|null  A 422 naming every problem, or null when the batch is legal.
     * ----------------------------------------------------------------------- */
    private function reviewBatch(Schedule $schedule, array $proposed, array $replacedIds = []): ?JsonResponse
    {
        $section = $schedule->section;

        if ($section === null) {
            return $this->rejection(
                'This schedule is not attached to a section, so its meetings cannot be validated.',
                [],
                ['Schedule #'.$schedule->id.' has no section.'],
            );
        }

        $errors = [];
        $conflicts = [];

        $subjects = Subject::with('department')->whereIn(
            'id',
            collect($proposed)->pluck('subject_id')->filter()->unique()->all()
        )->get()->keyBy('id');

        $targets = [];

        foreach ($proposed as $i => $data) {
            $subject = $subjects[$data['subject_id']] ?? null;

            if ($subject === null) {
                $errors[$i]['subject_id'] = 'Unknown subject.';
                continue;
            }

            $start = substr((string) $data['start_time'], 0, 5);
            $end = substr((string) $data['end_time'], 0, 5);
            $startMinutes = $this->minutes($start);
            $duration = $this->minutes($end) - $startMinutes;

            // -- the meeting's own shape -------------------------------------
            if ($duration <= 0) {
                $errors[$i]['end_time'] = 'The end time must be later than the start time.';
                continue;
            }

            if ($startMinutes % self::SLOT_MINUTES !== 0) {
                $errors[$i]['start_time'] = 'A meeting has to start on a '
                    .self::SLOT_MINUTES.'-minute boundary (07:00, 07:30, 08:00, …); '.$start.' does not.';
                continue;
            }

            if ($duration % self::SLOT_MINUTES !== 0) {
                $errors[$i]['end_time'] = 'A meeting has to last a whole number of '
                    .self::SLOT_MINUTES.'-minute slots; '.$start.'–'.$end.' does not.';
                continue;
            }

            // -- does this subject belong to this section --------------------
            // The same gate ScheduleController applies before it ever calls the
            // engine, so a session cannot be hand-placed onto a subject the
            // section was never assigned.
            if ((int) $subject->year_level !== (int) $section->year_level
                || $subject->semester_name !== $section->semester_name) {
                $errors[$i]['subject_id'] = 'Subject '.$subject->code
                    .' (Year '.$subject->year_level.', '.$subject->semester_name
                    .') cannot be assigned to section '.$section->name
                    .' (Year '.$section->year_level.', '.$section->semester_name.').';
                continue;
            }

            // -- department ownership ----------------------------------------
            // The same rule the section assignment gate applies: a minor may be
            // placed anywhere, a major only where its department is authorized,
            // and an unclassified subject nowhere. The editor builds its subject
            // list from this section's own budget rows, so this is the
            // server-side check that keeps a hand-built request honest.
            if (($reason = $subject->assignmentConflictReason($section)) !== null) {
                $errors[$i]['subject_id'] = $reason;
                continue;
            }

            // -- lecture / laboratory consistency ----------------------------
            $required = (int) $subject->{self::HOUR_BUDGETS[$data['session_type']]};

            if ($required <= 0) {
                $errors[$i]['session_type'] = $data['session_type'] === 'laboratory'
                    ? $subject->code.' has no laboratory component (lab hours 0), so it has no laboratory meeting to place.'
                    : $subject->code.' has no lecture hours, so it has no lecture meeting to place.';
                continue;
            }

            if ($data['session_type'] === 'laboratory' && empty($subject->lab_room_type)) {
                $errors[$i]['session_type'] = $subject->code
                    .' needs a laboratory room type before its laboratory meetings can be placed.';
                continue;
            }

            $targets[$i] = [
                'subject' => $subject,
                'subject_id' => $subject->id,
                'session_type' => $data['session_type'],
                'day_of_week' => (int) $data['day_of_week'],
                'start_time' => $start,
                'end_time' => $end,
                'room_id' => $data['room_id'],
                'faculty_id' => $data['faculty_id'],
            ];
        }

        // -- where each meeting would sit ------------------------------------
        // Room type, room capacity, the midday break and the faculty member's
        // declared availability. This is the shared rule set, so a rule added
        // for the legacy single-session edit applies here too.
        foreach ($targets as $i => $target) {
            $reasons = $this->placementConflicts(
                $target['subject'],
                $target['session_type'],
                Room::find($target['room_id']),
                Faculty::with('availabilities')->find($target['faculty_id']),
                (int) $section->student_count,
                $target['day_of_week'],
                $target['start_time'],
                $target['end_time'],
            );

            if (! empty($reasons)) {
                $errors[$i]['conflicts'] = $reasons;
                $conflicts = array_merge($conflicts, $reasons);
            }
        }

        // -- the section cannot be in two places at once ---------------------
        // Stored meetings that survive the request, plus the other entries in
        // it, are all one occupancy list: a clash between two entries of the
        // same batch is the same error as a clash against a stored row.
        $before = $this->storedSessions($schedule, $replacedIds);

        $codes = Subject::whereIn('id', array_unique(array_merge(
            array_column($before, 'subject_id'),
            array_column($targets, 'subject_id'),
        )))->pluck('code', 'id');

        $occupants = [];
        foreach ($before as $row) {
            $occupants[] = ['index' => null, 'row' => $row];
        }
        foreach ($targets as $i => $target) {
            $occupants[] = ['index' => $i, 'row' => $target];
        }

        foreach ($targets as $i => $target) {
            foreach ($occupants as $occupant) {
                if ($occupant['index'] === $i) {
                    continue; // a meeting does not clash with itself
                }

                if (! $this->overlaps($target, $occupant['row'])) {
                    continue;
                }

                $otherCode = $codes[$occupant['row']['subject_id']]
                    ?? 'subject #'.$occupant['row']['subject_id'];

                $reason = "Overlaps with another meeting in this same schedule ({$otherCode}).";
                $errors[$i]['conflicts'][] = $reason;
                $conflicts[] = $reason;
            }
        }

        // -- faculty and room double-booking in the same term ----------------
        foreach ($targets as $i => $target) {
            $reasons = $this->externalBookings($section, $target);

            if (! empty($reasons)) {
                $errors[$i]['conflicts'] = array_merge($errors[$i]['conflicts'] ?? [], $reasons);
                $conflicts = array_merge($conflicts, $reasons);
            }
        }

        // -- the hour budget -------------------------------------------------
        // Only checked once every entry is otherwise legal: with an invalid row
        // in the batch, the totals are meaningless and the row's own error is
        // the more useful thing to report.
        if (empty($errors)) {
            $conflicts = array_merge($conflicts, $this->hourExcesses($schedule, $targets, $replacedIds));
        }

        $conflicts = array_values(array_unique($conflicts));

        if (! empty($errors) || ! empty($conflicts)) {
            return $this->rejection(
                ! empty($conflicts)
                    ? 'These sessions could not be saved: they conflict with the timetable or exceed the hours the subject requires.'
                    : 'These sessions could not be saved: some of the submitted values are not valid for this schedule.',
                $errors,
                $conflicts,
            );
        }

        return null;
    }

    /** -----------------------------------------------------------------------
     * The rules that constrain where a single meeting may sit, independent of
     * what else is in the schedule.
     *
     * Shared by the legacy single-session edit and the batch reviewer, so the
     * two paths cannot drift apart about the same rule.
     *
     * @return list<string> Human-readable reasons, empty when the placement is legal.
     * ----------------------------------------------------------------------- */
    private function placementConflicts(
        ?Subject $subject,
        ?string $sessionType,
        ?Room $room,
        ?Faculty $faculty,
        int $studentCount,
        int $dayOfWeek,
        string $start,
        string $end,
    ): array {
        $conflicts = [];

        // The room's type must match what the meeting needs: a laboratory
        // session needs the subject's declared lab room type, a lecture needs a
        // lecture room. `type` is the canonical vocabulary shared with
        // RoomController and frontend/src/constants/roomTypes.ts, because the
        // solver matches rooms to sessions by exact string comparison.
        $neededType = $sessionType === 'laboratory' ? $subject?->lab_room_type : 'lecture';
        if ($neededType && $room && $room->type !== $neededType) {
            $conflicts[] = "Room '{$room->name}' is type '{$room->type}', but this session needs '{$neededType}'.";
        }

        // Room capacity — the other half of the same rule, where a room is
        // eligible only if it seats the whole section
        // (scheduler.py: `eligible_rooms = [... if r["capacity"] >= student_count]`).
        if ($studentCount > 0 && $room && $room->capacity < $studentCount) {
            $conflicts[] = "Room '{$room->name}' seats {$room->capacity}, but this section has {$studentCount} students.";
        }

        // Midday break — a hard constraint at generation time, so the same
        // window has to stay clear when a meeting is placed by hand.
        $lunch = Setting::lunch();
        if ($lunch['enabled']) {
            $bStart = substr($lunch['start'], 0, 5);
            $bEnd = substr($lunch['end'], 0, 5);

            if ($bEnd > $bStart && $start < $bEnd && $bStart < $end) {
                $conflicts[] = 'Overlaps the '.$this->twelveHour($bStart)
                    .'–'.$this->twelveHour($bEnd)
                    .' break, which is kept clear for students.';
            }
        }

        // Faculty availability — enforced only when the faculty member has
        // declared windows. An empty set means "no declared restriction", which
        // is deliberately not the same as "unavailable".
        if ($faculty && $faculty->availabilities->isNotEmpty()) {
            $available = $faculty->availabilities->contains(function ($a) use ($dayOfWeek, $start, $end) {
                $aStart = substr((string) $a->start_time, 0, 5);
                $aEnd = substr((string) $a->end_time, 0, 5);

                return (int) $a->day_of_week === $dayOfWeek
                    && $start >= $aStart
                    && $end <= $aEnd;
            });

            if (! $available) {
                $conflicts[] = "{$faculty->name} is not available on day {$dayOfWeek} "
                    ."from {$this->twelveHour($start)} to {$this->twelveHour($end)}.";
            }
        }

        return $conflicts;
    }

    /** -----------------------------------------------------------------------
     * Faculty or room already committed elsewhere in the same academic term.
     *
     * Drafts count. The term scope matches generation exactly: a schedule in
     * another academic year or semester is a different timetable and cannot be a
     * real clash.
     *
     * @param  array<string,mixed>  $target
     * @return list<string>
     * ----------------------------------------------------------------------- */
    private function externalBookings(Section $section, array $target): array
    {
        $reasons = [];

        $external = ScheduleSession::with('schedule')
            ->where('day_of_week', $target['day_of_week'])
            ->where(function ($q) use ($target) {
                $q->where('faculty_id', $target['faculty_id'])
                    ->orWhere('room_id', $target['room_id']);
            })
            // The editing section's own schedules are excluded here: meetings in
            // this schedule were already weighed against each other above, and
            // the section's other drafts are handled by being the same section.
            ->whereHas('schedule', function ($q) use ($section) {
                $q->where('section_id', '!=', $section->id)
                    ->whereIn('status', self::LIVE_STATUSES)
                    ->whereHas('section', function ($s) use ($section) {
                        $s->where('academic_year', $section->academic_year)
                            ->where('semester_name', $section->semester_name);
                    });
            })
            ->get();

        foreach ($external as $other) {
            if (! $this->overlaps($target, $other)) {
                continue;
            }

            if ((int) $other->faculty_id === (int) $target['faculty_id']) {
                $reasons[] = "Faculty is already booked elsewhere at this time (schedule #{$other->schedule_id}).";
            }

            if ((int) $other->room_id === (int) $target['room_id']) {
                $reasons[] = "Room is already booked elsewhere at this time (schedule #{$other->schedule_id}).";
            }
        }

        return $reasons;
    }

    /** -----------------------------------------------------------------------
     * Problems with the times a change would leave a meeting with.
     *
     * The same three checks the batch reviewer applies to a row, in the same
     * order and the same words, so hand-placed meetings sit on the grid the
     * generator draws every start from (`SLOT_MINUTES`) whichever endpoint
     * places them. The first failing check wins, as it does there.
     *
     * The pair is read AFTER a partial update has been merged onto the stored
     * values, because the request-level `after:start_time` rule cannot see the
     * merged state — see update().
     *
     * `end_time` carries the duration failures rather than `start_time`, matching
     * the batch reviewer, even when it is the start that moved: the two figures
     * are named in the message either way.
     *
     * @return array<string,string>  Field => reason, empty when the times are usable.
     * ----------------------------------------------------------------------- */
    private function slotErrors(?string $start, ?string $end): array
    {
        $start = substr((string) $start, 0, 5);
        $end = substr((string) $end, 0, 5);

        $startMinutes = $this->minutes($start);
        $duration = $this->minutes($end) - $startMinutes;

        if ($duration <= 0) {
            return ['end_time' => 'The end time must be later than the start time.'];
        }

        if ($startMinutes % self::SLOT_MINUTES !== 0) {
            return ['start_time' => 'A meeting has to start on a '
                .self::SLOT_MINUTES.'-minute boundary (07:00, 07:30, 08:00, …); '.$start.' does not.'];
        }

        if ($duration % self::SLOT_MINUTES !== 0) {
            return ['end_time' => 'A meeting has to last a whole number of '
                .self::SLOT_MINUTES.'-minute slots; '.$start.'–'.$end.' does not.'];
        }

        return [];
    }

    /** -----------------------------------------------------------------------
     * Subjects the request would push past the hours they require, as data.
     *
     * Lecture and laboratory are measured separately, against the subject's own
     * `lecture_hours` / `lab_hours`.
     *
     * A request is only refused when it makes an excess WORSE. That keeps a
     * schedule whose hours are already over budget editable, while still making
     * it impossible to extend the over-placement. An edit to a different
     * subject, or one that moves an over-long meeting without lengthening it,
     * therefore still saves — a schedule is never wedged by a state it already
     * holds.
     *
     * Both write paths go through here: the batch reviewer renders these rows as
     * sentences in its `conflicts` list, and the legacy single-session edit
     * returns them as the structured `hours` list, so the two can never disagree
     * about the same budget.
     *
     * @param  array<int,array<string,mixed>>  $targets
     * @param  list<int>  $replacedIds
     * @return list<array<string,mixed>>
     * ----------------------------------------------------------------------- */
    private function hourExcessDetails(Schedule $schedule, array $targets, array $replacedIds): array
    {
        $before = $this->placedMinutes($schedule);
        $after = $this->placedMinutes($schedule, $targets, $replacedIds);

        $rows = [];

        foreach ($after as $key => $minutes) {
            [$subjectId, $type] = explode('|', $key);

            $subject = Subject::find($subjectId);
            if ($subject === null) {
                continue;
            }

            $requiredMinutes = (int) $subject->{self::HOUR_BUDGETS[$type]} * 60;
            if ($requiredMinutes <= 0) {
                continue; // placing anything at all is caught per row above
            }

            $excessBefore = max(($before[$key] ?? 0) - $requiredMinutes, 0);
            $excessAfter = max($minutes - $requiredMinutes, 0);

            if ($excessAfter <= $excessBefore) {
                continue;
            }

            $rows[] = [
                'subject_id' => $subject->id,
                'code' => $subject->code,
                'title' => $subject->title,
                'session_type' => $type,
                'required' => round($requiredMinutes / 60, 2),
                'scheduled' => round($minutes / 60, 2),
                'over' => round($excessAfter / 60, 2),
                'message' => $subject->code.' needs only '.$this->plain($requiredMinutes / 60)
                    .'h of '.$type.' time, but this would schedule '
                    .$this->plain($minutes / 60).'h.',
            ];
        }

        return $rows;
    }

    /**
     * The same findings as sentences, for the batch reviewer's `conflicts` list.
     *
     * @param  array<int,array<string,mixed>>  $targets
     * @param  list<int>  $replacedIds
     * @return list<string>
     */
    private function hourExcesses(Schedule $schedule, array $targets, array $replacedIds): array
    {
        return array_map(
            fn (array $row) => $row['message'],
            $this->hourExcessDetails($schedule, $targets, $replacedIds),
        );
    }

    /**
     * One sentence naming the over-placement, for the legacy edit's 422.
     *
     * @param  list<array<string,mixed>>  $excesses
     */
    private function hourExcessMessage(array $excesses): string
    {
        if (count($excesses) === 1) {
            $row = $excesses[0];

            return 'This change would put '.$row['code'].' '
                .$this->plain((float) $row['over']).'h over the '.$row['session_type']
                .' hours it requires: '.$this->plain((float) $row['scheduled'])
                .'h scheduled of the '.$this->plain((float) $row['required']).'h required.';
        }

        return 'This change would put one or more subjects over the hours they require.';
    }

    /** -----------------------------------------------------------------------
     * Minutes of meeting time this schedule holds, per subject and session type.
     *
     * `$targets` are substituted for `$replacedIds`, so this can measure the
     * state before a request and the state after it with one implementation.
     *
     * @param  array<int,array<string,mixed>>  $targets
     * @param  list<int>  $replacedIds
     * @return array<string,int>  "subjectId|sessionType" => minutes
     * ----------------------------------------------------------------------- */
    private function placedMinutes(Schedule $schedule, array $targets = [], array $replacedIds = []): array
    {
        $totals = [];

        $rows = array_merge(
            $this->storedSessions($schedule, $replacedIds),
            array_map(fn (array $t) => [
                'subject_id' => $t['subject_id'],
                'session_type' => $t['session_type'],
                'start_time' => $t['start_time'],
                'end_time' => $t['end_time'],
            ], array_values($targets)),
        );

        foreach ($rows as $row) {
            $key = $row['subject_id'].'|'.$row['session_type'];

            $totals[$key] = ($totals[$key] ?? 0)
                + $this->minutes($row['end_time'])
                - $this->minutes($row['start_time']);
        }

        return $totals;
    }

    /** -----------------------------------------------------------------------
     * Required, scheduled and remaining hours per subject and session type, for
     * every subject this section is assigned.
     *
     * This is what the editor renders, so the counters on screen can never
     * disagree with what is stored. `remaining` is signed: a negative value
     * means the schedule holds more than the subject requires.
     *
     * @return list<array<string,mixed>>
     * ----------------------------------------------------------------------- */
    private function subjectHours(Schedule $schedule): array
    {
        $placed = $this->placedMinutes($schedule);

        $rows = [];

        foreach ($schedule->section?->subjects ?? [] as $subject) {
            foreach (self::HOUR_BUDGETS as $type => $column) {
                $required = (float) $subject->{$column};
                $scheduled = ($placed[$subject->id.'|'.$type] ?? 0) / 60;

                // A subject with no laboratory component has no laboratory row
                // to report. It only appears once something has been placed
                // there — which is itself an error the batch reviewer catches.
                if ($required <= 0 && $scheduled <= 0) {
                    continue;
                }

                $rows[] = [
                    'subject_id' => $subject->id,
                    'code' => $subject->code,
                    'title' => $subject->title,
                    'session_type' => $type,
                    'required' => $required,
                    'scheduled' => round($scheduled, 2),
                    'remaining' => round($required - $scheduled, 2),
                ];
            }
        }

        return $rows;
    }

    /** -----------------------------------------------------------------------
     * The shortfalls in a saved schedule, as sentences.
     *
     * A partial schedule is a legitimate state: the solver itself reports
     * unscheduled sessions, and the Department Head may be building a timetable
     * up one meeting at a time. So an unmet hour requirement is reported back
     * WITH the saved result rather than used to refuse the write. Only
     * over-placement is refused (see hourExcesses()).
     *
     * @param  list<array<string,mixed>>  $hours
     * @return list<string>
     * ----------------------------------------------------------------------- */
    private function hourWarnings(array $hours): array
    {
        $warnings = [];

        foreach ($hours as $row) {
            if ($row['remaining'] > 0) {
                $warnings[] = $row['code'].' '.$row['session_type'].' time is still short: '
                    .$this->plain($row['scheduled']).'h scheduled of the '
                    .$this->plain($row['required']).'h required.';
            }
        }

        return $warnings;
    }

    /** -----------------------------------------------------------------------
     * The body every successful batch write returns: the schedule's meetings,
     * the hour accounting, and any shortfall the admin still has to place.
     *
     * @param  Collection<int, ScheduleSession>|list<ScheduleSession>|null  $sessions
     * @param  array<string,mixed>  $extra
     * ----------------------------------------------------------------------- */
    private function saved(Schedule $schedule, Collection|array|null $sessions, int $status, array $extra = []): JsonResponse
    {
        $schedule->refresh();

        $hours = $this->subjectHours($schedule);

        return response()->json(array_merge($extra, [
            'sessions' => $sessions ?? $this->orderedSessions($schedule),
            'hours' => $hours,
            'warnings' => $this->hourWarnings($hours),
        ]), $status);
    }

    /**
     * The single shape every rejected batch uses: a summary sentence, the
     * per-entry `errors` keyed by the entry's position in the request, and the
     * same information flattened into `conflicts` for clients that only read
     * that.
     *
     * @param  array<int|string,mixed>  $errors  Keyed by request position, or by field name.
     * @param  list<string>  $conflicts
     */
    private function rejection(string $message, array $errors, array $conflicts): JsonResponse
    {
        return response()->json([
            'message' => $message,
            'errors' => $errors,
            'conflicts' => array_values($conflicts),
        ], 422);
    }

    /**
     * The schedule's stored meetings, normalised to the same shape a proposed
     * entry uses so the two can be compared directly, with the meetings a
     * request replaces left out.
     *
     * @param  list<int>  $excludeIds
     * @return list<array<string,mixed>>
     */
    private function storedSessions(Schedule $schedule, array $excludeIds = []): array
    {
        return $schedule->sessions()
            ->when(! empty($excludeIds), fn ($q) => $q->whereNotIn('id', $excludeIds))
            ->get()
            ->map(fn (ScheduleSession $s) => [
                'id' => $s->id,
                'subject_id' => $s->subject_id,
                'session_type' => $s->session_type,
                'day_of_week' => (int) $s->day_of_week,
                'start_time' => substr((string) $s->start_time, 0, 5),
                'end_time' => substr((string) $s->end_time, 0, 5),
                'room_id' => $s->room_id,
                'faculty_id' => $s->faculty_id,
            ])
            ->all();
    }

    /**
     * A schedule's meetings in reading order — Monday first, earliest first.
     *
     * @return Collection<int, ScheduleSession>
     */
    private function orderedSessions(Schedule $schedule): Collection
    {
        return $schedule->sessions()
            ->with(['subject', 'faculty', 'room'])
            ->orderBy('day_of_week')
            ->orderBy('start_time')
            ->get();
    }

    /**
     * Check the proposed session state against everything it must not collide
     * with (the legacy single-session path).
     */
    private function findConflicts(ScheduleSession $session, array $proposed): array
    {
        $schedule = $session->schedule;
        $start = substr((string) $proposed['start_time'], 0, 5);
        $end = substr((string) $proposed['end_time'], 0, 5);

        $conflicts = $this->placementConflicts(
            $session->subject,
            $session->session_type,
            Room::find($proposed['room_id']),
            Faculty::with('availabilities')->find($proposed['faculty_id']),
            (int) ($schedule?->section?->student_count ?? 0),
            (int) $proposed['day_of_week'],
            $start,
            $end,
        );

        // Overlap check: same section's own other sessions (can't be 2 places at once).
        foreach ($schedule->sessions()->where('id', '!=', $session->id)->get() as $other) {
            if ($this->overlaps($proposed, $other)) {
                $conflicts[] = "Overlaps with another session in this same schedule ({$other->subject->code}).";
            }
        }

        // Overlap check: faculty/room double-booking across OTHER live schedules
        // in the SAME term. The target section is excluded, so this measures the
        // sibling drafts, approved and published timetables — exactly what
        // generation checks a new plan against.
        $editingSection = $schedule?->section;

        $externalSessions = ScheduleSession::with('schedule')
            ->where('id', '!=', $session->id)
            ->where(function ($q) use ($proposed) {
                $q->where('faculty_id', $proposed['faculty_id'])
                    ->orWhere('room_id', $proposed['room_id']);
            })
            ->whereHas('schedule', function ($q) use ($schedule, $editingSection) {
                $q->where('section_id', '!=', $schedule->section_id)
                    ->whereIn('status', self::LIVE_STATUSES)
                    ->whereHas('section', function ($s) use ($editingSection) {
                        $s->where('academic_year', $editingSection?->academic_year)
                            ->where('semester_name', $editingSection?->semester_name);
                    });
            })
            ->get();

        foreach ($externalSessions as $other) {
            if (! $this->overlaps($proposed, $other)) {
                continue;
            }
            if ($other->faculty_id == $proposed['faculty_id']) {
                $conflicts[] = "Faculty is already booked elsewhere at this time (schedule #{$other->schedule_id}).";
            }
            if ($other->room_id == $proposed['room_id']) {
                $conflicts[] = "Room is already booked elsewhere at this time (schedule #{$other->schedule_id}).";
            }
        }

        return $conflicts;
    }

    /**
     * Every route here sits behind the `admin` route middleware, so the role is
     * checked twice: once by the route, and once here so the controller stays
     * safe if a route is ever moved out of that group.
     */
    private function authorizeEditor(Request $request, string $verb = 'edit'): void
    {
        if ($request->user()?->role !== 'admin') {
            abort(403, $verb === 'view'
                ? 'Only the Department Head can view schedule sessions.'
                : 'Only the Department Head can edit schedule sessions.');
        }
    }

    /**
     * The fields an overlap depends on, read the same way from a stored model or
     * a normalised array, and reduced to zero-padded HH:MM — which sorts
     * lexicographically the same as chronologically, so plain string comparison
     * is safe. Comparing a stored 'HH:MM:SS' against a proposed 'HH:MM' would
     * make two meetings that merely touch look like an overlap, because
     * '09:00' < '09:00:00' is true as a string.
     */
    private function windowOf(mixed $source): array
    {
        return [
            'day_of_week' => (int) data_get($source, 'day_of_week'),
            'start_time' => substr((string) data_get($source, 'start_time'), 0, 5),
            'end_time' => substr((string) data_get($source, 'end_time'), 0, 5),
        ];
    }

    private function overlaps(mixed $a, mixed $b): bool
    {
        $first = $this->windowOf($a);
        $second = $this->windowOf($b);

        return $first['day_of_week'] === $second['day_of_week']
            && $first['start_time'] < $second['end_time']
            && $second['start_time'] < $first['end_time'];
    }

    /** Minutes past midnight for `HH:MM` or `HH:MM:SS`. */
    private function minutes(?string $time): int
    {
        [$hours, $mins] = array_pad(explode(':', substr((string) $time, 0, 5)), 2, '0');

        return ((int) $hours) * 60 + (int) $mins;
    }

    /** `2.0` → `2`, `2.50` → `2.5`. Keeps hour figures in hour strings readable. */
    private function plain(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
