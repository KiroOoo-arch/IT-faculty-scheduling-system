import { API_BASE_URL } from '../context/AuthContext'

/**
 * The server's half of the legal documents: which version is current, the
 * effective dates, the privacy contact (usually empty until the college
 * designates one), and the list of facts the college still has to confirm.
 *
 * The wording lives in `constants/legal.ts`. The version deliberately does not:
 * acceptance is validated server-side against the version it reports here, so a
 * stale bundle can never record agreement to a revision that is not in force.
 */
export type LegalDocumentMeta = {
  version: string
  /** Null until the college adopts the document. */
  effective_date: string | null
  is_draft: boolean
}

export type LegalPrivacyContact = {
  configured: boolean
  office: string | null
  name: string | null
  email: string | null
  phone: string | null
  address: string | null
}

export type LegalMeta = {
  organisation: string
  terms: LegalDocumentMeta
  privacy: LegalDocumentMeta
  acceptance: { required: boolean; retention_period: string | null }
  privacy_contact: LegalPrivacyContact
  review_required: string[]
}

export type AcceptanceStatus = {
  required: boolean
  current_version: string
  effective_date: string | null
  accepted: boolean
  accepted_version: string | null
  accepted_at: string | null
  latest_accepted: { version: string; accepted_at: string | null; is_current_version: boolean } | null
}

/**
 * The metadata is the same for every visitor, so the document pages share one
 * request instead of each firing its own. A failure resolves to null rather
 * than throwing: the documents must still be readable if the API is down, and
 * the caller says plainly that the version could not be loaded.
 */
let metaPromise: Promise<LegalMeta | null> | null = null

export function fetchLegalMeta(): Promise<LegalMeta | null> {
  if (!metaPromise) {
    metaPromise = fetch(`${API_BASE_URL}/legal`, { headers: { Accept: 'application/json' } })
      .then((response) => (response.ok ? (response.json() as Promise<LegalMeta>) : null))
      .catch(() => null)
  }
  return metaPromise
}

/** Test/preview seam: forget the cached metadata. */
export function resetLegalMetaCache() {
  metaPromise = null
}

export async function fetchAcceptanceStatus(token: string | null): Promise<AcceptanceStatus> {
  const response = await fetch(`${API_BASE_URL}/terms/acceptance`, {
    headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' },
  })
  if (!response.ok) throw new Error(`Acceptance status could not be read (HTTP ${response.status}).`)
  return response.json() as Promise<AcceptanceStatus>
}

export async function submitAcceptance(token: string | null, termsVersion: string) {
  const response = await fetch(`${API_BASE_URL}/terms/acceptance`, {
    method: 'POST',
    headers: {
      Authorization: `Bearer ${token}`,
      'Content-Type': 'application/json',
      Accept: 'application/json',
    },
    // Only the version is sent. The account is taken from the bearer token on
    // the server, so this request cannot accept on anyone else's behalf.
    body: JSON.stringify({ terms_version: termsVersion }),
  })

  const data = await response.json().catch(() => ({}))

  if (!response.ok) {
    throw new Error(data.message || 'Your acceptance could not be recorded. Please try again.')
  }

  return data as { accepted: boolean; terms_version: string; accepted_at: string | null }
}

/** Formats an ISO date (or null) as a readable label. */
export function formatLegalDate(value: string | null): string {
  if (!value) return 'not yet set'
  const parsed = new Date(value)
  if (Number.isNaN(parsed.getTime())) return value
  return parsed.toLocaleDateString(undefined, { year: 'numeric', month: 'long', day: 'numeric' })
}

/** The contact lines that are actually configured, ready to render. */
export function contactLines(contact: LegalPrivacyContact | null): string[] {
  if (!contact || !contact.configured) return []
  return [contact.name, contact.office, contact.email, contact.phone, contact.address].filter(
    (line): line is string => Boolean(line && line.trim()),
  )
}
