import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { SYSTEM_ORG } from '../constants/system'
import { contactLines, fetchLegalMeta, type LegalMeta } from '../utils/legal'

/**
 * The dashboard's discreet footer: the college's name and the three links that
 * belong in every internal application — the privacy policy, the terms of use,
 * and where to send a privacy request.
 *
 * Deliberately not another row of navigation tiles. The dashboard's tile grid
 * is how you get to work (Users, Faculty, Subjects…); these are policies, read
 * occasionally, so they sit quietly at the foot of the page in small type.
 *
 * "Privacy Contact" appears only when the college has actually configured one.
 * A link to a contact that does not exist would be worse than no link.
 */
export default function DashboardFooter() {
  const [meta, setMeta] = useState<LegalMeta | null>(null)

  useEffect(() => {
    let cancelled = false
    fetchLegalMeta().then((result) => {
      if (!cancelled) setMeta(result)
    })
    return () => {
      cancelled = true
    }
  }, [])

  const contact = contactLines(meta?.privacy_contact ?? null)

  // The ring is added on :focus-visible, and the browser's own focus indicator is
  // deliberately left in place: `focus:outline-none` here would leave a keyboard
  // user with no visible focus at all in any context where :focus-visible does
  // not match, and these are links a keyboard user has to find.
  const linkClass =
    'rounded text-gray-600 underline decoration-gray-300 underline-offset-2 transition hover:text-[#0a2f9c] hover:decoration-[#0a2f9c] focus-visible:ring-2 focus-visible:ring-[#0a2f9c]/40 focus-visible:ring-offset-1'

  return (
    <footer className="mt-8 border-t border-gray-200 pt-4">
      <p className="text-[11px] font-semibold uppercase tracking-widest text-[#0a2f9c]">{SYSTEM_ORG}</p>
      <nav aria-label="College policies" className="mt-2">
        <ul className="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs">
          <li>
            <Link to="/privacy" className={linkClass}>
              Privacy Policy
            </Link>
          </li>
          <li aria-hidden="true" className="text-gray-300">
            ·
          </li>
          <li>
            <Link to="/terms" className={linkClass}>
              Terms of Use
            </Link>
          </li>
          {contact.length > 0 && (
            <>
              <li aria-hidden="true" className="text-gray-300">
                ·
              </li>
              <li>
                {/* The contact itself is on the policy page, under the rights
                    section, so the footer does not repeat the details. */}
                <Link to="/privacy#rights-and-inquiries" className={linkClass}>
                  Privacy Contact
                </Link>
              </li>
            </>
          )}
        </ul>
      </nav>
    </footer>
  )
}
