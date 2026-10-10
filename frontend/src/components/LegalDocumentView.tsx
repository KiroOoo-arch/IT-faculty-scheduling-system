import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { SYSTEM_NAME, SYSTEM_ORG } from '../constants/system'
import { LEGAL_DOCUMENTS, type LegalDocumentContent } from '../constants/legal'
import { contactLines, fetchLegalMeta, formatLegalDate, type LegalMeta } from '../utils/legal'
import { useAuth } from '../context/AuthContext'

/**
 * Renders one legal document: the Privacy Policy or the Terms of Use.
 *
 * Both documents share this layout so they cannot drift apart in structure —
 * the version line, the review notice and the contents list are identical, and
 * only the sections differ. The wording itself is passed in from
 * `constants/legal.ts`.
 *
 * Readability was the design constraint. These are long documents read once and
 * then referred back to, so: real heading levels, a contents list that jumps to
 * sections, generous line length and spacing, and the same navy/gold identity as
 * the rest of the college's screens. Every statement the college still has to
 * confirm is shown as an open item rather than buried in a footnote.
 */

/** A block the college must still confirm. Marked in text and in colour. */
function PendingBlock({ text }: { text: string }) {
  return (
    <p className="mt-4 flex gap-3 rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-sm leading-relaxed text-amber-900">
      <span aria-hidden="true" className="mt-px font-bold">
        !
      </span>
      <span>
        <span className="font-semibold">Requires college confirmation: </span>
        {text}
      </span>
    </p>
  )
}

