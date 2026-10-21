<?php

namespace App\Http\Controllers\Concerns;

/**
 * Times travel and are stored in 24-hour form (`14:00:00`) because that is what
 * the database, the solver and the API contract use. The human-readable strings
 * inside a `conflicts` array are rendered verbatim in the admin UI, so they are
 * written on a 12-hour clock.
 *
 * Only presentation is formatted here — never round-trip these strings back
 * into a request body, and never use them for comparisons.
 */
trait FormatsTimes
{
    /**
     * `14:00:00` → `2:00 PM`. Accepts `H:i` as well, and keeps any value it
     * cannot parse so a malformed time is visible rather than silently dropped.
     */
    protected function twelveHour(?string $time): string
    {
        if ($time === null || $time === '') {
            return '';
        }

        $parts = explode(':', $time);
        if (!is_numeric($parts[0])) {
            return $time;
        }

        $hour = (int) $parts[0];
        $minutes = str_pad($parts[1] ?? '00', 2, '0');
        $meridiem = $hour < 12 ? 'AM' : 'PM';
        $hour12 = $hour % 12 === 0 ? 12 : $hour % 12;

        return sprintf('%d:%s %s', $hour12, substr($minutes, 0, 2), $meridiem);
    }

    /** `12:00:00`, `15:00:00` → `12:00 PM–3:00 PM`. */
    protected function twelveHourRange(?string $start, ?string $end): string
    {
        return $this->twelveHour($start) . '–' . $this->twelveHour($end);
    }
}
