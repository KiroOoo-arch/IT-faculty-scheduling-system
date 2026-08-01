import { useEffect, useState } from 'react'
import { useAuth, API_BASE_URL } from '../../context/AuthContext'

type Subject = {
  id: number
  code: string
  title: string
  year_level: number
  semester_name: string
  lecture_hours: number
  lab_hours: number
  lab_room_type: string | null
  is_active: boolean
}

const emptyForm = {
  code: '',
  title: '',
  year_level: 1,
  semester_name: '1st Semester',
  lecture_hours: 2,
  lab_hours: 0,
  lab_room_type: '',
  is_active: true,
}

export default function SubjectsPage() {
  const { token } = useAuth()
  const [subjects, setSubjects] = useState<Subject[]>([])
  const [loading, setLoading] = useState(true)
  const [form, setForm] = useState(emptyForm)
  const [editing, setEditing] = useState<number | null>(null)
  const [error, setError] = useState('')

  async function fetchSubjects() {
    setLoading(true)
    try {
      const res = await fetch(`${API_BASE_URL}/subjects`, {
        headers: {
          Authorization: `Bearer ${token}`,
          'Accept': 'application/json',           // ← FIXED
        },
      })
      const data = await res.json()
      setSubjects(Array.isArray(data) ? data : [])
    } catch {
      setError('Failed to load subjects')
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => { fetchSubjects() }, [])

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault()
    setError('')

    const body = {
      code: form.code,
      title: form.title,
      year_level: form.year_level,               // ← KEPT: backend expects this
      semester_name: form.semester_name,          // ← KEPT: backend expects this
      lecture_hours: form.lecture_hours,
      lab_hours: form.lab_hours,
      lab_room_type: form.lab_room_type || null,
      is_active: form.is_active,
    }

    const url = editing
      ? `${API_BASE_URL}/subjects/${editing}`
      : `${API_BASE_URL}/subjects`
    const method = editing ? 'PUT' : 'POST'

    const res = await fetch(url, {
      method,
      headers: {
        Authorization: `Bearer ${token}`,
        'Accept': 'application/json',             // ← FIXED: was missing, caused 302
        'Content-Type': 'application/json',
      },
      body: JSON.stringify(body),
    })

    if (res.ok) {
      setForm(emptyForm)
      setEditing(null)
      fetchSubjects()
    } else {
      const data = await res.json()
      setError(data.message || JSON.stringify(data.errors) || 'Failed to save subject')
    }
  }

  function handleEdit(s: Subject) {
    setEditing(s.id)
    setForm({
      code: s.code,
      title: s.title,
      year_level: s.year_level,                   // ← KEPT
      semester_name: s.semester_name,              // ← KEPT
      lecture_hours: s.lecture_hours,
      lab_hours: s.lab_hours,
      lab_room_type: s.lab_room_type || '',
      is_active: s.is_active,
    })
  }

  async function handleDelete(id: number) {
    if (!confirm('Delete this subject?')) return
    const res = await fetch(`${API_BASE_URL}/subjects/${id}`, {
      method: 'DELETE',
      headers: {
        Authorization: `Bearer ${token}`,
        'Accept': 'application/json',             // ← FIXED: for consistency
      },
    })
    if (res.ok) fetchSubjects()
    else {
      const data = await res.json()
      setError(data.message || 'Failed to delete')
    }
  }

  return (
    <div className="min-h-screen bg-gray-100 p-8">
      <div className="max-w-5xl mx-auto">
        <div className="flex justify-between items-center mb-6">
          <h1 className="text-2xl font-bold text-gray-800">Subjects Management</h1>
          <a href="/dashboard" className="text-blue-600 hover:underline">← Back to Dashboard</a>
        </div>

        <div className="bg-white rounded-lg shadow-md p-6 mb-6">
          <h2 className="text-lg font-semibold mb-4">
            {editing ? 'Edit Subject' : 'Create Subject'}
          </h2>

          {error && (
            <div className="bg-red-50 border border-red-200 text-red-700 text-sm rounded-md p-3 mb-4">
              {error}
            </div>
          )}

          <form onSubmit={handleSubmit} className="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">Code</label>
              <input
                value={form.code}
                onChange={(e) => setForm({ ...form, code: e.target.value })}
                required
                className="w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
                placeholder="PROG1"
              />
            </div>

            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">Title</label>
              <input
                value={form.title}
                onChange={(e) => setForm({ ...form, title: e.target.value })}
                required
                className="w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
                placeholder="Programming 1"
              />
            </div>

            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">Year Level</label>
              <select
                value={form.year_level}
                onChange={(e) => setForm({ ...form, year_level: parseInt(e.target.value) })}
                className="w-full border border-gray-300 rounded-md px-3 py-2"
              >
                <option value={1}>1st Year</option>
                <option value={2}>2nd Year</option>
                <option value={3}>3rd Year</option>
                <option value={4}>4th Year</option>
              </select>
            </div>

            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">Semester</label>
              <select
                value={form.semester_name}
                onChange={(e) => setForm({ ...form, semester_name: e.target.value })}
                className="w-full border border-gray-300 rounded-md px-3 py-2"
              >
                <option>1st Semester</option>
                <option>2nd Semester</option>
              </select>
            </div>

            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">Lecture Hours</label>
              <input
                type="number"
                value={form.lecture_hours}
                onChange={(e) => setForm({ ...form, lecture_hours: parseInt(e.target.value) || 0 })}
                min={0}
                className="w-full border border-gray-300 rounded-md px-3 py-2"
              />
            </div>

            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">Lab Hours</label>
              <input
                type="number"
                value={form.lab_hours}
                onChange={(e) => setForm({ ...form, lab_hours: parseInt(e.target.value) || 0 })}
                min={0}
                className="w-full border border-gray-300 rounded-md px-3 py-2"
              />
            </div>

            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">Lab Room Type</label>
              <select
                value={form.lab_room_type}
                onChange={(e) => setForm({ ...form, lab_room_type: e.target.value })}
                className="w-full border border-gray-300 rounded-md px-3 py-2"
              >
                <option value="">None</option>
                <option value="computer_lab">Computer Lab</option>
                <option value="science_lab">Science Lab</option>
              </select>
            </div>

            <div className="flex gap-2 items-end">
              <button
                type="submit"
                className="bg-orange-600 text-white px-4 py-2 rounded-md hover:bg-orange-700 transition"
              >
                {editing ? 'Update' : 'Create'}
              </button>
              {editing && (
                <button
                  type="button"
                  onClick={() => { setEditing(null); setForm(emptyForm) }}
                  className="bg-gray-400 text-white px-4 py-2 rounded-md hover:bg-gray-500 transition"
                >
                  Cancel
                </button>
              )}
            </div>
          </form>
        </div>

        <div className="bg-white rounded-lg shadow-md overflow-hidden">
          <h2 className="text-lg font-semibold text-gray-800 p-4 border-b border-gray-200">
            All Subjects
          </h2>

          {loading && <p className="text-gray-500 p-4">Loading...</p>}

          {!loading && (
            <table className="w-full text-left">
              <thead className="bg-gray-800 text-white">
                <tr>
                  <th className="p-3">Code</th>
                  <th className="p-3">Title</th>
                  <th className="p-3">Year</th>
                  <th className="p-3">Semester</th>
                  <th className="p-3">Lec Hrs</th>
                  <th className="p-3">Lab Hrs</th>
                  <th className="p-3">Actions</th>
                </tr>
              </thead>
              <tbody>
                {subjects.map((s) => (
                  <tr key={s.id} className="border-t border-gray-200">
                    <td className="p-3 font-mono">{s.code}</td>
                    <td className="p-3">{s.title}</td>
                    <td className="p-3">{s.year_level}</td>
                    <td className="p-3">{s.semester_name}</td>
                    <td className="p-3">{s.lecture_hours}</td>
                    <td className="p-3">{s.lab_hours}</td>
                    <td className="p-3">
                      <button
                        onClick={() => handleEdit(s)}
                        className="text-blue-600 hover:underline text-sm mr-3"
                      >
                        Edit
                      </button>
                      <button
                        onClick={() => handleDelete(s.id)}
                        className="text-red-600 hover:underline text-sm"
                      >
                        Delete
                      </button>
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
