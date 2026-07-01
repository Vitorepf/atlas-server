<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Simplification;

/**
 * Pure dossier that assesses whether a consolidation candidate is behavior-equivalent
 * to the original, requiring callgraph, output-shape, and proof-command equivalence.
 *
 * Prevents deletion-first refactors from hiding behavior drift by requiring ALL three
 * evidence dimensions before marking safe_to_consolidate.
 *
 * NO network I/O, NO file I/O, NO provider calls.
 */
final class AtlasSelfConstructionBehaviorEquivalenceDossier
{
    public const SCHEMA = 'atlas.self_construction.behavior_equivalence_dossier.v1';

    public const VERDICT_SAFE = 'safe_to_consolidate';

    public const VERDICT_UNSAFE = 'unsafe';

    public const VERDICT_INCONCLUSIVE = 'inconclusive';

    /**
     * @param  array{
     *   candidate_name?:string,
     *   original_name?:string,
     *   callgraph?:array{original?:array<string,mixed>,candidate?:array<string,mixed>,match?:bool},
     *   output_shape?:array{original?:array<string,mixed>,candidate?:array<string,mixed>,match?:bool},
     *   proof_command?:array{command?:string,exit_code?:int,passed?:bool},
     * }  $evidence
     * @return array{
     *   schema:string,
     *   verdict:string,
     *   safe_to_consolidate:bool,
     *   reasons:list<string>,
     * }
     */
    public function assess(array $evidence): array
    {
        $reasons = [];

        // ── Callgraph evidence ─────────────────────────────────────────────
        $callgraph = $evidence['callgraph'] ?? null;
        $callgraphMatch = is_array($callgraph) && ($callgraph['match'] ?? false) === true;

        if (! is_array($callgraph)) {
            $reasons[] = 'missing_callgraph_evidence';
        } elseif (! $callgraphMatch) {
            $reasons[] = 'callgraph_mismatch';
        }

        // ── Output-shape evidence ──────────────────────────────────────────
        $outputShape = $evidence['output_shape'] ?? null;
        $outputShapeMatch = is_array($outputShape) && ($outputShape['match'] ?? false) === true;

        if (! is_array($outputShape)) {
            $reasons[] = 'missing_output_shape_evidence';
        } elseif (! $outputShapeMatch) {
            $reasons[] = 'output_shape_mismatch';
        }

        // ── Proof-command evidence ─────────────────────────────────────────
        $proofCommand = $evidence['proof_command'] ?? null;
        $proofPassed = is_array($proofCommand) && ($proofCommand['passed'] ?? false) === true;

        if (! is_array($proofCommand) || ! isset($proofCommand['command']) || (string) $proofCommand['command'] === '') {
            $reasons[] = 'missing_proof_command';
        } elseif (! $proofPassed) {
            $reasons[] = 'proof_command_failed';
        }

        // ── Verdict ────────────────────────────────────────────────────────
        // If any evidence is MISSING (not just mismatched), the dossier is inconclusive.
        // If evidence is present but mismatched/failed, it is unsafe.
        $hasMissing = false;
        $hasMismatch = false;
        foreach ($reasons as $r) {
            if (str_contains($r, 'missing')) {
                $hasMissing = true;
            }
            if (str_contains($r, 'mismatch') || str_contains($r, 'failed')) {
                $hasMismatch = true;
            }
        }

        if ($hasMissing) {
            $verdict = self::VERDICT_INCONCLUSIVE;
        } elseif ($hasMismatch) {
            $verdict = self::VERDICT_UNSAFE;
        } else {
            $verdict = self::VERDICT_SAFE;
            $reasons = ['all_evidence_dimensions_match'];
        }

        sort($reasons, SORT_STRING);

        return [
            'schema' => self::SCHEMA,
            'verdict' => $verdict,
            'safe_to_consolidate' => $verdict === self::VERDICT_SAFE,
            'reasons' => $reasons,
        ];
    }
}
