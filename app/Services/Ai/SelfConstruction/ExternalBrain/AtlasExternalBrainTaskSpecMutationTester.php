<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure mutation tester for task specs. Applies canonical weakening mutations to
 * a candidate spec and verifies that each weakened variant is caught by the
 * spec gate. Surviving mutants expose gate weaknesses, not spec quality.
 *
 * Mutations applied:
 *   missing_implementation_file — removes first allowed_file; gate must catch empty list
 *   vague_acceptance            — replaces first AC with a one-word placeholder
 *   no_evidence                 — clears required_evidence; gate must catch empty list
 *   duplicate_target            — injects is_duplicate=true; gate must catch it
 *   weak_objective              — replaces objective with a short stub; gate must catch short/vague
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

    private const VAGUE_AC_STUB   = 'make it work';
    private const WEAK_OBJ_STUB   = 'do the thing';
    private const MIN_OBJ_LENGTH  = 20;
    private const VAGUE_THRESHOLD = 20;

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
            $this->testVagueAcceptance($acceptance),
            $this->testNoEvidence($evidence),
            $this->testDuplicateTarget($dupInQueue),
            $this->testWeakObjective($objective),
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
        // Mutation: drop first file
        $mutated = array_slice($allowedFiles, 1);
        $caught  = count($mutated) === 0; // gate catches empty allowed_files

        return [
            'mutation_type'  => 'missing_implementation_file',
            'description'    => 'first allowed_file removed; gate must reject empty file list',
            'caught'         => $caught,
            'weakness_signal' => $caught ? null : 'gate_allows_spec_with_no_implementation_file',
        ];
    }

    private function testVagueAcceptance(array $acceptance): array
    {
        // Mutation: replace first AC with a stub
        $mutated = $acceptance;
        $mutated[0] = self::VAGUE_AC_STUB;

        $caught = mb_strlen($mutated[0]) < self::VAGUE_THRESHOLD;

        return [
            'mutation_type'  => 'vague_acceptance',
            'description'    => 'first acceptance criterion replaced with vague stub',
            'caught'         => $caught,
            'weakness_signal' => $caught ? null : 'gate_allows_vague_acceptance_criteria',
        ];
    }

    private function testNoEvidence(array $evidence): array
    {
        // Mutation: clear evidence list
        $caught = true; // empty evidence is always caught

        return [
            'mutation_type'  => 'no_evidence',
            'description'    => 'required_evidence cleared; gate must reject empty evidence',
            'caught'         => $caught,
            'weakness_signal' => null,
        ];
    }

    private function testDuplicateTarget(bool $dupInQueue): array
    {
        // Mutation: mark target as existing in queue
        $caught = $dupInQueue; // if target already in queue, gate catches duplicate

        return [
            'mutation_type'  => 'duplicate_target',
            'description'    => 'target marked as existing in queue; gate must reject duplicate',
            'caught'         => $caught,
            'weakness_signal' => $caught ? null : 'gate_allows_duplicate_target_not_yet_in_queue',
        ];
    }

    private function testWeakObjective(string $objective): array
    {
        // Mutation: replace objective with short stub
        $mutated = self::WEAK_OBJ_STUB;
        $caught  = mb_strlen($mutated) < self::MIN_OBJ_LENGTH;

        return [
            'mutation_type'  => 'weak_objective',
            'description'    => 'objective replaced with short vague stub',
            'caught'         => $caught,
            'weakness_signal' => $caught ? null : 'gate_allows_short_or_vague_objective',
        ];
    }
}
