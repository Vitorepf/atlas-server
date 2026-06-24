<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

final class AtlasLoopAmbitionLeapProposer
{
    private const MIN_GROUNDED_EVIDENCE = 3;

    /** @var list<string> */
    private const PROXY_DELTA_TERMS = [
        'cyclomatic',
        'dead code',
        'dead-code',
        'formatting',
        'refactor',
        'rename',
        'whitespace',
    ];

    /**
     * @param  list<array<string,mixed>>  $frontierGaps
     * @return list<array{record_type:string,leap_id:string,gap_id:string,hypothesis:?string,target_capability_delta:?string,grounded_evidence_refs:list<string>,abstain_reason:?string}>
     */
    public function propose(array $frontierGaps): array
    {
        if ($frontierGaps === []) {
            return [];
        }

        $leaps = [];
        foreach ($frontierGaps as $gap) {
            $gapId = trim((string) ($gap['gap_id'] ?? ''));
            $evidenceRefs = $this->evidenceRefs($gap);
            if ($gapId === '' || $evidenceRefs === []) {
                continue;
            }

            if (($gap['plateau_signal'] ?? false) !== true || count($evidenceRefs) < self::MIN_GROUNDED_EVIDENCE) {
                $leaps[] = $this->abstain($gapId, $evidenceRefs, 'insufficient_grounded_evidence');

                continue;
            }

            $delta = $this->targetCapabilityDelta($gap);
            if ($this->isProxyDelta($delta)) {
                $leaps[] = $this->abstain($gapId, $evidenceRefs, 'proxy_delta_rejected');

                continue;
            }

            $scope = trim((string) ($gap['scope'] ?? 'loop'));
            $leaps[] = [
                'record_type' => 'AmbitionLeap',
                'leap_id' => 'ambition_leap:'.sha1($gapId.'|'.$delta.'|'.implode('|', $evidenceRefs)),
                'gap_id' => $gapId,
                'hypothesis' => 'If Atlas turns the '.$scope.' frontier gap into a grounded ambition supply lane, the loop originates next-level objectives instead of idling on reactive work.',
                'target_capability_delta' => $delta,
                'grounded_evidence_refs' => $evidenceRefs,
                'abstain_reason' => null,
            ];
        }

        return $leaps;
    }

    /**
     * @param  array<string,mixed>  $gap
     * @return list<string>
     */
    private function evidenceRefs(array $gap): array
    {
        $refs = array_values(array_filter(array_map(
            static fn (mixed $ref): string => trim((string) $ref),
            (array) ($gap['evidence_refs'] ?? []),
        ), static fn (string $ref): bool => $ref !== ''));
        $refs = array_values(array_unique($refs));
        sort($refs, SORT_STRING);

        return $refs;
    }

    /**
     * @param  list<string>  $evidenceRefs
     * @return array{record_type:string,leap_id:string,gap_id:string,hypothesis:null,target_capability_delta:null,grounded_evidence_refs:list<string>,abstain_reason:string}
     */
    private function abstain(string $gapId, array $evidenceRefs, string $reason): array
    {
        return [
            'record_type' => 'AmbitionLeap',
            'leap_id' => 'ambition_leap:abstain:'.sha1($gapId.'|'.$reason.'|'.implode('|', $evidenceRefs)),
            'gap_id' => $gapId,
            'hypothesis' => null,
            'target_capability_delta' => null,
            'grounded_evidence_refs' => $evidenceRefs,
            'abstain_reason' => $reason,
        ];
    }

    /** @param array<string,mixed> $gap */
    private function targetCapabilityDelta(array $gap): string
    {
        $candidate = trim((string) ($gap['target_capability_delta'] ?? ''));
        if ($candidate !== '') {
            return $candidate;
        }

        $scope = trim((string) ($gap['scope'] ?? 'loop'));

        return 'Capability multiplier: convert '.$scope.' from reactive backlog consumption into evidence-grounded ambition origination with verified next-level objective supply.';
    }

    private function isProxyDelta(string $delta): bool
    {
        $normalized = strtolower($delta);
        foreach (self::PROXY_DELTA_TERMS as $term) {
            if (str_contains($normalized, $term)) {
                return true;
            }
        }

        return false;
    }
}
