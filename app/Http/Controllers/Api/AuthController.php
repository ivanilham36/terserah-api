<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;

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
