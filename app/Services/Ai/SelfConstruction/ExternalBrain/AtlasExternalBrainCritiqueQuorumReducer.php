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
                $blockingFindings[] = [
                    'type' => $type,
                    'severity' => $severity,
                    'evidence' => (string) ($best['evidence'] ?? ''),
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

        return [
            'schema_version' => self::SCHEMA,
            'blocking_findings' => $blockingFindings,
            'merged_duplicates' => $mergedDuplicates,
            'unresolved_conflicts' => $unresolvedConflicts,
            'repair_actions' => $repairActions,
            'accepted_tradeoffs' => $acceptedTradeoffs,
        ];
    }
}
