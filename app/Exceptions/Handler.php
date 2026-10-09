<?php

namespace App\Exceptions;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Validation\ValidationException;
use PDOException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Throwable;

class Handler extends ExceptionHandler
{
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    public function register(): void
    {
        $this->reportable(function (Throwable $e) {
            //
        });
    }

    public function render($request, Throwable $e)
    {
        if ($request->expectsJson() || $request->is('api/*')) {
            // 1. Validation Failure
            if ($e instanceof ValidationException) {
                return response()->json([
                    'status' => 'error',
                    'code' => 422,
                    'message' => 'Validasi gagal',
                    'errors' => $e->errors(),
                ], 422);
            }

            // 2. Authentication Failure
            if ($e instanceof AuthenticationException) {
                return response()->json([
                    'status' => 'error',
                    'code' => 401,
                    'message' => 'Unauthenticated / Token tidak valid',
                ], 401);
            }

            // 3. Authorization Failure
            if ($e instanceof AuthorizationException) {
                return response()->json([
                    'status' => 'error',
                    'code' => 403,
                    'message' => 'Akses dilarang / Hak akses tidak cukup',
                ], 403);
            }

            // 4. Rate Limiting (Throttle)
            if ($e instanceof ThrottleRequestsException || $e instanceof TooManyRequestsHttpException) {
                return response()->json([
                    'status' => 'error',
                    'code' => 429,
                    'message' => 'Terlalu banyak permintaan (Rate limit exceeded). Silakan coba beberapa saat lagi.',
                ], 429);
            }

            // 5. Resource Not Found
            if ($e instanceof ModelNotFoundException || $e instanceof NotFoundHttpException) {
                return response()->json([
                    'status' => 'error',
                    'code' => 404,
                    'message' => 'Data / Resource tidak ditemukan',
                ], 404);
            }

            // 6. Database / PDO Exception (Prevent Information Disclosure)
            if ($e instanceof QueryException || $e instanceof PDOException) {
                return response()->json([
                    'status' => 'error',
                    'code' => 500,
                    'message' => config('app.debug')
                        ? 'Database Error: ' . $e->getMessage()
                        : 'Terjadi kesalahan internal pada basis data server (Internal Database Error).',
                ], 500);
            }

            // 7. General HTTP Exception
            if ($e instanceof HttpException) {
                $statusCode = $e->getStatusCode();
                $message = $e->getMessage();
                if (!$message || ($statusCode >= 500 && !config('app.debug'))) {
                    $message = 'Internal Server Error';
                }
                return response()->json([
                    'status' => 'error',
                    'code' => $statusCode,
                    'message' => $message,
                ], $statusCode);
            }

            // 8. General Unhandled Server Exception (Strict Masking for APP_DEBUG=false)
            $statusCode = method_exists($e, 'getStatusCode') ? $e->getStatusCode() : 500;
            $message = (config('app.debug') || $statusCode < 500)
                ? ($e->getMessage() ?: 'Internal Server Error')
                : 'Internal Server Error';

            return response()->json([
                'status' => 'error',
                'code' => $statusCode,
                'message' => $message,
            ], $statusCode);
        }

        return parent::render($request, $e);
    }
}
