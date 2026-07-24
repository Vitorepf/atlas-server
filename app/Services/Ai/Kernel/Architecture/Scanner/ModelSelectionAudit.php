<?php

namespace App\Services\Ai\Kernel\Architecture\Scanner;

use Illuminate\Support\Facades\File;

class ModelSelectionAudit
{
    public function __construct(private ScanPrimitivesSupport $primitives) {}

    /**
     * @return array<string,callable(): array<int,string>>
     */
    public function checks(): array
    {
        return [
            'ap25_decide_model_selection_contract' => fn (): array => $this->scanDecideModelSelectionContract(),
            'ap31_continue_resume_contract_factory' => fn (): array => $this->scanContinueResumeContractFactory(),
            'ap32_fix_contract_factory' => fn (): array => $this->scanFixContractFactory(),
        ];
    }

    /**
     * @return array<int,string>
     */
    private function scanDecideModelSelectionContract(): array
    {
        $decidePath = app_path('Services/Ai/AtlasDecideService.php');
        $testPath = base_path('tests/Unit/Ai/AtlasDecideReceiptIntegrationTest.php');
        $cliTestPath = base_path('tests/Feature/AiAtlasDecideContractTest.php');

        $decide = File::exists($decidePath) ? File::get($decidePath) : '';
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $cliTest = File::exists($cliTestPath) ? File::get($cliTestPath) : '';

        $violations = [];

        foreach ([
            "\$selectionMode = \$manualProvider !== null ? 'manual_override' : \$this->automaticModelSelectionMode(\$policy)",
            "'selection_mode' => \$selectionMode",
            "'model_selection_authority' => 'atlas_decide'",
            "'available_selection_modes' => ['auto_best_allowed', 'auto_best_available', 'manual_override']",
            "'selection_mode' => \$providerSelection['selection_mode'] ?? data_get(\$decision, 'receipt_v2.provider_selection.selection_mode')",
            "'model_selection_authority' => \$providerSelection['model_selection_authority'] ?? 'atlas_decide'",
            "return (\$policy['default_model_policy'] ?? null) === 'best_quality'",
            "? 'auto_best_available'",
            ": 'auto_best_allowed'",
        ] as $token) {
            if (! str_contains($decide, $token)) {
                $violations[] = "app/Services/Ai/AtlasDecideService.php: Atlas Decide must expose explicit model selection modes and remain the selection authority [{$token}]";
            }
        }

        foreach ([
            "'provider_selection.selection_mode'",
            "'provider_selection.model_selection_authority'",
            "'provider_selection.available_selection_modes'",
            "'auto_best_allowed'",
            "'auto_best_available'",
            "'manual_override'",
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Unit/Ai/AtlasDecideReceiptIntegrationTest.php: Decide receipt integration must prove public model selection mode contract [{$token}]";
            }
        }

        foreach ([
            "'decision.selection_mode'",
            "'decision.model_selection_authority'",
            "'decision.available_selection_modes'",
        ] as $token) {
            if (! str_contains($cliTest, $token)) {
                $violations[] = "tests/Feature/AiAtlasDecideContractTest.php: atlas:ai:decide JSON must expose public model selection mode contract [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanContinueResumeContractFactory(): array
    {
        $factoryPath = app_path('Services/Ai/Programming/ProgrammingSurfaceContractFactory.php');
        $testPath = base_path('tests/Unit/Ai/Programming/ProgrammingSurfaceContractFactoryTest.php');
        $continuePath = app_path('Console/Commands/AtlasCliContinueCommand.php');

        $factory = File::exists($factoryPath) ? File::get($factoryPath) : '';
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $continue = File::exists($continuePath) ? File::get($continuePath) : '';

        $violations = [];

        foreach ([
            'public function resume(array $resume, array $operatorOptions, array $command, array $openBrain): array',
            "'schema_version' => 'atlas.cli_continue.resume_contract.v1'",
            "'surface' => 'atlas_cli_continue'",
            "'canonical_surface' => 'atlas_cli_dev'",
            "'target_command' => 'atlas:cli:dev'",
            "'target_surface' => 'atlas_cli_dev'",
            "'builder' => AtlasProgrammingSurfaceCommandBuilder::class",
            "'plan_id' => (string) (\$resume['plan_id'] ?? '')",
            "'programming_intent' => \$operatorOptions['programming_intent'] ?? (\$operatorOptions['intent'] ?? null)",
            "'open_brain' => \$openBrain",
            "'resume' => in_array('--resume='.(string) (\$resume['plan_id'] ?? ''), \$command, true)",
        ] as $token) {
            if (! str_contains($factory, $token)) {
                $violations[] = "app/Services/Ai/Programming/ProgrammingSurfaceContractFactory.php: Programming must own the shared resume_contract shape [{$token}]";
            }
        }

        foreach ([
            'test_resume_contract_preserves_continue_origin_and_canonical_dev_target',
            "'atlas.cli_continue.resume_contract.v1'",
            "'atlas_cli_continue'",
            "'atlas_cli_dev'",
            "'target_surface'",
            "'dev_flags'",
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Unit/Ai/Programming/ProgrammingSurfaceContractFactoryTest.php: resume contract factory needs explicit coverage [{$token}]";
            }
        }

        foreach ([
            "'schema_version' => 'atlas.cli_continue.resume_contract.v1'",
            "'canonical_surface' => 'atlas_cli_dev'",
            "'target_surface' => 'atlas_cli_dev'",
            "'builder' => AtlasProgrammingSurfaceCommandBuilder::class",
        ] as $token) {
            if (str_contains($continue, $token)) {
                $violations[] = "app/Console/Commands/AtlasCliContinueCommand.php: continue command must not inline resume_contract schema; use ProgrammingSurfaceContractFactory [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanFixContractFactory(): array
    {
        $factoryPath = app_path('Services/Ai/Programming/ProgrammingSurfaceContractFactory.php');
        $builderPath = app_path('Services/Ai/Programming/AtlasProgrammingSurfaceCommandBuilder.php');
        $devPath = app_path('Console/Commands/AtlasCliDevCommand.php');
        $testPath = base_path('tests/Unit/Ai/Programming/ProgrammingSurfaceContractFactoryTest.php');
        $fixTestPath = base_path('tests/Feature/AtlasCliFixCommandTest.php');

        $factory = File::exists($factoryPath) ? File::get($factoryPath) : '';
        $builder = File::exists($builderPath) ? File::get($builderPath) : '';
        $dev = File::exists($devPath) ? File::get($devPath) : '';
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $fixTest = File::exists($fixTestPath) ? File::get($fixTestPath) : '';

        $violations = [];

        foreach ([
            'public function fix(array $operatorOptions, array $command): array',
            "'schema_version' => 'atlas.cli_fix.contract.v1'",
            "'surface' => 'atlas_cli_fix'",
            "'canonical_surface' => 'atlas_cli_dev'",
            "'target_command' => 'atlas:cli:dev'",
            "'target_surface' => 'atlas_cli_dev'",
            "'flow' => 'programming.repair'",
            "'runtime' => 'dev_repair_executor'",
            "'orchestrator' => 'AtlasProgrammingOrchestrator'",
            "'repair_required' => true",
        ] as $token) {
            if (! str_contains($factory, $token)) {
                $violations[] = "app/Services/Ai/Programming/ProgrammingSurfaceContractFactory.php: Programming must own the shared fix_contract shape [{$token}]";
            }
        }

        foreach ([
            "'--surface-origin' => 'atlas_cli_fix'",
            "'--repair' => true",
        ] as $token) {
            if (! str_contains($builder, $token)) {
                $violations[] = "app/Services/Ai/Programming/AtlasProgrammingSurfaceCommandBuilder.php: atlas fix must preserve its origin when translated into atlas:cli:dev [{$token}]";
            }
        }

        foreach ([
            '{--surface-origin= : Internal surface alias origin for thin wrapper commands}',
            "\$this->surfaceOrigin() === 'atlas_cli_fix'",
            "\$devPlan['fix_contract'] = app(ProgrammingSurfaceContractFactory::class)->fix(",
            'private function surfaceOrigin(): ?string',
            'private function fixContractCommand(): array',
        ] as $token) {
            if (! str_contains($dev, $token)) {
                $violations[] = "app/Console/Commands/AtlasCliDevCommand.php: atlas dev must attach canonical fix_contract for atlas fix aliases [{$token}]";
            }
        }

        foreach ([
            'test_fix_contract_preserves_fix_origin_and_canonical_dev_repair_target',
            "'atlas.cli_fix.contract.v1'",
            "'atlas_cli_fix'",
            "'programming.repair'",
            "'repair_required'",
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Unit/Ai/Programming/ProgrammingSurfaceContractFactoryTest.php: fix contract factory needs explicit coverage [{$token}]";
            }
        }

        foreach ([
            "'dev_execution_plan.fix_contract.schema_version'",
            "'dev_execution_plan.fix_contract.surface'",
            "'dev_execution_plan.fix_contract.canonical_surface'",
            "'dev_execution_plan.fix_contract.flow'",
            "'dev_execution_plan.fix_contract.dev_flags.repair'",
        ] as $token) {
            if (! str_contains($fixTest, $token)) {
                $violations[] = "tests/Feature/AtlasCliFixCommandTest.php: atlas fix needs coverage proving fix_contract survives the dev alias [{$token}]";
            }
        }

        return $violations;
    }
}
