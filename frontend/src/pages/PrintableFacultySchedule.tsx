import { useEffect, useState } from 'react'
import { useAuth, API_BASE_URL } from '../context/AuthContext'
import { formatTime12h, formatTimeRange } from '../utils/time'
import PrintLetterhead from '../components/PrintLetterhead'

type Session = {
  id: number
  day_of_week: number
  start_time: string
  end_time: string
  session_type: string
  subject_code: string
  subject_title: string
  room: string
  section: string
  section_year_level: number | null
  semester_name: string | null
  academic_year: string | null
  hours: number
}

type FacultySchedule = {
  faculty: {
    id: number
    name: string
    faculty_type: string
    max_teaching_load: number
  }
  sessions: Session[]
  total_hours: number
  hours_per_day: Record<string, number>
  distinct_sections: string[]
}

const DAY_LABELS: Record<number, string> = {
  1: 'Monday', 2: 'Tuesday', 3: 'Wednesday', 4: 'Thursday',
  5: 'Friday', 6: 'Saturday', 7: 'Sunday',
}

/**
 * One faculty member's published teaching schedule, laid out as a weekly grid.
 *
 * The grid is derived from the sessions themselves rather than a fixed
 * timetable, so a class on a Saturday or outside the usual hours still appears
 * instead of being silently dropped from the printout.
 */
