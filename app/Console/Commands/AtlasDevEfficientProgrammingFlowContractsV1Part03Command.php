<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasDevEfficientProgrammingFlowContractsV1Part03Service;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atlas Dev Efficient Programming Flow Contracts v1 · Parte 3 — MiniProgrammingSpec invariant gate CLI.
 *
 *   php artisan atlas:aaeos:atlas-dev-efficient-programming-flow-contracts-v1-part03 [--json]
 *
 * Read-only, deterministic. Runs the §4.3 invariant decider over two canned
 * payloads — the doc's valid Repair-R2 example (must pass) and a deliberately
 * broken spec that trips I1/I4/I5/I9 — then emits both verdicts plus the manifest
 * as JSON. It never executes, routes, plans, calls a provider or touches a DB.
 *
 * @see docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-contracts-v1-part-03.md
 */
class AtlasDevEfficientProgrammingFlowContractsV1Part03Command extends Command
{
    protected $signature = 'atlas:aaeos:atlas-dev-efficient-programming-flow-contracts-v1-part03 {--json}';

    protected $description = 'Atlas Dev efficient programming flow contracts (Parte 3) · validate a MiniProgrammingSpec against its ten documented invariants.';

    public function handle(AtlasDevEfficientProgrammingFlowContractsV1Part03Service $service): int
    {
        try {
            // The doc's valid Repair-R2 example (doc lines 174-218), augmented with
            // the CompactSDD context the invariants are conditional on.
            $validSpec = [
                'goal' => 'Corrigir AtlasCliDevWorkflowServiceTest::test_workspace_resolution para passar com git root null',
                'risk_level' => 'R2',
                'task_kind' => 'repair',
                'mode' => 'repair',
                'compact_sdd_verification_profile' => 'php_laravel',
                'non_goals' => ['nao mudar API publica do WorkflowService', 'nao mexer em outros testes'],
                'canonical_context' => [
                    ['kind' => 'file', 'ref' => 'app/Services/Ai/Cli/AtlasCliDevWorkflowService.php', 'reason' => 'alvo do bug'],
                ],
                'assumptions' => [
                    ['text' => 'git root nullable e caso valido', 'confidence' => 'inference'],
                ],
                'expected_files' => ['app/Services/Ai/Cli/AtlasCliDevWorkflowService.php'],
                'allowed_files' => ['app/Services/Ai/Cli/AtlasCliDevWorkflowService.php'],
                'forbidden_files' => ['vendor/*', 'node_modules/*'],
                'acceptance_criteria' => [
                    [
                        'id' => 'ac_1',
                        'description' => 'teste passa',
                        'verification' => 'test',
                        'verification_ref' => 'tests/Unit/AtlasCliDevWorkflowServiceTest.php::test_workspace_resolution',
                    ],
                ],
                'verification_plan' => [
                    'profile' => 'php_laravel',
                    'commands' => ['composer test -- --filter=AtlasCliDevWorkflowServiceTest::test_workspace_resolution'],
                    'no_test_reason' => null,
                ],
            ];

            // A spec that violates several invariants at once.
            $brokenSpec = [
                'goal' => '', // I1 empty
                'risk_level' => 'R3',
                'task_kind' => 'patch',
                'mode' => 'patch',
                'compact_sdd_verification_profile' => 'php_laravel',
                'non_goals' => [], // I2 (risk R3 >= R2 needs non_goals)
                'canonical_context' => [], // I3
                'expected_files' => ['app/Foo.php'], // I4 not in allowed
                'allowed_files' => ['app/Bar.php', 'vendor/x'],
                'forbidden_files' => ['vendor/x'], // I5 overlaps allowed
                'acceptance_criteria' => [], // I6 write with no criteria
                'verification_plan' => [
                    'profile' => 'php_laravel',
                    'commands' => [],
                    'no_test_reason' => 'nao tem teste', // I9 illegal on php_laravel
                ],
            ];

            $payload = [
                'ok' => true,
                'manifest' => $service->manifest(),
                'valid_repair_r2' => $service->validateMiniSpec($validSpec),
                'broken_spec' => $service->validateMiniSpec($brokenSpec),
            ];

            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'atlas_dev_flow_contracts_v1_part03_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}
