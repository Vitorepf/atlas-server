<?php

declare(strict_types=1);

namespace App\Services\Ai\Compounding;

/**
 * Shared byte-identical helper(s) de-duplicated across this family (evidenceRefsOf).
 */
trait CompoundingEvidenceHelper
{
    private function evidenceRefsOf(AiLearningCandidate $candidate): array
    {
        $refs = $candidate->evidence_refs;
        if (! is_array($refs)) {
            return [];
        }
        $out = [];
        foreach ($refs as $ref) {
            if (! is_string($ref)) {
                continue;
            }
            $trim = trim($ref);
            if ($trim !== '') {
                $out[] = $trim;
            }
        }

        return $out;
    }
}
