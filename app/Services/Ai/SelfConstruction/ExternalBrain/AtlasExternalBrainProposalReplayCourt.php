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
 * Historical replay (AC2/AC3): each proposal is additionally compared against
 * success_patterns, poison_patterns, give_back_patterns, and low_value_patterns
 * (substring match against "objective target_file", case-insensitive) before a
 * final per-proposal decision is emitted:
 *   admit  — no failing check, no poison match (may match a success pattern).
 *   reject — matches a poison pattern, OR a non-repairable check failed
 *            (duplicate_target / task_fabric_check).
 *   revise — only repairable checks failed (scaffold_compliance and/or evidence_check).
 *   split  — the only failure is scope_breadth_check (target_files count exceeds
 *            split_file_threshold, default 3) — the proposal is too wide to admit
 *            as-is but does not need to be rejected, only broken up.
 * Each decision carries evidence_refs (the proposal's own evidence) and
 * replay_findings (which historical patterns/checks fired during replay).
 *
 * AC4 outputs: accepted_proposals, rejected_proposals, replay_checks,
 *   repairable_proposals, arena_ranking, court_verdict, decisions.
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
        'scope_breadth_check',
        'success_pattern_check',
        'poison_pattern_check',
        'give_back_pattern_check',
        'low_value_pattern_check',
    ];

    private const DEFAULT_SPLIT_FILE_THRESHOLD = 3;

    public const DECISION_ADMIT  = 'admit';
    public const DECISION_REVISE = 'revise';
    public const DECISION_REJECT = 'reject';
    public const DECISION_SPLIT  = 'split';

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
        $splitThreshold  = (int)   ($facts['split_file_threshold'] ?? self::DEFAULT_SPLIT_FILE_THRESHOLD);
        $successPatterns  = array_map('strtolower', is_array($facts['success_patterns']   ?? null) ? $facts['success_patterns']   : []);
        $poisonPatterns   = array_map('strtolower', is_array($facts['poison_patterns']     ?? null) ? $facts['poison_patterns']     : []);
        $giveBackPatterns = array_map('strtolower', is_array($facts['give_back_patterns']  ?? null) ? $facts['give_back_patterns']  : []);
        $lowValuePatterns = array_map('strtolower', is_array($facts['low_value_patterns']  ?? null) ? $facts['low_value_patterns']  : []);

        $accepted    = [];
        $rejected    = [];
        $repairable  = [];
        $decisions   = [];
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

            // 5. Scope breadth check: too many target files to admit as a single task.
            $targetFiles = is_array($proposal['target_files'] ?? null)
                ? $proposal['target_files']
                : ($targetFile !== '' ? [$targetFile] : []);
            $isOverwide = count($targetFiles) > $splitThreshold;
            $checkStats['scope_breadth_check']['checked']++;
            if ($isOverwide) {
                $failures[] = 'scope_breadth_check';
                $checkStats['scope_breadth_check']['failed']++;
            } else {
                $checkStats['scope_breadth_check']['passed']++;
            }

            // 6. Historical replay: compare against success/poison/give_back/low_value patterns.
            $haystack = strtolower($objective.' '.$targetFile);
            $matchedSuccess  = $this->matchesAnyPattern($haystack, $successPatterns);
            $matchedPoison   = $this->matchesAnyPattern($haystack, $poisonPatterns);
            $matchedGiveBack = $this->matchesAnyPattern($haystack, $giveBackPatterns);
            $matchedLowValue = $this->matchesAnyPattern($haystack, $lowValuePatterns);

            $checkStats['success_pattern_check']['checked']++;
            $matchedSuccess ? $checkStats['success_pattern_check']['passed']++ : $checkStats['success_pattern_check']['failed']++;
            $checkStats['poison_pattern_check']['checked']++;
            $matchedPoison ? $checkStats['poison_pattern_check']['failed']++ : $checkStats['poison_pattern_check']['passed']++;
            $checkStats['give_back_pattern_check']['checked']++;
            $matchedGiveBack ? $checkStats['give_back_pattern_check']['failed']++ : $checkStats['give_back_pattern_check']['passed']++;
            $checkStats['low_value_pattern_check']['checked']++;
            $matchedLowValue ? $checkStats['low_value_pattern_check']['failed']++ : $checkStats['low_value_pattern_check']['passed']++;

            if ($matchedPoison) {
                $failures[] = 'poison_pattern_match';
            }

            $replayFindings = [];
            if ($matchedSuccess) {
                $replayFindings[] = 'matches a known success pattern';
            }
            if ($matchedPoison) {
                $replayFindings[] = 'matches a known poison pattern';
            }
            if ($matchedGiveBack) {
                $replayFindings[] = 'matches a known give_back pattern';
            }
            if ($matchedLowValue) {
                $replayFindings[] = 'matches a known low_value pattern';
            }
            if ($isOverwide) {
                $replayFindings[] = sprintf('target_files count (%d) exceeds split threshold (%d)', count($targetFiles), $splitThreshold);
            }
            if ($replayFindings === []) {
                $replayFindings[] = 'no historical pattern match; standard replay checks applied';
            }

            $decisions[] = [
                'id'              => $id,
                'decision'        => $this->assembleCourtDecision($failures, $matchedPoison),
                'evidence_refs'   => array_values($evidence),
                'replay_findings' => $replayFindings,
            ];

            if (empty($failures)) {
                $accepted[] = $id;
                // AC3: arena_score for ranking.
                $evidenceFactor = min(1.0, count($evidence) / self::EVIDENCE_SATURATION);
                $arenaScores[$id] = round(
                    $leverage * 0.30 + $implementability * 0.25 + (1.0 - $risk) * 0.25 + $evidenceFactor * 0.20,
                    4,
                );
            } else {
                $repairHint = $this->repairHint($failures);
                $safeToResubmit = $repairHint !== null;

                $rejected[] = ['id' => $id, 'rejection_reasons' => $failures, 'safe_to_resubmit' => $safeToResubmit];

                if ($repairHint !== null) {
                    $repairable[] = [
                        'id' => $id,
                        'repair_hint' => $repairHint,
                        'repair_synthesis' => $this->repairSynthesis($failures, $minScaffold),
                    ];
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
            'decisions'            => $decisions,
        ];
    }

    /** @param list<string> $failures */
    private function assembleCourtDecision(array $failures, bool $matchedPoison): string
    {
        if ($matchedPoison) {
            return self::DECISION_REJECT;
        }
        if ($failures === []) {
            return self::DECISION_ADMIT;
        }
        if ($failures === ['scope_breadth_check']) {
            return self::DECISION_SPLIT;
        }
        if ($this->repairHint($failures) !== null) {
            return self::DECISION_REVISE;
        }

        return self::DECISION_REJECT;
    }

    /** @param list<string> $patterns */
    private function matchesAnyPattern(string $haystack, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if ($pattern !== '' && str_contains($haystack, $pattern)) {
                return true;
            }
        }

        return false;
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

    /**
     * @param  list<string>  $failures
     * @return array{missing_evidence:list<string>, scaffold_repair_steps:list<string>, acceptance_rewrite_hint:string, safe_to_resubmit:bool}
     */
    private function repairSynthesis(array $failures, float $minScaffold): array
    {
        $missingEvidence = in_array('evidence_check', $failures, true)
            ? ['attach_at_least_one_evidence_reference']
            : [];

        $scaffoldRepairSteps = in_array('scaffold_compliance', $failures, true)
            ? ["raise_scaffold_score_to_at_least_{$minScaffold}"]
            : [];

        $rewriteParts = [];
        if ($scaffoldRepairSteps !== []) {
            $rewriteParts[] = 'raise scaffold score before re-submission';
        }
        if ($missingEvidence !== []) {
            $rewriteParts[] = 'attach runnable evidence before re-submission';
        }

        return [
            'missing_evidence' => $missingEvidence,
            'scaffold_repair_steps' => $scaffoldRepairSteps,
            'acceptance_rewrite_hint' => implode('; ', $rewriteParts),
            'safe_to_resubmit' => true,
        ];
    }

    public const DECISION_REPLAY        = 'replay';
    public const DECISION_KEEP_REJECTED = 'keep_rejected';
    public const DECISION_REWRITE       = 'rewrite';

    /**
     * Decide whether previously-rejected proposals may be replayed now that new
     * evidence, scope changes, or fixed dependencies may have invalidated the
     * original rejection cause. Never replays a proposal whose original cause is
     * still present — that would just reproduce the same waste.
     *
     * @param  array{
     *   rejected_proposals?: list<array{
     *     id?: string, target_file?: string, original_rejection_reasons?: list<string>,
     *     new_evidence?: list<string>, scope_changed?: bool, dependency_fixed?: bool,
     *   }>,
     *   current_queue_targets?: list<string>,
     * }  $facts
     * @return array{schema_version:string, decisions:list<array<string,mixed>>}
     */
    public function replay(array $facts): array
    {
        $proposals    = is_array($facts['rejected_proposals'] ?? null) ? $facts['rejected_proposals'] : [];
        $queueTargets = array_flip(is_array($facts['current_queue_targets'] ?? null) ? $facts['current_queue_targets'] : []);

        $decisions = [];

        foreach ($proposals as $proposal) {
            $id              = (string) ($proposal['id'] ?? '');
            $targetFile      = (string) ($proposal['target_file'] ?? '');
            $originalReasons = is_array($proposal['original_rejection_reasons'] ?? null) ? $proposal['original_rejection_reasons'] : [];
            $newEvidence     = is_array($proposal['new_evidence'] ?? null)
                ? array_filter($proposal['new_evidence'], static fn ($e) => trim((string) $e) !== '')
                : [];
            $scopeChanged     = (bool) ($proposal['scope_changed']     ?? false);
            $dependencyFixed  = (bool) ($proposal['dependency_fixed']  ?? false);

            $stillPresent = [];
            foreach ($originalReasons as $reason) {
                $resolved = match ($reason) {
                    'duplicate_target'    => $targetFile === '' || ! array_key_exists($targetFile, $queueTargets),
                    'evidence_check'      => $newEvidence !== [],
                    'scaffold_compliance' => $dependencyFixed || $scopeChanged,
                    'task_fabric_check'   => $scopeChanged,
                    default                => false, // unknown reason: assume unresolved (fail-closed)
                };
                if (! $resolved) {
                    $stillPresent[] = $reason;
                }
            }

            if ($stillPresent !== []) {
                $decisions[] = [
                    'id'       => $id,
                    'decision' => self::DECISION_KEEP_REJECTED,
                    'reason'   => 'original_rejection_cause_still_present: '.implode(', ', $stillPresent),
                ];
                continue;
            }

            if ($scopeChanged) {
                $decisions[] = [
                    'id'       => $id,
                    'decision' => self::DECISION_REWRITE,
                    'reason'   => 'architecture/scope changed since original proposal; must be reformulated against the new scope, not replayed as-is',
                ];
                continue;
            }

            $decisions[] = [
                'id'       => $id,
                'decision' => self::DECISION_REPLAY,
                'reason'   => 'all original rejection causes resolved by new evidence or fixed dependencies',
            ];
        }

        return [
            'schema_version' => self::SCHEMA,
            'decisions'      => $decisions,
        ];
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
