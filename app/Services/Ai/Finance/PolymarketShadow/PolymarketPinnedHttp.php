<?php

declare(strict_types=1);

namespace App\Services\Ai\Finance\PolymarketShadow;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * HTTP client for Polymarket public READ-ONLY data endpoints.
 *
 * The operator's local resolver censors polymarket.com subdomains (DNS-level ISP
 * block verified 2026-06-10: local resolver returns NXDOMAIN while 1.1.1.1/8.8.8.8
 * resolve normally and the HTTPS endpoints answer 200). This client resolves the
 * hosts itself via DNS-over-HTTPS against Cloudflare's literal IP (no DNS needed)
 * and pins the connection with CURLOPT_RESOLVE.
 *
 * Scope is deliberately market-data-only (Gamma metadata + CLOB books). It carries
 * no keys and can place no orders: live execution remains behind the operator's
 * explicit funding/keys and the finance domain's market_execution_forbidden gate.
 */
final class PolymarketPinnedHttp
{
    private const DOH_ENDPOINT = 'https://1.1.1.1/dns-query';

    private const IP_CACHE_TTL_SECONDS = 600;

    /** Last-known-good Cloudflare edge, used only if DoH itself fails. */
    private const FALLBACK_IP = '104.18.34.205';

    /** @var array<string, string> in-process resolution cache */
    private array $resolved = [];

    public function getJson(string $url, int $timeoutSeconds = 10): ?array
    {
        $host = (string) parse_url($url, PHP_URL_HOST);
        if ($host === '') {
            return null;
        }

        $ip = $this->resolve($host);

        try {
            $response = Http::timeout($timeoutSeconds)
                ->connectTimeout(min(5, $timeoutSeconds))
                ->withOptions($ip !== null ? [
                    'curl' => [CURLOPT_RESOLVE => [$host.':443:'.$ip]],
                ] : [])
                ->get($url);
        } catch (\Throwable) {
            return null;
        }

        if (! $response->ok()) {
            return null;
        }

        $decoded = $response->json();

        return is_array($decoded) ? $decoded : null;
    }

    private function resolve(string $host): ?string
    {
        if (isset($this->resolved[$host])) {
            return $this->resolved[$host];
        }

        $cacheKey = 'poly_shadow:doh:'.$host;
        $cached = Cache::get($cacheKey);
        if (is_string($cached) && filter_var($cached, FILTER_VALIDATE_IP) !== false) {
            return $this->resolved[$host] = $cached;
        }

        $ip = $this->dohLookup($host) ?? self::FALLBACK_IP;
        Cache::put($cacheKey, $ip, self::IP_CACHE_TTL_SECONDS);

        return $this->resolved[$host] = $ip;
    }

    private function dohLookup(string $host): ?string
    {
        try {
            $response = Http::timeout(5)
                ->withHeaders(['accept' => 'application/dns-json'])
                ->get(self::DOH_ENDPOINT, ['name' => $host, 'type' => 'A']);
        } catch (\Throwable) {
            return null;
        }

        if (! $response->ok()) {
            return null;
        }

        foreach ((array) $response->json('Answer', []) as $answer) {
            $data = is_array($answer) ? ($answer['data'] ?? null) : null;
            if (is_string($data) && filter_var($data, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                return $data;
            }
        }

        return null;
    }
}
