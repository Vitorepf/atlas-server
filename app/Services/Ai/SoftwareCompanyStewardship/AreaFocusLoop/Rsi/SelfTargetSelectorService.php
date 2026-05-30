<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\Rsi;

use App\Services\Ai\Foundry\FoundrySemanticGapFinderService;
use App\Services\Ai\Foundry\Rsi\ImmutableInvariantRegistryService;
use App\Services\Ai\Foundry\Rsi\RsiSelfImprovementProposalGate;
use App\Services\Ai\Mission\MissionCanonicalHash;

/**
 * Governed RSI · Part B · SelfTargetSelectorService + self-gap emission.
 *
 * This is the bridge that lets the loop turn its OWN value telemetry into a
 * governed, proposal-only self-improvement target. It:
 *
 *   1. Reads {@see ComponentValueLedgerService::weakestComponent()} — the
 *      lowest value-per-token, NON-sacred, token-spending loop component (the
 *      ledger already excludes deterministic / zero-token components and any
 *      component whose source is protected by the Immutable Invariant Registry).
 *      No eligible weakest => HONEST SKIP, zero self gaps emitted.
 *
 *   2. Emits a SELF gap as a Pilar 2 capability_claim (the EXACT shape the
 *      on-main {@see FoundrySemanticGapFinderService} consumes — no parallel
 *      gap schema). The "capability" is the component id; drift_kind is the
 *      closed-set member `loop_component_low_value_per_token`; the evidence
 *      anchor is the component's OWN most-recent PROVEN ComponentValueLedger
 *      cycle (a REAL recorded cycle, never synthesized); the outcome_contract
 *      demands raising that component's value-per-token by Δ, measured by a REAL
 *      existing command (atlas:rsi:component-value-per-token). The selector never
 *      invents a metric or a cycle.
 *
 *   3. Builds the proposal diff descriptor (changed_paths = the component's real
 *      source path) and screens it through the {@see RsiSelfImprovementProposalGate}
 *      FIRST — the same Build-Safety gate that runs the fail-closed
 *      RsiInvariantGuardService against the frozen sacred set. A self gap whose
 *      proposal would touch / weaken a sacred gate is BLOCKED here and is NEVER
 *      attached to the emitted claim (it can never reach the gap pipeline or the
 *      operator's human gate).
 *
 * The selector is READ-ONLY + PROPOSAL-ONLY: it never merges, never reverts,
 * never canonizes, never calls a provider, never auto-applies. It returns a
 * self_gap_record the deep finding engine can feed straight into its
 * `capability_claims` seam, so SELF gaps flow into the SAME gated proposal
 * pipeline as product gaps. Default-off: with ATLAS_RSI_MODE off the proposal
 * gate is inert, so the selector emits no routed self gap (honest skip).
 */
final class SelfTargetSelectorService
{
    public const SELF_GAP_SCHEMA = 'atlas.rsi.self_target_gap_record.v1';

    public const SELF_DRIFT_KIND = 'loop_component_low_value_per_token';

    public const MEASURE_COMMAND_PREFIX = 'php artisan atlas:rsi:component-value-per-token';

    public const STATUS_SELECTED = 'self_target_selected';

    public const STATUS_NO_TARGET = 'no_eligible_self_target';

    public const STATUS_BLOCKED_BY_INVARIANT = 'self_target_blocked_by_invariant';

    public const STATUS_SKIPPED = 'rsi_mode_off';

    /** Default minimum value-per-token improvement the self gap demands. */
    private const DEFAULT_TARGET_DELTA = 0.0001;

    public function __construct(
        private readonly ComponentValueLedgerService $ledger,
        private readonly RsiSelfImprovementProposalGate $proposalGate,
        private readonly ImmutableInvariantRegistryService $registry,
    ) {
        // The selector binds the REAL registry into the ledger so the weakest-
        // component selection always excludes sacred components in the live path.
        $this->ledger->setRegistryForTesting($this->registry);
    }

