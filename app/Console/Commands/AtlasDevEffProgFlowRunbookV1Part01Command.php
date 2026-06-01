<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasDevEffProgFlowRunbookV1Part01Service;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atlas Dev Efficient Programming Flow Runbook v1 · Parte 1 — orchestration CLI.
 *
 *   php artisan atlas:aaeos:atlas-dev-eff-prog-flow-runbook-v1-part01 [--json]
 *
 * Read-only, deterministic. Exercises the orchestration invariants the runbook
 * embeds above the slices (rigid slice order, the efficient-flow activation gate,
 * the production rollout order plan→run, the vertical-delivery milestones and the
 * reuse-mandatory contract) against known inputs and emits the verdicts plus the
 * contract manifest as JSON.
 *
 * @see docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-runbook-v1-part-01.md
 */
class AtlasDevEffProgFlowRunbookV1Part01Command extends Command
{
    protected $signature = 'atlas:aaeos:atlas-dev-eff-prog-flow-runbook-v1-part01 {--json}';

    protected $description = 'Atlas Dev flow runbook (Parte 1) · evaluate slice order, flow activation gate, rollout order, delivery milestones and the reuse contract, then emit the manifest.';

    public function handle(AtlasDevEffProgFlowRunbookV1Part01Service $service): int
    {
        try {
            // §1 — after Fatia 1 with a green DoD, the next legal slice is 1.5.
            $nextSlice = $service->nextSlice('1', true);

            // §1 — a red DoD blocks advancing.
            $blockedSlice = $service->nextSlice('2', false);

            // §1 activation gate — plan on, workspace present, surface supported -> plan-only efficient.
            $flow = $service->resolveFlow(true, false, true, true);

            // §1 rollout — in prod (both false) enabling run before plan is illegal.
            $rollout = $service->evaluateRolloutToggle(
                ['plan_enabled' => false, 'run_enabled' => false],
                'run_enabled',
                true,
            );

            // §1.1 — Marco 2 needs slices 0,1,1.5,2 green.
            $milestone = $service->evaluateMilestone(2, ['0', '1', '1.5']);

            // §3 — a V2 twin of the driver is rejected.
            $newService = $service->evaluateNewService('AtlasDevWorkflowServiceV2', null, true);

            $payload = [
                'ok' => true,
                'manifest' => $service->manifest(),
                'next_slice' => $nextSlice,
                'blocked_slice' => $blockedSlice,
                'flow_resolution' => $flow,
                'rollout_toggle' => $rollout,
                'milestone' => $milestone,
                'new_service_check' => $newService,
            ];

            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'atlas_dev_eff_prog_flow_runbook_v1_part01_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}
