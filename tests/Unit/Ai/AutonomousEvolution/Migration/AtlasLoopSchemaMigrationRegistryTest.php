<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Migration;

use App\Services\Ai\AutonomousEvolution\Migration\AtlasLoopSchemaMigrationDuplicateStepException;
use App\Services\Ai\AutonomousEvolution\Migration\AtlasLoopSchemaMigrationGapException;
use App\Services\Ai\AutonomousEvolution\Migration\AtlasLoopSchemaMigrationRegistry;
use App\Services\Ai\AutonomousEvolution\Migration\AtlasLoopSchemaMigrationStep;
use PHPUnit\Framework\TestCase;

final class AtlasLoopSchemaMigrationRegistryTest extends TestCase
{
    public function test_register_and_resolve_chain_returns_ordered_steps(): void
    {
        $registry = new AtlasLoopSchemaMigrationRegistry;
        $registry->register('atlas_loop_evidence_ledger', 1, 2, $this->appendVersionTransform(2), $this->versionVerifier(2));
        $registry->register('atlas_loop_evidence_ledger', 2, 3, $this->appendVersionTransform(3), $this->versionVerifier(3), true);

        $chain = $registry->resolveChain('atlas_loop_evidence_ledger', 1, 3);

        $this->assertCount(2, $chain);
        $this->assertContainsOnlyInstancesOf(AtlasLoopSchemaMigrationStep::class, $chain);
        $this->assertSame([[1, 2], [2, 3]], array_map(
            static fn (AtlasLoopSchemaMigrationStep $step): array => [$step->fromVersion, $step->toVersion],
            $chain,
        ));
        $this->assertSame([1, 2, 3], $registry->knownVersions('atlas_loop_evidence_ledger'));
    }

    public function test_registry_rejects_duplicate_steps_and_gap_chains_with_distinct_exceptions(): void
    {
        $registry = new AtlasLoopSchemaMigrationRegistry;
        $registry->register('atlas_loop_decision_receipts', 1, 2, $this->appendVersionTransform(2), $this->versionVerifier(2));

        try {
            $registry->register('atlas_loop_decision_receipts', 1, 2, $this->appendVersionTransform(2), $this->versionVerifier(2));
            $this->fail('Expected duplicate step exception.');
        } catch (AtlasLoopSchemaMigrationDuplicateStepException $e) {
            $this->assertStringContainsString('Duplicate migration step', $e->getMessage());
        }

        $gapRegistry = new AtlasLoopSchemaMigrationRegistry;
        $gapRegistry->register('atlas_loop_workspace_blueprints', 1, 3, $this->appendVersionTransform(3), $this->versionVerifier(3));

        try {
            $gapRegistry->resolveChain('atlas_loop_workspace_blueprints', 1, 3);
            $this->fail('Expected gap chain exception.');
        } catch (AtlasLoopSchemaMigrationGapException $e) {
            $this->assertStringContainsString('No complete migration chain', $e->getMessage());
        }
    }

    public function test_registered_step_transform_is_byte_identical_for_same_input(): void
    {
        $registry = new AtlasLoopSchemaMigrationRegistry;
        $registry->register('atlas_loop_evidence_ledger', 1, 2, $this->appendVersionTransform(2), $this->versionVerifier(2));

        $step = $registry->resolveChain('atlas_loop_evidence_ledger', 1, 2)[0];
        $input = '{"artifact_kind":"atlas_loop_evidence_ledger","items":[{"id":"r1"}],"version":1}';

        $first = $step->transform($input);
        $second = $step->transform($input);

        $this->assertSame($first, $second);
        $this->assertTrue($step->verify($first));
    }

    private function appendVersionTransform(int $targetVersion): callable
    {
        return static function (string $snapshotBytes) use ($targetVersion): string {
            $payload = json_decode($snapshotBytes, true, flags: JSON_THROW_ON_ERROR);
            ksort($payload, SORT_STRING);
            $payload['version'] = $targetVersion;

            return json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        };
    }

    private function versionVerifier(int $expectedVersion): callable
    {
        return static function (string $snapshotBytes) use ($expectedVersion): bool {
            $payload = json_decode($snapshotBytes, true);

            return is_array($payload) && ($payload['version'] ?? null) === $expectedVersion;
        };
    }
}
