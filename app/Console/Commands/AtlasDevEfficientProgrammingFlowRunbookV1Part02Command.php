<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasDevEfficientProgrammingFlowRunbookV1Part02Service;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atlas Dev Efficient Programming Flow Runbook v1 · Parte 2 — slice-contract CLI.
 *
 *   php artisan atlas:aaeos:atlas-dev-efficient-programming-flow-runbook-v1-part02 [--json]
 *
 * Read-only, deterministic. Exercises the implementation-slice invariants the
 * runbook embeds in its PR plan (CompactSdd R4|R5->escalate_preview, the 4
 * canonical surface_ids, VerificationReceipt passed gate, FailureCapsule
 * retry/escalate, and the 7-condition Fatia 0 DoD) against known inputs and
 * emits the verdicts plus the contract manifest as JSON.
 *
 * @see docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-runbook-v1-part-02.md
 */
class AtlasDevEfficientProgrammingFlowRunbookV1Part02Command extends Command
{
    protected $signature = 'atlas:aaeos:atlas-dev-efficient-programming-flow-runbook-v1-part02 {--json}';

    protected $description = 'Atlas Dev flow runbook (Parte 2) · evaluate CompactSdd risk/mode, surface_id, verification completion, failure-capsule decision and the Fatia 0 DoD, then emit the manifest.';

    public function handle(AtlasDevEfficientProgrammingFlowRunbookV1Part02Service $service): int
    {
        try {
            // PR 0.2 invariant 1 — R4 forces mode=escalate_preview; a patch mode is invalid.
            $riskMode = $service->evaluateCompactSddRiskMode('R4', 'patch');

            // PR 0.2 DoD — a canonical surface.
            $surface = $service->evaluateSurfaceId('atlas_desktop_ai');

            // PR 0.4 — a clean passed completion.
            $verification = $service->evaluateVerificationCompletion(
                ['mini_spec_before_code_gate', 'scope_guard_light', 'verification_gate'],
                [
                    'mini_spec_before_code_gate' => 'passed',
                    'scope_guard_light' => 'passed',
                    'verification_gate' => 'passed',
                ],
                'passed',
                true,
                null,
                [],
            );

            // PR 0.4 — attempt 2 of max 2 has reached the cap -> escalate.
            $failure = $service->failureCapsuleDecision(2, 2);

            // 6.3 — Fatia 0 with one unmet condition is not green.
            $fatia0 = $service->evaluateFatia0Dod([
                'tests_green' => true,
                'lint_clean' => true,
                'phpstan_clean' => true,
                'coverage_min_95' => false,
                'index_code_ok' => true,
                'provider_safe_memory_ok' => true,
                'no_real_provider' => true,
            ]);

            $payload = [
                'ok' => true,
                'manifest' => $service->manifest(),
                'compact_sdd_risk_mode' => $riskMode,
                'surface_id' => $surface,
                'verification_completion' => $verification,
                'failure_capsule' => $failure,
                'fatia0_dod' => $fatia0,
            ];

            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'atlas_dev_flow_runbook_v1_part02_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}
