<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\LoadsFactsFileOption;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainAutonomyClaimAuditor;
use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainOriginatorBatchValueAuditor;
use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainOriginatorSpecNoveltyGate;
use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainOriginatorStopOrPivotAdvisor;
use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainOriginatorThemeSaturationMeter;
use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainPostCommitNoGapRunner;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

/**
 * Read-only operator surface: atlas:external-brain:originator-stop-pivot
 *
 * Composes four already-pure ExternalBrain organs into one anti-template-farm
 * origination verdict, so the originator knows whether to keep going, pivot
 * to a real gap, consolidate, or stop and research instead of padding an
 * already-sufficient queue:
 *
 *   - {@see AtlasExternalBrainOriginatorStopOrPivotAdvisor}   — the composite next_action verdict
 *     (internally composes batch-value + theme-saturation + impact-diversity)
 *   - {@see AtlasExternalBrainOriginatorThemeSaturationMeter} — standalone theme-saturation reading
 *   - {@see AtlasExternalBrainOriginatorBatchValueAuditor}    — standalone recent-batch-value reading
 *   - {@see AtlasExternalBrainOriginatorSpecNoveltyGate}      — novelty check on the NEXT candidate
 *     spec about to be originated (the advisor itself never checks the next candidate)
 *
 * Pure composition: no enqueue, no queue mutation, no provider calls. All
 * facts are supplied via a JSON facts file.
 *
 * Output (always JSON):
 *   schema, next_action, reasons, evidence, theme_saturation, batch_value,
 *   candidate_novelty
 */
final class AtlasExternalBrainOriginatorStopPivotCommand extends Command
{
    use LoadsFactsFileOption;

    use EmitsCanonicalJson;

    private const SCHEMA = 'atlas.external_brain.originator_stop_pivot.v1';

    /** @var string */
    protected $signature = 'atlas:external-brain:originator-stop-pivot
        {--facts-file= : Path to a JSON facts file (see AtlasExternalBrainOriginatorStopOrPivotAdvisor::advise() input shape)}
        {--json : Emit JSON output (always on)}';

    /** @var string */
    protected $description = 'Read-only: compose stop/pivot advisor, theme saturation, batch value, and spec novelty into one origination verdict.';

    public function handle(): int
    {
        $facts = $this->loadFacts();

        $advisor = new AtlasExternalBrainOriginatorStopOrPivotAdvisor;
        $themeSaturationMeter = new AtlasExternalBrainOriginatorThemeSaturationMeter;
        $batchValueAuditor = new AtlasExternalBrainOriginatorBatchValueAuditor;
        $specNoveltyGate = new AtlasExternalBrainOriginatorSpecNoveltyGate;
        $autonomyClaimAuditor = new AtlasExternalBrainAutonomyClaimAuditor;

        $verdict = $advisor->advise($facts);

        $themeSaturation = $themeSaturationMeter->measure(
            is_array($facts['theme_recent_tasks'] ?? null) ? $facts['theme_recent_tasks'] : [],
            is_array($facts['theme_context'] ?? null) ? $facts['theme_context'] : [],
        );
        $batchValue = $batchValueAuditor->audit(['tasks' => $facts['recent_batch_tasks'] ?? []]);

        // Novelty of the NEXT candidate about to be originated — distinct from the advisor's
        // verdict, which only reasons about the recent batch and existing queue state.
        $candidateNovelty = $specNoveltyGate->evaluate([
            'candidates' => $facts['next_candidates'] ?? [],
            'queued_targets' => $facts['queued_targets'] ?? [],
            'recent_authored_specs' => $facts['recent_authored_specs'] ?? [],
            'existing_class_names' => $facts['existing_class_names'] ?? [],
        ]);

        // Audit any autonomy claims supplied alongside the origination facts (e.g. "queue healthy",
        // "24/7 autonomous") against concrete evidence rather than accepting them at face value.
        $autonomyClaimAudits = [];
        foreach ((array) ($facts['autonomy_claims'] ?? []) as $claim) {
            if (is_array($claim)) {
                $autonomyClaimAudits[] = $autonomyClaimAuditor->audit($claim);
            }
        }

        // Post-commit no-gap verdict: composes wave resequencer, compression auditor,
        // originator gate, and end-to-end proof into one gap-free assessment.
        $noGapRunner = new AtlasExternalBrainPostCommitNoGapRunner;
        $postCommitNoGap = $noGapRunner->run([
            'resequence_facts'    => is_array($facts['resequence_facts'] ?? null) ? $facts['resequence_facts'] : [],
            'compression_facts'   => is_array($facts['compression_facts'] ?? null) ? $facts['compression_facts'] : [],
            'originator_proposal' => is_array($facts['originator_proposal'] ?? null) ? $facts['originator_proposal'] : [],
            'proof_facts'         => is_array($facts['proof_facts'] ?? null) ? $facts['proof_facts'] : [],
        ]);

        $payload = [
            'schema' => self::SCHEMA,
            'next_action' => $verdict['next_action'],
            'reasons' => $verdict['reasons'],
            'evidence' => $verdict['evidence'],
            'theme_saturation' => $themeSaturation,
            'batch_value' => $batchValue,
            'candidate_novelty' => $candidateNovelty,
            'autonomy_claim_audits' => $autonomyClaimAudits,
            'post_commit_no_gap' => $postCommitNoGap,
        ];

        $this->line($this->encode($payload));

        return self::SUCCESS;
    }

    /** @return array<string,mixed> */
}
