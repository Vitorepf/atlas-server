<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\TaskClassDiscovery\AtlasLoopTaskClassRegistry;
use Tests\TestCase;

final class AtlasLoopTaskClassRegistryTest extends TestCase
{
    private function registry(array $proposals): AtlasLoopTaskClassRegistry
    {
        $registry = new AtlasLoopTaskClassRegistry(
            static fn (string $id): ?array => $proposals[$id] ?? null,
        );

        return $registry;
    }

    private function proposal(array $overrides = []): array
    {
        return $overrides + [
            'class_id' => 'ledger-task',
            'shape_rules' => ['kind' => 'append-only', 'has_evidence' => true],
            'expected_acceptance_criteria_template' => ['tests pass'],
            'default_required_evidence_ids' => ['tests_or_gates_result'],
            'default_allowed_files_globs' => ['app/Services/Ai/AutonomousEvolution/**/*Ledger.php'],
            'source_cluster_fingerprint' => 'cluster-1',
        ];
    }

    public function test_approve_creates_version_1_entry(): void
    {
        $registry = $this->registry(['prop-1' => $this->proposal()]);
        $entry = $registry->approve('prop-1', 'OP-TOKEN');

        $this->assertSame('ledger-task', $entry->classId);
        $this->assertSame(1, $entry->version);
        $this->assertSame('OP-TOKEN', $entry->approvedByOperatorToken);
    }

    public function test_supersede_creates_version_2_entry_does_not_mutate_first(): void
    {
        $registry = $this->registry([
            'prop-1' => $this->proposal(),
            'prop-2' => $this->proposal(['expected_acceptance_criteria_template' => ['tests pass', 'docs updated']]),
        ]);
        $first = $registry->approve('prop-1', 'OP-1');
        $second = $registry->supersede('ledger-task', 'prop-2', 'OP-2');

        $this->assertSame(1, $first->version);
        $this->assertSame(2, $second->version);
        $this->assertSame(['tests pass'], $first->expectedAcceptanceCriteriaTemplate, 'first entry must NOT be mutated');
        $this->assertSame(['tests pass', 'docs updated'], $second->expectedAcceptanceCriteriaTemplate);
        $this->assertCount(2, $registry->all(), 'supersede must append, not replace');
    }

    public function test_match_returns_class_id_when_all_shape_rules_match(): void
    {
        $registry = $this->registry(['prop-1' => $this->proposal()]);
        $registry->approve('prop-1', 'OP');

        $matched = $registry->match(['kind' => 'append-only', 'has_evidence' => true, 'extra' => 'fine']);
        $this->assertSame('ledger-task', $matched);
    }

    public function test_match_returns_null_when_any_rule_fails(): void
    {
        $registry = $this->registry(['prop-1' => $this->proposal()]);
        $registry->approve('prop-1', 'OP');

        $this->assertNull($registry->match(['kind' => 'append-only']), 'missing rule key ⇒ no match');
        $this->assertNull($registry->match(['kind' => 'other', 'has_evidence' => true]), 'rule value mismatch ⇒ no match');
    }

    public function test_match_uses_latest_version_when_multiple_exist(): void
    {
        $registry = $this->registry([
            'p1' => $this->proposal(['shape_rules' => ['k' => 'v1']]),
            'p2' => $this->proposal(['shape_rules' => ['k' => 'v2']]),
        ]);
        $registry->approve('p1', 'OP');
        $registry->supersede('ledger-task', 'p2', 'OP');

        $this->assertSame('ledger-task', $registry->match(['k' => 'v2']));
        $this->assertNull($registry->match(['k' => 'v1']), 'old version v1 must not match after supersede');
    }

    public function test_supersede_unknown_class_throws(): void
    {
        $registry = $this->registry(['p2' => $this->proposal()]);
        $this->expectException(\RuntimeException::class);
        $registry->supersede('does-not-exist', 'p2', 'OP');
    }
}
