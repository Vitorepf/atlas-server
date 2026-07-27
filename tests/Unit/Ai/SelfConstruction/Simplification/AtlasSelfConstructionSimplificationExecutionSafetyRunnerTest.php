<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Simplification;

use App\Services\Ai\SelfConstruction\Simplification\AtlasSelfConstructionDeadOrganRetirementLedger;
use App\Services\Ai\SelfConstruction\Simplification\AtlasSelfConstructionSimplificationExecutionSafetyRunner;
use Tests\TestCase;

/**
 * Proves the execution safety runner gates deletions through six organs.
 *
 * THREE CASES:
 *   1. A deletion lacking a reversible rollback preimage is blocked.
 *   2. A deletion with a failing regression replay is blocked.
 *   3. A rollback-safe, regression-passing deletion executes and is ledgered.
 */
final class AtlasSelfConstructionSimplificationExecutionSafetyRunnerTest extends TestCase
{
    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function validConsolidation(array $overrides = []): array
    {
        return array_merge([
            'organ_id' => 'stale_wrapper',
            'action' => 'delete',
            'pre_image_refs' => ['pre-image-001'],
            'touched_files' => ['src/Organs/Stale.php'],
            'replay_gates' => ['php artisan test tests/Unit/Organs/StaleTest.php'],
            'restore_steps' => ['git checkout src/Organs/Stale.php'],
            'tests_covering_targets' => ['tests/Unit/Organs/StaleTest.php'],
            'behavior_equivalence_proven' => true,
            'rollback_plan_present' => true,
            'public_command_consumers' => ['php artisan stale:cleanup'],
            'command_replay_expectations' => [
                ['command' => 'php artisan stale:cleanup', 'expected_exit_code' => 0, 'output_contract_refs' => ['StaleCleanedUp']],
            ],
            'target_symbol' => 'App\\Organs\\Stale',
            'replacement_symbol' => 'App\\Organs\\NewOwner',
            'consumers' => [['file' => 'src/Organs/Stale.php', 'alias' => 'Stale']],
            'allowed_files' => ['src/Organs/Stale.php'],
            'required_tests' => ['tests/Unit/Organs/StaleTest.php'],
            'candidate_id' => 'stale_wrapper',
            'runtime_consumers' => [],
            'public_contract_consumers' => [],
            'dynamic_consumers' => [],
            'replacement_owner' => 'NewOwnerService',
            'rollback_path' => 'git revert HEAD',
            'canonical_owner' => 'NewOwnerService',
            'consumers_mapped' => true,
            'replacement_capability' => true,
            'replay_proof' => true,
            'rollback_receipt' => true,
            'docs_sync' => true,
            'evidence_refs' => ['test:stale-removed'],
            'consumer_scan_result' => ['consumers' => []],
            'parity_decision' => ['equivalent' => true],
        ], $overrides);
    }

    private function runner(): AtlasSelfConstructionSimplificationExecutionSafetyRunner
    {
        return new AtlasSelfConstructionSimplificationExecutionSafetyRunner;
    }

    public function test_lacking_rollback_preimage_is_blocked(): void
    {
        // No pre_image_refs → rollback preimage check blocks.
        $result = $this->runner()->execute($this->validConsolidation([
            'pre_image_refs' => [],
        ]));

        $this->assertTrue($result['execution_blocked']);
        $this->assertNotEmpty($result['blockers']);
        $this->assertStringContainsString('rollback_preimage', $result['blockers'][0]);
        $this->assertTrue((bool) ($result['execution_plan']['rollback_preimage']['blocked'] ?? false));
    }

    public function test_failing_regression_replay_is_blocked(): void
    {
        // Rollback preimage passes, but no behavior equivalence → regression replay is not ready.
        $result = $this->runner()->execute($this->validConsolidation([
            'behavior_equivalence_proven' => false,
        ]));

        $this->assertTrue($result['execution_blocked']);
        $replayBlockers = array_values(array_filter(
            $result['blockers'],
            static fn (string $b): bool => str_starts_with($b, 'regression_replay:'),
        ));
        $this->assertNotEmpty($replayBlockers, 'must have regression_replay blockers');
        $this->assertFalse((bool) ($result['execution_plan']['regression_replay']['ready'] ?? true));
    }

    public function test_rollback_safe_regression_passing_deletion_executes_and_is_ledgered(): void
    {
        $result = $this->runner()->execute($this->validConsolidation());

        $this->assertFalse($result['execution_blocked'], 'all six gates should pass');
        $this->assertSame([], $result['blockers']);

        // Rollback preimage passed
        $this->assertFalse((bool) ($result['execution_plan']['rollback_preimage']['blocked'] ?? true));
        // Regression replay is ready
        $this->assertTrue((bool) ($result['execution_plan']['regression_replay']['ready'] ?? false));
        // Safe deletion planned
        $this->assertSame('safe_delete', $result['execution_plan']['safe_deletion']['action'] ?? '');
        // Import rewrite is safe
        $this->assertFalse((bool) ($result['execution_plan']['import_rewrite']['unsafe'] ?? true));
        // Retirement is recorded
        $this->assertSame(
            AtlasSelfConstructionDeadOrganRetirementLedger::STATUS_RECORDED,
            $result['execution_plan']['retirement_ledger']['status'] ?? '',
        );
        $this->assertNotNull($result['execution_plan']['retirement_ledger']['receipt_hash']);
    }
}
