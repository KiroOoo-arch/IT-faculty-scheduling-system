import { useEffect, useMemo, useRef, useState } from 'react'
import { useAuth, API_BASE_URL } from '../../context/AuthContext'
import { LAB_ROOM_TYPES } from '../../constants/roomTypes'
import {
  CLASSIFICATION_LABELS,
  SUBJECT_CLASSIFICATIONS,
  classificationOf,
  type SubjectClassification,
} from '../../constants/subjectClassification'

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
  lecture_hours: number
  lab_hours: number
  lab_room_type: string | null
  is_active: boolean
  /**
   * The review outcome: major, minor or unclassified. Stored explicitly and
   * never inferred — a subject that merely happens to have an owner is not
   * treated as a major here, because the API does not treat it as one either
   * unless the classification says so.
   */
  classification: SubjectClassification
  /** The owning department of a major; null for a minor / shared subject. */
  department_id: number | null
  department?: Department | null
  /** Departments authorized to schedule this major. Laravel serialises the
   *  `departmentAccess` relation as `department_access`; both spellings are read
   *  so the page does not depend on the serialisation. */
  department_access?: Department[]
  departmentAccess?: Department[]
}

type FormState = {
  code: string
  title: string
  year_level: number
  semester_name: string
  lecture_hours: number
  lab_hours: number
  lab_room_type: string
  is_active: boolean
  classification: SubjectClassification
  /** '' means no owner chosen yet, matching the empty option in the select. */
  ownerDepartmentId: '' | number
  /**
   * Departments authorized on top of the owner. The owner is added automatically
   * when the form is sent, so it can never be left out of the grant list.
   */
  extraAccessIds: number[]
}

const emptyForm: FormState = {
  code: '',
  title: '',
  year_level: 1,
  semester_name: '1st Semester',
  lecture_hours: 2,
  lab_hours: 0,
  lab_room_type: '',
  is_active: true,
  // Fail closed, matching the column default: a new subject starts unclassified
  // and the administrator picks a review outcome deliberately. Defaulting to
  // minor would make an unreviewed subject schedulable everywhere.
  classification: 'unclassified',
  ownerDepartmentId: '',
  extraAccessIds: [],
}

/** The departments authorized for a subject, whichever spelling the API used. */
function authorizedDepartments(subject: Subject): Department[] {
  return subject.department_access ?? subject.departmentAccess ?? []
}

/** Every reason the API gave, flattened into readable lines. */
function failureLines(data: unknown): string[] {
  if (typeof data !== 'object' || data === null) return []

  const payload = data as { message?: unknown; errors?: Record<string, unknown> }
  const lines: string[] = []

  if (payload.errors && typeof payload.errors === 'object') {
    for (const value of Object.values(payload.errors)) {
      for (const entry of Array.isArray(value) ? value : [value]) {
        if (typeof entry === 'string' && entry.trim() !== '') lines.push(entry)
      }
    }
  }

  if (lines.length === 0 && typeof payload.message === 'string' && payload.message.trim() !== '') {
    lines.push(payload.message)
  }

  return lines.length > 0 ? lines : ['Failed to save subject']
}

const BADGE: Record<SubjectClassification, string> = {
  major: 'bg-blue-100 text-blue-800',
  minor: 'bg-gray-100 text-gray-600',
  unclassified: 'bg-amber-100 text-amber-800',
}

