<?php

namespace App\Services;

use Carbon\CarbonInterface;
use DateInterval;
use DateTimeInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

/**
 * Pembentuk URL publik terpusat agar seluruh notifikasi, laporan,
 * tautan unduhan PDF bertanda tangan, dan QR verifikasi memakai sumber URL konsisten.
 */
class PublicUrlService
{
    /** @var list<string> */
    private const LOCAL_SAFE_HOSTS = ['localhost', '127.0.0.1', '::1'];

    /**
     * Ambil base URL kanonik dari konfigurasi APP_URL sebagai satu-satunya sumber host/scheme publik tepercaya.
     */
    public function canonicalBaseUrl(): string
    {
        $configured = trim((string) config('app.url', ''));
        $normalized = $this->normalizeBaseUrl($configured);

        return $normalized ?? 'http://127.0.0.1:8000';
    }

    /**
     * Ambil base URL publik aplikasi. Selalu mengandalkan konfigurasi kanonik APP_URL
     * agar Host header atau X-Forwarded-* yang tidak tepercaya tidak dapat memanipulasi URL.
     */
    public function baseUrl(?Request $request = null): string
    {
        return $this->canonicalBaseUrl();
    }

    /**
     * Bangun URL penuh dari path relatif (mis. '/admin/pengajuan/{id}').
     */
    public function to(string $path = '/', ?Request $request = null): string
    {
        $base = $this->baseUrl($request);
        $trimmed = ltrim($path, '/');

        return $trimmed === '' ? $base : $base . '/' . $trimmed;
    }

    /**
     * Bangun URL penuh dari named route memakai base URL publik kanonik.
     */
    public function route(string $name, mixed $parameters = [], ?Request $request = null): string
    {
        $relative = route($name, $parameters, false);

        return $this->to($relative, $request);
    }

    /**
     * Bangun signed URL relatif yang dipadukan dengan base URL publik kanonik
     * agar tautan unduhan tetap aman lintas host yang diizinkan tanpa memercayai Host sembarangan.
     */
    public function temporarySignedRoute(
        string $name,
        CarbonInterface|DateTimeInterface|DateInterval|int $expiration,
        array $parameters = [],
        ?Request $request = null
    ): string {
        $relative = URL::temporarySignedRoute($name, $expiration, $parameters, false);

        return $this->to($relative, $request);
    }

    /**
     * Bangun signed URL absolut menggunakan root & scheme kanonik APP_URL
     * untuk route yang memvalidasi signature penuh (seperti verifikasi email).
     */
    public function canonicalTemporarySignedRoute(
        string $name,
        CarbonInterface|DateTimeInterface|DateInterval|int $expiration,
        array $parameters = []
    ): string {
        $canonical = $this->canonicalBaseUrl();
        $scheme = strtolower((string) (parse_url($canonical, PHP_URL_SCHEME) ?: 'http'));

        /** @var \Illuminate\Routing\UrlGenerator $url */
        $url = app('url');
        $url->forceRootUrl($canonical);
        $url->forceScheme($scheme);

        try {
            return $url->temporarySignedRoute($name, $expiration, $parameters, true);
        } finally {
            $url->forceRootUrl(null);
            $url->forceScheme(null);
        }
    }

    /**
     * Bangun URL verifikasi email bertanda tangan memakai host kanonik APP_URL.
     */
    public function emailVerificationUrl(object $notifiable): string
    {
        return $this->canonicalTemporarySignedRoute(
            'verification.verify',
            now()->addMinutes((int) config('auth.verification.expire', 60)),
            [
                'id' => $notifiable->getKey(),
                'hash' => sha1($notifiable->getEmailForVerification()),
            ]
        );
    }

    /**
     * Daftar host yang diizinkan menerima request HTTP aplikasi (tanpa wildcard bebas).
     *
     * @return list<string>
     */
    public function allowedHosts(): array
    {
        $hosts = self::LOCAL_SAFE_HOSTS;

        $appHost = parse_url($this->canonicalBaseUrl(), PHP_URL_HOST);
        $normalizedAppHost = $this->normalizeHost(is_string($appHost) ? $appHost : null);
        if ($normalizedAppHost !== null) {
            $hosts[] = $normalizedAppHost;
        }

        foreach ($this->parseConfiguredList(config('app.trusted_hosts', '')) as $entry) {
            $normalized = $this->normalizeHost($entry);
            if ($normalized !== null) {
                $hosts[] = $normalized;
            }
        }

        return array_values(array_unique($hosts));
    }

    /**
     * Validasi apakah nilai Host (raw atau hasil parsing) masuk daftar host yang diizinkan.
     */
    public function isHostAllowed(?string $rawHost): bool
    {
        $normalized = $this->normalizeHost($rawHost);
        if ($normalized === null) {
            return false;
        }

        return in_array($normalized, $this->allowedHosts(), true);
    }

