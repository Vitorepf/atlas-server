<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\KnowledgeSync;

use App\Services\Ai\SelfConstruction\KnowledgeSync\AtlasKnowledgeSyncCodeIndexReadinessGate;
use PHPUnit\Framework\TestCase;

final class AtlasKnowledgeSyncCodeIndexReadinessGateTest extends TestCase
{
    private AtlasKnowledgeSyncCodeIndexReadinessGate $gate;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gate = new AtlasKnowledgeSyncCodeIndexReadinessGate();
    }

    // AC: when changed_code_hash present, missing/mismatched symbol delta → blocker
    public function test_missing_symbol_delta_when_code_changed_is_blocked(): void
    {
        $result = $this->gate->evaluate([
            'changed_code_hash' => 'abc123',
            // changed_symbol_delta_hash missing
        ]);

        $this->assertFalse($result['ready']);
        $this->assertNotEmpty($result['blockers']);
        $this->assertTrue(
            count(array_filter($result['blockers'], fn ($b) => str_contains($b, 'changed_symbol_delta_hash'))) > 0
        );
    }

    public function test_symbol_delta_matches_code_hash_is_ready(): void
    {
        $result = $this->gate->evaluate([
            'changed_code_hash' => 'abc123',
            'changed_symbol_delta_hash' => 'delta_abc123',
            'indexed_code_hash' => 'abc123',
        ]);

        $this->assertTrue($result['ready']);
        $this->assertEmpty($result['blockers']);
    }

    public function test_indexed_code_hash_mismatch_is_blocked(): void
    {
        $result = $this->gate->evaluate([
            'changed_code_hash' => 'abc123',
            'changed_symbol_delta_hash' => 'delta_abc123',
            'indexed_code_hash' => 'stale_hash',
        ]);

        $this->assertFalse($result['ready']);
    }

    public function test_no_changed_code_hash_is_ready(): void
    {
        $result = $this->gate->evaluate([]);

        $this->assertTrue($result['ready']);
    }

    public function test_docs_only_bypass_is_ready(): void
    {
        $result = $this->gate->evaluate([
            'changed_code_hash' => 'abc123',
            'docs_only_bypass' => true,
        ]);

        $this->assertTrue($result['ready']);
    }

    public function test_empty_symbol_delta_is_blocked(): void
    {
        $result = $this->gate->evaluate([
            'changed_code_hash' => 'abc123',
            'changed_symbol_delta_hash' => '',
        ]);

        $this->assertFalse($result['ready']);
    }
}
