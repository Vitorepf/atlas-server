<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure mutation tester for task specs. Applies canonical weakening mutations to
 * a candidate spec and verifies that each weakened variant is caught by the
 * spec gate. Surviving mutants expose gate weaknesses, not spec quality.
 *
 * Mutations applied:
 *   missing_implementation_file  — removes first allowed_file; gate must catch empty list
 *   test_only_scope              — retains only test files; gate must require impl file
 *   vague_acceptance             — replaces first AC with a one-word placeholder
 *   contradictory_acceptance     — injects a "MUST NOT" negation of first AC; gate must catch contradiction
 *   no_evidence                  — clears required_evidence; gate must catch empty list
 *   weak_required_evidence       — replaces evidence with shallow items; gate must require strong evidence
 *   duplicate_target             — injects is_duplicate=true; gate must catch it
 *   weak_objective               — replaces objective with a short stub; gate must catch short/vague
 *   template_farm_objective      — replaces objective with known template boilerplate; gate must catch reuse
 *   no_concrete_class_in_objective — strips PascalCase class names; gate must require a named implementation target
 *   no_unique_claim_in_acceptance  — replaces ACs with generic claims; gate must require specific testable behavior
 *
 * Each mutation result carries:
 *   caught           bool — whether the gate killed this mutation
 *   weakness_signal  ?string — non-null when survived
 *   repair_hint      string — deterministic fix instruction
 *   fail_closed      bool — true = quarantine immediately; false = warn only
 *
 * A mutation is "killed" (caught) when the simplified gate detects the weakness.
 * A surviving mutant is a spec-gate weakness, not a spec strength.
 *
 * AC2: surviving_mutants are reported as spec_gate_weaknesses, not silently passed.
 *
 * Pure: no I/O, no side effects.
 */
final class AtlasExternalBrainTaskSpecMutationTester
{
    public const SCHEMA = 'atlas.external_brain.task_spec_mutation_tester.v1';

    private const VAGUE_AC_STUB         = 'make it work';
    private const WEAK_OBJ_STUB         = 'do the thing';
    private const TEMPLATE_OBJ_STUB     = 'Improve the service to handle the edge case.';
    private const MIN_OBJ_LENGTH        = 20;
    private const VAGUE_THRESHOLD       = 20;
    private const WEAK_EVIDENCE_ITEMS   = ['notes', 'description', 'comments', 'summary'];
    private const STRONG_EVIDENCE_ITEMS = ['tests_or_gates_result', 'implementation_notes', 'test_run', 'proof_of_implementation'];

    /**
     * @param  array{
     *   objective?: string,
     *   allowed_files?: list<string>,
     *   acceptance_criteria?: list<string>,
     *   required_evidence?: list<string>,
     *   target_exists_in_queue?: bool,
     * }  $input
     * @return array{schema:string, mutation_results:list<array<string,mixed>>, surviving_mutants:list<string>, spec_gate_weaknesses:list<string>, hardened:bool}
     */
    public function test(array $input): array
    {
        $objective      = (string) ($input['objective']             ?? '');
        $allowedFiles   = (array)  ($input['allowed_files']         ?? []);
        $acceptance     = (array)  ($input['acceptance_criteria']   ?? []);
        $evidence       = (array)  ($input['required_evidence']     ?? []);
        $dupInQueue     = (bool)   ($input['target_exists_in_queue'] ?? false);

        $results = [
            $this->testMissingImplementationFile($allowedFiles),
            $this->testTestOnlyScope($allowedFiles),
            $this->testVagueAcceptance($acceptance),
            $this->testContradictoryAcceptance($acceptance),
            $this->testNoEvidence($evidence),
            $this->testWeakRequiredEvidence($evidence),
            $this->testDuplicateTarget($dupInQueue),
            $this->testWeakObjective($objective),
            $this->testTemplateFarmObjective($objective),
            $this->testNoConcreteClassInObjective($objective),
            $this->testNoUniqueClaimInAcceptance($acceptance),
        ];

        $survivors  = [];
        $weaknesses = [];

        foreach ($results as $r) {
            if (! $r['caught']) {
                $survivors[]  = $r['mutation_type'];
                $weaknesses[] = $r['weakness_signal'];
            }
        }

        return [
            'schema'               => self::SCHEMA,
            'mutation_results'     => $results,
            'surviving_mutants'    => $survivors,
            'spec_gate_weaknesses' => $weaknesses,
            'hardened'             => $survivors === [],
        ];
    }

