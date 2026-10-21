# Privacy Policy, Terms of Use, and Terms Acceptance

*Implementation note — added October 2026*

This note covers the **Privacy Policy and Terms of Use pages**, the **dashboard footer links**, and the **versioned Terms acceptance** workflow. It records what was built, where the wording lives, how the version is published, and which questions remain for the college.

It does not replace [`Data-Privacy-and-Security.md`](Data-Privacy-and-Security.md), which remains the internal data-protection and security statement. The Privacy Policy page is the user-facing counterpart: what the application holds, why, who can see it, and how it is protected.

> **These documents are drafts.** Neither has an effective date, because the college has not adopted them. Both pages say so on their face. Publishing a privacy policy does not by itself make the college compliant with the Data Privacy Act of 2012, and none of the wording here is legal advice.

---

## 1. What was added

| Piece | Where |
|---|---|
| Privacy Policy page | `frontend/src/pages/PrivacyPolicyPage.tsx` → `/privacy` |
| Terms of Use page | `frontend/src/pages/TermsOfUsePage.tsx` → `/terms` |
| Shared document layout (contents list, version/date line, draft notice, open-items panel) | `frontend/src/components/LegalDocumentView.tsx` |
| The wording of both documents | `frontend/src/constants/legal.ts` |
| Discreet dashboard footer | `frontend/src/components/DashboardFooter.tsx` |
| First-use acceptance prompt | `frontend/src/components/TermsAcceptanceGate.tsx` |
| Sign-in page policy links | `frontend/src/pages/LoginPage.tsx` |
| Version, dates, contact, contact switch | `backend/config/legal.php` |
| Acceptance record | `terms_acceptances` table (`backend/database/migrations/2026_10_10_000001_create_terms_acceptances_table.php`) |

The dashboard itself is unchanged apart from the footer: the navigation tiles, schedule generation, review/approval, printing and reports behave exactly as before.

## 2. Routes

| Route | Who | What it returns |
|---|---|---|
| `/privacy`, `/terms` | Public (no sign-in) | The documents. Public on purpose: a policy has to be readable before anyone signs in, and neither page holds data from the system. They are real SPA routes, so a direct visit or a refresh lands on the document. |
| `GET /api/legal` | Public | The current version, effective dates, whether the prompt is on, the privacy contact (empty until designated), and the list of facts the college still has to confirm. Both pages read their version line from here, so the page and the server cannot disagree about which revision is in force. |
| `GET /api/terms/acceptance` | Any signed-in account | Whether *that account* has accepted the current version, plus the version it accepted most recently. |
| `POST /api/terms/acceptance` | Any signed-in account | Records acceptance of the current version for the signed-in account. |
| `GET /api/terms/acceptances` | Admin only | Who has accepted the current version and who has not — the list to work from after a revision. |

## 3. Configured version and effective date

`backend/config/legal.php`, overridable by environment variables:

| Setting | Env var | Default |
|---|---|---|
| Terms version | `TERMS_VERSION` | `1.0-draft` |
| Terms effective date | `TERMS_EFFECTIVE_DATE` | *(empty — the document is a draft)* |
| Privacy version | `PRIVACY_VERSION` | `1.0-draft` |
| Privacy effective date | `PRIVACY_EFFECTIVE_DATE` | *(empty)* |
| Organisation name | `LEGAL_ORGANISATION` | Lapu-Lapu City College |
| Acceptance prompt | `TERMS_REQUIRE_ACCEPTANCE` | `true` |
| Privacy contact | `PRIVACY_CONTACT_OFFICE`, `PRIVACY_CONTACT_NAME`, `PRIVACY_CONTACT_EMAIL`, `PRIVACY_CONTACT_PHONE`, `PRIVACY_CONTACT_ADDRESS` | *(all empty)* |
| Acceptance retention | `TERMS_ACCEPTANCE_RETENTION` | *(empty — to be agreed)* |

While `TERMS_EFFECTIVE_DATE` is empty the pages show a **draft pending college adoption** banner and the acceptance prompt says the same thing. Setting a date is what turns a draft into an adopted document — do it as part of the college's approval, not before.

