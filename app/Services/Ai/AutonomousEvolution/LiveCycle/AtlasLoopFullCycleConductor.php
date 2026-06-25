<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\LiveCycle;

use RuntimeException;
use Throwable;

/** Raised by the certify-phase runner when a cycle fails certification — short-circuits the conductor. */
class CertificationFailedException extends RuntimeException
{
}

/** Minimal contract every phase runner implements: pure, deterministic, returns a receipt array. */
interface AtlasLoopPhaseRunner
{
    /**
     * @param  array<string,mixed>  $scope  the cycle scope (id + any per-phase inputs)
     * @return array<string,mixed>          the phase receipt (status, facts, etc.)
     */
    public function run(array $scope): array;
}

/** Append-only receipt chain the conductor records each phase receipt into. */
interface UnifiedReceiptChain
{
    /**
     * @param  array<string,mixed>  $receipt
     */
    public function record(string $cycleId, string $phase, array $receipt): void;
}

/**
 * LIVE-CYCLE · the canonical 8-phase conductor (per loop-final-state-vision):
 *   orientar → compreender → decidir-alavancagem → arquitetar → decompor → implementar → certificar → fechar.
 *
 * Strictly sequential, no proxy success: a successful cycle requires ALL 8 phase receipts present with
 * non-failure status AND a non-null `merged_sha` on the close-phase receipt. Any phase that raises (e.g.,
 * {@see CertificationFailedException}) short-circuits — subsequent runners are NEVER invoked — and the final
 * receipt carries `final_status: aborted` with `aborted_at_phase` + `abort_reason`.
 */
final class AtlasLoopFullCycleConductor
{
    public const PHASES = [
        'orient',
        'comprehend',
        'leverage',
        'architect',
        'decompose',
        'implement',
        'certify',
        'close',
    ];

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_ABORTED = 'aborted';

    /**
     * @param  array{orient:AtlasLoopPhaseRunner, comprehend:AtlasLoopPhaseRunner, leverage:AtlasLoopPhaseRunner, architect:AtlasLoopPhaseRunner, decompose:AtlasLoopPhaseRunner, implement:AtlasLoopPhaseRunner, certify:AtlasLoopPhaseRunner, close:AtlasLoopPhaseRunner}  $runners
     */
    public function __construct(
        private readonly array $runners,
        private readonly UnifiedReceiptChain $receiptChain,
    ) {}

    /**
     * Factory that wires the default close-phase runner (AtlasLoopClosePhaseRunner) into the
     * conductor's runners map without any other change. This is the production seam that takes
     * AtlasLoopClosePhaseRunner off the orphan list and onto the live cycle.
     *
     * @param  array{orient:AtlasLoopPhaseRunner, comprehend:AtlasLoopPhaseRunner, leverage:AtlasLoopPhaseRunner, architect:AtlasLoopPhaseRunner, decompose:AtlasLoopPhaseRunner, implement:AtlasLoopPhaseRunner, certify:AtlasLoopPhaseRunner}  $runnersWithoutClose
     * @param  callable(array<string,mixed>):array<string,mixed>  $autoMergeDelegate
     * @param  callable(array<string,mixed>):void  $receiptChainAppender
     */
    public static function withDefaultClosePhase(
        array $runnersWithoutClose,
        UnifiedReceiptChain $receiptChain,
        callable $autoMergeDelegate,
        callable $receiptChainAppender,
        ?callable $masterSwitchOverride = null,
    ): self {
        $runnersWithoutClose['close'] = new AtlasLoopClosePhaseRunner(
            $autoMergeDelegate,
            $receiptChainAppender,
            $masterSwitchOverride,
        );

        return new self($runnersWithoutClose, $receiptChain);
    }

    /**
     * Run the 8-phase cycle to completion or abort. Returns the cycle summary receipt.
     *
     * @param  array<string,mixed>  $scope  must carry `cycle_id`
     * @return array{cycle_id:string, phases_completed:list<string>, aborted_at_phase:?string, abort_reason:?string, final_status:string, merged_sha:?string}
     */
    public function runCycle(array $scope): array
    {
        $cycleId = (string) ($scope['cycle_id'] ?? '');
        $phasesCompleted = [];
        $mergedSha = null;

        foreach (self::PHASES as $phase) {
            try {
                $receipt = $this->runners[$phase]->run($scope);
            } catch (Throwable $e) {
                return [
                    'cycle_id' => $cycleId,
                    'phases_completed' => $phasesCompleted,
                    'aborted_at_phase' => $phase,
                    'abort_reason' => mb_substr($e->getMessage(), 0, 200),
                    'final_status' => self::STATUS_ABORTED,
                    'merged_sha' => null,
                ];
            }

            // A non-failure status is required — a phase reporting status:'failed' aborts the cycle even when
            // no exception was raised (anti-proxy: a runner cannot silently mark itself failed and let the
            // chain continue).
            if ((string) ($receipt['status'] ?? 'ok') === 'failed') {
                $this->receiptChain->record($cycleId, $phase, $receipt);
                $phasesCompleted[] = $phase;

                return [
                    'cycle_id' => $cycleId,
                    'phases_completed' => $phasesCompleted,
                    'aborted_at_phase' => $phase,
                    'abort_reason' => (string) ($receipt['reason'] ?? 'phase_reported_failed'),
                    'final_status' => self::STATUS_ABORTED,
                    'merged_sha' => null,
                ];
            }

            $this->receiptChain->record($cycleId, $phase, $receipt);
            $phasesCompleted[] = $phase;
            if ($phase === 'close') {
                $mergedSha = isset($receipt['merged_sha']) ? (string) $receipt['merged_sha'] : null;
            }
        }

        // No proxy success: the close-phase receipt MUST carry a non-null merged_sha.
        if ($mergedSha === null || $mergedSha === '') {
            return [
                'cycle_id' => $cycleId,
                'phases_completed' => $phasesCompleted,
                'aborted_at_phase' => 'close',
                'abort_reason' => 'close_phase_missing_merged_sha',
                'final_status' => self::STATUS_ABORTED,
                'merged_sha' => null,
            ];
        }

        return [
            'cycle_id' => $cycleId,
            'phases_completed' => $phasesCompleted,
            'aborted_at_phase' => null,
            'abort_reason' => null,
            'final_status' => self::STATUS_COMPLETED,
            'merged_sha' => $mergedSha,
        ];
    }
}
