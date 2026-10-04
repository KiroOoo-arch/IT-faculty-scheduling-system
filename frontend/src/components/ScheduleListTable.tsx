import { formatTimeRange } from '../utils/time'

type ListSession = {
  id: number
  day_of_week: number
  start_time: string
  end_time: string
  session_type?: string
  subject?: { id?: number; code: string; title: string; lecture_hours?: number; lab_hours?: number }
  faculty?: { id: number; name: string }
  room?: { id: number; name: string }
}

/** Short day marks, as used on printed timetables: M T W TH F S. */
const SHORT_DAYS: Record<number, string> = {
  1: 'M', 2: 'T', 3: 'W', 4: 'TH', 5: 'F', 6: 'S', 7: 'SU',
}

/**
 * A subject-by-subject timetable, the way class schedules are handed out.
 *
 * Unlike the weekly grid, one subject is one row: its meetings stack as
 * separate lines in the Time and Days columns, so a subject that meets twice a
 * week reads as a single entry instead of two scattered cells. The Instructor
 * column is the addition over the usual printed form — the grid could imply it,
 * but a handout should name who teaches the class.
 *
 * The column is "Contact Hrs", not the "Units" a printed form usually carries:
 * the subjects table stores lecture_hours and lab_hours and has no unit value,
 * so this prints the recorded contact hours rather than an invented unit count.
 * It is a property of the subject (what it requires), so it will not always
 * equal the length of the meeting shown beside it.
 */
export default function ScheduleListTable({ sessions }: { sessions: ListSession[] }) {
  // Group meetings by subject so each subject becomes one row.
  const groups = new Map<string, ListSession[]>()
  for (const s of sessions) {
    const key = s.subject?.code ?? `subject-${s.id}`
    if (!groups.has(key)) groups.set(key, [])
    groups.get(key)!.push(s)
  }

  const rows = [...groups.entries()]
    .map(([code, meetings]) => {
      // Chronological within the row: Monday first, earliest start first.
      const ordered = [...meetings].sort(
        (a, b) => a.day_of_week - b.day_of_week || a.start_time.localeCompare(b.start_time),
      )
      const subject = ordered[0].subject
      const hours = (subject?.lecture_hours ?? 0) + (subject?.lab_hours ?? 0)
      // A subject can sit in more than one room across its meetings, so the
      // room is read per meeting line rather than once for the whole row.
      const room = [...new Set(ordered.map((s) => s.room?.name).filter(Boolean))].join(', ')
      const instructor = [...new Set(ordered.map((s) => s.faculty?.name).filter(Boolean))].join(', ')
      const hasLab = ordered.some((s) => s.session_type === 'laboratory')
      return { code, ordered, title: subject?.title ?? '—', hours, room: room || '—', instructor: instructor || '—', hasLab }
    })
    .sort((a, b) => a.code.localeCompare(b.code))

  return (
    <table className="schedule-list w-full max-w-5xl mx-auto border-collapse text-[11px]">
      <thead>
        <tr className="bg-[#0a2f9c] text-white">
          <th className="border border-[#0a2f9c] p-1.5 text-left">Subject</th>
          <th className="border border-[#0a2f9c] p-1.5 text-left">Description</th>
          <th className="border border-[#0a2f9c] p-1.5 text-center w-20">Contact Hrs</th>
          <th className="border border-[#0a2f9c] p-1.5 text-left w-40">Time</th>
          <th className="border border-[#0a2f9c] p-1.5 text-left w-14">Days</th>
          <th className="border border-[#0a2f9c] p-1.5 text-left w-24">Room</th>
          <th className="border border-[#0a2f9c] p-1.5 text-left w-36">Instructor</th>
        </tr>
      </thead>
      <tbody>
        {rows.map((row) => (
          <tr key={row.code} className="schedule-list-row align-top">
            <td className="border border-gray-400 p-1.5 font-semibold whitespace-nowrap">
              {row.code}
              {row.hasLab && <span className="ml-1 font-normal text-gray-500">(Lab)</span>}
            </td>
            <td className="border border-gray-400 p-1.5">{row.title}</td>
            <td className="border border-gray-400 p-1.5 text-center">{row.hours}</td>
            <td className="border border-gray-400 p-1.5 whitespace-nowrap">
              {row.ordered.map((s) => (
                <div key={s.id}>{formatTimeRange(s.start_time, s.end_time)}</div>
              ))}
            </td>
            <td className="border border-gray-400 p-1.5">
              {row.ordered.map((s) => (
                <div key={s.id}>{SHORT_DAYS[s.day_of_week] ?? `D${s.day_of_week}`}</div>
              ))}
            </td>
            <td className="border border-gray-400 p-1.5">{row.room}</td>
            <td className="border border-gray-400 p-1.5">{row.instructor}</td>
          </tr>
        ))}
        {rows.length === 0 && (
          <tr>
            <td colSpan={7} className="border border-gray-400 p-3 text-center text-gray-500">
              No sessions have been scheduled yet.
            </td>
          </tr>
        )}
      </tbody>
    </table>
  )
}
