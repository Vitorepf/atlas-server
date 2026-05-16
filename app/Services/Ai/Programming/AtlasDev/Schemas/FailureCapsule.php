<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Schemas;

use App\Services\Ai\Programming\AtlasDev\Schemas\Contracts\AtlasDevSchemaContract;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\AtlasDevSchemaArray;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\CanonicalHasher;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\CanonicalJson;
use InvalidArgumentException;

final class FailureCapsule implements AtlasDevSchemaContract
{
    public const SCHEMA_VERSION = 'atlas.dev.failure_capsule.v1';

    public const DECISION_RETRY = 'retry';
    public const DECISION_STOP = 'stop';
    public const DECISION_ESCALATE = 'escalate';

    public const ALLOWED_DECISIONS = [
        self::DECISION_RETRY,
        self::DECISION_STOP,
        self::DECISION_ESCALATE,
    ];

    /**
     * Fields that may be filled by a post-hoc reviewer after the capsule was
     * persisted. They are excluded from capsule_hash (invariant 6) so a review
     * never invalidates the original capsule identity.
     */
    public const POST_HOC_FIELDS = [
        'should_have_escalated',
        'escalation_signal_delta',
        'post_hoc_reviewer',
        'post_hoc_reviewed_at',
    ];

    private const HASH_FIELD = 'capsule_hash';

    /**
     * @param  list<string>  $changedFiles
     * @param  list<string>  $escalationSignalDelta
     */
    public function __construct(
        public readonly string $runId,
        public readonly string $taskContractHash,
        public readonly int $attemptIndex,
        public readonly string $gate,
        public readonly ?string $command,
        public readonly ?int $exitCode,
        public readonly string $primaryErrorExcerpt,
        public readonly ?string $fullErrorLogPath,
        public readonly ?string $failingTest,
        public readonly ?string $diffHash,
        public readonly array $changedFiles,
        public readonly string $failureSignature,
        public readonly string $decision,
        public readonly ?bool $shouldHaveEscalated,
        public readonly array $escalationSignalDelta,
        public readonly ?string $postHocReviewer,
        public readonly ?string $postHocReviewedAt,
        public readonly string $capsuleHash,
    ) {
        if ($this->attemptIndex < 0) {
            throw new InvalidArgumentException('FailureCapsule.attempt_index must be non-negative.');
        }
        if ($this->gate === '') {
            throw new InvalidArgumentException('FailureCapsule.gate must not be empty.');
        }
        if (! in_array($this->decision, self::ALLOWED_DECISIONS, true)) {
            throw new InvalidArgumentException(
                "FailureCapsule.decision must be one of [".implode(',', self::ALLOWED_DECISIONS)."], got '{$this->decision}'."
            );
        }
        if ($this->decision === self::DECISION_ESCALATE && $this->escalationSignalDelta === []) {
            throw new InvalidArgumentException(
                'FailureCapsule invariant: decision=escalate requires non-empty escalation_signal_delta.'
            );
        }
        // Invariant 4: primary_error_excerpt non-empty when exit_code != 0 or failing_test set
        if ($this->primaryErrorExcerpt === '' && (($this->exitCode !== null && $this->exitCode !== 0) || $this->failingTest !== null)) {
            throw new InvalidArgumentException(
                'FailureCapsule invariant: primary_error_excerpt must not be empty when exit_code!=0 or failing_test is set.'
            );
        }
        if (strlen($this->primaryErrorExcerpt) > 4096) {
            throw new InvalidArgumentException(
                'FailureCapsule invariant: primary_error_excerpt must fit in 4kb.'
            );
        }
        // Invariant 1: failure_signature is deterministic
        $expected = self::signatureOf($this->gate, $this->primaryErrorExcerpt);
        if ($this->failureSignature !== $expected) {
            throw new InvalidArgumentException(
                "FailureCapsule invariant: failure_signature must equal sha256(gate::normalize(primary_error_excerpt)). Expected '{$expected}'."
            );
        }
    }

    /**
     * Deterministic hash over a normalized error: collapses whitespace runs,
     * trims, lowercases.
     */
    public static function signatureOf(string $gate, string $primaryErrorExcerpt): string
    {
        $normalized = strtolower(trim((string) preg_replace('/\s+/', ' ', $primaryErrorExcerpt)));

        return 'sha256:'.hash('sha256', $gate.'::'.$normalized);
    }

    public function schemaVersion(): string
    {
        return self::SCHEMA_VERSION;
    }

