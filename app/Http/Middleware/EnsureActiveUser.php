<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveUser
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user() ?? auth()->user();

        if ($user instanceof User && ! $user->isActiveAccount()) {
            return self::denyInactiveResponse($request, $user);
        }

        return $next($request);
    }

    /**
     * Revoke active credentials and return an explicit denial response for inactive or soft-deleted accounts.
     */
    public static function denyInactiveResponse(Request $request, User $user): Response
    {
        $currentToken = method_exists($user, 'currentAccessToken') ? $user->currentAccessToken() : null;
        if ($currentToken instanceof PersonalAccessToken) {
            $user->revokeAccessTokens();
        }

        if (Auth::guard('web')->check()) {
            Auth::guard('web')->logout();
        }

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        $message = 'Akun Anda sedang dinonaktifkan atau sudah tidak aktif. Hubungi pengelola desa.';

        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json([
                'status' => 'error',
                'message' => $message,
            ], 403);
        }

        return redirect()
            ->route('login')
            ->with('error', $message)
            ->withErrors(['email' => $message]);
    }
}

