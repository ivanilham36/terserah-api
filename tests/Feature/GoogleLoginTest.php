<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GoogleLoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.google.client_id' => 'client-123']);
    }

    private function fakeGoogle(array $over = []): void
    {
        Http::fake(['oauth2.googleapis.com/*' => Http::response(array_merge([
            'aud' => 'client-123', 'sub' => 'g-1', 'email' => 'new@gmail.com', 'email_verified' => 'true', 'name' => 'Orang Baru',
        ], $over))]);
    }

    public function test_new_google_user_is_registered_automatically(): void
    {
        $this->fakeGoogle();

        $res = $this->postJson('/api/auth/google', ['credential' => 'tok'])->assertStatus(201)
            ->assertJsonStructure(['token', 'user' => ['id', 'email', 'username']]);

        $this->assertSame('new', $res->json('user.username'));
        $this->assertDatabaseHas('users', ['email' => 'new@gmail.com', 'google_id' => 'g-1']);
    }

    public function test_existing_google_user_logs_in_again_without_duplicate(): void
    {
        $this->fakeGoogle();
        $this->postJson('/api/auth/google', ['credential' => 'tok'])->assertStatus(201);
        $this->postJson('/api/auth/google', ['credential' => 'tok'])->assertOk();

        $this->assertSame(1, User::where('email', 'new@gmail.com')->count());
    }

    public function test_same_email_account_is_merged_and_unverified_password_is_cleared(): void
    {
        $existing = User::factory()->unverified()->create(['email' => 'new@gmail.com', 'password' => 'rahasia123']);
        $this->fakeGoogle();

        $res = $this->postJson('/api/auth/google', ['credential' => 'tok'])->assertOk();

        $this->assertSame($existing->id, $res->json('user.id'));
        $this->assertTrue($res->json('merged'));
        $this->assertTrue($res->json('password_cleared'));
        $this->assertSame(1, User::count());
        $existing->refresh();
        $this->assertSame('g-1', $existing->google_id);
        // password lama (yang mungkin dipasang pembajak) tidak berlaku lagi
        $this->postJson('/api/auth/login', ['login' => 'new@gmail.com', 'password' => 'rahasia123'])->assertStatus(401);
    }

    public function test_verified_account_keeps_password_when_merged(): void
    {
        User::factory()->create(['email' => 'new@gmail.com', 'password' => 'rahasia123']);
        $this->fakeGoogle();

        $res = $this->postJson('/api/auth/google', ['credential' => 'tok'])->assertOk();

        $this->assertFalse($res->json('password_cleared'));
        $this->postJson('/api/auth/login', ['login' => 'new@gmail.com', 'password' => 'rahasia123'])->assertOk();
    }

    public function test_rejects_wrong_audience_unverified_email_and_bad_token(): void
    {
        $this->fakeGoogle(['aud' => 'client-lain']);
        $this->postJson('/api/auth/google', ['credential' => 'tok'])->assertStatus(401);

        $this->fakeGoogle(['email_verified' => 'false']);
        $this->postJson('/api/auth/google', ['credential' => 'tok'])->assertStatus(401);

        Http::fake(['oauth2.googleapis.com/*' => Http::response(['error' => 'invalid_token'], 400)]);
        $this->postJson('/api/auth/google', ['credential' => 'palsu'])->assertStatus(401);

        $this->assertSame(0, User::count());
    }

    public function test_unconfigured_client_id_returns_503(): void
    {
        config(['services.google.client_id' => null]);

        $this->postJson('/api/auth/google', ['credential' => 'tok'])->assertStatus(503);
    }

    public function test_username_collision_gets_suffix(): void
    {
        User::factory()->create(['username' => 'new']);
        $this->fakeGoogle();

        $this->postJson('/api/auth/google', ['credential' => 'tok'])->assertStatus(201)->assertJsonPath('user.username', 'new1');
    }
}
