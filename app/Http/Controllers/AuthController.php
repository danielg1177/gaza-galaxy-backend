<?php

namespace App\Http\Controllers;

use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Http\Requests\UpdatePasswordRequest;
use App\Http\Requests\UpdateUsernameRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function register(RegisterRequest $request): JsonResponse
    {
        $user = User::create([
            'username' => $request->username,
            'password' => $request->password,
        ]);

        $token = $user->createToken('app')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'username' => $user->username,
            ],
        ], 201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::where('username', $request->username)->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            return response()->json(['message' => 'Invalid credentials'], 401);
        }

        $token = $user->createToken('app')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'username' => $user->username,
            ],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out']);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'id' => $user->id,
            'username' => $user->username,
        ]);
    }

    public function updateUsername(UpdateUsernameRequest $request): JsonResponse
    {
        $user = $request->user();
        $user->update(['username' => $request->username]);

        return response()->json([
            'id' => $user->id,
            'username' => $user->username,
        ]);
    }

    public function updatePassword(UpdatePasswordRequest $request): JsonResponse
    {
        $user = $request->user();

        if (! Hash::check($request->current_password, $user->password)) {
            return response()->json([
                'message' => 'Current password is incorrect',
                'errors' => ['current_password' => ['Current password is incorrect']],
            ], 422);
        }

        $user->update(['password' => $request->password]);

        return response()->json(['message' => 'Password updated']);
    }
}
