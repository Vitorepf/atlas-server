<?php

namespace App\Services\Engineering\CodeGraph;

use InvalidArgumentException;

/**
 * SSRF guard for any URL the code-graph ingest path may be asked to fetch
 * (AP-812 P-14). Pure logic — no IO beyond a single DNS resolution, no provider,
 * no decision authority. It feeds a yes/no to the caller; it never decides what
 * to fetch, only whether a candidate URL is safe to fetch at all.
 *
 * Technique captured (as technique, not authority) from the graphify dissection:
 *  - getaddrinfo-revalidation: a hostname is not trusted by its spelling. We
 *    resolve it and re-check EVERY resolved IP, so a public-looking name that
 *    resolves to a private/loopback/metadata address is still rejected. This is
 *    the cheap defence against the "looks external, points internal" class of
 *    SSRF (a literal-IP allow-list alone never catches it).
 *
 * Rejected, always:
 *  - any scheme other than http/https (file://, ftp://, gopher://, data:, ...);
 *  - loopback (127.0.0.0/8, ::1), link-local (169.254/16, fe80::/10);
 *  - RFC1918 private (10/8, 172.16/12, 192.168/16) and IPv6 ULA (fc00::/7);
 *  - RFC6598 Carrier-Grade NAT (100.64.0.0/10);
 *  - the cloud instance-metadata endpoint (169.254.169.254 / [fd00:ec2::254]);
 *  - reserved / unspecified ranges (0.0.0.0/8, ::, IPv4-mapped IPv6, etc.).
 *
 * Defence in depth: filter_var(...NO_PRIV_RANGE|NO_RES_RANGE) is the spine for
 * IPv4, with explicit CIDR checks layered on for the ranges those flags miss
 * (CGN, the metadata IP, IPv6) so we never rely on a single mechanism.
 */
class CodeGraphIngestGuard
{
    public const SCHEMA = 'atlas.code_graph.ingest_guard.v1';

    /** Schemes the ingest path is ever allowed to fetch. */
    private const ALLOWED_SCHEMES = ['http', 'https'];

    /** Cloud instance-metadata hosts that must never be reachable. */
    private const METADATA_HOSTS = [
        '169.254.169.254',
        'fd00:ec2::254',
        '[fd00:ec2::254]',
        'metadata.google.internal',
    ];

    /**
     * True when the URL is well-formed, http/https, and every IP its host
     * resolves to is a public, routable address. Never throws.
     */
    public function isSafeUrl(string $url): bool
    {
        return $this->inspect($url) === null;
    }

    /**
     * Same checks as {@see isSafeUrl()} but throws with the precise reason,
     * for call sites that want to fail loudly instead of branching.
     *
     * @throws InvalidArgumentException when the URL is unsafe to fetch.
     */
    public function assertSafeUrl(string $url): void
    {
        $reason = $this->inspect($url);
        if ($reason !== null) {
            throw new InvalidArgumentException('Unsafe ingest URL rejected: '.$reason);
        }
    }

    /**
     * @return string|null  null when safe; otherwise a short rejection reason.
     */
    private function inspect(string $url): ?string
    {
        $url = trim($url);
        if ($url === '') {
            return 'empty url';
        }

        $parts = parse_url($url);
        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return 'malformed url (missing scheme or host)';
        }

        $scheme = strtolower((string) $parts['scheme']);
        if (! in_array($scheme, self::ALLOWED_SCHEMES, true)) {
            return 'disallowed scheme: '.$scheme;
        }

        // Host as written: strip IPv6 brackets, lowercase. Userinfo (user:pass@)
        // is parsed out by parse_url already and cannot smuggle a second host.
        $host = strtolower(trim((string) $parts['host']));
        $bareHost = $this->stripBrackets($host);
        if ($bareHost === '') {
            return 'empty host';
        }

        // Explicit metadata-host denylist by name (defends the literal hostname
        // form before any resolution; the IP form is caught by the IP checks).
        if (in_array($host, self::METADATA_HOSTS, true) || in_array($bareHost, self::METADATA_HOSTS, true)) {
            return 'cloud metadata host: '.$bareHost;
        }

        // If the host is itself an IP literal, check it directly — no DNS.
        if (filter_var($bareHost, FILTER_VALIDATE_IP) !== false) {
            $reason = $this->ipReason($bareHost);

            return $reason === null ? null : 'host ip '.$bareHost.' is '.$reason;
        }

        // getaddrinfo-revalidation: resolve the name and reject if ANY resolved
        // address is non-public. gethostbynamel is IPv4-only; we add AAAA via
        // dns_get_record so an AAAA-only or dual-stack internal target can't slip
        // through the v4 path. A name that resolves to nothing is rejected too.
        $ips = $this->resolveAll($bareHost);
        if ($ips === []) {
            return 'host did not resolve: '.$bareHost;
        }

        foreach ($ips as $ip) {
            $reason = $this->ipReason($ip);
            if ($reason !== null) {
                return 'resolved ip '.$ip.' is '.$reason;
            }
        }

