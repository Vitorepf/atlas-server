<?php

declare(strict_types=1);

namespace App\Services\Ai\EngineeringKernel\Spec;

use App\Services\Ai\EngineeringKernel\CanonicalKernelPayload;

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
     * @param  string  $intentText  canonical intent text the criteria claim to satisfy
     * @param  array<int,array{id:string,description:string,verification:string,verification_ref:?string,case_class?:string,is_backstop?:bool}>  $acceptanceCriteria
     * @param  list<string>  $expectedFiles  files the diff is allowed to touch
     * @param  list<string>  $forbiddenFiles  files the diff must NOT touch
     * @param  list<string>  $nonGoals  explicitly out-of-scope behaviours
     */
    public function __construct(
        public string $intentText,
        public array $acceptanceCriteria,
        public array $expectedFiles = [],
        public array $forbiddenFiles = [],
        public array $nonGoals = [],
        public array $invariants = [], public array $nonFunctionalRequirements = [],
        public array $security = [], public array $accessibility = [], public array $observability = [],
        public array $compatibility = [], public array $migration = [], public array $rollback = [],
        public array $oracles = [], public array $invalidityConditions = [],
        public ?string $productIntentHash = null,
        public ?string $worldSnapshotHash = null,
        public ?string $evidenceBindingHash = null,
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
            invariants: array_values((array) ($data['invariants'] ?? [])),
            nonFunctionalRequirements: array_values((array) ($data['non_functional_requirements'] ?? [])),
            security: array_values((array) ($data['security'] ?? [])), accessibility: array_values((array) ($data['accessibility'] ?? [])),
            observability: array_values((array) ($data['observability'] ?? [])), compatibility: array_values((array) ($data['compatibility'] ?? [])),
            migration: array_values((array) ($data['migration'] ?? [])), rollback: array_values((array) ($data['rollback'] ?? [])),
            oracles: array_values((array) ($data['oracles'] ?? [])), invalidityConditions: array_values((array) ($data['invalidity_conditions'] ?? [])),
            productIntentHash: self::hashOrNull($data['product_intent_hash'] ?? null),
            worldSnapshotHash: self::hashOrNull($data['world_snapshot_hash'] ?? null),
            evidenceBindingHash: self::hashOrNull($data['evidence_binding_hash'] ?? null),
        );
    }

    /** @return list<string> */
    public function authorityGaps(): array
    {
        $gaps = [];
        foreach (['acceptanceCriteria', 'invariants', 'nonFunctionalRequirements', 'security', 'accessibility', 'observability',
            'compatibility', 'migration', 'rollback', 'oracles', 'invalidityConditions'] as $field) {
            if ($this->{$field} === []) {
                $gaps[] = 'missing_'.strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $field) ?? $field);
            }
        }

        return $gaps;
    }

    public function authorityHash(): string
    {
        return CanonicalKernelPayload::hash([
            'intent_text' => $this->intentText, 'acceptance_criteria' => $this->acceptanceCriteria,
            'expected_files' => $this->expectedFiles, 'forbidden_files' => $this->forbiddenFiles, 'non_goals' => $this->nonGoals,
            'invariants' => $this->invariants, 'nfr' => $this->nonFunctionalRequirements, 'security' => $this->security,
            'accessibility' => $this->accessibility, 'observability' => $this->observability, 'compatibility' => $this->compatibility,
            'migration' => $this->migration, 'rollback' => $this->rollback, 'oracles' => $this->oracles,
            'invalidity_conditions' => $this->invalidityConditions,
            'product_intent_hash' => $this->productIntentHash, 'world_snapshot_hash' => $this->worldSnapshotHash,
            'evidence_binding_hash' => $this->evidenceBindingHash,
        ]);
    }

    /** @return list<string> */
    public function bindingGaps(): array
    {
        $gaps = [];
        foreach (['productIntentHash' => 'product_intent_hash', 'worldSnapshotHash' => 'world_snapshot_hash', 'evidenceBindingHash' => 'evidence_binding_hash'] as $property => $name) {
            if ($this->{$property} === null) {
                $gaps[] = 'missing_'.$name;
            }
        }

        return $gaps;
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

    private static function hashOrNull(mixed $value): ?string
    {
        $value = is_scalar($value) ? trim((string) $value) : '';

        return preg_match('/^[a-f0-9]{64}$/i', $value) === 1 ? strtolower($value) : null;
    }
}
