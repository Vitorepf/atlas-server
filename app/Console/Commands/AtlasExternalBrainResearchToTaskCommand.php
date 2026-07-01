<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainLocalResearchFrontierTriageEngine;
use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainResearchToTaskDigestor;
use Illuminate\Console\Command;

/**
 * Read-only research-to-task converter. Runs bounded research/frontier rows
 * through {@see AtlasExternalBrainLocalResearchFrontierTriageEngine} (hype,
 * ungrounded, provider-dependent and high-risk rejection) first, then only
 * the rows the triage engine marks "promising" continue on to
 * {@see AtlasExternalBrainResearchToTaskDigestor} (target-path, runnable-
 * acceptance and completeness rejection) to become full task candidates.
 * Never enqueues, mutates the queue, or calls a provider.
 *
 * Input: a single JSON file (--input=PATH) with keys:
 *   { frontier_rows?:list, research_items?:list }
 * frontier_rows entries carry BOTH the triage fields (evidence_strength,
 * has_code, hype_signals, atlas_fit_score, implementation_risk, ...) and the
 * digestor fields (atlas_failure_mode, target_path, adaptation_notes,
 * allowed_files, test_path, anti_goodhart_risks, runnable_acceptance, ...) —
 * only rows the triage engine promotes to "promising" are handed to the
 * digestor. research_items entries skip triage and go straight to the
 * digestor (already-vetted research, not raw frontier capture).
 */
final class AtlasExternalBrainResearchToTaskCommand extends Command
{
    /** @var string */
    protected $signature = 'atlas:external-brain:research-to-task
        {--input= : Path to a JSON file with frontier_rows and/or research_items}';

    /** @var string */
    protected $description = 'Read-only research/frontier-digest to task-candidate converter (triage + digest, blocks hype/provider-dependent/non-runnable ideas).';

    public function handle(
        AtlasExternalBrainLocalResearchFrontierTriageEngine $triageEngine,
        AtlasExternalBrainResearchToTaskDigestor $digestor,
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

        $frontierRows = is_array($decoded['frontier_rows'] ?? null) ? $decoded['frontier_rows'] : [];
        $directResearchItems = is_array($decoded['research_items'] ?? null) ? $decoded['research_items'] : [];

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
            'rejected_by_digestor' => $digest['rejected'],
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

        $this->line((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        return self::SUCCESS;
    }
}
