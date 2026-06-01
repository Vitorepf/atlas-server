<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasDevEfficientProgrammingFlowV1Part03Service;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atlas Dev Efficient Programming Flow v1 · Parte 3 — verification/repair/escalation decider CLI.
 *
 *   php artisan atlas:aaeos:atlas-dev-efficient-programming-flow-v1-part03 [--json]
 *
 * Read-only, deterministic. Exercises the documented slice (§19–§23) with safe
 * defaults: an unverified->passed completion attempt (blocked by the gate), a
 * repair-loop stop on a repeated failure signature, a php_laravel verification
 * gate with no test and no reason (fail), a score-7 Forge escalation, an
 * evidence-minima check and the quality build gates, then emits the verdicts plus
 * the manifest as JSON.
 *
 * @see docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-v1-part-03.md
 */
class AtlasDevEfficientProgrammingFlowV1Part03Command extends Command
{
    protected $signature = 'atlas:aaeos:atlas-dev-efficient-programming-flow-v1-part03 {--json}';

    protected $description = 'Atlas Dev efficient programming flow (Parte 3) · evaluate completion states, repair loop stop, verification command profiles, escalation, evidence minima and quality gates.';

    public function handle(AtlasDevEfficientProgrammingFlowV1Part03Service $service): int
    {
        try {
            $payload = [
                'ok' => true,
                'manifest' => $service->manifest(),
                'completion_unverified_to_passed' => $service->classifyCompletion('passed', 'unverified'),
                'completion_no_patch_needed' => $service->classifyCompletion('no_patch_needed', 'verified', true),
                'repair_loop_repeated_signature' => $service->repairLoopDecision([
                    'same_failure_signature_twice' => true,
                ]),
                'repair_loop_clean' => $service->repairLoopDecision([
                    'risk_level' => 'R2',
                ]),
                'verification_gate_no_test_no_reason' => $service->verificationGate('php_laravel', false, null),
                'verification_gate_generic' => $service->verificationGate('generic_no_test', false, null),
                'escalation_score_7' => $service->escalationDecision([
                    'score' => 7,
                ]),
                'escalation_below_threshold' => $service->escalationDecision([
                    'score' => 1,
                ]),
                'evidence_patch_missing' => $service->evidenceCheck('patch', ['diff_hash', 'changed_files']),
                'quality_build_gates' => $service->qualityBuildGates(),
            ];

            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'atlas_dev_flow_v1_part03_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}
