<?php

declare(strict_types=1);

namespace App\Services\Ai\EngineeringKernel\Spec;

/**
 * Engineering Kernel value: the composed-but-not-yet-frozen spec the adversary attacks.
 *
 * Owns: carrying the acceptance criteria + declared boundaries + the canonical intent text, as an
 * immutable snapshot, so the floor can compute the frozen_hash over the EXACT criteria it inspected.
 * Must never own: deciding whether the spec is right (SovereignSpecFloor) or composing it (SpecComposer).
 *
 * This is a trust boundary: the frozen_hash must bind to THIS content, so the criteria are explicit.
 */
final readonly class SpecDraft
{
    /**
     * @param  string  $intentText                canonical intent text the criteria claim to satisfy
     * @param  array<int,array{id:string,description:string,verification:string,verification_ref:?string,case_class?:string,is_backstop?:bool}>  $acceptanceCriteria
     * @param  list<string>  $expectedFiles        files the diff is allowed to touch
     * @param  list<string>  $forbiddenFiles       files the diff must NOT touch
     * @param  list<string>  $nonGoals             explicitly out-of-scope behaviours
     */
    public function __construct(
        public string $intentText,
        public array $acceptanceCriteria,
        public array $expectedFiles = [],
        public array $forbiddenFiles = [],
        public array $nonGoals = [],
    ) {}

    /**
     * @param  array<string,mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            intentText: (string) ($data['intent_text'] ?? ''),
            acceptanceCriteria: array_values((array) ($data['acceptance_criteria'] ?? [])),
            expectedFiles: array_values(array_map('strval', (array) ($data['expected_files'] ?? []))),
            forbiddenFiles: array_values(array_map('strval', (array) ($data['forbidden_files'] ?? []))),
            nonGoals: array_values(array_map('strval', (array) ($data['non_goals'] ?? []))),
        );
    }

    /**
     * Behavioral criteria only — backstops (command-exits-0, scope) are EXCLUDED from coverage
     * counting so form cannot substitute for a discriminating test.
     *
     * @return array<int,array<string,mixed>>
     */
    public function behavioralCriteria(): array
    {
        return array_values(array_filter(
            $this->acceptanceCriteria,
            static fn (array $ac): bool => ($ac['is_backstop'] ?? false) !== true,
        ));
    }
}
