<?php

namespace App\Http\Controllers;

use App\Models\TermsAcceptance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The legal documents' server-side half: which version is current, and the
 * versioned record of who accepted which revision.
 *
 * The wording of both documents lives in the frontend
 * (`frontend/src/constants/legal.ts`). What lives here is everything a client
 * must not be trusted to decide: the current version, the effective dates, and
 * the acceptance record itself.
 */
class LegalController extends Controller
{
    /**
     * Document metadata. Public, because the Privacy Policy and the Terms of Use
     * must be readable before anyone signs in, and because an acceptance is only
     * meaningful if the page and the server agree on which version is current.
     *
     * Nothing personal is returned: versions, dates, and a contact block that is
     * empty until the college designates one.
     */
    public function show(): JsonResponse
    {
        $terms = config('legal.terms');
        $privacy = config('legal.privacy');
        $contact = config('legal.privacy_contact');

        return response()->json([
            'organisation' => config('legal.organisation'),
            'terms' => [
                'version' => (string) $terms['version'],
                'effective_date' => $terms['effective_date'] ?: null,
                'is_draft' => empty($terms['effective_date']),
            ],
            'privacy' => [
                'version' => (string) $privacy['version'],
                'effective_date' => $privacy['effective_date'] ?: null,
                'is_draft' => empty($privacy['effective_date']),
            ],
            'acceptance' => [
                'required' => (bool) config('legal.acceptance.required'),
                'retention_period' => config('legal.acceptance.retention_period') ?: null,
            ],
            'privacy_contact' => [
                'configured' => $this->contactIsConfigured($contact),
                'office' => $contact['office'] ?: null,
                'name' => $contact['name'] ?: null,
                'email' => $contact['email'] ?: null,
                'phone' => $contact['phone'] ?: null,
                'address' => $contact['address'] ?: null,
            ],
            // Surfaced so the pages state plainly what the college still has to
            // confirm, instead of presenting an unreviewed draft as settled policy.
            'review_required' => $this->reviewItems($terms, $privacy, $contact),
        ]);
    }

    /**
     * Whether the signed-in account has accepted the version that is currently
     * in force, and which version it accepted most recently.
     *
     * Read for the authenticated user only — there is no way to ask about
     * somebody else's acceptance here.
     */
    public function status(Request $request): JsonResponse
    {
        $user = $request->user();
        $current = TermsAcceptance::currentVersion();
        $record = TermsAcceptance::latestFor($user, $current);
        $latest = $user->termsAcceptances()->latest('accepted_at')->first();

        return response()->json([
            'required' => (bool) config('legal.acceptance.required'),
            'current_version' => $current,
            'effective_date' => config('legal.terms.effective_date') ?: null,
            'accepted' => $record !== null,
            'accepted_version' => $record?->terms_version,
            'accepted_at' => $record?->accepted_at?->toIso8601String(),
            // An account that accepted an older revision is not treated as
            // having accepted the current one; this is how the client can say so.
            'latest_accepted' => $latest ? [
                'version' => $latest->terms_version,
                'accepted_at' => $latest->accepted_at?->toIso8601String(),
                'is_current_version' => $latest->terms_version === $current,
            ] : null,
        ]);
    }

    /**
     * Record acceptance of the current Terms of Use for the authenticated user.
     *
     * Three deliberate properties:
     *  - the version is validated against the server's current version, so a
     *    stale tab cannot record agreement to a revision that is not in force;
     *  - the account is taken from the bearer token, never from the request
     *    body, so nobody can accept on another user's behalf;
     *  - repeating the request is a no-op that returns the original record
     *    rather than a second row or an error.
     *
     * CSRF is not applicable to this endpoint: the API authenticates with
     * Sanctum bearer tokens, not with a cookie session, so a cross-site form
     * post carries no ambient credentials to abuse.
     */
    public function accept(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'terms_version' => ['required', 'string', 'max:64'],
        ]);

        $current = TermsAcceptance::currentVersion();

        if ($validated['terms_version'] !== $current) {
            return response()->json([
                'message' => 'Version ' . $validated['terms_version']
                    . ' of the Terms of Use is no longer current. Reload the page to read version '
                    . $current . ' and accept that instead.',
                'errors' => [
                    'terms_version' => ['The published version is ' . $current . '.'],
                ],
                'current_version' => $current,
            ], 422);
        }

        $existing = TermsAcceptance::latestFor($request->user(), $current);

        if ($existing) {
            return response()->json([
                'message' => 'You have already accepted version ' . $current . '.',
                'accepted' => true,
                'already_accepted' => true,
                'terms_version' => $existing->terms_version,
                'accepted_at' => $existing->accepted_at?->toIso8601String(),
            ], 200);
        }

        $record = TermsAcceptance::record($request->user(), $current);

        return response()->json([
            'message' => 'Your acceptance of version ' . $current . ' has been recorded.',
            'accepted' => true,
            'already_accepted' => false,
            'terms_version' => $record->terms_version,
            'accepted_at' => $record->accepted_at?->toIso8601String(),
        ], 201);
    }

    /**
     * Who has and has not accepted the current version.
     *
     * Admin-only (route middleware). This is the list to work from after a
     * material revision: the accounts under `pending` have not accepted the
     * version in force. It carries the same account fields the Users page
     * already shows — name, email, role — and nothing more.
     */
    public function index(): JsonResponse
    {
        $current = TermsAcceptance::currentVersion();

        $accepted = TermsAcceptance::query()
            ->with('user:id,name,email,role')
            ->where('terms_version', $current)
            ->whereNotNull('user_id')
            ->orderBy('accepted_at')
            ->get()
            ->map(fn (TermsAcceptance $row) => [
                'user_id' => $row->user_id,
                'name' => $row->user?->name,
                'email' => $row->user?->email,
                'role' => $row->user?->role,
                'terms_version' => $row->terms_version,
                'accepted_at' => $row->accepted_at?->toIso8601String(),
            ]);

        $pending = TermsAcceptance::usersPendingCurrentVersion($current)
            ->map(fn ($user) => [
                'user_id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
            ]);

        return response()->json([
            'current_version' => $current,
            'accepted' => $accepted,
            'pending' => $pending,
        ]);
    }

    /**
     * @param  array<string, mixed>  $contact
     */
    private function contactIsConfigured(array $contact): bool
    {
        return !empty($contact['email']) || !empty($contact['office']) || !empty($contact['name']);
    }

    /**
     * The institutional facts this implementation cannot decide for the college.
     *
     * @param  array<string, mixed>  $terms
     * @param  array<string, mixed>  $privacy
     * @param  array<string, mixed>  $contact
     * @return list<string>
     */
    private function reviewItems(array $terms, array $privacy, array $contact): array
    {
        $items = [];

        if (empty($terms['effective_date'])) {
            $items[] = 'Terms of Use: no effective date has been set, so the published text is a draft pending college adoption.';
        }

        if (empty($privacy['effective_date'])) {
            $items[] = 'Privacy Policy: no effective date has been set, so the published text is a draft pending college adoption.';
        }

        if (!$this->contactIsConfigured($contact)) {
            $items[] = 'Privacy contact: the college has not designated a Data Protection Officer or published a privacy contact, so this page cannot yet tell users where to send a request.';
        }

        if (empty(config('legal.acceptance.retention_period'))) {
            $items[] = 'Acceptance records: no retention period has been agreed for the Terms acceptance log.';
        }

        return $items;
    }
}
