import { useEffect, useState } from 'react'
import { useAuth, API_BASE_URL } from '../context/AuthContext'
import { formatTime12h, formatTimeRange } from '../utils/time'

type Session = {
  id: number
  subject?: { code: string; title: string }
  faculty?: { id: number; name: string }
  room?: { id: number; name: string }
  session_type?: string
  day_of_week: number
  start_time: string
  end_time: string
}

type Schedule = {
  id: number
  section_id: number
  status: string
  section?: { id: number; name: string; year_level: number; semester_name: string; academic_year?: string }
  sessions?: Session[]
}

const DAY_LABELS: Record<number, string> = {
  1: 'Monday', 2: 'Tuesday', 3: 'Wednesday', 4: 'Thursday',
  5: 'Friday', 6: 'Saturday', 7: 'Sunday',
}

/** The normal teaching week. Extra days in the data get their own column. */
const BASE_DAYS = [1, 2, 3, 4, 5, 6].map((val) => ({ val, label: DAY_LABELS[val] }))

/** The usual teaching hours. Extra hours in the data get their own row. */
const BASE_SLOTS = [
  '07:00', '08:00', '09:00', '10:00', '11:00', '12:00',
  '13:00', '14:00', '15:00', '16:00', '17:00', '18:00', '19:00', '20:00',
]

/**
 * The hour a session belongs to: `09:30:00` → `09:00`. Sessions are bucketed
 * this way rather than matched exactly, so a manually edited start on the half
 * hour still appears in the grid (it shows its true range inside the cell).
 */
function hourBucket(time: string): string {
  return `${time.substring(0, 2)}:00`
}

export default function PrintableSchedule() {
  const { token } = useAuth()
  const [schedule, setSchedule] = useState<Schedule | null>(null)
  const [error, setError] = useState('')

  useEffect(() => {
    // ?schedule=ID  (query param keeps this page linkable from the dashboard)
    const params = new URLSearchParams(window.location.search)
    const id = params.get('schedule')
    if (!id) { setError('No schedule specified.'); return }

    fetch(`${API_BASE_URL}/schedules/${id}`, {
      headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' },
    })
      .then((r) => { if (!r.ok) throw new Error('Failed to load schedule'); return r.json() })
      .then(setSchedule)
      .catch((e) => setError(e instanceof Error ? e.message : 'Failed to load schedule'))
  }, [token])

  if (error) {
    return <div className="p-8 text-red-600">{error} <a href="/dashboard" className="text-blue-600 underline ml-2">Back to Dashboard</a></div>
  }
  if (!schedule) {
    return <div className="p-8 text-gray-500">Loading schedule...</div>
  }

  const sessions = schedule.sessions ?? []

  // Days, hours and cells are all derived from the sessions themselves, so a
  // session can never fall outside the printed grid: a Sunday class, an early
  // morning or a late evening slot gets its own column or row instead of
  // silently disappearing from the handout.
  const grid = new Map<string, Session[]>()
  for (const s of sessions) {
    const key = `${s.day_of_week}-${hourBucket(s.start_time)}`
    if (!grid.has(key)) grid.set(key, [])
    grid.get(key)!.push(s)
  }

  const days = [...BASE_DAYS]
  for (const val of new Set(sessions.map((s) => s.day_of_week))) {
    if (!days.some((d) => d.val === val)) {
      days.push({ val, label: DAY_LABELS[val] ?? `Day ${val}` })
    }
  }
  days.sort((a, b) => a.val - b.val)

  const slots = [...BASE_SLOTS]
  for (const slot of new Set(sessions.map((s) => hourBucket(s.start_time)))) {
    if (!slots.includes(slot)) slots.push(slot)
  }
  slots.sort()

  const section = schedule.section

  return (
    <div className="print-area bg-white min-h-screen p-6">
      {/* Screen-only controls — hidden when printing */}
      <div className="no-print flex gap-3 justify-end mb-4 max-w-5xl mx-auto">
        <button onClick={() => window.print()}
          className="bg-blue-600 text-white px-4 py-2 rounded-md hover:bg-blue-700 transition">
          🖨 Print / Save as PDF
        </button>
        <a href="/dashboard"
          className="border border-gray-300 px-4 py-2 rounded-md hover:bg-gray-100 transition">
          ← Back to Dashboard
        </a>
      </div>

      {/* Header */}
      <div className="max-w-5xl mx-auto mb-4 text-center">
        <h1 className="text-lg font-bold uppercase">IT Department</h1>
        <h2 className="text-base font-semibold">Class Schedule</h2>
        <p className="text-sm">
          {section ? `${section.name} — Year ${section.year_level}` : `Schedule #${schedule.id}`}
          {section?.semester_name ? ` · ${section.semester_name}` : ''}
          {section?.academic_year ? ` · A.Y. ${section.academic_year}` : ''}
        </p>
        <p className="text-xs text-gray-500">Status: {schedule.status.toUpperCase()}</p>
      </div>

      {/* Weekly grid */}
      <table className="schedule-grid w-full max-w-5xl mx-auto border-collapse text-[11px]">
        <thead>
          <tr>
            <th className="border border-gray-400 bg-gray-100 p-1 w-20">Time</th>
            {days.map((d) => (
              <th key={d.val} className="border border-gray-400 bg-gray-100 p-1">{d.label}</th>
            ))}
          </tr>
        </thead>
        <tbody>
          {slots.map((slot) => {
            const rowHasContent = days.some((d) => grid.has(`${d.val}-${slot}`))
            return (
              <tr key={slot} className={rowHasContent ? '' : 'empty-row'}>
                <td className="border border-gray-400 p-1 text-center font-medium">{formatTime12h(slot)}</td>
                {days.map((d) => {
                  const cell = grid.get(`${d.val}-${slot}`) ?? []
                  return (
                    <td key={d.val} className="border border-gray-400 p-1 align-top">
                      {cell.map((s) => (
                        <div key={s.id} className="session-cell mb-0.5">
                          <span className="font-semibold">{s.subject?.code}</span>
                          <span className="text-gray-600"> ({s.session_type === 'laboratory' ? 'Lab' : 'Lec'})</span>
                          <br />
                          <span>{s.faculty?.name}</span><br />
                          <span className="text-gray-600">{s.room?.name} · {formatTimeRange(s.start_time, s.end_time)}</span>
                        </div>
                      ))}
                    </td>
                  )
                })}
              </tr>
            )
          })}
        </tbody>
      </table>

      {/* Print CSS */}
      <style>{`
        @media print {
          @page { size: A4 landscape; margin: 12mm; }
          .no-print { display: none !important; }
          body { background: white !important; }
          .empty-row { display: none; }
          .schedule-grid { width: 100% !important; font-size: 10px; }
          .session-cell { break-inside: avoid; }
        }
      `}</style>
    </div>
  )
}
