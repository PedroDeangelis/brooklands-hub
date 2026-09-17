<?php

namespace App\Http\Middleware;

use Carbon\Carbon;
use Closure;
use Illuminate\Http\Request;

class VerifyBcSecret
{
    public function handle(Request $request, Closure $next)
    {
        $provided = $request->query('secret');

        if (! $provided || ! $this->isValid($provided)) {
            abort(403, 'Forbidden');
        }

        return $next($request);
    }

    private function isValid(string $provided): bool
    {
        $valid = [
            $this->tokenForDate(Carbon::now()),
            $this->tokenForDate(Carbon::now()->subDay()),
        ];

        return in_array($provided, $valid, true);
    }

    private function tokenForDate(Carbon $date): string
    {
        $day = $date->format('Y-m-d');
        $base = config('app.bc_route_secret');

        return hash_hmac('sha256', $day, $base);
    }
}
