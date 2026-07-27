<?php

namespace App\Services\Ai\Kernel\Architecture\Scanner;

use App\Support\PeeledSource;
use Illuminate\Support\Facades\File;

class InputContractAudit
{
    public function __construct(private ScanPrimitivesSupport $primitives) {}

    /**
     * @return array<string,callable(): array<int,string>>
     */
    public function checks(): array
    {
        return [
            'ap81_conversation_context_input_contract' => fn (): array => $this->scanConversationContextInputContract(),
            'ap83_atlas_vault_command_input_contract' => fn (): array => $this->scanAtlasVaultCommandInputContract(),
            'ap86_semantic_context_input_contract' => fn (): array => $this->scanSemanticContextInputContract(),
            'ap88_test_command_input_contract' => fn (): array => $this->scanTestCommandInputContract(),
            'ap97_scheduler_input_contract' => fn (): array => $this->scanSchedulerInputContract(),
            'ap109_operation_completed_inbox_refs_contract' => fn (): array => $this->scanOperationCompletedInboxRefsContract(),
        ];
    }

    /**
     * @return array<int,string>
     */
    private function scanConversationContextInputContract(): array
    {
        $inputPath = app_path('Services/Ai/Context/ConversationContextInput.php');
        $builderPath = app_path('Services/Ai/Context/AiConversationContextBuilder.php');
        $testPath = base_path('tests/Unit/Ai/Context/ConversationContextInputTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $violations = [];

        $input = PeeledSource::read($inputPath);
        $builder = PeeledSource::read($builderPath);
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $docs = $this->primitives->kernelDocumentationCorpus();

        foreach ([
            'final class ConversationContextInput',
            'public const DEFAULT_RECENT_TURN_LIMIT = 12',
            'public const MIN_RECENT_TURN_LIMIT = 2',
            'public const MAX_RECENT_TURN_LIMIT = 40',
            'public const DEFAULT_PAYLOAD_TURN_LIMIT = 8',
            'public const MIN_PAYLOAD_TURN_LIMIT = 1',
            'public const MAX_PAYLOAD_TURN_LIMIT = 20',
            'public function recentTurnLimit(',
            'public function payloadTurnLimit(',
        ] as $token) {
            if (! str_contains($input, $token)) {
                $violations[] = "app/Services/Ai/Context/ConversationContextInput.php: conversation context input contract is incomplete [{$token}]";
            }
        }

        foreach ([
            'private readonly ConversationContextInput $input',
            '$this->input->recentTurnLimit()',
            '$this->input->payloadTurnLimit()',
        ] as $token) {
            if (! str_contains($builder, $token)) {
                $violations[] = "app/Services/Ai/Context/AiConversationContextBuilder.php: conversation context builder must use shared input contract [{$token}]";
            }
        }

        foreach ([
            'test_normalizes_conversation_context_turn_limits_with_canonical_caps',
            'ConversationContextInput::MAX_RECENT_TURN_LIMIT',
            'ConversationContextInput::MAX_PAYLOAD_TURN_LIMIT',
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Unit/Ai/Context/ConversationContextInputTest.php: conversation context input contract must be covered [{$token}]";
            }
        }

        foreach ([
            'conversation context input contract',
            'AP-81',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: docs must describe conversation context input contract [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanAtlasVaultCommandInputContract(): array
    {
        $inputPath = app_path('Services/Semantic/AtlasVaultCommandInput.php');
        $commandPath = app_path('Console/Commands/AtlasVaultCommand.php');
        $testPath = base_path('tests/Unit/AtlasVaultCommandInputTest.php');
        $kernelDocsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $vaultDocsPath = base_path('docs/engineering-knowledge-base/obsidian-atlas-vault.md');
        $violations = [];

        $input = PeeledSource::read($inputPath);
        $command = PeeledSource::read($commandPath);
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $kernelDocs = $this->primitives->kernelDocumentationCorpus();
        $vaultDocs = File::exists($vaultDocsPath) ? File::get($vaultDocsPath) : '';

        foreach ([
            'final class AtlasVaultCommandInput',
            'public const DEFAULT_SYNC_LIMIT = 200',
            'public const DEFAULT_CONFLICT_LIMIT = 100',
            'public const MAX_COMMAND_LIMIT = 1000',
            'public function syncLimit(',
            'public function conflictLimit(',
        ] as $token) {
            if (! str_contains($input, $token)) {
                $violations[] = "app/Services/Semantic/AtlasVaultCommandInput.php: AtlasVault command input contract is incomplete [{$token}]";
            }
        }

        foreach ([
            'private AtlasVaultCommandInput $vaultInput',
            'AtlasVaultCommandInput $input',
            '$this->vaultInput->syncLimit($this->option(\'limit\'))',
            '$this->vaultInput->conflictLimit($this->option(\'limit\'))',
        ] as $token) {
            if (! str_contains($command, $token)) {
                $violations[] = "app/Console/Commands/AtlasVaultCommand.php: AtlasVault command must use shared input contract [{$token}]";
            }
        }

        foreach ([
            'test_normalizes_atlas_vault_command_limits_with_canonical_caps',
            'AtlasVaultCommandInput::DEFAULT_SYNC_LIMIT',
            'AtlasVaultCommandInput::MAX_COMMAND_LIMIT',
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Unit/AtlasVaultCommandInputTest.php: AtlasVault command input contract must be covered [{$token}]";
            }
        }

        foreach ([
            'atlas vault command input contract',
            'AP-83',
        ] as $token) {
            if (! str_contains($kernelDocs, $token) && ! str_contains($vaultDocs, $token)) {
                $violations[] = "docs/engineering-knowledge-base: docs must describe AtlasVault command input contract [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanSemanticContextInputContract(): array
    {
        $inputPath = app_path('Services/Ai/Context/SemanticContextInput.php');
        $builderPath = app_path('Services/Ai/Context/AiContextPackBuilder.php');
        $testPath = base_path('tests/Unit/Ai/Context/SemanticContextInputTest.php');
        $kernelDocsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $memoryDocsPath = base_path('docs/engineering-knowledge-base/atlas-ai-memory-context-core-open-brain.md');
        $violations = [];

        $input = PeeledSource::read($inputPath);
        $builder = PeeledSource::read($builderPath);
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $kernelDocs = $this->primitives->kernelDocumentationCorpus();
        $memoryDocs = File::exists($memoryDocsPath) ? File::get($memoryDocsPath) : '';

        foreach ([
            'final class SemanticContextInput',
            'public const DEFAULT_CONTEXT_NOTE_LIMIT = 5',
            'public const MAX_CONTEXT_NOTE_LIMIT = 30',
            'public const DEFAULT_CONTEXT_EXCERPT_CHARS = 1200',
            'public const MAX_CONTEXT_EXCERPT_CHARS = 8000',
            'public function contextNoteLimit(',
            'public function contextExcerptChars(',
        ] as $token) {
            if (! str_contains($input, $token)) {
                $violations[] = "app/Services/Ai/Context/SemanticContextInput.php: semantic context input contract is incomplete [{$token}]";
            }
        }

        foreach ([
            'private SemanticContextInput $semanticInput',
            '$this->semanticInput->contextNoteLimit($options[\'context_note_limit\'] ?? null)',
            '$this->semanticInput->contextExcerptChars()',
        ] as $token) {
            if (! str_contains($builder, $token)) {
                $violations[] = "app/Services/Ai/Context/AiContextPackBuilder.php: semantic context paths must use shared input contract [{$token}]";
            }
        }

        foreach ([
            'test_normalizes_semantic_context_limits_with_canonical_caps',
            'SemanticContextInput::MAX_CONTEXT_NOTE_LIMIT',
            'SemanticContextInput::MAX_CONTEXT_EXCERPT_CHARS',
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Unit/Ai/Context/SemanticContextInputTest.php: semantic context input contract must be covered [{$token}]";
            }
        }

        foreach ([
            'semantic context input contract',
            'AP-86',
        ] as $token) {
            if (! str_contains($kernelDocs, $token) && ! str_contains($memoryDocs, $token)) {
                $violations[] = "docs/engineering-knowledge-base: docs must describe semantic context input contract [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanTestCommandInputContract(): array
    {
        $inputPath = app_path('Services/Ai/Runtime/TestCommandInput.php');
        $resolverPath = app_path('Services/Ai/Runtime/AtlasTestCommandResolver.php');
        $testPath = base_path('tests/Unit/Ai/Runtime/TestCommandInputTest.php');
        $resolverTestPath = base_path('tests/Unit/AtlasTestCommandResolverTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $violations = [];

        $input = PeeledSource::read($inputPath);
        $resolver = PeeledSource::read($resolverPath);
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $resolverTest = File::exists($resolverTestPath) ? File::get($resolverTestPath) : '';
        $docs = $this->primitives->kernelDocumentationCorpus();

        foreach ([
            'final class TestCommandInput',
            "public const DEFAULT_MEMORY_LIMIT = '1024M'",
            'public function memoryLimit(',
        ] as $token) {
            if (! str_contains($input, $token)) {
                $violations[] = "app/Services/Ai/Runtime/TestCommandInput.php: test command input contract is incomplete [{$token}]";
            }
        }

        foreach ([
            'private readonly TestCommandInput $input',
            '$this->input->memoryLimit()',
        ] as $token) {
            if (! str_contains($resolver, $token)) {
                $violations[] = "app/Services/Ai/Runtime/AtlasTestCommandResolver.php: test command resolver must use shared input contract [{$token}]";
            }
        }

        foreach ([
            'test_normalizes_test_command_memory_limit_with_canonical_default',
            'TestCommandInput::DEFAULT_MEMORY_LIMIT',
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Unit/Ai/Runtime/TestCommandInputTest.php: test command input contract must be covered [{$token}]";
            }
        }

        if (! str_contains($resolverTest, 'memory_limit=1024M')) {
            $violations[] = 'tests/Unit/AtlasTestCommandResolverTest.php: resolver must preserve canonical test memory default in generated command';
        }

        foreach ([
            'test command input contract',
            'AP-88',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: docs must describe test command input contract [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanSchedulerInputContract(): array
    {
        $inputPath = app_path('Services/Ai/Scheduling/AtlasSchedulerInput.php');
        $servicePath = app_path('Services/Ai/Scheduling/AtlasCliSchedulerService.php');
        $commandPath = app_path('Console/Commands/AtlasSchedulerTickCommand.php');
        $testPath = base_path('tests/Unit/Ai/Scheduling/AtlasSchedulerInputTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $violations = [];

        $input = PeeledSource::read($inputPath);
        $service = PeeledSource::read($servicePath);
        $command = PeeledSource::read($commandPath);
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $docs = $this->primitives->kernelDocumentationCorpus();

        foreach ([
            'final class AtlasSchedulerInput',
            'public const DEFAULT_DUE_TASK_LIMIT = 25',
            'public const MAX_DUE_TASK_LIMIT = 100',
            'public function dueTaskLimit(',
        ] as $token) {
            if (! str_contains($input, $token)) {
                $violations[] = "app/Services/Ai/Scheduling/AtlasSchedulerInput.php: scheduler input contract is incomplete [{$token}]";
            }
        }

        foreach ([
            'private readonly AtlasSchedulerInput $input',
            '$this->input->dueTaskLimit($limit)',
        ] as $token) {
            if (! str_contains($service, $token)) {
                $violations[] = "app/Services/Ai/Scheduling/AtlasCliSchedulerService.php: scheduler service must use shared scheduler input [{$token}]";
            }
        }

        foreach ([
            'AtlasSchedulerInput $input',
            '$limit = $input->dueTaskLimit($this->option(\'limit\'))',
            'previewDueTasks(limit: $limit)',
            'limit: $limit',
        ] as $token) {
            if (! str_contains($command, $token)) {
                $violations[] = "app/Console/Commands/AtlasSchedulerTickCommand.php: scheduler tick command must use shared scheduler input [{$token}]";
            }
        }

        foreach ([
            'test_normalizes_scheduler_due_task_limit',
            'AtlasSchedulerInput::DEFAULT_DUE_TASK_LIMIT',
            'AtlasSchedulerInput::MAX_DUE_TASK_LIMIT',
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Unit/Ai/Scheduling/AtlasSchedulerInputTest.php: scheduler input contract must be covered [{$token}]";
            }
        }

        foreach ([
            'scheduler input contract',
            'AP-97',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: docs must describe scheduler input contract [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanOperationCompletedInboxRefsContract(): array
    {
        $violations = [];
        $runtimePath = app_path('Services/Ai/SelfImprovement/AtlasSelfImprovementRuntime.php');
        $testPath = base_path('tests/Feature/Ai/AtlasSelfImprovementRuntimeTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');

        $runtime = PeeledSource::read($runtimePath);
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $docs = $this->primitives->kernelDocumentationCorpus();

        foreach ([
            'LedgerEventType::OperationCompleted',
            "'emitted_count' => count(\$emitted)",
            "'emitted_inbox_item_ids' => array_values(\$emitted)",
        ] as $token) {
            if (! str_contains($runtime, $token)) {
                $violations[] = "app/Services/Ai/SelfImprovement/AtlasSelfImprovementRuntime.php: AP-109 OperationCompleted must preserve emitted inbox ids [{$token}]";
            }
        }

        foreach ([
            'LedgerEventType::OperationCompleted->value',
            'emitted_inbox_item_ids',
            'emitted_count',
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasSelfImprovementRuntimeTest.php: AP-109 OperationCompleted inbox refs must be covered [{$token}]";
            }
        }

        foreach ([
            'AP-109',
            'OperationCompleted Inbox Refs',
            'emitted_inbox_item_ids',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: AP-109 OperationCompleted inbox refs contract must be documented [{$token}]";
            }
        }

        return $violations;
    }
}
