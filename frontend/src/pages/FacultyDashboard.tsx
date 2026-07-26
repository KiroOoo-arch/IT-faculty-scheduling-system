import { useEffect, useState } from 'react'
import { useAuth, API_BASE_URL } from '../context/AuthContext'

type Session = {
  id: number
  subject: { code: string; title: string }
  room: { name: string }
  session_type: string
  day_of_week: number
  start_time: string
  end_time: string
}

const DAYS = ['', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday']

export default function FacultyDashboard() {
  const { user, token, logout } = useAuth()
  const [sessions, setSessions] = useState<Session[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')

  useEffect(() => {
    async function fetchSchedule() {
      try {
        const response = await fetch(`${API_BASE_URL}/my-schedule`, {
          headers: { Authorization: `Bearer ${token}` },
        })
        if (!response.ok) throw new Error('Failed to load schedule')
        const data = await response.json()
        setSessions(data.sessions)
      } catch (err) {
        setError(err instanceof Error ? err.message : 'Something went wrong')
      } finally {
        setLoading(false)
      }
    }
    fetchSchedule()
  }, [token])

  return (
    <div className="min-h-screen bg-gray-100 p-8">
      <div className="max-w-3xl mx-auto">
        <div className="flex justify-between items-center mb-6">
          <div>
            <h1 className="text-2xl font-bold text-gray-800">Welcome, {user?.name}</h1>
            <p className="text-gray-500">Your Schedule</p>
          </div>
          <button
            onClick={logout}
            className="bg-gray-800 text-white px-4 py-2 rounded-md hover:bg-gray-700 transition"
          >
            Log out
          </button>
        </div>

        {loading && <p className="text-gray-500">Loading schedule...</p>}
        {error && <p className="text-red-600">{error}</p>}

        {!loading && !error && sessions.length === 0 && (
          <p className="text-gray-500">No sessions scheduled yet.</p>
        )}

        {!loading && sessions.length > 0 && (
          <div className="bg-white rounded-lg shadow-md overflow-hidden">
            <table className="w-full text-left">
              <thead className="bg-gray-800 text-white">
                <tr>
                  <th className="p-3">Subject</th>
                  <th className="p-3">Type</th>
                  <th className="p-3">Day</th>
                  <th className="p-3">Time</th>
                  <th className="p-3">Room</th>
                </tr>
              </thead>
              <tbody>
                {sessions.map((s) => (
                  <tr key={s.id} className="border-t border-gray-200">
                    <td className="p-3">{s.subject.code} — {s.subject.title}</td>
                    <td className="p-3 capitalize">{s.session_type}</td>
                    <td className="p-3">{DAYS[s.day_of_week]}</td>
                    <td className="p-3">{s.start_time.slice(0, 5)}–{s.end_time.slice(0, 5)}</td>
                    <td className="p-3">{s.room.name}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>
    </div>
  )
}