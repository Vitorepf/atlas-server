<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

/**
 * ABSURD-LEAP 4 — the REGRESSION IMMUNE SYSTEM (trust to ship unsupervised).
 *
 * Shipping autonomously is only trustworthy if the loop GUARDS what it shipped: when a check that was green
 * goes RED on main, the loop must decide whether ITS OWN recent merge caused it and, if so, auto-spawn a
 * repair — closing the ship -> watch -> repair antifragile loop. This sentinel is the attribution brain: it
 * matches each newly-RED check against the loop's recent merges by FILE OVERLAP (the failure's related files
 * intersect a merge's touched files) and attributes it to the LATEST such merge, emitting a repair objective.
 * A failure that overlaps no loop merge is left UNATTRIBUTED — the loop never blames itself for external
 * breakage, and never papers over a real pre-existing failure.
 *
 * Pure + deterministic over injected (failures, recent merges) — the real main-watcher (suite runner) and
 * the repair-enqueue wire on top. Timestamps are ISO-8601 strings compared lexicographically (sortable).
 */
final class AtlasLoopRegressionSentinel
{
    /**
     * @param  list<array{id:string, related_files?:list<string>, detail?:string}>  $failures  newly-RED checks
     * @param  list<array{commit:string, files?:list<string>, merged_at?:string, proposal_id?:string}>  $recentMerges
     * @return array{attributed:list<array<string,mixed>>, unattributed:list<string>, repairs:list<array<string,mixed>>}
     */
    public function triage(array $failures, array $recentMerges): array
    {
        $attributed = [];
        $unattributed = [];
        $repairs = [];

        foreach ($failures as $failure) {
            if (! is_array($failure) || trim((string) ($failure['id'] ?? '')) === '') {
                continue;
            }
            $id = trim((string) $failure['id']);
            $relatedFiles = $this->normFiles($failure['related_files'] ?? []);

            $culprit = null;
            foreach ($recentMerges as $merge) {
                if (! is_array($merge) || trim((string) ($merge['commit'] ?? '')) === '') {
                    continue;
                }
                $mergeFiles = $this->normFiles($merge['files'] ?? []);
                if (array_intersect($relatedFiles, $mergeFiles) === []) {
                    continue; // no file overlap => this merge did not touch what broke
                }
                // Attribute to the LATEST overlapping merge (most-recent change to the broken surface).
                if ($culprit === null || (string) ($merge['merged_at'] ?? '') > (string) ($culprit['merged_at'] ?? '')) {
                    $culprit = $merge;
                }
            }

            if ($culprit === null) {
                $unattributed[] = $id; // external/pre-existing breakage — not the loop's to auto-repair

                continue;
            }

            $overlap = array_values(array_intersect($relatedFiles, $this->normFiles($culprit['files'] ?? [])));
            $record = [
                'failure_id' => $id,
                'commit' => (string) $culprit['commit'],
                'proposal_id' => (string) ($culprit['proposal_id'] ?? ''),
                'overlap_files' => $overlap,
                'merged_at' => (string) ($culprit['merged_at'] ?? ''),
            ];
            $attributed[] = $record;
            $repairs[] = [
                'objective' => sprintf(
                    'REPAIR regression: check "%s" went RED on main; attributed to loop merge %s touching %s. '
                    .'Restore the check to GREEN without reverting the intended change — fix forward.',
                    $id,
                    substr((string) $culprit['commit'], 0, 12),
                    implode(', ', array_slice($overlap, 0, 6)),
                ),
                'failure_id' => $id,
                'target_files' => $overlap,
                'source' => 'regression_sentinel',
                'source_key' => hash('sha256', 'regression_sentinel|'.$id.'|'.$culprit['commit']),
            ];
        }

        return [
            'attributed' => $attributed,
            'unattributed' => array_values(array_unique($unattributed)),
            'repairs' => $repairs,
        ];
    }

    /**
     * @return list<string>
     */
    private function normFiles(mixed $files): array
    {
        if (! is_array($files)) {
            return [];
        }
        $out = [];
        foreach ($files as $f) {
            $f = is_string($f) ? ltrim(trim($f), '/') : '';
            if ($f !== '') {
                $out[] = $f;
            }
        }

        return array_values(array_unique($out));
    }
}
