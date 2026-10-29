<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Protects /internal/* routes: only the Node bot (with the shared token) may call them. */
class VerifyInternalToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('restaurant.internal_token');

        if (strlen($expected) < 32) {
            return response()->json([
                'error' => 'not_configured',
                'message' => 'WM_INTERNAL_TOKEN is missing or shorter than 32 characters in admin/.env',
            ], 503);
        }

        $given = (string) $request->bearerToken();
        if ($given === '' || ! hash_equals($expected, $given)) {
            return response()->json(['error' => 'unauthorized', 'message' => 'Invalid internal token.'], 401);
        }

        return $next($request);
    }
}
