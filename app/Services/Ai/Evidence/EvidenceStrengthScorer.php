<?php

declare(strict_types=1);

namespace App\Services\Ai\Evidence;

/**
 * Kind-weighted evidence strength scorer.
 *
 * Upgrades the legacy count-only heuristic (4 cheap doc links == 'strong') into a
 * weighted total where a single green gate_run or test_result outweighs several
 * doc links. Pure: every returned field is computed from the supplied refs.
 * @unwired-until 2026-08-05 (Obra #7 W2: capability testada aguardando consumidor; triagem 2026-07-06)
 */
final class EvidenceStrengthScorer
{
    private const SCHEMA_VERSION = 'atlas.aaeos.evidence_strength.v1';

    private const UNKNOWN_KIND = 'unknown';

    /**
     * Weight contributed to the strength total by each normalized evidence kind.
     * Kinds absent from this map contribute 0 (still counted in the breakdown).
     *
     * @var array<string,int>
     */
    private const KIND_WEIGHTS = [
        'doc' => 1,
        'receipt' => 2,
        'implementation_notes' => 2,
        'commit_hash' => 3,
        'benchmark_weight' => 4,
        'gate_run' => 5,
        'test_result' => 5,
    ];

    /** Kinds that satisfy the "runnable proof" requirement group — any one is enough. */
    private const RUNNABLE_PROOF_KINDS = ['gate_run', 'test_result'];

    /** Kinds that satisfy the "implementation notes" requirement group. */
    private const IMPLEMENTATION_NOTE_KINDS = ['implementation_notes'];

    /**
     * @param  array<int|string,mixed>  $refs
     * @return array{schema_version:string, score:int, tier:string, kind_breakdown:array<string,int>, missing_required_kinds:list<string>}
     */
    public function score(array $refs): array
    {
        $total = 0;
        $kindBreakdown = [];

        foreach ($refs as $ref) {
            $kind = $this->normalizeKind($ref);

            if ($kind === null) {
                continue;
            }

            $total += self::KIND_WEIGHTS[$kind] ?? 0;
            $kindBreakdown[$kind] = ($kindBreakdown[$kind] ?? 0) + 1;
        }

        $hasRunnableProof = $this->hasAnyKind($kindBreakdown, self::RUNNABLE_PROOF_KINDS);
        $hasImplementationNotes = $this->hasAnyKind($kindBreakdown, self::IMPLEMENTATION_NOTE_KINDS);
        $hasDocRefs = ($kindBreakdown['doc'] ?? 0) > 0;

        $missingRequiredKinds = [];
        if (! $hasRunnableProof) {
            $missingRequiredKinds[] = 'gate_run_or_test_result';
        }
        if (! $hasImplementationNotes) {
            $missingRequiredKinds[] = 'implementation_notes';
        }

        // A doc-only proxy stack (piles of cheap doc links) must never reach strong without
        // runnable proof — but non-doc evidence kinds (commit_hash, benchmark_weight, receipt)
        // are legitimate on their own and are not held to the runnable-proof requirement.
        $strongBlockedByDocOnlyProxy = $hasDocRefs && ! $hasRunnableProof;

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'score' => $total,
            'tier' => $this->tierForScore($total, $strongBlockedByDocOnlyProxy),
            'kind_breakdown' => $kindBreakdown,
            'missing_required_kinds' => $missingRequiredKinds,
        ];
    }

    /**
     * @param  array<string,int>  $kindBreakdown
     * @param  list<string>  $kinds
     */
    private function hasAnyKind(array $kindBreakdown, array $kinds): bool
    {
        foreach ($kinds as $kind) {
            if (($kindBreakdown[$kind] ?? 0) > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolve the normalized kind for one ref, or null when the entry must be skipped.
     */
    private function normalizeKind(mixed $ref): ?string
    {
        if (is_string($ref)) {
            $trimmed = trim($ref);

            if ($trimmed === '') {
                return null;
            }

            return self::UNKNOWN_KIND;
        }

        if (is_array($ref)) {
            $raw = $ref['kind'] ?? $ref['type'] ?? null;

            if (! is_string($raw)) {
                return self::UNKNOWN_KIND;
            }

            $trimmed = trim($raw);

            return $trimmed === '' ? self::UNKNOWN_KIND : $trimmed;
        }

        return null;
    }

    private function tierForScore(int $score, bool $strongBlockedByDocOnlyProxy): string
    {
        return match (true) {
            $score <= 0 => 'invalid',
            $score <= 2 => 'weak',
            $score <= 6 || $strongBlockedByDocOnlyProxy => 'moderate',
            default => 'strong',
        };
    }
}
