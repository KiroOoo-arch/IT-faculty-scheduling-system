import { useEffect, useState, type ReactNode } from 'react'
import { Link } from 'react-router-dom'
import { useAuth } from '../context/AuthContext'
import { PRIVACY_POLICY, TERMS_ACCEPTANCE_POINTS, TERMS_OF_USE } from '../constants/legal'
import {
  fetchAcceptanceStatus,
  fetchLegalMeta,
  formatLegalDate,
  submitAcceptance,
  type AcceptanceStatus,
  type LegalMeta,
} from '../utils/legal'

/**
 * Versioned Terms of Use acceptance, applied at the point in the account
 * lifecycle where the college can actually ask: once the person is signed in,
 * before they use the scheduling functions.
 *
 * The rules this component follows, and why:
 *
 *  - It never blocks signing in and never touches other accounts. An account
 *    that has not accepted simply gets this prompt at first use, which is how
 *    acceptance tracking can be introduced without locking existing users out
 *    of a system they already use.
 *  - The version accepted is the server's current version, sent back to the
 *    server, which validates it. If the college publishes a revision while a
 *    page is open, the old version is refused and the reader is told to reload
 *    rather than being recorded as accepting something they have not read.
 *  - The account is never sent by this component. The server takes it from the
 *    bearer token, so an acceptance can only ever be recorded for the person
 *    making the request.
 *  - Acceptance is explicit: a checkbox the person must tick, and a button they
 *    must press. Signing in is not treated as agreement, and this is agreement
 *    to the Terms of Use only — acknowledging the Privacy Policy is not the same
 *    thing as consenting to processing, and the prompt does not ask for consent.
 *  - If the acceptance check cannot be completed because the API is unreachable,
 *    the page is shown with a visible warning instead of locking the user out of
 *    the scheduling work. The prompt protects transparency, not the servers.
 */

type GateState = 'checking' | 'satisfied' | 'required' | 'unavailable'

/** The one decision this gate makes, in one place. */
function resolve(acceptance: AcceptanceStatus): GateState {
  return acceptance.required && !acceptance.accepted ? 'required' : 'satisfied'
}

function Shell({ children }: { children: ReactNode }) {
  return (
    <div className="min-h-screen bg-slate-100 px-4 py-10 sm:px-6">
      <div className="mx-auto max-w-2xl">
        <p className="text-[11px] font-semibold uppercase tracking-[0.18em] text-[#0a2f9c]">
          Lapu-Lapu City College
        </p>
        <div className="mt-4 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-xl shadow-slate-900/5">
          <div className="h-1.5 bg-[#ffc000]" />
          <div className="px-6 py-7 sm:px-8">{children}</div>
        </div>
      </div>
    </div>
  )
}

