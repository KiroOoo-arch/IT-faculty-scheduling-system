"""
Canonical time handling for the scheduling engine.

The solver reasons about **minutes since midnight**. That is the one unit that
can represent 07:30 and 20:30 exactly, which whole hours cannot: the previous
`parse_hour()` helper did `int(value.split(":")[0])`, so a section window of
07:30-20:30 was silently rounded down to 07:00-20:00 before the solver ever saw
it.

Scheduling granularity is `SLOT_MINUTES` (30), not 1: starts are drawn from a
domain of 30-minute steps. Durations stay in real minutes so a 2-hour lecture is
120 minutes and a 2.5-hour lab is 150.

The database columns are PostgreSQL `TIME`, which already store minutes, so no
migration is involved -- only the boundary code that used to discard them.
"""

SLOT_MINUTES = 30

_MINUTES_PER_DAY = 24 * 60


def hhmm_to_minutes(value) -> int:
    """
    Parse a wall-clock time into minutes since midnight.

    Accepts `'HH:MM'`, `'HH:MM:SS'`, a `datetime.time`, or an `int`/`float`
    which is interpreted as **hours** (the shape the solver fixtures and the
    older API contract used, e.g. `7` for 07:00).

    Returns 0 for anything unparseable rather than raising, matching the
    tolerant behaviour of the previous `parse_hour()`, so a malformed row
    degrades to midnight instead of taking the whole generation request down.
    """
    if value is None:
        return 0

    # datetime.time (psycopg2 returns these for TIME columns)
    if hasattr(value, "hour") and hasattr(value, "minute"):
        return int(value.hour) * 60 + int(value.minute)

    if isinstance(value, bool):
        return 0

    if isinstance(value, (int, float)):
        # Legacy contract: a bare number means hours.
        return int(round(float(value) * 60))

    text = str(value).strip()
    if not text:
        return 0

    parts = text.split(":")
    try:
        hours = int(parts[0])
    except (ValueError, IndexError):
        return 0

    minutes = 0
    if len(parts) > 1:
        try:
            minutes = int(parts[1])
        except ValueError:
            minutes = 0

    return hours * 60 + minutes


def minutes_to_hhmm(minutes) -> str:
    """`450` -> `'07:30'`. Clamped to a single day so a bad value stays printable."""
    total = int(minutes)
    total = max(0, min(_MINUTES_PER_DAY, total))
    return f"{total // 60:02d}:{total % 60:02d}"


def hours_to_minutes(hours) -> int:
    """`2` -> `120`, `2.5` -> `150`."""
    return int(round(float(hours) * 60))


def allowed_starts(start_min: int, end_min: int, step: int = SLOT_MINUTES) -> list:
    """
    Every start minute in `[start_min, end_min]` on a `step` grid.

    Used to build the CP-SAT start domain, so the solver can only ever emit a
    session that begins on a half-hour boundary.
    """
    if end_min < start_min:
        return []
    first = -(-start_min // step) * step  # ceil to the next grid point
    return list(range(first, end_min + 1, step))


def exclude_break(starts: list, duration_min: int, break_start: int, break_end: int) -> list:
    """
    Drop the starts whose `[start, start + duration)` would overlap a break.

    A session has a fixed duration, so the starts that collide with the break
    form one contiguous run: the session must end by `break_start`, or begin at
    or after `break_end`. Expressing the break as a domain restriction keeps it
    a hard constraint without introducing per-session booleans.
    """
    if break_end <= break_start:
        return list(starts)

    last_ok_before = break_start - duration_min
    return [s for s in starts if s <= last_ok_before or s >= break_end]