<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasDevEfficientProgrammingFlowRunbookPart09Service;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atlas Dev Efficient Programming Flow Runbook v1 · Parte 9 — operator gate CLI.
 *
 *   php artisan atlas:aaeos:atlas-dev-efficient-programming-flow-runbook-part09 [--json]
 *
 * Read-only, deterministic. Exercises the three operator decision contracts the
 * runbook slice declares (15.1.10 release checklist, 15.1.12 project profile
 * resolution, 15.1.15 visual QA) against known-good inputs and emits the
 * verdicts plus the contract manifest as JSON.
 *
 * @see docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-runbook-v1-part-09.md
 */
class AtlasDevEfficientProgrammingFlowRunbookPart09Command extends Command
{
    protected $signature = 'atlas:aaeos:atlas-dev-efficient-programming-flow-runbook-part09 {--json}';

    protected $description = 'Atlas Dev flow runbook (Parte 9) · evaluate release checklist, project-slug resolution and visual-QA gate, then emit the manifest.';

    public function handle(AtlasDevEfficientProgrammingFlowRunbookPart09Service $service): int
    {
        try {
            // 15.1.10 — a fully confirmed mandatory checklist with run sequenced
            // after plan validation is releasable.
            $release = $service->evaluateReleaseChecklist([
                'app_key_base64_min_32_bytes' => true,
                'migrations_applied' => true,
                'readiness_passed' => true,
                'smoke_no_absolute_path_leak' => true,
                'plan_enabled_validated_before_run_enabled' => true,
                'run_enabled' => true,
            ]);

            // 15.1.12 — a Desktop request resolving a slug whose workspace exists.
            $resolution = $service->resolveProjectWorkspace(
                ['surface_id' => 'atlas_desktop_ai', 'project_slug' => 'atlas-server'],
                ['atlas-server' => ['workspace_path' => '/Users/op/code/atlas-server', 'workspace_exists' => true]],
                'atlas-server',
            );

            // 15.1.15 — a frontend change needing visual QA with no browser tool.
            $visual = $service->evaluateVisualQa([
                'is_frontend' => true,
                'visual_qa_required' => true,
                'browser_tooling_available' => false,
                'proposed_verdict' => 'passed',
                'declared_risk_level' => 'R1',
            ]);

            $payload = [
                'ok' => true,
                'manifest' => $service->manifest(),
                'release_checklist' => $release,
                'project_resolution' => $resolution,
                'visual_qa' => $visual,
            ];

            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'atlas_dev_flow_runbook_part09_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}
