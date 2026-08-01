import { useEffect, useState } from 'react'
import { useAuth, API_BASE_URL } from '../../context/AuthContext'

type Subject = {
  id: number
  code: string
  title: string
}

type Section = {
  id: number
  name: string
  year_level: number
  academic_year: string
  semester_name: string
  preferred_days: number[]
  preferred_start_time: string
  preferred_end_time: string
  subjects?: Subject[]
}

const emptyForm = {
  name: '', year_level: 1, academic_year: '2026-2027',
  semester_name: '1st Semester', preferred_days: [1, 2, 3, 4, 5],
  preferred_start_time: '07:00', preferred_end_time: '15:00',
}

const ALL_DAYS = [
  { val: 1, label: 'Mon' }, { val: 2, label: 'Tue' }, { val: 3, label: 'Wed' },
  { val: 4, label: 'Thu' }, { val: 5, label: 'Fri' }, { val: 6, label: 'Sat' }, { val: 7, label: 'Sun' },
]

export default function SectionsPage() {
  const { token } = useAuth()
  const [sections, setSections] = useState<Section[]>([])
  const [allSubjects, setAllSubjects] = useState<Subject[]>([])
  const [loading, setLoading] = useState(true)
  const [form, setForm] = useState(emptyForm)
  const [editing, setEditing] = useState<number | null>(null)
  const [error, setError] = useState('')
  const [selectedSubjectIds, setSelectedSubjectIds] = useState<number[]>([])

  function headers() {
    return {
      Authorization: `Bearer ${token}`,
      'Accept': 'application/json',
      'Content-Type': 'application/json',
    }
  }

  async function fetchSections() {
    setLoading(true)
    try {
      const res = await fetch(`${API_BASE_URL}/sections`, { headers: headers() })
      const data = await res.json()
      setSections(Array.isArray(data) ? data : [])
    } catch {
      setError('Failed to load sections')
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
    fetchSections()
    fetchSubjects()
  }, [])

  function toggleDay(day: number) {
    setForm((prev) => {
      const days = prev.preferred_days.includes(day)
        ? prev.preferred_days.filter((d) => d !== day)
        : [...prev.preferred_days, day].sort()
      return { ...prev, preferred_days: days }
    })
  }

  function toggleSubject(subjectId: number) {
    setSelectedSubjectIds((prev) =>
      prev.includes(subjectId)
        ? prev.filter((id) => id !== subjectId)
        : [...prev, subjectId]
    )
  }

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault()
    setError('')

    const body = {
      ...form,
      preferred_start_time: form.preferred_start_time.split(' ')[0],
      preferred_end_time: form.preferred_end_time.split(' ')[0],
      subject_ids: selectedSubjectIds,
    }

    const url = editing ? `${API_BASE_URL}/sections/${editing}` : `${API_BASE_URL}/sections`
    const method = editing ? 'PUT' : 'POST'

    const res = await fetch(url, {
      method,
      headers: headers(),
      body: JSON.stringify(body),
    })
    if (res.ok) {
      setForm(emptyForm)
      setEditing(null)
      setSelectedSubjectIds([])
      fetchSections()
    } else {
      const data = await res.json()
      setError(data.message || JSON.stringify(data.errors) || 'Failed to save section')
    }
  }

  function handleEdit(s: Section) {
    setEditing(s.id)
    setForm({
      name: s.name, year_level: s.year_level, academic_year: s.academic_year,
      semester_name: s.semester_name, preferred_days: s.preferred_days,
      preferred_start_time: s.preferred_start_time.slice(0, 5),
      preferred_end_time: s.preferred_end_time.slice(0, 5),
    })
    setSelectedSubjectIds(s.subjects?.map((sub) => sub.id) ?? [])
  }

  async function handleDelete(id: number) {
    if (!confirm('Delete this section?')) return
    const res = await fetch(`${API_BASE_URL}/sections/${id}`, {
      method: 'DELETE',
      headers: headers(),
    })
    if (res.ok) fetchSections()
    else {
      const data = await res.json()
      setError(data.message || 'Failed to delete')
    }
  }

  const dayLabels = ['', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun']

  return (
    <div className="min-h-screen bg-gray-100 p-8">
      <div className="max-w-5xl mx-auto">
        <div className="flex justify-between items-center mb-6">
          <h1 className="text-2xl font-bold text-gray-800">Sections Management</h1>
          <a href="/dashboard" className="text-blue-600 hover:underline">← Back to Dashboard</a>
        </div>

        <div className="bg-white rounded-lg shadow-md p-6 mb-6">
          <h2 className="text-lg font-semibold mb-4">{editing ? 'Edit Section' : 'Create Section'}</h2>
          {error && <p className="text-red-600 text-sm mb-3">{error}</p>}
          <form onSubmit={handleSubmit} className="space-y-4">
            <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">Name</label>
                <input value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} required
                  className="w-full border border-gray-300 rounded-md px-3 py-2" placeholder="BSIT 1A" />
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">Year Level</label>
                <select value={form.year_level} onChange={(e) => setForm({ ...form, year_level: parseInt(e.target.value) })}
                  className="w-full border border-gray-300 rounded-md px-3 py-2">
                  <option value={1}>1st Year</option><option value={2}>2nd Year</option>
                  <option value={3}>3rd Year</option><option value={4}>4th Year</option>
                </select>
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">Academic Year</label>
                <input value={form.academic_year} onChange={(e) => setForm({ ...form, academic_year: e.target.value })}
                  className="w-full border border-gray-300 rounded-md px-3 py-2" />
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">Semester</label>
                <select value={form.semester_name} onChange={(e) => setForm({ ...form, semester_name: e.target.value })}
                  className="w-full border border-gray-300 rounded-md px-3 py-2">
                  <option>1st Semester</option><option>2nd Semester</option>
                </select>
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">Start Time</label>
                <input type="time" value={form.preferred_start_time}
                  onChange={(e) => setForm({ ...form, preferred_start_time: e.target.value })}
                  className="w-full border border-gray-300 rounded-md px-3 py-2" />
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">End Time</label>
                <input type="time" value={form.preferred_end_time}
                  onChange={(e) => setForm({ ...form, preferred_end_time: e.target.value })}
                  className="w-full border border-gray-300 rounded-md px-3 py-2" />
              </div>
            </div>

            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">Preferred Days</label>
              <div className="flex gap-2 flex-wrap">
                {ALL_DAYS.map((d) => (
                  <button key={d.val} type="button" onClick={() => toggleDay(d.val)}
                    className={`px-3 py-1 rounded-md text-sm transition ${
                      form.preferred_days.includes(d.val)
                        ? 'bg-blue-600 text-white'
                        : 'bg-gray-200 text-gray-600 hover:bg-gray-300'
                    }`}>
                    {d.label}
                  </button>
                ))}
              </div>
            </div>

            {/* ===== NEW: Subject Assignment ===== */}
            <div className="border-t border-gray-200 pt-4">
              <h3 className="text-sm font-semibold text-gray-700 mb-2">Subjects Offered</h3>
              <p className="text-xs text-gray-500 mb-3">Check the subjects this section will take.</p>
              {allSubjects.length === 0 && <p className="text-xs text-gray-400">No subjects available.</p>}
              <div className="flex flex-wrap gap-3">
                {allSubjects.map((subject) => (
                  <label key={subject.id}
                    className="flex items-center gap-2 bg-white border border-gray-300 rounded-md px-3 py-2 cursor-pointer hover:bg-gray-50 transition">
                    <input type="checkbox" checked={selectedSubjectIds.includes(subject.id)}
                      onChange={() => toggleSubject(subject.id)}
                      className="rounded border-gray-300 text-blue-600 focus:ring-blue-500" />
                    <span className="text-sm font-mono">{subject.code}</span>
                    <span className="text-sm text-gray-500">— {subject.title}</span>
                  </label>
                ))}
              </div>
            </div>

            <div className="flex gap-2">
              <button type="submit" className="bg-teal-600 text-white px-4 py-2 rounded-md hover:bg-teal-700 transition">
                {editing ? 'Update' : 'Create'}
              </button>
              {editing && (
                <button type="button" onClick={() => { setEditing(null); setForm(emptyForm); setSelectedSubjectIds([]) }}
                  className="bg-gray-400 text-white px-4 py-2 rounded-md hover:bg-gray-500 transition">
                  Cancel
                </button>
              )}
            </div>
          </form>
        </div>

        <div className="bg-white rounded-lg shadow-md overflow-hidden">
          <h2 className="text-lg font-semibold text-gray-800 p-4 border-b border-gray-200">All Sections</h2>
          {loading && <p className="text-gray-500 p-4">Loading...</p>}
          {!loading && (
            <table className="w-full text-left">
              <thead className="bg-gray-800 text-white">
                <tr>
                  <th className="p-3">Name</th><th className="p-3">Year</th><th className="p-3">Semester</th>
                  <th className="p-3">Days</th><th className="p-3">Time</th><th className="p-3">Subjects</th>
                  <th className="p-3">Actions</th>
                </tr>
              </thead>
              <tbody>
                {sections.map((s) => (
                  <tr key={s.id} className="border-t border-gray-200">
                    <td className="p-3">{s.name}</td><td className="p-3">{s.year_level}</td>
                    <td className="p-3">{s.semester_name}</td>
                    <td className="p-3">{s.preferred_days.map((d) => dayLabels[d]).join(', ')}</td>
                    <td className="p-3">{s.preferred_start_time.slice(0, 5)} – {s.preferred_end_time.slice(0, 5)}</td>
                    <td className="p-3">{s.subjects?.map((sub) => sub.code).join(', ') || '—'}</td>
                    <td className="p-3">
                      <button onClick={() => handleEdit(s)} className="text-blue-600 hover:underline text-sm mr-3">Edit</button>
                      <button onClick={() => handleDelete(s.id)} className="text-red-600 hover:underline text-sm">Delete</button>
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
