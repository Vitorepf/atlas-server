<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainOriginatorBatchValueAuditor;
use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainOriginatorSpecNoveltyGate;
use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainOriginatorStopOrPivotAdvisor;
use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainOriginatorThemeSaturationMeter;
use Illuminate\Console\Command;

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

        $payload = [
            'schema' => self::SCHEMA,
            'next_action' => $verdict['next_action'],
            'reasons' => $verdict['reasons'],
            'evidence' => $verdict['evidence'],
            'theme_saturation' => $themeSaturation,
            'batch_value' => $batchValue,
            'candidate_novelty' => $candidateNovelty,
        ];

        $this->line((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        return self::SUCCESS;
    }

    /** @return array<string,mixed> */
    private function loadFacts(): array
    {
        $path = trim((string) $this->option('facts-file'));
        if ($path === '' || ! is_file($path) || ! is_readable($path)) {
            return [];
        }
        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : [];
    }
}
