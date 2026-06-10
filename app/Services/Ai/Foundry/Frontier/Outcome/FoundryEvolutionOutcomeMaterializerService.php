<?php

declare(strict_types=1);

namespace App\Services\Ai\Foundry\Frontier\Outcome;

use App\Services\Ai\Foundry\Frontier\Armor\FrontierMetricRollbackGate;
use App\Services\Ai\Foundry\FoundrySchemas;
use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AutonomousLoopReceiptIntegrityService;
use App\Services\Ai\Support\AppendOnlyJsonlStore;

/**
 * Foundry AP-E · Evolution Outcome Materializer — Invariant I5 (Measured-or-
 * Reverted) + I9 (property binding).
 *
 * This is the closing authority of the AFEF generation loop. After an
 * AFEF-origin finding has been MERGED (proven by a real
 * autonomous_loop_cycle_receipt.v1 with lifecycle_state===merged and a
 * non-empty merge_hash), this service decides — with REAL evidence only —
 * whether the change CONSOLIDATES or is REVERTED:
 *
 *   - evolution_outcome.v1 action='consolidate' is emitted ONLY when the
 *     proposal-bound success_metric measure_cmd ran for REAL (port.ran===true,
 *     exit_code===0) and the falsifiable operator(post_value, threshold)
 *     comparison passes within tolerance on the canonical property. No real
 *     green measure => NEVER consolidate.
 *   - Otherwise (comparison fails OR the measure port blocked/timed out) the
 *     outcome is action='reverted', refuted_by_reality=true, post_value is
 *     null-on-block (NEVER a fabricated numeric), the GitRevertPort is invoked
 *     (`git revert --no-edit`, NEVER `git reset --hard`), and the roadmap
 *     capability transitions implemented->reverted.
 *
 * I9 property binding: the measured property and the measure_cmd MUST live in
 * the SAME proposal.success_metric block that FrontierMetricRollbackGate
 * falsified — never an inferred command nor an unvalidated finding spec_seed
 * copy. materialize() blocks ('metric_property_required' /
 * 'measure_cmd_not_bound_to_property') unless both hold.
 *
 * measure_cmd execution and git revert are REAL-OR-BLOCKED via ports: with no
 * real merged finding both BLOCK honestly. The roadmap distinguishes
 * implemented (merged) from proven (measured) — only a real green measure
 * transitions implemented->proven.
 *
 * Composes (does NOT duplicate): FrontierMetricRollbackGate for falsifiable
 * metric decoding, FoundrySchemas::validateShape for outcome + roadmap shape,
 * AutonomousLoopReceiptIntegrityService for the merged precondition,
 * MissionCanonicalHash for deterministic hashes.
 */
final class FoundryEvolutionOutcomeMaterializerService
{
    public const STATUS_CONSOLIDATED = 'consolidated';

    public const STATUS_REVERTED = 'reverted';

    public const STATUS_BLOCKED = 'blocked';

    public const ACTION_CONSOLIDATE = 'consolidate';

    public const ACTION_REVERTED = 'reverted';

    public const STATE_IMPLEMENTED = 'implemented';

    public const STATE_PROVEN = 'proven';

    public const STATE_REVERTED = 'reverted';

    public const BLOCK_FINDING_NOT_MERGED = 'finding_not_merged';

    public const BLOCK_METRIC_NOT_FALSIFIABLE = 'metric_not_falsifiable';

    public const BLOCK_METRIC_PROPERTY_REQUIRED = 'metric_property_required';

    public const BLOCK_MEASURE_CMD_REQUIRED = 'measure_cmd_required';

    public const BLOCK_MEASURE_CMD_NOT_BOUND = 'measure_cmd_not_bound_to_property';

    public const BLOCK_ROADMAP_NOT_LINKED = 'roadmap_capability_not_linked';

    private ?string $outcomesStorageDirOverride = null;

    public function __construct(
        private readonly FrontierMetricRollbackGate $metricRollbackGate,
        private readonly MeasureCommandPort $measureCommandPort,
        private readonly GitRevertPort $gitRevertPort,
        private readonly RoadmapStorePort $roadmapStore,
    ) {}

    /**
     * Scoped JSONL seam (mirrors
     * FoundryEvidenceVerifierService::setRejectionStorageDirForTesting).
     */
    public function setOutcomesStorageDirForTesting(?string $dir): void
    {
        $this->outcomesStorageDirOverride = $dir;
    }

