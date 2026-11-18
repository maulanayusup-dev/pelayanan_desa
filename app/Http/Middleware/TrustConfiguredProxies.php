<?php

namespace App\Http\Middleware;

use App\Services\PublicUrlService;
use Closure;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;

/**
 * Mengatur trusted proxies secara eksplisit dari config('app.trusted_proxies').
 * Default kosong agar klien LAN maupun pengirim tak tepercaya tidak dapat memalsukan X-Forwarded-* headers.
 */
class TrustConfiguredProxies extends TrustProxies
{
    private const FORWARDED_HEADER_FLAGS = Request::HEADER_X_FORWARDED_FOR
        | Request::HEADER_X_FORWARDED_HOST
        | Request::HEADER_X_FORWARDED_PORT
        | Request::HEADER_X_FORWARDED_PROTO
        | Request::HEADER_X_FORWARDED_PREFIX
        | Request::HEADER_X_FORWARDED_AWS_ELB;

    public function __construct(
        private readonly PublicUrlService $urlService,
    ) {}

    public function handle(Request $request, Closure $next)
    {
        // Reset eksplisit per request agar state trusted proxy statis tidak bocor antar request/test.
        $request::setTrustedProxies(
            $this->urlService->trustedProxies(),
            self::FORWARDED_HEADER_FLAGS
        );

        return $next($request);
    }
}

