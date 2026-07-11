<?php

declare(strict_types=1);

namespace App\Services\Ai\EngineeringKernel;

use InvalidArgumentException;

final readonly class QualityCourtVerdict
{
    /** @param array<string,RoleDisposition> $dispositions */
    public function __construct(
        public string $caseHash,
        public string $candidateHash,
        public array $dispositions,
        public bool $authorityEligible,
    ) {
        if (array_keys($dispositions) !== EngineeringRoleRoster::OFFICIAL_ROLES
            || array_any($dispositions, static fn (mixed $item): bool => ! $item instanceof RoleDisposition)
            || $authorityEligible) {
            throw new InvalidArgumentException('quality_court_verdict_invalid');
        }
    }
}
