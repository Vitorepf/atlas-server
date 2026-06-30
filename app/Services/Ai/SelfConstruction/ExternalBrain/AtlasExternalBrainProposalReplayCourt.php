<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure replay court. Replays generated task proposals through queue-state,
 * duplicate, scaffold-compliance, and task-fabric checks before acceptance.
 *
 * Input facts:
 *   proposals              — list of {id, target_file?, objective, evidence?, scaffold_score?,
 *                             blast_radius?, leverage?, implementability?, risk?}.
 *   existing_queue_targets — list of target_file strings already in the live queue (duplicate check).
 *   min_scaffold_score     — minimum scaffold_score for compliance (default 0.60).
 *   require_evidence       — whether evidence must be present (default true).
 *   max_blast_radius       — maximum allowed blast_radius for task-fabric (default 0.90).
 *
 * AC2 — rejection checks (in priority order):
 *   1. duplicate_target   : target_file present in existing_queue_targets.
 *   2. scaffold_compliance: scaffold_score < min_scaffold_score.
 *   3. task_fabric_check  : blast_radius > max_blast_radius OR objective empty.
 *   4. evidence_check     : evidence absent or empty AND require_evidence=true.
 *
 * AC3 — accepted proposals are ranked by arena_score:
 *   arena_score = leverage*0.30 + implementability*0.25 + (1-risk)*0.25 + evidence_factor*0.20
 *   evidence_factor = min(1, evidence_count / EVIDENCE_SATURATION)
 *   Defaults: leverage=0.5, implementability=0.5, risk=0.5.
 *
 * Repairability: rejected proposals are repairable when:
 *   - The only rejection reason is scaffold_compliance (score can be improved), OR
 *   - The only reason is evidence_check (evidence can be added).
 *   Non-repairable: duplicate_target or task_fabric_check violations.
 *
 * AC4 outputs: accepted_proposals, rejected_proposals, replay_checks,
 *   repairable_proposals, arena_ranking, court_verdict.
 *
 * Pure, deterministic, no providers, no I/O.
 */
final class AtlasExternalBrainProposalReplayCourt
{
    public const SCHEMA = 'atlas.external_brain.proposal_replay_court.v1';

    private const DEFAULT_MIN_SCAFFOLD    = 0.60;
    private const DEFAULT_MAX_BLAST       = 0.90;
    private const DEFAULT_REQUIRE_EVIDENCE = true;
    private const EVIDENCE_SATURATION    = 5;

    private const CHECK_NAMES = [
        'duplicate_target',
        'scaffold_compliance',
        'task_fabric_check',
        'evidence_check',
    ];