    public function toCanonicalArray(): array
    {
        return CanonicalJson::canonicalize([
            'attempt_index' => $this->attemptIndex,
            'capsule_hash' => $this->capsuleHash,
            'changed_files' => array_values($this->changedFiles),
            'command' => $this->command,
            'decision' => $this->decision,
            'diff_hash' => $this->diffHash,
            'escalation_signal_delta' => array_values($this->escalationSignalDelta),
            'exit_code' => $this->exitCode,
            'failing_test' => $this->failingTest,
            'failure_signature' => $this->failureSignature,
            'full_error_log_path' => $this->fullErrorLogPath,
            'gate' => $this->gate,
            'post_hoc_reviewed_at' => $this->postHocReviewedAt,
            'post_hoc_reviewer' => $this->postHocReviewer,
            'primary_error_excerpt' => $this->primaryErrorExcerpt,
            'provider_safe' => true,
            'run_id' => $this->runId,
            'schema_version' => self::SCHEMA_VERSION,
            'should_have_escalated' => $this->shouldHaveEscalated,
            'task_contract_hash' => $this->taskContractHash,
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

    /**
     * Hash excludes the post-hoc fields (invariant 6) and the hash field itself.
     */
    public function hash(): string
    {
        $payload = $this->toCanonicalArray();
        unset($payload[self::HASH_FIELD]);
        foreach (self::POST_HOC_FIELDS as $f) {
            unset($payload[$f]);
        }

        return CanonicalHasher::hash($payload);
    }

    public function isProviderSafe(): bool
    {
        return true;
    }

    public function withPostHocReview(string $reviewer, bool $shouldHaveEscalated, array $delta, string $reviewedAtIso): self
    {
        if ($reviewer === '') {
            throw new InvalidArgumentException('FailureCapsule.withPostHocReview: reviewer must not be empty.');
        }
        if ($reviewedAtIso === '') {
            throw new InvalidArgumentException('FailureCapsule.withPostHocReview: reviewed_at must not be empty.');
        }
        $deltaList = [];
        foreach ($delta as $i => $item) {
            if (! is_string($item)) {
                throw new InvalidArgumentException("FailureCapsule.withPostHocReview: escalation_signal_delta[{$i}] must be a string.");
            }
            $deltaList[] = $item;
        }

        return new self(
            runId: $this->runId,
            taskContractHash: $this->taskContractHash,
            attemptIndex: $this->attemptIndex,
            gate: $this->gate,
            command: $this->command,
            exitCode: $this->exitCode,
            primaryErrorExcerpt: $this->primaryErrorExcerpt,
            fullErrorLogPath: $this->fullErrorLogPath,
            failingTest: $this->failingTest,
            diffHash: $this->diffHash,
            changedFiles: $this->changedFiles,
            failureSignature: $this->failureSignature,
            decision: $this->decision,
            shouldHaveEscalated: $shouldHaveEscalated,
            escalationSignalDelta: $deltaList,
            postHocReviewer: $reviewer,
            postHocReviewedAt: $reviewedAtIso,
            capsuleHash: $this->capsuleHash,
        );
    }

    /**
     * @param  list<string>  $changedFiles
     * @param  list<string>  $escalationSignalDelta
     */
    public static function issue(
        string $runId,
        string $taskContractHash,
        int $attemptIndex,
        string $gate,
        ?string $command,
        ?int $exitCode,
        string $primaryErrorExcerpt,
        ?string $fullErrorLogPath,
        ?string $failingTest,
        ?string $diffHash,
        array $changedFiles,
        string $decision,
        array $escalationSignalDelta = [],
    ): self {
        $signature = self::signatureOf($gate, $primaryErrorExcerpt);
        $skeleton = new self(
            runId: $runId,
            taskContractHash: $taskContractHash,
            attemptIndex: $attemptIndex,
            gate: $gate,
            command: $command,
            exitCode: $exitCode,
            primaryErrorExcerpt: $primaryErrorExcerpt,
            fullErrorLogPath: $fullErrorLogPath,
            failingTest: $failingTest,
            diffHash: $diffHash,
            changedFiles: $changedFiles,
            failureSignature: $signature,
            decision: $decision,
            shouldHaveEscalated: null,
            escalationSignalDelta: $escalationSignalDelta,
            postHocReviewer: null,
            postHocReviewedAt: null,
            capsuleHash: 'pending',
        );
        $hash = $skeleton->hash();

        return new self(
            runId: $runId,
            taskContractHash: $taskContractHash,
            attemptIndex: $attemptIndex,
            gate: $gate,
            command: $command,
            exitCode: $exitCode,
            primaryErrorExcerpt: $primaryErrorExcerpt,
            fullErrorLogPath: $fullErrorLogPath,
            failingTest: $failingTest,
            diffHash: $diffHash,
            changedFiles: $changedFiles,
            failureSignature: $signature,
            decision: $decision,
            shouldHaveEscalated: null,
            escalationSignalDelta: $escalationSignalDelta,
            postHocReviewer: null,
            postHocReviewedAt: null,
            capsuleHash: $hash,
        );
    }

    public static function fromArray(array $payload): self
    {
        return new self(
            runId: AtlasDevSchemaArray::string($payload, 'run_id'),
            taskContractHash: AtlasDevSchemaArray::string($payload, 'task_contract_hash'),
            attemptIndex: AtlasDevSchemaArray::int($payload, 'attempt_index'),
            gate: AtlasDevSchemaArray::string($payload, 'gate'),
            command: AtlasDevSchemaArray::nullableString($payload, 'command'),
            exitCode: AtlasDevSchemaArray::nullableInt($payload, 'exit_code'),
            primaryErrorExcerpt: AtlasDevSchemaArray::string($payload, 'primary_error_excerpt'),
            fullErrorLogPath: AtlasDevSchemaArray::nullableString($payload, 'full_error_log_path'),
            failingTest: AtlasDevSchemaArray::nullableString($payload, 'failing_test'),
            diffHash: AtlasDevSchemaArray::nullableString($payload, 'diff_hash'),
            changedFiles: AtlasDevSchemaArray::stringList($payload, 'changed_files'),
            failureSignature: AtlasDevSchemaArray::string($payload, 'failure_signature'),
            decision: AtlasDevSchemaArray::string($payload, 'decision'),
            shouldHaveEscalated: AtlasDevSchemaArray::nullableBool($payload, 'should_have_escalated'),
            escalationSignalDelta: AtlasDevSchemaArray::stringList($payload, 'escalation_signal_delta'),
            postHocReviewer: AtlasDevSchemaArray::nullableString($payload, 'post_hoc_reviewer'),
            postHocReviewedAt: AtlasDevSchemaArray::nullableString($payload, 'post_hoc_reviewed_at'),
            capsuleHash: AtlasDevSchemaArray::string($payload, 'capsule_hash'),
        );
    }
}
