<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Gate;

final class PatchApplyResult
{
    public const STATUS_APPLIED = 'applied';

    public const STATUS_SKIPPED = 'skipped';

    public const STATUS_FAILED = 'failed';

    public function __construct(
        public readonly string $status,
        public readonly int $exitCode,
        public readonly int $durationMs,
        public readonly string $stdout,
        public readonly string $stderr,
        public readonly ?string $reason = null,
    ) {}

    public function ok(): bool
    {
        return in_array($this->status, [self::STATUS_APPLIED, self::STATUS_SKIPPED], true);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'duration_ms' => $this->durationMs,
            'exit_code' => $this->exitCode,
            'ok' => $this->ok(),
            'reason' => $this->reason,
            'stderr_excerpt' => substr($this->stderr, 0, 2000),
            'stderr_hash' => hash('sha256', $this->stderr),
            'status' => $this->status,
            'stdout_excerpt' => substr($this->stdout, 0, 2000),
            'stdout_hash' => hash('sha256', $this->stdout),
        ];
    }
}
