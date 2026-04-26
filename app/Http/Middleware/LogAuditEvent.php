<?php

// ─── LogAuditEvent ────────────────────────────────────────────────────────────
// Route middleware that records a PHI (Protected Health Information) access
// event to AuditLog after any successful (2xx) request.
//
// Routes opt in by passing an action label, e.g. ->middleware('audit.log:phi_read').
// The middleware extracts the resource type from the URL and any numeric ID
// from the route parameters, then writes one AuditLog row per request.
//
// Notable rules:
//  - HIPAA 45 CFR §164.312(b) requires an audit trail of every system access
//    that touches patient data. This middleware is the catch-all that
//    guarantees coverage even for read-only views.
//  - Append-only: AuditLog rows are never edited or deleted (non-repudiation).
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Http\Middleware;

use App\Models\AuditLog;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class LogAuditEvent
{
    /**
     * Log a PHI access event for routes that touch participant data.
     *
     * Usage: ->middleware('audit.log:phi_read') or ->middleware('audit.log:phi_write')
     */
    public function handle(Request $request, Closure $next, string $action = 'phi_read'): Response
    {
        $response = $next($request);

        // Only log successful reads/writes (2xx)
        if ($response->isSuccessful() && Auth::check()) {
            $user = Auth::user();

            AuditLog::record(
                action: $action,
                tenantId: $user->tenant_id,
                userId: $user->id,
                resource_type: $this->resolveResourceType($request),
                resource_id: $this->resolveResourceId($request),
                description: "{$request->method()} {$request->path()}",
            );
        }

        return $response;
    }

    private function resolveResourceType(Request $request): ?string
    {
        // Extract resource type from the route name or URL segment
        $segments = $request->segments();

        return $segments[0] ?? null;
    }

    private function resolveResourceId(Request $request): ?int
    {
        // Try to extract a numeric ID from the route parameters
        $params = $request->route()?->parameters() ?? [];

        foreach ($params as $value) {
            if (is_numeric($value)) {
                return (int) $value;
            }
        }

        return null;
    }
}
