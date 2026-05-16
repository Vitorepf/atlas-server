<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev\Repair;

use App\Services\Ai\Programming\AtlasDev\Repair\FailureSignatureHasher;
use PHPUnit\Framework\TestCase;

final class FailureSignatureHasherTest extends TestCase
{
    public function test_normalize_strips_timestamps_paths_uuids_and_addresses(): void
    {
        $hasher = new FailureSignatureHasher();
        $raw = "[2026-05-16T12:34:56.789Z] FooTest::test_x failed at /Users/op/dev/foo.php:123 "
            ."run_id=01928f8e-1234-7abc-9def-abcdef012345 sha=deadbeefcafef00ddeadbeefcafef00d";

        $normalized = $hasher->normalize($raw);

        $this->assertStringNotContainsString('2026-05-16', $normalized);
        $this->assertStringNotContainsString('/Users/', $normalized);
        $this->assertStringNotContainsString('01928f8e-1234', $normalized);
        $this->assertStringNotContainsString('deadbeefcafef00d', $normalized);
        $this->assertStringContainsString('<ts>', $normalized);
        $this->assertStringContainsString('<path>', $normalized);
        $this->assertStringContainsString('<uuid>', $normalized);
        $this->assertStringContainsString('<hex>', $normalized);
    }

    public function test_signature_is_stable_across_volatile_logs(): void
    {
        $hasher = new FailureSignatureHasher();
        $a = $hasher->signature(
            'verification_gate',
            "[2026-05-16T12:34:56Z] AssertionError at /tmp/run-1/test.php:42 pid=12345",
        );
        $b = $hasher->signature(
            'verification_gate',
            "[2026-05-17T08:00:01Z] AssertionError at /tmp/run-9/test.php:99 pid=99999",
        );

        $this->assertSame($a, $b);
    }

    public function test_signature_differs_across_unrelated_errors(): void
    {
        $hasher = new FailureSignatureHasher();
        $a = $hasher->signature('verification_gate', 'AssertionError: expected 1 got 0');
        $b = $hasher->signature('verification_gate', 'SegmentationFault: nullpointer dereference');

        $this->assertNotSame($a, $b);
    }

    public function test_signature_changes_when_gate_changes(): void
    {
        $hasher = new FailureSignatureHasher();
        $a = $hasher->signature('verification_gate', 'AssertionError: expected 1 got 0');
        $b = $hasher->signature('scope_guard_light', 'AssertionError: expected 1 got 0');

        $this->assertNotSame($a, $b);
    }

    public function test_normalize_handles_empty_excerpt(): void
    {
        $hasher = new FailureSignatureHasher();
        $this->assertSame('', $hasher->normalize(''));
        // The DTO signature_of would still produce a deterministic digest.
        $this->assertNotEmpty($hasher->signature('g', ''));
    }

    public function test_normalize_is_idempotent(): void
    {
        $hasher = new FailureSignatureHasher();
        $raw = "[2026-01-01T00:00:00Z] Some error /var/log/x.log:5 sha=cafe0123cafe0123cafe0123cafe0123";

        $once = $hasher->normalize($raw);
        $twice = $hasher->normalize($once);

        $this->assertSame($once, $twice);
    }

    public function test_signature_collapses_whitespace_and_case_via_dto(): void
    {
        $hasher = new FailureSignatureHasher();
        $a = $hasher->signature('verification_gate', "Error:   foo bar");
        $b = $hasher->signature('verification_gate', "error: foo  bar");

        $this->assertSame($a, $b);
    }
}
