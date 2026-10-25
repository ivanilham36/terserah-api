<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Mail\ResetCodeMail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        $request->merge(['username' => Str::lower((string) $request->input('username'))]);

        $data = $request->validate([
            'name' => 'required|string|max:100',
            'username' => ['required', 'string', 'min:3', 'max:30', 'regex:/^[a-z0-9_.]+$/', 'unique:users,username'],
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:6',
        ], ['username.regex' => 'Username hanya boleh huruf kecil, angka, titik, dan underscore']);

        $user = User::create($data + ['role' => 'user']);
        $token = auth('api')->login($user);

        return $this->respond($token, $user, 201);
    }

    public function login(Request $request)
    {
        $request->validate([
            'login' => 'required_without:email|string',
            'email' => 'required_without:login|string',
            'password' => 'required|string',
        ]);

        // boleh pakai email atau username
        $login = trim((string) $request->input('login', $request->input('email')));
        $field = str_contains($login, '@') ? 'email' : 'username';

        if (! $token = auth('api')->attempt([$field => Str::lower($login), 'password' => $request->password])) {
            return response()->json(['message' => 'Email/username atau password salah'], 401);
        }

        return $this->respond($token, auth('api')->user());
    }

    // Langkah 1: kirim kode 6 digit ke email. Respons selalu sama agar email terdaftar tidak bisa ditebak.
    public function forgotPassword(Request $request)
    {
        $data = $request->validate(['email' => 'required|email']);
        $user = User::where('email', $data['email'])->first();

        if ($user) {
            $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            DB::table('password_reset_tokens')->updateOrInsert(
                ['email' => $user->email],
                ['token' => Hash::make($code), 'created_at' => now()]
            );
            Cache::forget('reset-tries:' . $user->email);

            try {
                Mail::to($user->email)->send(new ResetCodeMail($user->name, $code));
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return response()->json(['message' => 'Jika email terdaftar, kode reset sudah dikirim']);
    }

    // Langkah 2: kode + password baru. Kode berlaku 15 menit, maksimal 5 percobaan, sekali pakai.
    public function resetPassword(Request $request)
    {
        $data = $request->validate([
            'email' => 'required|email',
            'code' => 'required|digits:6',
            'password' => 'required|string|min:6',
        ]);

        $invalid = response()->json(['message' => 'Kode salah atau sudah kedaluwarsa'], 422);
        $triesKey = 'reset-tries:' . $data['email'];
        $row = DB::table('password_reset_tokens')->where('email', $data['email'])->first();

        if (! $row || now()->diffInMinutes($row->created_at, true) > 15 || Cache::get($triesKey, 0) >= 5) {
            return $invalid;
        }
        if (! Hash::check($data['code'], $row->token)) {
            Cache::put($triesKey, Cache::get($triesKey, 0) + 1, now()->addMinutes(15));

            return $invalid;
        }

        $user = User::where('email', $data['email'])->first();
        if (! $user) {
            return $invalid;
        }

        // kode dikirim ke emailnya, jadi kepemilikan email terbukti
        $user->update(['password' => $data['password'], 'email_verified_at' => $user->email_verified_at ?? now()]);
        DB::table('password_reset_tokens')->where('email', $data['email'])->delete();
        Cache::forget($triesKey);

        return response()->json(['message' => 'Password berhasil diubah, silakan login']);
    }

    // Login/daftar dengan Google (token ID dari Google Identity Services)
    public function google(Request $request)
    {
        $request->validate(['credential' => 'required|string']);
        $clientId = config('services.google.client_id');

        if (! $clientId) {
            return response()->json(['message' => 'Login Google belum dikonfigurasi'], 503);
        }

        try {
            $res = Http::timeout(8)->get('https://oauth2.googleapis.com/tokeninfo', ['id_token' => $request->credential]);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Tidak bisa menghubungi Google, coba lagi'], 503);
        }

        $info = $res->successful() ? $res->json() : null;
        $verified = $info && in_array($info['email_verified'] ?? false, [true, 'true'], true);

        if (! $info || ($info['aud'] ?? null) !== $clientId || ! $verified || empty($info['email']) || empty($info['sub'])) {
            return response()->json(['message' => 'Token Google tidak valid'], 401);
        }

        $email = Str::lower($info['email']);
        $merged = false;
        $passwordCleared = false;
        $user = User::where('google_id', $info['sub'])->first();

        if (! $user && ($user = User::where('email', $email)->first())) {
            // akun email yang sama sudah ada: gabungkan. Pendaftaran biasa tidak memverifikasi email, jadi
            // password lama dihapus (bisa dipasang ulang lewat Lupa password) agar akun tidak bisa dibajak.
            $merged = true;
            $passwordCleared = ! $user->email_verified_at;
            $update = ['google_id' => $info['sub']];
            if (! $user->email_verified_at) {
                $update['password'] = Str::random(40);
                $update['email_verified_at'] = now();
            }
            $user->update($update);
        }

        $created = false;
        if (! $user) {
            $created = true;
            $user = User::create([
                'name' => $info['name'] ?? Str::before($email, '@'),
                'username' => $this->uniqueUsername(Str::before($email, '@')),
                'email' => $email,
                'google_id' => $info['sub'],
                'password' => Str::random(40),
                'role' => 'user',
            ]);
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        $token = auth('api')->login($user);

        return $this->respond($token, $user, $created ? 201 : 200, ['merged' => $merged, 'password_cleared' => $passwordCleared]);
    }

    private function uniqueUsername(string $base): string
    {
        $base = Str::of($base)->lower()->replaceMatches('/[^a-z0-9_.]/', '')->substr(0, 24)->toString() ?: 'user';
        $name = $base;
        $i = 1;
        while (User::where('username', $name)->exists()) {
            $name = $base . $i++;
        }

        return $name;
    }

    public function me()
    {
        return response()->json(auth('api')->user());
    }

    public function logout()
    {
        auth('api')->logout();

        return response()->json(['message' => 'Berhasil logout']);
    }

    public function refresh()
    {
        return $this->respond(auth('api')->refresh(), auth('api')->user());
    }

    private function respond(string $token, User $user, int $status = 200, array $extra = [])
    {
        return response()->json([
            'token' => $token,
            'token_type' => 'Bearer',
            'expires_in' => auth('api')->factory()->getTTL() * 60,
            'user' => $user,
        ] + $extra, $status);
    }
}
