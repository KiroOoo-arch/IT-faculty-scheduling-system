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

export default function ReportsPage() {
  const { token } = useAuth()
  const [workload, setWorkload] = useState<FacultyWorkload[]>([])
  const [rooms, setRooms] = useState<RoomUtil[]>([])
  const [conflicts, setConflicts] = useState<ConflictLog[]>([])
  const [loading, setLoading] = useState(true)
  const [activeTab, setActiveTab] = useState<'workload' | 'rooms' | 'conflicts'>('workload')

  function headers() {
    return {
      Authorization: `Bearer ${token}`,
      'Accept': 'application/json',
    }
  }

  async function fetchAll() {
    setLoading(true)
    try {
      const [workRes, roomsRes, conflictRes] = await Promise.all([
        fetch(`${API_BASE_URL}/reports/faculty-workload`, { headers: headers() }),
        fetch(`${API_BASE_URL}/reports/room-utilization`, { headers: headers() }),
        fetch(`${API_BASE_URL}/reports/conflicts`, { headers: headers() }),
      ])
      if (workRes.ok) setWorkload(await workRes.json())
      if (roomsRes.ok) setRooms(await roomsRes.json())
      if (conflictRes.ok) setConflicts(await conflictRes.json())
    } catch {
      // handle error silently
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => { fetchAll() }, [])

  const tabs = [
    { key: 'workload' as const, label: '📊 Faculty Workload', count: workload.length },
    { key: 'rooms' as const, label: '🏫 Room Utilization', count: rooms.length },
    { key: 'conflicts' as const, label: '⚠️ Conflict History', count: conflicts.length },
  ]

  return (
    <div className="min-h-screen bg-gray-100 p-8">
      <div className="max-w-5xl mx-auto">
        <div className="flex justify-between items-center mb-6">
          <h1 className="text-2xl font-bold text-gray-800">Reports Dashboard</h1>
          <a href="/dashboard" className="text-blue-600 hover:underline">← Back to Dashboard</a>
        </div>

        {/* Tab Navigation */}
        <div className="flex gap-2 mb-6">
          {tabs.map((tab) => (
            <button
              key={tab.key}
              onClick={() => setActiveTab(tab.key)}
              className={`px-4 py-2 rounded-md text-sm font-medium transition ${
                activeTab === tab.key
                  ? 'bg-gray-800 text-white shadow'
                  : 'bg-white text-gray-600 hover:bg-gray-50 border border-gray-200'
              }`}
            >
              {tab.label}
              <span className={`ml-2 px-2 py-0.5 rounded-full text-xs ${
                activeTab === tab.key ? 'bg-gray-600 text-white' : 'bg-gray-100 text-gray-500'
              }`}>
                {tab.count}
              </span>
            </button>
          ))}
        </div>

        {loading && <p className="text-gray-500">Loading reports...</p>}

        {/* Faculty Workload */}
        {activeTab === 'workload' && !loading && (
          <div className="bg-white rounded-lg shadow-md overflow-hidden">
            <h2 className="text-lg font-semibold text-gray-800 p-4 border-b border-gray-200">Faculty Workload</h2>
            {workload.length === 0 ? (
              <p className="text-gray-400 p-4">No published schedules yet.</p>
            ) : (
              <table className="w-full text-left">
                <thead className="bg-gray-800 text-white">
                  <tr>
                    <th className="p-3">Faculty</th>
                    <th className="p-3">Type</th>
                    <th className="p-3">Assigned Hours</th>
                    <th className="p-3">Max Load</th>
                    <th className="p-3">Utilization</th>
                  </tr>
                </thead>
                <tbody>
                  {workload.map((w) => (
                    <tr key={w.faculty_id} className="border-t border-gray-200">
                      <td className="p-3 font-medium">{w.name}</td>
                      <td className="p-3 capitalize">{w.faculty_type}</td>
                      <td className="p-3">{w.assigned_hours}h</td>
                      <td className="p-3">{w.max_teaching_load}h</td>
                      <td className="p-3">
                        <div className="flex items-center gap-2">
                          <div className="w-24 bg-gray-200 rounded-full h-2.5">
                            <div
                              className={`h-2.5 rounded-full ${
                                (w.utilization_percent ?? 0) > 75 ? 'bg-red-500' :
                                (w.utilization_percent ?? 0) > 50 ? 'bg-yellow-500' : 'bg-green-500'
                              }`}
                              style={{ width: `${Math.min(w.utilization_percent ?? 0, 100)}%` }}
                            />
                          </div>
                          <span className="text-sm">{w.utilization_percent ?? '—'}%</span>
                        </div>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            )}
          </div>
        )}

        {/* Room Utilization */}
        {activeTab === 'rooms' && !loading && (
          <div className="bg-white rounded-lg shadow-md overflow-hidden">
            <h2 className="text-lg font-semibold text-gray-800 p-4 border-b border-gray-200">Room Utilization</h2>
            {rooms.length === 0 ? (
              <p className="text-gray-400 p-4">No rooms found.</p>
            ) : (
              <table className="w-full text-left">
                <thead className="bg-gray-800 text-white">
                  <tr>
                    <th className="p-3">Room</th>
                    <th className="p-3">Type</th>
                    <th className="p-3">Capacity</th>
                    <th className="p-3">Booked Hours/Week</th>
                  </tr>
                </thead>
                <tbody>
                  {rooms.map((r) => (
                    <tr key={r.room_id} className="border-t border-gray-200">
                      <td className="p-3 font-medium">{r.name}</td>
                      <td className="p-3 capitalize">{r.type}</td>
                      <td className="p-3">{r.capacity}</td>
                      <td className="p-3">{r.booked_hours_per_week}h</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            )}
          </div>
        )}

        {/* Conflict History */}
        {activeTab === 'conflicts' && !loading && (
          <div className="bg-white rounded-lg shadow-md overflow-hidden">
            <h2 className="text-lg font-semibold text-gray-800 p-4 border-b border-gray-200">Conflict History</h2>
            {conflicts.length === 0 ? (
              <p className="text-gray-400 p-4">No generation history yet.</p>
            ) : (
              <div className="divide-y divide-gray-200">
                {conflicts.map((log) => (
                  <div key={log.id} className="p-4">
                    <div className="flex justify-between items-start mb-2">
                      <div>
                        <span className="font-medium">Section: {log.section}</span>
                        <span className={`ml-3 px-2 py-0.5 rounded text-xs font-medium ${
                          log.status === 'OPTIMAL' ? 'bg-green-100 text-green-700' :
                          log.status?.toLowerCase() === 'optimal' ? 'bg-green-100 text-green-700' :
                          'bg-red-100 text-red-700'
                        }`}>
                          {log.status}
                        </span>
                      </div>
                      <span className="text-xs text-gray-400">
                        {new Date(log.created_at).toLocaleString()}
                      </span>
                    </div>
                    <p className="text-sm text-gray-600">{log.message}</p>
                    <p className="text-xs text-gray-400 mt-1">Requested by: {log.requested_by}</p>
                  </div>
                ))}
              </div>
            )}
          </div>
        )}
      </div>
    </div>
  )
}
