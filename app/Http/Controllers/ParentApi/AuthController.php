<?php

namespace App\Http\Controllers\ParentApi;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ]);

        $user = User::where('email', $data['email'])->first();

        // One message for every failure: never reveal which part was wrong
        if (! $user || ! Hash::check($data['password'], $user->password)
            || ! $user->school_id || ! $user->hasRole('parent')) {
            return response()->json(['message' => 'These credentials do not match our records.'], 422);
        }

        $token = $user->createToken($data['device_name'] ?? 'parent app', ['parent'])->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email],
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['ok' => true]);
    }
}
