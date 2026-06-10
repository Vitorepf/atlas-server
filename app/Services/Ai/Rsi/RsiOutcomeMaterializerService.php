<?php

declare(strict_types=1);

namespace App\Services\Ai\Rsi;

use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AutonomousLoopReceiptIntegrityService;
use App\Services\Ai\Support\AppendOnlyJsonlStore;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Governed RSI · Part 3 · RsiOutcomeMaterializerService — META measured-or-
 * reverted (the closing authority of the self-improvement loop).
 *
 * After a HUMAN-APPROVED self-improvement to a loop COMPONENT has MERGED through
 * the normal owner-flow (proven by a real autonomous_loop_cycle_receipt.v1 with
 * lifecycle_state===merged and a non-empty merge_hash), this service decides —
 * with REAL signal only — whether the self-improvement CONSOLIDATES or is
 * REVERTED, mirroring FoundryEvolutionOutcomeMaterializerService:
 *
 *   - action='consolidate' (state proven) ONLY when BOTH hold:
 *       (a) the operator explicitly ACCEPTED the merged self-improvement
 *           (real-world signal via the GroundTruthValueAdapter; live telemetry
 *           is out of scope), AND
 *       (b) the target component's ground-truth value-per-token RE-MEASURED AFTER
 *           the merge >= baseline_value_per_token + target_delta on the SAME
 *           metric the proposal bound (no inferred metric).
 *   - otherwise action='reverted', refuted_by_reality=true, post_value
 *     null-on-block (NEVER fabricated), the RsiGitRevertPort is invoked
 *     (`git revert --no-edit`, NEVER `git reset --hard`), and the outcome is
 *     marked LEARNED (a recorded negative-value attempt the loop will not retry
 *     blind).
 *
 * This is the meta-loop's teeth: a self-improvement to the loop's own machinery
 * that did not provably raise the target component's value-per-token — even if a
 * human approved it — is reverted. A merge alone is `implemented`; only a real
 * operator-accepted, value-raising re-measure is `proven`.
 *
 * RSI SAFETY: the self-improvement was already screened by the Build-Safety
 * RsiInvariantGuardService + routed proposal-only through the human gate upstream
 * (SelfTargetSelector / RsiSelfImprovementProposalGate). This service is the
 * downstream meta-judge; it NEVER auto-applies, NEVER canonizes, NEVER calls a
 * provider, and reverts (never resets) on any non-improvement. It composes — does
 * NOT duplicate — the GroundTruthValueAdapter (real signal) and the loop's revert
 * sequence (via the RsiGitRevertPort).
 */
final class RsiOutcomeMaterializerService
{
    public const SCHEMA = 'atlas.rsi.evolution_outcome.v1';

    public const STATUS_CONSOLIDATED = 'consolidated';

    public const STATUS_REVERTED = 'reverted';

    public const STATUS_BLOCKED = 'blocked';

    public const ACTION_CONSOLIDATE = 'consolidate';

    public const ACTION_REVERTED = 'reverted';

    public const STATE_IMPLEMENTED = 'implemented';

    public const STATE_PROVEN = 'proven';

    public const STATE_REVERTED = 'reverted';

    public const BLOCK_NOT_MERGED = 'self_improvement_not_merged';

    public const BLOCK_NO_COMPONENT = 'target_component_required';

    public const BLOCK_NO_CONTRACT = 'value_per_token_contract_required';

    private ?string $storageRootOverride = null;

    public function __construct(
        private readonly GroundTruthValueAdapterService $groundTruth,
        private readonly RsiGitRevertPort $gitRevertPort,
    ) {}

    public function setStorageRootForTesting(?string $dir): void
    {
        $this->storageRootOverride = $dir;
    }

