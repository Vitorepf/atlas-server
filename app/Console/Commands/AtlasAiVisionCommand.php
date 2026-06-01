<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasAiVisionService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atlas AI Vision contract validator CLI.
 *
 *   php artisan atlas:aaeos:ai-vision [--json]
 *
 * With no options it emits the founding structure + canonical pipeline and runs
 * the placement / surface / evidence / channel audits against representative
 * inputs (including one intentional placement violation) so the doc's rules are
 * exercised. Read-only, deterministic, DB-free.
 *
 * @see docs/engineering-knowledge-base/atlas-ai-vision.md
 */
class AtlasAiVisionCommand extends Command
{
    protected $signature = 'atlas:aaeos:ai-vision {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas AAEOS · validate placement, pipeline, surface and channel rules against the Atlas AI Vision spec.';

    public function handle(AtlasAiVisionService $service): int
    {
        try {
            $result = [
                'vision' => $service->vision(),
                'pipeline_canonical' => $service->validatePipeline([
                    'stages' => AtlasAiVisionService::CANONICAL_PIPELINE,
                ]),
                // A horizontal Core capability someone tried to bury in a surface.
                'placement_violation' => $service->classifyPlacement([
                    'capability' => 'context',
                    'surfaces_served' => ['cli', 'app'],
                    'requested_placement' => 'surface',
                ]),
                'surface_audit' => $service->auditSurface([
                    'surface' => 'cli',
                    'owns_business_logic' => false,
                ]),
                'success_without_evidence' => $service->canDeclareSuccess([
                    'claims_success' => true,
                    'evidence' => [],
                ]),
                'direct_provider_call' => $service->auditChannel([
                    'provider' => 'claude',
                    'routed_through_atlas' => false,
                ]),
            ];

            $this->line((string) json_encode(
                ['ok' => true, 'result' => $result],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'ai_vision_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}
