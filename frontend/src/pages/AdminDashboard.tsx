import { useEffect, useState } from 'react'
import { useAuth, API_BASE_URL } from '../context/AuthContext'

type Faculty = {
  id: number
  faculty_type: string
  max_teaching_load: number
  user?: { name: string; email: string }
  subjects?: { code: string; title: string }[]
}

type Schedule = {
  id: number
  section_id: number
  status: 'draft' | 'approved' | 'published'
  generated_at: string
  sessions?: { id: number; subject?: { code: string }; day_of_week: number; start_time: string; end_time: string }[]
}

export default function AdminDashboard() {
  const { user, token, logout } = useAuth()
  const [faculties, setFaculties] = useState<Faculty[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [generating, setGenerating] = useState(false)
  const [generateResult, setGenerateResult] = useState('')

  // Schedule management state
  const [schedules, setSchedules] = useState<Schedule[]>([])
  const [schedulesLoading, setSchedulesLoading] = useState(false)

  // Shared headers
  function headers() {
    return {
      Authorization: `Bearer ${token}`,
      'Accept': 'application/json',
      'Content-Type': 'application/json',
    }
  }

  useEffect(() => {
    async function fetchFaculties() {
      try {
        const response = await fetch(`${API_BASE_URL}/faculties`, { headers: headers() })
        if (!response.ok) throw new Error('Failed to load faculty list')
        const data = await response.json()
        setFaculties(Array.isArray(data) ? data : [])
      } catch (err) {
        setError(err instanceof Error ? err.message : 'Something went wrong')
      } finally {
        setLoading(false)
      }
    }
    fetchFaculties()
    fetchSchedules()
  }, [token])

  async function fetchSchedules() {
    setSchedulesLoading(true)
    try {
      const response = await fetch(`${API_BASE_URL}/schedules`, { headers: headers() })
      if (response.ok) {
        const data = await response.json()
        setSchedules(Array.isArray(data) ? data : [])
      }
    } catch {
      // Schedules endpoint might not exist yet — that's okay
    } finally {
      setSchedulesLoading(false)
    }
  }

  async function handleGenerate(sectionId: number) {
    setGenerating(true)
    setGenerateResult('')
    try {
      const response = await fetch(`${API_BASE_URL}/schedules/generate/${sectionId}`, {
        method: 'POST',
        headers: headers(),
      })
      const data = await response.json()
      if (!response.ok) throw new Error(data.message || 'Generation failed')
      setGenerateResult(`✅ Status: ${data.status} — ${data.sessions?.length ?? 0} sessions created.`)
      fetchSchedules() // Refresh schedule list
    } catch (err) {
      setGenerateResult(err instanceof Error ? `❌ Error: ${err.message}` : 'Something went wrong')
    } finally {
      setGenerating(false)
    }
  }

  async function handleApprove(scheduleId: number) {
    try {
      const response = await fetch(`${API_BASE_URL}/schedules/${scheduleId}/approve`, {
        method: 'PATCH',
        headers: headers(),
      })
      const data = await response.json()
      if (!response.ok) throw new Error(data.message || 'Approval failed')
      fetchSchedules() // Refresh
    } catch (err) {
      alert(err instanceof Error ? err.message : 'Approval failed')
    }
  }

  async function handlePublish(scheduleId: number) {
    try {
      const response = await fetch(`${API_BASE_URL}/schedules/${scheduleId}/publish`, {
        method: 'PATCH',
        headers: headers(),
      })
      const data = await response.json()
      if (!response.ok) throw new Error(data.message || 'Publish failed')
      fetchSchedules() // Refresh
    } catch (err) {
      alert(err instanceof Error ? err.message : 'Publish failed')
    }
  }

  const dayNames = ['', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday']

  function formatTime(t: string) {
    return t?.substring(0, 5) ?? ''
  }

  return (
    <div className="min-h-screen bg-gray-100 p-8">
      <div className="max-w-5xl mx-auto">
        <div className="flex justify-between items-center mb-6">
          <div>
            <h1 className="text-2xl font-bold text-gray-800">Welcome, {user?.name}</h1>
            <p className="text-gray-500">Admin Dashboard</p>
          </div>
          <button onClick={logout} className="bg-gray-800 text-white px-4 py-2 rounded-md hover:bg-gray-700 transition">
            Log out
          </button>
        </div>

        {/* Navigation Links */}
        <div className="grid grid-cols-2 md:grid-cols-5 gap-3 mb-6">
          <a href="/admin/users" className="bg-green-600 text-white px-4 py-3 rounded-md hover:bg-green-700 transition text-center font-medium">👤 Users</a>
          <a href="/admin/faculty" className="bg-purple-600 text-white px-4 py-3 rounded-md hover:bg-purple-700 transition text-center font-medium">🎓 Faculty</a>
          <a href="/admin/subjects" className="bg-orange-600 text-white px-4 py-3 rounded-md hover:bg-orange-700 transition text-center font-medium">📚 Subjects</a>
          <a href="/admin/rooms" className="bg-blue-600 text-white px-4 py-3 rounded-md hover:bg-blue-700 transition text-center font-medium">🏫 Rooms</a>
          <a href="/admin/sections" className="bg-teal-600 text-white px-4 py-3 rounded-md hover:bg-teal-700 transition text-center font-medium">📋 Sections</a>
          <a href="/admin/reports" className="bg-red-600 text-white px-4 py-3 rounded-md hover:bg-red-700 transition text-center font-medium">📈 Reports</a>
        </div>

        {/* Generate Schedule */}
        <div className="bg-white rounded-lg shadow-md p-6 mb-6">
          <h2 className="text-lg font-semibold text-gray-800 mb-3">Generate Schedule</h2>
          <p className="text-gray-500 text-sm mb-3">Section: BSIT 1A (id: 1)</p>
          <button
            onClick={() => handleGenerate(1)}
            disabled={generating}
            className="bg-blue-600 text-white px-4 py-2 rounded-md hover:bg-blue-700 transition disabled:opacity-50"
          >
            {generating ? 'Generating...' : 'Generate Schedule'}
          </button>
          {generateResult && (
            <p className={`mt-3 text-sm ${generateResult.startsWith('❌') ? 'text-red-600' : 'text-green-600'}`}>
              {generateResult}
            </p>
          )}
        </div>

        {/* ===== NEW: Schedule Review & Approval ===== */}
        <div className="bg-white rounded-lg shadow-md p-6 mb-6">
          <h2 className="text-lg font-semibold text-gray-800 mb-4">Schedule Review & Approval</h2>

          {schedulesLoading && <p className="text-gray-500">Loading schedules...</p>}

          {!schedulesLoading && schedules.length === 0 && (
            <p className="text-gray-400 text-sm">No schedules found. Click "Generate Schedule" above to create one.</p>
          )}

          {!schedulesLoading && schedules.length > 0 && (
            <div className="space-y-4">
              {schedules.map((schedule) => (
                <div key={schedule.id} className="border border-gray-200 rounded-lg p-4">
                  <div className="flex justify-between items-center mb-3">
                    <div>
                      <span className="font-semibold">Schedule #{schedule.id}</span>
                      <span className="text-gray-500 text-sm ml-2">Section #{schedule.section_id}</span>
                      <span className={`ml-3 px-2 py-0.5 rounded text-xs font-medium ${
                        schedule.status === 'published' ? 'bg-green-100 text-green-700' :
                        schedule.status === 'approved' ? 'bg-blue-100 text-blue-700' :
                        'bg-yellow-100 text-yellow-700'
                      }`}>
                        {schedule.status}
                      </span>
                    </div>
                    <div className="flex gap-2">
                      {schedule.status === 'draft' && (
                        <button
                          onClick={() => handleApprove(schedule.id)}
                          className="bg-blue-600 text-white px-3 py-1.5 rounded text-sm hover:bg-blue-700 transition"
                        >
                          Approve
                        </button>
                      )}
                      {schedule.status === 'approved' && (
                        <button
                          onClick={() => handlePublish(schedule.id)}
                          className="bg-green-600 text-white px-3 py-1.5 rounded text-sm hover:bg-green-700 transition"
                        >
                          Publish
                        </button>
                      )}
                      {schedule.status === 'published' && (
                        <span className="text-green-600 text-sm font-medium">✅ Faculty can see this</span>
                      )}
                    </div>
                  </div>

                  {/* Sessions table */}
                  {schedule.sessions && schedule.sessions.length > 0 && (
                    <table className="w-full text-sm">
                      <thead>
                        <tr className="text-gray-500 border-b">
                          <th className="py-1 pr-2 text-left">Subject</th>
                          <th className="py-1 px-2 text-left">Day</th>
                          <th className="py-1 px-2 text-left">Time</th>
                        </tr>
                      </thead>
                      <tbody>
                        {schedule.sessions.map((session) => (
                          <tr key={session.id} className="border-b border-gray-100">
                            <td className="py-1 pr-2">{session.subject?.code ?? '—'}</td>
                            <td className="py-1 px-2">{dayNames[session.day_of_week] ?? '—'}</td>
                            <td className="py-1 px-2">{formatTime(session.start_time)}–{formatTime(session.end_time)}</td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  )}
                </div>
              ))}
            </div>
          )}
        </div>

        {/* Faculty Table */}
        <div className="bg-white rounded-lg shadow-md overflow-hidden">
          <h2 className="text-lg font-semibold text-gray-800 p-4 border-b border-gray-200">Faculty</h2>
          {loading && <p className="text-gray-500 p-4">Loading faculty...</p>}
          {error && <p className="text-red-600 p-4">{error}</p>}
          {!loading && !error && (
            <table className="w-full text-left">
              <thead className="bg-gray-800 text-white">
                <tr>
                  <th className="p-3">Name</th>
                  <th className="p-3">Type</th>
                  <th className="p-3">Max Load</th>
                  <th className="p-3">Subjects</th>
                </tr>
              </thead>
              <tbody>
                {faculties.map((f) => (
                  <tr key={f.id} className="border-t border-gray-200">
                    <td className="p-3">{f.user?.name ?? `Faculty #${f.id}`}</td>
                    <td className="p-3 capitalize">{f.faculty_type}</td>
                    <td className="p-3">{f.max_teaching_load}h</td>
                    <td className="p-3">{f.subjects?.map((s) => s.code).join(', ') ?? '—'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </div>
      </div>
    </div>
  )
}