export default function PrintableFacultySchedule() {
  const { token } = useAuth()
  const [data, setData] = useState<FacultySchedule | null>(null)
  const [error, setError] = useState('')

  useEffect(() => {
    // ?faculty=ID — query param keeps this page linkable from the Reports tab.
    const params = new URLSearchParams(window.location.search)
    const id = params.get('faculty')
    if (!id) {
      setError('No faculty member specified.')
      return
    }

    fetch(`${API_BASE_URL}/reports/faculty/${id}/schedule`, {
      headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' },
    })
      .then((r) => {
        if (!r.ok) throw new Error('Failed to load the faculty schedule')
        return r.json()
      })
      .then(setData)
      .catch((e) => setError(e instanceof Error ? e.message : 'Failed to load the faculty schedule'))
  }, [token])

  if (error) {
    return (
      <div className="p-8 text-red-600">
        {error}
        <a href="/admin/reports" className="text-blue-600 underline ml-2">Back to Reports</a>
      </div>
    )
  }
  if (!data) {
    return <div className="p-8 text-gray-500">Loading schedule...</div>
  }

  const sessions = data.sessions

  // Days and hour rows are derived from the data, so nothing can fall outside
  // the printed grid.
  const days = [...new Set(sessions.map((s) => s.day_of_week))].sort((a, b) => a - b)
  const hourBucket = (time: string) => `${time.substring(0, 2)}:00`
  const slots = [...new Set(sessions.map((s) => hourBucket(s.start_time)))].sort()

  const grid = new Map<string, Session[]>()
  for (const s of sessions) {
    const key = `${s.day_of_week}-${hourBucket(s.start_time)}`
    if (!grid.has(key)) grid.set(key, [])
    grid.get(key)!.push(s)
  }

  const term = sessions.find((s) => s.semester_name || s.academic_year)

  return (
    <div className="print-area bg-white min-h-screen p-6">
      {/* Screen-only controls — hidden when printing */}
      <div className="no-print flex gap-3 justify-end mb-4 max-w-5xl mx-auto">
        <button onClick={() => window.print()}
          className="bg-blue-600 text-white px-4 py-2 rounded-md hover:bg-blue-700 transition">
          🖨 Print / Save as PDF
        </button>
        <a href="/admin/reports"
          className="btn-navy-outline">
          ← Back to Reports
        </a>
      </div>

      {/* Header — the school letterhead, then the document title */}
      <PrintLetterhead
        title="Faculty Teaching Schedule"
        subtitle={data.faculty.name}
      >
        <p className="text-center text-xs text-gray-600 capitalize">
          {data.faculty.faculty_type.replace('_', ' ')}
          {' · '}{data.total_hours}h of {data.faculty.max_teaching_load}h
        </p>
        <p className="text-center text-xs text-gray-600">
          {data.distinct_sections.join(', ')}
          {term?.semester_name ? ` · ${term.semester_name}` : ''}
          {term?.academic_year ? ` · A.Y. ${term.academic_year}` : ''}
        </p>
      </PrintLetterhead>

      {sessions.length === 0 ? (
        <p className="max-w-5xl mx-auto text-center text-gray-500 py-12">
          No published sessions are assigned to {data.faculty.name} yet.
          A schedule must be published before it can be handed out.
        </p>
      ) : (
        <>
          {/* Weekly grid */}
          <table className="schedule-grid w-full max-w-5xl mx-auto border-collapse text-[11px]">
            <thead>
              <tr>
                <th className="border border-gray-400 bg-gray-100 p-1 w-20">Time</th>
                {days.map((d) => (
                  <th key={d} className="border border-gray-400 bg-gray-100 p-1">
                    {DAY_LABELS[d] ?? `Day ${d}`}
                  </th>
                ))}
              </tr>
            </thead>
            <tbody>
              {slots.map((slot) => (
                <tr key={slot}>
                  <td className="border border-gray-400 p-1 text-center font-medium">
                    {formatTime12h(slot)}
                  </td>
                  {days.map((d) => {
                    const cell = grid.get(`${d}-${slot}`) ?? []
                    return (
                      <td key={d} className="border border-gray-400 p-1 align-top">
                        {cell.map((s) => (
                          <div key={s.id} className="session-cell mb-0.5">
                            <span className="font-semibold">{s.subject_code}</span>
                            <span className="text-gray-600">
                              {' '}({s.session_type === 'laboratory' ? 'Lab' : 'Lec'})
                            </span>
                            <br />
                            <span>{s.section}</span>
                            <br />
                            <span className="text-gray-600">
                              {s.room} · {formatTimeRange(s.start_time, s.end_time)}
                            </span>
                          </div>
                        ))}
                      </td>
                    )
                  })}
                </tr>
              ))}
            </tbody>
          </table>

          {/* Flat list — easier to read than the grid when printed in portrait. 
              Also the only place a room change between two sessions is obvious. */}
          <div className="max-w-5xl mx-auto mt-6">
            <h3 className="text-sm font-semibold mb-2">Weekly Load Summary</h3>
            <table className="w-full text-xs border-collapse">
              <thead>
                <tr className="bg-gray-100">
                  <th className="border border-gray-300 p-1 text-left">Day</th>
                  <th className="border border-gray-300 p-1 text-left">Subject</th>
                  <th className="border border-gray-300 p-1 text-left">Section</th>
                  <th className="border border-gray-300 p-1 text-left">Room</th>
                  <th className="border border-gray-300 p-1 text-left">Time</th>
                  <th className="border border-gray-300 p-1 text-right">Hours</th>
                </tr>
              </thead>
              <tbody>
                {sessions.map((s) => (
                  <tr key={s.id}>
                    <td className="border border-gray-300 p-1">{DAY_LABELS[s.day_of_week] ?? s.subject_code}</td>
                    <td className="border border-gray-300 p-1">{s.subject_code}</td>
                    <td className="border border-gray-300 p-1">{s.section}</td>
                    <td className="border border-gray-300 p-1">{s.room}</td>
                    <td className="border border-gray-300 p-1">
                      {formatTimeRange(s.start_time, s.end_time)}
                    </td>
                    <td className="border border-gray-300 p-1 text-right">{s.hours}</td>
                  </tr>
                ))}
                <tr className="bg-gray-100 font-semibold">
                  <td className="border border-gray-300 p-1" colSpan={5}>Total</td>
                  <td className="border border-gray-300 p-1 text-right">{data.total_hours}h</td>
                </tr>
              </tbody>
            </table>
          </div>
        </>
      )}

      <style>{`
        @media print {
          @page { size: A4 landscape; margin: 12mm; }
          .no-print { display: none !important; }
          body { background: white !important; }
          .schedule-grid { width: 100% !important; font-size: 10px; }
          .session-cell { break-inside: avoid; }
        }
      `}</style>
    </div>
  )
}