    /**
     * @param  array<string,mixed>  $facts
     * @return array<string,mixed>
     */
    public function adjudicate(array $facts): array
    {
        $proposals       = is_array($facts['proposals'] ?? null) ? $facts['proposals'] : [];
        $queueTargets    = array_flip(is_array($facts['existing_queue_targets'] ?? null) ? $facts['existing_queue_targets'] : []);
        $minScaffold     = (float) ($facts['min_scaffold_score'] ?? self::DEFAULT_MIN_SCAFFOLD);
        $requireEvidence = (bool)  ($facts['require_evidence']   ?? self::DEFAULT_REQUIRE_EVIDENCE);
        $maxBlast        = (float) ($facts['max_blast_radius']    ?? self::DEFAULT_MAX_BLAST);

        $accepted    = [];
        $rejected    = [];
        $repairable  = [];
        $arenaScores = []; // id => arena_score

        // Per-check counters.
        $checkStats = [];
        foreach (self::CHECK_NAMES as $name) {
            $checkStats[$name] = ['checked' => 0, 'passed' => 0, 'failed' => 0];
        }

        foreach ($proposals as $proposal) {
            $id              = (string) ($proposal['id']              ?? '');
            $targetFile      = (string) ($proposal['target_file']     ?? '');
            $objective       = trim((string) ($proposal['objective']   ?? ''));
            $evidence        = is_array($proposal['evidence'] ?? null) ? $proposal['evidence'] : (is_string($proposal['evidence'] ?? null) ? [$proposal['evidence']] : []);
            $evidence        = array_filter($evidence, static fn ($e) => trim((string) $e) !== '');
            $scaffold        = (float) ($proposal['scaffold_score']   ?? 0.0);
            $blastRadius     = (float) ($proposal['blast_radius']     ?? 0.0);
            $leverage        = (float) ($proposal['leverage']         ?? 0.5);
            $implementability = (float) ($proposal['implementability'] ?? 0.5);
            $risk            = (float) ($proposal['risk']             ?? 0.5);

            $failures = [];

            // 1. Duplicate target check.
            $checkStats['duplicate_target']['checked']++;
            if ($targetFile !== '' && array_key_exists($targetFile, $queueTargets)) {
                $failures[] = 'duplicate_target';
                $checkStats['duplicate_target']['failed']++;
            } else {
                $checkStats['duplicate_target']['passed']++;
            }

            // 2. Scaffold compliance check.
            $checkStats['scaffold_compliance']['checked']++;
            if ($scaffold < $minScaffold) {
                $failures[] = 'scaffold_compliance';
                $checkStats['scaffold_compliance']['failed']++;
            } else {
                $checkStats['scaffold_compliance']['passed']++;
            }

            // 3. Task-fabric check.
            $checkStats['task_fabric_check']['checked']++;
            if ($objective === '' || $blastRadius > $maxBlast) {
                $failures[] = 'task_fabric_check';
                $checkStats['task_fabric_check']['failed']++;
            } else {
                $checkStats['task_fabric_check']['passed']++;
            }

            // 4. Evidence check.
            $checkStats['evidence_check']['checked']++;
            if ($requireEvidence && empty($evidence)) {
                $failures[] = 'evidence_check';
                $checkStats['evidence_check']['failed']++;
            } else {
                $checkStats['evidence_check']['passed']++;
            }

            if (empty($failures)) {
                $accepted[] = $id;
                // AC3: arena_score for ranking.
                $evidenceFactor = min(1.0, count($evidence) / self::EVIDENCE_SATURATION);
                $arenaScores[$id] = round(
                    $leverage * 0.30 + $implementability * 0.25 + (1.0 - $risk) * 0.25 + $evidenceFactor * 0.20,
                    4,
                );
            } else {
                $rejected[] = ['id' => $id, 'rejection_reasons' => $failures];
                $repairHint = $this->repairHint($failures);
                if ($repairHint !== null) {
                    $repairable[] = ['id' => $id, 'repair_hint' => $repairHint];
                }
            }
        }

        $verdict = $this->verdict(count($proposals), count($accepted));

        // AC3: sort accepted proposals by arena_score descending, then id for determinism.
        arsort($arenaScores);
        $arenaRanking = [];
        $rank = 1;
        foreach ($arenaScores as $id => $score) {
            $arenaRanking[] = ['rank' => $rank++, 'id' => $id, 'arena_score' => $score];
        }

        return [
            'schema_version'       => self::SCHEMA,
            'accepted_proposals'   => $accepted,
            'rejected_proposals'   => $rejected,
            'replay_checks'        => $checkStats,
            'repairable_proposals' => $repairable,
            'arena_ranking'        => $arenaRanking,
            'court_verdict'        => $verdict,
        ];
    }

    private function repairHint(array $failures): ?string
    {
        // Non-repairable failures take precedence.
        if (in_array('duplicate_target', $failures, true) || in_array('task_fabric_check', $failures, true)) {
            return null;
        }
        $hints = [];
        if (in_array('scaffold_compliance', $failures, true)) {
            $hints[] = 'improve scaffold score before re-submission';
        }
        if (in_array('evidence_check', $failures, true)) {
            $hints[] = 'attach evidence before re-submission';
        }

        return $hints ? implode('; ', $hints) : null;
    }

    private function verdict(int $total, int $accepted): string
    {
        if ($total === 0) {
            return 'empty';
        }
        if ($accepted === $total) {
            return 'all_accepted';
        }
        if ($accepted === 0) {
            return 'all_rejected';
        }

        return 'partial_acceptance';
    }
}
