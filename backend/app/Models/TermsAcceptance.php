<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * One account's acceptance of one version of the Terms of Use.
 *
 * The model deliberately exposes a small surface: read the current published
 * version, ask whether an account has accepted it, record an acceptance, and
 * list the accounts that still have not. Everything that could rewrite history
 * (update, delete, "replace the acceptance") simply does not exist here.
 */
class TermsAcceptance extends Model
{
    protected $fillable = [
        'user_id',
        'terms_version',
        'accepted_at',
    ];

    protected $casts = [
        'accepted_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The version currently published by the college (config/legal.php).
     *
     * This — not anything sent by a client — is what an acceptance is validated
     * against, so a stale page, a cached bundle, or a hand-crafted request
     * cannot record agreement to a revision that is not in force.
     */
    public static function currentVersion(): string
    {
        return (string) config('legal.terms.version');
    }

    public static function hasAccepted(User $user, ?string $version = null): bool
    {
        $version ??= static::currentVersion();

        return static::query()
            ->where('user_id', $user->id)
            ->where('terms_version', $version)
            ->exists();
    }

    public static function latestFor(User $user, ?string $version = null): ?self
    {
        $version ??= static::currentVersion();

        return static::query()
            ->where('user_id', $user->id)
            ->where('terms_version', $version)
            ->latest('accepted_at')
            ->first();
    }

    /**
     * Record that this account accepted this version.
     *
     * `firstOrCreate`-style rather than a plain insert: a double-click, a
     * retried request or a refreshed page must not fail or duplicate the
     * record. The row is never rewritten, so the original acceptance time is
     * what callers see afterwards.
     */
    public static function record(User $user, string $version): self
    {
        return static::firstOrCreate(
            ['user_id' => $user->id, 'terms_version' => $version],
            ['accepted_at' => now()],
        );
    }

    /**
     * Accounts that have not yet accepted the current version.
     *
     * Used to answer "who still needs to accept the revised Terms?" after a
     * material revision, so the admin can prompt them instead of guessing.
     *
     * @return Collection<int, User>
     */
    public static function usersPendingCurrentVersion(?string $version = null): Collection
    {
        $version ??= static::currentVersion();

        $accepted = static::query()
            ->where('terms_version', $version)
            ->whereNotNull('user_id')
            ->pluck('user_id');

        return User::query()->whereNotIn('id', $accepted)->orderBy('name')->get();
    }
}
