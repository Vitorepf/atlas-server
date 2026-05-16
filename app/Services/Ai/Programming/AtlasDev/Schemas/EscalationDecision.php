<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Schemas;

use App\Services\Ai\Programming\AtlasDev\Schemas\Components\EscalationSignals;
use App\Services\Ai\Programming\AtlasDev\Schemas\Contracts\AtlasDevSchemaContract;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\AtlasDevSchemaArray;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\CanonicalHasher;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\CanonicalJson;
use InvalidArgumentException;

final class EscalationDecision implements AtlasDevSchemaContract
{
    public const SCHEMA_VERSION = 'atlas.dev.escalation_decision.v1';

    public const TARGET_FORGE = 'forge';
    public const TARGET_OBRA_CANDIDATE = 'obra_candidate';

    public const ALLOWED_TARGETS = [
        self::TARGET_FORGE,
        self::TARGET_OBRA_CANDIDATE,
    ];

    public const ALLOWED_RISK_LEVELS = ['R0', 'R1', 'R2', 'R3', 'R4', 'R5'];

    private const SCORE_MIN = 0;
    private const SCORE_MAX = 10;

    public const POST_HOC_FIELDS = [
        'was_correct',
        'post_hoc_reviewer',
        'post_hoc_reviewed_at',
    ];

    private const HASH_FIELD = 'decision_hash';

    /**
     * @param  list<string>  $reasons
     */
    public function __construct(
        public readonly string $runId,
        public readonly string $taskContractHash,
        public readonly string $triggeredAt,
        public readonly string $target,
        public readonly array $reasons,
        public readonly EscalationSignals $signals,
        public readonly int $score,
        public readonly string $riskLevel,
        public readonly bool $humanActionRequired,
        public readonly ?string $previewArtifactPath,
        public readonly ?bool $wasCorrect,
        public readonly ?string $postHocReviewer,
        public readonly ?string $postHocReviewedAt,
        public readonly string $decisionHash,
    ) {
        if (! in_array($this->target, self::ALLOWED_TARGETS, true)) {
            throw new InvalidArgumentException(
                "EscalationDecision.target must be one of [".implode(',', self::ALLOWED_TARGETS)."], got '{$this->target}'."
            );
        }
        if (! in_array($this->riskLevel, self::ALLOWED_RISK_LEVELS, true)) {
            throw new InvalidArgumentException(
                "EscalationDecision.risk_level must be one of [".implode(',', self::ALLOWED_RISK_LEVELS)."], got '{$this->riskLevel}'."
            );
        }
        if ($this->score < self::SCORE_MIN || $this->score > self::SCORE_MAX) {
            throw new InvalidArgumentException(
                "EscalationDecision.score must be in [".self::SCORE_MIN.','.self::SCORE_MAX."], got {$this->score}."
            );
        }
        if ($this->triggeredAt === '') {
            throw new InvalidArgumentException('EscalationDecision.triggered_at must not be empty.');
        }
        // Invariant 3: reasons non-empty
        if ($this->reasons === []) {
            throw new InvalidArgumentException('EscalationDecision invariant: reasons must be non-empty.');
        }
        foreach ($this->reasons as $i => $r) {
            if (! is_string($r)) {
                throw new InvalidArgumentException("EscalationDecision.reasons[{$i}] must be a string.");
            }
        }

        $riskIndex = self::riskIndex($this->riskLevel);

        // Invariant 1: target=forge requires score>=7 OR risk_level>=R4
        if ($this->target === self::TARGET_FORGE) {
            if ($this->score < 7 && $riskIndex < 4) {
                throw new InvalidArgumentException(
                    "EscalationDecision invariant: target=forge requires score>=7 or risk_level>=R4 (got score={$this->score}, risk={$this->riskLevel})."
                );
            }
        }
        // Invariant 2: target=obra_candidate requires score>=4
        if ($this->target === self::TARGET_OBRA_CANDIDATE && $this->score < 4) {
            throw new InvalidArgumentException(
                "EscalationDecision invariant: target=obra_candidate requires score>=4 (got {$this->score})."
            );
        }
        // Invariant 4: human_action_required=true when target=forge
        if ($this->target === self::TARGET_FORGE && ! $this->humanActionRequired) {
            throw new InvalidArgumentException(
                'EscalationDecision invariant: target=forge requires human_action_required=true.'
            );
        }
    }

    public static function riskIndex(string $risk): int
    {
        $i = array_search($risk, self::ALLOWED_RISK_LEVELS, true);
        if ($i === false) {
            return -1;
        }

        return (int) $i;
    }

