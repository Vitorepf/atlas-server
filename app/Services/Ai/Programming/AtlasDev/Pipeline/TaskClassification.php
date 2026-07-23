<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Pipeline;


/**
 * Immutable result of {@see TaskClassifier::classify()}.
 *
 * Carries the task_kind decision plus the classifier-adjusted intent clarity
 * and the matched rule trace so downstream services (and tests) can audit how
 * the kind was chosen.
 */
final class TaskClassification
{
    public const KIND_QUESTION = 'question';

    public const KIND_PATCH = 'patch';

    public const KIND_REPAIR = 'repair';

    public const KIND_REVIEW = 'review';

    public const KIND_FRONTEND = 'frontend';

    public const KIND_RISKY = 'risky';

    public const KINDS = [
        self::KIND_QUESTION,
        self::KIND_PATCH,
        self::KIND_REPAIR,
        self::KIND_REVIEW,
        self::KIND_FRONTEND,
        self::KIND_RISKY,
    ];

    /**
     * @param  list<string>  $matchedRules
     */
    public function __construct(
        public readonly string $taskKind,
        public readonly string $intentClarityLevel,
        public readonly array $matchedRules,
        public readonly bool $writeImplied,
    ) {}

    public function withClarity(string $clarity): self
    {
        return new self(
            taskKind: $this->taskKind,
            intentClarityLevel: $clarity,
            matchedRules: $this->matchedRules,
            writeImplied: $this->writeImplied,
        );
    }
}