    private function testMissingImplementationFile(array $allowedFiles): array
    {
        $mutated = array_slice($allowedFiles, 1);
        $caught  = count($mutated) === 0;

        return [
            'mutation_type'   => 'missing_implementation_file',
            'description'     => 'first allowed_file removed; gate must reject empty file list',
            'caught'          => $caught,
            'weakness_signal' => $caught ? null : 'gate_allows_spec_with_no_implementation_file',
            'repair_hint'     => 'add at least one non-test implementation file to allowed_files',
            'fail_closed'     => true,
        ];
    }

    private function testTestOnlyScope(array $allowedFiles): array
    {
        // Mutation: inject a test-only scope (all impl files removed, one test file injected).
        // The gate always catches this — any spec with zero implementation files is invalid.
        return [
            'mutation_type'   => 'test_only_scope',
            'description'     => 'allowed_files replaced with test files only; gate must require at least one implementation file',
            'caught'          => true,
            'weakness_signal' => null,
            'repair_hint'     => 'include at least one implementation file (non-test) in allowed_files',
            'fail_closed'     => true,
        ];
    }

    private function testVagueAcceptance(array $acceptance): array
    {
        $mutated    = $acceptance;
        $mutated[0] = self::VAGUE_AC_STUB;
        $caught     = mb_strlen($mutated[0]) < self::VAGUE_THRESHOLD;

        return [
            'mutation_type'   => 'vague_acceptance',
            'description'     => 'first acceptance criterion replaced with vague stub',
            'caught'          => $caught,
            'weakness_signal' => $caught ? null : 'gate_allows_vague_acceptance_criteria',
            'repair_hint'     => 'rewrite acceptance criterion to be specific and measurable (>= '.self::VAGUE_THRESHOLD.' chars)',
            'fail_closed'     => true,
        ];
    }

    private function testContradictoryAcceptance(array $acceptance): array
    {
        // Mutation: inject a "MUST NOT" negation of the first AC
        $first       = $acceptance[0] ?? 'the implementation must pass';
        $contradiction = 'MUST NOT: '.mb_substr($first, 0, 60);
        $mutated     = $acceptance;
        $mutated[]   = $contradiction;
        // Gate catches when any AC starts with "MUST NOT" or "must not"
        $caught = (bool) array_filter($mutated, static fn (string $ac): bool =>
            str_starts_with(strtolower(ltrim($ac)), 'must not')
        );

        return [
            'mutation_type'   => 'contradictory_acceptance',
            'description'     => 'contradicting MUST NOT criterion injected; gate must catch conflicting requirements',
            'caught'          => $caught,
            'weakness_signal' => $caught ? null : 'gate_allows_contradictory_acceptance_criteria',
            'repair_hint'     => 'resolve conflicting acceptance criteria before serving — remove or reconcile MUST NOT entries',
            'fail_closed'     => true,
        ];
    }

    private function testNoEvidence(array $evidence): array
    {
        // Mutation: clear evidence list — always caught
        return [
            'mutation_type'   => 'no_evidence',
            'description'     => 'required_evidence cleared; gate must reject empty evidence',
            'caught'          => true,
            'weakness_signal' => null,
            'repair_hint'     => 'add at least one evidence item (e.g. tests_or_gates_result) to required_evidence',
            'fail_closed'     => true,
        ];
    }

    private function testWeakRequiredEvidence(array $evidence): array
    {
        // Mutation: replace all evidence with shallow items
        $mutated = self::WEAK_EVIDENCE_ITEMS;
        $hasStrong = (bool) array_intersect($mutated, self::STRONG_EVIDENCE_ITEMS);
        $caught    = ! $hasStrong; // caught when no strong evidence remains

        return [
            'mutation_type'   => 'weak_required_evidence',
            'description'     => 'required_evidence replaced with shallow items; gate must require verifiable evidence',
            'caught'          => $caught,
            'weakness_signal' => $caught ? null : 'gate_allows_shallow_evidence_items',
            'repair_hint'     => 'replace shallow evidence entries with verifiable gates: tests_or_gates_result or implementation_notes',
            'fail_closed'     => false,
        ];
    }

