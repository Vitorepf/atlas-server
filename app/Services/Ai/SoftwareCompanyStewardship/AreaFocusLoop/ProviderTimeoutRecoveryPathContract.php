<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

/**
 * Minimal data contract for the provider_timeout chaos recovery path in
 * {@see LoopChaosCertificationService}. Consumed by {@see LoopChaosCertificationService::certify()}
 * when evaluating the provider_timeout fault and surfaced on every certification report.
 *
 * Proves the AP-790 transient-quarantine invariant: after provider_timeout the
 * loop must quarantine transiently and retry on the next cycle instead of
 * re-selecting the same stuck finding.
 */
final class ProviderTimeoutRecoveryPathContract
{
    public const SCHEMA = 'atlas.software_company_stewardship.provider_timeout_recovery_path.v1';

    public const GAP_MATRIX_CANONICAL = 'docs/engineering-knowledge-base/atlas-agentic-engineering-os-runtime-gap-matrix.md';

    public const SCENARIO_ID = 'provider_timeout_recovery_path';

    public const FAULT_ID = 'provider_timeout';

    public const MANDATED_CHAOS_OUTCOME = LoopChaosCertificationService::OUTCOME_BOUNDED_RETRY;

    public const TRANSIENT_BLOCKER = 'owner_runtime_provider_timeout';

    public const AP790_QUARANTINE_SCHEMA = AreaFocusCandidateQuarantineService::SCHEMA;

    private function __construct(
        public readonly string $areaId,
        public readonly string $focus,
        public readonly string $findingKey,
        public readonly int $cycleIndex,
        public readonly ?string $observedOutcome,
        public readonly ?string $blocker,
        public readonly ?bool $sameFindingReselected,
    ) {}

    public static function defaults(
        string $areaId = 'agentic_engineering_os',
        string $focus = 'dev_forge',
    ): self {
        return new self(
            areaId: $areaId,
            focus: $focus,
            findingKey: '',
            cycleIndex: 0,
            observedOutcome: null,
            blocker: null,
            sameFindingReselected: null,
        );
    }

    /**
     * @param  array<string,mixed>  $input
     */
    public static function fromArray(array $input): self
    {
        $observedOutcome = $input['observed_outcome'] ?? null;
        $normalizedOutcome = is_string($observedOutcome) && $observedOutcome !== ''
            ? trim($observedOutcome)
            : null;

        $blocker = $input['blocker'] ?? null;
        $normalizedBlocker = is_string($blocker) && $blocker !== ''
            ? trim($blocker)
            : null;

        $sameFindingReselected = $input['same_finding_reselected'] ?? null;
        $normalizedSameFindingReselected = is_bool($sameFindingReselected)
            ? $sameFindingReselected
            : null;

        return new self(
            areaId: trim((string) ($input['area_id'] ?? 'agentic_engineering_os')),
            focus: trim((string) ($input['focus'] ?? 'dev_forge')),
            findingKey: trim((string) ($input['finding_key'] ?? '')),
            cycleIndex: max(0, (int) ($input['cycle_index'] ?? 0)),
            observedOutcome: $normalizedOutcome,
            blocker: $normalizedBlocker,
            sameFindingReselected: $normalizedSameFindingReselected,
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        $observedOutcomeMatchesMandate = $this->observedOutcome === self::MANDATED_CHAOS_OUTCOME;
        $triggersTransientQuarantine = $this->blocker !== null
            && in_array($this->blocker, AreaFocusCandidateQuarantineService::TRANSIENT_BLOCKERS, true);
        $sameStuckSelection = $this->sameFindingReselected === true;
        $retriesNextCycleNotSameSelection = $this->sameFindingReselected === false;
        $recoveryPathValid = $observedOutcomeMatchesMandate
            && $triggersTransientQuarantine
            && $retriesNextCycleNotSameSelection;

        return [
            'schema_version' => self::SCHEMA,
            'scenario_id' => self::SCENARIO_ID,
            'fault_id' => self::FAULT_ID,
            'mandated_chaos_outcome' => self::MANDATED_CHAOS_OUTCOME,
            'transient_blocker' => self::TRANSIENT_BLOCKER,
            'ap790_quarantine_schema' => self::AP790_QUARANTINE_SCHEMA,
            'gap_matrix_canonical' => self::GAP_MATRIX_CANONICAL,
            'area_id' => $this->areaId,
            'focus' => $this->focus,
            'inputs' => [
                'finding_key' => $this->findingKey,
                'cycle_index' => $this->cycleIndex,
                'observed_outcome' => $this->observedOutcome,
                'blocker' => $this->blocker,
                'same_finding_reselected' => $this->sameFindingReselected,
            ],
            'outputs' => [
                'observed_outcome_matches_mandate' => $observedOutcomeMatchesMandate,
                'triggers_transient_quarantine' => $triggersTransientQuarantine,
                'same_stuck_selection' => $sameStuckSelection,
                'retries_next_cycle_not_same_selection' => $retriesNextCycleNotSameSelection,
                'recovery_path_valid' => $recoveryPathValid,
            ],
        ];
    }
}