    public function schemaVersion(): string
    {
        return self::SCHEMA_VERSION;
    }

    public function toCanonicalArray(): array
    {
        return CanonicalJson::canonicalize([
            'decision_hash' => $this->decisionHash,
            'human_action_required' => $this->humanActionRequired,
            'post_hoc_reviewed_at' => $this->postHocReviewedAt,
            'post_hoc_reviewer' => $this->postHocReviewer,
            'preview_artifact_path' => $this->previewArtifactPath,
            'provider_safe' => true,
            'reasons' => array_values($this->reasons),
            'risk_level' => $this->riskLevel,
            'run_id' => $this->runId,
            'schema_version' => self::SCHEMA_VERSION,
            'score' => $this->score,
            'signals' => $this->signals->toCanonicalArray(),
            'target' => $this->target,
            'task_contract_hash' => $this->taskContractHash,
            'triggered_at' => $this->triggeredAt,
            'was_correct' => $this->wasCorrect,
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
     * Excludes post-hoc fields (invariant 5) and the hash field itself.
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

    /**
     * @param  list<string>  $reasons
     */
    public static function issue(
        string $runId,
        string $taskContractHash,
        string $triggeredAt,
        string $target,
        array $reasons,
        EscalationSignals $signals,
        int $score,
        string $riskLevel,
        bool $humanActionRequired,
        ?string $previewArtifactPath = null,
    ): self {
        $skeleton = new self(
            runId: $runId,
            taskContractHash: $taskContractHash,
            triggeredAt: $triggeredAt,
            target: $target,
            reasons: $reasons,
            signals: $signals,
            score: $score,
            riskLevel: $riskLevel,
            humanActionRequired: $humanActionRequired,
            previewArtifactPath: $previewArtifactPath,
            wasCorrect: null,
            postHocReviewer: null,
            postHocReviewedAt: null,
            decisionHash: 'pending',
        );
        $hash = $skeleton->hash();

        return new self(
            runId: $runId,
            taskContractHash: $taskContractHash,
            triggeredAt: $triggeredAt,
            target: $target,
            reasons: $reasons,
            signals: $signals,
            score: $score,
            riskLevel: $riskLevel,
            humanActionRequired: $humanActionRequired,
            previewArtifactPath: $previewArtifactPath,
            wasCorrect: null,
            postHocReviewer: null,
            postHocReviewedAt: null,
            decisionHash: $hash,
        );
    }

    public function withPostHocReview(string $reviewer, bool $wasCorrect, string $reviewedAtIso): self
    {
        if ($reviewer === '' || $reviewedAtIso === '') {
            throw new InvalidArgumentException('EscalationDecision.withPostHocReview: reviewer and reviewed_at must not be empty.');
        }

        return new self(
            runId: $this->runId,
            taskContractHash: $this->taskContractHash,
            triggeredAt: $this->triggeredAt,
            target: $this->target,
            reasons: $this->reasons,
            signals: $this->signals,
            score: $this->score,
            riskLevel: $this->riskLevel,
            humanActionRequired: $this->humanActionRequired,
            previewArtifactPath: $this->previewArtifactPath,
            wasCorrect: $wasCorrect,
            postHocReviewer: $reviewer,
            postHocReviewedAt: $reviewedAtIso,
            decisionHash: $this->decisionHash,
        );
    }

    public static function fromArray(array $payload): self
    {
        return new self(
            runId: AtlasDevSchemaArray::string($payload, 'run_id'),
            taskContractHash: AtlasDevSchemaArray::string($payload, 'task_contract_hash'),
            triggeredAt: AtlasDevSchemaArray::string($payload, 'triggered_at'),
            target: AtlasDevSchemaArray::string($payload, 'target'),
            reasons: AtlasDevSchemaArray::stringList($payload, 'reasons'),
            signals: EscalationSignals::fromArray((array) $payload['signals']),
            score: AtlasDevSchemaArray::int($payload, 'score'),
            riskLevel: AtlasDevSchemaArray::string($payload, 'risk_level'),
            humanActionRequired: AtlasDevSchemaArray::bool($payload, 'human_action_required'),
            previewArtifactPath: AtlasDevSchemaArray::nullableString($payload, 'preview_artifact_path'),
            wasCorrect: AtlasDevSchemaArray::nullableBool($payload, 'was_correct'),
            postHocReviewer: AtlasDevSchemaArray::nullableString($payload, 'post_hoc_reviewer'),
            postHocReviewedAt: AtlasDevSchemaArray::nullableString($payload, 'post_hoc_reviewed_at'),
            decisionHash: AtlasDevSchemaArray::string($payload, 'decision_hash'),
        );
    }
}
