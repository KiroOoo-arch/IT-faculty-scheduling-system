import { useEffect, useState } from 'react'
import { useAuth, API_BASE_URL } from '../../context/AuthContext'

type Subject = {
  id: number
  code: string
  title: string
}

type Availability = {
  id?: number
  day_of_week: number
  start_time: string | null
  end_time: string | null
}

type Faculty = {
  id: number
  name: string
  faculty_type: string
  max_teaching_load: number
  subjects?: Subject[]
}

const ALL_DAYS = [
  { val: 1, label: 'Mon' }, { val: 2, label: 'Tue' }, { val: 3, label: 'Wed' },
  { val: 4, label: 'Thu' }, { val: 5, label: 'Fri' }, { val: 6, label: 'Sat' }, { val: 7, label: 'Sun' },
]

export default function FacultyPage() {
  const { token } = useAuth()
  const [faculties, setFaculties] = useState<Faculty[]>([])
  const [allSubjects, setAllSubjects] = useState<Subject[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')

  const [form, setForm] = useState({
    name: '',
    faculty_type: 'full_time', max_teaching_load: 24,
  })
  const [editing, setEditing] = useState<number | null>(null)
  const [editForm, setEditForm] = useState({
    name: '', faculty_type: 'full_time', max_teaching_load: 24,
  })
  const [selectedSubjectIds, setSelectedSubjectIds] = useState<number[]>([])

  // Availability state
  const [availability, setAvailability] = useState<Availability[]>([])
  const [availStartTime, setAvailStartTime] = useState('07:00')
  const [availEndTime, setAvailEndTime] = useState('17:00')

  function headers() {
    return {
      Authorization: `Bearer ${token}`,
      'Accept': 'application/json',
      'Content-Type': 'application/json',
    }
  }

  async function fetchFaculties() {
    setLoading(true)
    try {
      const res = await fetch(`${API_BASE_URL}/faculties`, { headers: headers() })
      const data = await res.json()
      setFaculties(Array.isArray(data) ? data : [])
    } catch {
      setError('Failed to load faculties')
    } finally {
      setLoading(false)
    }
  }

  async function fetchSubjects() {
    try {
      const res = await fetch(`${API_BASE_URL}/subjects`, { headers: headers() })
      const data = await res.json()
      setAllSubjects(Array.isArray(data) ? data : [])
    } catch { /* ignore */ }
  }

  useEffect(() => {
    fetchFaculties()
    fetchSubjects()
  }, [])

  async function fetchAvailability(facultyId: number) {
    try {
      const res = await fetch(`${API_BASE_URL}/faculties/${facultyId}/availability`, { headers: headers() })
      if (res.ok) {
        const data = await res.json()
        setAvailability(Array.isArray(data) ? data : [])
      }
    } catch { /* ignore */ }
  }

  function toggleAvailDay(day: number) {
    setAvailability((prev) => {
      const exists = prev.find((a) => a.day_of_week === day)
      if (exists) {
        return prev.filter((a) => a.day_of_week !== day)
      } else {
        return [...prev, { day_of_week: day, start_time: availStartTime + ':00', end_time: availEndTime + ':00' }]
      }
    })
  }

  function isDaySelected(day: number) {
    return availability.some((a) => a.day_of_week === day)
  }

  async function handleCreate(e: React.FormEvent) {
    e.preventDefault()
    setError('')
    const res = await fetch(`${API_BASE_URL}/faculties`, {
      method: 'POST',
      headers: headers(),
      body: JSON.stringify(form),
    })
    if (res.ok) {
      setForm({ name: '', faculty_type: 'full_time', max_teaching_load: 24 })
      fetchFaculties()
    } else {
      const data = await res.json()
      setError(data.message || 'Failed to create faculty')
    }
  }

  async function handleUpdate(e: React.FormEvent) {
    e.preventDefault()
    if (!editing) return
    setError('')

    const res = await fetch(`${API_BASE_URL}/faculties/${editing}`, {
      method: 'PUT',
      headers: headers(),
      body: JSON.stringify(editForm),
    })
    if (!res.ok) {
      const data = await res.json()
      setError(data.message || 'Failed to update faculty')
      return
    }

    const subRes = await fetch(`${API_BASE_URL}/faculties/${editing}/subjects`, {
      method: 'POST',
      headers: headers(),
      body: JSON.stringify({ subject_ids: selectedSubjectIds }),
    })
    if (!subRes.ok) {
      const data = await subRes.json()
      setError(data.message || 'Failed to update subjects')
      return
    }

    // Save availability
    const availRes = await fetch(`${API_BASE_URL}/faculties/${editing}/availability`, {
      method: 'POST',
      headers: headers(),
      body: JSON.stringify({ availability }),
    })
    if (!availRes.ok) {
      const data = await availRes.json()
      setError(data.message || 'Failed to update availability')
      return
    }

    if (subRes.ok) {
      setEditing(null)
      fetchFaculties()
    }
  }

  async function handleDelete(id: number) {
    if (!confirm('Delete this faculty member? Their qualifications, availability, and schedule sessions will also be removed.')) return
    const res = await fetch(`${API_BASE_URL}/faculties/${id}`, {
      method: 'DELETE',
      headers: headers(),
    })
    if (res.ok) fetchFaculties()
    else {
      const data = await res.json()
      setError(data.message || 'Failed to delete')
    }
  }

  function startEdit(f: Faculty) {
    setEditing(f.id)
    setEditForm({
      name: f.name,
      faculty_type: f.faculty_type,
      max_teaching_load: f.max_teaching_load,
    })
    setSelectedSubjectIds(f.subjects?.map((s) => s.id) ?? [])
    fetchAvailability(f.id)
  }

  function toggleSubject(subjectId: number) {
    setSelectedSubjectIds((prev) =>
      prev.includes(subjectId)
        ? prev.filter((id) => id !== subjectId)
        : [...prev, subjectId]
    )
  }

  return (
    <div className="min-h-screen bg-gray-100 p-8">
      <div className="max-w-5xl mx-auto">
        <div className="flex justify-between items-center mb-6">
          <h1 className="text-2xl font-bold text-gray-800">Faculty Management</h1>
          <a href="/dashboard" className="text-blue-600 hover:underline">← Back to Dashboard</a>
        </div>

        {/* Create Form */}
        <div className="bg-white rounded-lg shadow-md p-6 mb-6">
          <h2 className="text-lg font-semibold mb-4">Create Faculty Member</h2>
          <p className="text-gray-500 text-sm mb-3">
            Faculty are records, not login accounts — only the Admin logs into the system.
          </p>
          {error && <p className="text-red-600 text-sm mb-3">{error}</p>}
          <form onSubmit={handleCreate} className="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">Full Name</label>
              <input value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} required
                className="w-full border border-gray-300 rounded-md px-3 py-2" placeholder="Prof. Juan Dela Cruz" />
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">Faculty Type</label>
              <select value={form.faculty_type} onChange={(e) => setForm({ ...form, faculty_type: e.target.value })}
                className="w-full border border-gray-300 rounded-md px-3 py-2">
                <option value="full_time">Full Time</option>
                <option value="part_time">Part Time</option>
              </select>
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">Max Teaching Load (hours)</label>
              <input type="number" value={form.max_teaching_load}
                onChange={(e) => setForm({ ...form, max_teaching_load: parseInt(e.target.value) || 24 })} min={1}
                className="w-full border border-gray-300 rounded-md px-3 py-2" />
            </div>
            <div className="flex items-end">
              <button type="submit" className="bg-purple-600 text-white px-6 py-2 rounded-md hover:bg-purple-700 transition">
                Create Faculty
              </button>
            </div>
          </form>
        </div>

        {/* Edit Form */}
        {editing && (
          <div className="bg-yellow-50 rounded-lg shadow-md p-6 mb-6 border border-yellow-300">
            <h2 className="text-lg font-semibold mb-4">Edit Faculty #{editing}</h2>
            {error && <p className="text-red-600 text-sm mb-3">{error}</p>}
            <form onSubmit={handleUpdate}>
              <div className="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
                <div>
                  <label className="block text-sm font-medium text-gray-700 mb-1">Name</label>
                  <input value={editForm.name} onChange={(e) => setEditForm({ ...editForm, name: e.target.value })} required
                    className="w-full border border-gray-300 rounded-md px-3 py-2" />
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700 mb-1">Faculty Type</label>
                  <select value={editForm.faculty_type} onChange={(e) => setEditForm({ ...editForm, faculty_type: e.target.value })}
                    className="w-full border border-gray-300 rounded-md px-3 py-2">
                    <option value="full_time">Full Time</option>
                    <option value="part_time">Part Time</option>
                  </select>
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700 mb-1">Max Teaching Load</label>
                  <input type="number" value={editForm.max_teaching_load}
                    onChange={(e) => setEditForm({ ...editForm, max_teaching_load: parseInt(e.target.value) || 24 })} min={1}
                    className="w-full border border-gray-300 rounded-md px-3 py-2" />
                </div>
              </div>

              <div className="flex gap-2 mb-4">
                <button type="submit" className="bg-blue-600 text-white px-4 py-2 rounded-md hover:bg-blue-700 transition">Save</button>
                <button type="button" onClick={() => setEditing(null)} className="bg-gray-400 text-white px-4 py-2 rounded-md hover:bg-gray-500 transition">Cancel</button>
              </div>

              {/* Subjects */}
              <div className="border-t border-yellow-200 pt-4 mb-4">
                <h3 className="text-sm font-semibold text-gray-700 mb-2">Subjects (qualifications)</h3>
                <p className="text-xs text-gray-500 mb-3">Check the subjects this faculty member is qualified to teach.</p>
                {allSubjects.length === 0 && <p className="text-xs text-gray-400">No subjects available.</p>}
                <div className="flex flex-wrap gap-3">
                  {allSubjects.map((subject) => (
                    <label key={subject.id} className="flex items-center gap-2 bg-white border border-gray-300 rounded-md px-3 py-2 cursor-pointer hover:bg-gray-50 transition">
                      <input type="checkbox" checked={selectedSubjectIds.includes(subject.id)} onChange={() => toggleSubject(subject.id)} className="rounded border-gray-300 text-blue-600 focus:ring-blue-500" />
                      <span className="text-sm font-mono">{subject.code}</span>
                      <span className="text-sm text-gray-500">— {subject.title}</span>
                    </label>
                  ))}
                </div>
              </div>

              {/* Availability */}
              <div className="border-t border-yellow-200 pt-4">
                <h3 className="text-sm font-semibold text-gray-700 mb-2">Availability (preferred days & times)</h3>
                <p className="text-xs text-gray-500 mb-3">Select which days this faculty member is available and their preferred hours.</p>

                <div className="flex gap-4 mb-3">
                  <div>
                    <label className="block text-xs font-medium text-gray-600 mb-1">Start Time</label>
                    <input type="time" value={availStartTime} onChange={(e) => setAvailStartTime(e.target.value)}
                      className="border border-gray-300 rounded-md px-2 py-1 text-sm" />
                  </div>
                  <div>
                    <label className="block text-xs font-medium text-gray-600 mb-1">End Time</label>
                    <input type="time" value={availEndTime} onChange={(e) => setAvailEndTime(e.target.value)}
                      className="border border-gray-300 rounded-md px-2 py-1 text-sm" />
                  </div>
                </div>

                <div className="flex gap-2 flex-wrap">
                  {ALL_DAYS.map((d) => (
                    <button key={d.val} type="button" onClick={() => toggleAvailDay(d.val)}
                      className={`px-3 py-1.5 rounded-md text-sm transition ${
                        isDaySelected(d.val)
                          ? 'bg-blue-600 text-white'
                          : 'bg-gray-200 text-gray-600 hover:bg-gray-300'
                      }`}>
                      {d.label}
                    </button>
                  ))}
                </div>
                <p className="text-xs text-gray-400 mt-2">
                  {availability.length > 0
                    ? `Selected: ${availability.map((a) => ALL_DAYS[a.day_of_week - 1]?.label).join(', ')}`
                    : 'No days selected — faculty will be available on all section days.'}
                </p>
              </div>
            </form>
          </div>
        )}

        {/* Table */}
        <div className="bg-white rounded-lg shadow-md overflow-hidden">
          <h2 className="text-lg font-semibold text-gray-800 p-4 border-b border-gray-200">All Faculty</h2>
          {loading && <p className="text-gray-500 p-4">Loading...</p>}
          {!loading && (
            <table className="w-full text-left">
              <thead className="bg-gray-800 text-white">
                <tr>
                  <th className="p-3">ID</th>
                  <th className="p-3">Name</th>
                  <th className="p-3">Type</th>
                  <th className="p-3">Max Load</th>
                  <th className="p-3">Subjects</th>
                  <th className="p-3">Actions</th>
                </tr>
              </thead>
              <tbody>
                {faculties.map((f) => (
                  <tr key={f.id} className="border-t border-gray-200">
                    <td className="p-3">{f.id}</td>
                    <td className="p-3">{f.name ?? '—'}</td>
                    <td className="p-3 capitalize">{f.faculty_type}</td>
                    <td className="p-3">{f.max_teaching_load}h</td>
                    <td className="p-3">{f.subjects?.map((s) => s.code).join(', ') ?? '—'}</td>
                    <td className="p-3">
                      <button onClick={() => startEdit(f)} className="text-blue-600 hover:underline text-sm mr-3">Edit</button>
                      <button onClick={() => handleDelete(f.id)} className="text-red-600 hover:underline text-sm">Delete</button>
                    </td>
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
