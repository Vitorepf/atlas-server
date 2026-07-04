<?php

declare(strict_types=1);

namespace App\Services\Ai\EngineeringKernel\Spec;

/**
 * Engineering Kernel value: the raw operator/goal intent plus whatever disambiguation was already
 * elicited, against which the composed SpecDraft is checked for fidelity-OF-spec.
 *
 * Owns: carrying the raw goal text, the recognized verbs, and the operator's answers to prior
 * clarification questions, immutably.
 * Must never own: judging whether the draft matches it (SovereignSpecFloor's job).
 */
final readonly class IntentEnvelope
{
    /**
     * @param  string  $rawGoal                       the raw operator/goal text (pre-composition)
     * @param  list<string>  $recognizedVerbs          canonical verbs extracted from the goal
     * @param  array<string,string>  $elicitedAnswers  clarification question => operator answer
     */
    public function __construct(
        public string $rawGoal,
        public array $recognizedVerbs = [],
        public array $elicitedAnswers = [],
    ) {}

    /**
     * @param  array<string,mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            rawGoal: (string) ($data['raw_goal'] ?? ''),
            recognizedVerbs: array_values(array_map('strval', (array) ($data['recognized_verbs'] ?? []))),
            elicitedAnswers: (array) ($data['elicited_answers'] ?? []),
        );
    }

    public function wasElicited(string $question): bool
    {
        return array_key_exists($question, $this->elicitedAnswers)
            && trim((string) $this->elicitedAnswers[$question]) !== '';
    }
}
