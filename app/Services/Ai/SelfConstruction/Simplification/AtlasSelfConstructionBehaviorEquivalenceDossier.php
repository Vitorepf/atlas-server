<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Simplification;

/**
 * Pure dossier that assesses whether a consolidation candidate is behavior-equivalent
 * to the original, requiring callgraph, output-shape, and proof-command equivalence.
 *
 * OUTPUT-SHAPE DIVERGENCES (AC2/AC3):
 *   When output_shape provides original and candidate as nested arrays, the dossier
 *   computes a recursive diff and reports each divergence as a dotted path in reasons:
 *     outputs_diverge:api.status.rate_limit
 *   The top-level output_shape_mismatch reason is still emitted alongside individual
 *   divergences when at least one divergence is not suppressed by tolerated_deltas.
 *
 *   tolerated_deltas (new, optional, default []) accepts dotted paths that should be
 *   ignored even when the values diverge. This lets callers suppress known volatile
 *   nested fields (e.g. "api.status.timestamp") while still failing on real drift.
 *
 *   When output_shape.match is explicitly provided (backward compat), the dossier uses
 *   the boolean directly and does not compute a nested diff.
 *
 * DOSSIER HASH (AC4):
 *   A deterministic sha256 hex digest computed from serialized evidence input, enabling
 *   stable comparison across runs for identical evidence.
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
     * @param  array<string,mixed>  $evidence
     * @return array<string,mixed>
     */
    public function assess(array $evidence): array
    {
        $reasons = [];

        // ── Dossier hash (deterministic, derived from all evidence) ────────
        $dossierHash = $this->computeHash($evidence);

        // ── tolerated_deltas: dotted paths to ignore in output-shape diff ──
        $toleratedDeltas = array_map('strval', is_array($evidence['tolerated_deltas'] ?? null) ? $evidence['tolerated_deltas'] : []);

        // ── Output-shape evidence — nested diff OR boolean match ──────────
        $outputShape = $evidence['output_shape'] ?? null;
        $outputDivergences = [];
        if (is_array($outputShape)) {
            // Explicit boolean match overrides nested comparison.
            if (array_key_exists('match', $outputShape)) {
                if ($outputShape['match'] !== true) {
                    $reasons[] = 'output_shape_mismatch';
                }
            } elseif (is_array($outputShape['original'] ?? null) && is_array($outputShape['candidate'] ?? null)) {
                // Compute nested diff and filter tolerated deltas.
                $allDivergences = $this->diffNested(
                    $outputShape['original'],
                    $outputShape['candidate'],
                    '',
                );
                $outputDivergences = array_values(array_filter(
                    $allDivergences,
                    static fn (string $path): bool => ! self::isTolerated($path, $toleratedDeltas),
                ));
                if ($outputDivergences !== []) {
                    $reasons[] = 'output_shape_mismatch';
                    foreach ($outputDivergences as $divPath) {
                        $reasons[] = 'outputs_diverge:'.$divPath;
                    }
                }
            } else {
                $reasons[] = 'missing_output_shape_evidence';
            }
        } else {
            $reasons[] = 'missing_output_shape_evidence';
        }

        // ── Callgraph evidence ─────────────────────────────────────────────
        $callgraph = $evidence['callgraph'] ?? null;
        $callgraphMatch = is_array($callgraph) && ($callgraph['match'] ?? false) === true;

        if (! is_array($callgraph)) {
            $reasons[] = 'missing_callgraph_evidence';
        } elseif (! $callgraphMatch) {
            $reasons[] = 'callgraph_mismatch';
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
            if (str_contains($r, 'mismatch') || str_contains($r, 'failed') || str_starts_with($r, 'outputs_diverge:')) {
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
            'schema'               => self::SCHEMA,
            'verdict'              => $verdict,
            'safe_to_consolidate'  => $verdict === self::VERDICT_SAFE,
            'reasons'              => $reasons,
            'output_shape_diff'    => $outputDivergences,
            'tolerated_deltas'     => $toleratedDeltas,
            'dossier_hash'         => $dossierHash,
        ];
    }

    /**
     * Recursively compares two nested arrays and returns dotted-path strings
     * for every leaf-level divergence.
     *
     * @param  array<string,mixed>  $original
     * @param  array<string,mixed>  $candidate
     * @return list<string>
     */
    private function diffNested(array $original, array $candidate, string $prefix): array
    {
        $divergences = [];

        // Keys present in original.
        foreach ($original as $key => $originalValue) {
            $path = $prefix !== '' ? $prefix.'.'.$key : (string) $key;
            if (! array_key_exists($key, $candidate)) {
                $divergences[] = $path;
                continue;
            }
            $candidateValue = $candidate[$key];
            if (is_array($originalValue) && is_array($candidateValue)) {
                $nested = $this->diffNested($originalValue, $candidateValue, $path);
                $divergences = array_merge($divergences, $nested);
            } elseif ($originalValue !== $candidateValue) {
                $divergences[] = $path;
            }
        }

        // Keys present in candidate but missing from original.
        foreach ($candidate as $key => $candidateValue) {
            $path = $prefix !== '' ? $prefix.'.'.$key : (string) $key;
            if (! array_key_exists($key, $original)) {
                $divergences[] = $path;
            }
        }

        return $divergences;
    }

    /**
     * Returns true when $path matches or is a prefix of any entry in $toleratedDeltas,
     * or any entry is a prefix of $path (suppression of the whole subtree).
     */
    private static function isTolerated(string $path, array $toleratedDeltas): bool
    {
        foreach ($toleratedDeltas as $delta) {
            if ($path === $delta || str_starts_with($path, $delta.'.') || str_starts_with($delta, $path.'.')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Deterministic hash of the entire evidence input. Sorted keys ensure
     * identical evidence always produces the same hash regardless of key order.
     *
     * @param  array<string,mixed>  $evidence
     */
    private function computeHash(array $evidence): string
    {
        return hash('sha256', (string) json_encode(
            self::ksortRecursive($evidence),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }

    /**
     * @param  array<int|string,mixed>  $input
     * @return array<int|string,mixed>
     */
    private static function ksortRecursive(array $input): array
    {
        foreach ($input as $key => $value) {
            if (is_array($value)) {
                $input[$key] = self::ksortRecursive($value);
            }
        }
        if ($input !== [] && array_keys($input) !== range(0, count($input) - 1)) {
            ksort($input);
        }

        return $input;
    }
}
