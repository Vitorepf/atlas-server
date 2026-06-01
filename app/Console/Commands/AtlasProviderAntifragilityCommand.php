<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasProviderAntifragilityService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Operator entry point for the Provider Antifragility thesis decider.
 *
 * Demonstrates the thesis contracts on safe defaults: threat determination
 * (a release is a threat only when Atlas is wrapper-positioned), the five-step
 * ingestion pipeline and positioning, the obsolescence rule (convert or remove,
 * never keep as duplicate), structural-moat assessment, and residual-threat
 * handling (mitigated, never eliminated).
 *
 * @see docs/engineering-knowledge-base/thesis/provider-antifragility.md
 */
final class AtlasProviderAntifragilityCommand extends Command
{
    protected $signature = 'atlas:aaeos:provider-antifragility {--json : Machine-readable JSON output}';

    protected $description = 'Decide Provider Antifragility thesis rules: release threat classification, five-step ingestion, obsolescence disposition, structural moats and residual threats.';

    public function handle(AtlasProviderAntifragilityService $service): int
    {
        try {
            $result = [
                'release_when_wrapper_positioned' => $service->classifyRelease(true),
                'release_when_above_provider' => $service->classifyRelease(false),
                'position_as_driver' => $service->positionRelease('driver'),
                'position_rejected' => $service->positionRelease('rejected_backlog'),
                'ingestion_in_progress' => $service->ingestionProgress(['catalog', 'compare']),
                'ingestion_complete' => $service->ingestionProgress(
                    AtlasProviderAntifragilityService::INGESTION_STEPS
                ),
                'obsolete_with_value' => $service->resolveObsoleteComponent('legacy-router', true, true),
                'obsolete_without_value' => $service->resolveObsoleteComponent('legacy-router', true, false),
                'moat_attacked' => $service->assessMoat('sovereign_user_owned_memory'),
                'raw_intelligence_attacked' => $service->assessMoat('raw_model_reasoning'),
                'residual_threat' => $service->handleResidualThreat('os_vendor_embedded_assistant'),
            ];
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'exception',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }

        $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