    /**
     * I5/I9 measured-or-reverted materialization.
     *
     * @param  array<string,mixed>  $mergedCycleReceipt  autonomous_loop_cycle_receipt.v1
     * @param  array<string,mixed>  $evolutionProposal   gate-admitted evolution_proposal.v1
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function materialize(
        array $mergedCycleReceipt,
        array $evolutionProposal,
        string $areaId,
        array $input = [],
    ): array {
        // (1) Precondition: the finding MUST be really merged. No measure runs otherwise.
        $lifecycleState = is_string($mergedCycleReceipt['lifecycle_state'] ?? null)
            ? $mergedCycleReceipt['lifecycle_state']
            : '';
        $mergeHash = is_string($mergedCycleReceipt['merge_hash'] ?? null)
            ? $mergedCycleReceipt['merge_hash']
            : '';

        if ($lifecycleState !== AutonomousLoopReceiptIntegrityService::STATE_MERGED || $mergeHash === '') {
            return $this->block(self::BLOCK_FINDING_NOT_MERGED);
        }

        // (2) Decode the falsifiable {operator,baseline,threshold} via the gate ONLY.
        $gate = $this->metricRollbackGate->evaluate($evolutionProposal);
        $metric = $gate['falsifiable_metric'] ?? null;
        if (! is_array($metric)) {
            return $this->block(self::BLOCK_METRIC_NOT_FALSIFIABLE);
        }
        $operator = (string) $metric['operator'];
        $threshold = (string) $metric['threshold'];
        $baseline = (string) $metric['baseline'];

        // (3) I9 property binding. property + measure_cmd MUST be in the
        // gate-admitted proposal.success_metric block — never a spec_seed copy.
        $successMetric = $evolutionProposal['success_metric'] ?? null;
        $property = '';
        $boundMeasureCmd = '';
        if (is_array($successMetric)) {
            $property = is_string($successMetric['property'] ?? null) ? trim($successMetric['property']) : '';
            $boundMeasureCmd = is_string($successMetric['measure_cmd'] ?? null) ? trim($successMetric['measure_cmd']) : '';
        }

        if ($property === '') {
            return $this->block(self::BLOCK_METRIC_PROPERTY_REQUIRED);
        }

        // A measure_cmd offered ONLY via an unvalidated finding spec_seed copy
        // (input), absent from the validated proposal block, is rejected: the
        // executed command MUST belong to the proposal whose property the gate
        // falsified.
        if ($boundMeasureCmd === '') {
            $specSeedCmd = '';
            $specSeed = $input['finding_spec_seed'] ?? null;
            if (is_array($specSeed)) {
                $ssMetric = $specSeed['success_metric'] ?? null;
                if (is_array($ssMetric) && is_string($ssMetric['measure_cmd'] ?? null) && trim($ssMetric['measure_cmd']) !== '') {
                    $specSeedCmd = trim($ssMetric['measure_cmd']);
                }
            }

            return $this->block(
                $specSeedCmd !== '' ? self::BLOCK_MEASURE_CMD_NOT_BOUND : self::BLOCK_MEASURE_CMD_REQUIRED,
            );
        }

        // (4) Roadmap linkage. Map the merged-cycle PACKET finding_id (parent::packet::N)
        // to its PARENT finding id and match against the latest roadmap capability.
        $packetFindingId = is_string($mergedCycleReceipt['finding_id'] ?? null)
            ? $mergedCycleReceipt['finding_id']
            : '';
        $parentFindingId = $this->parentFindingId($packetFindingId);

        $roadmapBefore = $this->roadmapStore->latest($areaId);
        $capabilityIndex = $this->matchCapabilityIndex($roadmapBefore, $parentFindingId);
        if ($roadmapBefore === null || $capabilityIndex === null) {
            return $this->block(self::BLOCK_ROADMAP_NOT_LINKED);
        }

        // (5) Run the proposal-bound measure_cmd (real-or-blocked).
        $measure = $this->measureCommandPort->run($boundMeasureCmd, $property);
        $ran = (bool) ($measure['ran'] ?? false);
        $exitCode = (int) ($measure['exit_code'] ?? -1);
        $stdout = is_string($measure['stdout'] ?? null) ? $measure['stdout'] : '';

        $postValue = null;
        $toleranceMet = false;
        $improved = false;

        if ($ran && $exitCode === 0) {
            $parsed = $this->parseFloatOrNull($stdout);
            if ($parsed !== null) {
                $postValue = $parsed;
                $toleranceMet = $this->compare($operator, $parsed, $threshold);
                $improved = $toleranceMet;
            }
        }

        $measuredAt = $this->now($input);

        if ($improved) {
            // Real green measure: CONSOLIDATE + implemented->proven.
            $outcome = $this->buildOutcome(
                proposal: $evolutionProposal,
                measureCmd: $boundMeasureCmd,
                baseline: $baseline,
                postValue: $postValue,
                improved: true,
                toleranceMet: true,
                action: self::ACTION_CONSOLIDATE,
                refutedByReality: false,
                measuredAt: $measuredAt,
                mergeHash: $mergeHash,
                packetFindingId: $packetFindingId,
                property: $property,
                revertCommitHash: null,
            );

            $roadmapAfter = $this->advanceRoadmap(
                $roadmapBefore,
                $capabilityIndex,
                self::STATE_PROVEN,
                $outcome['outcome_hash'],
                $measuredAt,
            );

            $this->appendOutcome($areaId, $outcome);
            $this->appendRoadmap($areaId, $roadmapAfter);

            return [
                'status' => self::STATUS_CONSOLIDATED,
                'outcome' => $outcome,
                'roadmap_after' => $roadmapAfter,
                'blocker_reason' => null,
            ];
        }

        // Non-improvement (comparison failed OR measure blocked/timed out):
        // REVERT via the git revert PORT (--no-edit, NEVER reset --hard).
        $verifyCmd = $this->rollbackVerifyCmd($evolutionProposal);
        $revert = $this->gitRevertPort->revert($mergeHash, $verifyCmd);
        $revertCommitHash = is_string($revert['revert_commit_hash'] ?? null) ? $revert['revert_commit_hash'] : '';

        $outcome = $this->buildOutcome(
            proposal: $evolutionProposal,
            measureCmd: $boundMeasureCmd,
            baseline: $baseline,
            postValue: $postValue,
            improved: false,
            toleranceMet: $toleranceMet,
            action: self::ACTION_REVERTED,
            refutedByReality: true,
            measuredAt: $measuredAt,
            mergeHash: $mergeHash,
            packetFindingId: $packetFindingId,
            property: $property,
            revertCommitHash: $revertCommitHash,
        );

        $roadmapAfter = $this->advanceRoadmap(
            $roadmapBefore,
            $capabilityIndex,
            self::STATE_REVERTED,
            $outcome['outcome_hash'],
            $measuredAt,
        );

        $this->appendOutcome($areaId, $outcome);
        $this->appendRoadmap($areaId, $roadmapAfter);

        return [
            'status' => self::STATUS_REVERTED,
            'outcome' => $outcome,
            'roadmap_after' => $roadmapAfter,
            'revert_commit_hash' => $revertCommitHash,
            'blocker_reason' => null,
        ];
    }

    /**
     * @param  array<string,mixed>  $proposal
     * @return array<string,mixed>
     */
    private function buildOutcome(
        array $proposal,
        string $measureCmd,
        string $baseline,
        ?float $postValue,
        bool $improved,
        bool $toleranceMet,
        string $action,
        bool $refutedByReality,
        string $measuredAt,
        string $mergeHash,
        string $packetFindingId,
        string $property,
        ?string $revertCommitHash,
    ): array {
        $proposalId = is_string($proposal['proposal_id'] ?? null) ? $proposal['proposal_id'] : '';

        $outcome = [
            'schema_version' => FoundrySchemas::EVOLUTION_OUTCOME,
            'proposal_id' => $proposalId,
            'measure_cmd' => $measureCmd,
            'baseline' => $baseline,
            'post_value' => $postValue,
            'improved' => $improved,
            'tolerance_met' => $toleranceMet,
            'action' => $action,
            'refuted_by_reality' => $refutedByReality,
            'measured_at' => $measuredAt,
            // ---- extra audit keys (non-fatal unexpected_keys under validateShape) ----
            'merge_hash' => $mergeHash,
            'finding_id' => $packetFindingId,
            'property' => $property,
            'revert_commit_hash' => $revertCommitHash,
        ];

        $outcome['outcome_hash'] = MissionCanonicalHash::sha256([
            'proposal_id' => $proposalId,
            'measure_cmd' => $measureCmd,
            'baseline' => $baseline,
            'post_value' => $postValue,
            'improved' => $improved,
            'tolerance_met' => $toleranceMet,
            'action' => $action,
            'refuted_by_reality' => $refutedByReality,
            'merge_hash' => $mergeHash,
            'finding_id' => $packetFindingId,
            'property' => $property,
            'revert_commit_hash' => $revertCommitHash,
        ]);

        return $outcome;
    }

