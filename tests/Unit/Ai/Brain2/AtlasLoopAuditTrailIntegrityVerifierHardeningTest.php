<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Brain2;

use PHPUnit\Framework\TestCase;
use App\Services\Ai\AutonomousEvolution\AuditTrail\AtlasLoopAuditTrailIntegrityVerifier;

final class AtlasLoopAuditTrailIntegrityVerifierHardeningTest extends TestCase
{
    /**
     * canonicalHash must fail closed (throw) when facts contain invalid UTF-8
     * that makes json_encode return false, instead of hashing an empty string.
     */
    public function test_canonical_hash_fails_closed_for_invalid_utf8(): void
    {
        $verifier = new AtlasLoopAuditTrailIntegrityVerifier([]);

        // Use reflection to call the private canonicalHash method.
        $method = new \ReflectionMethod($verifier, 'canonicalHash');

        // Facts with invalid UTF-8 bytes — json_encode will return false.
        $facts = [
            'event' => 'test',
            'data' => "\xff\xfe\x00\x80", // Invalid UTF-8 sequence
        ];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('canonicalHash failed to encode facts payload');

        $method->invoke($verifier, $facts);
    }

    /**
     * Valid facts must still produce a deterministic hash.
     */
    public function test_canonical_hash_produces_deterministic_hash(): void
    {
        $verifier = new AtlasLoopAuditTrailIntegrityVerifier([]);

        $method = new \ReflectionMethod($verifier, 'canonicalHash');

        $facts = ['event' => 'test', 'value' => 42];

        $hash1 = $method->invoke($verifier, $facts);
        $hash2 = $method->invoke($verifier, $facts);

        $this->assertSame($hash1, $hash2);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $hash1);
    }

    /**
     * Verify the source code has the fail-closed guard.
     */
    public function test_source_has_fail_closed_guard(): void
    {
        $source = file_get_contents(__DIR__.'/../../../../app/Services/Ai/AutonomousEvolution/AuditTrail/AtlasLoopAuditTrailIntegrityVerifier.php');

        $this->assertStringContainsString('RuntimeException', $source, 'canonicalHash must throw RuntimeException on encoding failure');
        // The old vulnerable pattern was: (string) json_encode without checking.
        $this->assertStringNotContainsString('(string) json_encode', $source, 'canonicalHash must not cast json_encode to string without checking');
    }
}