        return null;
    }

    /**
     * Classify a single IP. Returns null when the IP is a public, routable
     * address that is safe to fetch; otherwise a short reason.
     */
    private function ipReason(string $ip): ?string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return 'not a valid ip';
        }

        // Hard denylist for the metadata IPs regardless of flag coverage.
        if (in_array($ip, ['169.254.169.254', 'fd00:ec2::254'], true)) {
            return 'cloud metadata address';
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            // Spine: reject RFC1918 private + reserved (covers loopback,
            // link-local, 0.0.0.0/8, broadcast, future-use, ...). It does NOT
            // cover multicast (224.0.0.0/4) — that range is rejected explicitly
            // below, alongside CGN.
            $public = filter_var(
                $ip,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
            );
            if ($public === false) {
                return 'private/reserved ipv4';
            }
            // RFC6598 CGN (100.64.0.0/10) is NOT covered by NO_PRIV/NO_RES.
            if ($this->inCidrV4($ip, '100.64.0.0', 10)) {
                return 'cgn (rfc6598) ipv4';
            }
            // Multicast (224.0.0.0/4) is NOT covered by NO_PRIV/NO_RES either.
            if ($this->inCidrV4($ip, '224.0.0.0', 4)) {
                return 'multicast ipv4';
            }

            return null;
        }

        // IPv6 path.
        $public = filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_IPV6 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        );
        if ($public === false) {
            return 'private/reserved ipv6';
        }
        // Multicast (ff00::/8) is NOT covered by NO_PRIV/NO_RES for IPv6.
        if ($this->isMulticastV6($ip)) {
            return 'multicast ipv6';
        }
        // IPv4-mapped/compatible IPv6 (::ffff:a.b.c.d, ::a.b.c.d) re-checked as v4
        // so an internal v4 cannot be smuggled inside a v6 literal.
        $embedded = $this->embeddedV4($ip);
        if ($embedded !== null) {
            $reason = $this->ipReason($embedded);

            return $reason === null ? null : 'ipv4-mapped '.$reason;
        }

        return null;
    }

    /**
     * Resolve a hostname to every A and AAAA address, stdlib only.
     *
     * @return array<int,string>
     */
    private function resolveAll(string $host): array
    {
        $ips = [];

        $v4 = @gethostbynamel($host);
        if (is_array($v4)) {
            foreach ($v4 as $ip) {
                if (is_string($ip) && $ip !== '') {
                    $ips[$ip] = true;
                }
            }
        }

        // AAAA via dns_get_record (stdlib). Best-effort: failures just mean no
        // extra v6 records, and the v4 result (or none) still governs.
        $aaaa = @dns_get_record($host, DNS_AAAA);
        if (is_array($aaaa)) {
            foreach ($aaaa as $record) {
                if (is_array($record) && isset($record['ipv6']) && is_string($record['ipv6']) && $record['ipv6'] !== '') {
                    $ips[$record['ipv6']] = true;
                }
            }
        }

        return array_keys($ips);
    }

    /** Is an IPv4 address inside the given CIDR block? */
    private function inCidrV4(string $ip, string $network, int $prefix): bool
    {
        $ipLong = ip2long($ip);
        $netLong = ip2long($network);
        if ($ipLong === false || $netLong === false) {
            return false;
        }
        if ($prefix <= 0) {
            return true;
        }
        if ($prefix > 32) {
            return false;
        }
        $mask = -1 << (32 - $prefix);

        return ($ipLong & $mask) === ($netLong & $mask);
    }

    /**
     * Extract an embedded IPv4 from an IPv4-mapped/compatible IPv6 literal,
     * e.g. "::ffff:127.0.0.1" or "::ffff:7f00:1" -> "127.0.0.1".
     */
    private function embeddedV4(string $ip): ?string
    {
        $packed = @inet_pton($ip);
        if ($packed === false || strlen($packed) !== 16) {
            return null;
        }
        // IPv4-mapped (::ffff:0:0/96) and IPv4-compatible (::/96) both carry the
        // v4 in the last 4 bytes; the first 10 bytes are zero for both.
        $first10 = substr($packed, 0, 10);
        if ($first10 !== str_repeat("\0", 10)) {
            return null;
        }
        $marker = substr($packed, 10, 2);
        if ($marker !== "\xff\xff" && $marker !== "\x00\x00") {
            return null;
        }
        $v4 = @inet_ntop(substr($packed, 12, 4));
        if (! is_string($v4) || filter_var($v4, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return null;
        }
        // Ignore the all-zero tail (that's just "::"), which is handled as v6.
        return $v4 === '0.0.0.0' ? null : $v4;
    }

    /**
     * Is an IPv6 literal inside the multicast block ff00::/8? The whole /8 is
     * multicast, so the packed (16-byte) form simply starts with 0xff.
     */
    private function isMulticastV6(string $ip): bool
    {
        $packed = @inet_pton($ip);

        return is_string($packed) && strlen($packed) === 16 && $packed[0] === "\xff";
    }

    private function stripBrackets(string $host): string
    {
        if (strlen($host) >= 2 && $host[0] === '[' && substr($host, -1) === ']') {
            return substr($host, 1, -1);
        }

        return $host;
    }
}
