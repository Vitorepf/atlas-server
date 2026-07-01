<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainAcceptanceReplayCoverageMatrix;
use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainAdversarialCritiqueTournament;
use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainAmplifierComplexityBudget;
use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainAmplifierControlPlane;
use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainAmplifierEndToEndTrial;
use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainAmplifierFallbackRunbook;
use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainAutonomyDependencyInverter;
use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainHighValueBatchComposer;
use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainLeverageScorer;
use Illuminate\Console\Command;

/**
 * Read-only originator-quality runtime. Pipes candidate opportunities
 * through four pure gates, in order:
 *
 *   1. {@see AtlasExternalBrainAdversarialCritiqueTournament} — five+ critique
 *      lenses reject proxy work, operator dependency, duplicate/overwide
 *      targets and template-farm shape BEFORE anything is scored.
 *   2. {@see AtlasExternalBrainLeverageScorer} — survivors are ranked by
 *      compounding leverage, not ease.
 *   3. {@see AtlasExternalBrainAcceptanceReplayCoverageMatrix} — each ranked
 *      candidate must carry a runnable command, real implementation-file
 *      coverage, and evidence for any claimed leverage dimension.
 *   4. {@see AtlasExternalBrainHighValueBatchComposer} — the fully-vetted,
 *      ranked survivors are composed into one bounded, wave-ordered batch
 *      instead of quota padding.
 *
 * Never enqueues, mutates the queue, or calls a provider.
 *
 * Input: a single JSON file (--input=PATH) with keys:
 *   { opportunities:list, max_batch?:int }
 * Each opportunity entry carries fields for ALL four stages simultaneously
 * (objective, allowed_files, acceptance_criteria, required_evidence,
 * value_mechanism, category, label, evidence_refs, plus the leverage-scoring
 * dimensions) — each stage reads only the keys it understands.
 */
final class AtlasExternalBrainOriginatorQualityCommand extends Command
{
    /** @var string */
    protected $signature = 'atlas:external-brain:originator-quality
        {--input= : Path to a JSON file with opportunities and optional max_batch}';

    /** @var string */
    protected $description = 'Read-only originator-quality runtime: critique → leverage-rank → coverage-audit → bounded high-value batch.';

