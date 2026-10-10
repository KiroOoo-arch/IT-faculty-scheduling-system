<?php

namespace Tests\Feature;

use App\Models\TermsAcceptance;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Versioned Terms of Use acceptance.
 *
 * The guarantees these tests pin down, in the order they matter:
 *  - a signed-in account can record acceptance of the *current* version only;
 *  - it can never record acceptance for another account, whatever the body says;
 *  - repeating the request does not duplicate or rewrite the record;
 *  - a new published version puts every account back to "not accepted";
 *  - the acceptance metadata is public, the acceptance itself is not, and the
 *    list of who has accepted is admin-only.
 */
class TermsAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $otherAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('legal.terms.version', '1.0-draft');
        Config::set('legal.terms.effective_date', null);
        Config::set('legal.acceptance.required', true);

        $this->admin = User::create([
            'name' => 'Department Head',
            'email' => 'admin@test.local',
            'password' => bcrypt('password'),
            'role' => 'admin',
        ]);

        $this->otherAdmin = User::create([
            'name' => 'Second Admin',
            'email' => 'second@test.local',
            'password' => bcrypt('password'),
            'role' => 'admin',
        ]);
    }

    #[Test]
    public function the_document_metadata_is_public_and_reports_the_current_version(): void
    {
        $this->getJson('/api/legal')
            ->assertOk()
            ->assertJsonPath('organisation', 'Lapu-Lapu City College')
            ->assertJsonPath('terms.version', '1.0-draft')
            ->assertJsonPath('terms.is_draft', true)
            ->assertJsonPath('acceptance.required', true)
            ->assertJsonPath('privacy_contact.configured', false);
    }

    #[Test]
    public function the_metadata_reports_the_unconfirmed_institutional_facts(): void
    {
        $items = $this->getJson('/api/legal')->assertOk()->json('review_required');

        $this->assertNotEmpty($items);
        $this->assertStringContainsString('effective date', implode(' ', $items));
        $this->assertStringContainsString('privacy contact', implode(' ', $items));
    }

    #[Test]
    public function a_guest_cannot_read_or_record_acceptance(): void
    {
        $this->getJson('/api/terms/acceptance')->assertStatus(401);
        $this->postJson('/api/terms/acceptance', ['terms_version' => '1.0-draft'])->assertStatus(401);
    }

    #[Test]
    public function an_authenticated_user_starts_out_not_having_accepted(): void
    {
        $this->actingAs($this->admin)
            ->getJson('/api/terms/acceptance')
            ->assertOk()
            ->assertJsonPath('required', true)
            ->assertJsonPath('current_version', '1.0-draft')
            ->assertJsonPath('accepted', false)
            ->assertJsonPath('latest_accepted', null);
    }

    #[Test]
    public function acceptance_records_the_user_the_version_and_the_timestamp(): void
    {
        $response = $this->actingAs($this->admin)
            ->postJson('/api/terms/acceptance', ['terms_version' => '1.0-draft'])
            ->assertStatus(201)
            ->assertJsonPath('accepted', true)
            ->assertJsonPath('terms_version', '1.0-draft');

        $this->assertNotNull($response->json('accepted_at'));

        $this->assertDatabaseHas('terms_acceptances', [
            'user_id' => $this->admin->id,
            'terms_version' => '1.0-draft',
        ]);

        $this->actingAs($this->admin)
            ->getJson('/api/terms/acceptance')
            ->assertJsonPath('accepted', true)
            ->assertJsonPath('accepted_version', '1.0-draft');
    }

    #[Test]
    public function an_outdated_version_is_refused_with_the_current_one_named(): void
    {
        $this->actingAs($this->admin)
            ->postJson('/api/terms/acceptance', ['terms_version' => '0.9'])
            ->assertStatus(422)
            ->assertJsonPath('current_version', '1.0-draft')
            ->assertJsonPath('errors.terms_version.0', 'The published version is 1.0-draft.');

        $this->assertDatabaseCount('terms_acceptances', 0);
    }

    #[Test]
    public function a_user_cannot_accept_on_another_users_behalf(): void
    {
        // The body claims the acceptance belongs to someone else. The account is
        // taken from the bearer token, so the claimed id is simply not read.
        $this->actingAs($this->admin)
            ->postJson('/api/terms/acceptance', [
                'terms_version' => '1.0-draft',
                'user_id' => $this->otherAdmin->id,
            ])
            ->assertStatus(201);

        $this->assertDatabaseHas('terms_acceptances', ['user_id' => $this->admin->id]);
        $this->assertDatabaseMissing('terms_acceptances', ['user_id' => $this->otherAdmin->id]);
        $this->assertSame(1, TermsAcceptance::count());
    }

    #[Test]
    public function accepting_the_same_version_twice_keeps_one_record_and_its_original_time(): void
    {
        $first = $this->actingAs($this->admin)
            ->postJson('/api/terms/acceptance', ['terms_version' => '1.0-draft'])
            ->assertStatus(201);

        $second = $this->actingAs($this->admin)
            ->postJson('/api/terms/acceptance', ['terms_version' => '1.0-draft'])
            ->assertStatus(200)
            ->assertJsonPath('already_accepted', true);

        $this->assertSame($first->json('accepted_at'), $second->json('accepted_at'));
        $this->assertSame(1, TermsAcceptance::count());
    }

    #[Test]
    public function a_missing_version_is_a_validation_error(): void
    {
        $this->actingAs($this->admin)
            ->postJson('/api/terms/acceptance', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('terms_version');
    }

    #[Test]
    public function a_new_published_version_puts_a_user_back_to_not_accepted(): void
    {
        $this->actingAs($this->admin)
            ->postJson('/api/terms/acceptance', ['terms_version' => '1.0-draft'])
            ->assertStatus(201);

        // The college adopts a revised version.
        Config::set('legal.terms.version', '2.0');

        $this->actingAs($this->admin)
            ->getJson('/api/terms/acceptance')
            ->assertOk()
            ->assertJsonPath('current_version', '2.0')
            ->assertJsonPath('accepted', false)
            ->assertJsonPath('latest_accepted.version', '1.0-draft')
            ->assertJsonPath('latest_accepted.is_current_version', false);
    }

    #[Test]
    public function accepting_a_new_version_keeps_the_earlier_record(): void
    {
        $this->actingAs($this->admin)
            ->postJson('/api/terms/acceptance', ['terms_version' => '1.0-draft'])
            ->assertStatus(201);

        Config::set('legal.terms.version', '2.0');

        $this->actingAs($this->admin)
            ->postJson('/api/terms/acceptance', ['terms_version' => '2.0'])
            ->assertStatus(201);

        // Append-only: the history of what was accepted is still there.
        $this->assertDatabaseHas('terms_acceptances', ['user_id' => $this->admin->id, 'terms_version' => '1.0-draft']);
        $this->assertDatabaseHas('terms_acceptances', ['user_id' => $this->admin->id, 'terms_version' => '2.0']);
        $this->assertSame(2, TermsAcceptance::count());
    }

    #[Test]
    public function the_pending_list_names_the_accounts_that_have_not_accepted(): void
    {
        $this->actingAs($this->admin)
            ->postJson('/api/terms/acceptance', ['terms_version' => '1.0-draft'])
            ->assertStatus(201);

        $response = $this->actingAs($this->admin)
            ->getJson('/api/terms/acceptances')
            ->assertOk()
            ->assertJsonPath('current_version', '1.0-draft')
            ->assertJsonCount(1, 'accepted')
            ->assertJsonCount(1, 'pending');

        $this->assertSame($this->admin->id, $response->json('accepted.0.user_id'));
        $this->assertSame($this->otherAdmin->id, $response->json('pending.0.user_id'));

        // The list carries the fields the Users page already shows, and nothing
        // that belongs to authentication.
        $this->assertSame(
            ['user_id', 'name', 'email', 'role'],
            array_keys($response->json('pending.0'))
        );
    }

    #[Test]
    public function the_acceptance_list_is_closed_to_guests(): void
    {
        $this->getJson('/api/terms/acceptances')->assertStatus(401);
    }

    #[Test]
    public function the_acceptance_list_is_admin_only(): void
    {
        $notAnAdmin = User::create([
            'name' => 'Non Admin',
            'email' => 'nobody@test.local',
            'password' => bcrypt('password'),
            'role' => 'faculty',
        ]);

        $this->actingAs($notAnAdmin)->getJson('/api/terms/acceptances')->assertStatus(403);
    }

    #[Test]
    public function a_deleted_account_leaves_the_acceptance_history_behind(): void
    {
        $this->actingAs($this->otherAdmin)
            ->postJson('/api/terms/acceptance', ['terms_version' => '1.0-draft'])
            ->assertStatus(201);

        $this->otherAdmin->delete();

        // The record outlives the account; only the attribution is cleared, the
        // same way `schedules.approved_by` is nulled when an approver is removed.
        $this->assertDatabaseCount('terms_acceptances', 1);
        $this->assertDatabaseHas('terms_acceptances', [
            'user_id' => null,
            'terms_version' => '1.0-draft',
        ]);
    }

    #[Test]
    public function suspending_the_prompt_still_reports_the_published_version(): void
    {
        Config::set('legal.acceptance.required', false);

        $this->actingAs($this->admin)
            ->getJson('/api/terms/acceptance')
            ->assertOk()
            ->assertJsonPath('required', false)
            ->assertJsonPath('current_version', '1.0-draft');
    }
}
