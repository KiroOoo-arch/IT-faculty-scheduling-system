import { useEffect, useState } from 'react'
import { useAuth, API_BASE_URL } from '../../context/AuthContext'

type User = {
  id: number
  name: string
  email: string
  role: string
}

export default function UsersPage() {
  const { token } = useAuth()
  const [users, setUsers] = useState<User[]>([])
  const [loading, setLoading] = useState(true)
  const [form, setForm] = useState({ name: '', email: '', password: '', role: 'faculty' })
  const [editing, setEditing] = useState<number | null>(null)
  const [error, setError] = useState('')

  async function fetchUsers() {
    setLoading(true)
    try {
      const res = await fetch(`${API_BASE_URL}/users`, {
        headers: { Authorization: `Bearer ${token}` },
      })
      const data = await res.json()
      setUsers(Array.isArray(data) ? data : [])
    } catch {
      setError('Failed to load users')
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => { fetchUsers() }, [])

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault()
    setError('')

    const body: Record<string, string> = { name: form.name, email: form.email, role: form.role }
    if (form.password) body.password = form.password

    const url = editing ? `${API_BASE_URL}/users/${editing}` : `${API_BASE_URL}/users`
    const method = editing ? 'PUT' : 'POST'

    const res = await fetch(url, {
      method,
      headers: { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json' },
      body: JSON.stringify(body),
    })

    if (res.ok) {
      setForm({ name: '', email: '', password: '', role: 'faculty' })
      setEditing(null)
      fetchUsers()
    } else {
      const data = await res.json()
      setError(data.message || 'Failed to save user')
    }
  }

  function handleEdit(user: User) {
    setEditing(user.id)
    setForm({ name: user.name, email: user.email, password: '', role: user.role })
  }

  async function handleDelete(id: number) {
    if (!confirm('Delete this user?')) return
    await fetch(`${API_BASE_URL}/users/${id}`, {
      method: 'DELETE',
      headers: { Authorization: `Bearer ${token}` },
    })
    fetchUsers()
  }

  function handleCancel() {
    setEditing(null)
    setForm({ name: '', email: '', password: '', role: 'faculty' })
  }

  return (
    <div className="min-h-screen bg-gray-100 p-8">
      <div className="max-w-4xl mx-auto">
        <div className="flex justify-between items-center mb-6">
          <h1 className="text-2xl font-bold text-gray-800">Users Management</h1>
          <a href="/dashboard" className="text-blue-600 hover:underline">← Back to Dashboard</a>
        </div>

        {/* Form */}
        <div className="bg-white rounded-lg shadow-md p-6 mb-6">
          <h2 className="text-lg font-semibold mb-4">{editing ? 'Edit User' : 'Create User'}</h2>
          {error && <p className="text-red-600 text-sm mb-3">{error}</p>}
          <form onSubmit={handleSubmit} className="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">Name</label>
              <input
                value={form.name}
                onChange={(e) => setForm({ ...form, name: e.target.value })}
                required
                className="w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
              />
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">Email</label>
              <input
                type="email"
                value={form.email}
                onChange={(e) => setForm({ ...form, email: e.target.value })}
                required
                className="w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
              />
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">Password {editing && '(leave blank to keep)'}</label>
              <input
                type="password"
                value={form.password}
                onChange={(e) => setForm({ ...form, password: e.target.value })}
                required={!editing}
                className="w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
              />
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">Role</label>
              <select
                value={form.role}
                onChange={(e) => setForm({ ...form, role: e.target.value })}
                className="w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
              >
                <option value="admin">Admin</option>
                <option value="faculty">Faculty</option>
              </select>
            </div>
            <div className="flex gap-2 items-end">
              <button type="submit" className="bg-green-600 text-white px-4 py-2 rounded-md hover:bg-green-700 transition">
                {editing ? 'Update' : 'Create'}
              </button>
              {editing && (
                <button type="button" onClick={handleCancel} className="bg-gray-400 text-white px-4 py-2 rounded-md hover:bg-gray-500 transition">
                  Cancel
                </button>
              )}
            </div>
          </form>
        </div>

        {/* Table */}
        <div className="bg-white rounded-lg shadow-md overflow-hidden">
          <h2 className="text-lg font-semibold text-gray-800 p-4 border-b border-gray-200">All Users</h2>
          {loading && <p className="text-gray-500 p-4">Loading...</p>}
          {!loading && (
            <table className="w-full text-left">
              <thead className="bg-gray-800 text-white">
                <tr>
                  <th className="p-3">ID</th>
                  <th className="p-3">Name</th>
                  <th className="p-3">Email</th>
                  <th className="p-3">Role</th>
                  <th className="p-3">Actions</th>
                </tr>
              </thead>
              <tbody>
                {users.map((u) => (
                  <tr key={u.id} className="border-t border-gray-200">
                    <td className="p-3">{u.id}</td>
                    <td className="p-3">{u.name}</td>
                    <td className="p-3">{u.email}</td>
                    <td className="p-3 capitalize">{u.role}</td>
                    <td className="p-3">
                      <button onClick={() => handleEdit(u)} className="text-blue-600 hover:underline text-sm mr-3">Edit</button>
                      <button onClick={() => handleDelete(u.id)} className="text-red-600 hover:underline text-sm">Delete</button>
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
