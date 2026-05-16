<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Gate;

use InvalidArgumentException;

/**
 * Raw outcome of executing one validation_commands entry.
 */
final class VerificationCommandResult
{
    public function __construct(
        public readonly string $command,
        public readonly int $exitCode,
        public readonly string $stdout,
        public readonly string $stderr,
        public readonly int $durationMs,
        public readonly bool $timedOut = false,
        public readonly ?string $rejectedReason = null,
    ) {
        if ($command === '') {
            throw new InvalidArgumentException('VerificationCommandResult.command must not be empty.');
        }
        if ($durationMs < 0) {
            throw new InvalidArgumentException('VerificationCommandResult.duration_ms must be non-negative.');
        }
    }

    public function ok(): bool
    {
        return $this->exitCode === 0 && ! $this->timedOut && $this->rejectedReason === null;
    }

    public function combinedOutput(): string
    {
        if ($this->stderr === '') {
            return $this->stdout;
        }
        if ($this->stdout === '') {
            return $this->stderr;
        }

        return $this->stdout."\n".$this->stderr;
    }
}