**Suspending the prompt:** set `TERMS_REQUIRE_ACCEPTANCE=false`. Nothing else changes: the pages stay up, the footer stays, and the API still reports the published version. Use this if the college wants the documents published for reading before it is ready to ask for acceptance.

## 4. Updating the wording

1. Edit the sections in `frontend/src/constants/legal.ts`. The structure is data — heading, paragraphs, bullet lists, and `pending` blocks for statements the college has still to confirm.
2. If the change is **material** (what is collected, why, who can see it, or a user's responsibilities), bump the version in `backend/config/legal.php` (`TERMS_VERSION`) and set the new effective date. Every account is then asked to accept the revision once, on next use. Accepting a revision **adds** a record; it never overwrites the earlier one.
3. If the change is not material (a typo, a clearer sentence), edit the text without bumping the version.
4. `frontend/src/constants/legal.ts` also carries `reviewNotes` per document — the document-level open items shown in the "Open items for college review" panel. Keep them honest: delete a note only when the college has actually settled the point.

## 5. How acceptance tracking works

- **What is stored:** one row per account per version — account id, `terms_version`, `accepted_at`. Nothing else: no password, no token, no IP address, no user agent.
- **Append-only:** accepting a later version inserts a new row. Earlier acceptances stay, so the history of who accepted what survives a revision. This is why the table has a unique index on `(user_id, terms_version)`: a retried request finds the existing row instead of duplicating it.
- **When it is asked:** once, at first use after signing in, in place of the page content — not at sign-in, and not by blocking login. Existing accounts are therefore not locked out by the introduction of tracking; they simply meet the prompt the next time they use the system.
- **Who it is recorded for:** always the account on the bearer token. The version is validated against the server's current version, so a stale tab cannot record agreement to a revision that is not in force, and the request carries no user id at all, so nobody can accept for someone else.
- **If the API is unreachable:** the page is shown with a visible warning instead of blocking the user. The prompt exists to inform, not to gate access on a network round-trip.
- **Privacy acknowledgment is not consent:** the prompt asks for agreement to the *Terms of Use* only. The Privacy Policy is linked, not "consented to" with a checkbox, because processing has to rest on a lawful basis the college identifies — see `Data-Privacy-and-Security.md` and the open item on the policy page.
- **After a revision:** `GET /api/terms/acceptances` lists the accounts that have not yet accepted the current version; the prompt shows everyone else what they accepted and when.

## 6. Still to be confirmed by the college

These are placeholders on purpose. None of them has been invented, and each appears on the pages as an open item:

1. **Adoption and effective dates** for both documents.
2. **Lawful basis** for each processing purpose under the Data Privacy Act of 2012 (R.A. 10173) and its IRR.
3. **Designated privacy contact / Data Protection Officer**, and how requests reach them.
4. **Retention and disposal** periods for scheduling records, generation records and acceptance records.
5. **Hosting, backups and device security** if the system moves off the single local machine — the Transport line in the existing statement assumes an internal-network deployment that has not been set up yet.
6. **Liability, dispute resolution and enforcement** wording: no limitation-of-liability or dispute clause has been drafted, and none should be added without legal advice.
7. **Ownership and redistribution** conditions for printed and exported timetables.
8. **Whether the acceptance prompt should be enforced** while the documents are still drafts (see `TERMS_REQUIRE_ACCEPTANCE` above).
9. **Counsel review** of both texts before they are relied on.

## 7. Running the checks

```bash
# The acceptance API, end to end (auth, version validation, cross-user protection,
# idempotency, revision handling, admin-only listing):
cd backend && php vendor/bin/phpunit --filter TermsAcceptanceTest

# The whole backend suite, to confirm nothing else moved:
cd backend && php artisan test

# Frontend type check, production build and lint:
cd frontend && npm run build && npm run lint
```

The migration is applied with `cd backend && php artisan migrate`. On the test database it runs with the suite (`RefreshDatabase`), so no separate step is needed there.

To try the workflow by hand: sign in, and the Terms prompt appears in place of the dashboard until it is accepted. `GET /api/legal` shows which version is current; changing `TERMS_VERSION` and reloading makes the prompt reappear with the earlier acceptance named.
