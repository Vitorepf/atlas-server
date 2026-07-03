<?php

namespace Tests\Unit\Ai\AutonomousEvolution;

use PHPUnit\Framework\TestCase;
use App\Services\Ai\AutonomousEvolution\AtlasLoopVerificationAtomNormalizer;

final class AtlasLoopVerificationAtomNormalizerHardeningTest extends TestCase
{
    private function makeNormalizer(): AtlasLoopVerificationAtomNormalizer
    {
        return new AtlasLoopVerificationAtomNormalizer(
            fn (mixed $v) => is_string($v) ? $v : null,
            fn (mixed $v) => is_array($v) ? array_map('strval', $v) : [],
            fn (string $event, array $payload) => null,
            fn (string $job, array $payload) => null,
            fn (string $table, array $payload) => null,
            fn (string $intent) => null,
            fn (string $intent) => null,
        );
    }

    /**
     * A blank method must be rejected (return null) instead of being
     * silently coerced to GET.
     */
    public function test_blank_method_returns_null(): void
    {
        $normalizer = $this->makeNormalizer();
        $method = new \ReflectionMethod($normalizer, 'normalizeHttpResponseAtom');

        $atom = [
            'method' => '',
            'path' => '/api/health',
            'status' => 200,
            'body_contains' => 'ok',
        ];

        $result = $method->invoke($normalizer, $atom);

        $this->assertNull($result);
    }

    /**
     * An invalid HTTP verb (not a real method) must be rejected.
     */
    public function test_invalid_method_returns_null(): void
    {
        $normalizer = $this->makeNormalizer();
        $method = new \ReflectionMethod($normalizer, 'normalizeHttpResponseAtom');

        $atom = [
            'method' => 'INVALID',
            'path' => '/api/health',
            'status' => 200,
            'body_contains' => 'ok',
        ];

        $result = $method->invoke($normalizer, $atom);

        $this->assertNull($result);
    }

    /**
     * A valid HTTP method (GET) with a proper path and body still normalizes.
     */
    public function test_valid_method_normalizes_correctly(): void
    {
        $normalizer = $this->makeNormalizer();
        $method = new \ReflectionMethod($normalizer, 'normalizeHttpResponseAtom');

        $atom = [
            'method' => 'GET',
            'path' => '/api/health',
            'status' => 200,
            'body_contains' => 'ok',
        ];

        $result = $method->invoke($normalizer, $atom);

        $this->assertNotNull($result);
        $this->assertSame('GET', $result['method']);
        $this->assertSame('/api/health', $result['path']);
        $this->assertSame(200, $result['status']);
    }

    /**
     * A valid POST method normalizes correctly.
     */
    public function test_post_method_normalizes_correctly(): void
    {
        $normalizer = $this->makeNormalizer();
        $method = new \ReflectionMethod($normalizer, 'normalizeHttpResponseAtom');

        $atom = [
            'method' => 'POST',
            'path' => '/api/users',
            'status' => 201,
            'body_exact' => '{"id":1}',
        ];

        $result = $method->invoke($normalizer, $atom);

        $this->assertNotNull($result);
        $this->assertSame('POST', $result['method']);
    }
}
