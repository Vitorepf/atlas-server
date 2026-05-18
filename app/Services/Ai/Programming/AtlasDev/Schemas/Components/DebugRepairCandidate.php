<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Schemas\Components;

use App\Services\Ai\Programming\AtlasDev\Schemas\Contracts\AtlasDevSchemaContract;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\AtlasDevSchemaArray;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\CanonicalHasher;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\CanonicalJson;
use InvalidArgumentException;

/**
 * Concrete repair candidate emitted by Atlas Dev Debug Intelligence.
 *
 * Each candidate carries a `strategy` (what to do), a `target_files` list (where),
 * an `expected_outcome` (what success looks like), the validation commands the
 * agent will run after applying, and a confidence score.
 *
 * The strategy intentionally maps to operator-facing verbs — `apply_patch`,
 * `add_test`, `revert`, `escalate`, `await_input` — not to provider-specific
 * verbs. This keeps the candidate independent of which provider executes it.
 */
final class DebugRepairCandidate implements AtlasDevSchemaContract
{
    public const SCHEMA_VERSION = 'atlas.dev.components.debug_repair_candidate.v1';

    public const STRATEGY_APPLY_PATCH = 'apply_patch';

    public const STRATEGY_ADD_TEST = 'add_test';

    public const STRATEGY_FIX_AND_TEST = 'fix_and_test';

    public const STRATEGY_REVERT = 'revert';

    public const STRATEGY_RESCOPE = 'rescope';

    public const STRATEGY_ESCALATE = 'escalate';

    public const STRATEGY_AWAIT_INPUT = 'await_input';

    public const STRATEGY_NO_PATCH_NEEDED = 'no_patch_needed';

    public const ALLOWED_STRATEGIES = [
        self::STRATEGY_APPLY_PATCH,
        self::STRATEGY_ADD_TEST,
        self::STRATEGY_FIX_AND_TEST,
        self::STRATEGY_REVERT,
        self::STRATEGY_RESCOPE,
        self::STRATEGY_ESCALATE,
        self::STRATEGY_AWAIT_INPUT,
        self::STRATEGY_NO_PATCH_NEEDED,
    ];

    /**
     * @param  list<string>  $targetFiles
     * @param  list<string>  $validationCommands
     */
    public function __construct(
        public readonly string $strategy,
        public readonly string $summary,
        public readonly array $targetFiles,
        public readonly string $expectedOutcome,
        public readonly array $validationCommands,
        public readonly float $confidence,
        public readonly ?string $linkedCauseCategory = null,
    ) {
        if (! in_array($this->strategy, self::ALLOWED_STRATEGIES, true)) {
            throw new InvalidArgumentException(
                'DebugRepairCandidate.strategy must be one of ['.implode(',', self::ALLOWED_STRATEGIES)."], got '{$this->strategy}'."
            );
        }
        if (trim($this->summary) === '') {
            throw new InvalidArgumentException('DebugRepairCandidate.summary must not be empty.');
        }
        if (trim($this->expectedOutcome) === '') {
            throw new InvalidArgumentException('DebugRepairCandidate.expected_outcome must not be empty.');
        }
        if ($this->confidence < 0.0 || $this->confidence > 1.0) {
            throw new InvalidArgumentException(
                "DebugRepairCandidate.confidence must be in [0.0, 1.0], got {$this->confidence}."
            );
        }
        foreach ($this->targetFiles as $i => $f) {
            if (! is_string($f) || trim($f) === '') {
                throw new InvalidArgumentException("DebugRepairCandidate.target_files[{$i}] must be a non-empty string.");
            }
        }
        foreach ($this->validationCommands as $i => $c) {
            if (! is_string($c) || trim($c) === '') {
                throw new InvalidArgumentException("DebugRepairCandidate.validation_commands[{$i}] must be a non-empty string.");
            }
        }
        // Invariant: strategies that mutate the workspace must declare at
        // least one validation command so the caller cannot "succeed" silently.
        if (in_array($this->strategy, [self::STRATEGY_APPLY_PATCH, self::STRATEGY_ADD_TEST, self::STRATEGY_FIX_AND_TEST, self::STRATEGY_REVERT], true)
            && $this->validationCommands === []
        ) {
            throw new InvalidArgumentException(
                "DebugRepairCandidate invariant: strategy='{$this->strategy}' requires at least one validation_command."
            );
        }
    }

    public function schemaVersion(): string
    {
        return self::SCHEMA_VERSION;
    }

    public function toCanonicalArray(): array
    {
        return CanonicalJson::canonicalize([
            'confidence' => $this->confidence,
            'expected_outcome' => $this->expectedOutcome,
            'linked_cause_category' => $this->linkedCauseCategory,
            'strategy' => $this->strategy,
            'summary' => $this->summary,
            'target_files' => array_values($this->targetFiles),
            'validation_commands' => array_values($this->validationCommands),
        ]);
    }

    public function toProviderSafeArray(): array
    {
        return $this->toCanonicalArray();
    }

    public function toJson(): string
    {
        return CanonicalJson::encode($this->toCanonicalArray());
    }

    public function hash(): string
    {
        return CanonicalHasher::hash($this->toCanonicalArray());
    }

    public function isProviderSafe(): bool
    {
        return true;
    }

    public static function fromArray(array $payload): self
    {
        if (! array_key_exists('confidence', $payload) || ! is_numeric($payload['confidence'])) {
            throw new InvalidArgumentException("Field 'confidence' must be numeric.");
        }

        return new self(
            strategy: AtlasDevSchemaArray::string($payload, 'strategy'),
            summary: AtlasDevSchemaArray::string($payload, 'summary'),
            targetFiles: AtlasDevSchemaArray::stringList($payload, 'target_files'),
            expectedOutcome: AtlasDevSchemaArray::string($payload, 'expected_outcome'),
            validationCommands: AtlasDevSchemaArray::stringList($payload, 'validation_commands'),
            confidence: (float) $payload['confidence'],
            linkedCauseCategory: AtlasDevSchemaArray::nullableString($payload, 'linked_cause_category'),
        );
    }
}
