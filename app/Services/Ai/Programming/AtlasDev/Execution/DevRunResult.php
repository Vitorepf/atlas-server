<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Execution;

use App\Services\Ai\EngineeringKernel\EngineeringOutcome;

final readonly class DevRunResult
{
    private function __construct(
        public string $status,
        public string $runHash,
        public string $planHash,
        public ?string $reason,
        public array $details,
    ) {}

    /** @param array<string,mixed> $details */
    public static function blocked(ConfirmedDevRun $run, string $reason, string $planHash = '', array $details = []): self
    {
        return new self('blocked', $run->runHash, $planHash, $reason, $details);
    }

    /** @param array<string,mixed> $details */
    public static function handedOff(ConfirmedDevRun $run, string $planHash, array $details = []): self
    {
        return new self('forge_handoff_required', $run->runHash, $planHash, null, $details);
    }

    public static function fromKernelOutcome(ConfirmedDevRun $run, string $planHash, EngineeringOutcome $outcome): self
    {
        $status = in_array($outcome->status, ['completed_read_only', 'released'], true) ? $outcome->status : 'blocked';

        return new self($status, $run->runHash, $planHash, $status === 'blocked' ? ($outcome->uncertainties[0] ?? 'kernel_outcome_blocked') : null, [
            'kernel_outcome' => $outcome->toArray(),
        ]);
    }
}