    public function handle(
        AtlasExternalBrainAdversarialCritiqueTournament $tournament,
        AtlasExternalBrainLeverageScorer $scorer,
        AtlasExternalBrainAcceptanceReplayCoverageMatrix $coverageMatrix,
        AtlasExternalBrainHighValueBatchComposer $batchComposer,
        AtlasExternalBrainAmplifierComplexityBudget $complexityBudget,
        AtlasExternalBrainAmplifierControlPlane $amplifierControlPlane,
        AtlasExternalBrainAmplifierEndToEndTrial $endToEndTrial,
        AtlasExternalBrainAmplifierFallbackRunbook $fallbackRunbook,
        AtlasExternalBrainAutonomyDependencyInverter $dependencyInverter,
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

        $opportunities = is_array($decoded['opportunities'] ?? null) ? array_values($decoded['opportunities']) : [];
        $maxBatch = isset($decoded['max_batch']) ? (int) $decoded['max_batch'] : AtlasExternalBrainHighValueBatchComposer::DEFAULT_MAX_BATCH;

        $critique = $tournament->run(['packets' => $opportunities]);

        $blockedIndices = [];
        foreach ($critique['blocking_findings'] as $finding) {
            if (isset($finding['packet_index'])) {
                $blockedIndices[(int) $finding['packet_index']] = true;
            }
            foreach ((array) ($finding['packet_indices'] ?? []) as $idx) {
                $blockedIndices[(int) $idx] = true;
            }
        }

        $survivors = [];
        foreach ($opportunities as $i => $opp) {
            if (! isset($blockedIndices[$i]) && is_array($opp)) {
                $survivors[] = $opp;
            }
        }

        // rank() returns scoring fields only (label, final_score, ...) — merge the original
        // opportunity fields back in by label so downstream stages still see objective,
        // allowed_files, acceptance_criteria, required_evidence, value_mechanism, category.
        $survivorsByLabel = [];
        foreach ($survivors as $opp) {
            $survivorsByLabel[(string) ($opp['label'] ?? '')] = $opp;
        }
        $ranked = array_map(
            static fn (array $scored): array => array_merge($survivorsByLabel[$scored['label']] ?? [], $scored),
            $scorer->rank($survivors),
        );

        $coverageRejections = [];
        $coverageApproved = [];
        foreach ($ranked as $opp) {
            $audit = $coverageMatrix->audit($opp);
            if ($audit['verdict'] === AtlasExternalBrainAcceptanceReplayCoverageMatrix::VERDICT_REJECTED) {
                $coverageRejections[] = [
                    'label' => $opp['label'] ?? '',
                    'rejections' => $audit['rejections'],
                ];

                continue;
            }
            $coverageApproved[] = $opp;
        }

        $batch = $batchComposer->compose($coverageApproved, ['max_batch' => $maxBatch]);

        $payload = [
            'status' => 'ok',
            'critique_blocking' => $critique['blocking'],
            'critique_winning_attack' => $critique['winning_attack'],
            'critique_blocking_findings' => $critique['blocking_findings'],
            'critique_rejected_count' => count($blockedIndices),
            'leverage_ranked_count' => count($ranked),
            'coverage_rejections' => $coverageRejections,
            'emitted_batch' => $batch['emitted'],
            'batch_rejected' => $batch['rejected'],
            'batch_stats' => $batch['stats'],
            'batch_thesis' => $batch['batch_thesis'],
            'wave_plan' => $batch['wave_plan'],
        ];

        // Optional system-wide amplifier complexity-budget check: this is a distinct
        // concern from per-opportunity coverage auditing above (component/gate/judge/
        // telemetry budgets vs. per-candidate acceptance replay), so it is only run
        // when the caller explicitly supplies a complexity_budget section.
        if (is_array($decoded['complexity_budget'] ?? null)) {
            $payload['complexity_budget'] = $complexityBudget->evaluate($decoded['complexity_budget']);
        }

        // Optional amplifier control-plane mode decision: a distinct concern from the
        // complexity budget above (origination mode selection vs. system-wide budget),
        // so it is only run when the caller explicitly supplies an amplifier_control_plane section.
        if (is_array($decoded['amplifier_control_plane'] ?? null)) {
            $payload['amplifier_control_plane'] = $amplifierControlPlane->decide($decoded['amplifier_control_plane']);
        }

        // Optional amplifier end-to-end trial: benchmarks small/scaffolded/frontier tiers
        // against supplied facts and recommends a tier. Distinct from the mode decision
        // above, so it only runs when the caller explicitly supplies an
        // amplifier_end_to_end_trial section.
        if (is_array($decoded['amplifier_end_to_end_trial'] ?? null)) {
            $payload['amplifier_end_to_end_trial'] = $endToEndTrial->run($decoded['amplifier_end_to_end_trial']);
        }

        // Optional amplifier fallback runbook: compiles the strengthening-step sequence and
        // escalation decision for a small-model run. Distinct from the tier trial above, so
        // it only runs when the caller explicitly supplies an amplifier_fallback_runbook section.
        if (is_array($decoded['amplifier_fallback_runbook'] ?? null)) {
            $payload['amplifier_fallback_runbook'] = $fallbackRunbook->compile($decoded['amplifier_fallback_runbook']);
        }

        // Optional autonomy dependency inversion: proposes Atlas-native replacements for
        // human/operator/provider dependencies still active in steady state. Distinct from
        // the fallback runbook above, so it only runs when the caller explicitly supplies an
        // autonomy_dependency_inversion section.
        if (is_array($decoded['autonomy_dependency_inversion'] ?? null)) {
            $payload['autonomy_dependency_inversion'] = $dependencyInverter->invert($decoded['autonomy_dependency_inversion']);
        }

        $this->line((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        return self::SUCCESS;
    }
}
