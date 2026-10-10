<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\FormatsTimes;
use App\Models\Schedule;
use App\Models\ScheduleSession;
use App\Models\Section;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use App\Models\ScheduleGenerationLog;
use App\Models\Subject;

class ScheduleController extends Controller
{
    use FormatsTimes;

    public function generate(Section $section)
    {
        // Pre-scheduling data-integrity gate: every subject assigned to the
        // section must match the section's year level and semester. Blocks
        // legacy/invalid assignments before the AI engine is ever called.
        $mismatches = [];
        foreach ($section->subjects as $subject) {
            if ((int) $subject->year_level !== (int) $section->year_level
                || $subject->semester_name !== $section->semester_name) {
                $problems = [];
                if ((int) $subject->year_level !== (int) $section->year_level) {
                    $problems[] = "belongs to Year {$subject->year_level}";
                }
                if ($subject->semester_name !== $section->semester_name) {
                    $problems[] = "belongs to {$subject->semester_name}";
                }
                $mismatches[] = sprintf(
                    '%s %s; this section is Year %d, %s.',
                    $subject->code,
                    implode(' and ', $problems),
                    $section->year_level,
                    $section->semester_name
                );
            }
        }

        if (!empty($mismatches)) {
            return response()->json([
                'message' => 'Schedule generation blocked: some assigned subjects do not match this section\'s year level and semester. Fix the section\'s subject assignments first. '
                    . implode(' ', $mismatches),
                'errors' => ['subject_ids' => $mismatches],
            ], 422);
        }

        // Serialize generation per academic term. The engine reads its
        // cross-section constraints from the database at the start of the run
        // and the draft is only written at the end of it, so two sections
        // generated for one term at the same time would each read the database
        // before the other's draft existed — and could both be handed the same
        // faculty member or room. Holding this lock across the engine call, the
        // conflict check and the write makes each run see the drafts written by
        // the runs before it.
        //
        // The wait is not optional: if the lock cannot be acquired the request
        // is refused with a 409 rather than quietly proceeding, because a run
        // that ignored the lock could still race the one holding it and persist
        // a conflicting draft. `block()` releases the lock in a `finally`, so
        // it is freed on success, on every handled failure, and on an uncaught
        // exception alike.
        $lockKey = sprintf(
            'schedule-generation:%s:%s',
            $section->academic_year,
            $section->semester_name
        );

        try {
            return Cache::lock(
                $lockKey,
                (int) config('scheduling.generation_lock.ttl', 180)
            )->block((int) config('scheduling.generation_lock.wait', 30), function () use ($section) {
                return $this->generateWithEngine($section);
            });
        } catch (LockTimeoutException $e) {
            return response()->json([
                'error' => 'Another schedule generation is already running for this term.',
                'details' => 'Wait for the current run to finish, then try again.',
            ], 409);
        }
    }

