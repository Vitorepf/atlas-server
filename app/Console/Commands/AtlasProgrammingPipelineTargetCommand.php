<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasProgrammingPipelineTargetService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atlas Programming Pipeline Target decider CLI.
 *
 *   php artisan atlas:aaeos:programming-pipeline-target
 *     [--prompt="fix the failing migration"] [--surface=dev]
 *     [--risk=medium] [--harness] [--json]
 *
 * Read-only, deterministic. Runs the unified pipeline decision surface for one
 * raw request: classify intent, select executor (simple_provider |
 * dev_repair_executor | engineering_harness), pick the quality-matrix gates and
 * normalize the surface onto the single Programming pipeline (flagging an
 * unknown surface as a bypass). No provider call, no gate run, no DB.
 *
 * @see docs/engineering-knowledge-base/architecture-audit/programming-pipeline-target.md
 */
class AtlasProgrammingPipelineTargetCommand extends Command
{
    protected $signature = 'atlas:aaeos:programming-pipeline-target
        {--prompt= : raw programming request to classify and route}
        {--surface=dev : surface name (dev, forge, fix, continue, app, workers)}
        {--risk=medium : risk level (low, medium, high, critical)}
        {--harness : force the engineering harness lane}
        {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas Programming Pipeline Target · classify intent, select executor, pick quality gates and route a surface onto the unified Programming pipeline.';

    public function handle(AtlasProgrammingPipelineTargetService $service): int
    {
        try {
            $prompt = (string) ($this->option('prompt') ?: 'implement a new feature');
            $surface = (string) ($this->option('surface') ?: 'dev');
            $risk = (string) ($this->option('risk') ?: 'medium');

            $payload = [
                'ok' => true,
                'plan' => $service->plan($prompt, $surface, [
                    'risk' => $risk,
                    'harness_required' => (bool) $this->option('harness'),
                ]),
            ];

            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'programming_pipeline_target_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}
