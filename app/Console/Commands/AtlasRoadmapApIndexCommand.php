<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasRoadmapApIndexService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atlas AI Kernel Roadmap AP Index CLI.
 *
 *   php artisan atlas:aaeos:roadmap-ap-index [--json]
 *
 * Read-only, deterministic. Emits the closed Phase Map, routes a sample AP to
 * its active family, and runs the seven-condition AP Acceptance gate. The safe
 * default models an AP that has met every acceptance condition WITH the quality
 * gate green and knowledge refreshed, so it is accepted as done.
 *
 * @see docs/engineering-knowledge-base/kernel/roadmap-ap-index.md
 */
class AtlasRoadmapApIndexCommand extends Command
{
    protected $signature = 'atlas:aaeos:roadmap-ap-index {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas AI Kernel · roadmap AP index (Phase Map, AP family routing, AP Acceptance gate).';

    public function handle(AtlasRoadmapApIndexService $service): int
    {
        try {
            // Safe default: an AP whose entire acceptance checklist is satisfied,
            // including a green docs-health/architecture gate and refreshed
            // knowledge, so readiness is backed by verifiable evidence.
            $signals = [
                'contract_documented' => true,
                'artifact_exists_when_needed' => true,
                'events_and_read_models_declared' => true,
                'surfaces_aligned_when_applicable' => true,
                'tests_happy_and_guard_path' => true,
                'gates_run' => true,
                'knowledge_refreshed' => true,
            ];

            $acceptance = $service->evaluateAcceptance($signals);
            $route = $service->routeAp('AP-99');

            $payload = [
                'ok' => true,
                'phase_map' => $service->phaseMap(),
                'sample_route' => $route,
                'acceptance' => $acceptance,
            ];

            $this->line((string) json_encode(
                $payload,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));

            return $acceptance['done'] ? self::SUCCESS : self::FAILURE;
        } catch (Throwable $e) {
            $this->line((string) json_encode(
                [
                    'ok' => false,
                    'error' => $e->getMessage(),
                ],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));

            return self::FAILURE;
        }
    }
}
