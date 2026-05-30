<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Rsi;

use App\Services\Ai\Rsi\GroundTruthValueAdapterService;
use App\Services\Ai\Rsi\OperatorAcceptanceSignalPort;
use App\Services\Ai\Rsi\RsiGitRevertPort;
use App\Services\Ai\Rsi\RsiOutcomeMaterializerService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\Rsi\ComponentValueLedgerService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * RSI Part 3 unit proofs for the GroundTruthValueAdapter (real-signal fold) and
 * the RsiOutcomeMaterializer (meta measured-or-reverted) honesty + safety:
 *   - a missing post-merge signal is NULL, never fabricated;
 *   - the meta authority NEVER consolidates without BOTH a real value rise AND a
 *     real operator-acceptance signal;
 *   - a non-merged self-improvement is BLOCKED (no measure, no revert);
 *   - every outcome stamps auto_applied=false + auto_canonized=false (the meta
 *     loop can never auto-canonize, mirroring proposal-only gating).
 */
final class RsiOutcomeMaterializerServiceTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir().'/atlas_rsi_unit_'.uniqid('', true);
        File::ensureDirectoryExists($this->tmp);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tmp);
        parent::tearDown();
    }

    public function test_ground_truth_value_is_null_when_no_proven_signal_exists(): void
    {
        $adapter = new GroundTruthValueAdapterService(
            $this->emptyLedger(),
            $this->acceptancePort(true),
        );

        $signal = $adapter->groundTruthValue([
            'component_id' => 'repair_loop',
            'merge_hash' => 'abc',
        ]);

        // No recorded proven cycle => null value, never a fabricated number.
        $this->assertNull($signal['value']);
        $this->assertFalse($signal['signal_available']);
        $this->assertFalse($signal['provider_invoked']);
    }

    public function test_non_merged_self_improvement_is_blocked_with_no_revert(): void
    {
        $revertLog = [];
        $service = $this->materializer($this->acceptancePort(true), $revertLog, postValuePerToken: 9.0);

        $result = $service->materialize(
            ['lifecycle_state' => 'planned', 'merge_hash' => ''],
            $this->selfImprovement(),
            ['repo_root' => $this->tmp],
        );

        $this->assertSame(RsiOutcomeMaterializerService::STATUS_BLOCKED, $result['status']);
        $this->assertSame(RsiOutcomeMaterializerService::BLOCK_NOT_MERGED, $result['blocker_reason']);
        $this->assertNull($result['outcome']);
        $this->assertSame([], $revertLog, 'A non-merged self-improvement must never be reverted.');
    }

    public function test_value_rise_without_operator_acceptance_is_reverted_never_consolidated(): void
    {
        $revertLog = [];
        $service = $this->materializer($this->acceptancePort(false), $revertLog, postValuePerToken: 99.0);

        $result = $service->materialize(
            ['lifecycle_state' => 'merged', 'merge_hash' => 'deadbeef'],
            $this->selfImprovement(),
            ['repo_root' => $this->tmp],
        );

        $this->assertSame(RsiOutcomeMaterializerService::STATUS_REVERTED, $result['status']);
        $this->assertFalse($result['outcome']['operator_accepted']);
        $this->assertTrue($result['outcome']['refuted_by_reality']);
        $this->assertTrue($result['outcome']['learned']);
        // RSI safety: the meta loop never auto-applies / auto-canonizes anything.
        $this->assertFalse($result['outcome']['auto_applied']);
        $this->assertFalse($result['outcome']['auto_canonized']);
        $this->assertSame([['deadbeef']], $revertLog);
    }

    public function test_consolidation_requires_real_rise_and_acceptance(): void
    {
        $revertLog = [];
        $service = $this->materializer($this->acceptancePort(true), $revertLog, postValuePerToken: 5.0);

        $result = $service->materialize(
            ['lifecycle_state' => 'merged', 'merge_hash' => 'deadbeef'],
            $this->selfImprovement(),
            ['repo_root' => $this->tmp],
        );

        $this->assertSame(RsiOutcomeMaterializerService::STATUS_CONSOLIDATED, $result['status']);
        $this->assertSame('proven', $result['outcome']['state']);
        $this->assertSame([], $revertLog);
        $this->assertFalse($result['outcome']['auto_canonized']);

        // Append-only ledger recorded exactly one consolidate event.
        $events = $service->replay('agentic_engineering_os', 'dev_forge');
        $this->assertCount(1, $events);
        $this->assertSame('consolidate', $events[0]['action']);
    }

    /**
     * @return array<string,mixed>
     */
    private function selfImprovement(): array
    {
        return [
            'component_id' => 'repair_loop',
            'value_per_token_contract' => ['baseline' => 1.0, 'target_delta' => 1.0],
        ];
    }

    private function emptyLedger(): ComponentValueLedgerService
    {
        $ledger = new ComponentValueLedgerService;
        $ledger->setStorageRootForTesting($this->tmp.'/empty_ledger_'.uniqid('', true));

        return $ledger;
    }

    /**
     * @param  list<array{0:string}>  $revertLog
     */
    private function materializer(OperatorAcceptanceSignalPort $acceptance, array &$revertLog, float $postValuePerToken): RsiOutcomeMaterializerService
    {
        $ledger = new ComponentValueLedgerService;
        $ledger->setStorageRootForTesting($this->tmp.'/gt_'.uniqid('', true));
        $ledger->recordCycle(
            ['area_id' => 'agentic_engineering_os', 'focus' => 'dev_forge', 'cycle_id' => 'seed', 'merge_hash' => 'seed'],
            ['outcome_met' => true, 'measured_delta' => $postValuePerToken],
            ['repair_loop' => ['tokens' => 1, 'flags' => []]],
        );

        $adapter = new GroundTruthValueAdapterService($ledger, $acceptance);

        $revertPort = new class($revertLog) implements RsiGitRevertPort
        {
            /** @param list<array{0:string}> $log */
            public function __construct(private array &$log) {}

            public function revert(string $repoRoot, string $mergeHash): array
            {
                $this->log[] = [$mergeHash];

                return ['reverted' => true, 'revert_commit_hash' => 'revert_'.$mergeHash, 'detail' => 'git_revert_no_edit'];
            }
        };

        $service = new RsiOutcomeMaterializerService($adapter, $revertPort);
        $service->setStorageRootForTesting($this->tmp.'/outcomes');

        return $service;
    }

    private function acceptancePort(bool $accepted): OperatorAcceptanceSignalPort
    {
        return new class($accepted) implements OperatorAcceptanceSignalPort
        {
            public function __construct(private readonly bool $accepted) {}

            public function isAcceptedByOperator(string $mergeHash, string $componentId): array
            {
                return $this->accepted
                    ? ['accepted' => true, 'evidence_ref' => 'e', 'confidence' => 1.0, 'reason' => 'operator_accepted']
                    : ['accepted' => false, 'evidence_ref' => null, 'confidence' => null, 'reason' => 'no_operator_acceptance_evidence'];
            }
        };
    }
}
