import type { ReactNode } from 'react'

/**
 * The school letterhead shared by every printable document.
 *
 * Earlier this was a single low-resolution raster banner, which blurred badly
 * once it was scaled up to the width of the page. It is now assembled from
 * parts so that everything which can be sharp is sharp:
 *
 *   - the diagonal gold/navy stripes are a vector, so they stay crisp at any
 *     size;
 *   - the college shield and the Bagong Pilipinas mark are the official
 *     high-resolution artwork (`/lcc-seal.png`, `/bagong-pilipinas.png`),
 *     rather than a few dozen pixels carved out of a screenshot;
 *   - the college name, address and school code are real text, so they print
 *     as type instead of a scaled-up picture of type.
 *
 * The layout mirrors the printed letterhead it replaces: stripes sweeping down
 * behind the two marks, the wordmark set to the right, a dashed rule beneath
 * the marks, and the gold-over-navy rule closing the header.
 */
export default function PrintLetterhead({
  title,
  subtitle,
  children,
}: {
  title: string
  subtitle?: string
  children?: ReactNode
}) {
  return (
    <header className="letterhead max-w-5xl mx-auto mb-5">
      <div className="letterhead-banner">
        {/* Decorative stripes. preserveAspectRatio="none" lets them stretch to
            whatever width the header ends up at on screen or on paper. */}
        <svg
          className="letterhead-stripes"
          viewBox="0 0 325 67"
          preserveAspectRatio="none"
          aria-hidden="true"
          focusable="false"
        >
          {/* Two nested edges rather than one thick band: the sweep starts
              high on the left, flattens and thins towards the right, and ends
              low enough that it never reaches the wordmark. */}
          <polygon
            points="0,8 100,32 200,49 325,53 325,58 200,55 100,40 0,17"
            fill="#0a2f9c"
          />
          <polygon
            points="0,18 100,41 200,56 325,59 325,65 200,63 100,53 0,33"
            fill="#ffc000"
          />
        </svg>

        <img
          className="letterhead-seal"
          src="/lcc-seal.png"
          alt="Lapu-Lapu City College seal"
        />

        <div className="letterhead-bp">
          <img src="/bagong-pilipinas.png" alt="Bagong Pilipinas" />
          <span>BAGONG PILIPINAS</span>
        </div>

        <div className="letterhead-wordmark">
          <span className="letterhead-name">Lapu-Lapu City College</span>
          <span className="letterhead-addr">
            Don B. Benedicto Road, Gun-ob, Lapu-Lapu City, 6015
          </span>
          <span className="letterhead-addr">School Code: 7014</span>
        </div>
      </div>

      <div className="letterhead-dashed" />
      <div className="letterhead-rule" />

      <h1 className="text-center text-base font-bold uppercase tracking-wide mt-2">
        {title}
      </h1>
      {subtitle && (
        <p className="text-center text-sm font-semibold text-gray-700">{subtitle}</p>
      )}
      {children}
    </header>
  )
}
