import { useEffect, useState, useCallback } from 'react'
import { useAuth, API_BASE_URL } from '../../context/auth'
import { formatTimeRange } from '../../utils/time'
import { classificationOf } from '../../constants/subjectClassification'

type Department = {
  id: number
  code: string
  name: string
}

type Subject = {
  id: number
  code: string
  title: string
  year_level: number
  semester_name: string
  // null / absent = minor / shared subject any department may take.
  department_id?: number | null
  department?: Department | null
  /** The review outcome. Absent or unrecognised reads as unclassified. */
  classification?: string
  /** Departments authorized to schedule this major (`department_access`). */
  department_access?: Department[]
  departmentAccess?: Department[]
}

/** The departments authorized for a subject, whichever spelling the API used. */
function authorizedIds(subject: Subject): number[] {
  return (subject.department_access ?? subject.departmentAccess ?? []).map((d) => d.id)
}

const YEAR_LABELS = ['', '1st Year', '2nd Year', '3rd Year', '4th Year']

type Section = {
  id: number
  name: string
  year_level: number
  academic_year: string
  semester_name: string
  preferred_days: number[]
  preferred_start_time: string
  preferred_end_time: string
  student_count: number
  department_id: number | null
  department?: Department | null
  subjects?: Subject[]
}

const emptyForm = {
  name: '', year_level: 1, academic_year: '2026-2027',
  semester_name: '1st Semester', preferred_days: [1, 2, 3, 4, 5],
  preferred_start_time: '07:00', preferred_end_time: '15:00',
  student_count: 30,
  // '' means no department, matching the empty option in the select.
  department_id: '',
}

const ALL_DAYS = [
  { val: 1, label: 'Mon' }, { val: 2, label: 'Tue' }, { val: 3, label: 'Wed' },
  { val: 4, label: 'Thu' }, { val: 5, label: 'Fri' }, { val: 6, label: 'Sat' }, { val: 7, label: 'Sun' },
]