    /**
     * Append-only roadmap advance: copy the latest line, transition the matched
     * capability state, bump version+1, restamp generated_at. NEVER mutates.
     *
     * @param  array<string,mixed>  $roadmap
     * @return array<string,mixed>
     */
    private function advanceRoadmap(
        array $roadmap,
        int $capabilityIndex,
        string $newState,
        string $evidenceRef,
        string $updatedAt,
    ): array {
        $next = $roadmap;
        $capabilities = is_array($roadmap['capabilities'] ?? null) ? $roadmap['capabilities'] : [];
        $capabilities = array_values($capabilities);

        $capability = is_array($capabilities[$capabilityIndex] ?? null) ? $capabilities[$capabilityIndex] : [];
        $capVersion = (int) ($capability['version'] ?? 0);
        $capability['state'] = $newState;
        $capability['version'] = $capVersion + 1;
        $capability['updated_at'] = $updatedAt;
        $capability['evidence_ref'] = $evidenceRef;
        $capabilities[$capabilityIndex] = $capability;

        $next['capabilities'] = $capabilities;
        $next['version'] = (int) ($roadmap['version'] ?? 0) + 1;
        $next['generated_at'] = $updatedAt;

        return $next;
    }

    /**
     * Match the latest roadmap capability whose finding_id equals the PARENT
     * promoted finding id. Returns the array index, or null if no unambiguous
     * single match.
     *
     * @param  array<string,mixed>|null  $roadmap
     */
    private function matchCapabilityIndex(?array $roadmap, string $parentFindingId): ?int
    {
        if ($roadmap === null || $parentFindingId === '') {
            return null;
        }

        $capabilities = is_array($roadmap['capabilities'] ?? null) ? array_values($roadmap['capabilities']) : [];
        $matches = [];
        foreach ($capabilities as $i => $capability) {
            if (! is_array($capability)) {
                continue;
            }
            if ((string) ($capability['finding_id'] ?? '') === $parentFindingId) {
                $matches[] = $i;
            }
        }

        return count($matches) === 1 ? $matches[0] : null;
    }

