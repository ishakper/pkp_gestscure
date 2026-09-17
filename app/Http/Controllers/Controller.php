<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Routing\Controller as BaseController;
use OpenApi\Attributes as OA;

#[OA\Info(
    version: "1.0.0",
    title: "PKP SecureGate API Documentation",
    description: "Dokumentasi API Otomatis PKP SecureGate — Sistem Keamanan Access Control, Manajemen Presensi & Enterprise Operations."
)]
#[OA\Server(
    url: "/api/v1",
    description: "PKP SecureGate API v1 Server"
)]
#[OA\SecurityScheme(
    securityScheme: "sanctum",
    type: "http",
    name: "Authorization",
    in: "header",
    bearerFormat: "JWT",
    scheme: "bearer",
    description: "Masukkan token autentikasi Sanctum (Bearer <token>)"
)]
class Controller extends BaseController
{
    use AuthorizesRequests, ValidatesRequests;
}
