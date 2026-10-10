import LegalDocumentView from '../components/LegalDocumentView'
import { TERMS_OF_USE } from '../constants/legal'

/**
 * /terms — the Terms of Use.
 *
 * Public on purpose: a person must be able to read the terms before accepting
 * them, which includes the first-use prompt after signing in. The page is a
 * route inside the SPA, so a direct visit or a refresh lands on it rather than
 * on the dashboard.
 */
export default function TermsOfUsePage() {
  return <LegalDocumentView doc={TERMS_OF_USE} />
}