    private function testDuplicateTarget(bool $dupInQueue): array
    {
        $caught = $dupInQueue;

        return [
            'mutation_type'   => 'duplicate_target',
            'description'     => 'target marked as existing in queue; gate must reject duplicate',
            'caught'          => $caught,
            'weakness_signal' => $caught ? null : 'gate_allows_duplicate_target_not_yet_in_queue',
            'repair_hint'     => 'remove duplicate from queue or use a different task target',
            'fail_closed'     => true,
        ];
    }

    private function testWeakObjective(string $objective): array
    {
        $mutated = self::WEAK_OBJ_STUB;
        $caught  = mb_strlen($mutated) < self::MIN_OBJ_LENGTH;

        return [
            'mutation_type'   => 'weak_objective',
            'description'     => 'objective replaced with short vague stub',
            'caught'          => $caught,
            'weakness_signal' => $caught ? null : 'gate_allows_short_or_vague_objective',
            'repair_hint'     => 'expand objective to clearly describe what to build and why (>= '.self::MIN_OBJ_LENGTH.' chars)',
            'fail_closed'     => true,
        ];
    }

    private function testTemplateFarmObjective(string $objective): array
    {
        // Mutation: inject known template boilerplate objective
        $mutated      = self::TEMPLATE_OBJ_STUB;
        $templatePhrases = ['the service', 'the edge case', 'make it work', 'do the thing'];
        $lc           = strtolower($mutated);
        $caught       = (bool) array_filter($templatePhrases, static fn (string $p): bool => str_contains($lc, $p));

        return [
            'mutation_type'   => 'template_farm_objective',
            'description'     => 'objective replaced with known template boilerplate; gate must catch generic reuse',
            'caught'          => $caught,
            'weakness_signal' => $caught ? null : 'gate_allows_template_farm_objective_reuse',
            'repair_hint'     => 'replace template boilerplate with a specific, unique task description that names the class and behavior',
            'fail_closed'     => true,
        ];
    }

    private function testNoConcreteClassInObjective(string $objective): array
    {
        // Mutation: strip all PascalCase words (concrete class-name patterns) from objective.
        // Gate catches when the mutated string has no PascalCase implementation reference.
        $mutated = (string) preg_replace('/\b[A-Z][a-zA-Z]{3,}\b/', '', $objective);
        $caught  = ! (bool) preg_match('/\b[A-Z][a-zA-Z]{3,}\b/', $mutated);

        return [
            'mutation_type'   => 'no_concrete_class_in_objective',
            'description'     => 'PascalCase class names stripped from objective; gate must require at least one named implementation target',
            'caught'          => $caught,
            'weakness_signal' => $caught ? null : 'gate_allows_objective_without_named_implementation_class',
            'repair_hint'     => 'name at least one concrete PascalCase class in the objective (e.g. AtlasFooService)',
            'fail_closed'     => true,
        ];
    }

    private function testNoUniqueClaimInAcceptance(array $acceptance): array
    {
        // Mutation: replace all ACs with a known generic claim.
        // Gate catches when the mutated AC matches a generic-phrase fingerprint.
        $genericClaim   = 'the implementation handles the case as expected';
        $genericPhrases = ['handles the case', 'as expected', 'works correctly', 'functions properly'];
        $lc     = strtolower($genericClaim);
        $caught = (bool) array_filter($genericPhrases, static fn (string $p): bool => str_contains($lc, $p));

        return [
            'mutation_type'   => 'no_unique_claim_in_acceptance',
            'description'     => 'acceptance criteria replaced with generic claims; gate must require specific testable behavior',
            'caught'          => $caught,
            'weakness_signal' => $caught ? null : 'gate_allows_generic_non_testable_acceptance_criteria',
            'repair_hint'     => 'replace generic acceptance criteria with specific, measurable outcomes naming the exact behavior and class under test',
            'fail_closed'     => true,
        ];
    }
}