    /**
     * Map a packet finding_id (parent::packet::N) to its PARENT finding id.
     */
    private function parentFindingId(string $packetFindingId): string
    {
        if ($packetFindingId === '') {
            return '';
        }
        $pos = strpos($packetFindingId, '::packet::');
        if ($pos === false) {
            return $packetFindingId;
        }

        return substr($packetFindingId, 0, $pos);
    }

    private function rollbackVerifyCmd(array $proposal): string
    {
        $rollback = $proposal['rollback'] ?? null;
        if (is_array($rollback) && is_string($rollback['verify_cmd'] ?? null)) {
            return trim($rollback['verify_cmd']);
        }

        return '';
    }

    private function compare(string $operator, float $postValue, string $threshold): bool
    {
        if (! is_numeric($threshold)) {
            return false;
        }
        $t = (float) $threshold;

        return match ($operator) {
            '<=' => $postValue <= $t,
            '>=' => $postValue >= $t,
            '<' => $postValue < $t,
            '>' => $postValue > $t,
            '==' => $postValue === $t,
            '!=' => $postValue !== $t,
            default => false,
        };
    }

    private function parseFloatOrNull(string $stdout): ?float
    {
        $trimmed = trim($stdout);
        if ($trimmed === '' || ! is_numeric($trimmed)) {
            return null;
        }

        return (float) $trimmed;
    }

    /**
     * @param  array<string,mixed>  $input
     */
    private function now(array $input): string
    {
        $at = $input['measured_at'] ?? null;

        return is_string($at) && $at !== '' ? $at : '1970-01-01T00:00:00+00:00';
    }

    /**
     * @return array{status:string,outcome:null,roadmap_after:null,blocker_reason:string}
     */
    private function block(string $reason): array
    {
        return [
            'status' => self::STATUS_BLOCKED,
            'outcome' => null,
            'roadmap_after' => null,
            'blocker_reason' => $reason,
        ];
    }

    /**
     * @param  array<string,mixed>  $outcome
     */
    private function appendOutcome(string $areaId, array $outcome): void
    {
        AppendOnlyJsonlStore::append($this->storagePath($areaId, 'evolution_outcomes.jsonl'), $outcome);
    }

    /**
     * @param  array<string,mixed>  $roadmap
     */
    private function appendRoadmap(string $areaId, array $roadmap): void
    {
        AppendOnlyJsonlStore::append($this->storagePath($areaId, 'roadmap.jsonl'), $roadmap);
    }

    private function storagePath(string $areaId, string $file): string
    {
        $slug = preg_replace('/[^a-z0-9_]+/', '_', strtolower($areaId)) ?: 'unknown_area';

        $base = $this->outcomesStorageDirOverride
            ?? (function_exists('storage_path') ? storage_path('atlas/foundry') : sys_get_temp_dir().'/atlas/foundry');

        return $base.DIRECTORY_SEPARATOR.$slug.DIRECTORY_SEPARATOR.$file;
    }

}
