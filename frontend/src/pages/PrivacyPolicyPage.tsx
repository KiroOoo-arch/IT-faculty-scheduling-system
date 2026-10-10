import LegalDocumentView from '../components/LegalDocumentView'
import { PRIVACY_POLICY } from '../constants/legal'

/**
 * /privacy — the Privacy Policy.
 *
 * Public on purpose: the policy has to be readable before anyone signs in, and
 * it contains no data from the system. The page is a route inside the SPA, so a
 * direct visit or a refresh lands on it rather than on the dashboard.
 */
export default function PrivacyPolicyPage() {
  return <LegalDocumentView doc={PRIVACY_POLICY} />
}
