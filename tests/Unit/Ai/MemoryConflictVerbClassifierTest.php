<?php

declare(strict_types=1);

namespace Tests\Unit\Ai;

use App\Services\Ai\Memory\MemoryConflictVerbClassifier;
use Tests\TestCase;

/**
 * Atlas Cognition Operating System — Deep Cores (S201).
 *
 * Covers the pure ordered-rule logic of MemoryConflictVerbClassifier:
 *  - verdict per the <=7 ordered rules (computed from key/scope/polarity/ts)
 *  - escalate gate (visible verdict on high-risk memory type)
 *  - schema_version literal
 *
 * No DB, no clock, no I/O — every assertion reads a computed field.
 */
final class MemoryConflictVerbClassifierTest extends TestCase
{
    private function fact(
        string $key,
        string $scopeType,
        string $polarity,
        int $recordedTs,
        string $memoryType,
    ): array {
        return [
            'key' => $key,
            'scope_type' => $scopeType,
            'polarity' => $polarity,
            'recorded_ts' => $recordedTs,
            'memory_type' => $memoryType,
        ];
    }

    public function test_different_keys_yield_not_conflict_without_escalation(): void
    {
        $result = (new MemoryConflictVerbClassifier())->classify(
            $this->fact('deploy_window', 'global', 'affirm', 1000, 'decision'),
            $this->fact('rollback_policy', 'global', 'negate', 1000, 'decision'),
        );

        $this->assertSame('not_conflict', $result['verdict']);
        $this->assertFalse($result['escalate']);
        $this->assertSame('rule_1_keys_differ', $result['reason']);
    }

    public function test_same_key_cross_scope_type_is_scoped(): void
    {
        $result = (new MemoryConflictVerbClassifier())->classify(
            $this->fact('cache_ttl', 'global', 'affirm', 1200, 'config'),
            $this->fact('cache_ttl', 'project', 'negate', 1300, 'config'),
        );

        $this->assertSame('scoped', $result['verdict']);
        $this->assertFalse($result['escalate']);
        $this->assertSame('rule_2_different_scope_type', $result['reason']);
    }

    public function test_opposite_polarity_newer_wins_supersedes_and_escalates_for_decision(): void
    {
        $result = (new MemoryConflictVerbClassifier())->classify(
            $this->fact('auth_strategy', 'global', 'affirm', 2000, 'decision'),
            $this->fact('auth_strategy', 'global', 'negate', 2500, 'decision'),
        );

        $this->assertSame('supersedes', $result['verdict']);
        $this->assertTrue($result['escalate']);
        $this->assertSame('rule_4_opposite_polarity_newer_supersedes', $result['reason']);
    }

    public function test_equal_ts_opposite_polarity_is_conflicts_with_and_escalates(): void
    {
        $result = (new MemoryConflictVerbClassifier())->classify(
            $this->fact('data_residency', 'global', 'affirm', 3000, 'policy'),
            $this->fact('data_residency', 'global', 'negate', 3000, 'policy'),
        );

        $this->assertSame('conflicts_with', $result['verdict']);
        $this->assertTrue($result['escalate']);
        $this->assertSame('rule_5_opposite_polarity_equal_ts', $result['reason']);
    }

    public function test_same_key_scope_same_polarity_is_compatible_without_escalation(): void
    {
        $result = (new MemoryConflictVerbClassifier())->classify(
            $this->fact('retry_limit', 'global', 'affirm', 4000, 'decision'),
            $this->fact('retry_limit', 'global', 'affirm', 4100, 'decision'),
        );

        $this->assertSame('compatible', $result['verdict']);
        $this->assertFalse($result['escalate']);
        $this->assertSame('rule_3_same_polarity', $result['reason']);
    }

    public function test_missing_scope_type_on_one_side_is_related(): void
    {
        $result = (new MemoryConflictVerbClassifier())->classify(
            $this->fact('feature_flag', '', 'affirm', 5000, 'decision'),
            $this->fact('feature_flag', 'global', 'negate', 5100, 'decision'),
        );

        $this->assertSame('related', $result['verdict']);
        $this->assertFalse($result['escalate']);
        $this->assertSame('rule_6_missing_scope_type', $result['reason']);
    }

    public function test_supersedes_does_not_escalate_for_non_high_risk_memory_type(): void
    {
        // Generalisation guard: visible verdict but no high-risk memory type -> no escalation.
        $result = (new MemoryConflictVerbClassifier())->classify(
            $this->fact('log_level', 'global', 'negate', 7000, 'config'),
            $this->fact('log_level', 'global', 'affirm', 6500, 'config'),
        );

        $this->assertSame('supersedes', $result['verdict']);
        $this->assertFalse($result['escalate']);
    }

    public function test_supersedes_holds_when_first_side_is_strictly_newer(): void
    {
        // Generalisation guard: newer-wins is symmetric (not keyed to argument order).
        $result = (new MemoryConflictVerbClassifier())->classify(
            $this->fact('ingest_mode', 'project', 'affirm', 9100, 'architecture'),
            $this->fact('ingest_mode', 'project', 'negate', 9000, 'architecture'),
        );

        $this->assertSame('supersedes', $result['verdict']);
        $this->assertTrue($result['escalate']);
    }

    public function test_schema_version_is_literal(): void
    {
        $result = (new MemoryConflictVerbClassifier())->classify(
            $this->fact('any_key', 'global', 'affirm', 1, 'decision'),
            $this->fact('other_key', 'global', 'affirm', 1, 'decision'),
        );

        $this->assertSame('atlas.memory.conflict_verb.v1', $result['schema_version']);
    }
}
