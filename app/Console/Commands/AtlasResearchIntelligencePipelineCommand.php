<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasResearchIntelligencePipelineService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atlas AI Research Intelligence Pipeline decider CLI.
 *
 *   php artisan atlas:aaeos:research-intelligence-pipeline [--json]
 *
 * Read-only, deterministic. Runs the four documented pipeline contracts over a
 * worked example (a fully advanced pipeline whose packet still carries one
 * unresolved conflict) and emits the per-contract verdicts plus the terminal
 * disposition. The conflict forces a research_more disposition, proving the
 * pipeline preserves conflicting evidence instead of promoting over it. No doc
 * is written, no code is applied, no tool is run.
 *
 * @see docs/engineering-knowledge-base/research-self-improvement/research-pipeline.md
 */
class AtlasResearchIntelligencePipelineCommand extends Command
{
    protected $signature = 'atlas:aaeos:research-intelligence-pipeline
        {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas research · intelligence pipeline (ordering, discovery mix, output quality, promote/hold/archive/research_more).';

    public function handle(AtlasResearchIntelligencePipelineService $service): int
    {
        try {
            $result = $service->runPipeline(
                // All 8 steps complete => the pipeline is ready to decide.
                AtlasResearchIntelligencePipelineService::STEPS,
                // Balanced discovery: repo anchor + official doc, community as lead.
                ['repo', 'official_doc', 'community'],
                // High-quality output: all required good signals, no bad signals.
                ['specific', 'source_backed', 'conflict_aware', 'time_aware', 'actionable', 'bounded'],
                // Packet with real impact but ONE unresolved conflict => must NOT promote.
                [
                    'schema_version' => AtlasResearchIntelligencePipelineService::PACKET_SCHEMA,
                    'objective' => 'demo',
                    'question' => 'demo',
                    'atlas_impact' => ['touches research docs'],
                    'conflicts' => [['id' => 'c1', 'resolved' => false]],
                    'uncertainties' => [],
                    'recommended_action' => 'promote_to_doc',
                    'created_at' => '2026-06-01T00:00:00Z',
                ],
            );

            $this->line((string) json_encode([
                'ok' => true,
                'pipeline' => $result,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'research_intelligence_pipeline_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}
