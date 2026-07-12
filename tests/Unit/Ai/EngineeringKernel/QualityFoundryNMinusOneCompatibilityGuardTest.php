<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\EngineeringKernel;

use App\Services\Ai\EngineeringKernel\Quality\QualityFoundryNMinusOneCompatibilityGuard;
use PHPUnit\Framework\TestCase;

final class QualityFoundryNMinusOneCompatibilityGuardTest extends TestCase
{
    public function test_additive_fixture_proves_rollback_to_compatible_n_minus_one(): void
    {
        $current = $this->artifact('v2', ['v1'], [
            ['operation' => 'add_table', 'name' => 'new_projection', 'destructive' => false],
            ['operation' => 'add_index', 'name' => 'projection_hash_idx', 'destructive' => false],
        ]);
        $previous = $this->artifact('v1', [], []);

        $result = (new QualityFoundryNMinusOneCompatibilityGuard)->evaluate($current, $previous, [
            'environment' => 'fixture', 'rollback_probe' => 'passed',
            'receipt_hash' => hash('sha256', 'rollback-probe'),
        ]);

        self::assertSame('compatible', $result['status']);
        self::assertTrue($result['rollback_allowed']);
        self::assertSame([], $result['blockers']);
    }

    public function test_destructive_or_unproven_fixture_is_blocked(): void
    {
        $current = $this->artifact('v2', ['v1'], [
            ['operation' => 'drop_column', 'name' => 'legacy', 'destructive' => true],
        ]);

        $result = (new QualityFoundryNMinusOneCompatibilityGuard)->evaluate($current, $this->artifact('v1', [], []), [
            'environment' => 'fixture', 'rollback_probe' => 'missing',
        ]);

        self::assertSame('blocked', $result['status']);
        self::assertFalse($result['rollback_allowed']);
        self::assertContains('migration_not_forward_only_additive:drop_column', $result['blockers']);
        self::assertContains('rollback_probe_required', $result['blockers']);
    }

    public function test_schema_or_artifact_drift_blocks_rollback(): void
    {
        $current = $this->artifact('v2', ['v0'], [['operation' => 'add_column', 'name' => 'safe', 'destructive' => false]]);
        $previous = $this->artifact('v1', [], []);
        $previous['artifact_hash'] = hash('sha256', 'mutated-previous');

        $result = (new QualityFoundryNMinusOneCompatibilityGuard)->evaluate($current, $previous, [
            'environment' => 'staging', 'rollback_probe' => 'passed', 'receipt_hash' => hash('sha256', 'probe'),
        ]);

        self::assertSame('blocked', $result['status']);
        self::assertContains('n_minus_one_schema_not_declared', $result['blockers']);
        self::assertContains('artifact_hash_mismatch', $result['blockers']);
    }

    /** @param list<string> $backwardCompatibleWith @param list<array<string,mixed>> $migrations */
    private function artifact(string $schema, array $backwardCompatibleWith, array $migrations): array
    {
        $artifact = [
            'schema_version' => $schema,
            'artifact_id' => 'artifact-'.$schema,
            'backward_compatible_with' => $backwardCompatibleWith,
            'migrations' => $migrations,
        ];
        $artifact['artifact_hash'] = hash('sha256', json_encode($artifact, JSON_THROW_ON_ERROR));

        return $artifact;
    }
}
