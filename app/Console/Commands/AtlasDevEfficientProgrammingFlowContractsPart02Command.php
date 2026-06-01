<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasDevEfficientProgrammingFlowContractsPart02Service;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atlas Dev Efficient Programming Flow Contracts v1 · Parte 2 — invariant gate CLI.
 *
 *   php artisan atlas:aaeos:atlas-dev-efficient-programming-flow-contracts-part02 [--json]
 *
 * Read-only, deterministic. Validates a known-good Atlas Dev OperationEnvelope
 * and a known-good CompactSDD against the doc 4.1 / 4.2 invariants, plus the
 * contract manifest, and emits the verdicts as JSON.
 *
 * @see docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-contracts-v1-part-02.md
 */
class AtlasDevEfficientProgrammingFlowContractsPart02Command extends Command
{
    protected $signature = 'atlas:aaeos:atlas-dev-efficient-programming-flow-contracts-part02 {--json}';

    protected $description = 'Atlas Dev flow contracts (Parte 2) · validate OperationEnvelope + CompactSDD invariants and emit the manifest.';

    public function handle(AtlasDevEfficientProgrammingFlowContractsPart02Service $service): int
    {
        try {
            $envelope = [
                'run_id' => '0192b5d2-2fe0-7c4f-9d2a-1f3b8e9a4c2d',
                'surface_id' => 'atlas_desktop_ai',
                'surface_context' => ['product_surface' => 'atlas_ai_desktop_mac'],
                'flow_id' => 'atlas_dev',
                'flow_origin' => 'atlas_ai_router',
                'command_intent' => 'fix',
                'routed_slash_command' => true,
                'workspace' => '/Users/op/code/atlas-server',
                'intent_clarity_level' => 'high',
                'preflight' => [
                    'permission_mode' => 'write',
                    'write_allowed' => true,
                    'operator_explicit' => false,
                ],
            ];

            $compactSdd = [
                'run_id' => '0192b5d2-2fe0-7c4f-9d2a-1f3b8e9a4c2d',
                'task_kind' => 'repair',
                'risk_level' => 'R2',
                'mode' => 'repair',
                'verification_profile' => 'php_laravel',
            ];

            $payload = [
                'ok' => true,
                'manifest' => $service->manifest(),
                'operation_envelope' => $service->validateEnvelope($envelope),
                'compact_sdd' => $service->validateCompactSdd($compactSdd),
            ];

            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'atlas_dev_flow_contracts_part02_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}
