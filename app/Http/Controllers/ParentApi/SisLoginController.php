<?php

namespace App\Http\Controllers\ParentApi;

use App\Exceptions\SisLoginException;
use App\Http\Controllers\Controller;
use App\Services\Sis\SisParentAccounts;
use Illuminate\Http\Request;

class SisLoginController extends Controller
{
    /** The app signs in to the SIS, then sends the SIS token here. The canteen never sees the password. */
    public function login(Request $request, SisParentAccounts $accounts)
    {
        $data = $request->validate([
            'sis_token' => ['required', 'string', 'max:2000'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ]);

        try {
            $result = $accounts->login($data['sis_token'], $data['device_name'] ?? 'parent app');
        } catch (SisLoginException $e) {
            return response()->json(['message' => $e->getMessage(), 'reason' => $e->reason], $e->status);
        }

        return response()->json($result);
    }
}
