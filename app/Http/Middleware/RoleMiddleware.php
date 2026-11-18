<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RoleMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next, ...$roles): Response
    {
        $user = $request->user() ?? auth()->user();

        if (! $user) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json(['message' => 'Tidak terautentikasi.'], 401);
            }

            return redirect('login');
        }

        if (! $user->isActiveAccount()) {
            return EnsureActiveUser::denyInactiveResponse($request, $user);
        }

        $userRole = $user->role?->name;
        
        if (!$userRole) {
            abort(403, 'Akun Anda belum memiliki peran/role yang valid.');
        }

        // If no roles specified, assume any admin role (not masyarakat)
        if (empty($roles)) {
            if ($userRole === 'masyarakat') {
                abort(403, 'Akses ditolak.');
            }
        } else {
            $allowedRoles = [];
            foreach ($roles as $r) {
                $allowedRoles[] = strtolower($r);
                if ($r === 'kades') $allowedRoles[] = 'kepala_desa';
                if ($r === 'kepala_desa') $allowedRoles[] = 'kades';
            }

            if (!in_array(strtolower($userRole), $allowedRoles)) {
                abort(403, 'Akses ditolak.');
            }
        }

        return $next($request);
    }
}
