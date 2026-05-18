<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Schemas\Components;

use App\Services\Ai\Programming\AtlasDev\Schemas\Support\AtlasDevSchemaArray;
use InvalidArgumentException;

/**
 * A research question the planner could NOT answer with the consulted
 * sources. Open questions are part of the receipt's honesty contract: a
 * research run that yields zero open questions on a non-trivial topic is
 * suspicious by construction.
 */
final class ResearchOpenQuestion
{
    public const SEVERITY_LOW = 'low';

    public const SEVERITY_MEDIUM = 'medium';

    public const SEVERITY_HIGH = 'high';

    public const ALLOWED_SEVERITIES = [
        self::SEVERITY_LOW,
        self::SEVERITY_MEDIUM,
        self::SEVERITY_HIGH,
    ];

    public function __construct(
        public readonly string $questionId,
        public readonly string $question,
        public readonly string $severity,
        public readonly ?string $suggestedNextStep = null,
    ) {
        if (trim($this->questionId) === '') {
            throw new InvalidArgumentException('ResearchOpenQuestion.question_id must not be empty.');
        }
        if (trim($this->question) === '') {
            throw new InvalidArgumentException('ResearchOpenQuestion.question must not be empty.');
        }
        if (! in_array($this->severity, self::ALLOWED_SEVERITIES, true)) {
            throw new InvalidArgumentException(
                'ResearchOpenQuestion.severity must be one of ['.implode(',', self::ALLOWED_SEVERITIES)."], got '{$this->severity}'."
            );
        }
    }

    /**
     * @return array<string,mixed>
     */
    public function toCanonicalArray(): array
    {
        return [
            'question' => $this->question,
            'question_id' => $this->questionId,
            'severity' => $this->severity,
            'suggested_next_step' => $this->suggestedNextStep,
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        return new self(
            questionId: AtlasDevSchemaArray::string($payload, 'question_id'),
            question: AtlasDevSchemaArray::string($payload, 'question'),
            severity: AtlasDevSchemaArray::string($payload, 'severity'),
            suggestedNextStep: AtlasDevSchemaArray::nullableString($payload, 'suggested_next_step'),
        );
    }
}