    /**
     * Normalisasi dan validasi string Host (opsional beserta port), menolak karakter injeksi.
     */
    public function normalizeHost(?string $rawHost): ?string
    {
        if (! is_string($rawHost) || $rawHost === '') {
            return null;
        }

        // Tolak spasi/kontrol, multiple host (koma), userinfo (@), path (/ \), wildcard (*), query/fragment, dll.
        if (preg_match('/[\x00-\x20\x7f,@\/\\\\?#*;]/', $rawHost) === 1) {
            return null;
        }

        if (str_starts_with($rawHost, '[')) {
            if (preg_match('/^\[([^\]]+)\](?::([0-9]{1,5}))?$/', $rawHost, $matches) !== 1) {
                return null;
            }

            if (isset($matches[2]) && ! $this->isValidPort($matches[2])) {
                return null;
            }

            return $this->normalizeIp($matches[1], FILTER_FLAG_IPV6);
        }

        if (filter_var($rawHost, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            return $this->normalizeIp($rawHost, FILTER_FLAG_IPV6);
        }

        if (substr_count($rawHost, ':') > 1) {
            return null;
        }

        $hostPart = $rawHost;
        if (str_contains($rawHost, ':')) {
            [$hostPart, $port] = explode(':', $rawHost, 2);
            if (! $this->isValidPort($port)) {
                return null;
            }
        }

        $lower = strtolower($hostPart);
        if ($lower === '' || strlen($lower) > 253) {
            return null;
        }

        if (filter_var($lower, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return $lower;
        }

        return preg_match('/^[a-z0-9](?:[a-z0-9_-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9_-]{0,61}[a-z0-9])?)*$/', $lower) === 1
            ? $lower
            : null;
    }

    /**
     * Daftar IP atau CIDR reverse proxy tepercaya dari konfigurasi (kosong secara default).
     *
     * @return list<string>
     */
    public function trustedProxies(): array
    {
        $proxies = [];

        foreach ($this->parseConfiguredList(config('app.trusted_proxies', '')) as $entry) {
            $normalized = $this->normalizeProxyEntry($entry);
            if ($normalized !== null) {
                $proxies[] = $normalized;
            }
        }

        return array_values(array_unique($proxies));
    }

    private function normalizeBaseUrl(string $url): ?string
    {
        $trimmed = rtrim(trim($url), '/');
        if ($trimmed === '') {
            return null;
        }

        $parts = parse_url($trimmed);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = (string) ($parts['host'] ?? '');

        if (! in_array($scheme, ['http', 'https'], true) || $this->normalizeHost($host) === null) {
            return null;
        }

        return $trimmed;
    }

    /**
     * @return list<string>
     */
    private function parseConfiguredList(mixed $raw): array
    {
        $items = is_array($raw) ? $raw : explode(',', (string) $raw);
        $result = [];

        foreach ($items as $item) {
            if (! is_string($item) && ! is_numeric($item)) {
                continue;
            }

            $trimmed = trim((string) $item);
            if ($trimmed !== '') {
                $result[] = $trimmed;
            }
        }

        return $result;
    }

    private function normalizeProxyEntry(string $entry): ?string
    {
        if ($entry === '' || $entry === '*' || preg_match('/\s/', $entry) === 1) {
            return null;
        }

        if (! str_contains($entry, '/')) {
            return $this->normalizeIp($entry);
        }

        if (substr_count($entry, '/') !== 1) {
            return null;
        }

        [$ip, $prefix] = explode('/', $entry, 2);
        if (preg_match('/^[0-9]{1,3}$/', $prefix) !== 1) {
            return null;
        }

        $prefixInt = (int) $prefix;
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false && $prefixInt >= 1 && $prefixInt <= 32) {
            return $ip . '/' . $prefixInt;
        }

        $ipv6 = $this->normalizeIp($ip, FILTER_FLAG_IPV6);
        if ($ipv6 !== null && $prefixInt >= 1 && $prefixInt <= 128) {
            return $ipv6 . '/' . $prefixInt;
        }

        return null;
    }

    private function normalizeIp(string $ip, int $flags = 0): ?string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, $flags) === false) {
            return null;
        }

        $packed = @inet_pton($ip);
        if ($packed === false) {
            return null;
        }

        $canonical = @inet_ntop($packed);

        return is_string($canonical) ? strtolower($canonical) : null;
    }

    private function isValidPort(string $port): bool
    {
        if (preg_match('/^[0-9]{1,5}$/', $port) !== 1) {
            return false;
        }

        $num = (int) $port;

        return $num >= 1 && $num <= 65535;
    }
}

