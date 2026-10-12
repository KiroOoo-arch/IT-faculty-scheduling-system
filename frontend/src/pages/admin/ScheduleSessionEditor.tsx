import { useCallback, useEffect, useMemo, useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { API_BASE_URL, useAuth } from '../../context/AuthContext'
import { formatTimeRange } from '../../utils/time'

/**
 * The multi-session editor: a subject's meetings, one row each.
 *
 * The generator places a subject as a single block — one `lecture_hours`-long
 * lecture and one `lab_hours`-long laboratory (ai-engine/solver/scheduler.py) —
 * and this page is how that block is split across days by hand. Everything it
 * shows is computed from the two budgets the subjects table holds, lecture and
 * laboratory kept apart, because that is what the solver schedules from and what
 * the API enforces.
 */

type HoursRow = {
  subject_id: number
  code: string
  title: string
  session_type: MeetingType
  required: number
  scheduled: number
  remaining: number
}

type MeetingType = 'lecture' | 'laboratory'

type Meeting = {
  /** Stable React key. `id` is null until the meeting has been saved. */
  key: string
  id: number | null
  subject_id: number
  session_type: MeetingType
  day_of_week: number
  start_time: string
  end_time: string
  room_id: number | null
  faculty_id: number | null
}

type Schedule = {
  id: number
  status: 'draft' | 'approved' | 'published' | 'archived' | 'rejected'
  section?: { id: number; name: string; year_level: number; semester_name: string }
}

type Faculty = { id: number; name: string }
type Room = { id: number; name: string; type: string }

type SessionPayload = {
  sessions: {
    id: number
    subject_id: number
    session_type: MeetingType
    day_of_week: number
    start_time: string
    end_time: string
    room_id: number
    faculty_id: number
  }[]
  hours: HoursRow[]
  warnings?: string[]
}

const DAYS = [
  { value: 1, label: 'Monday' },
  { value: 2, label: 'Tuesday' },
  { value: 3, label: 'Wednesday' },
  { value: 4, label: 'Thursday' },
  { value: 5, label: 'Friday' },
  { value: 6, label: 'Saturday' },
  { value: 7, label: 'Sunday' },
]

const TYPE_LABELS: Record<MeetingType, string> = {
  lecture: 'Lecture',
  laboratory: 'Laboratory',
}

/** Meeting lengths the API accepts: the solver's 30-minute slot grid. */
const HALF_HOUR = 1800

/** `09:00`, `10:30` → 90. Returns 0 for anything that is not yet a full pair. */
function minutesBetween(start: string, end: string): number {
  if (!start || !end) return 0

  const [sh, sm] = start.split(':').map(Number)
  const [eh, em] = end.split(':').map(Number)
  if ([sh, sm, eh, em].some((n) => Number.isNaN(n))) return 0

  return eh * 60 + em - (sh * 60 + sm)
}

/** `90` → `1.5`, `120` → `2`. */
function hoursLabel(minutes: number): string {
  return String(Math.round((minutes / 60) * 100) / 100)
}

function budgetKey(subjectId: number, type: MeetingType): string {
  return `${subjectId}|${type}`
}

/** The fields the API stores, and what change detection compares. */
function serialise(meeting: Meeting) {
  return {
    subject_id: meeting.subject_id,
    session_type: meeting.session_type,
    day_of_week: meeting.day_of_week,
    start_time: meeting.start_time,
    end_time: meeting.end_time,
    room_id: meeting.room_id,
    faculty_id: meeting.faculty_id,
  }
}

/**
 * The API reports a rejected batch three ways: a summary `message`, per-entry
 * `errors` keyed by the entry's position, and the same reasons flattened into
 * `conflicts`. The specific reasons are the actionable part, so they are
 * preferred over the summary.
 */
function describeFailure(data: {
  message?: string
  conflicts?: string[]
  errors?: Record<string, Record<string, string | string[]>>
}): string {
  if (Array.isArray(data.conflicts) && data.conflicts.length > 0) {
    return data.conflicts.join(' ')
  }

  if (data.errors && typeof data.errors === 'object') {
    const lines = Object.values(data.errors).flatMap((entry) =>
      Object.values(entry).flatMap((value) => (Array.isArray(value) ? value : [value])),
    )
    if (lines.length > 0) return lines.join(' ')
  }

  return data.message || 'The change could not be saved.'
}

let tempKey = 0

export default function ScheduleSessionEditor() {
  const { scheduleId } = useParams<{ scheduleId: string }>()
  const { token } = useAuth()

  const [schedule, setSchedule] = useState<Schedule | null>(null)
  const [meetings, setMeetings] = useState<Meeting[]>([])
  /** The last state read from the server, so a save only sends what changed. */
  const [saved, setSaved] = useState<Meeting[]>([])
  const [hours, setHours] = useState<HoursRow[]>([])
  const [faculties, setFaculties] = useState<Faculty[]>([])
  const [rooms, setRooms] = useState<Room[]>([])
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState('')
  const [warnings, setWarnings] = useState<string[]>([])
  const [notice, setNotice] = useState('')

  const headers = useMemo(
    () => ({
      Authorization: `Bearer ${token}`,
      Accept: 'application/json',
      'Content-Type': 'application/json',
    }),
    [token],
  )

  /**
   * Everything the page reads, in one request batch.
   *
   * Deliberately free of React state: the mount effect awaits this and only
   * then sets state, so mounting does not cascade a synchronous re-render, and
   * the button-driven reloads below can reuse it unchanged.
   */
  const fetchAll = useCallback(async () => {
    if (!scheduleId) throw new Error('No schedule was selected.')

    const [scheduleRes, sessionRes, facultyRes, roomRes] = await Promise.all([
      fetch(`${API_BASE_URL}/schedules/${scheduleId}`, { headers }),
      fetch(`${API_BASE_URL}/schedules/${scheduleId}/sessions`, { headers }),
      fetch(`${API_BASE_URL}/faculties`, { headers }),
      fetch(`${API_BASE_URL}/rooms`, { headers }),
    ])

    if (!sessionRes.ok) {
      const data = await sessionRes.json().catch(() => ({}))
      throw new Error(describeFailure(data))
    }

    const sessionData: SessionPayload = await sessionRes.json()
    const scheduleData: Schedule | null = scheduleRes.ok ? await scheduleRes.json() : null
    const facultyData = facultyRes.ok ? await facultyRes.json() : []
    const roomData = roomRes.ok ? await roomRes.json() : []

    return {
      sessionData,
      scheduleData,
      faculties: Array.isArray(facultyData) ? facultyData : [],
      rooms: Array.isArray(roomData) ? roomData : [],
    }
  }, [headers, scheduleId])

  /** Put a loaded payload on screen, and remember it as the saved state. */
  const apply = useCallback((payload: Awaited<ReturnType<typeof fetchAll>>) => {
    const loaded: Meeting[] = (payload.sessionData.sessions ?? []).map((s) => ({
      key: `saved-${s.id}`,
      id: s.id,
      subject_id: s.subject_id,
      session_type: s.session_type,
      day_of_week: s.day_of_week,
      start_time: s.start_time.slice(0, 5),
      end_time: s.end_time.slice(0, 5),
      room_id: s.room_id,
      faculty_id: s.faculty_id,
    }))

    setMeetings(loaded)
    setSaved(loaded)
    setHours(payload.sessionData.hours ?? [])
    setWarnings(payload.sessionData.warnings ?? [])
    setSchedule(payload.scheduleData)
    setFaculties(payload.faculties)
    setRooms(payload.rooms)
  }, [])

  useEffect(() => {
    let cancelled = false

    void (async () => {
      try {
        const payload = await fetchAll()
        if (!cancelled) apply(payload)
      } catch (err) {
        if (!cancelled) setError(err instanceof Error ? err.message : 'Failed to load the schedule')
      } finally {
        if (!cancelled) setLoading(false)
      }
    })()

    return () => {
      cancelled = true
    }
  }, [apply, fetchAll])

  /** Re-read from the server, used by the buttons and after a save. */
  const reload = useCallback(async () => {
    setLoading(true)
    setError('')

    try {
      apply(await fetchAll())
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to load the schedule')
    } finally {
      setLoading(false)
    }
  }, [apply, fetchAll])

  const editable = schedule?.status === 'draft'

  /**
   * The subject/type pairs this section can be scheduled for: one per budget the
   * subjects table declares. The composer picks from here, so a lecture hour can
   * never be filed against a laboratory requirement, or the other way round.
   */
  const subjects = useMemo(() => {
    const seen = new Map<number, { id: number; code: string; title: string }>()
    for (const row of hours) {
      if (!seen.has(row.subject_id)) {
        seen.set(row.subject_id, { id: row.subject_id, code: row.code, title: row.title })
      }
    }
    return [...seen.values()].sort((a, b) => a.code.localeCompare(b.code))
  }, [hours])

  function typesForSubject(subjectId: number): MeetingType[] {
    return hours.filter((row) => row.subject_id === subjectId).map((row) => row.session_type)
  }

  /** Live required/scheduled/remaining, summed from the rows on screen. */
  const liveHours = useMemo(() => {
    const scheduled = new Map<string, number>()
    for (const meeting of meetings) {
      const key = budgetKey(meeting.subject_id, meeting.session_type)
      scheduled.set(key, (scheduled.get(key) ?? 0) + minutesBetween(meeting.start_time, meeting.end_time))
    }

    return hours.map((row) => {
      const key = budgetKey(row.subject_id, row.session_type)
      const placedHours = (scheduled.get(key) ?? 0) / 60

      return {
        ...row,
        scheduled: Math.round(placedHours * 100) / 100,
        remaining: Math.round((row.required - placedHours) * 100) / 100,
      }
    })
  }, [hours, meetings])

  const shortfalls = liveHours.filter((row) => row.remaining > 0)
  const overs = liveHours.filter((row) => row.remaining < 0)

  /** Saved meetings as the save routine needs them: ids, and a shape to diff. */
  const savedIds = useMemo(
    () => saved.map((meeting) => meeting.id).filter((id): id is number => id !== null),
    [saved],
  )

  const changedIds = useMemo(() => {
    const snapshot = new Map<string, string>()
    for (const meeting of saved) {
      if (meeting.id === null) continue
      snapshot.set(meeting.key, JSON.stringify(serialise(meeting)))
    }

    const changed = new Set<number>()
    for (const meeting of meetings) {
      if (meeting.id === null) continue
      const before = snapshot.get(`saved-${meeting.id}`)
      if (before !== undefined && before !== JSON.stringify(serialise(meeting))) changed.add(meeting.id)
    }

    return changed
  }, [meetings, saved])

  function addMeeting() {
    const first = hours.find((row) => row.remaining > 0) ?? hours[0]
    if (!first) {
      setError('This section has no subjects assigned, so there is nothing to schedule.')
      return
    }

    tempKey += 1
    setMeetings((current) => [
      ...current,
      {
        key: `new-${tempKey}`,
        id: null,
        subject_id: first.subject_id,
        session_type: first.session_type,
        day_of_week: 1,
        start_time: '08:00',
        end_time: '09:00',
        room_id: null,
        faculty_id: null,
      },
    ])
  }

  function updateMeeting(key: string, patch: Partial<Meeting>) {
    setMeetings((current) =>
      current.map((meeting) => {
        if (meeting.key !== key) return meeting

        const next = { ...meeting, ...patch }

        // A subject/type pair has to stay one of the section's budgets, so
        // changing the subject re-points the type at whatever that subject runs.
        if (patch.subject_id !== undefined) {
          const types = typesForSubject(patch.subject_id)
          if (!types.includes(next.session_type)) {
            next.session_type = types[0] ?? next.session_type
          }
        }

        return next
      }),
    )
  }

  function removeMeeting(key: string) {
    setMeetings((current) => current.filter((meeting) => meeting.key !== key))
  }

  /** Local shape checks, so an obviously incomplete row never reaches the API. */
  function localProblems(list: Meeting[]): string | null {
    for (const meeting of list) {
      const label =
        hours.find((row) => row.subject_id === meeting.subject_id)?.code ?? 'A meeting'

      if (meeting.room_id === null) return `${label}: choose a room.`
      if (meeting.faculty_id === null) return `${label}: choose a faculty member.`
      if (!meeting.start_time || !meeting.end_time) {
        return `${label}: a start and an end time are required.`
      }

      const minutes = minutesBetween(meeting.start_time, meeting.end_time)
      if (minutes <= 0) return `${label}: the end time must be later than the start time.`
      if (minutes % 30 !== 0) return `${label}: a meeting must last a whole number of half hours.`
    }

    return null
  }

  async function send(method: string, path: string, body: unknown) {
    const response = await fetch(`${API_BASE_URL}${path}`, {
      method,
      headers,
      body: JSON.stringify(body),
    })

    if (!response.ok) {
      const data = await response.json().catch(() => ({}))
      throw new Error(describeFailure(data))
    }

    return response.json().catch(() => ({}))
  }

  async function save() {
    const problem = localProblems(meetings)
    if (problem) {
      setError(problem)
      return
    }

    setSaving(true)
    setError('')
    setNotice('')

    // Removals go first. Splitting one generated block into several meetings
    // means replacing a row that still holds the subject's whole hour budget,
    // and a create sent before that removal would be refused for exceeding it.
    const keptIds = new Set(
      meetings.map((meeting) => meeting.id).filter((id): id is number => id !== null),
    )
    const deleted = savedIds.filter((id) => !keptIds.has(id))
    const updated = meetings.filter((meeting) => meeting.id !== null && changedIds.has(meeting.id))
    const created = meetings.filter((meeting) => meeting.id === null)

    try {
      if (deleted.length > 0) {
        await send('DELETE', `/schedules/${scheduleId}/sessions`, { session_ids: deleted })
      }

      if (updated.length > 0) {
        await send('PUT', `/schedules/${scheduleId}/sessions`, {
          sessions: updated.map((meeting) => ({ id: meeting.id, ...serialise(meeting) })),
        })
      }

      if (created.length > 0) {
        await send('POST', `/schedules/${scheduleId}/sessions`, {
          sessions: created.map(serialise),
        })
      }

      await reload()

      setNotice(
        created.length + updated.length + deleted.length === 0
          ? 'Nothing had changed.'
          : 'Schedule saved.',
      )
    } catch (err) {
      setError(err instanceof Error ? err.message : 'The schedule could not be saved.')
      // Each request is atomic on the server, but a three-step save can still
      // stop half way. Re-reading shows what is actually stored, instead of
      // leaving a refused change on screen looking as though it took effect.
      await reload()
    } finally {
      setSaving(false)
    }
  }

  if (loading) {
    return (
      <div className="min-h-screen bg-gray-100 p-8">
        <div className="mx-auto max-w-5xl">
          <p className="text-gray-500">Loading schedule sessions…</p>
        </div>
      </div>
    )
  }

  return (
    <div className="min-h-screen bg-gray-100 p-8">
      <div className="mx-auto max-w-5xl">
        <div className="mb-6 flex items-start justify-between gap-4">
          <div>
            <p className="text-[11px] font-semibold uppercase tracking-widest text-[#0a2f9c]">
              Schedule Sessions
            </p>
            <h1 className="text-2xl font-bold text-gray-800">
              {schedule?.section
                ? `${schedule.section.name} — Year ${schedule.section.year_level} (${schedule.section.semester_name})`
                : `Schedule #${scheduleId}`}
            </h1>
            <p className="mt-1 text-sm text-gray-500">
              Split a subject across as many meetings as it needs. Lecture and laboratory hours are counted
              separately, against what the subject requires.
            </p>
          </div>
          <Link to="/dashboard" className="btn-navy-outline whitespace-nowrap">
            ← Back to Dashboard
          </Link>
        </div>

        {schedule && !editable && (
          <div className="mb-6 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
            This schedule is <strong>{schedule.status}</strong>. Only a draft can be edited — unpublish or
            return it to draft first. The meetings below are shown read-only.
          </div>
        )}

        {error && (
          <div className="mb-6 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
            {error}
          </div>
        )}

        {notice && !error && (
          <div className="mb-6 rounded-md border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700">
            {notice}
          </div>
        )}

        {/* Hours: required, scheduled and remaining, per subject and type. */}
        <div className="mb-6 overflow-hidden rounded-lg border border-gray-200 bg-white shadow-md">
          <div className="border-b-4 border-[#ffc000] bg-[#0a2f9c] px-6 py-4">
            <h2 className="text-sm font-semibold uppercase tracking-widest text-white">Required Hours</h2>
            <p className="mt-1 text-xs text-blue-100">
              Lecture and laboratory are separate budgets, exactly as the scheduler treats them.
            </p>
          </div>

          <div className="p-6">
            {liveHours.length === 0 ? (
              <p className="text-sm text-gray-500">
                This section has no subjects assigned yet, so there are no hours to place.
              </p>
            ) : (
              <table className="w-full text-sm">
                <thead>
                  <tr className="border-b text-left text-gray-500">
                    <th className="py-1 pr-2">Subject</th>
                    <th className="px-2 py-1">Type</th>
                    <th className="px-2 py-1 text-center">Required</th>
                    <th className="px-2 py-1 text-center">Scheduled</th>
                    <th className="px-2 py-1 text-center">Remaining</th>
                  </tr>
                </thead>
                <tbody>
                  {liveHours.map((row) => (
                    <tr key={budgetKey(row.subject_id, row.session_type)} className="border-b border-gray-100">
                      <td className="py-1 pr-2 font-medium text-gray-800">
                        {row.code}
                        <span className="ml-2 font-normal text-gray-400">{row.title}</span>
                      </td>
                      <td className="px-2 py-1">{TYPE_LABELS[row.session_type]}</td>
                      <td className="px-2 py-1 text-center">{row.required}h</td>
                      <td className="px-2 py-1 text-center">{row.scheduled}h</td>
                      <td
                        className={`px-2 py-1 text-center font-medium ${
                          row.remaining < 0
                            ? 'text-red-600'
                            : row.remaining === 0
                              ? 'text-green-600'
                              : 'text-amber-600'
                        }`}
                      >
                        {row.remaining < 0 ? `${Math.abs(row.remaining)}h over` : `${row.remaining}h`}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            )}

            {overs.length > 0 && (
              <p className="mt-4 rounded border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-700">
                {overs.map((row) => `${row.code} ${row.session_type}`).join(', ')} exceed the hours the subject
                requires. Saving will be refused until that is reduced.
              </p>
            )}

            {shortfalls.length > 0 && (
              <p className="mt-4 rounded border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800">
                Still to place:{' '}
                {shortfalls.map((row) => `${row.code} ${row.session_type} ${row.remaining}h`).join(', ')}. A
                partly-scheduled draft is allowed and can be finished later.
              </p>
            )}

            {warnings.length > 0 && (
              <ul className="mt-4 space-y-1 rounded border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800">
                {warnings.map((warning) => (
                  <li key={warning}>{warning}</li>
                ))}
              </ul>
            )}
          </div>
        </div>

        {/* The meetings themselves. */}
        <div className="rounded-lg border border-gray-200 bg-white p-6 shadow-md">
          <div className="mb-4 flex items-center justify-between">
            <h2 className="text-lg font-semibold text-gray-800">Meetings</h2>
            {editable && (
              <button
                onClick={addMeeting}
                className="rounded-md bg-[#0a2f9c] px-4 py-2 text-sm font-semibold text-white transition hover:bg-[#08227a]"
              >
                + Add Meeting
              </button>
            )}
          </div>

          {meetings.length === 0 ? (
            <p className="text-sm text-gray-500">
              No meetings yet. {editable ? 'Add one to start building the timetable.' : ''}
            </p>
          ) : (
            <div className="space-y-3">
              {meetings.map((meeting) => {
                const row = hours.find(
                  (candidate) =>
                    candidate.subject_id === meeting.subject_id &&
                    candidate.session_type === meeting.session_type,
                )
                const minutes = minutesBetween(meeting.start_time, meeting.end_time)

                return (
                  <div key={meeting.key} className="rounded-md border border-gray-200 p-3">
                    <div className="grid grid-cols-2 gap-3 md:grid-cols-6">
                      <label className="col-span-2 md:col-span-1">
                        <span className="mb-1 block text-[11px] font-semibold uppercase tracking-wide text-gray-500">
                          Subject
                        </span>
                        <select
                          value={meeting.subject_id}
                          disabled={!editable}
                          onChange={(e) => updateMeeting(meeting.key, { subject_id: Number(e.target.value) })}
                          className="w-full rounded-md border border-gray-300 px-2 py-1.5 text-sm disabled:bg-gray-100"
                        >
                          {subjects.map((subject) => (
                            <option key={subject.id} value={subject.id}>
                              {subject.code}
                            </option>
                          ))}
                        </select>
                      </label>

                      <label>
                        <span className="mb-1 block text-[11px] font-semibold uppercase tracking-wide text-gray-500">
                          Type
                        </span>
                        <select
                          value={meeting.session_type}
                          disabled={!editable}
                          onChange={(e) =>
                            updateMeeting(meeting.key, { session_type: e.target.value as MeetingType })
                          }
                          className="w-full rounded-md border border-gray-300 px-2 py-1.5 text-sm disabled:bg-gray-100"
                        >
                          {typesForSubject(meeting.subject_id).map((type) => (
                            <option key={type} value={type}>
                              {TYPE_LABELS[type]}
                            </option>
                          ))}
                        </select>
                      </label>

                      <label>
                        <span className="mb-1 block text-[11px] font-semibold uppercase tracking-wide text-gray-500">
                          Day
                        </span>
                        <select
                          value={meeting.day_of_week}
                          disabled={!editable}
                          onChange={(e) => updateMeeting(meeting.key, { day_of_week: Number(e.target.value) })}
                          className="w-full rounded-md border border-gray-300 px-2 py-1.5 text-sm disabled:bg-gray-100"
                        >
                          {DAYS.map((day) => (
                            <option key={day.value} value={day.value}>
                              {day.label}
                            </option>
                          ))}
                        </select>
                      </label>

                      <label>
                        <span className="mb-1 block text-[11px] font-semibold uppercase tracking-wide text-gray-500">
                          Start
                        </span>
                        <input
                          type="time"
                          step={HALF_HOUR}
                          value={meeting.start_time}
                          disabled={!editable}
                          onChange={(e) => updateMeeting(meeting.key, { start_time: e.target.value })}
                          className="w-full rounded-md border border-gray-300 px-2 py-1.5 text-sm disabled:bg-gray-100"
                        />
                      </label>

                      <label>
                        <span className="mb-1 block text-[11px] font-semibold uppercase tracking-wide text-gray-500">
                          End
                        </span>
                        <input
                          type="time"
                          step={HALF_HOUR}
                          value={meeting.end_time}
                          disabled={!editable}
                          onChange={(e) => updateMeeting(meeting.key, { end_time: e.target.value })}
                          className="w-full rounded-md border border-gray-300 px-2 py-1.5 text-sm disabled:bg-gray-100"
                        />
                      </label>

                      <div className="flex items-end">
                        <span className="text-xs text-gray-500">
                          {minutes > 0 ? `${hoursLabel(minutes)}h` : '—'}
                        </span>
                      </div>
                    </div>

                    <div className="mt-3 grid grid-cols-1 gap-3 md:grid-cols-3">
                      <label>
                        <span className="mb-1 block text-[11px] font-semibold uppercase tracking-wide text-gray-500">
                          Room
                        </span>
                        <select
                          value={meeting.room_id ?? ''}
                          disabled={!editable}
                          onChange={(e) =>
                            updateMeeting(meeting.key, {
                              room_id: e.target.value ? Number(e.target.value) : null,
                            })
                          }
                          className="w-full rounded-md border border-gray-300 px-2 py-1.5 text-sm disabled:bg-gray-100"
                        >
                          <option value="">— choose a room —</option>
                          {rooms.map((room) => (
                            <option key={room.id} value={room.id}>
                              {room.name} ({room.type})
                            </option>
                          ))}
                        </select>
                      </label>

                      <label>
                        <span className="mb-1 block text-[11px] font-semibold uppercase tracking-wide text-gray-500">
                          Faculty
                        </span>
                        <select
                          value={meeting.faculty_id ?? ''}
                          disabled={!editable}
                          onChange={(e) =>
                            updateMeeting(meeting.key, {
                              faculty_id: e.target.value ? Number(e.target.value) : null,
                            })
                          }
                          className="w-full rounded-md border border-gray-300 px-2 py-1.5 text-sm disabled:bg-gray-100"
                        >
                          <option value="">— choose a faculty member —</option>
                          {faculties.map((faculty) => (
                            <option key={faculty.id} value={faculty.id}>
                              {faculty.name}
                            </option>
                          ))}
                        </select>
                      </label>

                      <div className="flex items-end justify-between gap-2">
                        <p className="text-xs text-gray-500">
                          {formatTimeRange(meeting.start_time, meeting.end_time)}
                          {row ? ` · ${TYPE_LABELS[row.session_type]}` : ''}
                        </p>
                        {editable && (
                          <button
                            onClick={() => removeMeeting(meeting.key)}
                            className="whitespace-nowrap text-sm text-red-600 hover:underline"
                          >
                            Remove
                          </button>
                        )}
                      </div>
                    </div>
                  </div>
                )
              })}
            </div>
          )}

          {editable && (
            <>
              <div className="mt-6 flex justify-end gap-2">
                <button
                  onClick={() => reload()}
                  disabled={saving}
                  className="rounded-md border border-gray-300 px-4 py-2 text-sm transition hover:bg-gray-50 disabled:opacity-50"
                >
                  Discard changes
                </button>
                <button
                  onClick={() => save()}
                  disabled={saving}
                  className="rounded-md bg-[#0a2f9c] px-5 py-2 text-sm font-semibold text-white transition hover:bg-[#08227a] disabled:opacity-50"
                >
                  {saving ? 'Saving…' : 'Save Schedule'}
                </button>
              </div>

              <p className="mt-3 text-right text-xs text-gray-400">
                Removals are saved first, then edits, then new meetings — so splitting a generated block into
                shorter meetings is never refused for holding too many hours.
              </p>
            </>
          )}
        </div>
      </div>
    </div>
  )
}
