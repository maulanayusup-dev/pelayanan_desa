<?php

namespace App\Http\Middleware;

use App\Services\PublicUrlService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Exception\SuspiciousOperationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Memastikan setiap request HTTP (di seluruh environment termasuk local & testing)
 * memakai Host header yang tervalidasi dan masuk allowlist aplikasi.
 */
class EnsureTrustedHost
{
    public function __construct(
        private readonly PublicUrlService $urlService,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->headers->has('HOST')) {
            $rawHost = $request->headers->get('HOST');
            if ($this->urlService->normalizeHost(is_string($rawHost) ? $rawHost : null) === null) {
                abort(400, 'Host header tidak valid.');
            }
        }

        if ($request->isFromTrustedProxy() && $request->headers->has('X-Forwarded-Host')) {
            $forwardedHost = $request->headers->get('X-Forwarded-Host');
            if ($this->urlService->normalizeHost(is_string($forwardedHost) ? $forwardedHost : null) === null) {
                abort(400, 'Forwarded host tidak valid.');
            }
        }

        try {
            $resolvedHost = $request->getHost();
        } catch (SuspiciousOperationException) {
            abort(400, 'Host header tidak valid.');
        }

        if (! $this->urlService->isHostAllowed($resolvedHost)) {
            abort(400, 'Host tidak diizinkan.');
        }

        return $next($request);
    }
}

