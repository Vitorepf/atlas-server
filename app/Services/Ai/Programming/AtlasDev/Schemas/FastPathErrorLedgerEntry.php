<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Schemas;

use App\Services\Ai\Programming\AtlasDev\Schemas\Components\CompletionSummary;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\ObservedSignals;
use App\Services\Ai\Programming\AtlasDev\Schemas\Contracts\AtlasDevSchemaContract;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\AtlasDevSchemaArray;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\CanonicalHasher;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\CanonicalJson;
use InvalidArgumentException;

final class FastPathErrorLedgerEntry implements AtlasDevSchemaContract
{
    public const SCHEMA_VERSION = 'atlas.dev.fast_path_error_ledger.v1';

    public const FAILURE_MODE_WRONG_FILE = 'wrong_file';
    public const FAILURE_MODE_WRONG_SCOPE = 'wrong_scope';
    public const FAILURE_MODE_MISSED_TEST = 'missed_test';
    public const FAILURE_MODE_BAD_REPAIR = 'bad_repair';
    public const FAILURE_MODE_MISSED_ESCALATION = 'missed_escalation';
    public const FAILURE_MODE_FALSE_ESCALATION = 'false_escalation';
    public const FAILURE_MODE_PROMPT_PROJECTION_ERROR = 'prompt_projection_error';
    public const FAILURE_MODE_CONTEXT_ERROR = 'context_error';
    public const FAILURE_MODE_OTHER = 'other';

    public const ALLOWED_FAILURE_MODES = [
        self::FAILURE_MODE_WRONG_FILE,
        self::FAILURE_MODE_WRONG_SCOPE,
        self::FAILURE_MODE_MISSED_TEST,
        self::FAILURE_MODE_BAD_REPAIR,
        self::FAILURE_MODE_MISSED_ESCALATION,
        self::FAILURE_MODE_FALSE_ESCALATION,
        self::FAILURE_MODE_PROMPT_PROJECTION_ERROR,
        self::FAILURE_MODE_CONTEXT_ERROR,
        self::FAILURE_MODE_OTHER,
    ];

    public const ALLOWED_COMPLETION_STATES = [
        CompletionSummary::STATUS_PASSED,
        CompletionSummary::STATUS_NEEDS_REVIEW,
        CompletionSummary::STATUS_FAILED,
        CompletionSummary::STATUS_BLOCKED,
        CompletionSummary::STATUS_ESCALATE_FORGE,
        CompletionSummary::STATUS_NO_PATCH_NEEDED,
    ];

    private const HASH_FIELD = 'entry_hash';

    /**
     * @param  list<string>  $missingEscalationSignals
     * @param  list<string>  $correctionRecommendation
     */
    public function __construct(
        public readonly string $runId,
        public readonly string $failureSignature,
        public readonly string $completionState,
        public readonly string $actualFailureMode,
        public readonly ?bool $shouldHaveEscalated,
        public readonly array $missingEscalationSignals,
        public readonly ObservedSignals $observedSignals,
        public readonly array $correctionRecommendation,
        public readonly bool $reviewerSigned,
        public readonly ?string $reviewer,
        public readonly ?string $reviewedAt,
        public readonly string $entryHash,
    ) {
        if ($this->runId === '') {
            throw new InvalidArgumentException('FastPathErrorLedgerEntry.run_id must not be empty.');
        }
        if ($this->failureSignature === '') {
            throw new InvalidArgumentException('FastPathErrorLedgerEntry.failure_signature must not be empty.');
        }
        if (! in_array($this->completionState, self::ALLOWED_COMPLETION_STATES, true)) {
            throw new InvalidArgumentException(
                "FastPathErrorLedgerEntry.completion_state invalid: '{$this->completionState}'."
            );
        }
        if (! in_array($this->actualFailureMode, self::ALLOWED_FAILURE_MODES, true)) {
            throw new InvalidArgumentException(
                "FastPathErrorLedgerEntry.actual_failure_mode invalid: '{$this->actualFailureMode}'."
            );
        }
        foreach ($this->missingEscalationSignals as $i => $s) {
            if (! is_string($s)) {
                throw new InvalidArgumentException("missing_escalation_signals[{$i}] must be a string.");
            }
        }
        foreach ($this->correctionRecommendation as $i => $r) {
            if (! is_string($r)) {
                throw new InvalidArgumentException("correction_recommendation[{$i}] must be a string.");
            }
        }

        // Invariant 1: reviewer_signed=true requires reviewer + reviewed_at
        if ($this->reviewerSigned) {
            if ($this->reviewer === null || $this->reviewer === '' || $this->reviewedAt === null || $this->reviewedAt === '') {
                throw new InvalidArgumentException(
                    'FastPathErrorLedgerEntry invariant: reviewer_signed=true requires reviewer and reviewed_at.'
                );
            }
        }

        // Invariant 2: missed_escalation requires should_have_escalated=true AND missing_escalation_signals non-empty
        if ($this->actualFailureMode === self::FAILURE_MODE_MISSED_ESCALATION) {
            if ($this->shouldHaveEscalated !== true) {
                throw new InvalidArgumentException(
                    'FastPathErrorLedgerEntry invariant: actual_failure_mode=missed_escalation requires should_have_escalated=true.'
                );
            }
            if ($this->missingEscalationSignals === []) {
                throw new InvalidArgumentException(
                    'FastPathErrorLedgerEntry invariant: actual_failure_mode=missed_escalation requires non-empty missing_escalation_signals.'
                );
            }
        }
    }

