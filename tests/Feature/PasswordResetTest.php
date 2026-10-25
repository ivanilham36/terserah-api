<?php

namespace Tests\Feature;

use App\Mail\ResetCodeMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Mail::fake();
    }

    private function requestCode(User $u): string
    {
        $this->postJson('/api/auth/forgot-password', ['email' => $u->email])->assertOk();
        $code = null;
        Mail::assertSent(ResetCodeMail::class, function ($m) use (&$code) {
            $code = $m->code;

            return true;
        });

        return $code;
    }

    public function test_full_reset_flow_changes_password(): void
    {
        $u = User::factory()->create(['password' => 'lama123']);
        $code = $this->requestCode($u);

        $this->assertSame(6, strlen($code));
        $this->postJson('/api/auth/reset-password', ['email' => $u->email, 'code' => $code, 'password' => 'baru1234'])->assertOk();

        $this->postJson('/api/auth/login', ['email' => $u->email, 'password' => 'baru1234'])->assertOk();
        $this->postJson('/api/auth/login', ['email' => $u->email, 'password' => 'lama123'])->assertStatus(401);
    }

    public function test_unknown_email_gets_same_response_and_no_mail(): void
    {
        $this->postJson('/api/auth/forgot-password', ['email' => 'tidakada@x.test'])
            ->assertOk()->assertJson(['message' => 'Jika email terdaftar, kode reset sudah dikirim']);
        Mail::assertNothingSent();
    }

    public function test_wrong_code_is_rejected_and_locks_after_five_tries(): void
    {
        $u = User::factory()->create();
        $code = $this->requestCode($u);
        $wrong = $code === '000000' ? '111111' : '000000';

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/reset-password', ['email' => $u->email, 'code' => $wrong, 'password' => 'baru1234'])->assertStatus(422);
        }
        // kode benar pun ditolak setelah 5 kali salah
        $this->postJson('/api/auth/reset-password', ['email' => $u->email, 'code' => $code, 'password' => 'baru1234'])->assertStatus(422);
    }

    public function test_expired_code_is_rejected(): void
    {
        $u = User::factory()->create();
        $code = $this->requestCode($u);
        DB::table('password_reset_tokens')->where('email', $u->email)->update(['created_at' => now()->subMinutes(16)]);

        $this->postJson('/api/auth/reset-password', ['email' => $u->email, 'code' => $code, 'password' => 'baru1234'])->assertStatus(422);
    }

    public function test_code_is_single_use(): void
    {
        $u = User::factory()->create();
        $code = $this->requestCode($u);

        $this->postJson('/api/auth/reset-password', ['email' => $u->email, 'code' => $code, 'password' => 'baru1234'])->assertOk();
        $this->postJson('/api/auth/reset-password', ['email' => $u->email, 'code' => $code, 'password' => 'lagi1234'])->assertStatus(422);
    }

    public function test_new_password_must_be_valid(): void
    {
        $u = User::factory()->create();
        $code = $this->requestCode($u);

        $this->postJson('/api/auth/reset-password', ['email' => $u->email, 'code' => $code, 'password' => '123'])->assertStatus(422);
    }
}
