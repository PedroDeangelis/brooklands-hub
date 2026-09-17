<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyBcSignedUrl
{
    public function handle(Request $request, Closure $next): Response
    {

        $secret = env('BC_ROUTE_SECRET');
        if (! $secret) {
            abort(500, 'BC route secret not configured');
        }

        // New format: ?uid=&expires=&sig=
        if ($request->query('sig') !== null) {
            $uid = $request->query('uid');
            $expires = $request->query('expires');
            $sig = $request->query('sig');

            if (! ctype_digit((string) $uid) || ! ctype_digit((string) $expires)) {
                abort(403);
            }
            if ((int) $expires < time()) {
                abort(403, 'URL expired');
            }

            $path = $request->getPathInfo(); // e.g. "/bc-image/123"
            $message = $path."\n".(int) $uid."\n".(int) $expires;
            $expected = hash_hmac('sha256', $message, $secret);

            if (! hash_equals($expected, (string) $sig)) {
                abort(403, 'Invalid signature');
            }

            // Expose uid to the controller for authorization checks.
            $request->attributes->set('bc_uid', (int) $uid);

            return $next($request);
        }

        // Legacy format (remove after WP cutover): ?secret=<daily-token>
        $legacy = $request->query('secret');
        if ($legacy !== null) {
            // Tolerate ±1 day for tz / clock skew around midnight.
            foreach ([-1, 0] as $offset) {
                $day = date('Y-m-d', time() + $offset * 86400);
                $expected = hash_hmac('sha256', $day, $secret);
                if (hash_equals($expected, (string) $legacy)) {
                    return $next($request);
                }
            }
            abort(403, 'Invalid signature');
        }

        abort(403, 'Missing signature');
    }
}