    public function schemaVersion(): string
    {
        return self::SCHEMA_VERSION;
    }

    public function toCanonicalArray(): array
    {
        return CanonicalJson::canonicalize([
            'actual_failure_mode' => $this->actualFailureMode,
            'completion_state' => $this->completionState,
            'correction_recommendation' => array_values($this->correctionRecommendation),
            'entry_hash' => $this->entryHash,
            'failure_signature' => $this->failureSignature,
            'missing_escalation_signals' => array_values($this->missingEscalationSignals),
            'observed_signals' => $this->observedSignals->toCanonicalArray(),
            'provider_safe' => true,
            'reviewed_at' => $this->reviewedAt,
            'reviewer' => $this->reviewer,
            'reviewer_signed' => $this->reviewerSigned,
            'run_id' => $this->runId,
            'schema_version' => self::SCHEMA_VERSION,
            'should_have_escalated' => $this->shouldHaveEscalated,
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
        return CanonicalHasher::hashWithout($this->toCanonicalArray(), self::HASH_FIELD);
    }

    public function isProviderSafe(): bool
    {
        return true;
    }

    /**
     * @param  list<string>  $missingEscalationSignals
     * @param  list<string>  $correctionRecommendation
     */
    public static function issue(
        string $runId,
        string $failureSignature,
        string $completionState,
        string $actualFailureMode,
        ?bool $shouldHaveEscalated,
        array $missingEscalationSignals,
        ObservedSignals $observedSignals,
        array $correctionRecommendation,
        bool $reviewerSigned = false,
        ?string $reviewer = null,
        ?string $reviewedAt = null,
    ): self {
        $skeleton = new self(
            runId: $runId,
            failureSignature: $failureSignature,
            completionState: $completionState,
            actualFailureMode: $actualFailureMode,
            shouldHaveEscalated: $shouldHaveEscalated,
            missingEscalationSignals: $missingEscalationSignals,
            observedSignals: $observedSignals,
            correctionRecommendation: $correctionRecommendation,
            reviewerSigned: $reviewerSigned,
            reviewer: $reviewer,
            reviewedAt: $reviewedAt,
            entryHash: 'pending',
        );
        $hash = $skeleton->hash();

        return new self(
            runId: $runId,
            failureSignature: $failureSignature,
            completionState: $completionState,
            actualFailureMode: $actualFailureMode,
            shouldHaveEscalated: $shouldHaveEscalated,
            missingEscalationSignals: $missingEscalationSignals,
            observedSignals: $observedSignals,
            correctionRecommendation: $correctionRecommendation,
            reviewerSigned: $reviewerSigned,
            reviewer: $reviewer,
            reviewedAt: $reviewedAt,
            entryHash: $hash,
        );
    }

    public static function fromArray(array $payload): self
    {
        return new self(
            runId: AtlasDevSchemaArray::string($payload, 'run_id'),
            failureSignature: AtlasDevSchemaArray::string($payload, 'failure_signature'),
            completionState: AtlasDevSchemaArray::string($payload, 'completion_state'),
            actualFailureMode: AtlasDevSchemaArray::string($payload, 'actual_failure_mode'),
            shouldHaveEscalated: AtlasDevSchemaArray::nullableBool($payload, 'should_have_escalated'),
            missingEscalationSignals: AtlasDevSchemaArray::stringList($payload, 'missing_escalation_signals'),
            observedSignals: ObservedSignals::fromArray((array) $payload['observed_signals']),
            correctionRecommendation: AtlasDevSchemaArray::stringList($payload, 'correction_recommendation'),
            reviewerSigned: AtlasDevSchemaArray::bool($payload, 'reviewer_signed'),
            reviewer: AtlasDevSchemaArray::nullableString($payload, 'reviewer'),
            reviewedAt: AtlasDevSchemaArray::nullableString($payload, 'reviewed_at'),
            entryHash: AtlasDevSchemaArray::string($payload, 'entry_hash'),
        );
    }
}
