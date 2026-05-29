<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

/**
 * Minimal data contract for the provider-spent-without-merge waste signal in
 * {@see LoopPostCycleAuditorService}. Step 1 of 3: shape only — no post-cycle
 * auditor wiring in this class.
 *
 * Classifies each blocked cycle as provider_wasted (provider ran, no merge) or
 * no_spend_block (blocked before any provider call).
 */
final class ProviderSpentWithoutMergeContract
{
    public const SCHEMA = 'atlas.software_company_stewardship.provider_spent_without_merge.v1';

    public const IMPLEMENTATION_REALITY_CANONICAL = 'docs/engineering-knowledge-base/atlas-agentic-engineering-os-implementation-reality.md';

    public const CONTRACT_ID = 'provider_spent_without_merge_waste_signal';

    public const SIGNAL_ID = 'provider_spent_without_merge';

    public const POST_CYCLE_STATUS_VALID_BLOCK = LoopPostCycleAuditorService::STATUS_VALID_BLOCK;

    public const CLASSIFICATION_PROVIDER_WASTED = 'provider_wasted';

    public const CLASSIFICATION_NO_SPEND_BLOCK = 'no_spend_block';

    private function __construct(
        public readonly string $areaId,
        public readonly string $focus,
        public readonly string $runId,
        public readonly int $cycleIndex,
        public readonly bool $providerInvoked,
        public readonly bool $mergePerformed,
        public readonly string $blockedCycleSpendClassification,
    ) {}

    public static function defaults(
        string $areaId = 'agentic_engineering_os',
        string $focus = 'dev_forge',
    ): self {
        return new self(
            areaId: $areaId,
            focus: $focus,
            runId: '',
            cycleIndex: 0,
            providerInvoked: false,
            mergePerformed: false,
            blockedCycleSpendClassification: self::CLASSIFICATION_NO_SPEND_BLOCK,
        );
    }

    /**
     * @param  array<string,mixed>  $input
     */
    public static function fromArray(array $input): self
    {
        $providerInvoked = (bool) ($input['provider_invoked'] ?? false);
        $mergePerformed = (bool) ($input['merge_performed'] ?? false);

        return new self(
            areaId: trim((string) ($input['area_id'] ?? $input['area'] ?? 'agentic_engineering_os')),
            focus: trim((string) ($input['focus'] ?? 'dev_forge')),
            runId: trim((string) ($input['run_id'] ?? '')),
            cycleIndex: max(0, (int) ($input['cycle_index'] ?? 0)),
            providerInvoked: $providerInvoked,
            mergePerformed: $mergePerformed,
            blockedCycleSpendClassification: self::resolveClassification($providerInvoked, $mergePerformed),
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        $countsAsProviderWaste = $this->blockedCycleSpendClassification === self::CLASSIFICATION_PROVIDER_WASTED;
        $countsAsHonestNoSpendBlock = $this->blockedCycleSpendClassification === self::CLASSIFICATION_NO_SPEND_BLOCK;

        return [
            'schema_version' => self::SCHEMA,
            'contract_id' => self::CONTRACT_ID,
            'signal_id' => self::SIGNAL_ID,
            'implementation_reality_canonical' => self::IMPLEMENTATION_REALITY_CANONICAL,
            'post_cycle_status_valid_block' => self::POST_CYCLE_STATUS_VALID_BLOCK,
            'area_id' => $this->areaId,
            'focus' => $this->focus,
            'inputs' => [
                'run_id' => $this->runId,
                'cycle_index' => $this->cycleIndex,
                'provider_invoked' => $this->providerInvoked,
                'merge_performed' => $this->mergePerformed,
            ],
            'outputs' => [
                'blocked_cycle_spend_classification' => $this->blockedCycleSpendClassification,
                'counts_as_provider_waste' => $countsAsProviderWaste,
                'counts_as_honest_no_spend_block' => $countsAsHonestNoSpendBlock,
                'surfaces_wasted_provider_spend' => $countsAsProviderWaste,
            ],
        ];
    }

    private static function resolveClassification(bool $providerInvoked, bool $mergePerformed): string
    {
        if (! $providerInvoked || $mergePerformed) {
            return self::CLASSIFICATION_NO_SPEND_BLOCK;
        }

        return self::CLASSIFICATION_PROVIDER_WASTED;
    }
}
