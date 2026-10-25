<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Mail\ResetCodeMail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:6',
        ]);

        $user = User::create($data + ['role' => 'user']);
        $token = auth('api')->login($user);

        return $this->respond($token, $user, 201);
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        if (! $token = auth('api')->attempt($credentials)) {
            return response()->json(['message' => 'Email atau password salah'], 401);
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

        $user->update(['password' => $data['password']]);
        DB::table('password_reset_tokens')->where('email', $data['email'])->delete();
        Cache::forget($triesKey);

        return response()->json(['message' => 'Password berhasil diubah, silakan login']);
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

    private function respond(string $token, User $user, int $status = 200)
    {
        return response()->json([
            'token' => $token,
            'token_type' => 'Bearer',
            'expires_in' => auth('api')->factory()->getTTL() * 60,
            'user' => $user,
        ], $status);
    }
}
