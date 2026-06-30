<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Drafts provider-safe documentation update proposals from a control-plane snapshot.
 *
 * Input: output of AtlasExternalBrainControlPlaneSnapshot::snapshot()
 *
 * Output: a structured proposal with four canonical sections:
 *   architecture — what structural capabilities are proven
 *   operation    — current operational posture given maturity
 *   limitations  — what is not final, what blockers remain
 *   evidence     — tasks/gates that demonstrate real progress
 *
 * GUARD: readiness_claim is capped at <95% whenever certification blockers
 * (impacts: caps_maturity_at_emerging, caps_maturity_at_functional, lowers_maturity)
 * remain in the snapshot. The drafter never self-declares 95%+ final under blockers.
 *
 * Pure / deterministic. No I/O. Never edits docs directly.
 */
final class AtlasExternalBrainDocSyncDrafter
{
    public const SCHEMA = 'atlas.external_brain.doc_sync_drafter.v1';

    private const CERTIFICATION_BLOCKING_IMPACTS = [
        'caps_maturity_at_emerging',
        'caps_maturity_at_functional',
        'lowers_maturity',
    ];

    private const BAND_READINESS = [
        'bootstrapping' => 10,
        'emerging'      => 30,
        'functional'    => 55,
        'advanced'      => 75,
        'autonomous'    => 90,
    ];

    /**
     * @param  array<string,mixed>  $snapshot  Output of AtlasExternalBrainControlPlaneSnapshot::snapshot()
     * @return array{
     *     schema: string,
     *     readiness_claim: string,
     *     certification_blocked: bool,
     *     refused_claims: list<string>,
     *     sections: array{architecture:string, operation:string, limitations:string, evidence:string},
     *     doc_deltas: list<array<string,string>>,
     * }
     */
    public function draft(array $snapshot): array
    {
        $band         = (string) ($snapshot['maturity_band'] ?? 'bootstrapping');
        $maturityScore = (float) ($snapshot['maturity_score'] ?? 0.0);
        $blockers     = (array) ($snapshot['blockers'] ?? []);
        $nextActions  = (array) ($snapshot['next_actions'] ?? []);
        $evidenceGaps = (array) ($snapshot['evidence_gaps'] ?? []);
        $nextMissing  = (array) ($snapshot['next_missing_capabilities'] ?? []);
        $dimensions   = (array) ($snapshot['dimensions'] ?? []);

        $certBlocked  = $this->hasCertificationBlockers($blockers);
        $baseReadiness = self::BAND_READINESS[$band] ?? 10;
        $readiness    = $certBlocked ? min($baseReadiness, 89) : $baseReadiness;

        $refusedClaims = [];
        if ($certBlocked) {
            $refusedClaims[] = '95_percent_final_refused:certification_blockers_remain';
        }

        return [
            'schema'                => self::SCHEMA,
            'readiness_claim'       => "{$readiness}% final",
            'certification_blocked' => $certBlocked,
            'refused_claims'        => $refusedClaims,
            'sections'              => [
                'architecture' => $this->architectureSection($band, $maturityScore, $dimensions, $nextMissing),
                'operation'    => $this->operationSection($band, $dimensions, $nextActions),
                'limitations'  => $this->limitationsSection($blockers, $evidenceGaps, $certBlocked),
                'evidence'     => $this->evidenceSection($dimensions, $evidenceGaps, $nextMissing),
            ],
            'doc_deltas' => $this->buildDocDeltas($band, $blockers, $evidenceGaps, $nextActions, $certBlocked),
        ];
    }

    /** @param list<array<string,string>> $blockers */
    private function hasCertificationBlockers(array $blockers): bool
    {
        $impacts = array_column($blockers, 'impact');
        foreach (self::CERTIFICATION_BLOCKING_IMPACTS as $blocking) {
            if (in_array($blocking, $impacts, true)) {
                return true;
            }
        }

        return false;
    }

    private function architectureSection(
        string $band,
        float $score,
        array $dimensions,
        array $nextMissing,
    ): string {
        $ledgerPresent = (bool) ($dimensions['ledger_present'] ?? false);
        $ledgerNote    = $ledgerPresent
            ? 'Learning ledger is initialized and tracking outcomes.'
            : 'Learning ledger is absent — a structural gap that blocks evidence accumulation.';

        $missingCount = count($nextMissing);
        $missingNote  = $missingCount > 0
            ? "Structural capability gaps remain in {$missingCount} dimension(s)."
            : 'All tracked capability dimensions are above threshold.';

        return "Maturity band: {$band} (score: {$score}). {$ledgerNote} {$missingNote}";
    }