    /**
     * Run the engine and persist its result. Only ever called while this term's
     * generation lock is held (see `generate()`), so the constraint snapshot
     * the engine reads and the draft this method writes cannot interleave with
     * another section's generation for the same term.
     */
    private function generateWithEngine(Section $section)
    {
        // Call the Python AI engine. The section's existing draft is left
        // alone until we have a usable result: archiving up front meant a
        // failed or infeasible attempt replaced the draft with nothing, so a
        // transient engine outage silently destroyed the admin's work.
        //
        // A connection failure (engine not
        // running, port closed) throws instead of returning a response, so it
        // is handled explicitly: the attempt is logged and reported as 502.
        try {
            $response = Http::timeout(30)->post(
                "http://127.0.0.1:8001/generate-schedule/{$section->id}"
            );
        } catch (ConnectionException $e) {
            ScheduleGenerationLog::create([
                'section_id' => $section->id,
                'requested_by' => auth()->id(),
                'status' => 'failure',
                'message' => 'AI engine unreachable: ' . $e->getMessage(),
                'unscheduled_sessions' => null,
            ]);

            return response()->json([
                'error' => 'AI engine unreachable',
                'details' => $e->getMessage(),
            ], 502);
        }

        if ($response->failed()) {
            // The engine's 4xx responses are data problems the Department Head
            // can actually fix — a section with no subjects assigned, no
            // qualified faculty, no available room. Reporting those as 502
            // "AI engine request failed" claimed the server was broken and hid
            // the one sentence that said what to do, so the engine's own reason
            // is surfaced as 422. A genuine upstream fault still reads as 502.
            $engineMessage = $response->json('detail') ?? $response->body();
            $upstreamFault = $response->serverError();

            ScheduleGenerationLog::create([
                'section_id' => $section->id,
                'requested_by' => auth()->id(),
                'status' => 'failure',
                'message' => $upstreamFault
                    ? 'AI engine request failed: ' . $response->body()
                    : $engineMessage,
                'unscheduled_sessions' => null,
            ]);

            if (!$upstreamFault) {
                return response()->json([
                    'message' => $engineMessage,
                    'error' => $engineMessage,
                ], 422);
            }

            return response()->json([
                'error' => 'AI engine request failed',
                'details' => $response->body(),
            ], 502);
        }

        $result = $response->json();

        // FEASIBLE means every session was placed but the solver hit its time
        // budget before proving optimality (scheduler.py returns StatusName()
        // once scheduled_count === total), so it is a successful result and
        // must be treated like OPTIMAL/PARTIAL rather than as a failure.
        if (!in_array($result['status'], ['OPTIMAL', 'FEASIBLE', 'PARTIAL'])) {
            ScheduleGenerationLog::create([
                'section_id' => $section->id,
                'requested_by' => auth()->id(),
                'status' => 'failure',
                'message' => $result['message'] ?? 'No feasible schedule found.',
                'unscheduled_sessions' => $result['sessions'] ?? [],
            ]);

            return response()->json([
                'status' => $result['status'],
                'message' => $result['message'] ?? 'No feasible schedule found.',
            ], 422);
        }

        $scheduled = array_filter($result['sessions'], fn ($s) => $s['is_scheduled'] === true);
        $unscheduled = array_filter($result['sessions'], fn ($s) => $s['is_scheduled'] === false);

        // The engine is told (through the database) to avoid every other
        // section's live timetable in this term, drafts included. If it could
        // not — an infeasible-but-partial run, a stale engine, a section moved
        // into the term by hand — that shows up here, BEFORE anything is
        // written. A conflicting plan is refused and the section's existing
        // draft is left exactly as it was.
        $conflicts = $this->findGeneratedConflicts($section, $scheduled);
        if (!empty($conflicts)) {
            ScheduleGenerationLog::create([
                'section_id' => $section->id,
                'requested_by' => auth()->id(),
                'status' => 'failure',
                'message' => 'Generated schedule conflicts with another section in this term.',
                'unscheduled_sessions' => array_values($unscheduled),
            ]);

            return response()->json([
                'message' => 'Generated schedule conflicts with another section in this term.',
                'conflicts' => $conflicts,
            ], 422);
        }

        // Replace the draft atomically. Archiving the previous draft, creating
        // its replacement and inserting the replacement's sessions are one
        // unit: if any step fails the transaction rolls back, so a half-written
        // schedule can never be left behind and the previous draft survives.
        // The archive stays inside the transaction for the same reason —
        // archiving first and failing afterwards would destroy the admin's
        // draft with nothing to show for it.
        $schedule = DB::transaction(function () use ($section, $scheduled) {
            Schedule::where('section_id', $section->id)
                ->where('status', 'draft')
                ->update(['status' => 'archived']);

            $schedule = Schedule::create([
                'section_id' => $section->id,
                'status' => 'draft',
            ]);

            foreach ($scheduled as $session) {
                ScheduleSession::create([
                    'schedule_id' => $schedule->id,
                    'subject_id' => $session['subject_id'],
                    'faculty_id' => $session['faculty_id'],
                    'room_id' => $session['room_id'],
                    'session_type' => $session['session_type'],
                    'day_of_week' => $session['day_of_week'],
                    // The engine returns wall-clock HH:MM, which is the only form
                    // that preserves a half-hour start such as 07:30. The old
                    // sprintf('%02d:00', ...) rebuilt the time from whole hours and
                    // would have rounded a 7:30 AM session to 7:00 AM.
                    'start_time' => $session['start_time'] ?? sprintf('%02d:00', $session['start_hour']),
                    'end_time' => $session['end_time'] ?? sprintf('%02d:00', $session['end_hour']),
                ]);
            }

            return $schedule;
        });

        // Name the affected subjects in the message so the log reads as a
        // sentence ("GE 1, TPC 311") rather than an anonymous count. The
        // per-session reasons are stored alongside and shown in Reports.
        $message = 'All sessions scheduled successfully.';
        if (count($unscheduled) > 0) {
            $codes = collect($unscheduled)
                ->pluck('subject_id')
                ->filter()
                ->unique()
                ->values();
            $names = Subject::whereIn('id', $codes)->pluck('code', 'id');
            $labels = $codes
                ->map(fn ($id) => $names[$id] ?? "Subject #{$id}")
                ->unique()
                ->implode(', ');

            $message = count($unscheduled) . ' session(s) could not be scheduled: ' . $labels . '.';
        }

        ScheduleGenerationLog::create([
            'section_id' => $section->id,
            'requested_by' => auth()->id(),
            'status' => strtolower($result['status']),
            'message' => $message,
            'unscheduled_sessions' => array_values($unscheduled),
        ]);

        return response()->json([
            'schedule_id' => $schedule->id,
            'status' => $result['status'],
            'sessions' => $schedule->sessions()->with(['subject', 'faculty', 'room'])->get(),
            'unscheduled' => array_values($unscheduled),
        ]);
    }

