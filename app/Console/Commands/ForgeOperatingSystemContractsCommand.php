<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\ForgeOperatingSystemContractsService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Forge Operating System Contracts conformance CLI.
 *
 *   php artisan atlas:aaeos:forge-operating-system-contracts [--json]
 *
 * Runs the whole-catalog conformance checker over a reference Forge object
 * bundle and emits the pass|fail evidence document. Read-only and deterministic;
 * it never mutates Forge state or relaxes a gate.
 *
 * @see docs/engineering-knowledge-base/atlas-forge-operating-system-contracts.md
 */
class ForgeOperatingSystemContractsCommand extends Command
{
    protected $signature = 'atlas:aaeos:forge-operating-system-contracts {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas Forge OS · checks a Forge object bundle (mother spec, work packet, state transition, release gate, AI rules) against the documented contracts.';

    public function handle(ForgeOperatingSystemContractsService $service): int
    {
        try {
            // Safe default bundle: a conformant reference set that demonstrates a
            // green catalog run. Operators can wire real Forge objects later.
            $bundle = [
                'mother_spec' => [
                    'objetivo_maior' => 'Demonstrar conformidade de contrato.',
                    'motivacao' => 'Provar que o catalogo roda.',
                    'escopo' => 'Apenas verificacao de contrato.',
                    'fora_de_escopo' => 'Execucao de provider.',
                    'arquitetura_afetada' => 'forge.contracts',
                    'docs_canonicos' => ['atlas-forge-operating-system-contracts.md'],
                    'code_intelligence_context' => 'forge contract symbols',
                    'riscos' => ['contrato sem objeto persistente'],
                    'gates' => ['release-green'],
                    'estrategia_de_divisao' => 'um packet por contrato',
                    'criterio_de_conclusao_global' => 'todos os contratos verdes',
                ],
                'work_packet' => [
                    'id' => 'WP-FORGE-CONTRACTS-0001',
                    'titulo' => 'Conformance reference packet',
                    'objetivo' => 'Validar campos de packet.',
                    'mother_spec_id' => 'MS-FORGE-0001',
                    'owner' => 'forge-local',
                    'allowed_files' => ['app/Services/Ai/Aaeos/Generated/'],
                    'forbidden_files' => ['routes/'],
                    'reserved_symbols' => ['ForgeOperatingSystemContractsService'],
                    'dependencies' => [],
                    'inputs' => ['bundle'],
                    'outputs' => ['conformance report'],
                    'validation_commands' => ['php artisan test'],
                    'acceptance_criteria' => ['status=pass'],
                    'evidence' => ['report.json'],
                    'risk' => 'low',
                    'rollback' => 'revert files',
                    'idempotency_key' => 'forge-contracts-0001',
                    'permission_profile' => 'read_only',
                    'sandbox_profile' => 'default',
                    'secret_policy' => 'no_secrets',
                    'artifact_outputs' => ['report.json'],
                    'integration_notes' => 'none',
                ],
                'state_transition' => [
                    'from' => 'verified',
                    'to' => 'queued_for_integration',
                    'event_id' => 'EVT-0001',
                ],
                'release' => array_merge(
                    array_fill_keys(ForgeOperatingSystemContractsService::RELEASE_REQUIREMENTS, true),
                    ['artifacts_present' => true, 'provenance_present' => true],
                ),
                'ai_rules_context' => [
                    'packet' => [
                        'allowed_files' => ['app/Services/Ai/Aaeos/Generated/'],
                        'forbidden_files' => ['routes/'],
                        'evidence' => ['report.json'],
                    ],
                    'provider_has_policy_authority' => false,
                    'permission_gate_skipped' => false,
                    'release' => ['artifacts_present' => true, 'provenance_present' => true],
                    'treats_future_as_implemented' => false,
                ],
            ];

            $result = $service->checkBundle($bundle);

            $this->line((string) json_encode(
                ['ok' => true, 'result' => $result],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));

            return $result['status'] === ForgeOperatingSystemContractsService::STATUS_PASS
                ? self::SUCCESS
                : self::FAILURE;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'forge_operating_system_contracts_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}
