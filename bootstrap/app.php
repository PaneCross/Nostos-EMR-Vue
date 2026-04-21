<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            \App\Http\Middleware\HandleInertiaRequests::class,
        ]);

        // OTP auth endpoints are exempt from CSRF — the OTP code itself is the
        // security mechanism, and the XSRF-TOKEN cookie is not yet available on
        // the login page before any authenticated session is established.
        $middleware->validateCsrfTokens(except: [
            '/auth/request-otp',
            '/auth/verify-otp',
            '/super-admin/view-as',             // Auth-gated by requireSuperAdmin(); CSRF redundant
            '/billing/hos-m',                   // Auth-gated by finance/primary_care/it_admin
            '/billing/hos-m/*',                 // Includes update + submit routes
            '/scheduling/day-center/manage/bulk',// Auth-gated by activities/it_admin
        ]);

        $middleware->alias([
            'department.access' => \App\Http\Middleware\CheckDepartmentAccess::class,
            'fhir.auth'         => \App\Http\Middleware\FhirAuthMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