    /**
     * Meta measured-or-reverted materialization for a merged self-improvement.
     *
     * @param  array<string,mixed>  $mergedCycleReceipt  autonomous_loop_cycle_receipt.v1
     *                                                   (lifecycle_state===merged + merge_hash)
     * @param  array<string,mixed>  $selfImprovement  the human-approved self-improvement:
     *                                                component_id + value_per_token contract
     *                                                {baseline, target_delta}
     * @param  array<string,mixed>  $context  area_id/focus/repo_root; optional
     *                                        `records` injected ledger events
     * @return array<string,mixed> atlas.rsi.evolution_outcome.v1 verdict
     */
    public function materialize(array $mergedCycleReceipt, array $selfImprovement, array $context = []): array
    {
        // (1) Precondition: the self-improvement MUST be really merged.
        $lifecycle = is_string($mergedCycleReceipt['lifecycle_state'] ?? null) ? $mergedCycleReceipt['lifecycle_state'] : '';
        $mergeHash = is_string($mergedCycleReceipt['merge_hash'] ?? null) ? trim($mergedCycleReceipt['merge_hash']) : '';
        if ($lifecycle !== AutonomousLoopReceiptIntegrityService::STATE_MERGED || $mergeHash === '') {
            return $this->block(self::BLOCK_NOT_MERGED, $context);
        }

        // (2) The self-improvement must name the target component and its bound
        // value-per-token contract {baseline, target_delta} — no inferred metric.
        $componentId = is_string($selfImprovement['component_id'] ?? null) ? trim($selfImprovement['component_id']) : '';
        if ($componentId === '') {
            return $this->block(self::BLOCK_NO_COMPONENT, $context);
        }
        $contract = is_array($selfImprovement['value_per_token_contract'] ?? null) ? $selfImprovement['value_per_token_contract'] : [];
        if (! is_numeric($contract['baseline'] ?? null) || ! is_numeric($contract['target_delta'] ?? null)) {
            return $this->block(self::BLOCK_NO_CONTRACT, $context);
        }
        $baseline = (float) $contract['baseline'];
        $targetDelta = (float) $contract['target_delta'];

        $areaId = (string) ($context['area_id'] ?? 'agentic_engineering_os');
        $focus = (string) ($context['focus'] ?? 'dev_forge');
        $repoRoot = (string) ($context['repo_root'] ?? (function_exists('base_path') ? base_path() : '.'));

        // (3) Re-measure the component's ground-truth value-per-token AFTER the
        // merge, plus the real-world operator-acceptance signal. The adapter is
        // the SINGLE real-signal boundary; a missing signal is NULL, never faked.
        $signal = $this->groundTruth->groundTruthValue([
            'area_id' => $areaId,
            'focus' => $focus,
            'component_id' => $componentId,
            'merge_hash' => $mergeHash,
            'records' => $context['records'] ?? null,
        ]);

        $operatorAccepted = (bool) ($signal['operator_accepted'] ?? false);
        $postValue = is_numeric($signal['value'] ?? null) ? (float) $signal['value'] : null;
        $required = $baseline + $targetDelta;

        // Consolidate ONLY when the human accepted AND reality raised the
        // component's value-per-token to/above the bound threshold.
        $valueRaised = $postValue !== null && $postValue >= $required;
        $improved = $operatorAccepted && $valueRaised;

        $measuredAt = $this->now($context);

        if ($improved) {
            $outcome = $this->buildOutcome(
                $componentId, $mergeHash, $baseline, $targetDelta, $postValue,
                improved: true,
                operatorAccepted: true,
                action: self::ACTION_CONSOLIDATE,
                state: self::STATE_PROVEN,
                refutedByReality: false,
                learned: false,
                revertCommitHash: null,
                signal: $signal,
                measuredAt: $measuredAt,
                areaId: $areaId,
                focus: $focus,
            );
            $this->append($areaId, $focus, $outcome);

            return [
                'status' => self::STATUS_CONSOLIDATED,
                'outcome' => $outcome,
                'revert_commit_hash' => null,
                'blocker_reason' => null,
            ];
        }

        // Non-improvement (operator did not accept OR value did not rise OR the
        // post-merge signal is unavailable): REVERT via git revert --no-edit.
        $revert = $this->gitRevertPort->revert($repoRoot, $mergeHash);
        $revertCommitHash = is_string($revert['revert_commit_hash'] ?? null) && $revert['revert_commit_hash'] !== ''
            ? $revert['revert_commit_hash']
            : null;

        $outcome = $this->buildOutcome(
            $componentId, $mergeHash, $baseline, $targetDelta, $postValue,
            improved: false,
            operatorAccepted: $operatorAccepted,
            action: self::ACTION_REVERTED,
            state: self::STATE_REVERTED,
            refutedByReality: true,
            learned: true,
            revertCommitHash: $revertCommitHash,
            signal: $signal,
            measuredAt: $measuredAt,
            areaId: $areaId,
            focus: $focus,
        );
        $outcome['revert_detail'] = (string) ($revert['detail'] ?? '');
        $this->append($areaId, $focus, $outcome);

        return [
            'status' => self::STATUS_REVERTED,
            'outcome' => $outcome,
            'revert_commit_hash' => $revertCommitHash,
            'blocker_reason' => null,
        ];
    }