    /**
     * Select the weakest non-sacred component and emit (if eligible + guard-clean)
     * a SELF capability_claim plus its guard screening.
     *
     * Recognised $input keys:
     *   - area_id / focus:           ledger scope (defaults agentic_engineering_os / dev_forge)
     *   - records:                   list  injected ledger events (test seam; no I/O)
     *   - target_delta:              float minimum value-per-token raise (default 0.0001)
     *   - rsi_mode_enabled:          bool  flag override forwarded to the proposal gate
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed> atlas.rsi.self_target_gap_record.v1
     */
    public function select(array $input = []): array
    {
        $areaId = (string) ($input['area_id'] ?? 'agentic_engineering_os');
        $focus = (string) ($input['focus'] ?? 'dev_forge');
        $records = is_array($input['records'] ?? null) ? $input['records'] : null;
        $targetDelta = $this->resolveTargetDelta($input['target_delta'] ?? null);

        $weakest = $this->ledger->weakestComponent($areaId, $focus, $records);
        if ($weakest === null) {
            // Honest-stop: no eligible non-sacred token-spending target exists.
            return $this->emit(self::STATUS_NO_TARGET, $areaId, $focus, null, null, null, 'no eligible non-sacred weakest component with proven signal');
        }

        $componentId = (string) $weakest['component_id'];
        $sourcePath = $this->ledger->componentSourcePath($componentId);
        $baseline = (float) $weakest['value_per_token'];

        $provenCycleIds = $this->ledger->provenCycleIdsForComponent($areaId, $focus, $componentId, $records);
        if ($sourcePath === '' || $provenCycleIds === []) {
            // Cannot anchor honestly => no self gap.
            return $this->emit(self::STATUS_NO_TARGET, $areaId, $focus, $componentId, null, null, 'weakest component has no source path or no proven cycle to anchor');
        }
        // Most-recent proven cycle is the evidence anchor (real recorded cycle).
        $anchorCycleId = $provenCycleIds[array_key_last($provenCycleIds)];
        $anchorId = 'self_'.$componentId.'_'.substr(MissionCanonicalHash::sha256($anchorCycleId), 0, 16);

        $claim = $this->buildSelfClaim($componentId, (string) $weakest['role'], $sourcePath, $baseline, $targetDelta, $anchorId, $anchorCycleId, $areaId, $focus);

        // SAFETY FIRST: screen the proposal that would CLOSE this self gap before
        // the claim may flow into the gap pipeline. The proposal would touch the
        // weakest component's own source file.
        $screening = $this->proposalGate->admit(
            ['diff' => ['changed_paths' => [$sourcePath]]],
            $this->gateInput($input),
        );
        $gateStatus = (string) ($screening['status'] ?? '');

        if ($gateStatus === RsiSelfImprovementProposalGate::STATUS_SKIPPED) {
            // RSI mode off => proposal gate inert => no routed self gap (honest).
            return $this->emit(self::STATUS_SKIPPED, $areaId, $focus, $componentId, $claim, $screening, 'ATLAS_RSI_MODE off; self gap not routed');
        }

        if ($gateStatus === RsiSelfImprovementProposalGate::STATUS_BLOCKED_BY_INVARIANT) {
            // Guard rejected the proposal => the claim is NOT emitted into the pipeline.
            return $this->emit(self::STATUS_BLOCKED_BY_INVARIANT, $areaId, $focus, $componentId, null, $screening, 'invariant guard rejected the self-improvement proposal');
        }

        // Guard passed => proposal-only, routed to the human gate. The claim is
        // safe to flow into the same Pilar 2 gap pipeline as a product gap.
        return $this->emit(self::STATUS_SELECTED, $areaId, $focus, $componentId, $claim, $screening, 'weakest non-sacred component selected; self gap is guard-clean and proposal-only');
    }