export default function SectionsPage() {
  const { token } = useAuth()
  const [sections, setSections] = useState<Section[]>([])
  /** What the server says this selection may schedule — `?department_id=N`. */
  const [subjectOptions, setSubjectOptions] = useState<Subject[]>([])
  /** The subjects the section being edited already holds, from its own record. */
  const [assignedSubjects, setAssignedSubjects] = useState<Subject[]>([])
  const [departments, setDepartments] = useState<Department[]>([])
  const [loading, setLoading] = useState(true)
  const [subjectsLoading, setSubjectsLoading] = useState(false)
  const [subjectsError, setSubjectsError] = useState('')
  const [form, setForm] = useState(emptyForm)
  const [editing, setEditing] = useState<number | null>(null)
  const [error, setError] = useState('')
  const [selectedSubjectIds, setSelectedSubjectIds] = useState<number[]>([])

  const headers = useCallback(function headers() {
    return {
      Authorization: `Bearer ${token}`,
      'Accept': 'application/json',
      'Content-Type': 'application/json',
    }
  }, [token])

  const fetchSections = useCallback(async () => {
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
  }, [headers])

  /**
   * The subject choices for the department currently selected, asked of the server
   * (`GET /api/subjects?department_id=N`) rather than filtered here.
   *
   * The endpoint applies the authorization rule itself — that department's
   * authorized majors plus every minor / shared subject — so the picker cannot
   * drift from what the assignment and generation gates will accept: an
   * unclassified subject, or a major of a department with no grant, is simply not
   * in the response. A section with no department asks with an empty value and is
   * offered minors only.
   */
  const fetchSubjectOptions = useCallback(async (departmentValue: string) => {
    setSubjectsLoading(true)
    setSubjectsError('')

    try {
      const res = await fetch(`${API_BASE_URL}/subjects?department_id=${departmentValue}`, {
        headers: headers(),
      })

      if (!res.ok) {
        throw new Error(`The subjects for this department could not be loaded (HTTP ${res.status}).`)
      }

      const data = await res.json()
      setSubjectOptions(Array.isArray(data) ? data : [])
    } catch (err) {
      setSubjectOptions([])
      setSubjectsError(err instanceof Error ? err.message : 'The subject list could not be loaded.')
    } finally {
      setSubjectsLoading(false)
    }
  }, [headers])

  function reloadSubjectOptions() {
    void fetchSubjectOptions(form.department_id === '' ? '' : String(form.department_id))
  }

  /** The owning-department vocabulary. Read-only: departments are data, not a screen. */
  const fetchDepartments = useCallback(async () => {
    try {
      const res = await fetch(`${API_BASE_URL}/departments`, { headers: headers() })
      const data = await res.json()
      setDepartments(Array.isArray(data) ? data : [])
    } catch { /* ignore */ }
  }, [headers])

  // fetchSections sets its loading flag synchronously, which an effect body
  // must not do during the commit (it would paint an empty table, then the
  // real one). The kickoff waits for a microtask, which still runs before the
  // paint. Both fetchSections and fetchDepartments are stable across renders
  // (useCallback keyed on headers/token), so they can be included here.
  useEffect(() => {
    void Promise.resolve().then(() => {
      void fetchSections()
      void fetchDepartments()
    })
  }, [fetchSections, fetchDepartments])

  /** Re-read the subject choices whenever the department changes. */
  useEffect(() => {
    const departmentValue = form.department_id === '' ? '' : String(form.department_id)
    let current = true

    void Promise.resolve().then(() => {
      if (current) void fetchSubjectOptions(departmentValue)
    })

    return () => {
      current = false
    }
  }, [form.department_id, fetchSubjectOptions])

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

  /**
   * The scheduling rule, mirroring the API: a minor / shared subject is open to
   * every department, a major only to the departments authorized for it (its
   * owner, or a department it was explicitly granted to — ELEC201 for BIT), and
   * an unclassified subject to none. The server is still the authority — this
   * only keeps subjects the API would refuse out of the form.
   */
  function schedulableByFormDepartment(s: Subject): boolean {
    const classification = classificationOf(s.classification)

    if (classification === 'minor') return true
    if (classification !== 'major') return false
    if (form.department_id === '') return false

    return authorizedIds(s).includes(Number(form.department_id))
  }

  /** The department chosen in the form, for readable messages. */
  function formDepartmentName(): string | null {
    return departments.find((d) => String(d.id) === String(form.department_id))?.name ?? null
  }

  /** Why an already-assigned subject is flagged as not matching this section. */
  function mismatchReason(s: Subject): string {
    const classification = classificationOf(s.classification)

    if (classification === 'unclassified') {
      return 'not classified yet — choose Major or Minor / shared'
    }

    if (s.year_level !== form.year_level || s.semester_name !== form.semester_name) {
      return `${YEAR_LABELS[s.year_level] ?? `Year ${s.year_level}`} · ${s.semester_name}`
    }

    const owner = s.department?.name ?? 'another department'

    if (classification === 'major') {
      return form.department_id === ''
        ? `major of ${owner} — this section has no department`
        : `major of ${owner}, not authorized for ${formDepartmentName() ?? 'this department'}`
    }

    return 'shared subject'
  }

  /**
   * The options this form actually offers: the server's scoped list, narrowed to
   * this section's year and semester. `schedulableByFormDepartment` stays as a
   * second, local check — were the response ever stale, the form would still
   * refuse to offer a subject it can see is not permitted here. The server remains
   * the authority either way: it validates every assignment it is sent.
   */
  const matchingSubjects = subjectOptions.filter(
    (s) => s.year_level === form.year_level
      && s.semester_name === form.semester_name
      && schedulableByFormDepartment(s)
  )

  /** The subjects that are checked, from whichever list holds them. */
  const selectedSubjects = selectedSubjectIds
    .map((id) => subjectOptions.find((s) => s.id === id) ?? assignedSubjects.find((s) => s.id === id))
    .filter((s): s is Subject => s !== undefined)

  /**
   * An assignment the current selection no longer covers stays on screen with a
   * warning: the save would be refused for it, and a checkbox that quietly
   * disappeared would give no clue why.
   */
  const mismatchedSubjects = subjectsLoading || subjectsError !== ''
    ? []
    : selectedSubjects.filter((s) => !matchingSubjects.some((m) => m.id === s.id))

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault()
    setError('')

    // Catch the two states the API also rejects, so the reason appears next to
    // the form instead of coming back as a raw validation error.
    if (form.preferred_days.length === 0) {
      setError('Select at least one preferred day — the solver can only place classes on the days this section allows.')
      return
    }
    if (form.preferred_end_time <= form.preferred_start_time) {
      setError('The preferred end time must be later than the preferred start time.')
      return
    }

    const body = {
      ...form,
      preferred_start_time: form.preferred_start_time.split(' ')[0],
      preferred_end_time: form.preferred_end_time.split(' ')[0],
      // '' is the no-department option, which the API reads as null.
      department_id: form.department_id === '' ? null : Number(form.department_id),
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
      setAssignedSubjects([])
      fetchSections()
    } else {
      const data = await res.json()
      setError(data.message || (data.errors ? Object.values(data.errors).flat().join(' ') : '') || 'Failed to save section')
    }
  }

  function handleEdit(s: Section) {
    setEditing(s.id)
    setForm({
      name: s.name, year_level: s.year_level, academic_year: s.academic_year,
      semester_name: s.semester_name, preferred_days: s.preferred_days,
      preferred_start_time: s.preferred_start_time.slice(0, 5),
      preferred_end_time: s.preferred_end_time.slice(0, 5),
      student_count: s.student_count,
      department_id: s.department_id === null ? '' : String(s.department_id),
    })
    setSelectedSubjectIds(s.subjects?.map((sub) => sub.id) ?? [])
    // Held separately from the scoped list so an assignment the rule now excludes
    // is still shown, flagged, rather than silently vanishing from the form.
    setAssignedSubjects(s.subjects ?? [])
  }

  async function handleDelete(id: number) {
    if (!confirm('Delete this section? All of its schedules and schedule sessions will also be removed.')) return
    let res = await fetch(`${API_BASE_URL}/sections/${id}`, {
      method: 'DELETE',
      headers: headers(),
    })
    // A published schedule belongs to this section: name the loss and require a
    // second confirmation before cascading it away.
    if (res.status === 409) {
      const data = await res.json()
      if (!confirm(`${data.message}\n\nDelete anyway?`)) return
      res = await fetch(`${API_BASE_URL}/sections/${id}?force=1`, {
        method: 'DELETE',
        headers: headers(),
      })
    }
    if (res.ok) fetchSections()
    else {
      const data = await res.json().catch(() => ({}))
      setError(data.message || 'Failed to delete')
    }
  }

  const dayLabels = ['', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun']

  return (
    <div className="min-h-screen bg-gray-100 p-8">
      <div className="max-w-5xl mx-auto">
        <div className="flex justify-between items-center mb-6">
          <h1 className="text-2xl font-bold text-gray-800">Sections Management</h1>
          <a href="/dashboard" className="btn-navy-outline">← Back to Dashboard</a>
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
                <label className="block text-sm font-medium text-gray-700 mb-1">Department</label>
                <select value={form.department_id} onChange={(e) => setForm({ ...form, department_id: e.target.value })}
                  className="w-full border border-gray-300 rounded-md px-3 py-2">
                  <option value="">No department (minor subjects only)</option>
                  {departments.map((d) => (
                    <option key={d.id} value={d.id}>{d.name}</option>
                  ))}
                </select>
                <p className="text-xs text-gray-400 mt-1">Decides which major subjects this section may take. Minor / shared subjects are open to every department.</p>
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
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">Students</label>
                <input type="number" min={1} value={form.student_count}
                  onChange={(e) => setForm({ ...form, student_count: e.target.value === '' ? 0 : parseInt(e.target.value) })}
                  className="w-full border border-gray-300 rounded-md px-3 py-2"
                  placeholder="30" />
                <p className="text-xs text-gray-400 mt-1">Used by the solver: a room is only eligible when its capacity is at least this number.</p>
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

            {/* ===== Subject Assignment (chosen by the server for this department) ===== */}
            <div className="border-t border-gray-200 pt-4">
              <h3 className="text-sm font-semibold text-gray-700 mb-2">Subjects Offered</h3>
              <p className="text-xs text-gray-500 mb-1">
                Check the subjects this section will take. Only subjects for
                <span className="font-semibold text-gray-700"> {YEAR_LABELS[form.year_level] ?? `Year ${form.year_level}`}</span> ·
                <span className="font-semibold text-gray-700"> {form.semester_name}</span> that this section's department may take are listed.
              </p>
              <p className="text-xs text-gray-400 mb-3">
                The list comes from the API for the department selected above, so it already excludes anything that department may not schedule —
                another department's major, and every subject still marked Unclassified. Need a different subject? Change the Year Level, Semester or
                Department, or classify the subject on the Subjects page.
              </p>

              {subjectsLoading && (
                <p className="text-xs text-gray-400 mb-3">Loading the subjects this department may take…</p>
              )}

              {subjectsError !== '' && (
                <div className="mb-3 rounded border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-700">
                  {subjectsError}{' '}
                  <button type="button" onClick={reloadSubjectOptions} className="font-medium underline">
                    Try again
                  </button>
                </div>
              )}

              {!subjectsLoading && subjectsError === '' && (
                <>
                  {/* An assignment this selection no longer covers stays visible, flagged. */}
                  {mismatchedSubjects.length > 0 && (
                    <div className="mb-3">
                      <p className="text-xs font-semibold text-amber-700 mb-2">
                        ⚠ Assigned subjects this department may not schedule as set up here (fix or remove):
                      </p>
                      <div className="flex flex-wrap gap-3">
                        {mismatchedSubjects.map((subject) => (
                          <label key={subject.id}
                            className="flex items-center gap-2 bg-amber-50 border border-amber-400 rounded-md px-3 py-2 cursor-pointer hover:bg-amber-100 transition">
                            <input type="checkbox" checked={selectedSubjectIds.includes(subject.id)}
                              onChange={() => toggleSubject(subject.id)}
                              className="rounded border-amber-500 text-amber-600 focus:ring-amber-500" />
                            <span className="text-sm font-mono">{subject.code}</span>
                            <span className="text-sm text-gray-500">— {subject.title}</span>
                            <span className="text-xs bg-amber-200 text-amber-800 rounded-full px-2 py-0.5 whitespace-nowrap">
                              {mismatchReason(subject)} ⚠
                            </span>
                          </label>
                        ))}
                      </div>
                    </div>
                  )}

                  {matchingSubjects.length === 0 ? (
                    mismatchedSubjects.length === 0 && (
                      <p className="text-xs text-gray-400">
                        No subjects available for {YEAR_LABELS[form.year_level] ?? `Year ${form.year_level}`} · {form.semester_name} in this department — add them on the Subjects page, or classify them there as Minor / shared (open to every department) or as a Major authorized for this department. Unclassified subjects cannot be scheduled at all.
                      </p>
                    )
                  ) : (
                    <div className="flex flex-wrap gap-3">
                      {matchingSubjects.map((subject) => (
                        <label key={subject.id}
                          className="flex items-center gap-2 bg-white border border-gray-300 rounded-md px-3 py-2 cursor-pointer hover:bg-gray-50 transition">
                          <input type="checkbox" checked={selectedSubjectIds.includes(subject.id)}
                            onChange={() => toggleSubject(subject.id)}
                            className="rounded border-gray-300 text-blue-600 focus:ring-blue-500" />
                          <span className="text-sm font-mono">{subject.code}</span>
                          <span className="text-sm text-gray-500">— {subject.title}</span>
                          <span className="text-xs bg-gray-100 text-gray-500 rounded-full px-2 py-0.5 whitespace-nowrap">
                            {YEAR_LABELS[subject.year_level] ?? `Year ${subject.year_level}`} · {subject.semester_name}
                          </span>
                          {classificationOf(subject.classification) === 'major'
                            && subject.department_id !== null
                            && Number(subject.department_id) !== Number(form.department_id) && (
                              <span className="text-xs bg-blue-100 text-blue-800 rounded-full px-2 py-0.5 whitespace-nowrap">
                                shared from {subject.department?.name ?? `department #${subject.department_id}`}
                              </span>
                            )}
                        </label>
                      ))}
                    </div>
                  )}
                </>
              )}
            </div>

            <div className="flex gap-2">
              <button type="submit" className="bg-teal-600 text-white px-4 py-2 rounded-md hover:bg-teal-700 transition">
                {editing ? 'Update' : 'Create'}
              </button>
              {editing && (
                <button type="button" onClick={() => { setEditing(null); setForm(emptyForm); setSelectedSubjectIds([]); setAssignedSubjects([]) }}
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
                  <th className="p-3">Days</th><th className="p-3">Time</th><th className="p-3">Students</th><th className="p-3">Department</th><th className="p-3">Subjects</th>
                  <th className="p-3">Actions</th>
                </tr>
              </thead>
              <tbody>
                {sections.map((s) => (
                  <tr key={s.id} className="border-t border-gray-200">
                    <td className="p-3">{s.name}</td><td className="p-3">{s.year_level}</td>
                    <td className="p-3">{s.semester_name}</td>
                    <td className="p-3">{s.preferred_days.map((d) => dayLabels[d]).join(', ')}</td>
                    <td className="p-3">{formatTimeRange(s.preferred_start_time, s.preferred_end_time)}</td>
                    <td className="p-3">{s.student_count}</td>
                    <td className="p-3">{s.department?.name ?? '—'}</td>
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