export default function SubjectsPage() {
  const { token } = useAuth()
  const [subjects, setSubjects] = useState<Subject[]>([])
  const [departments, setDepartments] = useState<Department[]>([])
  const [loading, setLoading] = useState(true)
  const [form, setForm] = useState(emptyForm)
  const [editing, setEditing] = useState<number | null>(null)
  /** The row being edited as the server last returned it, for change detection. */
  const [original, setOriginal] = useState<Subject | null>(null)
  const [errors, setErrors] = useState<string[]>([])
  const [notice, setNotice] = useState('')
  const [query, setQuery] = useState('')
  /**
   * The result of a review-list action, shown beside the queue it belongs to.
   * A bulk run reports one line per subject, so `ok` is true only when every
   * subject in the run was classified.
   */
  const [reviewFeedback, setReviewFeedback] = useState<{
    ok: boolean
    lines: string[]
  } | null>(null)
  /** Ids ticked in the review queue, for a bulk action. */
  const [selectedIds, setSelectedIds] = useState<number[]>([])
  /** True while a bulk classification runs, so one cannot be started twice. */
  const [bulkBusy, setBulkBusy] = useState(false)
  /**
   * A "Review Selected as Major" run: the subjects still awaiting a decision,
   * the open one first. Empty when no run is in progress.
   */
  const [majorQueue, setMajorQueue] = useState<number[]>([])
  /** How many subjects the run started with, for the "2 of 3" progress line. */
  const [majorBatchTotal, setMajorBatchTotal] = useState(0)
  /** The create / edit card, so a review action can bring it into view. */
  const formRef = useRef<HTMLDivElement | null>(null)

  const headers = {
    Authorization: `Bearer ${token}`,
    'Accept': 'application/json',
    'Content-Type': 'application/json',
  }

  async function fetchSubjects() {
    setLoading(true)
    try {
      const res = await fetch(`${API_BASE_URL}/subjects`, { headers })
      const data = await res.json()
      setSubjects(Array.isArray(data) ? data : [])
    } catch {
      setErrors(['Failed to load subjects'])
    } finally {
      setLoading(false)
    }
  }

  /** The owning / authorizing vocabulary. Read-only: departments are data, not a screen. */
  async function fetchDepartments() {
    try {
      const res = await fetch(`${API_BASE_URL}/departments`, { headers })
      const data = await res.json()
      setDepartments(Array.isArray(data) ? data : [])
    } catch { /* ignore */ }
  }

  useEffect(() => { fetchSubjects(); fetchDepartments() }, [])

  const isMajor = form.classification === 'major'

  /** The departments the form would authorize: the owner, then the extras. */
  const authorizedIds = useMemo(() => {
    if (!isMajor || form.ownerDepartmentId === '') return []
    return [form.ownerDepartmentId, ...form.extraAccessIds]
      .map(Number)
      .filter((id, index, all) => all.indexOf(id) === index)
  }, [form.extraAccessIds, form.ownerDepartmentId, isMajor])

  /** Everything still unreviewed: the queue this screen exists to empty. */
  const needsReview = useMemo(
    () => subjects.filter((s) => classificationOf(s.classification) === 'unclassified'),
    [subjects],
  )

  /**
   * The ticked subjects, read out of the queue rather than from the ids alone.
   * A subject that has just been classified, or deleted, leaves the selection by
   * leaving the queue — so the count can never include a row the table no longer
   * shows, and no stale id has to be pruned by hand.
   */
  const selectedSubjects = useMemo(
    () => needsReview.filter((s) => selectedIds.includes(s.id)),
    [needsReview, selectedIds],
  )

  const allSelected = needsReview.length > 0 && selectedSubjects.length === needsReview.length

  const counts = useMemo(() => {
    const tally = { major: 0, minor: 0, unclassified: 0 }
    for (const subject of subjects) tally[classificationOf(subject.classification)] += 1
    return tally
  }, [subjects])

  const visibleSubjects = useMemo(() => {
    const needle = query.trim().toLowerCase()
    if (needle === '') return subjects
    return subjects.filter(
      (s) => s.code.toLowerCase().includes(needle) || s.title.toLowerCase().includes(needle),
    )
  }, [query, subjects])

  function toggleSelected(id: number) {
    setSelectedIds((prev) => (prev.includes(id) ? prev.filter((x) => x !== id) : [...prev, id]))
  }

  function toggleSelectAll() {
    setSelectedIds(allSelected ? [] : needsReview.map((s) => s.id))
  }

  function clearSelection() {
    setSelectedIds([])
  }

  function resetForm() {
    setForm(emptyForm)
    setEditing(null)
    setOriginal(null)
    // A refusal or a notice belongs to the edit that produced it, so leaving the
    // form clears it: an error that outlives its form reads as a broken screen.
    setErrors([])
    setNotice('')
  }

  function handleClassification(classification: SubjectClassification) {
    setForm((prev) => ({
      ...prev,
      classification,
      // A minor or unclassified subject has no owner and no grants, so the two
      // are cleared here rather than sent and refused. Switching back to major
      // asks for the owner again.
      ownerDepartmentId: classification === 'major' ? prev.ownerDepartmentId : '',
      extraAccessIds: classification === 'major' ? prev.extraAccessIds : [],
    }))
  }

  function toggleAccess(departmentId: number) {
    setForm((prev) => ({
      ...prev,
      extraAccessIds: prev.extraAccessIds.includes(departmentId)
        ? prev.extraAccessIds.filter((id) => id !== departmentId)
        : [...prev.extraAccessIds, departmentId],
    }))
  }

  /**
   * The classification or owner of an existing subject is changing, which alters
   * which sections may schedule it. The API refuses a change that would strand an
   * existing assignment, so this is advance warning rather than the guard itself.
   */
  function ownershipChangeNotice(): string | null {
    if (!original) return null

    const before = classificationOf(original.classification)
    const classificationChanged = before !== form.classification
    const ownerChanged = isMajor
      ? Number(form.ownerDepartmentId) !== (original.department_id ?? null)
      : original.department_id !== null

    if (!classificationChanged && !ownerChanged) return null

    return `${original.code}: this changes which departments may schedule the subject`
      + (classificationChanged ? ` (${CLASSIFICATION_LABELS[before]} → ${CLASSIFICATION_LABELS[form.classification]})` : '')
      + '. If a section could no longer schedule it, the server refuses the change and names that section. Continue?'
  }

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault()
    setErrors([])
    setNotice('')

    // The same two checks the API makes, so the reason appears next to the form
    // instead of coming back as a raw validation error.
    if (form.lab_hours > 0 && !form.lab_room_type) {
      setErrors(['This subject has lab hours but no Lab Room Type. Choose the type of lab room its lab sessions require, or set Lab Hours to 0.'])
      return
    }
    if (form.lab_hours === 0 && form.lab_room_type) {
      setErrors(['This subject has no lab hours, so Lab Room Type must be "None". Set Lab Hours above 0 if the subject needs a lab.'])
      return
    }
    if (isMajor && form.ownerDepartmentId === '') {
      setErrors(['A major subject needs an owning department. Choose the department that owns this subject, or classify it as minor / shared.'])
      return
    }

    const notice = ownershipChangeNotice()
    if (notice && !confirm(notice)) return

    // The classification is always sent explicitly: omitting it would let the API
    // infer a major from the presence of an owner, which is exactly the inference
    // this screen must not rely on.
    const body = reviewPayload(
      form,
      form.classification,
      // '' is "no owner", which the API reads as null. A minor or an unclassified
      // subject is stored with no owner.
      isMajor ? Number(form.ownerDepartmentId) : null,
      // The owner is included on purpose: the API keeps it in the grant list
      // anyway, and sending it makes the intent visible in the request.
      isMajor ? authorizedIds : [],
    )

    const url = editing
      ? `${API_BASE_URL}/subjects/${editing}`
      : `${API_BASE_URL}/subjects`
    const method = editing ? 'PUT' : 'POST'

    const res = await fetch(url, { method, headers, body: JSON.stringify(body) })
    const data = await res.json().catch(() => ({}))

    if (res.ok) {
      // The subject just saved is the head of a "Review Selected as Major" run,
      // so the run moves on to the next one; any other save leaves it alone.
      const inBatch = editing !== null && majorQueue[0] === editing
      const remaining = inBatch ? majorQueue.slice(1) : []

      // After resetForm, so the confirmation survives clearing the form.
      resetForm()
      setNotice(`${body.code} saved as ${CLASSIFICATION_LABELS[body.classification]}.`)
      fetchSubjects()

      if (inBatch) advanceMajorBatch(remaining)
    } else {
      setErrors(failureLines(data))
    }
  }

  /**
   * The fields a create or update must carry, plus the review outcome being
   * asserted. Written once so the form and the review list cannot disagree about
   * what the API is asked to store, and every field is always sent — the stored
   * row never depends on the server inferring an outcome from what is missing.
   */
  function reviewPayload(
    source: {
      code: string
      title: string
      year_level: number
      semester_name: string
      lecture_hours: number
      lab_hours: number
      lab_room_type: string | null
      is_active: boolean
    },
    classification: SubjectClassification,
    ownerDepartmentId: number | null,
    accessDepartmentIds: number[],
  ) {
    return {
      code: source.code,
      title: source.title,
      year_level: source.year_level,
      semester_name: source.semester_name,
      lecture_hours: source.lecture_hours,
      lab_hours: source.lab_hours,
      lab_room_type: source.lab_room_type || null,
      is_active: source.is_active,
      classification,
      department_id: ownerDepartmentId,
      access_department_ids: accessDepartmentIds,
    }
  }

  /**
   * Put a subject into the form. The classification comes from the record unless
   * the caller is asking for a specific review outcome, which is how the review
   * list opens an unreviewed subject as a Major so its owner can be chosen.
   */
  function loadIntoForm(s: Subject, classificationOverride?: SubjectClassification) {
    // Opening a subject the run is not on ends the run: the administrator has
    // moved on, and the progress strip would otherwise point at a subject that is
    // no longer on screen. openMajorReview re-establishes it straight after.
    if (majorQueue[0] !== s.id) {
      setMajorQueue([])
      setMajorBatchTotal(0)
    }

    setEditing(s.id)
    setOriginal(s)
    setErrors([])
    setNotice('')

    const classification = classificationOverride ?? classificationOf(s.classification)
    const access = authorizedDepartments(s)

    setForm({
      code: s.code,
      title: s.title,
      year_level: s.year_level,
      semester_name: s.semester_name,
      lecture_hours: s.lecture_hours,
      lab_hours: s.lab_hours,
      lab_room_type: s.lab_room_type || '',
      is_active: s.is_active,
      classification,
      ownerDepartmentId: classification === 'major' && s.department_id !== null
        ? s.department_id
        : '',
      // The owner's own grant is shown as the locked owner row, so it is removed
      // from the extras — otherwise it would appear twice.
      extraAccessIds: access
        .map((d) => d.id)
        .filter((id) => classification === 'major' && id !== s.department_id),
    })
  }

  /**
   * Put a subject into the form as a Major, as step `position` of `total` of a
   * "Review Selected as Major" run — `queue` is what remains of the run, the
   * subject being opened first. A queue of one is a subject reviewed on its own.
   */
  function openMajorReview(s: Subject, queue: number[], total: number) {
    loadIntoForm(s, 'major')
    setMajorQueue(queue)
    setMajorBatchTotal(total)
    formRef.current?.scrollIntoView({ behavior: 'smooth', block: 'start' })

    const position = total - queue.length + 1
    setNotice(
      queue.length > 1
        ? `Reviewing ${position} of ${total}: ${s.code} — choose its owning department, tick every `
          + 'department authorized to schedule it, then press Update.'
        : `${s.code}: choose its owning department, tick every department authorized to schedule it, then Update.`,
    )
  }

  /**
   * Reviewing a subject as a major cannot be a single click: the owner and the
   * departments authorized for it are decisions only an administrator can make, so
   * this opens the subject in the form with Major already selected.
   */
  function handleReviewAsMajor(s: Subject) {
    setReviewFeedback(null)
    openMajorReview(s, [], 0)
  }

  /**
   * Move a "Review Selected as Major" run on to its next subject. Called after a
   * subject is saved and when the administrator skips one; a subject that has left
   * the queue in the meantime is passed over rather than opened.
   */
  function advanceMajorBatch(remaining: number[]) {
    const [next, ...rest] = remaining
    const subject = next === undefined ? undefined : subjects.find((s) => s.id === next)

    if (subject === undefined) {
      if (rest.length > 0) {
        advanceMajorBatch(rest)
        return
      }
      setMajorQueue([])
      setMajorBatchTotal(0)
      setNotice((prev) => (prev
        ? `${prev} The review of the selected subjects is finished.`
        : 'The review of the selected subjects is finished.'))
      return
    }

    openMajorReview(subject, remaining, majorBatchTotal)
  }

  /** Leave the subject the run is on unclassified and open the next one. */
  function skipCurrentMajor() {
    const code = subjects.find((s) => s.id === majorQueue[0])?.code
    resetForm()
    advanceMajorBatch(majorQueue.slice(1))
    if (code) {
      setNotice((prev) => (prev
        ? `${code} was left unclassified. ${prev}`
        : `${code} was left unclassified — it is still in the queue below.`))
    }
  }

  /** Abandon the run; the subjects it has not reached stay in the queue. */
  function stopMajorBatch() {
    setMajorQueue([])
    setMajorBatchTotal(0)
  }

  /**
   * Save one subject as Minor / shared through the ordinary subject endpoint — the
   * same contract the form uses, so no bulk endpoint is needed and every backend
   * rule (lab consistency, the ownership rules, the stranded-section check) still
   * applies to each subject exactly as if it had been edited on its own. Returns
   * the failure lines, or null when the subject was saved.
   */
  async function saveAsMinor(s: Subject): Promise<string[] | null> {
    try {
      const res = await fetch(`${API_BASE_URL}/subjects/${s.id}`, {
        method: 'PUT',
        headers,
        body: JSON.stringify(reviewPayload(s, 'minor', null, [])),
      })

      if (res.ok) return null

      const data = await res.json().catch(() => ({}))
      return failureLines(data)
    } catch {
      return ['the server could not be reached, so nothing was changed.']
    }
  }

  /**
   * Reviewing a subject as minor / shared needs no other decision — no owner, no
   * grants, and the API clears any it held — so it is one deliberate click from
   * the queue instead of a trip through the form. It is also the permissive
   * outcome, so the button says exactly what it will do.
   */
  async function handleReviewAsMinor(s: Subject) {
    setReviewFeedback(null)
    setErrors([])

    const failure = await saveAsMinor(s)

    if (failure === null) {
      setReviewFeedback({
        ok: true,
        lines: [`${s.code} is now Minor / shared — every department may schedule it.`],
      })
      if (editing === s.id) resetForm()
      fetchSubjects()
    } else {
      setReviewFeedback({ ok: false, lines: [`${s.code} was not changed: ${failure.join(' ')}`] })
    }
  }

  /**
   * Classify every ticked subject as Minor / shared, one request per subject, in
   * the order the queue shows them. Each subject is reported separately: a run
   * that half succeeds says so, names the subjects that were refused, and leaves
   * exactly those ticked, rather than reporting the whole run as done.
   */
  async function handleBulkMinor() {
    const targets = selectedSubjects
    if (targets.length === 0 || bulkBusy) return

    const confirmed = confirm(
      `Classify ${targets.length} subject${targets.length === 1 ? '' : 's'} as Minor / shared?\n\n`
      + `${targets.map((s) => s.code).join(', ')}\n\n`
      + 'Every department will be able to schedule them. Any owning department and authorization grants '
      + 'they hold are cleared.',
    )
    if (!confirmed) return

    setBulkBusy(true)
    setReviewFeedback(null)
    setErrors([])

    const lines: string[] = []
    const failed: number[] = []

    // Sequential on purpose: one refusal at a time keeps the order the queue shows
    // and keeps a partial failure to a single subject's reason.
    for (const subject of targets) {
      const failure = await saveAsMinor(subject)

      if (failure === null) {
        lines.push(`${subject.code} — now Minor / shared.`)
      } else {
        failed.push(subject.id)
        lines.push(`${subject.code} — not changed: ${failure.join(' ')}`)
      }
    }

    const succeeded = targets.length - failed.length
    const summary = failed.length === 0
      ? `Classified ${succeeded} subject${succeeded === 1 ? '' : 's'} as Minor / shared.`
      : `Classified ${succeeded} of ${targets.length} subjects. ${failed.length} could not be classified and `
        + `${failed.length === 1 ? 'is' : 'are'} still unclassified — ${failed.length === 1 ? 'it is' : 'they are'} `
        + 'still ticked below.'

    if (editing !== null && targets.some((s) => s.id === editing)) resetForm()

    setReviewFeedback({ ok: failed.length === 0, lines: [summary, ...lines] })
    // Only the refused subjects stay ticked, so a second attempt is exactly the
    // work that is left.
    setSelectedIds(failed)
    setBulkBusy(false)
    await fetchSubjects()
  }

  /**
   * Review the ticked subjects as Majors. A major cannot be one click — it needs
   * an owner and the departments authorized for it — so the selection becomes a
   * run through the form, one subject at a time, and nothing is saved until the
   * administrator presses Update for that subject.
   */
  function handleBulkMajor() {
    const targets = selectedSubjects
    if (targets.length === 0 || bulkBusy) return

    const confirmed = confirm(
      `Review ${targets.length} subject${targets.length === 1 ? '' : 's'} as Major?\n\n`
      + `${targets.map((s) => s.code).join(', ')}\n\n`
      + 'You will be asked for an owning department and the departments authorized to schedule each one, '
      + 'in turn. Nothing is saved until you press Update for that subject.',
    )
    if (!confirmed) return

    setReviewFeedback(null)
    setErrors([])
    clearSelection()
    // The selection becomes the run, so ticking is not a second thing to undo
    // while the administrator works through it.
    openMajorReview(targets[0], targets.map((s) => s.id), targets.length)
  }

  async function handleDelete(id: number) {
    if (!confirm('Delete this subject? Its schedule sessions, faculty assignments, and section links will also be removed.')) return
    let res = await fetch(`${API_BASE_URL}/subjects/${id}`, {
      method: 'DELETE',
      headers,
    })
    // A published schedule still uses this subject: surface what would be lost
    // and only proceed after an explicit second confirmation.
    if (res.status === 409) {
      const data = await res.json()
      if (!confirm(`${data.message}\n\nDelete anyway?`)) return
      res = await fetch(`${API_BASE_URL}/subjects/${id}?force=1`, {
        method: 'DELETE',
        headers,
      })
    }
    if (res.ok) {
      if (editing === id) resetForm()
      fetchSubjects()
    } else {
      const data = await res.json().catch(() => ({}))
      setErrors(failureLines(data))
    }
  }

  /** One line describing who may schedule the subject, per its classification. */
  function ownershipNote(s: Subject): string {
    const classification = classificationOf(s.classification)

    if (classification === 'minor') return 'Available to every department — no owner, no grants.'
    if (classification === 'unclassified') return 'Cannot be scheduled or generated until it is classified.'

    const authorized = authorizedDepartments(s)
    const owner = s.department?.name ?? (s.department_id === null ? 'an unknown department' : `department #${s.department_id}`)

    if (authorized.length === 0) {
      return `Owned by ${owner}. No authorization rows — the owner cannot schedule it either.`
    }

    return `Owned by ${owner}. Authorized for ${authorized.map((d) => d.name).join(', ')}.`
  }

  const ownerDepartment = departments.find((d) => d.id === form.ownerDepartmentId) ?? null
  const otherDepartments = departments.filter((d) => d.id !== form.ownerDepartmentId)

  return (
    <div className="min-h-screen bg-gray-100 p-8">
      <div className="max-w-5xl mx-auto">
        <div className="flex justify-between items-center mb-6">
          <h1 className="text-2xl font-bold text-gray-800">Subjects Management</h1>
          <a href="/dashboard" className="btn-navy-outline">← Back to Dashboard</a>
        </div>

        <div className="bg-white rounded-lg shadow-md p-6 mb-6" ref={formRef}>
          <h2 className="text-lg font-semibold mb-1">
            {editing ? `Edit Subject — ${form.code || `#${editing}`}` : 'Create Subject'}
          </h2>
          <p className="text-xs text-gray-500 mb-4">
            Classification decides who may schedule the subject. It is stored explicitly — it is never deduced
            from whether an owner is set.
          </p>

          {errors.length > 0 && (
            <div className="bg-red-50 border border-red-200 text-red-700 text-sm rounded-md p-3 mb-4">
              {errors.length === 1
                ? errors[0]
                : (
                  <ul className="list-disc list-inside space-y-1">
                    {errors.map((line) => <li key={line}>{line}</li>)}
                  </ul>
                )}
            </div>
          )}

          {notice && errors.length === 0 && (
            <div className="bg-green-50 border border-green-200 text-green-700 text-sm rounded-md p-3 mb-4">
              {notice}
            </div>
          )}

          {majorQueue.length > 0 && (
            <div className="bg-blue-50 border border-blue-200 text-blue-900 text-sm rounded-md p-3 mb-4 flex flex-wrap items-center gap-3">
              <span>
                <span className="font-medium">Reviewing selected subjects as Major.</span>{' '}
                {majorBatchTotal - majorQueue.length + 1} of {majorBatchTotal} — {majorQueue.length} left,
                including this one. Nothing is saved until you press Update.
              </span>
              <div className="flex flex-wrap gap-2 ml-auto">
                <button
                  type="button"
                  onClick={skipCurrentMajor}
                  className="border border-blue-300 bg-white text-blue-800 text-sm px-3 py-1.5 rounded-md hover:bg-blue-100 transition"
                >
                  Skip this subject
                </button>
                <button
                  type="button"
                  onClick={stopMajorBatch}
                  className="border border-blue-300 bg-white text-blue-800 text-sm px-3 py-1.5 rounded-md hover:bg-blue-100 transition"
                >
                  Stop this run
                </button>
              </div>
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
                {LAB_ROOM_TYPES.map((t) => (
                  <option key={t.value} value={t.value}>{t.label}</option>
                ))}
              </select>
            </div>

            <div className="md:col-span-3">
              <label className="block text-sm font-medium text-gray-700 mb-1">Classification</label>
              <div className="flex flex-wrap gap-2">
                {SUBJECT_CLASSIFICATIONS.map((option) => (
                  <button
                    key={option.value}
                    type="button"
                    onClick={() => handleClassification(option.value)}
                    aria-pressed={form.classification === option.value}
                    className={`px-3 py-2 rounded-md text-sm border transition ${
                      form.classification === option.value
                        ? 'bg-blue-600 border-blue-600 text-white'
                        : 'bg-white border-gray-300 text-gray-700 hover:bg-gray-50'
                    }`}
                  >
                    {option.label}
                  </button>
                ))}
              </div>
              <p className="text-xs text-gray-400 mt-1">
                {SUBJECT_CLASSIFICATIONS.find((o) => o.value === form.classification)?.hint}
              </p>
            </div>

            {isMajor && (
              <>
                <div className="md:col-span-3 border-t border-gray-200 pt-4">
                  <label className="block text-sm font-medium text-gray-700 mb-1">Owning Department</label>
                  <select
                    value={form.ownerDepartmentId}
                    onChange={(e) => setForm({
                      ...form,
                      ownerDepartmentId: e.target.value === '' ? '' : Number(e.target.value),
                    })}
                    className="w-full border border-gray-300 rounded-md px-3 py-2"
                  >
                    <option value="">— choose the owning department —</option>
                    {departments.map((d) => (
                      <option key={d.id} value={d.id}>{d.code} — {d.name}</option>
                    ))}
                  </select>
                  <p className="text-xs text-gray-400 mt-1">
                    A major belongs to exactly one department. Move a subject between departments while a
                    section still holds it and the API refuses the change rather than stranding that section.
                  </p>
                </div>

                <div className="md:col-span-3">
                  <span className="block text-sm font-medium text-gray-700 mb-1">Authorized Departments</span>
                  <p className="text-xs text-gray-500 mb-2">
                    Departments allowed to schedule this major into their own sections. The owner is always
                    authorized — it is included automatically and cannot be removed.
                  </p>

                  <div className="flex flex-wrap gap-2">
                    <span className="flex items-center gap-2 bg-blue-50 border border-blue-300 rounded-md px-3 py-2">
                      <input type="checkbox" checked readOnly disabled className="rounded" />
                      <span className="text-sm text-blue-900">
                        {ownerDepartment ? `${ownerDepartment.code} — ${ownerDepartment.name}` : 'Owner — not chosen yet'}
                      </span>
                      <span className="text-xs bg-blue-200 text-blue-900 rounded-full px-2 py-0.5 whitespace-nowrap">owner</span>
                    </span>

                    {otherDepartments.map((d) => (
                      <label
                        key={d.id}
                        className={`flex items-center gap-2 border rounded-md px-3 py-2 cursor-pointer transition ${
                          form.extraAccessIds.includes(d.id)
                            ? 'bg-blue-50 border-blue-300'
                            : 'bg-white border-gray-300 hover:bg-gray-50'
                        }`}
                      >
                        <input
                          type="checkbox"
                          checked={form.extraAccessIds.includes(d.id)}
                          onChange={() => toggleAccess(d.id)}
                          className="rounded border-gray-300 text-blue-600 focus:ring-blue-500"
                        />
                        <span className="text-sm text-gray-700">{d.code} — {d.name}</span>
                      </label>
                    ))}
                  </div>

                  <p className="text-xs text-gray-400 mt-2">
                    {form.ownerDepartmentId === ''
                      ? 'Choose an owning department first — a major with no owner cannot be saved.'
                      : `Will be authorized for ${authorizedIds.length} department${authorizedIds.length === 1 ? '' : 's'}: `
                        + departments.filter((d) => authorizedIds.includes(d.id)).map((d) => d.name).join(', ')
                        + (authorizedIds.length === 1
                          ? '. Tick another department to authorize it as well — a major may be shared, as ELEC201 is.'
                          : '.')}
                  </p>
                </div>
              </>
            )}

            {form.classification === 'minor' && (
              <div className="md:col-span-3 border-t border-gray-200 pt-4">
                <div className="bg-gray-50 border border-gray-200 rounded-md p-3 text-sm text-gray-600">
                  <span className="font-medium text-gray-700">Minor / shared subject.</span> It is open to every
                  department under the shared-subject policy. It gets no owning department and no authorization
                  rows — either would imply a decision that was not made.
                </div>
              </div>
            )}

            {form.classification === 'unclassified' && (
              <div className="md:col-span-3 border-t border-gray-200 pt-4">
                <div className="bg-amber-50 border border-amber-200 rounded-md p-3 text-sm text-amber-800">
                  <span className="font-medium">Not yet reviewed.</span> An unclassified subject cannot be
                  assigned to a section or generated into a schedule. Sections that try are refused by the API
                  and by the engine.
                </div>
              </div>
            )}

            <div className="flex gap-2 items-end md:col-span-3">
              <button
                type="submit"
                className="bg-orange-600 text-white px-4 py-2 rounded-md hover:bg-orange-700 transition"
              >
                {editing ? 'Update' : 'Create'}
              </button>
              {editing && (
                <button
                  type="button"
                  onClick={resetForm}
                  className="bg-gray-400 text-white px-4 py-2 rounded-md hover:bg-gray-500 transition"
                >
                  Cancel
                </button>
              )}
            </div>
          </form>
        </div>

        {/* ===== The review queue: what is still unreviewed, with the decision beside it ===== */}
        <div className="bg-white rounded-lg shadow-md overflow-hidden mb-6">
          <div className="p-4 border-b border-amber-200 bg-amber-50">
            <div className="flex flex-wrap items-center justify-between gap-3">
              <h2 className="text-lg font-semibold text-gray-800">Subjects Needing Review</h2>
              <span
                className={`text-xs rounded-full px-2 py-0.5 whitespace-nowrap ${
                  needsReview.length === 0 ? 'bg-green-100 text-green-800' : 'bg-amber-200 text-amber-900'
                }`}
              >
                {needsReview.length === 0 ? 'nothing outstanding' : `${needsReview.length} unclassified`}
              </span>
            </div>
            <p className="text-xs text-gray-600 mt-1">
              Unclassified means nobody has reviewed the subject yet, so it cannot be assigned to a section or
              generated into a schedule. Reviewing it is the whole decision: Minor / shared opens it to every
              department, Major asks for the department that owns it and the departments authorized for it.
            </p>
          </div>

          {!loading && needsReview.length > 0 && (
            <div className="px-4 py-3 border-b border-gray-200 bg-gray-50 flex flex-wrap items-center gap-3">
              <div className="flex flex-wrap items-center gap-3">
                <label className="flex items-center gap-2 text-sm text-gray-700">
                  <input
                    type="checkbox"
                    checked={allSelected}
                    onChange={toggleSelectAll}
                    disabled={bulkBusy}
                    aria-label="Select every subject needing review"
                    className="rounded border-gray-300 text-blue-600 focus:ring-blue-500"
                  />
                  Select all
                </label>
                <span
                  className={`text-xs rounded-full px-2 py-0.5 whitespace-nowrap ${
                    selectedSubjects.length === 0 ? 'bg-gray-200 text-gray-600' : 'bg-blue-100 text-blue-800'
                  }`}
                >
                  {selectedSubjects.length} selected
                </span>
              </div>

              <div className="flex flex-wrap items-center gap-2 ml-auto">
                <button
                  type="button"
                  onClick={() => void handleBulkMinor()}
                  disabled={selectedSubjects.length === 0 || bulkBusy}
                  className="bg-gray-700 text-white text-sm px-3 py-2 rounded-md hover:bg-gray-800 transition disabled:opacity-50 disabled:cursor-not-allowed"
                >
                  {bulkBusy ? 'Classifying…' : 'Classify Selected as Minor / Shared'}
                </button>
                <button
                  type="button"
                  onClick={handleBulkMajor}
                  disabled={selectedSubjects.length === 0 || bulkBusy}
                  className="bg-blue-600 text-white text-sm px-3 py-2 rounded-md hover:bg-blue-700 transition disabled:opacity-50 disabled:cursor-not-allowed"
                >
                  Review Selected as Major…
                </button>
                <button
                  type="button"
                  onClick={clearSelection}
                  disabled={selectedSubjects.length === 0 || bulkBusy}
                  className="border border-gray-300 text-gray-700 text-sm px-3 py-2 rounded-md hover:bg-gray-100 transition disabled:opacity-50 disabled:cursor-not-allowed"
                >
                  Clear selection
                </button>
              </div>
            </div>
          )}

          {reviewFeedback !== null && (
            <div
              className={`px-4 py-2 text-sm border-b ${
                reviewFeedback.ok
                  ? 'bg-green-50 border-green-200 text-green-700'
                  : 'bg-red-50 border-red-200 text-red-700'
              }`}
            >
              {reviewFeedback.lines.length === 1
                ? reviewFeedback.lines[0]
                : (
                  <ul className="list-disc list-inside space-y-1">
                    {reviewFeedback.lines.map((line) => <li key={line}>{line}</li>)}
                  </ul>
                )}
            </div>
          )}

          {loading && <p className="text-gray-500 p-4">Loading...</p>}

          {!loading && needsReview.length === 0 && (
            <p className="p-4 text-sm text-green-700">
              Nothing to review — every subject carries a review outcome.
            </p>
          )}

          {!loading && needsReview.length > 0 && (
            <table className="w-full text-left">
              <thead className="bg-gray-100 text-gray-600">
                <tr>
                  <th className="p-3 w-10">
                    <input
                      type="checkbox"
                      checked={allSelected}
                      onChange={toggleSelectAll}
                      disabled={bulkBusy}
                      aria-label="Select every subject needing review"
                      className="rounded border-gray-300 text-blue-600 focus:ring-blue-500"
                    />
                  </th>
                  <th className="p-3">Code</th>
                  <th className="p-3">Title</th>
                  <th className="p-3">Year</th>
                  <th className="p-3">Semester</th>
                  <th className="p-3">Classification</th>
                  <th className="p-3">Review</th>
                </tr>
              </thead>
              <tbody>
                {needsReview.map((s) => (
                  <tr key={s.id} className="border-t border-gray-200 align-top">
                    <td className="p-3">
                      <input
                        type="checkbox"
                        checked={selectedIds.includes(s.id)}
                        onChange={() => toggleSelected(s.id)}
                        disabled={bulkBusy}
                        aria-label={`Select ${s.code} for review`}
                        className="rounded border-gray-300 text-blue-600 focus:ring-blue-500"
                      />
                    </td>
                    <td className="p-3 font-mono">{s.code}</td>
                    <td className="p-3">{s.title}</td>
                    <td className="p-3">{s.year_level}</td>
                    <td className="p-3">{s.semester_name}</td>
                    <td className="p-3">
                      <span className={`text-xs rounded-full px-2 py-0.5 whitespace-nowrap ${BADGE.unclassified}`}>
                        {CLASSIFICATION_LABELS.unclassified}
                      </span>
                    </td>
                    <td className="p-3">
                      <button
                        onClick={() => handleReviewAsMajor(s)}
                        className="text-blue-600 hover:underline text-sm mr-3"
                      >
                        Classify as Major…
                      </button>
                      <button
                        onClick={() => void handleReviewAsMinor(s)}
                        className="text-gray-700 hover:underline text-sm"
                      >
                        Classify as Minor / Shared
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </div>

        <div className="bg-white rounded-lg shadow-md overflow-hidden">
          <div className="p-4 border-b border-gray-200 flex flex-wrap items-center justify-between gap-3">
            <div>
              <h2 className="text-lg font-semibold text-gray-800">All Subjects</h2>
              <p className="text-xs text-gray-500 mt-1">
                {counts.major} major · {counts.minor} minor / shared · {counts.unclassified} unclassified
              </p>
            </div>
            <input
              value={query}
              onChange={(e) => setQuery(e.target.value)}
              placeholder="Search code or title"
              aria-label="Search subjects"
              className="border border-gray-300 rounded-md px-3 py-2 text-sm w-64 focus:outline-none focus:ring-2 focus:ring-blue-500"
            />
          </div>

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
                  <th className="p-3">Classification</th>
                  <th className="p-3">Actions</th>
                </tr>
              </thead>
              <tbody>
                {visibleSubjects.map((s) => {
                  const classification = classificationOf(s.classification)
                  return (
                    <tr key={s.id} className="border-t border-gray-200 align-top">
                      <td className="p-3 font-mono">{s.code}</td>
                      <td className="p-3">{s.title}</td>
                      <td className="p-3">{s.year_level}</td>
                      <td className="p-3">{s.semester_name}</td>
                      <td className="p-3">{s.lecture_hours}</td>
                      <td className="p-3">{s.lab_hours}</td>
                      <td className="p-3">
                        <span
                          className={`text-xs rounded-full px-2 py-0.5 whitespace-nowrap ${BADGE[classification]}`}
                        >
                          {CLASSIFICATION_LABELS[classification]}
                        </span>
                        <p className="text-xs text-gray-500 mt-1 max-w-xs">{ownershipNote(s)}</p>
                      </td>
                      <td className="p-3">
                        <button
                          onClick={() => loadIntoForm(s)}
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
                  )
                })}
                {visibleSubjects.length === 0 && (
                  <tr className="border-t border-gray-200">
                    <td className="p-3 text-sm text-gray-500" colSpan={8}>
                      {subjects.length === 0 ? 'No subjects yet.' : `No subject matches “${query}”.`}
                    </td>
                  </tr>
                )}
              </tbody>
            </table>
          )}
        </div>
      </div>
    </div>
  )
}
