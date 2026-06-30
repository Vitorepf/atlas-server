<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure commit-to-roadmap delta mapper. Translates a green commit into the REAL capability
 * delta it delivered — never just "a task completed" — so the roadmap only advances on proven
 * behavior change, never on scaffolding, test-only commits, or unverified self-reports.
 *
 * INPUT:
 *   commits: list<{
 *     capability_id:          string
 *     touched_files?:         list<string>
 *     test_files?:            list<string>
 *     objective?:             string
 *     impact_class?:          string  — real_capability|scaffolding|observability|test_only|unverified
 *     has_behavior_evidence?: bool    — concrete proof beyond green tests (e.g. behavior_delta observed)
 *     before_maturity?:       string  — none|scaffolding|partial|integrated|mature
 *     after_maturity?:        string  — claimed post-commit maturity
 *   }>
 *
 * CLOSE-GAP ELIGIBILITY (a roadmap gap is allowed to close, AC3):
 *   impact_class === 'real_capability'
 *   AND has_behavior_evidence === true
 *   AND touched_files contains at least one non-test file (never test-only)
 *   AND after_maturity ranks strictly above before_maturity on the maturity ladder
 *   Anything else is REFUSED: the claimed after_maturity never lands in roadmap_delta — the
 *   capability is reported unchanged with a named evidence gap.
 *
 * OUTPUT:
 *   { schema, roadmap_delta:list<{capability_id,before_maturity,after_maturity,matured,reason}>,
 *     matured_capabilities:list<string>, unchanged_claims:list<string>, evidence_gaps:list<string>,
 *     next_roadmap_gap_candidates:list<string> }
 *
 * Pure / deterministic. No I/O, no provider calls.
 */
final class AtlasExternalBrainCommitToRoadmapDeltaMapper
{
    public const SCHEMA = 'atlas.external_brain.commit_to_roadmap_delta_mapper.v1';

    private const MATURITY_ORDER = [
        'none'       => 0,
        'scaffolding' => 1,
        'partial'    => 2,
        'integrated' => 3,
        'mature'     => 4,
    ];

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function map(array $input): array
    {
        $commits = is_array($input['commits'] ?? null) ? $input['commits'] : [];

        $roadmapDelta            = [];
        $maturedCapabilities     = [];
        $unchangedClaims         = [];
        $evidenceGaps            = [];
        $nextRoadmapGapCandidates = [];

        foreach ($commits as $commit) {
            if (! is_array($commit)) {
                continue;
            }
            $capabilityId = trim((string) ($commit['capability_id'] ?? ''));
            if ($capabilityId === '') {
                continue;
            }

            $touchedFiles  = array_values(array_filter(array_map('strval', (array) ($commit['touched_files'] ?? []))));
            $testFiles     = array_values(array_filter(array_map('strval', (array) ($commit['test_files'] ?? []))));
            $objective     = trim((string) ($commit['objective'] ?? ''));
            $impactClass   = strtolower(trim((string) ($commit['impact_class'] ?? 'unverified')));
            $hasEvidence   = (bool) ($commit['has_behavior_evidence'] ?? false);
            $before        = strtolower(trim((string) ($commit['before_maturity'] ?? 'none')));
            $afterClaimed  = strtolower(trim((string) ($commit['after_maturity'] ?? $before)));

            $nonTestFiles = array_values(array_diff($touchedFiles, $testFiles));
            $isTestOnly   = $touchedFiles !== [] && $nonTestFiles === [];

            $beforeRank = self::MATURITY_ORDER[$before] ?? 0;
            $afterRank  = self::MATURITY_ORDER[$afterClaimed] ?? $beforeRank;

            $reasons = [];
            if ($impactClass !== 'real_capability') {
                $reasons[] = "impact_class={$impactClass}_not_real_capability";
            }
            if (! $hasEvidence) {
                $reasons[] = 'no_behavior_evidence_beyond_green_tests';
            }
            if ($isTestOnly) {
                $reasons[] = 'test_only_commit_no_implementation_file';
            }
            if ($afterRank <= $beforeRank) {
                $reasons[] = 'claimed_after_maturity_does_not_exceed_before_maturity';
            }

            $matured = $reasons === [];

            if ($matured) {
                $roadmapDelta[] = [
                    'capability_id'    => $capabilityId,
                    'before_maturity'  => $before,
                    'after_maturity'   => $afterClaimed,
                    'matured'          => true,
                    'reason'           => 'real_capability_delta_with_behavior_evidence',
                ];
                $maturedCapabilities[] = $capabilityId;
            } else {
                $roadmapDelta[] = [
                    'capability_id'    => $capabilityId,
                    'before_maturity'  => $before,
                    'after_maturity'   => $before, // claim refused — maturity stays where it was
                    'matured'          => false,
                    'reason'           => implode(',', $reasons),
                ];
                $unchangedClaims[] = $capabilityId;
                foreach ($reasons as $reason) {
                    $evidenceGaps[] = "{$capabilityId}:{$reason}";
                }
                $nextRoadmapGapCandidates[] = $capabilityId;
            }
        }

        return [
            'schema'                       => self::SCHEMA,
            'roadmap_delta'                => $roadmapDelta,
            'matured_capabilities'         => array_values(array_unique($maturedCapabilities)),
            'unchanged_claims'             => array_values(array_unique($unchangedClaims)),
            'evidence_gaps'                => $evidenceGaps,
            'next_roadmap_gap_candidates'  => array_values(array_unique($nextRoadmapGapCandidates)),
        ];
    }
}
