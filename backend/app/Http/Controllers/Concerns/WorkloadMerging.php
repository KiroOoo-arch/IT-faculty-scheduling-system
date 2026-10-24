<?php

namespace App\Http\Controllers\Concerns;

/**
 * Shared interval-merging logic used by ReportController and
 * ScheduleApprovalController to calculate a faculty member's weekly
 * teaching hours from published schedule sessions. Both sides must
 * produce identical results so the report and the publish-time load
 * guard compare like-with-like.
 */
trait WorkloadMerging
{
    /** Minutes past midnight for `HH:MM` or `HH:MM:SS`. */
    private function minutes(?string $time): int
    {
        [$hours, $minutes] = array_pad(explode(':', substr((string) $time, 0, 5)), 2, '0');

        return ((int) $hours) * 60 + (int) $minutes;
    }

    /** Total unique occupied minutes once overlapping or adjacent intervals on
     * the same weekday are merged. Weekdays stay separate: a Monday meeting
     * does not shorten Tuesday.
     *
     * @param  iterable<int, object{day_of_week: int|string, start_time: ?string, end_time: ?string}>  $sessions
     */
    private function mergedMinutesByDay(iterable $sessions): int
    {
        // Build intervals per day of week (1=Monday through 7=Sunday).
        $intervalsByDay = [];

        foreach ($sessions as $session) {
            $day = (int) $session->day_of_week;
            $start = $this->minutes($session->start_time);
            $end = $this->minutes($session->end_time);

            // A weekday outside 1–7 or a pair that does not move forward
            // occupies no measurable time.
            if ($day < 1 || $day > 7 || $end <= $start) {
                continue;
            }

            $intervalsByDay[$day][] = [$start, $end];
        }

        // Merge overlapping/adjacent intervals for each day so simultaneous
        // sessions are not double-counted. Weekdays stay separate: a Monday
        // meeting does not shorten Tuesday.
        $totalMinutes = 0;

        foreach ($intervalsByDay as $intervals) {
            usort($intervals, fn ($a, $b) => $a[0] <=> $b[0]);

            $merged = [];

            foreach ($intervals as [$start, $end]) {
                if (empty($merged)) {
                    $merged[] = [$start, $end];
                    continue;
                }

                $last = count($merged) - 1;

                if ($start <= $merged[$last][1]) {
                    $merged[$last][1] = max($merged[$last][1], $end);
                } else {
                    $merged[] = [$start, $end];
                }
            }

            foreach ($merged as [$start, $end]) {
                $totalMinutes += $end - $start;
            }
        }

        return $totalMinutes;
    }
}