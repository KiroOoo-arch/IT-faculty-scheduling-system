<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Versioned Terms of Use acceptance.
     *
     * Append-only by design: a row records that one account accepted one
     * version at one moment, and nothing in the application updates or deletes
     * those rows. Accepting a later version inserts another row rather than
     * overwriting the earlier one, so the history of who accepted what, and
     * when, survives a revision — which is the only reason to keep the record
     * at all.
     *
     * `user_id` is nullable and cleared on account deletion: the acceptance is
     * an accountability artifact, so the account goes and the record stays,
     * exactly as `schedules.approved_by` is nulled rather than cascading the
     * approval away (see App\Models\User::booted()).
     *
     * The unique index makes the API's "accept" operation idempotent at the
     * database level: one row per account per version, however many times the
     * request is retried.
     *
     * Nothing else is stored. No password, no token, no IP address, no user
     * agent, no free-text reason — none of it is needed to answer "has this
     * account accepted this version, and when?".
     */
    public function up(): void
    {
        Schema::create('terms_acceptances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('terms_version', 64);
            $table->timestamp('accepted_at');
            $table->timestamps();

            $table->unique(['user_id', 'terms_version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('terms_acceptances');
    }
};
