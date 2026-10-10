import { useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { SYSTEM_NAME, SYSTEM_ORG } from '../constants/system'
import { useAuth } from '../context/AuthContext'

/*
 * The sign-in page, built around the college's own colours.
 *
 * Left of the fold is the brand panel: the navy-and-gold sweep from the
 * printable letterhead, the official seal, and the system's full name. Right of
 * the fold is the card holding the form itself. On a narrow screen the panel
 * becomes a band above the card rather than disappearing, so the page is still
 * recognisably the college's.
 *
 * Icons are hand-written SVG rather than an icon package: the project ships
 * only React, Tailwind and react-router, and the letterhead set the precedent
 * of inlining its own vector artwork instead of adding a dependency for a
 * handful of shapes.
 */

function MailIcon({ className }: { className?: string }) {
  return (
    <svg
      className={className}
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth="1.8"
      strokeLinecap="round"
      strokeLinejoin="round"
      aria-hidden="true"
      focusable="false"
    >
      <rect x="2.5" y="4.5" width="19" height="15" rx="2" />
      <path d="m3 7 8.2 5.6a1.5 1.5 0 0 0 1.6 0L21 7" />
    </svg>
  )
}

function LockIcon({ className }: { className?: string }) {
  return (
    <svg
      className={className}
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth="1.8"
      strokeLinecap="round"
      strokeLinejoin="round"
      aria-hidden="true"
      focusable="false"
    >
      <rect x="4" y="10.5" width="16" height="10" rx="2" />
      <path d="M8 10.5V7.5a4 4 0 0 1 8 0v3" />
    </svg>
  )
}

function EyeIcon({ className }: { className?: string }) {
  return (
    <svg
      className={className}
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth="1.8"
      strokeLinecap="round"
      strokeLinejoin="round"
      aria-hidden="true"
      focusable="false"
    >
      <path d="M2 12s3.6-6.5 10-6.5S22 12 22 12s-3.6 6.5-10 6.5S2 12 2 12Z" />
      <circle cx="12" cy="12" r="3" />
    </svg>
  )
}

function EyeOffIcon({ className }: { className?: string }) {
  return (
    <svg
      className={className}
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth="1.8"
      strokeLinecap="round"
      strokeLinejoin="round"
      aria-hidden="true"
      focusable="false"
    >
      <path d="M3 3l18 18" />
      <path d="M10.6 6.1A9.9 9.9 0 0 1 12 6c6.4 0 10 6 10 6a17 17 0 0 1-3.4 4" />
      <path d="M6.3 7.9A16.6 16.6 0 0 0 2 12s3.6 6 10 6a10 10 0 0 0 4.2-.9" />
      <path d="M9.9 9.9a3 3 0 0 0 4.2 4.2" />
    </svg>
  )
}

/* The submit button's mark.
 *
 * Deliberately not the arrow-into-a-door frame that nearly every portal sign-in
 * uses: that is the one glyph that would make this page look like a stock
 * template rather than the college's own. A key says the same thing — this is
 * the button that authenticates you — without being interchangeable with the
 * next portal a panelist has seen. It also stays distinct from the closed
 * padlock on the password field above it.
 *
 * Drawn lying horizontally, at its own wide 22x11 ratio rather than inside a
 * 24x24 square. A key is mostly long, straight strokes; the diagonal version
 * used the same box but left a third of it empty, so at button size the bow
 * collapsed into a dot and the whole mark read as a squiggle. Lengthwise it
 * keeps full width, which is also the axis the button's row already reads on. */
function KeyIcon({ className }: { className?: string }) {
  return (
    <svg
      className={className}
      viewBox="0 0 22 11"
      fill="none"
      stroke="currentColor"
      strokeWidth="2"
      strokeLinecap="round"
      strokeLinejoin="round"
      aria-hidden="true"
      focusable="false"
    >
      <circle cx="5" cy="5.5" r="4" />
      <path d="M9 5.5h12" />
      <path d="M15.5 5.5v3.5" />
      <path d="M19 5.5v2.5" />
    </svg>
  )
}

function SpinnerIcon({ className }: { className?: string }) {
  return (
    <svg
      className={className}
      viewBox="0 0 24 24"
      fill="none"
      aria-hidden="true"
      focusable="false"
    >
      <circle cx="12" cy="12" r="9" stroke="currentColor" strokeOpacity="0.3" strokeWidth="3" />
      <path
        d="M21 12a9 9 0 0 0-9-9"
        stroke="currentColor"
        strokeWidth="3"
        strokeLinecap="round"
      />
    </svg>
  )
}

function AlertIcon({ className }: { className?: string }) {
  return (
    <svg
      className={className}
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth="1.9"
      strokeLinecap="round"
      strokeLinejoin="round"
      aria-hidden="true"
      focusable="false"
    >
      <circle cx="12" cy="12" r="9" />
      <path d="M12 7.5v5.5" />
      <path d="M12 16.3h.01" />
    </svg>
  )
}

/* One definition for both fields, so the email box cannot end up a few pixels
 * taller than the password box. */
const fieldClass =
  'w-full rounded-lg border border-slate-300 bg-white py-2.5 text-sm text-slate-900 shadow-sm outline-none transition placeholder:text-slate-400 focus:border-[#0a2f9c] focus:ring-4 focus:ring-[#0a2f9c]/15 disabled:bg-slate-50 disabled:text-slate-500'

export default function LoginPage() {
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [showPassword, setShowPassword] = useState(false)
  const [error, setError] = useState('')
  const [loading, setLoading] = useState(false)
  const { login } = useAuth()
  const navigate = useNavigate()

  async function handleLogin(e: React.FormEvent) {
    e.preventDefault()
    setError('')
    setLoading(true)
    try {
      await login(email, password)
      navigate('/dashboard')
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Something went wrong')
    } finally {
      setLoading(false)
    }
  }

  return (
    <div className="min-h-screen grid lg:grid-cols-2 bg-slate-100">
      {/* Brand panel. Decorative throughout: every word in it is repeated as a
          real heading inside the card, so hiding it from assistive technology
          costs nothing and keeps the form the first thing announced. */}
      <section
        aria-hidden="true"
        className="login-brand flex flex-col justify-center px-8 pt-10 pb-28 sm:px-12 lg:px-16 lg:py-16"
      >
        {/* The letterhead's own sweep. Stretched the full height of a tall panel
            it turned into a wedge, so it is anchored to the bottom edge at its
            true 325:67 proportions — see .login-brand-stripes. Surveyed at
            screen widths from 430px up, it never reaches the text above it. */}
        <svg
          className="login-brand-stripes"
          viewBox="0 0 325 67"
          preserveAspectRatio="none"
          focusable="false"
        >
          <polygon points="0,8 100,32 200,49 325,53 325,58 200,55 100,40 0,17" fill="#ffffff" fillOpacity="0.18" />
          <polygon points="0,18 100,41 200,56 325,59 325,65 200,63 100,53 0,33" fill="#ffc000" />
        </svg>

        <div className="login-brand-inner max-w-md mx-auto lg:mx-0 text-center lg:text-left">
          <img
            src="/lcc-seal.png"
            alt=""
            className="login-seal h-20 w-auto mx-auto lg:mx-0 lg:h-28"
          />
          <h2 className="login-brand-wordmark mt-5 text-2xl sm:text-3xl lg:text-4xl">
            {SYSTEM_ORG}
          </h2>
          <div className="login-brand-rule mx-auto lg:mx-0 mt-4" />
          <p className="mt-4 text-sm leading-relaxed text-white/80 sm:text-base">{SYSTEM_NAME}</p>
          <p className="mt-6 hidden text-xs font-semibold uppercase tracking-[0.18em] text-[#ffc000] lg:block">
            Don B. Benedicto Road, Gun-ob · School Code 7014
          </p>
        </div>
      </section>

      {/* Form panel. */}
      <main className="login-pane flex items-center justify-center px-6 py-10 sm:px-10">
        <div className="w-full max-w-sm">
          <div className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-xl shadow-slate-900/5">
            <div className="login-card-accent" />

            <div className="px-7 py-8">
              <h1 className="text-xl font-bold text-slate-800">Sign in to your account</h1>
              <p className="mt-1 text-sm text-slate-500">
                Faculty and administrator access only.
              </p>

              <form onSubmit={handleLogin} className="mt-7 space-y-5">
                <div>
                  <label
                    htmlFor="login-email"
                    className="mb-1.5 block text-sm font-medium text-slate-700"
                  >
                    Email
                  </label>
                  <div className="relative">
                    <MailIcon className="pointer-events-none absolute left-3.5 top-1/2 h-[18px] w-[18px] -translate-y-1/2 text-slate-400" />
                    <input
                      id="login-email"
                      type="email"
                      value={email}
                      onChange={(e) => setEmail(e.target.value)}
                      required
                      autoComplete="email"
                      autoFocus
                      disabled={loading}
                      aria-invalid={error ? true : undefined}
                      aria-describedby={error ? 'login-error' : undefined}
                      className={`${fieldClass} pl-11 pr-3`}
                      placeholder="you@example.com"
                    />
                  </div>
                </div>

                <div>
                  <label
                    htmlFor="login-password"
                    className="mb-1.5 block text-sm font-medium text-slate-700"
                  >
                    Password
                  </label>
                  <div className="relative">
                    <LockIcon className="pointer-events-none absolute left-3.5 top-1/2 h-[18px] w-[18px] -translate-y-1/2 text-slate-400" />
                    <input
                      id="login-password"
                      type={showPassword ? 'text' : 'password'}
                      value={password}
                      onChange={(e) => setPassword(e.target.value)}
                      required
                      autoComplete="current-password"
                      disabled={loading}
                      aria-invalid={error ? true : undefined}
                      aria-describedby={error ? 'login-error' : undefined}
                      className={`${fieldClass} pl-11 pr-11`}
                      placeholder="••••••••"
                    />
                    {/* type="button" so toggling the mask never submits a
                        half-filled form. */}
                    <button
                      type="button"
                      onClick={() => setShowPassword((shown) => !shown)}
                      className="absolute right-2 top-1/2 -translate-y-1/2 rounded-md p-1.5 text-slate-400 transition hover:text-[#0a2f9c] focus:outline-none focus-visible:ring-2 focus-visible:ring-[#0a2f9c]/40"
                      aria-label={showPassword ? 'Hide password' : 'Show password'}
                      aria-pressed={showPassword}
                      title={showPassword ? 'Hide password' : 'Show password'}
                    >
                      {showPassword ? (
                        <EyeOffIcon className="h-[18px] w-[18px]" />
                      ) : (
                        <EyeIcon className="h-[18px] w-[18px]" />
                      )}
                    </button>
                  </div>
                </div>

                {error && (
                  <p
                    id="login-error"
                    role="alert"
                    className="flex items-start gap-2 rounded-lg border border-red-200 bg-red-50 px-3 py-2.5 text-sm text-red-700"
                  >
                    <AlertIcon className="mt-0.5 h-4 w-4 flex-none" />
                    <span>{error}</span>
                  </p>
                )}

                <button
                  type="submit"
                  disabled={loading}
                  className="flex w-full items-center justify-center gap-2 rounded-lg bg-[#0a2f9c] px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-[#08257d] focus:outline-none focus-visible:ring-4 focus-visible:ring-[#0a2f9c]/30 disabled:cursor-not-allowed disabled:opacity-60"
                >
                  {loading ? (
                    <>
                      <SpinnerIcon className="h-4 w-4 animate-spin" />
                      Signing in…
                    </>
                  ) : (
                    <>
                      <KeyIcon className="h-[13px] w-[26px]" />
                      Log in
                    </>
                  )}
                </button>
              </form>
            </div>
          </div>

          {/* The policies are linked where the credentials are collected, so the
              documents are readable before anyone types a password. That is a
              link, deliberately not an "by signing in you consent" notice: the
              explicit agreement to the Terms of Use happens once, after sign-in,
              and acknowledging a privacy policy is not consent to processing. */}
          <nav aria-label="College policies" className="mt-6 text-center text-xs leading-relaxed text-slate-400">
            <ul className="flex flex-wrap items-center justify-center gap-x-2 gap-y-1">
              <li>
                <Link to="/privacy" className="login-policy-link">Privacy Policy</Link>
              </li>
              <li aria-hidden="true">·</li>
              <li>
                <Link to="/terms" className="login-policy-link">Terms of Use</Link>
              </li>
            </ul>
          </nav>

          <p className="mt-4 text-center text-xs leading-relaxed text-slate-400">
            © {new Date().getFullYear()} {SYSTEM_ORG}
            <br />
            Faculty, Classroom and Laboratory Scheduling System
          </p>
        </div>
      </main>
    </div>
  )
}
