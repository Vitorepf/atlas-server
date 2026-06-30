<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure reducer: collapses many critique outputs into a minimal, deduplicated set
 * of blocking findings, tradeoffs, and repair actions before task enqueue.
 *
 * Reduction rules (per finding type across all critique_outputs):
 *   1. Group by type.
 *   2. Conflict: some instances have severity='high', others do not, AND no instance
 *      carries resolves_conflict=true → emit unresolved_conflict; skip type.
 *   3. Merge: pick the instance with the longest evidence string as representative.
 *   4. severity='high' → blocking_finding; collect repair_actions (deduped).
 *   5. severity='low' OR accepted=true → accepted_tradeoff.
 *   6. merged_duplicates: types that appeared in 2+ findings.
 */
final class AtlasExternalBrainCritiqueQuorumReducer
{
    public const SCHEMA = 'atlas.external_brain.critique_quorum_reducer.v1';

    // evidence_strength threshold to treat a high-severity blocker as "sufficient evidence".
    private const STRONG_EVIDENCE_THRESHOLD    = 0.70;
    // At least this many critics must agree for strength+agreement to force reject.
    private const STRONG_AGREEMENT_MIN         = 2;
    // These blocker classes force reject on their own when evidence_strength is strong.
    private const FORCE_REJECT_BLOCKER_CLASSES = ['safety', 'correctness', 'data_loss'];
    // These blocker classes force escalate (after reject check).
    private const ESCALATE_BLOCKER_CLASSES     = ['scope_disagreement', 'escalate'];

    /**
     * @param  array<string,mixed>  $input  critique_outputs list
     * @return array<string,mixed>
     */
    public function reduce(array $input): array
    {
        $critiqueOutputs = is_array($input['critique_outputs'] ?? null) ? $input['critique_outputs'] : [];

        $byType = [];
        foreach ($critiqueOutputs as $output) {
            foreach (is_array($output['findings'] ?? null) ? $output['findings'] : [] as $finding) {
                $type = (string) ($finding['type'] ?? 'unknown');
                $byType[$type][] = $finding;
            }
        }

        $blockingFindings = [];
        $mergedDuplicates = [];
        $unresolvedConflicts = [];
        $repairActions = [];
        $acceptedTradeoffs = [];
        $seenRepairs = [];

        foreach ($byType as $type => $findings) {
            if (count($findings) >= 2) {
                $mergedDuplicates[] = ['type' => $type, 'merged_count' => count($findings)];
            }

            $severities = array_map(static fn (array $f): string => (string) ($f['severity'] ?? 'low'), $findings);
            $hasHigh = in_array('high', $severities, true);
            $hasNonHigh = count(array_filter($severities, static fn (string $s): bool => $s !== 'high')) > 0;

            if ($hasHigh && $hasNonHigh) {
                $resolved = (bool) array_filter($findings, static fn (array $f): bool => ! empty($f['resolves_conflict']));
                if (! $resolved) {
                    $unresolvedConflicts[] = [
                        'type' => $type,
                        'conflict' => 'severity_disagreement',
                        'finding_count' => count($findings),
                    ];

                    continue;
                }
            }

            usort($findings, static fn (array $a, array $b): int => strlen((string) ($b['evidence'] ?? '')) <=> strlen((string) ($a['evidence'] ?? '')));
            $best = $findings[0];
            $severity = (string) ($best['severity'] ?? 'low');

            if ($severity === 'high') {
                $maxEvidenceStrength = 0.0;
                foreach ($findings as $f) {
                    $maxEvidenceStrength = max($maxEvidenceStrength, min(1.0, max(0.0, (float) ($f['evidence_strength'] ?? 0.0))));
                }
                $blockingFindings[] = [
                    'type'              => $type,
                    'severity'          => $severity,
                    'evidence'          => (string) ($best['evidence'] ?? ''),
                    'evidence_strength' => $maxEvidenceStrength,
                    'agreement_count'   => count($findings),
                    'blocker_class'     => (string) ($best['blocker_class'] ?? ''),
                ];
                foreach ($findings as $f) {
                    $repair = trim((string) ($f['repair_action'] ?? ''));
                    if ($repair !== '' && ! isset($seenRepairs[$repair])) {
                        $seenRepairs[$repair] = true;
                        $repairActions[] = ['type' => $type, 'action' => $repair];
                    }
                }
            } elseif ($severity === 'low' || ! empty($best['accepted'])) {
                $acceptedTradeoffs[] = [
                    'type' => $type,
                    'severity' => $severity,
                    'evidence' => (string) ($best['evidence'] ?? ''),
                ];
            }
        }

        [$decision, $decisionReason] = $this->computeDecision($blockingFindings, $unresolvedConflicts);

        return [
            'schema_version'      => self::SCHEMA,
            'decision'            => $decision,
            'decision_reason'     => $decisionReason,
            'blocking_findings'   => $blockingFindings,
            'merged_duplicates'   => $mergedDuplicates,
            'unresolved_conflicts' => $unresolvedConflicts,
            'repair_actions'      => $repairActions,
            'accepted_tradeoffs'  => $acceptedTradeoffs,
        ];
    }

    /** @return array{string, string} [$decision, $reason] */
    private function computeDecision(array $blockingFindings, array $unresolvedConflicts): array
    {
        foreach ($blockingFindings as $bf) {
            $strongEvidence  = ($bf['evidence_strength'] ?? 0.0) >= self::STRONG_EVIDENCE_THRESHOLD;
            $strongAgreement = ($bf['agreement_count']   ?? 0)   >= self::STRONG_AGREEMENT_MIN;
            $safetyClass     = in_array($bf['blocker_class'] ?? '', self::FORCE_REJECT_BLOCKER_CLASSES, true);

            if ($strongEvidence && ($strongAgreement || $safetyClass)) {
                return ['reject', 'high_severity_blocker_with_sufficient_evidence'];
            }
        }

        if ($unresolvedConflicts !== []) {
            return ['escalate', 'unresolved_conflicts_require_escalation'];
        }

        foreach ($blockingFindings as $bf) {
            if (in_array($bf['blocker_class'] ?? '', self::ESCALATE_BLOCKER_CLASSES, true)) {
                return ['escalate', 'blocker_class_requires_escalation'];
            }
        }

        if ($blockingFindings !== []) {
            return ['repair', 'blocking_findings_with_insufficient_evidence_for_reject'];
        }

        return ['approve', 'no_blocking_findings'];
    }
}