    /**
     * @param  array<string,mixed>  $signal
     * @return array<string,mixed>
     */
    private function buildOutcome(
        string $componentId,
        string $mergeHash,
        float $baseline,
        float $targetDelta,
        ?float $postValue,
        bool $improved,
        bool $operatorAccepted,
        string $action,
        string $state,
        bool $refutedByReality,
        bool $learned,
        ?string $revertCommitHash,
        array $signal,
        string $measuredAt,
        string $areaId,
        string $focus,
    ): array {
        $outcome = [
            'schema_version' => self::SCHEMA,
            'area_id' => $areaId,
            'focus' => $focus,
            'component_id' => $componentId,
            'merge_hash' => $mergeHash,
            'metric_id' => 'rsi_value_per_token_'.$componentId,
            'baseline_value_per_token' => $baseline,
            'target_delta' => $targetDelta,
            'required_value_per_token' => round($baseline + $targetDelta, 8),
            'post_value_per_token' => $postValue,
            'operator_accepted' => $operatorAccepted,
            'improved' => $improved,
            'action' => $action,
            'state' => $state,
            'refuted_by_reality' => $refutedByReality,
            'learned' => $learned,
            'revert_commit_hash' => $revertCommitHash,
            'measured_at' => $measuredAt,
            'ground_truth_signal' => $signal,
            'provider_invoked' => false,
            'auto_applied' => false,
            'auto_canonized' => false,
        ];

        $outcome['outcome_hash'] = MissionCanonicalHash::sha256([
            'component_id' => $componentId,
            'merge_hash' => $mergeHash,
            'baseline_value_per_token' => $baseline,
            'target_delta' => $targetDelta,
            'post_value_per_token' => $postValue,
            'operator_accepted' => $operatorAccepted,
            'improved' => $improved,
            'action' => $action,
            'refuted_by_reality' => $refutedByReality,
            'revert_commit_hash' => $revertCommitHash,
        ]);

        return $outcome;
    }

    /**
     * @param  array<string,mixed>  $context
     * @return array{status:string,outcome:null,revert_commit_hash:null,blocker_reason:string}
     */
    private function block(string $reason, array $context): array
    {
        return [
            'status' => self::STATUS_BLOCKED,
            'outcome' => null,
            'revert_commit_hash' => null,
            'blocker_reason' => $reason,
        ];
    }

    /**
     * @param  array<string,mixed>  $outcome
     */
    private function append(string $areaId, string $focus, array $outcome): void
    {
        AppendOnlyJsonlStore::appendUsingFilePutContents(
            $this->ledgerPath($areaId, $focus),
            $outcome,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            FILE_APPEND,
        );
    }

    public function ledgerPath(string $areaId, string $focus): string
    {
        $root = $this->storageRootOverride
            ?? (function_exists('storage_path')
                ? storage_path('atlas/rsi/evolution_outcomes')
                : sys_get_temp_dir().'/atlas/rsi/evolution_outcomes');

        return rtrim($root, '/').'/'.$this->slug($areaId).'/'.$this->slug($focus).'.jsonl';
    }

    /**
     * Replay the append-only meta-outcome ledger (deterministic fold, no writes).
     *
     * @return list<array<string,mixed>>
     */
    public function replay(string $areaId, string $focus): array
    {
        return AppendOnlyJsonlStore::read($this->ledgerPath($areaId, $focus));
    }

    private function slug(string $value): string
    {
        $slug = strtolower((string) preg_replace('/[^a-zA-Z0-9_-]+/', '_', trim($value)));

        return trim($slug, '_') ?: 'unscoped';
    }

    /**
     * @param  array<string,mixed>  $context
     */
    private function now(array $context): string
    {
        $at = $context['measured_at'] ?? null;
        if (is_string($at) && $at !== '') {
            return $at;
        }

        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);
    }
}
