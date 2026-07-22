<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

final class AtlasLoopLeapRiskAuditor
{
    /** @var list<string> */
    private const CLOSURE_TERMS = [
        'auditor',
        'certifier',
        'judge',
        'juiz',
        'riskauditor',
    ];

    public function __construct(
        private readonly ?AtlasLoopHarnessGuard $guard = null,
        private readonly ?AtlasLoopAntiFarmFloor $antiFarmFloor = null,
    ) {
    }

    /**
     * @param  list<array<string,mixed>>  $ambitionLeaps
     * @param  list<string>  $liveEvidenceRefs
     * @return list<array{record_type:string,leap_id:string,status:string,reasons:list<string>}>
     */
    public function audit(array $ambitionLeaps, array $liveEvidenceRefs = []): array
    {
        $live = array_fill_keys(array_values(array_unique(array_filter(array_map(
            static fn (string $ref): string => trim($ref),
            $liveEvidenceRefs,
        ), static fn (string $ref): bool => $ref !== ''))), true);

        return array_map(fn (array $leap): array => $this->verdict($leap, $live), $ambitionLeaps);
    }

    /**
     * @param  array<string,mixed>  $leap
     * @param  array<string,bool>  $liveEvidenceRefs
     * @return array{record_type:string,leap_id:string,status:string,reasons:list<string>}
     */
    private function verdict(array $leap, array $liveEvidenceRefs): array
    {
        $leapId = trim((string) ($leap['leap_id'] ?? ''));
        $reasons = [];
        if ($leapId === '' || ($leap['record_type'] ?? null) !== 'AmbitionLeap') {
            return $this->riskVerdict($leapId !== '' ? $leapId : 'unknown', ['unknown_leap_shape']);
        }

        $targetPath = trim((string) ($leap['target_path'] ?? ''));
        if ($targetPath !== '' && ($this->guard ?? new AtlasLoopHarnessGuard)->isForbiddenSelfTarget($targetPath)) {
            $reasons[] = 'forbidden_scope';
        }

        if ($this->touchesAuditorJudgeClosure($leap)) {
            $reasons[] = 'auditor_judge_closure';
        }

        $evidenceRefs = $this->evidenceRefs($leap);
        if ($evidenceRefs === [] || $this->danglingEvidenceRefs($evidenceRefs, $liveEvidenceRefs) !== []) {
            $reasons[] = 'dangling_evidence_refs';
        }

        $floor = ($this->antiFarmFloor ?? new AtlasLoopAntiFarmFloor)->eligibleToMerge($this->antiFarmEvidence($leap));
        if (($floor['eligible'] ?? false) !== true) {
            $reasons[] = 'proxy_farm:'.implode(',', (array) ($floor['reasons'] ?? ['anti_farm_floor_failed']));
        }

        return $this->riskVerdict($leapId, array_values(array_unique($reasons)));
    }

    /**
     * @param  list<string>  $reasons
     * @return array{record_type:string,leap_id:string,status:string,reasons:list<string>}
     */
    private function riskVerdict(string $leapId, array $reasons): array
    {
        return [
            'record_type' => 'RiskVerdict',
            'leap_id' => $leapId,
            'status' => $reasons === [] ? 'pass' : 'reject',
            'reasons' => $reasons,
        ];
    }

    /** @param array<string,mixed> $leap */
    private function touchesAuditorJudgeClosure(array $leap): bool
    {
        $haystack = strtolower(implode(' ', array_filter([
            (string) ($leap['target_path'] ?? ''),
            (string) ($leap['hypothesis'] ?? ''),
            (string) ($leap['target_capability_delta'] ?? ''),
        ], static fn (string $value): bool => $value !== '')));

        foreach (self::CLOSURE_TERMS as $term) {
            if (str_contains($haystack, $term)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string,mixed>  $leap
     * @return list<string>
     */
    private function evidenceRefs(array $leap): array
    {
        $refs = array_values(array_filter(array_map(
            static fn (mixed $ref): string => trim((string) $ref),
            (array) ($leap['grounded_evidence_refs'] ?? []),
        ), static fn (string $ref): bool => $ref !== ''));
        $refs = array_values(array_unique($refs));
        sort($refs, SORT_STRING);

        return $refs;
    }

    /**
     * @param  list<string>  $evidenceRefs
     * @param  array<string,bool>  $liveEvidenceRefs
     * @return list<string>
     */
    private function danglingEvidenceRefs(array $evidenceRefs, array $liveEvidenceRefs): array
    {
        return array_values(array_filter($evidenceRefs, static fn (string $ref): bool => ! isset($liveEvidenceRefs[$ref])));
    }

    /**
     * @param  array<string,mixed>  $leap
     * @return array<string,mixed>
     */
    private function antiFarmEvidence(array $leap): array
    {
        $evidence = $leap['anti_farm_evidence'] ?? [];
        if (! is_array($evidence)) {
            return [];
        }

        return $evidence;
    }
}
