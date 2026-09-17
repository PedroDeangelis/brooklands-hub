<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;

class GoogleController extends Controller
{
    public function redirect()
    {
        $allowedHd = config('services.google.allowed_hd');

        return Socialite::driver('google')
            ->with($allowedHd ? ['hd' => $allowedHd] : [])
            ->redirect();
    }

    public function callback()
    {
        $googleUser = Socialite::driver('google')->user();
        $raw = $googleUser->user ?? [];

        $allowedHd = config('services.google.allowed_hd');
        $emailVerified = $raw['email_verified'] ?? false;
        $hd = $raw['hd'] ?? null;

        if (! $emailVerified || ($allowedHd && $hd !== $allowedHd)) {
            abort(403, 'Unauthorized domain.');
        }

        $user = User::firstOrCreate([
            'email' => $googleUser->getEmail(),
        ], [
            'name' => $googleUser->getName(),
            'role' => 'user',
        ]);

        Auth::login($user);

        return redirect('/');
    }
}
