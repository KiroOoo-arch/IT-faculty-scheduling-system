import { useEffect, useState } from 'react'
import { useAuth, API_BASE_URL } from '../context/AuthContext'
import { formatTimeRange } from '../utils/time'
import { SYSTEM_NAME, SYSTEM_ORG } from '../constants/system'

type Faculty = {
  id: number
  name: string
  faculty_type: string
  max_teaching_load: number
  subjects?: { code: string; title: string }[]
}

type Section = {
  id: number
  name: string
  year_level: number
  semester_name: string
  subjects?: { code: string }[]
}

type Room = {
  id: number
  name: string
  type: string
}

type Session = {
  id: number
  subject?: { code: string }
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
  status: 'draft' | 'approved' | 'published' | 'archived'
  generated_at: string
  section?: { id: number; name: string; year_level: number; semester_name: string }
  sessions?: Session[]
}

const ALL_DAYS = [
  { val: 1, label: 'Monday' }, { val: 2, label: 'Tuesday' }, { val: 3, label: 'Wednesday' },
  { val: 4, label: 'Thursday' }, { val: 5, label: 'Friday' }, { val: 6, label: 'Saturday' },
  { val: 7, label: 'Sunday' },
]

export default function AdminDashboard() {
  const { user, token, logout } = useAuth()
  const [faculties, setFaculties] = useState<Faculty[]>([])
  const [rooms, setRooms] = useState<Room[]>([])
  const [sections, setSections] = useState<Section[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [generating, setGenerating] = useState(false)
  const [generateResult, setGenerateResult] = useState('')
  const [selectedSectionId, setSelectedSectionId] = useState<number | null>(null)
  const [showArchived, setShowArchived] = useState(false)

  const [schedules, setSchedules] = useState<Schedule[]>([])
  const [schedulesLoading, setSchedulesLoading] = useState(false)

  const [editingSession, setEditingSession] = useState<Session | null>(null)
  const [editDay, setEditDay] = useState(1)
  const [editStart, setEditStart] = useState('')
  const [editEnd, setEditEnd] = useState('')
  const [editRoomId, setEditRoomId] = useState<number | null>(null)
  const [editFacultyId, setEditFacultyId] = useState<number | null>(null)
  const [editError, setEditError] = useState('')
  const [editSaving, setEditSaving] = useState(false)

  const [showDeleteConfirm, setShowDeleteConfirm] = useState(false)
  const [deleteTargetId, setDeleteTargetId] = useState<number | null>(null)

  function headers() {
    return {
      Authorization: `Bearer ${token}`,
      'Accept': 'application/json',
      'Content-Type': 'application/json',
    }
  }

  useEffect(() => {
    async function fetchData() {
      try {
        const [facRes, secRes, roomRes] = await Promise.all([
          fetch(`${API_BASE_URL}/faculties`, { headers: headers() }),
          fetch(`${API_BASE_URL}/sections`, { headers: headers() }),
          fetch(`${API_BASE_URL}/rooms`, { headers: headers() }),
        ])
        if (facRes.ok) {
          const data = await facRes.json()
          setFaculties(Array.isArray(data) ? data : [])
        }
        if (secRes.ok) {
          const data = await secRes.json()
          setSections(Array.isArray(data) ? data : [])
        }
        if (roomRes.ok) {
          const data = await roomRes.json()
          setRooms(Array.isArray(data) ? data : [])
        }
      } catch (err) {
        setError(err instanceof Error ? err.message : 'Something went wrong')
      } finally {
        setLoading(false)
      }
    }
    fetchData()
    fetchSchedules()
  }, [token])

  useEffect(() => {
    if (sections.length > 0 && selectedSectionId === null) {
      setSelectedSectionId(sections[0].id)
    }
  }, [sections])

  useEffect(() => {
    fetchSchedules()
  }, [showArchived])

  async function fetchSchedules() {
    setSchedulesLoading(true)
    try {
      const response = await fetch(`${API_BASE_URL}/schedules?show_archived=${showArchived}`, { headers: headers() })
      if (response.ok) {
        const data = await response.json()
        setSchedules(Array.isArray(data) ? data : [])
      }
    } catch { /* ignore */ } finally {
      setSchedulesLoading(false)
    }
  }

  async function handleGenerate() {
    if (!selectedSectionId) return

    // Warn before replacing work that already exists for this section, so a
    // misclick does not archive the current draft without the admin noticing.
    const existing = schedules.filter(
      (s) => s.section_id === selectedSectionId && s.status !== 'archived'
    )
    if (existing.length > 0) {
      const published = existing.find((s) => s.status === 'published')
      const approved = existing.find((s) => s.status === 'approved')
      const draft = existing.find((s) => s.status === 'draft')
      const has = [
        published && `a published schedule (#${published.id})`,
        approved && `an approved schedule (#${approved.id})`,
        draft && `a generated draft (#${draft.id})`,
      ].filter(Boolean).join(' and ')
      const effect = published
        ? 'Generating archives any current draft and creates a new one; the published timetable stays live until you publish the new schedule.'
        : 'Generating archives the existing schedule and creates a new draft.'
      if (!confirm(`This section already has ${has}. ${effect}\n\nGenerate anyway?`)) return
    }

    setGenerating(true)
    setGenerateResult('')
    try {
      const response = await fetch(`${API_BASE_URL}/schedules/generate/${selectedSectionId}`, {
        method: 'POST', headers: headers(),
      })
      const data = await response.json()
      if (!response.ok) throw new Error(data.message || data.error || 'Generation failed')
      setGenerateResult(`✅ Status: ${data.status} — ${data.sessions?.length ?? 0} sessions created.`)
      fetchSchedules()
    } catch (err) {
      setGenerateResult(err instanceof Error ? `❌ Error: ${err.message}` : 'Something went wrong')
    } finally {
      setGenerating(false)
    }
  }

  async function handleApprove(scheduleId: number) {
    try {
      const response = await fetch(`${API_BASE_URL}/schedules/${scheduleId}/approve`, {
        method: 'PATCH', headers: headers(),
      })
      const data = await response.json()
      if (!response.ok) throw new Error(data.message || 'Approval failed')
      fetchSchedules()
    } catch (err) {
      alert(err instanceof Error ? err.message : 'Approval failed')
    }
  }

  async function handlePublish(scheduleId: number) {
    try {
      const response = await fetch(`${API_BASE_URL}/schedules/${scheduleId}/publish`, {
        method: 'PATCH', headers: headers(),
      })
      const data = await response.json()
      if (!response.ok) throw new Error(data.message || 'Publish failed')
      fetchSchedules()
    } catch (err) {
      alert(err instanceof Error ? err.message : 'Publish failed')
    }
  }

  // 👇 NEW: Unpublish handler
  async function handleUnpublish(scheduleId: number) {
    if (!confirm('Unpublish this schedule? It will revert to draft for editing.')) return
    try {
      const response = await fetch(`${API_BASE_URL}/schedules/${scheduleId}/unpublish`, {
        method: 'PATCH', headers: headers(),
      })
      const data = await response.json()
      if (!response.ok) throw new Error(data.message || 'Unpublish failed')
      fetchSchedules()
    } catch (err) {
      alert(err instanceof Error ? err.message : 'Unpublish failed')
    }
  }

  function handleDeleteSchedule(scheduleId: number) {
    setDeleteTargetId(scheduleId)
    setShowDeleteConfirm(true)
  }

  async function confirmDelete() {
    if (!deleteTargetId) return
    try {
      const response = await fetch(`${API_BASE_URL}/schedules/${deleteTargetId}`, {
        method: 'DELETE', headers: headers(),
      })
      if (!response.ok) {
        const data = await response.json()
        throw new Error(data.message || 'Delete failed')
      }
      setShowDeleteConfirm(false)
      setDeleteTargetId(null)
      fetchSchedules()
    } catch (err) {
      alert(err instanceof Error ? err.message : 'Delete failed')
      setShowDeleteConfirm(false)
    }
  }

  function startEditSession(session: Session) {
    setEditingSession(session)
    setEditDay(session.day_of_week)
    setEditStart(session.start_time.substring(0, 5))
    setEditEnd(session.end_time.substring(0, 5))
    setEditRoomId(session.room?.id ?? null)
    setEditFacultyId(session.faculty?.id ?? null)
    setEditError('')
  }

  async function saveEditSession() {
    if (!editingSession) return
    setEditSaving(true)
    setEditError('')
    try {
      const response = await fetch(`${API_BASE_URL}/schedules/sessions/${editingSession.id}`, {
        method: 'PUT',
        headers: headers(),
        body: JSON.stringify({
          day_of_week: editDay,
          start_time: editStart.split(' ')[0],
          end_time: editEnd.split(' ')[0],
          room_id: editRoomId,
          faculty_id: editFacultyId,
        }),
      })
      const data = await response.json()
      if (!response.ok) {
        // The API always returns a generic `message` alongside a specific
        // `conflicts` list, so prefer the reasons — that is the actionable part.
        const details = Array.isArray(data.conflicts) && data.conflicts.length > 0
          ? data.conflicts.join(' ')
          : data.message
        throw new Error(details || 'Edit failed')
      }
      setEditingSession(null)
      fetchSchedules()
    } catch (err) {
      setEditError(err instanceof Error ? err.message : 'Something went wrong')
    } finally {
      setEditSaving(false)
    }
  }



  const selectedSection = sections.find((s) => s.id === selectedSectionId)
  // Headline numbers for the faculty summary. The full roster lives on the
  // Faculty page and the load analysis in Reports, so the dashboard only needs
  // the counts and a way to reach them.
  const fullTimeCount = faculties.filter((f) => f.faculty_type === 'full_time').length
  const partTimeCount = faculties.filter((f) => f.faculty_type === 'part_time').length

  return (
    <div className="min-h-screen bg-gray-100 p-8">
      <div className="max-w-5xl mx-auto">
        <div className="flex justify-between items-center mb-6">
          <div>
            <p className="text-[11px] font-semibold uppercase tracking-widest text-[#0a2f9c]">{SYSTEM_ORG}</p>
            <h1 className="text-2xl font-bold text-gray-800">Welcome, {user?.name}</h1>
            <p className="mt-1 text-sm font-semibold text-[#0a2f9c]">{SYSTEM_NAME}</p>
          </div>
          <button onClick={logout} className="btn-navy-outline">Log out</button>
        </div>

        {/* A failed initial load used to surface only inside the faculty table at
            the bottom of the page. Now that the table is gone the message belongs
            up here, where it is actually seen. */}
        {error && (
          <div className="mb-6 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
            {error}
          </div>
        )}

        {/* Navigation Links */}
        <div className="grid grid-cols-2 md:grid-cols-6 gap-3 mb-6">
          <a href="/admin/users" className="bg-green-600 text-white px-4 py-3 rounded-md hover:bg-green-700 transition text-center font-medium">👤 Users</a>
          <a href="/admin/faculty" className="bg-purple-600 text-white px-4 py-3 rounded-md hover:bg-purple-700 transition text-center font-medium">🎓 Faculty</a>
          <a href="/admin/subjects" className="bg-orange-600 text-white px-4 py-3 rounded-md hover:bg-orange-700 transition text-center font-medium">📚 Subjects</a>
          <a href="/admin/rooms" className="bg-blue-600 text-white px-4 py-3 rounded-md hover:bg-blue-700 transition text-center font-medium">🏫 Rooms</a>
          <a href="/admin/sections" className="bg-teal-600 text-white px-4 py-3 rounded-md hover:bg-teal-700 transition text-center font-medium">📋 Sections</a>
          <a href="/admin/reports" className="bg-red-600 text-white px-4 py-3 rounded-md hover:bg-red-700 transition text-center font-medium">📈 Reports</a>
        </div>

        {/* Generate Schedule - the navy band and gold rule echo the college
            letterhead, so the primary action on this page reads as formal. */}
        <div className="bg-white rounded-lg shadow-md mb-6 overflow-hidden border border-gray-200">
          <div className="bg-[#0a2f9c] border-b-4 border-[#ffc000] px-6 py-4">
            <h2 className="text-white font-semibold uppercase tracking-widest text-sm">Generate Schedule</h2>
            <p className="text-blue-100 text-xs mt-1">
              Produce a draft timetable for a section using the constraint-based scheduler.
            </p>
          </div>

          <div className="p-6">
            <div className="flex flex-col sm:flex-row sm:items-end gap-4">
              <div className="w-full sm:max-w-sm">
                <label htmlFor="generate-section" className="block text-[11px] font-semibold uppercase tracking-wide text-[#12266b] mb-1">
                  Section
                </label>
                <select id="generate-section" value={selectedSectionId ?? ''} onChange={(e) => setSelectedSectionId(parseInt(e.target.value))}
                  className="w-full border border-gray-300 rounded-md px-3 py-2 text-sm text-gray-800 bg-white transition focus:outline-none focus:border-[#0a2f9c] focus:ring-2 focus:ring-[#0a2f9c]/20">
                  {sections.map((s) => (
                    <option key={s.id} value={s.id}>{s.name} (Year {s.year_level} — {s.semester_name})</option>
                  ))}
                </select>
              </div>

              <button onClick={handleGenerate} disabled={generating || !selectedSectionId}
                className="whitespace-nowrap bg-[#0a2f9c] text-white px-5 py-2 rounded-md text-sm font-semibold shadow-sm transition hover:bg-[#08227a] disabled:opacity-40 disabled:cursor-not-allowed">
                {generating ? 'Generating...' : `Generate Schedule for ${selectedSection?.name || '...'}`}
              </button>
            </div>

            {selectedSection && (
              <p className="mt-4 text-xs text-gray-500">
                <span className="text-[11px] font-semibold uppercase tracking-wide text-gray-400">Subjects</span>
                <span className="block mt-1 text-gray-600">
                  {selectedSection.subjects?.map((s) => s.code).join(', ') || 'None assigned'}
                </span>
              </p>
            )}

            {generateResult && (
              <p className={`mt-4 rounded-md border px-3 py-2 text-sm ${generateResult.startsWith('\u274c') ? 'bg-red-50 text-red-700 border-red-200' : 'bg-green-50 text-green-700 border-green-200'}`}>
                {generateResult}
              </p>
            )}
          </div>
        </div>

        {/* Schedule Review & Approval */}
        <div className="bg-white rounded-lg shadow-md p-6 mb-6">
          <div className="flex justify-between items-center mb-4">
            <h2 className="text-lg font-semibold text-gray-800">Schedule Review & Approval</h2>
            <button onClick={() => setShowArchived(!showArchived)}
              className="text-xs px-3 py-1.5 rounded border border-gray-300 hover:bg-gray-100 transition">
              {showArchived ? '🙈 Hide Archived' : '📂 Show Archived'}
            </button>
          </div>

          {schedulesLoading && <p className="text-gray-500">Loading schedules...</p>}
          {!schedulesLoading && schedules.length === 0 && <p className="text-gray-400 text-sm">No schedules found.</p>}

          {!schedulesLoading && schedules.length > 0 && (
            <div className="space-y-4">
              {schedules.map((schedule) => (
                <div key={schedule.id} className="border border-gray-200 rounded-lg p-4">
                  <div className="flex justify-between items-center mb-3">
                    <div>
                      <span className="font-semibold">Schedule #{schedule.id}</span>
                      <span className="text-gray-500 text-sm ml-2">
                        {schedule.section
                          ? `${schedule.section.name} — Year ${schedule.section.year_level} (${schedule.section.semester_name})`
                          : `Section #${schedule.section_id}`}
                      </span>
                      <span className={`ml-3 px-2 py-0.5 rounded text-xs font-medium ${
                        schedule.status === 'published' ? 'bg-green-100 text-green-700' :
                        schedule.status === 'approved' ? 'bg-blue-100 text-blue-700' :
                        schedule.status === 'archived' ? 'bg-gray-100 text-gray-500' :
                        'bg-yellow-100 text-yellow-700'
                      }`}>{schedule.status}</span>
                    </div>
                    <div className="flex gap-2">
                      {schedule.status === 'draft' && (
                        <button onClick={() => handleApprove(schedule.id)}
                          className="bg-blue-600 text-white px-3 py-1.5 rounded text-sm hover:bg-blue-700 transition">Approve</button>
                      )}
                      {schedule.status === 'approved' && (
                        <button onClick={() => handlePublish(schedule.id)}
                          className="bg-green-600 text-white px-3 py-1.5 rounded text-sm hover:bg-green-700 transition">Publish</button>
                      )}
                      {/* Published → Print/Download + Unpublish buttons */}
                      {schedule.status === 'published' && (
                        <>
                          <a href={`/print-schedule?schedule=${schedule.id}`} target="_blank" rel="noreferrer"
                            className="bg-indigo-600 hover:bg-indigo-700 text-white px-3 py-1.5 rounded text-sm transition">
                            🖨 Print / PDF
                          </a>
                          <button onClick={() => handleUnpublish(schedule.id)}
                            className="bg-yellow-500 hover:bg-yellow-600 text-white px-3 py-1.5 rounded text-sm transition">
                            Unpublish
                          </button>
                        </>
                      )}
                      {/* Draft, approved and archived can all be discarded. Only a
                          published schedule is protected: it is the live timetable,
                          so it must be unpublished before it can be deleted. */}
                      {schedule.status !== 'published' && (
                        <button onClick={() => handleDeleteSchedule(schedule.id)}
                          className="text-red-600 hover:underline text-sm">🗑 Delete</button>
                      )}
                    </div>
                  </div>

                  {schedule.sessions && schedule.sessions.length > 0 && (
                    <table className="w-full text-sm">
                      <thead>
                        <tr className="text-gray-500 border-b">
                          <th className="py-1 pr-2 text-left">Subject</th>
                          <th className="py-1 px-2 text-left">Type</th>
                          <th className="py-1 px-2 text-left">Day</th>
                          <th className="py-1 px-2 text-left">Time</th>
                          <th className="py-1 px-2 text-left">Room</th>
                          <th className="py-1 px-2 text-left">Faculty</th>
                          {(schedule.status === 'draft' || schedule.status === 'approved') && (
                            <th className="py-1 pl-2 text-left">Actions</th>
                          )}
                        </tr>
                      </thead>
                      <tbody>
                        {schedule.sessions.map((session) => (
                          <tr key={session.id} className="border-b border-gray-100">
                            <td className="py-1 pr-2">{session.subject?.code ?? '—'}</td>
                            <td className="py-1 px-2">{session.session_type ?? '—'}</td>
                            <td className="py-1 px-2">{ALL_DAYS[session.day_of_week - 1]?.label ?? '—'}</td>
                            <td className="py-1 px-2">{formatTimeRange(session.start_time, session.end_time)}</td>
                            <td className="py-1 px-2">{session.room?.name ?? '—'}</td>
                            <td className="py-1 px-2">{session.faculty?.name ?? '—'}</td>
                            {(schedule.status === 'draft' || schedule.status === 'approved') && (
                              <td className="py-1 pl-2">
                                <button onClick={() => startEditSession(session)}
                                  className="text-blue-600 hover:underline text-xs">Edit</button>
                              </td>
                            )}
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

        {/* Session Edit Modal */}
        {editingSession && (
          <div className="fixed inset-0 bg-black bg-opacity-40 flex items-center justify-center z-50">
            <div className="bg-white rounded-lg shadow-xl p-6 w-full max-w-lg mx-4">
              <h3 className="text-lg font-semibold mb-4">Edit Session: {editingSession.subject?.code ?? '—'}</h3>
              {editError && (
                <div className="bg-red-50 border border-red-200 text-red-700 text-sm rounded-md p-3 mb-4">❌ {editError}</div>
              )}
              <div className="grid grid-cols-2 gap-4 mb-4">
                <div>
                  <label className="block text-xs font-medium text-gray-600 mb-1">Day</label>
                  <select value={editDay} onChange={(e) => setEditDay(parseInt(e.target.value))}
                    className="w-full border border-gray-300 rounded-md px-3 py-2 text-sm">
                    {ALL_DAYS.map((d) => (
                      <option key={d.val} value={d.val}>{d.label}</option>
                    ))}
                  </select>
                </div>
                <div>
                  <label className="block text-xs font-medium text-gray-600 mb-1">Room</label>
                  <select value={editRoomId ?? ''} onChange={(e) => setEditRoomId(e.target.value ? parseInt(e.target.value) : null)}
                    className="w-full border border-gray-300 rounded-md px-3 py-2 text-sm">
                    <option value="">—</option>
                    {rooms.map((r) => (
                      <option key={r.id} value={r.id}>{r.name} ({r.type})</option>
                    ))}
                  </select>
                </div>
                <div>
                  <label className="block text-xs font-medium text-gray-600 mb-1">Start Time</label>
                  <input type="time" value={editStart} onChange={(e) => setEditStart(e.target.value)}
                    className="w-full border border-gray-300 rounded-md px-3 py-2 text-sm" />
                </div>
                <div>
                  <label className="block text-xs font-medium text-gray-600 mb-1">End Time</label>
                  <input type="time" value={editEnd} onChange={(e) => setEditEnd(e.target.value)}
                    className="w-full border border-gray-300 rounded-md px-3 py-2 text-sm" />
                </div>
                <div className="col-span-2">
                  <label className="block text-xs font-medium text-gray-600 mb-1">Faculty</label>
                  <select value={editFacultyId ?? ''} onChange={(e) => setEditFacultyId(e.target.value ? parseInt(e.target.value) : null)}
                    className="w-full border border-gray-300 rounded-md px-3 py-2 text-sm">
                    <option value="">—</option>
                    {faculties.map((f) => (
                      <option key={f.id} value={f.id}>{f.name ?? `Faculty #${f.id}`}</option>
                    ))}
                  </select>
                </div>
              </div>
              <div className="flex justify-end gap-2">
                <button onClick={() => setEditingSession(null)}
                  className="px-4 py-2 text-sm border border-gray-300 rounded-md hover:bg-gray-50 transition">Cancel</button>
                <button onClick={saveEditSession} disabled={editSaving}
                  className="bg-blue-600 text-white px-4 py-2 text-sm rounded-md hover:bg-blue-700 transition disabled:opacity-50">
                  {editSaving ? 'Saving...' : 'Save Changes'}
                </button>
              </div>
            </div>
          </div>
        )}

        {/* Delete Confirmation Modal */}
        {showDeleteConfirm && (
          <div className="fixed inset-0 bg-black bg-opacity-40 flex items-center justify-center z-50">
            <div className="bg-white rounded-lg shadow-xl p-6 w-full max-w-sm mx-4">
              <h3 className="text-lg font-semibold mb-2">Delete Schedule?</h3>
              <p className="text-gray-600 text-sm mb-6">
                This will permanently delete this schedule and all its sessions. This cannot be undone.
              </p>
              <div className="flex justify-end gap-2">
                <button onClick={() => setShowDeleteConfirm(false)}
                  className="px-4 py-2 text-sm border border-gray-300 rounded-md hover:bg-gray-50 transition">
                  Cancel
                </button>
                <button onClick={confirmDelete}
                  className="px-4 py-2 text-sm bg-red-600 text-white rounded-md hover:bg-red-700 transition">
                  Yes, Delete
                </button>
              </div>
            </div>
          </div>
        )}

        {/* Faculty counts only. The full roster lives on the Faculty page and the
            load analysis in Reports - both reachable from the tiles above - so
            this keeps the one thing they do not show (the full-time/part-time
            split) and deliberately adds no second set of links to the same pages. */}
        <div className="rounded-lg border border-gray-200 bg-white p-4 shadow-md">
          <div>
            <p className="text-[11px] font-semibold uppercase tracking-widest text-[#0a2f9c]">Faculty</p>
            <p className="mt-0.5 text-sm text-gray-600">
              {loading ? 'Loading...' : `${faculties.length} on record \u00b7 ${fullTimeCount} full-time \u00b7 ${partTimeCount} part-time`}
            </p>
          </div>
        </div>

      </div>
    </div>
  )
}
