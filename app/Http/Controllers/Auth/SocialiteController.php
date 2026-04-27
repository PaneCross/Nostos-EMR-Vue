<?php

// ─── SocialiteController ──────────────────────────────────────────────────────
// Optional OAuth login via Google or Yahoo (Laravel Socialite). Alternative
// sign-in path for users who have those accounts; OTP email login remains
// the primary method.
//
// Flow: GET /auth/{provider}/redirect → provider consent →
//       GET /auth/{provider}/callback → match an existing active User by
//       email → sign in. There is NO auto-provisioning: an unrecognized
//       email is rejected with a friendly error. IT Admin must create the
//       user account first.
//
// Notable rules:
//  - In local dev with no client_id configured, returns a friendly hint
//    rather than crashing.
//  - Successful + failed logins both write AuditLog rows (HIPAA access trail).
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;

class SocialiteController extends Controller
{
    private const SUPPORTED_PROVIDERS = ['google', 'yahoo'];

    /** Redirect to OAuth provider. */
    public function redirect(string $provider): RedirectResponse
    {
        if (! in_array($provider, self::SUPPORTED_PROVIDERS)) {
            return redirect()->route('login')->withErrors(['oauth' => 'Unsupported provider.']);
        }

        // In local dev, OAuth credentials won't be set : show friendly message.
        $clientId = config("services.{$provider}.client_id");

        if (empty($clientId)) {
            return redirect()->route('login')->withErrors([
                'oauth' => ucfirst($provider) . ' OAuth is not configured in local dev. '
                         . 'Set ' . strtoupper($provider) . '_CLIENT_ID in your .env to enable it.',
            ]);
        }

        return Socialite::driver($provider)->redirect();
    }

    /** Handle OAuth callback. */
    public function callback(string $provider, \Illuminate\Http\Request $request): RedirectResponse
    {
        if (! in_array($provider, self::SUPPORTED_PROVIDERS)) {
            return redirect()->route('login')->withErrors(['oauth' => 'Unsupported provider.']);
        }

        try {
            $socialUser = Socialite::driver($provider)->user();
        } catch (\Throwable $e) {
            return redirect()->route('login')->withErrors([
                'oauth' => 'Authentication failed. Please try again or use the OTP method.',
            ]);
        }

        $user = User::where('email', $socialUser->getEmail())
            ->where('is_active', true)
            ->first();

        if (! $user) {
            AuditLog::record(
                action: 'login_failed',
                description: "OAuth login attempted for unregistered email: {$socialUser->getEmail()} via {$provider}",
            );

            return redirect()->route('login')->withErrors([
                'oauth' => 'Account not found. Contact your administrator.',
            ]);
        }

        Auth::login($user, remember: false);
        $request->session()->regenerate();
        $request->session()->put('tenant_id', $user->tenant_id);
        $request->session()->put('user_department', $user->department);

        $user->update(['last_login_at' => now()]);

        AuditLog::record(
            action: 'login',
            tenantId: $user->tenant_id,
            userId: $user->id,
            description: "Login via {$provider} OAuth",
        );

        return redirect("/dashboard/{$user->department}");
    }
}
