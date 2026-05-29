<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

/**
 * AP-790 · compatibility contract for the 24h stewardship recovery shape.
 */
final class TwentyFourHStewardshipRecoveryUntilConsecutiveMergedCyclesAreNormalContract
{
    public const SCHEMA = Reliable24hStewardshipRecoveryContract::SCHEMA;

    public const DEFAULT_TARGET_CONSECUTIVE_MERGES = Reliable24hStewardshipRecoveryContract::DEFAULT_TARGET_CONSECUTIVE_MERGES;

    public const MERGE_ELIGIBILITY = Reliable24hStewardshipRecoveryContract::MERGE_ELIGIBILITY;

    private function __construct(
        private readonly Reliable24hStewardshipRecoveryContract $contract,
    ) {}

    public static function defaults(
        string $areaId = 'agentic_engineering_os',
        string $focus = 'dev_forge',
    ): self {
        return new self(Reliable24hStewardshipRecoveryContract::defaults($areaId, $focus));
    }

    /**
     * @param  array<string,mixed>  $input
     */
    public static function fromArray(array $input): self
    {
        return new self(Reliable24hStewardshipRecoveryContract::fromArray($input));
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return $this->contract->toArray();
    }
}
