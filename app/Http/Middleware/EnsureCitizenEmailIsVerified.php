<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureCitizenEmailIsVerified
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Only enforce email verification on citizens (masyarakat)
        if ($user && ($user->role?->name === 'masyarakat' || !$user->role) && !$user->hasVerifiedEmail()) {
            return $request->expectsJson()
                ? abort(403, 'Alamat email Anda belum diverifikasi. Silakan periksa kotak masuk atau spam email Anda.')
                : redirect()->route('verification.notice');
        }

        return $next($request);
    }
}
