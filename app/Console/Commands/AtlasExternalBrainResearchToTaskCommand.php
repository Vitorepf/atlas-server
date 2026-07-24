<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainLocalResearchFrontierTriageEngine;
use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainResearchDigestGrounder;
use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainResearchSourceTrustRanker;
use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainResearchToTaskDigestor;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

/**
 * Read-only research-to-task converter. Runs bounded research/frontier rows
 * through {@see AtlasExternalBrainResearchSourceTrustRanker} (source-trust
 * gate — rejects rows the ranker marks "reject" before anything else runs),
 * then {@see AtlasExternalBrainLocalResearchFrontierTriageEngine} (hype,
 * ungrounded, provider-dependent and high-risk rejection), then only the
 * rows the triage engine marks "promising" continue on to
 * {@see AtlasExternalBrainResearchToTaskDigestor} (target-path, runnable-
 * acceptance and completeness rejection) to become full task candidates.
 * A separate, additive pathway for raw research_ideas runs through
 * {@see AtlasExternalBrainResearchDigestGrounder} (local-symbol grounding)
 * to become grounded task candidates. Never enqueues, mutates the queue, or
 * calls a provider.
 *
 * Input: a single JSON file (--input=PATH) with keys:
 *   { frontier_rows?:list, research_items?:list, research_ideas?:list }
 * frontier_rows entries carry the trust-ranker fields (source_type,
 * has_concrete_claim, source_date, has_source_url, is_hype_heavy,
 * grounding, as_of), the triage fields (evidence_strength, has_code,
 * hype_signals, atlas_fit_score, implementation_risk, ...) and the digestor
 * fields (atlas_failure_mode, target_path, adaptation_notes, allowed_files,
 * test_path, anti_goodhart_risks, runnable_acceptance, source_type, ...) —
 * only rows the trust ranker admits AND the triage engine promotes to
 * "promising" are handed to the digestor. research_items entries skip trust
 * ranking and triage and go straight to the digestor (already-vetted
 * research, not raw frontier capture). research_ideas entries go through
 * the grounder instead — a separate Atlas-native-pattern grounding path.
 */
final class AtlasExternalBrainResearchToTaskCommand extends Command
{
    use EmitsCanonicalJson;

    /** @var string */
    protected $signature = 'atlas:external-brain:research-to-task
        {--input= : Path to a JSON file with frontier_rows, research_items, and/or research_ideas}';

    /** @var string */
    protected $description = 'Read-only research/frontier-digest to task-candidate converter (trust-rank + triage + digest + ground, blocks hype/provider-dependent/non-runnable ideas).';

    public function handle(
        AtlasExternalBrainResearchSourceTrustRanker $trustRanker,
        AtlasExternalBrainLocalResearchFrontierTriageEngine $triageEngine,
        AtlasExternalBrainResearchToTaskDigestor $digestor,
        AtlasExternalBrainResearchDigestGrounder $grounder,
    ): int {
        $inputPath = trim((string) $this->option('input'));
        if ($inputPath === '' || ! is_file($inputPath)) {
            $this->error('--input=<path> required and must exist');

            return self::FAILURE;
        }

        $decoded = json_decode((string) file_get_contents($inputPath), true);
        if (! is_array($decoded)) {
            $this->error('invalid input JSON');

            return self::FAILURE;
        }

        $rawFrontierRows = is_array($decoded['frontier_rows'] ?? null) ? $decoded['frontier_rows'] : [];
        $directResearchItems = is_array($decoded['research_items'] ?? null) ? $decoded['research_items'] : [];
        $researchIdeas = is_array($decoded['research_ideas'] ?? null) ? $decoded['research_ideas'] : [];

        $trustRejected = [];
        $frontierRows = [];
        foreach ($rawFrontierRows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $trust = $trustRanker->rank($row);
            if ($trust['use_decision'] === AtlasExternalBrainResearchSourceTrustRanker::USE_REJECT) {
                $trustRejected[] = ['id' => (string) ($row['id'] ?? ''), 'trust' => $trust];

                continue;
            }
            $frontierRows[] = $row;
        }

        $groundResult = $grounder->ground(['research_ideas' => $researchIdeas]);

        $triage = $triageEngine->triage(['frontier_rows' => $frontierRows]);

        $promisingIds = array_column($triage['promising'], 'id');
        $promotedFromFrontier = array_values(array_filter(
            $frontierRows,
            static fn (mixed $row): bool => is_array($row) && in_array((string) ($row['id'] ?? ''), $promisingIds, true),
        ));

        $digest = $digestor->digest([
            'research_items' => array_merge($promotedFromFrontier, $directResearchItems),
        ]);

        $payload = [
            'status' => 'ok',
            'promoted_task_candidates' => $digest['promoted'],
            'promoted_count' => $digest['promoted_count'],
            'rejected_by_trust_ranker' => $trustRejected,
            'rejected_by_digestor' => $digest['rejected'],
            'grounded_task_candidates' => $groundResult['task_candidates'],
            'grounded_rejected' => $groundResult['rejected'],
            'grounded_held_for_research' => $groundResult['held_for_research'],
            'rejected_by_triage' => [
                'hype_rejected' => $triage['hype_rejected'],
                'ungrounded_rejected' => $triage['ungrounded_rejected'],
                'provider_dependent_rejected' => $triage['provider_dependent_rejected'],
                'high_risk_rejected' => $triage['high_risk_rejected'],
                'no_atlas_fit_rejected' => $triage['no_atlas_fit_rejected'],
                'hold_for_review' => $triage['hold_for_review'],
            ],
            'exploratory' => $triage['exploratory'],
            'next_research_action' => $triage['next_research_action'],
            'leverage_rank' => $triage['leverage_rank'],
            'duplicate_family_warnings' => $triage['duplicate_family_warnings'],
        ];

        $this->line($this->encode($payload));

        return self::SUCCESS;
    }
}
