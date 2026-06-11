<?php

declare(strict_types=1);

namespace App\Services\Ai\Analysis;

/**
 * G6 lens 1 — evidence discipline.
 *
 * Deterministic (no provider call). Refutes when:
 *  - the analysis carries no claims at all (nothing evidenced ⇒ default-refute);
 *  - any claim lacks a non-empty `evidence_refs` list;
 *  - a claim's declared confidence exceeds what its evidence count supports
 *    (confidence > 0.9 backed by fewer than 2 evidence refs).
 */
final class EvidenceLensJudge implements AnalysisJudgePort
{
    public const LENS = 'evidence';

    private const HIGH_CONFIDENCE_THRESHOLD = 0.9;

    private const HIGH_CONFIDENCE_MIN_REFS = 2;

    /**
     * @param  array<string,mixed>  $analysis
     * @return array{verdict:'accept'|'refute', reasons:list<string>, lens:string}
     */
    public function judge(array $analysis): array
    {
        $claims = $analysis['claims'] ?? null;

        if (! is_array($claims) || $claims === []) {
            return $this->refute(['no_claims_present']);
        }

        $reasons = [];

        foreach (array_values($claims) as $index => $claim) {
            if (! is_array($claim)) {
                $reasons[] = 'claim_not_structured:'.$this->claimId($claim, $index);

                continue;
            }

            $claimId = $this->claimId($claim, $index);
            $refs = $claim['evidence_refs'] ?? null;
            $refCount = is_array($refs) ? count(array_filter($refs, static fn ($ref): bool => trim((string) $ref) !== '')) : 0;

            if ($refCount === 0) {
                $reasons[] = 'claim_missing_evidence_refs:'.$claimId;
            }

            $confidence = $claim['confidence'] ?? null;
            if (
                is_numeric($confidence)
                && (float) $confidence > self::HIGH_CONFIDENCE_THRESHOLD
                && $refCount < self::HIGH_CONFIDENCE_MIN_REFS
            ) {
                $reasons[] = 'confidence_exceeds_evidence:'.$claimId;
            }
        }

        if ($reasons !== []) {
            return $this->refute($reasons);
        }

        return ['verdict' => 'accept', 'reasons' => [], 'lens' => self::LENS];
    }

    /**
     * @param  list<string>  $reasons
     * @return array{verdict:'refute', reasons:list<string>, lens:string}
     */
    private function refute(array $reasons): array
    {
        return ['verdict' => 'refute', 'reasons' => $reasons, 'lens' => self::LENS];
    }

    private function claimId(mixed $claim, int $index): string
    {
        if (is_array($claim)) {
            $id = trim((string) ($claim['id'] ?? ''));
            if ($id !== '') {
                return $id;
            }
        }

        return 'index_'.$index;
    }
}
