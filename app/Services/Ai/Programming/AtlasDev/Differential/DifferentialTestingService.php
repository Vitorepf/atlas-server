<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Differential;


/**
 * E4 -- Differential testing service for best-of-N candidate comparison.
 *
 * Invoked by the compatibility candidate-comparison path
 * when N>=2 candidates have been generated. Compares every candidate's diff
 * text and produces a {@see CandidateDivergenceResult}:
 *
 *   - All candidates produce identical diffs => AGREEMENT (high confidence,
 *     no flag, passed allowed). VAL-E4-001.
 *   - At least one candidate's diff differs => DIVERGENCE carrying every
 *     candidate's diff as evidence. VAL-E4-002.
 *   - Fewer than 2 candidates => SKIP (nothing to compare).
 *
 * The comparison is deterministic and model-irrelevant: it is a pure
 * byte-for-byte comparison of the captured candidate diff texts. No real
 * LLM is involved.
 *
 * The service produces only the RESULT; the {@see CandidateDivergenceGate}
 * routes it through the sanctioned channels (advisory flag / hard
 * STATUS_FAILED / off no-op) based on the e4.mode config.
 *
 * Canonical: mission architecture.md (Atlas Dev Elevation v2, M5 / E4,
 * e4-differential-candidates feature).
 */
final class DifferentialTestingService
{
    /**
     * Compare N candidates' diff texts.
     *
     * Each candidate record must carry an 'index' (int) and a
     * 'candidate_diff_text' (?string). A null/missing diff text (e.g. a
     * candidate that threw during generation) is treated as an empty string
     * for comparison but counts as a divergence from any non-empty diff.
     *
     * @param  list<array{index: int, candidate_diff_text?: ?string}>  $candidates
     */
    public function compare(array $candidates): CandidateDivergenceResult
    {
        if (count($candidates) < 2) {
            return CandidateDivergenceResult::skipped(
                'fewer than 2 candidates: nothing to compare',
            );
        }

        // Normalize each candidate's diff text (null -> '').
        $normalized = [];
        foreach ($candidates as $candidate) {
            $index = (int) $candidate['index'];
            $diff = trim((string) ($candidate['candidate_diff_text'] ?? ''));
            $normalized[] = ['index' => $index, 'diff' => $diff];
        }

        // Check if all diffs are identical.
        $firstDiff = $normalized[0]['diff'];
        $allAgree = true;
        foreach ($normalized as $entry) {
            if ($entry['diff'] !== $firstDiff) {
                $allAgree = false;
                break;
            }
        }

        if ($allAgree) {
            return CandidateDivergenceResult::agreement(count($normalized));
        }

        // Divergence: carry every candidate's diff as evidence.
        return CandidateDivergenceResult::divergence($normalized);
    }
}
