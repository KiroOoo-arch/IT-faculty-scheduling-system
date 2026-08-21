import { useEffect, useState } from 'react'
import { useAuth, API_BASE_URL } from '../../context/AuthContext'

type FacultyWorkload = {
  faculty_id: number
  name: string
  faculty_type: string
  assigned_hours: number
  max_teaching_load: number
  utilization_percent: number | null
}

type RoomUtil = {
  room_id: number
  name: string
  type: string
  capacity: number
  booked_hours_per_week: number
}

type ConflictLog = {
  id: number
  section: string
  status: string
  message: string
  unscheduled_sessions: any | null
  requested_by: string
  created_at: string
}

type ScheduleStatusItem = {
  status: string
  count: number
}

type SectionSummaryItem = {
  section_id: number
  name: string
  year_level: number
  academic_year: string
  semester_name: string
  total_sessions: number
  total_hours: number
  faculty_count: number
  subjects_count: number
}

type Tab = 'overview' | 'faculty' | 'rooms' | 'sections' | 'conflicts'

export default function ReportsPage() {
  const { token, logout } = useAuth()
  const [activeTab, setActiveTab] = useState<Tab>('overview')
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')

  // Data states
  const [scheduleStatus, setScheduleStatus] = useState<ScheduleStatusItem[]>([])
  const [totalSchedules, setTotalSchedules] = useState(0)
  const [facultyWorkload, setFacultyWorkload] = useState<FacultyWorkload[]>([])
  const [rooms, setRooms] = useState<RoomUtil[]>([])
  const [sectionSummary, setSectionSummary] = useState<SectionSummaryItem[]>([])
  const [conflicts, setConflicts] = useState<ConflictLog[]>([])

  function headers() {
    return {
      Authorization: `Bearer ${token}`,
      Accept: 'application/json',
    }
  }

  useEffect(() => {
    async function fetchAll() {
      try {
        const [statusRes, facRes, roomRes, secRes, conRes] = await Promise.all([
          fetch(`${API_BASE_URL}/reports/schedule-status`, { headers: headers() }),
          fetch(`${API_BASE_URL}/reports/faculty-workload`, { headers: headers() }),
          fetch(`${API_BASE_URL}/reports/room-utilization`, { headers: headers() }),
          fetch(`${API_BASE_URL}/reports/section-summary`, { headers: headers() }),
          fetch(`${API_BASE_URL}/reports/conflicts`, { headers: headers() }),
        ])

        if (statusRes.ok) {
          const data = await statusRes.json()
          setScheduleStatus(data.statuses || [])
          setTotalSchedules(data.total || 0)
        }
        if (facRes.ok) {
          const data = await facRes.json()
          setFacultyWorkload(Array.isArray(data) ? data : [])
        }
        if (roomRes.ok) {
          const data = await roomRes.json()
          setRooms(Array.isArray(data) ? data : [])
        }
        if (secRes.ok) {
          const data = await secRes.json()
          setSectionSummary(Array.isArray(data) ? data : [])
        }
        if (conRes.ok) {
          const data = await conRes.json()
          setConflicts(Array.isArray(data) ? data : [])
        }
      } catch (err) {
        setError(err instanceof Error ? err.message : 'Failed to load reports')
      } finally {
        setLoading(false)
      }
    }
    fetchAll()
  }, [token])

  function statusColor(status: string) {
    switch (status) {
      case 'published': return 'bg-green-100 text-green-700'
      case 'approved': return 'bg-blue-100 text-blue-700'
      case 'draft': return 'bg-yellow-100 text-yellow-700'
      case 'archived': return 'bg-gray-100 text-gray-500'
      case 'optimal': return 'bg-green-100 text-green-700'
      case 'partial': return 'bg-yellow-100 text-yellow-700'
      case 'failure': return 'bg-red-100 text-red-700'
      default: return 'bg-gray-100 text-gray-500'
    }
  }

  const tabs: { key: Tab; label: string; icon: string }[] = [
    { key: 'overview', label: 'Overview', icon: '📊' },
    { key: 'faculty', label: 'Faculty Load', icon: '👨‍🏫' },
    { key: 'rooms', label: 'Room Usage', icon: '🏫' },
    { key: 'sections', label: 'Sections', icon: '📋' },
    { key: 'conflicts', label: 'Generation Logs', icon: '⚠️' },
  ]

  return (
    <div className="min-h-screen bg-gray-100 p-8">
      <div className="max-w-6xl mx-auto">
        {/* Header */}
        <div className="flex justify-between items-center mb-6">
          <div>
            <h1 className="text-2xl font-bold text-gray-800">📈 Reports</h1>
            <p className="text-gray-500">Schedule analytics and summaries</p>
          </div>
          <div className="flex gap-3">
            <a href="/admin" className="bg-gray-600 text-white px-4 py-2 rounded-md hover:bg-gray-700 transition text-sm">
              ← Dashboard
            </a>
            <button onClick={logout} className="bg-gray-800 text-white px-4 py-2 rounded-md hover:bg-gray-700 transition text-sm">
              Log out
            </button>
          </div>
        </div>

        {/* Tab Navigation */}
        <div className="flex gap-2 mb-6 flex-wrap">
          {tabs.map((tab) => (
            <button
              key={tab.key}
              onClick={() => setActiveTab(tab.key)}
              className={`px-4 py-2 rounded-md text-sm font-medium transition ${
                activeTab === tab.key
                  ? 'bg-blue-600 text-white'
                  : 'bg-white text-gray-600 hover:bg-gray-200 border border-gray-200'
              }`}
            >
              {tab.icon} {tab.label}
            </button>
          ))}
        </div>

        {loading && (
          <div className="bg-white rounded-lg shadow-md p-8 text-center">
            <p className="text-gray-500 text-lg">Loading reports...</p>
          </div>
        )}

        {error && (
          <div className="bg-red-50 border border-red-200 rounded-lg p-4 text-red-700">
            ❌ {error}
          </div>
        )}

        {!loading && !error && (
          <>
            {/* ========== OVERVIEW TAB ========== */}
            {activeTab === 'overview' && (
              <div className="space-y-6">
                {/* Schedule Status Cards */}
                <div className="bg-white rounded-lg shadow-md p-6">
                  <h2 className="text-lg font-semibold text-gray-800 mb-4">Schedule Status Overview</h2>
                  <div className="grid grid-cols-2 md:grid-cols-5 gap-4 mb-4">
                    {scheduleStatus.map((s) => (
                      <div key={s.status} className="text-center p-4 rounded-lg bg-gray-50 border border-gray-200">
                        <p className={`text-2xl font-bold ${s.status === 'published' ? 'text-green-600' : s.status === 'draft' ? 'text-yellow-600' : s.status === 'approved' ? 'text-blue-600' : 'text-gray-500'}`}>
                          {s.count}
                        </p>
                        <p className="text-sm text-gray-500 capitalize">{s.status}</p>
                      </div>
                    ))}
                  </div>
                  <p className="text-sm text-gray-400">Total schedules: <span className="font-semibold text-gray-700">{totalSchedules}</span></p>
                </div>

                {/* Quick Stats */}
                <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                  <div className="bg-white rounded-lg shadow-md p-6">
                    <h3 className="text-sm font-medium text-gray-500 mb-1">👨‍🏫 Faculty Members</h3>
                    <p className="text-3xl font-bold text-purple-600">{facultyWorkload.length}</p>
                    <p className="text-xs text-gray-400 mt-1">
                      {facultyWorkload.filter(f => f.assigned_hours > 0).length} with active load
                    </p>
                  </div>
                  <div className="bg-white rounded-lg shadow-md p-6">
                    <h3 className="text-sm font-medium text-gray-500 mb-1">🏫 Rooms</h3>
                    <p className="text-3xl font-bold text-blue-600">{rooms.length}</p>
                    <p className="text-xs text-gray-400 mt-1">
                      {rooms.filter(r => r.type === 'lecture').length} lecture, {rooms.filter(r => r.type === 'computer_lab').length} labs
                    </p>
                  </div>
                  <div className="bg-white rounded-lg shadow-md p-6">
                    <h3 className="text-sm font-medium text-gray-500 mb-1">📋 Sections</h3>
                    <p className="text-3xl font-bold text-teal-600">{sectionSummary.length}</p>
                    <p className="text-xs text-gray-400 mt-1">
                      {sectionSummary.reduce((sum, s) => sum + s.total_sessions, 0)} total sessions
                    </p>
                  </div>
                </div>
              </div>
            )}

            {/* ========== FACULTY WORKLOAD TAB ========== */}
            {activeTab === 'faculty' && (
              <div className="bg-white rounded-lg shadow-md p-6">
                <h2 className="text-lg font-semibold text-gray-800 mb-4">Faculty Teaching Load</h2>
                <p className="text-sm text-gray-500 mb-4">Assigned hours vs. maximum load per faculty (published schedules only)</p>
                <div className="overflow-x-auto">
                  <table className="w-full text-sm">
                    <thead>
                      <tr className="bg-gray-800 text-white">
                        <th className="p-3 text-left">Name</th>
                        <th className="p-3 text-left">Type</th>
                        <th className="p-3 text-center">Assigned Hours</th>
                        <th className="p-3 text-center">Max Load</th>
                        <th className="p-3 text-center">Utilization</th>
                        <th className="p-3 text-left">Load Bar</th>
                      </tr>
                    </thead>
                    <tbody>
                      {facultyWorkload.map((f) => {
                        const pct = f.utilization_percent ?? 0
                        const barColor = pct > 90 ? 'bg-red-500' : pct > 70 ? 'bg-yellow-500' : 'bg-green-500'
                        return (
                          <tr key={f.faculty_id} className="border-t border-gray-200 hover:bg-gray-50">
                            <td className="p-3 font-medium">{f.name}</td>
                            <td className="p-3 capitalize">{f.faculty_type}</td>
                            <td className="p-3 text-center font-semibold">{f.assigned_hours}h</td>
                            <td className="p-3 text-center">{f.max_teaching_load}h</td>
                            <td className="p-3 text-center">
                              <span className={`px-2 py-0.5 rounded text-xs font-medium ${
                                pct > 90 ? 'bg-red-100 text-red-700' :
                                pct > 70 ? 'bg-yellow-100 text-yellow-700' :
                                'bg-green-100 text-green-700'
                              }`}>
                                {f.assigned_hours === 0 ? 'No load' : `${pct}%`}
                              </span>
                            </td>
                            <td className="p-3">
                              <div className="w-full bg-gray-200 rounded-full h-2.5">
                                <div className={`${barColor} h-2.5 rounded-full`} style={{ width: `${Math.min(pct, 100)}%` }} />
                              </div>
                            </td>
                          </tr>
                        )
                      })}
                      {facultyWorkload.length === 0 && (
                        <tr><td colSpan={6} className="p-4 text-center text-gray-400">No faculty data</td></tr>
                      )}
                    </tbody>
                  </table>
                </div>
              </div>
            )}

            {/* ========== ROOM UTILIZATION TAB ========== */}
            {activeTab === 'rooms' && (
              <div className="bg-white rounded-lg shadow-md p-6">
                <h2 className="text-lg font-semibold text-gray-800 mb-4">Room Utilization</h2>
                <p className="text-sm text-gray-500 mb-4">Booked hours per week per room (published schedules only)</p>
                <div className="overflow-x-auto">
                  <table className="w-full text-sm">
                    <thead>
                      <tr className="bg-gray-800 text-white">
                        <th className="p-3 text-left">Room Name</th>
                        <th className="p-3 text-left">Type</th>
                        <th className="p-3 text-center">Capacity</th>
                        <th className="p-3 text-center">Booked Hours/Week</th>
                        <th className="p-3 text-left">Usage</th>
                      </tr>
                    </thead>
                    <tbody>
                      {rooms.sort((a, b) => b.booked_hours_per_week - a.booked_hours_per_week).map((r) => (
                        <tr key={r.room_id} className="border-t border-gray-200 hover:bg-gray-50">
                          <td className="p-3 font-medium">{r.name}</td>
                          <td className="p-3 capitalize">{r.type === 'computer_lab' ? 'Computer Lab' : 'Lecture'}</td>
                          <td className="p-3 text-center">{r.capacity}</td>
                          <td className="p-3 text-center font-semibold">{r.booked_hours_per_week}h</td>
                          <td className="p-3">
                            <div className="w-full bg-gray-200 rounded-full h-2.5">
                              <div
                                className={`h-2.5 rounded-full ${r.booked_hours_per_week > 30 ? 'bg-blue-600' : r.booked_hours_per_week > 15 ? 'bg-blue-400' : 'bg-blue-200'}`}
                                style={{ width: `${Math.min((r.booked_hours_per_week / 40) * 100, 100)}%` }}
                              />
                            </div>
                          </td>
                        </tr>
                      ))}
                      {rooms.length === 0 && (
                        <tr><td colSpan={5} className="p-4 text-center text-gray-400">No room data</td></tr>
                      )}
                    </tbody>
                  </table>
                </div>
              </div>
            )}

            {/* ========== SECTION SUMMARY TAB ========== */}
            {activeTab === 'sections' && (
              <div className="bg-white rounded-lg shadow-md p-6">
                <h2 className="text-lg font-semibold text-gray-800 mb-4">Section Summary</h2>
                <p className="text-sm text-gray-500 mb-4">Sessions, hours, and faculty count per section (published schedules)</p>
                <div className="overflow-x-auto">
                  <table className="w-full text-sm">
                    <thead>
                      <tr className="bg-gray-800 text-white">
                        <th className="p-3 text-left">Section</th>
                        <th className="p-3 text-center">Year</th>
                        <th className="p-3 text-center">Semester</th>
                        <th className="p-3 text-center">Sessions</th>
                        <th className="p-3 text-center">Total Hours</th>
                        <th className="p-3 text-center">Faculty</th>
                        <th className="p-3 text-center">Subjects</th>
                      </tr>
                    </thead>
                    <tbody>
                      {sectionSummary.map((s) => (
                        <tr key={s.section_id} className="border-t border-gray-200 hover:bg-gray-50">
                          <td className="p-3 font-medium">{s.name}</td>
                          <td className="p-3 text-center">{s.year_level}</td>
                          <td className="p-3 text-center">{s.semester_name}</td>
                          <td className="p-3 text-center">{s.total_sessions}</td>
                          <td className="p-3 text-center font-semibold">{s.total_hours}h</td>
                          <td className="p-3 text-center">{s.faculty_count}</td>
                          <td className="p-3 text-center">{s.subjects_count}</td>
                        </tr>
                      ))}
                      {sectionSummary.length === 0 && (
                        <tr><td colSpan={7} className="p-4 text-center text-gray-400">No section data</td></tr>
                      )}
                    </tbody>
                  </table>
                </div>
              </div>
            )}

            {/* ========== CONFLICTS / LOGS TAB ========== */}
            {activeTab === 'conflicts' && (
              <div className="bg-white rounded-lg shadow-md p-6">
                <h2 className="text-lg font-semibold text-gray-800 mb-4">Schedule Generation Logs</h2>
                <p className="text-sm text-gray-500 mb-4">History of all AI schedule generation attempts</p>
                {conflicts.length === 0 && (
                  <p className="text-gray-400 text-sm">No generation logs found.</p>
                )}
                <div className="space-y-3">
                  {conflicts.map((log) => (
                    <div key={log.id} className="border border-gray-200 rounded-lg p-4">
                      <div className="flex justify-between items-center mb-2">
                        <div className="flex items-center gap-2">
                          <span className="font-semibold text-gray-800">Section: {log.section}</span>
                          <span className={`px-2 py-0.5 rounded text-xs font-medium ${statusColor(log.status)}`}>
                            {log.status.toUpperCase()}
                          </span>
                        </div>
                        <span className="text-xs text-gray-400">
                          {new Date(log.created_at).toLocaleString()}
                        </span>
                      </div>
                      {log.message && (
                        <p className="text-sm text-gray-600 mb-2">{log.message}</p>
                      )}
                      {log.unscheduled_sessions && Array.isArray(log.unscheduled_sessions) && log.unscheduled_sessions.length > 0 && (
                        <div className="bg-yellow-50 border border-yellow-200 rounded p-3 mt-2">
                          <p className="text-xs font-medium text-yellow-700 mb-1">Unscheduled Sessions:</p>
                          <ul className="text-xs text-yellow-600 list-disc list-inside">
                            {log.unscheduled_sessions.map((u: any, i: number) => (
                              <li key={i}>{typeof u === 'string' ? u : JSON.stringify(u)}</li>
                            ))}
                          </ul>
                        </div>
                      )}
                      <p className="text-xs text-gray-400 mt-2">Requested by: {log.requested_by}</p>
                    </div>
                  ))}
                </div>
              </div>
            )}
          </>
        )}
      </div>
    </div>
  )
}