    private function operationSection(string $band, array $dimensions, array $nextActions): string
    {
        $queueStatus  = (string) ($dimensions['queue_status'] ?? 'unknown');
        $auditVerdict = (string) ($dimensions['audit_verdict'] ?? 'unknown');
        $stalledYield = (bool) ($dimensions['stalled_yield'] ?? false);

        $lines = [
            "Operational posture at maturity band '{$band}':",
            "  queue: {$queueStatus}",
            "  audit: {$auditVerdict}",
            '  yield: '.($stalledYield ? 'stalled' : 'active'),
        ];

        if ($nextActions !== []) {
            $lines[] = 'Pending operator actions:';
            foreach ($nextActions as $action) {
                $lines[] = "  - {$action}";
            }
        }

        return implode("\n", $lines);
    }

    /** @param list<array<string,string>> $blockers */
    private function limitationsSection(array $blockers, array $evidenceGaps, bool $certBlocked): string
    {
        if ($blockers === [] && $evidenceGaps === []) {
            return 'No active limitations. All certification criteria are clear.';
        }

        $lines = $certBlocked
            ? ['CERTIFICATION BLOCKED: the following blockers prevent a ≥95% final claim:']
            : ['Active limitations (do not affect certification threshold):'];

        foreach ($blockers as $blocker) {
            $dim    = (string) ($blocker['dimension'] ?? 'unknown');
            $impact = (string) ($blocker['impact'] ?? 'unknown');
            $lines[] = "  - [{$dim}] impact: {$impact}";
        }

        if ($evidenceGaps !== []) {
            $lines[] = 'Evidence gaps:';
            foreach ($evidenceGaps as $gap) {
                $lines[] = "  - {$gap}";
            }
        }

        return implode("\n", $lines);
    }

    private function evidenceSection(array $dimensions, array $evidenceGaps, array $nextMissing): string
    {
        $ledgerPresent = (bool) ($dimensions['ledger_present'] ?? false);

        $lines = $ledgerPresent
            ? ['Evidence ledger: present and active.']
            : ['Evidence ledger: ABSENT — no runtime evidence is being accumulated.'];

        if ($evidenceGaps !== []) {
            $lines[] = 'Gaps requiring evidence before next certification gate:';
            foreach ($evidenceGaps as $gap) {
                $lines[] = "  - {$gap}";
            }
        } else {
            $lines[] = 'No outstanding evidence gaps.';
        }

        if ($nextMissing !== []) {
            $lines[] = 'Capability dimensions below threshold:';
            foreach ($nextMissing as $cap) {
                $name  = (string) ($cap['capability'] ?? '');
                $score = (float) ($cap['current_score'] ?? 0.0);
                $lines[] = "  - {$name}: {$score}";
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @param  list<array<string,string>>  $blockers
     * @return list<array{doc_key:string, section:string, proposed_delta:string, rationale:string}>
     */
    private function buildDocDeltas(
        string $band,
        array $blockers,
        array $evidenceGaps,
        array $nextActions,
        bool $certBlocked,
    ): array {
        $deltas = [];

        $deltas[] = [
            'doc_key'        => 'droid-wiki/systems/open-brain/index.md',
            'section'        => 'maturity-status',
            'proposed_delta' => "Current maturity band: **{$band}**. "
                .($certBlocked ? 'Certification is blocked; see limitations section.' : 'Certification path is clear.'),
            'rationale' => 'Keeps the maturity band declaration aligned with the current control-plane snapshot.',
        ];

        if ($blockers !== []) {
            $blockerList = implode(', ', array_column($blockers, 'dimension'));
            $deltas[] = [
                'doc_key'        => 'droid-wiki/systems/open-brain/index.md',
                'section'        => 'active-blockers',
                'proposed_delta' => "Active blockers: {$blockerList}. These must be resolved before the next certification gate.",
                'rationale'      => 'Ensures operators know which blockers are holding back maturity progression.',
            ];
        }

        if ($evidenceGaps !== []) {
            $gapList = implode(', ', $evidenceGaps);
            $deltas[] = [
                'doc_key'        => 'droid-wiki/systems/open-brain/context-pack.md',
                'section'        => 'evidence-gaps',
                'proposed_delta' => "Outstanding evidence gaps: {$gapList}.",
                'rationale'      => 'Surfaces evidence requirements so they appear in the context pack for the next cycle.',
            ];
        }

        if ($nextActions !== []) {
            $deltas[] = [
                'doc_key'        => 'droid-wiki/systems/evolution-loop/the-loop-brain.md',
                'section'        => 'pending-actions',
                'proposed_delta' => 'Pending operator actions derived from snapshot: '.implode('; ', $nextActions).'.',
                'rationale'      => 'Translates snapshot next_actions into a doc-level action list for the loop brain doc.',
            ];
        }

        return $deltas;
    }
}
