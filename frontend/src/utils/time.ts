/**
 * Times are stored and transported in 24-hour form (`14:00:00`) because that is
 * what the database, the solver and the API contract use. Everything an admin
 * reads is rendered on a 12-hour clock with an AM/PM suffix, e.g. `2:00 PM`.
 *
 * Keep formatting at the point of display: never send a 12-hour string back to
 * the API, and never use these helpers to build a lookup key.
 */

/** `14:00:00` → `2:00 PM`. Accepts `HH:MM` too; returns '' for empty input. */
export function formatTime12h(time?: string | null): string {
  if (!time) return ''

  const [hourPart, minutePart = '00'] = time.split(':')
  const hour = parseInt(hourPart, 10)
  if (Number.isNaN(hour)) return time

  const meridiem = hour < 12 ? 'AM' : 'PM'
  const hour12 = hour % 12 === 0 ? 12 : hour % 12

  return `${hour12}:${minutePart.slice(0, 2)} ${meridiem}`
}

/** `12:00:00`, `15:00:00` → `12:00 PM–3:00 PM`. */
export function formatTimeRange(start?: string | null, end?: string | null): string {
  const from = formatTime12h(start)
  const to = formatTime12h(end)
  if (!from && !to) return ''
  return `${from}–${to}`
}
