<?php

namespace App\Http\Middleware;

use App\Models\Admin;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RestrictSwaggerAccess
{
    /**
     * Authorized administrator roles permitted to view internal API documentation.
     * Restricts access to core operations and security engineering roles.
     *
     * @var array<int, string>
     */
    protected const ALLOWED_ROLES = [
        'super_admin',
        'infra_admin',
        'security_engineer',
    ];

    /**
     * Handle an incoming request for Swagger/OpenAPI documentation.
     *
     * Enforces authentication via web session or Sanctum bearer token and
     * strictly verifies the user is an Admin with an authorized role.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = auth('web')->user() ?? auth('sanctum')->user() ?? $request->user();

        if (! $user) {
            if ($request->expectsJson() || $request->is('docs*')) {
                return response()->json([
                    'message' => 'Unauthenticated.',
                ], 401);
            }

            return redirect()->guest(route('login'));
        }

        if (! ($user instanceof Admin) || ! in_array($user->role, self::ALLOWED_ROLES, true)) {
            if ($request->expectsJson() || $request->is('docs*')) {
                return response()->json([
                    'message' => 'Forbidden. Restricted to authorized administrators.',
                ], 403);
            }

            abort(403, 'Forbidden. Restricted to authorized administrators.');
        }

        return $next($request);
    }
}
