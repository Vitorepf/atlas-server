<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\ContextIntelligence;

use App\Services\Ai\ContextIntelligence\ContextIntelligencePayloadHash;
use PHPUnit\Framework\TestCase;

final class ContextIntelligencePayloadHashTest extends TestCase
{
    public function test_for_payload_ignores_generated_at_and_named_hash_field_only(): void
    {
        $a = ContextIntelligencePayloadHash::forPayload([
            'schema_version' => 'atlas.context_intelligence.example.v1',
            'status' => 'ready',
            'generated_at' => '2026-06-09T10:00:00Z',
            'example_hash' => 'old',
            'nested' => ['keep' => 'same'],
        ], 'example_hash');

        $b = ContextIntelligencePayloadHash::forPayload([
            'schema_version' => 'atlas.context_intelligence.example.v1',
            'status' => 'ready',
            'generated_at' => '2026-06-09T11:00:00Z',
            'example_hash' => 'different',
            'nested' => ['keep' => 'same'],
        ], 'example_hash');

        $c = ContextIntelligencePayloadHash::forPayload([
            'schema_version' => 'atlas.context_intelligence.example.v1',
            'status' => 'blocked',
            'generated_at' => '2026-06-09T11:00:00Z',
            'example_hash' => 'different',
            'nested' => ['keep' => 'same'],
        ], 'example_hash');

        $this->assertSame($a, $b);
        $this->assertNotSame($a, $c);
    }
}
