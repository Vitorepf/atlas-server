<?php

declare(strict_types=1);

namespace App\Services\Ai\EngineeringKernel\Spec;

use Carbon\CarbonImmutable;

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
     * @param  string  $rawGoal  the raw operator/goal text (pre-composition)
     * @param  list<string>  $recognizedVerbs  canonical verbs extracted from the goal
     * @param  array<string,string>  $elicitedAnswers  clarification question => operator answer
     */
    public function __construct(
        public string $rawGoal,
        public array $recognizedVerbs = [],
        public array $elicitedAnswers = [],
        public string $problem = '', public string $user = '', public string $value = '',
        public string $metric = '', public string $successWindow = '', public array $sources = [],
        public array $provenance = [], public array $constraints = [], public array $nonGoals = [],
        public array $hypotheses = [], public array $uncertainties = [], public array $falsifiers = [],
        public array $releasePolicy = [], public array $outcomePolicy = [], public string $worldObservedAt = '',
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
            problem: trim((string) ($data['problem'] ?? '')), user: trim((string) ($data['user'] ?? '')),
            value: trim((string) ($data['value'] ?? '')), metric: trim((string) ($data['metric'] ?? '')),
            successWindow: trim((string) ($data['success_window'] ?? '')), sources: array_values((array) ($data['sources'] ?? [])),
            provenance: array_values((array) ($data['provenance'] ?? [])), constraints: array_values((array) ($data['constraints'] ?? [])),
            nonGoals: array_values((array) ($data['non_goals'] ?? [])), hypotheses: array_values((array) ($data['hypotheses'] ?? [])),
            uncertainties: array_values((array) ($data['uncertainties'] ?? [])), falsifiers: array_values((array) ($data['falsifiers'] ?? [])),
            releasePolicy: (array) ($data['release_policy'] ?? []), outcomePolicy: (array) ($data['outcome_policy'] ?? []),
            worldObservedAt: trim((string) ($data['world_observed_at'] ?? '')),
        );
    }

    /** @return list<string> */
    public function productAuthorityGaps(): array
    {
        $gaps = [];
        foreach (['problem', 'user', 'value', 'metric', 'successWindow', 'worldObservedAt'] as $field) {
            if (trim($this->{$field}) === '') {
                $gaps[] = 'missing_'.strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $field) ?? $field);
            }
        }
        foreach (['sources', 'provenance', 'constraints', 'hypotheses', 'falsifiers', 'releasePolicy', 'outcomePolicy'] as $field) {
            if ($this->{$field} === []) {
                $gaps[] = 'missing_'.strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $field) ?? $field);
            }
        }
        try {
            if ($this->worldObservedAt === '' || now()->diffInMinutes(CarbonImmutable::parse($this->worldObservedAt), true) > 60) {
                $gaps[] = 'stale_world_model';
            }
        } catch (\Throwable) {
            $gaps[] = 'stale_world_model';
        }
        if (array_intersect(array_map('strval', $this->constraints), array_map('strval', $this->nonGoals)) !== []) {
            $gaps[] = 'contradictory_constraints';
        }

        return array_values(array_unique($gaps));
    }

    public function productAuthorityHash(): string
    {
        return hash('sha256', json_encode(get_object_vars($this), JSON_THROW_ON_ERROR));
    }

    public function wasElicited(string $question): bool
    {
        return array_key_exists($question, $this->elicitedAnswers)
            && trim((string) $this->elicitedAnswers[$question]) !== '';
    }
}
