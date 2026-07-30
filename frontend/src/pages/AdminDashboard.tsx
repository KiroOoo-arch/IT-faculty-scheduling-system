import { useEffect, useState } from 'react'
import { useAuth, API_BASE_URL } from '../context/AuthContext'

type Faculty = {
  id: number
  faculty_type: string
  user: {
    name: string
    email: string
  }
  subjects: {
    code: string
    title: string
  }[]
}

export default function AdminDashboard() {
  const { user, token, logout } = useAuth()

  const [faculties, setFaculties] = useState<Faculty[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [generating, setGenerating] = useState(false)
  const [generateResult, setGenerateResult] = useState('')

  useEffect(() => {
    async function fetchFaculties() {
      try {
        const response = await fetch(`${API_BASE_URL}/faculties`, {
          headers: {
            Authorization: `Bearer ${token}`,
          },
        })

        if (!response.ok) {
          throw new Error('Failed to load faculty list')
        }

        const data = await response.json()
        setFaculties(data)
      } catch (err) {
        setError(
          err instanceof Error ? err.message : 'Something went wrong'
        )
      } finally {
        setLoading(false)
      }
    }

    fetchFaculties()
  }, [token])

  async function handleGenerate(sectionId: number) {
    setGenerating(true)
    setGenerateResult('')

    try {
      const response = await fetch(
        `${API_BASE_URL}/schedules/generate/${sectionId}`,
        {
          method: 'POST',
          headers: {
            Authorization: `Bearer ${token}`,
          },
        }
      )

      const data = await response.json()

      if (!response.ok) {
        throw new Error(data.message || 'Generation failed')
      }

      setGenerateResult(
        `Success — status: ${data.status}, ${
          data.sessions?.length ?? 0
        } sessions created.`
      )
    } catch (err) {
      setGenerateResult(
        err instanceof Error
          ? `Error: ${err.message}`
          : 'Something went wrong'
      )
    } finally {
      setGenerating(false)
    }
  }

  return (
    <div className="min-h-screen bg-gray-100 p-8">
      <div className="max-w-4xl mx-auto">

        <div className="flex justify-between items-center mb-6">
          <div>
            <h1 className="text-2xl font-bold text-gray-800">
              Welcome, {user?.name}
            </h1>

            <p className="text-gray-500">
              Admin Dashboard
            </p>
          </div>

          <div className="flex gap-2">

            <a
              href="/admin/rooms"
              className="bg-blue-600 text-white px-4 py-2 rounded-md hover:bg-blue-700 transition"
            >
              Manage Rooms
            </a>

            <button
              onClick={logout}
              className="bg-gray-800 text-white px-4 py-2 rounded-md hover:bg-gray-700 transition"
            >
              Log out
            </button>

          </div>
        </div>

        <div className="bg-white rounded-lg shadow-md p-6 mb-6">
          <h2 className="text-lg font-semibold text-gray-800 mb-3">
            Generate Schedule
          </h2>

          <p className="text-gray-500 text-sm mb-3">
            Section: BSIT 1A (id: 1)
          </p>

          <button
            onClick={() => handleGenerate(1)}
            disabled={generating}
            className="bg-blue-600 text-white px-4 py-2 rounded-md hover:bg-blue-700 transition disabled:opacity-50"
          >
            {generating ? 'Generating...' : 'Generate Schedule'}
          </button>

          {generateResult && (
            <p
              className={`mt-3 text-sm ${
                generateResult.startsWith('Error')
                  ? 'text-red-600'
                  : 'text-green-600'
              }`}
            >
              {generateResult}
            </p>
          )}
        </div>

        <div className="bg-white rounded-lg shadow-md overflow-hidden">
          <h2 className="text-lg font-semibold text-gray-800 p-4 border-b border-gray-200">
            Faculty
          </h2>

          {loading && (
            <p className="text-gray-500 p-4">
              Loading faculty...
            </p>
          )}

          {error && (
            <p className="text-red-600 p-4">
              {error}
            </p>
          )}

          {!loading && !error && (
            <table className="w-full text-left">
              <thead className="bg-gray-800 text-white">
                <tr>
                  <th className="p-3">Name</th>
                  <th className="p-3">Type</th>
                  <th className="p-3">Subjects</th>
                </tr>
              </thead>

              <tbody>
                {faculties.map((faculty) => (
                  <tr
                    key={faculty.id}
                    className="border-t border-gray-200"
                  >
                    <td className="p-3">
                      {faculty.user.name}
                    </td>

                    <td className="p-3 capitalize">
                      {faculty.faculty_type}
                    </td>

                    <td className="p-3">
                      {faculty.subjects
                        .map((subject) => subject.code)
                        .join(', ')}
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