export default function LegalDocumentView({ doc }: { doc: LegalDocumentContent }) {
  const { user } = useAuth()
  const [meta, setMeta] = useState<LegalMeta | null>(null)
  const [metaResolved, setMetaResolved] = useState(false)

  useEffect(() => {
    let cancelled = false
    fetchLegalMeta().then((result) => {
      if (cancelled) return
      setMeta(result)
      setMetaResolved(true)
    })
    return () => {
      cancelled = true
    }
  }, [])

  // Scroll to the top when switching between the two documents, so a reader who
  // clicks "Terms of Use" from the bottom of the Privacy Policy lands at its
  // start rather than at whatever anchor the previous page was showing.
  useEffect(() => {
    window.scrollTo({ top: 0 })
  }, [doc.id])

  const docMeta = meta
    ? doc.id === 'privacy'
      ? meta.privacy
      : meta.terms
    : null

  const other = LEGAL_DOCUMENTS.find((candidate) => candidate.id !== doc.id)
  const contact = contactLines(meta?.privacy_contact ?? null)
  const backTo = user ? '/dashboard' : '/login'
  const backLabel = user ? '← Back to dashboard' : '← Back to sign in'

  return (
    <div className="min-h-screen bg-slate-100">
      <a
        href="#document-start"
        className="sr-only focus:not-sr-only focus:absolute focus:left-4 focus:top-4 focus:z-50 focus:rounded-md focus:bg-[#0a2f9c] focus:px-4 focus:py-2 focus:text-sm focus:font-semibold focus:text-white"
      >
        Skip to the document
      </a>

      {/* College identity bar, echoing the letterhead: navy field, gold rule. */}
      <header className="bg-[#0a2f9c] border-b-4 border-[#ffc000]">
        <div className="mx-auto flex max-w-3xl flex-col gap-3 px-6 py-6 sm:flex-row sm:items-center sm:justify-between">
          <div>
            <p className="text-[11px] font-semibold uppercase tracking-[0.18em] text-[#ffc000]">
              {SYSTEM_ORG}
            </p>
            <p className="mt-1 text-sm text-blue-100">{SYSTEM_NAME}</p>
          </div>
          <Link
            to={backTo}
            className="self-start rounded-md border border-white/40 px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-white/10 focus-visible:ring-4 focus-visible:ring-white/30 sm:self-auto"
          >
            {backLabel}
          </Link>
        </div>
      </header>

      <main id="document-start" className="mx-auto max-w-3xl px-4 py-8 sm:px-6">
        <article className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-xl shadow-slate-900/5">
          <div className="border-b border-slate-200 px-6 py-6 sm:px-8">
            <h1 className="text-2xl font-bold text-slate-900 sm:text-3xl">{doc.title}</h1>
            <p className="mt-3 text-sm leading-relaxed text-slate-600">{doc.standing}</p>

            <dl className="mt-5 flex flex-wrap gap-x-8 gap-y-2 text-xs text-slate-600">
              <div className="flex gap-2">
                <dt className="font-semibold uppercase tracking-wide text-slate-500">Version</dt>
                <dd className="font-semibold text-[#0a2f9c]">
                  {docMeta ? docMeta.version : metaResolved ? 'unavailable' : 'loading…'}
                </dd>
              </div>
              <div className="flex gap-2">
                <dt className="font-semibold uppercase tracking-wide text-slate-500">Effective date</dt>
                <dd>{docMeta ? formatLegalDate(docMeta.effective_date) : '—'}</dd>
              </div>
            </dl>

            {!metaResolved && (
              <p role="status" className="mt-3 text-xs text-slate-500">
                Loading the published version…
              </p>
            )}

            {metaResolved && !meta && (
              <p
                role="status"
                className="mt-4 rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900"
              >
                The published version and effective date could not be loaded from the server, so the
                details above may be out of date. Reload the page before relying on them.
              </p>
            )}

            {docMeta?.is_draft && (
              <p className="mt-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm font-semibold text-red-800">
                Draft pending college adoption — this text has not been approved or adopted by the
                college. It is published here for review and must not be treated as an approved
                institutional policy.
              </p>
            )}
          </div>

          {/* Contents. Long documents are navigated, not read linearly, and the
              links are ordinary anchors so they work with the keyboard. */}
          <nav aria-label={`Contents of the ${doc.title}`} className="border-b border-slate-200 bg-slate-50 px-6 py-5 sm:px-8">
            <h2 className="text-[11px] font-semibold uppercase tracking-[0.18em] text-[#0a2f9c]">
              Contents
            </h2>
            <ol className="mt-3 grid gap-1.5 sm:grid-cols-2">
              {doc.sections.map((section) => (
                <li key={section.id}>
                  <a
                    href={`#${section.id}`}
                    className="rounded text-sm text-slate-700 underline decoration-slate-300 underline-offset-2 transition hover:text-[#0a2f9c] hover:decoration-[#0a2f9c] focus-visible:ring-2 focus-visible:ring-[#0a2f9c]/40"
                  >
                    {section.heading}
                  </a>
                </li>
              ))}
            </ol>
          </nav>

          <div className="px-6 py-6 sm:px-8">
            {doc.sections.map((section) => (
              <section key={section.id} className="scroll-mt-6 border-b border-slate-100 py-7 last:border-b-0 last:pb-2">
                <h2 id={section.id} className="text-lg font-bold text-slate-900">
                  {section.heading}
                </h2>
                {section.blocks.map((block, index) => {
                  if (block.kind === 'paragraph') {
                    return (
                      <p key={index} className="mt-3 text-sm leading-relaxed text-slate-700">
                        {block.text}
                      </p>
                    )
                  }
                  if (block.kind === 'list') {
                    return (
                      <ul key={index} className="mt-3 space-y-2 pl-5 text-sm leading-relaxed text-slate-700">
                        {block.items.map((item) => (
                          <li key={item} className="list-disc marker:text-[#0a2f9c]">
                            {item}
                          </li>
                        ))}
                      </ul>
                    )
                  }
                  return <PendingBlock key={index} text={block.text} />
                })}
              </section>
            ))}
          </div>

          {/* What the college still has to decide, gathered in one place so a
              reviewer does not have to hunt for the open items. */}
          <div className="border-t border-slate-200 bg-slate-50 px-6 py-6 sm:px-8">
            <h2 className="text-[11px] font-semibold uppercase tracking-[0.18em] text-[#0a2f9c]">
              Open items for college review
            </h2>
            <ul className="mt-3 space-y-2 text-sm leading-relaxed text-slate-700">
              {doc.reviewNotes.map((note) => (
                <li key={note} className="flex gap-2">
                  <span aria-hidden="true" className="mt-2 h-1.5 w-1.5 flex-none rounded-full bg-[#ffc000]" />
                  <span>{note}</span>
                </li>
              ))}
              {(meta?.review_required ?? []).map((note) => (
                <li key={note} className="flex gap-2">
                  <span aria-hidden="true" className="mt-2 h-1.5 w-1.5 flex-none rounded-full bg-[#ffc000]" />
                  <span>{note}</span>
                </li>
              ))}
            </ul>
          </div>

          <div className="border-t border-slate-200 px-6 py-6 sm:px-8">
            <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
              <div>
                <h2 className="text-[11px] font-semibold uppercase tracking-[0.18em] text-slate-500">
                  Privacy contact
                </h2>
                {contact.length > 0 ? (
                  <address className="mt-2 space-y-0.5 text-sm not-italic leading-relaxed text-slate-700">
                    {contact.map((line) => (
                      <span key={line} className="block">
                        {line}
                      </span>
                    ))}
                  </address>
                ) : (
                  <p className="mt-2 max-w-md text-sm leading-relaxed text-slate-600">
                    Not yet published. The college has not designated a data protection officer or
                    published a privacy contact, so raise a privacy request with the college
                    administration office until one is confirmed.
                  </p>
                )}
              </div>

              {other && (
                <div className="sm:text-right">
                  <h2 className="text-[11px] font-semibold uppercase tracking-[0.18em] text-slate-500">
                    Also read
                  </h2>
                  <Link
                    to={other.path}
                    className="mt-2 inline-block text-sm font-semibold text-[#0a2f9c] underline decoration-[#0a2f9c]/40 underline-offset-2 transition hover:decoration-[#0a2f9c] focus-visible:ring-2 focus-visible:ring-[#0a2f9c]/40"
                  >
                    {other.title}
                  </Link>
                </div>
              )}
            </div>
          </div>
        </article>

        <p className="mt-6 text-center text-xs leading-relaxed text-slate-500">
          {SYSTEM_ORG} · {doc.title}
          {docMeta ? ` · version ${docMeta.version}` : ''}
        </p>
      </main>
    </div>
  )
}
