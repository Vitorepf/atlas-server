<?php

namespace App\Services\Ai\Kernel\Architecture\Scanner;

use App\Support\PeeledSource;
use Illuminate\Support\Facades\File;

class ChatAudit
{
    public function __construct(private ScanPrimitivesSupport $primitives) {}

    /**
     * @return array<string,callable(): array<int,string>>
     */
    public function checks(): array
    {
        return [
            'ap23_chat_dev_programming_contract' => fn (): array => $this->scanChatDevProgrammingContract(),
            'ap27_chat_model_selection_contract' => fn (): array => $this->scanChatModelSelectionContract(),
            'ap30_chat_programming_contract_factory' => fn (): array => $this->scanChatProgrammingContractFactory(),
        ];
    }

    /**
     * @return array<int,string>
     */
    private function scanChatDevProgrammingContract(): array
    {
        $chatPath = app_path('Console/Commands/AiChatCommand.php');
        $testPath = base_path('tests/Feature/Console/AiChatProviderChoiceTest.php');

        $chat = PeeledSource::read($chatPath);
        $test = File::exists($testPath) ? File::get($testPath) : '';

        $violations = [];

        foreach ([
            'ProgrammingSurfaceContractFactory',
            "\$payload['programming_chat_contract'] = app(ProgrammingSurfaceContractFactory::class)->chatDev(\$devPlan, \$programmingMessagePlan, \$payload['programming_dispatch'])",
        ] as $token) {
            if (! str_contains($chat, $token)) {
                $violations[] = "app/Console/Commands/AiChatCommand.php: atlas chat --dev must emit programming_chat_contract tied to Kernel Pipeline and AtlasProgrammingOrchestrator [{$token}]";
            }
        }

        foreach ([
            'test_chat_dev_without_explicit_dev_plan_generates_programming_contract',
            'test_chat_dev_attaches_kernel_pipeline_to_legacy_declared_dev_plan',
            "'atlas.ai_chat.programming_contract.v1'",
            "'programming_chat_contract.programming_flow'",
            "'programming_chat_contract.kernel_pipeline_flow'",
            "'programming_chat_contract.kernel_pipeline_provider_execution_allowed'",
            "'programming_chat_contract.kernel_pipeline_contract_required'",
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Feature/Console/AiChatProviderChoiceTest.php: chat dev needs coverage proving programming_chat_contract for auto and declared dev plans [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanChatModelSelectionContract(): array
    {
        $chatPath = app_path('Console/Commands/AiChatCommand.php');
        $testPath = base_path('tests/Feature/Console/AiChatProviderChoiceTest.php');

        $chat = PeeledSource::read($chatPath);
        $test = File::exists($testPath) ? File::get($testPath) : '';

        $violations = [];

        foreach ([
            'ModelSelectionContractFactory',
            "'model_selection_contract' => \$this->modelSelectionContract(",
            'private function modelSelectionContract(?string $provider, ?array $modelSelection, ?string $modelOverride, bool $fairMode, array $context = []): array',
            '->forAiChat($provider, $modelSelection, $modelOverride, $fairMode, $context)',
            "'model_selection_contract' => data_get(\$trace->metadata, 'model_selection_contract')",
        ] as $token) {
            if (! str_contains($chat, $token)) {
                $violations[] = "app/Console/Commands/AiChatCommand.php: atlas chat must expose model_selection_contract with Atlas Decide authority [{$token}]";
            }
        }

        foreach ([
            "'model_selection_contract.schema_version'",
            "'model_selection_contract.authority'",
            "'model_selection_contract.selection_mode'",
            "'model_selection_contract.available_selection_modes'",
            "'model_selection_contract.operator_requested_provider'",
            "'model_selection_contract.requested_model'",
            "'model_selection_contract.requested_model_alias'",
            "data_get(\$payload, 'model_selection_contract')",
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Feature/Console/AiChatProviderChoiceTest.php: atlas chat needs tests for model selection contract in auto and manual override paths [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanChatProgrammingContractFactory(): array
    {
        $factoryPath = app_path('Services/Ai/Programming/ProgrammingSurfaceContractFactory.php');
        $testPath = base_path('tests/Unit/Ai/Programming/ProgrammingSurfaceContractFactoryTest.php');
        $chatPath = app_path('Console/Commands/AiChatCommand.php');

        $factory = PeeledSource::read($factoryPath);
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $chat = File::exists($chatPath) ? File::get($chatPath) : '';

        $violations = [];

        foreach ([
            'public function chatDev(?array $devPlan, array $programmingMessagePlan, ?array $dispatch): array',
            "'schema_version' => 'atlas.ai_chat.programming_contract.v1'",
            "'surface' => 'atlas_ai_chat'",
            "'mode' => 'dev'",
            "'orchestrator' => 'AtlasProgrammingOrchestrator'",
            "'programming_flow' => data_get(\$programmingMessagePlan, 'policy_profile.profile_id')",
            "'dispatch_path' => data_get(\$dispatch, 'dispatch_path')",
            "'kernel_pipeline_surface' => data_get(\$devPlan, 'kernel_pipeline.input.surface_id')",
            "'kernel_pipeline_flow' => data_get(\$devPlan, 'kernel_pipeline.input.safe_hints.flow')",
            "'kernel_pipeline_provider_execution_allowed' => (bool) data_get(\$devPlan, 'kernel_pipeline.provider_execution_allowed', false)",
            "'kernel_pipeline_contract_required' => (bool) data_get(\$devPlan, 'kernel_pipeline_contract.required', false)",
        ] as $token) {
            if (! str_contains($factory, $token)) {
                $violations[] = "app/Services/Ai/Programming/ProgrammingSurfaceContractFactory.php: Programming must own the shared chat dev contract shape [{$token}]";
            }
        }

        foreach ([
            'test_chat_dev_contract_preserves_programming_dispatch_and_kernel_pipeline_binding',
            "'atlas.ai_chat.programming_contract.v1'",
            "'atlas_ai_chat'",
            "'programming.repair'",
            "'chat_dev_auto_plan'",
            "'kernel_pipeline_contract_required'",
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Unit/Ai/Programming/ProgrammingSurfaceContractFactoryTest.php: chat dev contract factory needs explicit coverage [{$token}]";
            }
        }

        foreach ([
            "'schema_version' => 'atlas.ai_chat.programming_contract.v1'",
            "'kernel_pipeline_surface' => data_get(\$devPlan, 'kernel_pipeline.input.surface_id')",
            "'kernel_pipeline_contract_required' => (bool) data_get(\$devPlan, 'kernel_pipeline_contract.required', false)",
        ] as $token) {
            if (str_contains($chat, $token)) {
                $violations[] = "app/Console/Commands/AiChatCommand.php: chat command must not inline programming_chat_contract schema; use ProgrammingSurfaceContractFactory [{$token}]";
            }
        }

        return $violations;
    }
}
