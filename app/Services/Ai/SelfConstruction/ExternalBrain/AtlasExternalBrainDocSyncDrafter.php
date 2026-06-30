<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Drafts provider-safe documentation update proposals from a control-plane snapshot.
 *
 * Input: output of AtlasExternalBrainControlPlaneSnapshot::snapshot(), extended with:
 *   stale_docs           bool          (default false) — documentation is outdated
 *   stale_code_index     bool          (default false) — code intelligence index is stale
 *   queue_quality_risks  list<string>  (default [])    — unresolved queue-quality risk labels
 *
 * Output sections (all 7 are always emitted):
 *   architecture       — structural capabilities proven so far
 *   operation          — current operational posture
 *   limitations        — active blockers and evidence gaps
 *   evidence           — ledger state and evidence health
 *   queue_quality      — queue depth / quality signal
 *   knowledge_freshness — staleness of docs and code index
 *   next_leverage      — highest-value next improvements
 *
 * READINESS CAP: readiness_claim is capped below 95% whenever ANY of:
 *   - certification blockers (impacts: caps_maturity_at_*, lowers_maturity)
 *   - evidence_gaps non-empty
 *   - stale_docs = true
 *   - stale_code_index = true
 *   - queue_quality_risks non-empty
 *
 * DOC DELTAS include: doc_key, section, proposed_delta, rationale, evidence_refs, update_priority.
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
     * @param  array<string,mixed>  $snapshot
     * @return array<string,mixed>
     */
    public function draft(array $snapshot): array
    {
        $band              = (string) ($snapshot['maturity_band']             ?? 'bootstrapping');
        $maturityScore     = (float)  ($snapshot['maturity_score']            ?? 0.0);
        $blockers          = (array)  ($snapshot['blockers']                  ?? []);
        $nextActions       = (array)  ($snapshot['next_actions']              ?? []);
        $evidenceGaps      = (array)  ($snapshot['evidence_gaps']             ?? []);
        $nextMissing       = (array)  ($snapshot['next_missing_capabilities'] ?? []);
        $dimensions        = (array)  ($snapshot['dimensions']                ?? []);
        $staleDocs         = (bool)   ($snapshot['stale_docs']                ?? false);
        $staleCodeIndex    = (bool)   ($snapshot['stale_code_index']          ?? false);
        $queueQualityRisks = (array)  ($snapshot['queue_quality_risks']       ?? []);

        $certBlocked      = $this->hasCertificationBlockers($blockers);
        $isReadinessCapped = $certBlocked
            || $evidenceGaps !== []
            || $staleDocs
            || $staleCodeIndex
            || $queueQualityRisks !== [];

        $baseReadiness = self::BAND_READINESS[$band] ?? 10;
        $readiness     = $isReadinessCapped ? min($baseReadiness, 89) : $baseReadiness;

        $refusedClaims = [];
        if ($certBlocked) {
            $refusedClaims[] = '95_percent_final_refused:certification_blockers_remain';
        }
        if ($evidenceGaps !== []) {
            $refusedClaims[] = '95_percent_final_refused:evidence_gaps_present';
        }
        if ($staleDocs) {
            $refusedClaims[] = '95_percent_final_refused:stale_docs';
        }
        if ($staleCodeIndex) {
            $refusedClaims[] = '95_percent_final_refused:stale_code_index';
        }
        if ($queueQualityRisks !== []) {
            $refusedClaims[] = '95_percent_final_refused:queue_quality_risks_unresolved';
        }

        return [
            'schema'                => self::SCHEMA,
            'readiness_claim'       => "{$readiness}% final",
            'certification_blocked' => $certBlocked,
            'refused_claims'        => $refusedClaims,
            'sections'              => [
                'architecture'       => $this->architectureSection($band, $maturityScore, $dimensions, $nextMissing),
                'operation'          => $this->operationSection($band, $dimensions, $nextActions),
                'limitations'        => $this->limitationsSection($blockers, $evidenceGaps, $certBlocked),
                'evidence'           => $this->evidenceSection($dimensions, $evidenceGaps, $nextMissing),
                'queue_quality'      => $this->queueQualitySection($dimensions, $queueQualityRisks),
                'knowledge_freshness'=> $this->knowledgeFreshnessSection($staleDocs, $staleCodeIndex),
                'next_leverage'      => $this->nextLeverageSection($nextMissing, $nextActions, $blockers),
            ],
            'doc_deltas' => $this->buildDocDeltas(
                $band, $blockers, $evidenceGaps, $nextActions,
                $certBlocked, $staleDocs, $staleCodeIndex, $queueQualityRisks,
            ),
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
        $queueStatus  = (string) ($dimensions['queue_status']  ?? 'unknown');
        $auditVerdict = (string) ($dimensions['audit_verdict'] ?? 'unknown');
        $stalledYield = (bool)  ($dimensions['stalled_yield']  ?? false);

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
            $impact = (string) ($blocker['impact']    ?? 'unknown');
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
                $name  = (string) ($cap['capability']    ?? '');
                $score = (float)  ($cap['current_score'] ?? 0.0);
                $lines[] = "  - {$name}: {$score}";
            }
        }

        return implode("\n", $lines);
    }

    private function queueQualitySection(array $dimensions, array $queueQualityRisks): string
    {
        $queueStatus = (string) ($dimensions['queue_status'] ?? 'unknown');

        if ($queueQualityRisks === []) {
            return "Queue status: {$queueStatus}. No queue quality risks detected.";
        }

        $riskList = implode(', ', $queueQualityRisks);

        return "Queue status: {$queueStatus}. Unresolved quality risks: {$riskList}. "
            .'These must be cleared before readiness can advance.';
    }

    private function knowledgeFreshnessSection(bool $staleDocs, bool $staleCodeIndex): string
    {
        $docLine  = $staleDocs
            ? 'Documentation index is STALE — doc deltas may not reflect current state.'
            : 'Documentation index is current.';
        $codeIdxLine = $staleCodeIndex
            ? 'Code intelligence index is STALE — code-graph queries may lag real state.'
            : 'Code intelligence index is current.';

        return "{$docLine}\n{$codeIdxLine}";
    }

    private function nextLeverageSection(array $nextMissing, array $nextActions, array $blockers): string
    {
        if ($nextMissing === [] && $nextActions === [] && $blockers === []) {
            return 'No immediate leverage gaps identified. System is operating at full chartered capacity.';
        }

        $lines = [];

        if ($blockers !== []) {
            $lines[] = 'Highest-value next step: resolve active blockers:';
            foreach ($blockers as $b) {
                $dim = (string) ($b['dimension'] ?? 'unknown');
                $lines[] = "  - unblock {$dim}";
            }
        }

        if ($nextMissing !== []) {
            $lines[] = 'Capability improvements with highest leverage:';
            foreach ($nextMissing as $cap) {
                $name  = (string) ($cap['capability']    ?? '');
                $score = (float)  ($cap['current_score'] ?? 0.0);
                $lines[] = "  - raise {$name} above threshold (current: {$score})";
            }
        }

        if ($nextActions !== []) {
            $lines[] = 'Operator actions required to unlock next tier:';
            foreach ($nextActions as $action) {
                $lines[] = "  - {$action}";
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @param  list<array<string,string>>  $blockers
     * @return list<array{doc_key:string,section:string,proposed_delta:string,rationale:string,evidence_refs:list<string>,update_priority:string}>
     */
    private function buildDocDeltas(
        string $band,
        array $blockers,
        array $evidenceGaps,
        array $nextActions,
        bool $certBlocked,
        bool $staleDocs,
        bool $staleCodeIndex,
        array $queueQualityRisks,
    ): array {
        $deltas = [];

        $isHighPriority = $certBlocked || $evidenceGaps !== [] || $staleDocs || $staleCodeIndex || $queueQualityRisks !== [];

        $deltas[] = [
            'doc_key'         => 'droid-wiki/systems/open-brain/index.md',
            'section'         => 'maturity-status',
            'proposed_delta'  => "Current maturity band: **{$band}**. "
                .($certBlocked ? 'Certification is blocked; see limitations section.' : 'Certification path is clear.'),
            'rationale'       => 'Keeps the maturity band declaration aligned with the current control-plane snapshot.',
            'evidence_refs'   => ['snapshot:maturity_band', 'snapshot:maturity_score'],
            'update_priority' => $isHighPriority ? 'high' : 'medium',
        ];

        if ($blockers !== []) {
            $blockerList = implode(', ', array_column($blockers, 'dimension'));
            $deltas[] = [
                'doc_key'         => 'droid-wiki/systems/open-brain/index.md',
                'section'         => 'active-blockers',
                'proposed_delta'  => "Active blockers: {$blockerList}. These must be resolved before the next certification gate.",
                'rationale'       => 'Ensures operators know which blockers are holding back maturity progression.',
                'evidence_refs'   => array_map(fn ($b) => 'blocker:'.(string) ($b['dimension'] ?? 'unknown'), $blockers),
                'update_priority' => 'high',
            ];
        }

        if ($evidenceGaps !== []) {
            $gapList = implode(', ', $evidenceGaps);
            $deltas[] = [
                'doc_key'         => 'droid-wiki/systems/open-brain/context-pack.md',
                'section'         => 'evidence-gaps',
                'proposed_delta'  => "Outstanding evidence gaps: {$gapList}.",
                'rationale'       => 'Surfaces evidence requirements so they appear in the context pack for the next cycle.',
                'evidence_refs'   => array_map(fn ($g) => "gap:{$g}", $evidenceGaps),
                'update_priority' => 'high',
            ];
        }

        if ($nextActions !== []) {
            $deltas[] = [
                'doc_key'         => 'droid-wiki/systems/evolution-loop/the-loop-brain.md',
                'section'         => 'pending-actions',
                'proposed_delta'  => 'Pending operator actions derived from snapshot: '.implode('; ', $nextActions).'.',
                'rationale'       => 'Translates snapshot next_actions into a doc-level action list for the loop brain doc.',
                'evidence_refs'   => ['snapshot:next_actions'],
                'update_priority' => 'medium',
            ];
        }

        if ($staleDocs || $staleCodeIndex) {
            $staleFlags = array_filter(['docs' => $staleDocs, 'code_index' => $staleCodeIndex]);
            $deltas[] = [
                'doc_key'         => 'droid-wiki/systems/open-brain/context-pack.md',
                'section'         => 'knowledge-freshness',
                'proposed_delta'  => 'Knowledge staleness detected: '.implode(', ', array_keys($staleFlags)).' must be refreshed before next sync.',
                'rationale'       => 'Flags stale knowledge sources so operators know to run sync/index before relying on context packs.',
                'evidence_refs'   => array_keys(array_filter(['snapshot:stale_docs' => $staleDocs, 'snapshot:stale_code_index' => $staleCodeIndex])),
                'update_priority' => 'high',
            ];
        }

        if ($queueQualityRisks !== []) {
            $riskList = implode(', ', $queueQualityRisks);
            $deltas[] = [
                'doc_key'         => 'droid-wiki/systems/evolution-loop/the-loop-brain.md',
                'section'         => 'queue-quality-risks',
                'proposed_delta'  => "Unresolved queue quality risks: {$riskList}. Address before advancing readiness.",
                'rationale'       => 'Ensures queue health blockers are visible in the loop brain doc.',
                'evidence_refs'   => array_map(fn ($r) => "queue_risk:{$r}", $queueQualityRisks),
                'update_priority' => 'high',
            ];
        }

        return $deltas;
    }
}
