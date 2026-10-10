<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Legal documents — version, effective date, and contact
    |--------------------------------------------------------------------------
    |
    | The published version of each document, and the effective date, live here
    | rather than in the page text. Two reasons:
    |
    |  1. The server is the authority on which version is *current*. Terms
    |     acceptance is validated against `terms.version` below, so a client
    |     cannot record acceptance of a draft, an older revision, or a version
    |     that only exists in its own bundle.
    |  2. The college owns these values. Bumping a version is a configuration
    |     change with an audit trail, not an edit buried in a React component.
    |
    | A null `effective_date` means the document has not been adopted yet: the
    | pages render it with a "pending review" notice and it must not be
    | presented as an approved institutional policy.
    |
    | The page text itself lives in `frontend/src/constants/legal.ts`; the two
    | halves are deliberately separate because the wording is edited by the
    | college's authorized personnel while the version/date is what the server
    | enforces.
    |
    */

    'organisation' => env('LEGAL_ORGANISATION', 'Lapu-Lapu City College'),

    'terms' => [
        // Only this exact string is accepted by POST /api/terms/acceptance.
        'version' => env('TERMS_VERSION', '1.0-draft'),
        // e.g. 2026-11-02 once the college adopts it. Null = not yet adopted.
        'effective_date' => env('TERMS_EFFECTIVE_DATE'),
    ],

    'privacy' => [
        'version' => env('PRIVACY_VERSION', '1.0-draft'),
        'effective_date' => env('PRIVACY_EFFECTIVE_DATE'),
    ],

    'acceptance' => [
        /*
         | Whether an authenticated user is asked to accept the current Terms of
         | Use before using the scheduling pages. Enforcement is one-time per
         | version and never blocks signing in.
         |
         | Set TERMS_REQUIRE_ACCEPTANCE=false to suspend the prompt (for example
         | while the college is still reviewing the wording).
         */
        'required' => env('TERMS_REQUIRE_ACCEPTANCE', true),

        // The law and the college's own reviewers have not established a
        // retention period for these records, so none is enforced. Recorded
        // deliberately as a value the institution must confirm.
        'retention_period' => env('TERMS_ACCEPTANCE_RETENTION', null),
    ],

    /*
    |--------------------------------------------------------------------------
    | Privacy contact
    |--------------------------------------------------------------------------
    |
    | Left null on purpose. The college has not yet designated a Data Protection
    | Officer or published a privacy contact, and inventing an address, a phone
    | number or a named officer would be worse than an explicit placeholder:
    | users would send privacy requests somewhere that does not exist.
    |
    | Fill these in (env, or directly) once the designation is official. The
    | pages only render a "Privacy Contact" link when a contact is configured.
    |
    */

    'privacy_contact' => [
        'office' => env('PRIVACY_CONTACT_OFFICE'),
        'name' => env('PRIVACY_CONTACT_NAME'),
        'email' => env('PRIVACY_CONTACT_EMAIL'),
        'phone' => env('PRIVACY_CONTACT_PHONE'),
        'address' => env('PRIVACY_CONTACT_ADDRESS'),
    ],

];