export default function TermsAcceptanceGate({ children }: { children: ReactNode }) {
  const { token, logout } = useAuth()
  const [state, setState] = useState<GateState>('checking')
  const [status, setStatus] = useState<AcceptanceStatus | null>(null)
  const [meta, setMeta] = useState<LegalMeta | null>(null)
  const [acknowledged, setAcknowledged] = useState(false)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState('')

  // The check runs on mount and when the token changes. State is written from
  // the promise callbacks, never synchronously in the effect body, and the
  // initial value of `state` is already 'checking' — the state this needs to
  // show while the request is in flight.
  useEffect(() => {
    let cancelled = false

    fetchAcceptanceStatus(token)
      .then((acceptance) => fetchLegalMeta().then((legalMeta) => ({ acceptance, legalMeta })))
      .then(({ acceptance, legalMeta }) => {
        if (cancelled) return
        setStatus(acceptance)
        setMeta(legalMeta)
        setState(resolve(acceptance))
      })
      .catch(() => {
        // Fail open, visibly: an unreachable API must not stop an authorized
        // administrator from working.
        if (!cancelled) setState('unavailable')
      })

    return () => {
      cancelled = true
    }
  }, [token])

  async function handleAccept() {
    if (!status) return
    setSaving(true)
    setError('')
    try {
      await submitAcceptance(token, status.current_version)
      setState('satisfied')
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Your acceptance could not be recorded.')
      // Re-read the status: the most likely cause is that the published version
      // changed under us, and the prompt must then show the new version rather
      // than offer to accept the old one again.
      fetchAcceptanceStatus(token)
        .then((acceptance) => {
          setStatus(acceptance)
          setState(resolve(acceptance))
        })
        .catch(() => {})
    } finally {
      setSaving(false)
    }
  }

  if (state === 'checking') {
    return (
      <Shell>
        <p role="status" className="text-sm text-slate-600">
          Checking your Terms of Use acceptance…
        </p>
      </Shell>
    )
  }

  if (state === 'satisfied') return <>{children}</>

  if (state === 'unavailable') {
    return (
      <>
        <div
          role="status"
          className="border-b-2 border-amber-300 bg-amber-50 px-4 py-2 text-center text-xs text-amber-900"
        >
          Your Terms of Use acceptance could not be checked because the server did not answer. You can
          keep working; the check runs again on the next page load.
        </div>
        {children}
      </>
    )
  }

  const effectiveDate = meta ? meta.terms.effective_date : status?.effective_date ?? null
  const isDraft = meta ? meta.terms.is_draft : null
  const currentVersion = status?.current_version ?? ''
  const previouslyAccepted = status?.latest_accepted ?? null

  return (
    <Shell>
      <h1 className="text-xl font-bold text-slate-900">Terms of Use</h1>
      <p className="mt-2 text-sm leading-relaxed text-slate-600">
        Before you use the scheduling system, please read and accept the Terms of Use. This is a
        one-time step per version: you will be asked again only if the college publishes a revision.
      </p>

      <dl className="mt-4 flex flex-wrap gap-x-8 gap-y-2 text-xs text-slate-600">
        <div className="flex gap-2">
          <dt className="font-semibold uppercase tracking-wide text-slate-500">Version</dt>
          <dd className="font-semibold text-[#0a2f9c]">{currentVersion || '—'}</dd>
        </div>
        <div className="flex gap-2">
          <dt className="font-semibold uppercase tracking-wide text-slate-500">Effective date</dt>
          <dd>{formatLegalDate(effectiveDate)}</dd>
        </div>
      </dl>

      {isDraft && (
        <p className="mt-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-xs font-semibold text-red-800">
          Draft pending college adoption — this text has not been approved by the college yet.
        </p>
      )}

      {/* An acceptance of an older version is called out, so the reason for the
          prompt is obvious rather than looking like a repeat of a step already
          completed. */}
      {previouslyAccepted && !previouslyAccepted.is_current_version && (
        <p className="mt-4 rounded-lg border border-blue-200 bg-blue-50 px-4 py-3 text-xs text-blue-900">
          You accepted version {previouslyAccepted.version} on{' '}
          {formatLegalDate(previouslyAccepted.accepted_at)}. The college has published version{' '}
          {currentVersion}, so your acceptance of the earlier version no longer covers it.
        </p>
      )}

      <h2 className="mt-6 text-[11px] font-semibold uppercase tracking-[0.18em] text-[#0a2f9c]">
        In short
      </h2>
      <ul className="mt-3 space-y-2 pl-5 text-sm leading-relaxed text-slate-700">
        {TERMS_ACCEPTANCE_POINTS.map((point) => (
          <li key={point} className="list-disc marker:text-[#0a2f9c]">
            {point}
          </li>
        ))}
      </ul>
      <p className="mt-3 text-xs leading-relaxed text-slate-500">
        The summary above is not the agreement itself. The full text of both documents is one click
        away, and it is the full text you are accepting.
      </p>

      <div className="mt-4 flex flex-wrap gap-4 text-sm font-semibold">
        <Link
          to={TERMS_OF_USE.path}
          target="_blank"
          rel="noreferrer"
          className="rounded text-[#0a2f9c] underline decoration-[#0a2f9c]/40 underline-offset-2 transition hover:decoration-[#0a2f9c] focus-visible:ring-2 focus-visible:ring-[#0a2f9c]/40"
        >
          Read the full Terms of Use ↗
        </Link>
        <Link
          to={PRIVACY_POLICY.path}
          target="_blank"
          rel="noreferrer"
          className="rounded text-[#0a2f9c] underline decoration-[#0a2f9c]/40 underline-offset-2 transition hover:decoration-[#0a2f9c] focus-visible:ring-2 focus-visible:ring-[#0a2f9c]/40"
        >
          Read the Privacy Policy ↗
        </Link>
      </div>

      <p className="mt-4 text-xs leading-relaxed text-slate-500">
        The Privacy Policy explains how the college handles personal information in this system. It is
        provided for transparency: reading it is not a consent to processing, and the college relies
        on its own legal basis for the scheduling it performs.
      </p>

      {error && (
        <p role="alert" className="mt-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
          {error}
        </p>
      )}

      <div className="mt-6 rounded-lg border border-slate-200 bg-slate-50 px-4 py-4">
        <label className="flex cursor-pointer items-start gap-3 text-sm text-slate-800">
          <input
            type="checkbox"
            checked={acknowledged}
            onChange={(event) => setAcknowledged(event.target.checked)}
            className="mt-0.5 h-4 w-4 flex-none rounded border-slate-400 text-[#0a2f9c] focus-visible:ring-2 focus-visible:ring-[#0a2f9c]/40"
          />
          <span>
            I have read and accept the Terms of Use, version {currentVersion || '—'}.
          </span>
        </label>

        <div className="mt-4 flex flex-wrap items-center gap-3">
          <button
            type="button"
            onClick={handleAccept}
            disabled={!acknowledged || saving}
            className="rounded-lg bg-[#0a2f9c] px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-[#08257d] focus-visible:ring-4 focus-visible:ring-[#0a2f9c]/30 disabled:cursor-not-allowed disabled:opacity-40"
          >
            {saving ? 'Recording…' : 'Accept and continue'}
          </button>
          <button
            type="button"
            onClick={logout}
            className="rounded-lg border border-slate-300 px-4 py-2.5 text-sm font-medium text-slate-700 transition hover:bg-white focus-visible:ring-4 focus-visible:ring-slate-300"
          >
            Sign out
          </button>
        </div>
        <p className="mt-3 text-xs text-slate-500">
          Your acceptance is recorded against your account, the version above, and the date. You can
          read both documents again at any time from the footer of the dashboard.
        </p>
      </div>
    </Shell>
  )
}
