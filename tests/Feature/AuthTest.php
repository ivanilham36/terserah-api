<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_register_returns_token_and_user(): void
    {
        $res = $this->postJson('/api/auth/register', [
            'name' => 'Amy', 'email' => 'amy@test.com', 'password' => 'secret123',
        ]);

        $res->assertStatus(201)->assertJsonStructure(['token', 'token_type', 'expires_in', 'user' => ['id', 'name', 'email']]);
        $this->assertDatabaseHas('users', ['email' => 'amy@test.com', 'role' => 'user']);
    }

    public function test_register_rejects_duplicate_email(): void
    {
        User::factory()->create(['email' => 'a@test.com']);

        $this->postJson('/api/auth/register', [
            'name' => 'Lain', 'email' => 'a@test.com', 'password' => 'secret123',
        ])->assertStatus(422)->assertJsonValidationErrors(['email']);
    }

    public function test_login_with_wrong_password_is_unauthorized(): void
    {
        User::factory()->create(['email' => 'a@test.com']);

        $this->postJson('/api/auth/login', ['email' => 'a@test.com', 'password' => 'salah'])
            ->assertStatus(401);
    }

    public function test_login_then_access_me_with_token(): void
    {
        User::factory()->create(['email' => 'a@test.com']);

        $token = $this->postJson('/api/auth/login', ['email' => 'a@test.com', 'password' => 'password'])
            ->assertOk()->json('token');

        $this->withToken($token)->getJson('/api/auth/me')
            ->assertOk()->assertJsonPath('email', 'a@test.com');
    }

    public function test_me_without_token_is_unauthorized(): void
    {
        $this->getJson('/api/auth/me')->assertStatus(401);
    }
}
