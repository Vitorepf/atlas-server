<?php

namespace Tests\Unit\Engineering\CodeGraph;

use App\Services\Engineering\CodeGraph\CodeGraphIngestGuard;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Offline by construction: every case uses an IP-literal host so no DNS is hit,
 * keeping the SSRF guard test deterministic in CI. The getaddrinfo-revalidation
 * path (gethostbynamel/dns_get_record) is exercised structurally via literal IPs
 * — the same ipReason() classifier that resolved IPs flow through.
 */
class CodeGraphIngestGuardTest extends TestCase
{
    private function guard(): CodeGraphIngestGuard
    {
        return new CodeGraphIngestGuard;
    }

    public function test_public_http_and_https_ip_literals_are_allowed(): void
    {
        $guard = $this->guard();

        // Public, routable IP literals (no DNS). 8.8.8.8 / 1.1.1.1 are public.
        $this->assertTrue($guard->isSafeUrl('http://8.8.8.8/repo.git'));
        $this->assertTrue($guard->isSafeUrl('https://1.1.1.1:443/v3/path?x=1'));
        $this->assertTrue($guard->isSafeUrl('https://[2606:4700:4700::1111]/'));
    }

    public function test_assert_safe_url_does_not_throw_for_public_host(): void
    {
        $this->guard()->assertSafeUrl('https://93.184.216.34/');
        $this->addToAssertionCount(1);
    }

    public function test_file_scheme_is_rejected(): void
    {
        $guard = $this->guard();
        $this->assertFalse($guard->isSafeUrl('file:///etc/passwd'));
        $this->assertFalse($guard->isSafeUrl('file://localhost/etc/shadow'));
    }

    public function test_ftp_and_other_schemes_are_rejected(): void
    {
        $guard = $this->guard();
        $this->assertFalse($guard->isSafeUrl('ftp://8.8.8.8/x'));
        $this->assertFalse($guard->isSafeUrl('gopher://8.8.8.8/'));
        $this->assertFalse($guard->isSafeUrl('data:text/plain,hello'));
    }

    public function test_loopback_is_rejected(): void
    {
        $guard = $this->guard();
        $this->assertFalse($guard->isSafeUrl('http://127.0.0.1/'));
        $this->assertFalse($guard->isSafeUrl('http://127.0.0.1:8080/admin'));
        $this->assertFalse($guard->isSafeUrl('http://[::1]/'));
    }

    public function test_rfc1918_private_ranges_are_rejected(): void
    {
        $guard = $this->guard();
        $this->assertFalse($guard->isSafeUrl('http://10.0.0.5/'));
        $this->assertFalse($guard->isSafeUrl('http://10.255.255.255/'));
        $this->assertFalse($guard->isSafeUrl('http://172.16.0.1/'));
        $this->assertFalse($guard->isSafeUrl('http://192.168.1.1/'));
    }

    public function test_link_local_is_rejected(): void
    {
        $guard = $this->guard();
        $this->assertFalse($guard->isSafeUrl('http://169.254.1.1/'));
        $this->assertFalse($guard->isSafeUrl('http://[fe80::1]/'));
    }

    public function test_cloud_metadata_endpoint_is_rejected(): void
    {
        $guard = $this->guard();
        // IP form, name form, and the GCP metadata hostname.
        $this->assertFalse($guard->isSafeUrl('http://169.254.169.254/latest/meta-data/'));
        $this->assertFalse($guard->isSafeUrl('http://metadata.google.internal/computeMetadata/v1/'));
    }

    public function test_cgn_rfc6598_range_is_rejected(): void
    {
        $guard = $this->guard();
        // 100.64.0.0/10 is Carrier-Grade NAT — not caught by NO_PRIV/NO_RES flags.
        $this->assertFalse($guard->isSafeUrl('http://100.64.0.1/'));
        $this->assertFalse($guard->isSafeUrl('http://100.127.255.254/'));
    }

    public function test_ipv4_mapped_ipv6_loopback_is_rejected(): void
    {
        $guard = $this->guard();
        // Smuggling 127.0.0.1 inside a v6 literal must not bypass the guard.
        $this->assertFalse($guard->isSafeUrl('http://[::ffff:127.0.0.1]/'));
    }

    public function test_unspecified_and_zero_addresses_are_rejected(): void
    {
        $guard = $this->guard();
        $this->assertFalse($guard->isSafeUrl('http://0.0.0.0/'));
        $this->assertFalse($guard->isSafeUrl('http://[::]/'));
    }

    public function test_malformed_and_empty_urls_are_rejected(): void
    {
        $guard = $this->guard();
        $this->assertFalse($guard->isSafeUrl(''));
        $this->assertFalse($guard->isSafeUrl('   '));
        $this->assertFalse($guard->isSafeUrl('not a url'));
        $this->assertFalse($guard->isSafeUrl('http://'));
        $this->assertFalse($guard->isSafeUrl('//8.8.8.8/no-scheme'));
    }

    public function test_assert_safe_url_throws_for_loopback_with_reason(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Unsafe ingest URL rejected/');
        $this->guard()->assertSafeUrl('http://127.0.0.1/');
    }

    public function test_assert_safe_url_throws_for_metadata_ip(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->guard()->assertSafeUrl('http://169.254.169.254/latest/meta-data/');
    }
}