    /**
     * Faculty/room clashes between a just-generated plan and every OTHER
     * section's live timetable in the same academic term — draft, approved and
     * published alike.
     *
     * Drafts are deliberately included. A sibling draft is a booking someone is
     * actively planning, and it is exactly what the engine was just asked to
     * avoid; this is the check that notices if it did not. The target section is
     * excluded, so regenerating a section is never measured against the draft it
     * is about to replace.
     *
     * @param  array  $scheduled  The engine's scheduled sessions (raw engine shape).
     * @return array<string>     Human-readable clash descriptions, or an empty array.
     */
    private function findGeneratedConflicts(Section $section, array $scheduled): array
    {
        $conflicts = [];

        $external = ScheduleSession::with(['schedule.section', 'room'])
            ->whereHas('schedule', function ($q) use ($section) {
                $q->where('section_id', '!=', $section->id)
                  ->whereIn('status', ['draft', 'approved', 'published'])
                  ->whereHas('section', function ($s) use ($section) {
                      $s->where('academic_year', $section->academic_year)
                        ->where('semester_name', $section->semester_name);
                  });
            })
            ->get();

        foreach ($scheduled as $candidate) {
            // The engine normally sends wall-clock HH:MM, but the hour fields are
            // still accepted, so normalise the same way the insert above does.
            $cStart = substr($candidate['start_time']
                ?? sprintf('%02d:00', $candidate['start_hour']), 0, 5);
            $cEnd = substr($candidate['end_time']
                ?? sprintf('%02d:00', $candidate['end_hour']), 0, 5);

            foreach ($external as $other) {
                if ((int) $candidate['day_of_week'] !== (int) $other->day_of_week) {
                    continue;
                }

                // Both sides are reduced to HH:MM before comparing. Comparing a
                // stored 'HH:MM:SS' with an engine 'HH:MM' would make two
                // sessions that merely touch look like an overlap, because
                // '09:00' < '09:00:00' is true as a string.
                $oStart = substr($other->start_time, 0, 5);
                $oEnd = substr($other->end_time, 0, 5);
                if (!($cStart < $oEnd && $oStart < $cEnd)) {
                    continue;
                }

                $otherSection = $other->schedule?->section?->name ?? "schedule #{$other->schedule_id}";

                if ((int) $candidate['faculty_id'] === (int) $other->faculty_id) {
                    $conflicts[] = "Faculty is already teaching day {$other->day_of_week} "
                        . "{$this->twelveHourRange($oStart, $oEnd)} "
                        . "in section {$otherSection} (schedule #{$other->schedule_id}).";
                }

                if ((int) $candidate['room_id'] === (int) $other->room_id) {
                    $roomName = $other->room->name ?? "room #{$other->room_id}";
                    $conflicts[] = "Room '{$roomName}' is already booked day {$other->day_of_week} "
                        . "{$this->twelveHourRange($oStart, $oEnd)} "
                        . "in section {$otherSection} (schedule #{$other->schedule_id}).";
                }
            }
        }

        return array_values(array_unique($conflicts));
    }
}

/**
 * ScheduleController
 *
 * Generates a draft schedule for a section:
 * - archives old draft schedules
 * - calls the Python AI engine
 * - saves scheduled sessions
 * - logs success or failure and unscheduled sessions
 */