<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class RequestForensicsMiddleware
{
    /**
     * Handle an incoming request and log route forensics if enabled.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (!config('app.request_forensics_enabled', false)) {
            return $response;
        }

        try {
            $timestamp = now()->toIso8601String();
            $method = $request->method();
            $route = $request->route();
            $routeUri = $route ? $route->uri() : $request->path();
            $routeName = $route ? ($route->getName() ?? '-') : '-';
            
            $user = $request->user();
            $role = $user?->role ?? ($user ? 'authenticated' : 'guest');
            $status = $response->getStatusCode();

            $logEntry = sprintf(
                "[FORENSIC] %s | %s | %s | %s | %s | %d",
                $timestamp,
                $method,
                $routeUri,
                $routeName,
                $role,
                $status
            );

            Log::channel('request_forensics')->info($logEntry);
        } catch (\Throwable $e) {
            // Diagnostic middleware must never disrupt application traffic
        }

        return $response;
    }
}
