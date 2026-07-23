<?php

namespace App\Services\Ai\Kernel\Architecture\Scanner;

use Illuminate\Support\Facades\File;

class ContextAudit
{
    public function __construct(private ScanPrimitivesSupport $primitives)
    {
    }

    /**
     * @return array<string,callable(): array<int,string>>
     */
    public function checks(): array
    {
        return [
            'ap85_context_pack_memory_input_contract' => fn (): array => $this->scanContextPackMemoryInputContract(),
            'ap100_context_pack_manifest_reflection_contract' => fn (): array => $this->scanContextPackManifestReflectionContract(),
            'ap101_context_retrieval_router_contract' => fn (): array => $this->scanContextRetrievalRouterContract(),
        ];
    }

    /**
     * @return array<int,string>
     */
    private function scanContextPackMemoryInputContract(): array
    {
        $inputPath = app_path('Services/Ai/Context/ContextPackMemoryInput.php');
        $builderPath = app_path('Services/Ai/Context/AiContextPackBuilder.php');
        $testPath = base_path('tests/Unit/Ai/Context/ContextPackMemoryInputTest.php');
        $kernelDocsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $memoryDocsPath = base_path('docs/engineering-knowledge-base/atlas-ai-memory-context-core-open-brain.md');
        $violations = [];

        $input = File::exists($inputPath) ? File::get($inputPath) : '';
        $builder = File::exists($builderPath) ? File::get($builderPath) : '';
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $kernelDocs = $this->primitives->kernelDocumentationCorpus();
        $memoryDocs = File::exists($memoryDocsPath) ? File::get($memoryDocsPath) : '';

        foreach ([
            'final class ContextPackMemoryInput',
            'public const DEFAULT_MEMORY_REGISTRY_LIMIT = 8',
            'public const MAX_MEMORY_REGISTRY_LIMIT = 50',
            'public const DEFAULT_VERBATIM_RECALL_LIMIT = 4',
            'public const MAX_VERBATIM_RECALL_LIMIT = 25',
            'public const DEFAULT_VERBATIM_RECALL_BUDGET_CHARS = 1600',
            'public const MAX_VERBATIM_RECALL_BUDGET_CHARS = 8000',
            'public const DEFAULT_VERBATIM_RECALL_ITEM_CHARS = 600',
            'public const MAX_VERBATIM_RECALL_ITEM_CHARS = 3000',
            'public const DEFAULT_MEMORY_REGISTRY_EXCERPT_CHARS = 900',
            'public const MAX_MEMORY_REGISTRY_EXCERPT_CHARS = 5000',
            'public function memoryRegistryLimit(',
            'public function verbatimRecallLimit(',
            'public function verbatimRecallBudgetChars(',
            'public function verbatimRecallItemChars(',
            'public function memoryRegistryExcerptChars(',
        ] as $token) {
            if (! str_contains($input, $token)) {
                $violations[] = "app/Services/Ai/Context/ContextPackMemoryInput.php: context pack memory input contract is incomplete [{$token}]";
            }
        }

        foreach ([
            'private ContextPackMemoryInput $memoryInput',
            '$this->memoryInput->memoryRegistryLimit($options[\'memory_registry_limit\'] ?? null)',
            '$this->memoryInput->verbatimRecallLimit($options[\'verbatim_recall_limit\'] ?? null)',
            '$this->memoryInput->memoryRegistryExcerptChars()',
            '$this->memoryInput->verbatimRecallBudgetChars($options[\'verbatim_recall_budget_chars\'] ?? null)',
            '$this->memoryInput->verbatimRecallItemChars($options[\'verbatim_recall_item_chars\'] ?? null)',
        ] as $token) {
            if (! str_contains($builder, $token)) {
                $violations[] = "app/Services/Ai/Context/AiContextPackBuilder.php: context pack memory paths must use shared input contract [{$token}]";
            }
        }

        foreach ([
            'test_normalizes_context_pack_memory_limits_with_canonical_caps',
            'ContextPackMemoryInput::MAX_MEMORY_REGISTRY_LIMIT',
            'ContextPackMemoryInput::MAX_VERBATIM_RECALL_LIMIT',
            'ContextPackMemoryInput::MAX_VERBATIM_RECALL_BUDGET_CHARS',
            'ContextPackMemoryInput::MAX_MEMORY_REGISTRY_EXCERPT_CHARS',
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Unit/Ai/Context/ContextPackMemoryInputTest.php: context pack memory input contract must be covered [{$token}]";
            }
        }

        foreach ([
            'context pack memory input contract',
            'AP-85',
        ] as $token) {
            if (! str_contains($kernelDocs, $token) && ! str_contains($memoryDocs, $token)) {
                $violations[] = "docs/engineering-knowledge-base: docs must describe context pack memory input contract [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanContextPackManifestReflectionContract(): array
    {
        $violations = [];
        $contextPackPath = app_path('Services/Ai/ValueObjects/AiContextPack.php');
        $gatePath = app_path('Services/Ai/Context/ContextPackSelfReflectionGate.php');
        $testPath = base_path('tests/Unit/Ai/Context/ContextPackSelfReflectionGateTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');

        $contextPack = File::exists($contextPackPath) ? File::get($contextPackPath) : '';
        $gate = File::exists($gatePath) ? File::get($gatePath) : '';
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $docs = $this->primitives->kernelDocumentationCorpus();

        foreach ([
            'private function withManifest(array $data, array $contextRefs): array',
            "'schema_version' => 'atlas.context_pack.manifest.v1'",
            "'created_at' => \$createdAt->toJSON()",
            "'expires_at' => \$createdAt->copy()->addSeconds(\$ttlSeconds)->toJSON()",
            "'sources' => \$sources",
            "'context_ref_hash' => hash('sha256'",
        ] as $token) {
            if (! str_contains($contextPack, $token)) {
                $violations[] = "app/Services/Ai/ValueObjects/AiContextPack.php: Context Pack must carry AP-100 manifest with sources, created_at and expires_at [{$token}]";
            }
        }

        foreach ([
            'class ContextPackSelfReflectionGate',
            "public const STATUS_SUFFICIENT = 'sufficient'",
            "public const STATUS_INSUFFICIENT = 'insufficient'",
            "public const STATUS_CONTRADICTORY = 'contradictory'",
            "public const STATUS_RISKY = 'risky'",
            'public function assess(AiContextPack|array $contextPack): array',
            "'schema_version' => 'atlas.context_pack.self_reflection.v1'",
            'refresh_or_request_context',
            'surface_conflict_before_execution',
            'require_review_before_execution',
        ] as $token) {
            if (! str_contains($gate, $token)) {
                $violations[] = "app/Services/Ai/Context/ContextPackSelfReflectionGate.php: Self-Reflection Gate must classify sufficient/insufficient/contradictory/risky context [{$token}]";
            }
        }

        foreach ([
            'ContextPackSelfReflectionGateTest',
            'test_context_pack_manifest_has_sources_created_at_and_expires_at',
            'test_self_reflection_gate_classifies_sufficient_insufficient_contradictory_and_risky_context',
            'atlas.context_pack.manifest.v1',
            'atlas.context_pack.self_reflection.v1',
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Unit/Ai/Context/ContextPackSelfReflectionGateTest.php: AP-100 manifest and reflection gate must be covered [{$token}]";
            }
        }

        foreach ([
            'AP-100',
            'Context Pack Manifest',
            'Self-Reflection Gate',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: AP-100 context manifest/reflection contract must be documented [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanContextRetrievalRouterContract(): array
    {
        $violations = [];
        $routerPath = app_path('Services/Ai/Context/ContextRetrievalRouter.php');
        $builderPath = app_path('Services/Ai/Context/AiContextPackBuilder.php');
        $packPath = app_path('Services/Ai/ValueObjects/AiContextPack.php');
        $testPath = base_path('tests/Unit/Ai/Context/ContextRetrievalRouterTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');

        $router = File::exists($routerPath) ? File::get($routerPath) : '';
        $builder = File::exists($builderPath) ? File::get($builderPath) : '';
        $pack = File::exists($packPath) ? File::get($packPath) : '';
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $docs = $this->primitives->kernelDocumentationCorpus();

        foreach ([
            'final class ContextRetrievalRouter',
            "public const SCHEMA_VERSION = 'atlas.context.retrieval_plan.v1'",
            "'vector_retrieval'",
            "'graph_retrieval'",
            "'evidence_replay'",
            "'code_intelligence'",
            "'memory_signals'",
            "'do_not_create_parallel_memory' => true",
        ] as $token) {
            if (! str_contains($router, $token)) {
                $violations[] = "app/Services/Ai/Context/ContextRetrievalRouter.php: AP-101 retrieval router contract is incomplete [{$token}]";
            }
        }

        foreach ([
            'private ContextRetrievalRouter $retrievalRouter',
            '$retrievalPlan = $this->retrievalRouter->plan($input, $task, $payload, $options)',
            '\'retrieval\' => $retrievalPlan',
        ] as $token) {
            if (! str_contains($builder, $token)) {
                $violations[] = "app/Services/Ai/Context/AiContextPackBuilder.php: Context Builder must attach AP-101 retrieval plan [{$token}]";
            }
        }

        foreach ([
            'Retrieval Router Plan',
            'selected_sources',
        ] as $token) {
            if (! str_contains($pack, $token)) {
                $violations[] = "app/Services/Ai/ValueObjects/AiContextPack.php: prompt context must expose AP-101 retrieval plan [{$token}]";
            }
        }

        foreach ([
            'ContextRetrievalRouterTest',
            'test_builds_provider_safe_retrieval_plan_for_programming_context',
            'test_marks_evidence_required_for_high_risk_and_graph_for_architecture_questions',
            'atlas.context.retrieval_plan.v1',
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Unit/Ai/Context/ContextRetrievalRouterTest.php: AP-101 retrieval router must be covered [{$token}]";
            }
        }

        foreach ([
            'AP-101',
            'Retrieval Router',
            'atlas.context.retrieval_plan.v1',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: AP-101 retrieval router contract must be documented [{$token}]";
            }
        }

        return $violations;
    }
}
