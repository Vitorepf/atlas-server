<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Repair\Contracts;

use App\Services\Ai\Programming\AtlasDev\Support\AtlasDevStringListNormalizer;
use InvalidArgumentException;

/**
 * Result of one repair attempt as reported by the upstream
 * {@see RepairAttemptEvaluator}. Provider/Gate own the actual execution; the
 * repair loop only sees this honest projection of what happened.
 *
 * `status` is the operational outcome of the attempt:
 *   - `passed`            verification gates green after the attempt;
 *   - `failed`            one or more gates failed but scope was respected;
 *   - `scope_violation`   the attempt touched files outside the contract;
 *   - `blocked`           preflight/permission/ambiguity stopped the attempt
 *                          before any meaningful execution.
 */
final class RepairAttemptOutcome
{
    public const STATUS_PASSED = 'passed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_SCOPE_VIOLATION = 'scope_violation';
    public const STATUS_BLOCKED = 'blocked';

    public const ALLOWED_STATUSES = [
        self::STATUS_PASSED,
        self::STATUS_FAILED,
        self::STATUS_SCOPE_VIOLATION,
        self::STATUS_BLOCKED,
    ];

    /**
     * @param  list<string>  $changedFiles
     */
    public function __construct(
        public readonly string $status,
        public readonly ?string $gate,
        public readonly ?string $command,
        public readonly ?int $exitCode,
        public readonly string $primaryErrorExcerpt,
        public readonly ?string $fullErrorLogPath,
        public readonly ?string $failingTest,
        public readonly ?string $diffHash,
        public readonly array $changedFiles,
        public readonly int $diffSizeLines,
    ) {
        if (! in_array($this->status, self::ALLOWED_STATUSES, true)) {
            throw new InvalidArgumentException(
                "RepairAttemptOutcome.status invalid: '{$this->status}'."
            );
        }
        if ($this->status !== self::STATUS_PASSED && ($this->gate === null || $this->gate === '')) {
            throw new InvalidArgumentException(
                'RepairAttemptOutcome: non-passing outcome must name the failing gate.'
            );
        }
        if ($this->status !== self::STATUS_PASSED && $this->primaryErrorExcerpt === '') {
            throw new InvalidArgumentException(
                'RepairAttemptOutcome: non-passing outcome requires primary_error_excerpt.'
            );
        }
        if ($this->diffSizeLines < 0) {
            throw new InvalidArgumentException('RepairAttemptOutcome.diff_size_lines must be non-negative.');
        }
        AtlasDevStringListNormalizer::requireNonEmptyStrings(
            $this->changedFiles,
            'RepairAttemptOutcome.changed_files',
        );
    }

    public static function passed(?string $diffHash, array $changedFiles, int $diffSizeLines): self
    {
        return new self(
            status: self::STATUS_PASSED,
            gate: null,
            command: null,
            exitCode: null,
            primaryErrorExcerpt: '',
            fullErrorLogPath: null,
            failingTest: null,
            diffHash: $diffHash,
            changedFiles: array_values($changedFiles),
            diffSizeLines: $diffSizeLines,
        );
    }

    public function isPassed(): bool
    {
        return $this->status === self::STATUS_PASSED;
    }
}
