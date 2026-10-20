<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class HomeController extends Controller
{
    public function __invoke(Request $request)
    {
        $user = $request->user();

        // A super-admin belongs to no school: the schools list is their home
        if ($user->school_id === null && $user->hasRole('super-admin')) {
            return redirect()->route('super.schools');
        }

        return view('dashboard');
    }
}