    /**
     * Convenience: the Pilar 2 capability_claims list to feed straight into
     * AreaFocusDeepFindingEngineService's `capability_claims` seam. Empty unless
     * a guard-clean self target was selected (proposal-only, never auto-applied).
     *
     * @param  array<string,mixed>  $input
     * @return list<array<string,mixed>>
     */
    public function capabilityClaims(array $input = []): array
    {
        $record = $this->select($input);
        $claim = $record['capability_claim'] ?? null;

        return ($record['status'] === self::STATUS_SELECTED && is_array($claim)) ? [$claim] : [];
    }

    /**
     * Build the SELF capability_claim in the EXACT shape FoundrySemanticGapFinder
     * consumes (capability / documented_state / drift_kind / anchor_id /
     * runtime_state / source_doc* / outcome_contract), plus anchor_cycle_id so the
     * deep finding engine's verifier seam confirms the anchor as a REAL cycle.
     *
     * @return array<string,mixed>
     */
    private function buildSelfClaim(
        string $componentId,
        string $role,
        string $sourcePath,
        float $baseline,
        float $targetDelta,
        string $anchorId,
        string $anchorCycleId,
        string $areaId,
        string $focus,
    ): array {
        $measureCommand = self::MEASURE_COMMAND_PREFIX
            .' '.$componentId
            .' --area='.$areaId
            .' --focus='.$focus;

        return [
            'capability' => 'loop_component:'.$componentId,
            'documented_state' => 'available',
            'drift_kind' => self::SELF_DRIFT_KIND,
            'anchor_id' => $anchorId,
            'anchor_cycle_id' => $anchorCycleId,
            'runtime_state' => 'under_delivering_value_per_token',
            'source_doc' => $sourcePath,
            'source_doc_path' => $sourcePath,
            'source_doc_line' => 1,
            'missing_runtime_ref' => $sourcePath,
            'self_target' => true,
            'component_id' => $componentId,
            'component_role' => $role,
            'outcome_contract' => [
                'metric_id' => 'rsi_value_per_token_'.$componentId,
                'baseline' => $baseline,
                'target_delta' => $targetDelta,
                'measure_command' => $measureCommand,
                'metric_json_path' => 'metric',
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function gateInput(array $input): array
    {
        $gateInput = [];
        if (($input['rsi_mode_enabled'] ?? null) === true) {
            $gateInput['rsi_mode_enabled'] = true;
        }

        return $gateInput;
    }

    private function resolveTargetDelta(mixed $raw): float
    {
        if (is_numeric($raw) && (float) $raw > 0.0) {
            return (float) $raw;
        }

        return self::DEFAULT_TARGET_DELTA;
    }

    /**
     * @param  array<string,mixed>|null  $claim
     * @param  array<string,mixed>|null  $screening
     * @return array<string,mixed>
     */
    private function emit(
        string $status,
        string $areaId,
        string $focus,
        ?string $componentId,
        ?array $claim,
        ?array $screening,
        string $detail,
    ): array {
        $payload = [
            'schema_version' => self::SELF_GAP_SCHEMA,
            'status' => $status,
            'area_id' => $areaId,
            'focus' => $focus,
            'target_component_id' => $componentId,
            'detail' => $detail,
            'capability_claim' => $claim,
            'guard_screening' => $screening,
            // The selector adds NO authority: it never applies, merges or canonizes.
            'proposal_only' => true,
            'auto_applied' => false,
            'auto_canonized' => false,
            'provider_invoked' => false,
        ];
        $payload['self_gap_hash'] = 'sha256:'.MissionCanonicalHash::sha256($this->identity($payload));

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function identity(array $payload): array
    {
        // Strip volatile guard hashes so the self_gap_hash is deterministic for
        // the same (weakest, contract) inputs.
        if (is_array($payload['guard_screening'] ?? null)) {
            unset($payload['guard_screening']['gate_hash'], $payload['guard_screening']['screening']['screening_hash']);
        }

        return $payload;
    }
}
