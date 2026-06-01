<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasResearchOperatingSystemService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atlas AI Research Operating System governor CLI.
 *
 *   php artisan atlas:aaeos:research-operating-system [--json]
 *
 * Read-only, deterministic. Validates a sample research run against the ordered
 * pipeline, enforces the Core Rule on a sample claim set, and evaluates a sample
 * Implementation Phase against the required gates. Emits a single auditable
 * decision envelope.
 *
 * @see docs/engineering-knowledge-base/research-self-improvement/research-operating-system.md
 */
class AtlasResearchOperatingSystemCommand extends Command
{
    protected $signature = 'atlas:aaeos:research-operating-system {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas research OS · pipeline, core-rule and phase-gate governor.';

    public function handle(AtlasResearchOperatingSystemService $service): int
    {
        try {
            // Safe defaults: a clean, fully-traversed pipeline.
            $pipeline = $service->validatePipeline(AtlasResearchOperatingSystemService::PIPELINE);

            // Core Rule on a fully-supported sample claim set.
            $report = $service->gateReport([
                ['verified' => true, 'has_evidence' => true, 'citation_healthy' => true],
            ]);

            // A complete phase satisfying all required gates.
            $phase = $service->evaluatePhase([
                'phase' => 3,
                'gates' => ['evidence' => true, 'citation_health' => true, 'promotion' => true],
            ]);

            $this->line((string) json_encode([
                'ok' => true,
                'schema' => AtlasResearchOperatingSystemService::SCHEMA_VERSION,
                'pipeline' => $pipeline,
                'report_gate' => $report,
                'phase_gate' => $phase,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'research_operating_system_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}
