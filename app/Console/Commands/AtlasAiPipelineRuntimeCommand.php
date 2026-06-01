<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasAiPipelineRuntimeService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atlas AI Pipeline — canonical macro-pipeline decider CLI.
 *
 *   php artisan atlas:aaeos:atlas-ai-pipeline-runtime [--json]
 *
 * Read-only and deterministic. With safe defaults it demonstrates the contract:
 * the canonical 14-stage macro order (Intent before Domain), the 12 invariants
 * evaluated against a thin operational run that omits the receipt/evidence
 * (so several fail), the `atlas forge` alias resolving to heavy intensity on
 * the SAME pipeline, and a manual model that is not policy-allowed being
 * blocked back to auto-best on the Decision Receipt.
 *
 * @see docs/engineering-knowledge-base/atlas-ai-pipeline.md
 */
class AtlasAiPipelineRuntimeCommand extends Command
{
    protected $signature = 'atlas:aaeos:atlas-ai-pipeline-runtime {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas AI pipeline: canonical macro order, 12 invariants, intensity tiers and Decision Receipt authority.';

    public function handle(AtlasAiPipelineRuntimeService $service): int
    {
        try {
            // A thin operational run that changes real state but skips the
            // Decision Receipt and evidence packet — several invariants fail.
            $thinRun = $service->checkInvariants([
                'changes_real_state' => true,
                'claims_operational_success' => true,
                'input' => ['origin' => 'cli', 'surface' => 'cli', 'workspace' => 'atlas', 'attachments' => []],
                'intent_confidence' => 0.9,
                'domain' => 'programming',
                // domain_profile / flow_profile / decision_receipt_id / evidence_packet omitted on purpose
            ]);

            // A manual model not allowed by policy is blocked back to auto-best.
            $receipt = $service->compileDecisionReceipt([
                'domain_profile' => 'programming.forge',
                'flow_profile' => 'forge.build',
                'policy' => ['allowed_models' => ['atlas-auto-a', 'atlas-auto-b'], 'auto_best_model' => 'atlas-auto-a'],
                'requested_model' => 'some-unlisted-model',
            ]);

            $decision = [
                'canonical_pipeline' => $service->canonicalPipeline(),
                'thin_operational_run_invariants' => $thinRun,
                'forge_alias' => $service->resolveIntensity('atlas forge'),
                'blocked_manual_override_receipt' => $receipt,
            ];

            $this->line((string) json_encode(
                ['ok' => true, 'decision' => $decision],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'atlas_ai_pipeline_runtime_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}
