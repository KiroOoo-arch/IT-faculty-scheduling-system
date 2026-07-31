import { useEffect, useState } from 'react'
import { useAuth, API_BASE_URL } from '../../context/AuthContext'

type Faculty = {
  id: number
  user_id: number
  faculty_type: string
  max_teaching_load: number
  user?: { id: number; name: string; email: string }
}

export default function FacultyPage() {
  const { token } = useAuth()
  const [faculties, setFaculties] = useState<Faculty[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')

  // Combined form (creates User + Faculty)
  const [form, setForm] = useState({
    name: '', email: '', password: '',
    faculty_type: 'full_time', max_teaching_load: 24,
  })
  const [editing, setEditing] = useState<number | null>(null)
  const [editForm, setEditForm] = useState({
    user_id: 0, faculty_type: 'full_time', max_teaching_load: 24,
  })

  async function fetchFaculties() {
    setLoading(true)
    try {
      const res = await fetch(`${API_BASE_URL}/faculties`, {
        headers: { Authorization: `Bearer ${token}` },
      })
      const data = await res.json()
      setFaculties(Array.isArray(data) ? data : [])
    } catch {
      setError('Failed to load faculties')
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => { fetchFaculties() }, [])

  async function handleCreate(e: React.FormEvent) {
    e.preventDefault()
    setError('')
    const res = await fetch(`${API_BASE_URL}/admin/create-faculty`, {
      method: 'POST',
      headers: { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json' },
      body: JSON.stringify(form),
    })
    if (res.ok) {
      setForm({ name: '', email: '', password: '', faculty_type: 'full_time', max_teaching_load: 24 })
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
      headers: { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json' },
      body: JSON.stringify(editForm),
    })
    if (res.ok) {
      setEditing(null)
      fetchFaculties()
    } else {
      const data = await res.json()
      setError(data.message || 'Failed to update faculty')
    }
  }

  async function handleDelete(id: number) {
    if (!confirm('Delete this faculty member?')) return
    await fetch(`${API_BASE_URL}/faculties/${id}`, {
      method: 'DELETE',
      headers: { Authorization: `Bearer ${token}` },
    })
    fetchFaculties()
  }

  function startEdit(f: Faculty) {
    setEditing(f.id)
    setEditForm({
      user_id: f.user_id,
      faculty_type: f.faculty_type,
      max_teaching_load: f.max_teaching_load,
    })
  }

  return (
    <div className="min-h-screen bg-gray-100 p-8">
      <div className="max-w-4xl mx-auto">
        <div className="flex justify-between items-center mb-6">
          <h1 className="text-2xl font-bold text-gray-800">Faculty Management</h1>
          <a href="/dashboard" className="text-blue-600 hover:underline">← Back to Dashboard</a>
        </div>

        {/* Create Form */}
        <div className="bg-white rounded-lg shadow-md p-6 mb-6">
          <h2 className="text-lg font-semibold mb-4">Create Faculty Member</h2>
          <p className="text-gray-500 text-sm mb-3">Creates both the user account and faculty record in one step.</p>
          {error && <p className="text-red-600 text-sm mb-3">{error}</p>}
          <form onSubmit={handleCreate} className="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">Full Name</label>
              <input
                value={form.name}
                onChange={(e) => setForm({ ...form, name: e.target.value })}
                required
                className="w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
                placeholder="Prof. Juan Dela Cruz"
              />
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">Email (for login)</label>
              <input
                type="email"
                value={form.email}
                onChange={(e) => setForm({ ...form, email: e.target.value })}
                required
                className="w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
                placeholder="cruz@example.com"
              />
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">Password</label>
              <input
                type="password"
                value={form.password}
                onChange={(e) => setForm({ ...form, password: e.target.value })}
                required
                className="w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
              />
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">Faculty Type</label>
              <select
                value={form.faculty_type}
                onChange={(e) => setForm({ ...form, faculty_type: e.target.value })}
                className="w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
              >
                <option value="full_time">Full Time</option>
                <option value="part_time">Part Time</option>
              </select>
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">Max Teaching Load (hours)</label>
              <input
                type="number"
                value={form.max_teaching_load}
                onChange={(e) => setForm({ ...form, max_teaching_load: parseInt(e.target.value) || 24 })}
                min={1}
                className="w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
              />
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
            <form onSubmit={handleUpdate} className="grid grid-cols-1 md:grid-cols-3 gap-4">
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">Faculty Type</label>
                <select
                  value={editForm.faculty_type}
                  onChange={(e) => setEditForm({ ...editForm, faculty_type: e.target.value })}
                  className="w-full border border-gray-300 rounded-md px-3 py-2"
                >
                  <option value="full_time">Full Time</option>
                  <option value="part_time">Part Time</option>
                </select>
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">Max Teaching Load</label>
                <input
                  type="number"
                  value={editForm.max_teaching_load}
                  onChange={(e) => setEditForm({ ...editForm, max_teaching_load: parseInt(e.target.value) || 24 })}
                  min={1}
                  className="w-full border border-gray-300 rounded-md px-3 py-2"
                />
              </div>
              <div className="flex gap-2 items-end">
                <button type="submit" className="bg-blue-600 text-white px-4 py-2 rounded-md hover:bg-blue-700 transition">Save</button>
                <button type="button" onClick={() => setEditing(null)} className="bg-gray-400 text-white px-4 py-2 rounded-md hover:bg-gray-500 transition">Cancel</button>
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
                  <th className="p-3">Email</th>
                  <th className="p-3">Type</th>
                  <th className="p-3">Max Load</th>
                  <th className="p-3">Actions</th>
                </tr>
              </thead>
              <tbody>
                {faculties.map((f) => (
                  <tr key={f.id} className="border-t border-gray-200">
                    <td className="p-3">{f.id}</td>
                    <td className="p-3">{f.user?.name ?? '—'}</td>
                    <td className="p-3">{f.user?.email ?? '—'}</td>
                    <td className="p-3 capitalize">{f.faculty_type}</td>
                    <td className="p-3">{f.max_teaching_load}h</td>
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
