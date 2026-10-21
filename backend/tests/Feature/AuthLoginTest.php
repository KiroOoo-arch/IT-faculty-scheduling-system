<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The login endpoint's failure codes.
 *
 * A wrong password is an authentication failure (401), not a malformed request
 * (422). Clients branch on this: 422 sends them looking for a bad field, while
 * 401 is the signal to re-prompt for credentials.
 */
class AuthLoginTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create([
            'name' => 'Department Head',
            'email' => 'admin@test.local',
            'password' => bcrypt('password'),
            'role' => 'admin',
        ]);
    }

    #[Test]
    public function valid_credentials_return_a_token(): void
    {
        $this->postJson('/api/login', [
            'email' => 'admin@test.local',
            'password' => 'password',
        ])
            ->assertStatus(200)
            ->assertJsonStructure(['user', 'token']);
    }

    #[Test]
    public function a_wrong_password_is_a_401_not_a_422(): void
    {
        $this->postJson('/api/login', [
            'email' => 'admin@test.local',
            'password' => 'not-the-password',
        ])
            ->assertStatus(401)
            ->assertJsonPath('message', 'The provided credentials are incorrect.');
    }

    #[Test]
    public function an_unknown_email_is_a_401(): void
    {
        $this->postJson('/api/login', [
            'email' => 'nobody@test.local',
            'password' => 'password',
        ])->assertStatus(401);
    }

    #[Test]
    public function a_malformed_request_is_still_a_422(): void
    {
        // Missing fields stay a validation problem, so the two failure modes
        // remain distinguishable from each other.
        $this->postJson('/api/login', [])->assertStatus(422);
    }
}
