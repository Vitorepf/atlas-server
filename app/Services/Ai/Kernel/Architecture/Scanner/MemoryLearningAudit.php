<?php

namespace App\Services\Ai\Kernel\Architecture\Scanner;

use App\Support\PeeledSource;
use Illuminate\Support\Facades\File;

class MemoryLearningAudit
{
    public function __construct(private ScanPrimitivesSupport $primitives) {}

    /**
     * @return array<string,callable(): array<int,string>>
     */
    public function checks(): array
    {
        return [
            'ap79_memory_query_input_contract' => fn (): array => $this->scanMemoryQueryInputContract(),
            'ap84_memory_recall_input_contract' => fn (): array => $this->scanMemoryRecallInputContract(),
            'ap106_learning_proposed_review_signal_projection_contract' => fn (): array => $this->scanLearningProposedReviewSignalProjectionContract(),
            'ap108_learning_proposed_inbox_link_contract' => fn (): array => $this->scanLearningProposedInboxLinkContract(),
        ];
    }

    /**
     * @return array<int,string>
     */
    private function scanMemoryQueryInputContract(): array
    {
        $inputPath = app_path('Services/Ai/Memory/MemoryQueryInput.php');
        $registryPath = app_path('Services/Ai/Memory/AtlasMemoryRegistryService.php');
        $verbatimPath = app_path('Services/Ai/Memory/AtlasVerbatimMemoryService.php');
        $governancePath = app_path('Services/Ai/MemoryGovernance/AtlasMemoryGovernanceService.php');
        $privacyPath = app_path('Services/Ai/MemoryGovernance/AtlasMemoryPrivacyService.php');
        $deltaPromotionPath = app_path('Services/Ai/Memory/AtlasMemoryDeltaPromotionService.php');
        $learningPromotionPath = app_path('Services/Ai/Memory/AtlasMemoryLearningPromotionService.php');
        $maintenancePath = app_path('Services/Ai/Memory/AtlasMemoryMaintenanceService.php');
        $reviewQueuePath = app_path('Services/Ai/Memory/AtlasMemoryReviewQueueService.php');
        $qualityPath = app_path('Services/Ai/Memory/AtlasMemoryQualityService.php');
        $listCommandPath = app_path('Console/Commands/AtlasMemoryListCommand.php');
        $verbatimCommandPath = app_path('Console/Commands/AtlasMemoryVerbatimCommand.php');
        $relationsCommandPath = app_path('Console/Commands/AtlasMemoryRelationsCommand.php');
        $reviewQueueCommandPath = app_path('Console/Commands/AtlasMemoryReviewQueueCommand.php');
        $governanceCommandPath = app_path('Console/Commands/AtlasMemoryGovernanceCommand.php');
        $privacyCommandPath = app_path('Console/Commands/AtlasMemoryPrivacyCommand.php');
        $testPath = base_path('tests/Unit/Ai/Memory/MemoryQueryInputTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $violations = [];

        $input = PeeledSource::read($inputPath);
        $registry = PeeledSource::read($registryPath);
        $verbatim = PeeledSource::read($verbatimPath);
        $governance = PeeledSource::read($governancePath);
        $privacy = PeeledSource::read($privacyPath);
        $deltaPromotion = PeeledSource::read($deltaPromotionPath);
        $learningPromotion = PeeledSource::read($learningPromotionPath);
        $maintenance = PeeledSource::read($maintenancePath);
        $reviewQueue = PeeledSource::read($reviewQueuePath);
        $quality = PeeledSource::read($qualityPath);
        $listCommand = PeeledSource::read($listCommandPath);
        $verbatimCommand = PeeledSource::read($verbatimCommandPath);
        $relationsCommand = PeeledSource::read($relationsCommandPath);
        $reviewQueueCommand = PeeledSource::read($reviewQueueCommandPath);
        $governanceCommand = PeeledSource::read($governanceCommandPath);
        $privacyCommand = PeeledSource::read($privacyCommandPath);
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $docs = $this->primitives->kernelDocumentationCorpus();

        foreach ([
            'final class MemoryQueryInput',
            'public const DEFAULT_REGISTRY_LIMIT = 50',
            'public const DEFAULT_RELEVANT_LIMIT = 25',
            'public const DEFAULT_VERBATIM_LIMIT = 50',
            'public const DEFAULT_VERBATIM_CONTEXT_LIMIT = 12',
            'public const DEFAULT_GOVERNANCE_SCAN_LIMIT = 200',
            'public const DEFAULT_RELATION_LIMIT = 50',
            'public const DEFAULT_PROMOTION_LIMIT = 50',
            'public const DEFAULT_REVIEW_QUEUE_LIMIT = 50',
            'public const DEFAULT_QUALITY_HISTORY_DAYS = 30',
            'public const MAX_MEMORY_LIMIT = 200',
            'public const MAX_SCAN_LIMIT = 500',
            'public const MAX_QUALITY_HISTORY_DAYS = 365',
            'public function registryLimit(',
            'public function relevantLimit(',
            'public function verbatimLimit(',
            'public function verbatimContextLimit(',
            'public function governanceScanLimit(',
            'public function relationLimit(',
            'public function promotionLimit(',
            'public function reviewQueueLimit(',
            'public function qualityHistoryDays(',
        ] as $token) {
            if (! str_contains($input, $token)) {
                $violations[] = "app/Services/Ai/Memory/MemoryQueryInput.php: memory query input contract is incomplete [{$token}]";
            }
        }

        foreach ([
            'MemoryQueryInput $input',
            '$this->input->registryLimit(',
        ] as $token) {
            if (! str_contains($registry, $token)) {
                $violations[] = "app/Services/Ai/Memory/AtlasMemoryRegistryService.php: memory registry must use shared query input contract [{$token}]";
            }
        }

        foreach ([
            'private readonly MemoryQueryInput $input',
            '$this->input->verbatimLimit(',
        ] as $token) {
            if (! str_contains($verbatim, $token)) {
                $violations[] = "app/Services/Ai/Memory/AtlasVerbatimMemoryService.php: verbatim memory must use shared query input contract [{$token}]";
            }
        }

        foreach ([
            'private readonly MemoryQueryInput $input',
            '$this->input->governanceScanLimit(',
            '$this->input->relationLimit(',
        ] as $token) {
            if (! str_contains($governance, $token)) {
                $violations[] = "app/Services/Ai/MemoryGovernance/AtlasMemoryGovernanceService.php: memory governance must use shared query input contract [{$token}]";
            }
        }

        foreach ([
            'private readonly MemoryQueryInput $input',
            '$this->input->governanceScanLimit(',
        ] as $token) {
            if (! str_contains($privacy, $token)) {
                $violations[] = "app/Services/Ai/MemoryGovernance/AtlasMemoryPrivacyService.php: memory privacy must use shared query input contract [{$token}]";
            }
        }

        foreach ([
            'private readonly MemoryQueryInput $input',
            '$this->input->promotionLimit(',
        ] as $token) {
            if (! str_contains($deltaPromotion, $token)) {
                $violations[] = "app/Services/Ai/Memory/AtlasMemoryDeltaPromotionService.php: memory delta promotion must use shared query input contract [{$token}]";
            }
        }

        foreach ([
            'private readonly MemoryQueryInput $input',
            '$this->input->promotionLimit(',
        ] as $token) {
            if (! str_contains($learningPromotion, $token)) {
                $violations[] = "app/Services/Ai/Memory/AtlasMemoryLearningPromotionService.php: memory learning promotion must use shared query input contract [{$token}]";
            }
        }

        foreach ([
            'private readonly MemoryQueryInput $input',
            '$this->input->promotionLimit(',
        ] as $token) {
            if (! str_contains($maintenance, $token)) {
                $violations[] = "app/Services/Ai/Memory/AtlasMemoryMaintenanceService.php: memory maintenance must use shared query input contract [{$token}]";
            }
        }

        foreach ([
            'private readonly MemoryQueryInput $input',
            '$this->input->reviewQueueLimit(',
        ] as $token) {
            if (! str_contains($reviewQueue, $token)) {
                $violations[] = "app/Services/Ai/Memory/AtlasMemoryReviewQueueService.php: memory review queue must use shared query input contract [{$token}]";
            }
        }

        foreach ([
            'private readonly MemoryQueryInput $input',
            '$this->input->qualityHistoryDays(',
            '$this->input->registryLimit(',
        ] as $token) {
            if (! str_contains($quality, $token)) {
                $violations[] = "app/Services/Ai/Memory/AtlasMemoryQualityService.php: memory quality must use shared query input contract [{$token}]";
            }
        }

        foreach ([
            'MemoryQueryInput $input',
            '$input->registryLimit($this->option(\'limit\'))',
        ] as $token) {
            if (! str_contains($listCommand, $token)) {
                $violations[] = "app/Console/Commands/AtlasMemoryListCommand.php: memory list command must use shared query input contract [{$token}]";
            }
        }

        foreach ([
            'MemoryQueryInput $input',
            '$this->memoryInput()->verbatimLimit($this->option(\'limit\'))',
            'private function memoryInput(): MemoryQueryInput',
        ] as $token) {
            if (! str_contains($verbatimCommand, $token)) {
                $violations[] = "app/Console/Commands/AtlasMemoryVerbatimCommand.php: verbatim command must use shared query input contract [{$token}]";
            }
        }

        foreach ([
            'MemoryQueryInput $input',
            '$this->memoryInput()->relationLimit($this->option(\'limit\'))',
            'private function memoryInput(): MemoryQueryInput',
        ] as $token) {
            if (! str_contains($relationsCommand, $token)) {
                $violations[] = "app/Console/Commands/AtlasMemoryRelationsCommand.php: memory relations command must use shared query input contract [{$token}]";
            }
        }

        foreach ([
            'MemoryQueryInput $input',
            '$input->reviewQueueLimit($this->option(\'limit\'))',
        ] as $token) {
            if (! str_contains($reviewQueueCommand, $token)) {
                $violations[] = "app/Console/Commands/AtlasMemoryReviewQueueCommand.php: memory review queue command must use shared query input contract [{$token}]";
            }
        }

        foreach ([
            'MemoryQueryInput $input',
            '$this->memoryInput()->governanceScanLimit($this->option(\'limit\'))',
            'private function memoryInput(): MemoryQueryInput',
        ] as $token) {
            if (! str_contains($governanceCommand, $token)) {
                $violations[] = "app/Console/Commands/AtlasMemoryGovernanceCommand.php: memory governance command must use shared query input contract [{$token}]";
            }
        }

        foreach ([
            'MemoryQueryInput $input',
            '$this->memoryInput()->governanceScanLimit($this->option(\'limit\'))',
            'private function memoryInput(): MemoryQueryInput',
        ] as $token) {
            if (! str_contains($privacyCommand, $token)) {
                $violations[] = "app/Console/Commands/AtlasMemoryPrivacyCommand.php: memory privacy command must use shared query input contract [{$token}]";
            }
        }

        foreach ([
            'test_normalizes_memory_query_limits_with_canonical_caps',
            'MemoryQueryInput::MAX_MEMORY_LIMIT',
            'MemoryQueryInput::MAX_SCAN_LIMIT',
            'MemoryQueryInput::MAX_QUALITY_HISTORY_DAYS',
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Unit/Ai/Memory/MemoryQueryInputTest.php: memory query input contract must be covered [{$token}]";
            }
        }

        foreach ([
            'memory query input contract',
            'AP-79',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: docs must describe memory query input contract [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanMemoryRecallInputContract(): array
    {
        $inputPath = app_path('Services/Ai/Memory/MemoryRecallInput.php');
        $retrievalPath = app_path('Services/Ai/Memory/AtlasHybridMemoryRetrievalService.php');
        $composerPath = app_path('Services/Ai/Memory/AtlasMemoryContextComposer.php');
        $testPath = base_path('tests/Unit/Ai/Memory/MemoryRecallInputTest.php');
        $kernelDocsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $memoryDocsPath = base_path('docs/engineering-knowledge-base/atlas-ai-memory-context-core-open-brain.md');
        $violations = [];

        $input = PeeledSource::read($inputPath);
        $retrieval = PeeledSource::read($retrievalPath);
        $composer = PeeledSource::read($composerPath);
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $kernelDocs = $this->primitives->kernelDocumentationCorpus();
        $memoryDocs = File::exists($memoryDocsPath) ? File::get($memoryDocsPath) : '';

        foreach ([
            'final class MemoryRecallInput',
            'public const DEFAULT_RECALL_LIMIT = 10',
            'public const MAX_RECALL_LIMIT = 50',
            'public const MAX_REGISTRY_CANDIDATE_LIMIT = 100',
            'public const MAX_VERBATIM_CANDIDATE_LIMIT = 50',
            'public const MAX_SEMANTIC_CANDIDATE_LIMIT = 50',
            'public const DEFAULT_BUDGET_CHARS = 2400',
            'public const MAX_BUDGET_CHARS = 12000',
            'public const DEFAULT_ITEM_CHARS = 360',
            'public const MAX_ITEM_CHARS = 3000',
            'public const DEFAULT_REGISTRY_EXCERPT_CHARS = 900',
            'public const MAX_REGISTRY_EXCERPT_CHARS = 5000',
            'public function recallLimit(',
            'public function registryCandidateLimit(',
            'public function verbatimCandidateLimit(',
            'public function semanticCandidateLimit(',
            'public function budgetChars(',
            'public function itemChars(',
            'public function registryExcerptChars(',
        ] as $token) {
            if (! str_contains($input, $token)) {
                $violations[] = "app/Services/Ai/Memory/MemoryRecallInput.php: memory recall input contract is incomplete [{$token}]";
            }
        }

        foreach ([
            'private readonly MemoryRecallInput $input',
            '$this->input->recallLimit($options[\'limit\'] ?? null)',
            '$this->input->registryCandidateLimit($options[\'registry_limit\'] ?? null, $limit)',
            '$this->input->verbatimCandidateLimit($options[\'verbatim_limit\'] ?? null, $limit)',
            '$this->input->semanticCandidateLimit($options[\'semantic_limit\'] ?? null, $limit)',
            '$this->input->budgetChars($options[\'budget_chars\'] ?? null)',
            '$this->input->itemChars($options[\'item_chars\'] ?? null)',
            '$this->input->registryExcerptChars()',
        ] as $token) {
            if (! str_contains($retrieval, $token)) {
                $violations[] = "app/Services/Ai/Memory/AtlasHybridMemoryRetrievalService.php: hybrid memory recall must use shared input contract [{$token}]";
            }
        }

        foreach ([
            'private readonly MemoryRecallInput $input',
            '$this->input->recallLimit($options[\'memory_recall_limit\'] ?? null)',
            '$this->input->budgetChars($options[\'memory_recall_budget_chars\'] ?? null)',
            '$this->input->itemChars($options[\'memory_recall_item_chars\'] ?? null)',
        ] as $token) {
            if (! str_contains($composer, $token)) {
                $violations[] = "app/Services/Ai/Memory/AtlasMemoryContextComposer.php: memory context composer must use shared recall input contract [{$token}]";
            }
        }

        foreach ([
            'test_normalizes_hybrid_memory_recall_limits_with_canonical_caps',
            'MemoryRecallInput::MAX_RECALL_LIMIT',
            'MemoryRecallInput::MAX_REGISTRY_CANDIDATE_LIMIT',
            'MemoryRecallInput::MAX_BUDGET_CHARS',
            'MemoryRecallInput::MAX_REGISTRY_EXCERPT_CHARS',
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Unit/Ai/Memory/MemoryRecallInputTest.php: memory recall input contract must be covered [{$token}]";
            }
        }

        foreach ([
            'memory recall input contract',
            'AP-84',
        ] as $token) {
            if (! str_contains($kernelDocs, $token) && ! str_contains($memoryDocs, $token)) {
                $violations[] = "docs/engineering-knowledge-base: docs must describe memory recall input contract [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanLearningProposedReviewSignalProjectionContract(): array
    {
        $violations = [];
        $runtimePath = app_path('Services/Ai/SelfImprovement/AtlasSelfImprovementRuntime.php');
        $testPath = base_path('tests/Feature/Ai/AtlasSelfImprovementRuntimeTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');

        $runtime = PeeledSource::read($runtimePath);
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $docs = $this->primitives->kernelDocumentationCorpus();

        foreach ([
            '$metadata = (array) ($finding[\'metadata\'] ?? []);',
            "'schema_version' => \$metadata['schema_version'] ?? null",
            "'review_signal' => (array) (\$metadata['review_signal'] ?? [])",
            "'source_types' => collect((array) (\$finding['source_refs'] ?? []))",
        ] as $token) {
            if (! str_contains($runtime, $token)) {
                $violations[] = "app/Services/Ai/SelfImprovement/AtlasSelfImprovementRuntime.php: AP-106 LearningProposed projection must preserve review_signal/schema/source types [{$token}]";
            }
        }

        foreach ([
            'finding.schema_version',
            'finding.review_signal.status',
            'finding.review_signal.recommended_action',
            'finding.source_types',
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasSelfImprovementRuntimeTest.php: AP-106 LearningProposed projection must be covered [{$token}]";
            }
        }

        foreach ([
            'AP-106',
            'LearningProposed Review Signal Projection',
            'finding.review_signal',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: AP-106 LearningProposed projection contract must be documented [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanLearningProposedInboxLinkContract(): array
    {
        $violations = [];
        $runtimePath = app_path('Services/Ai/SelfImprovement/AtlasSelfImprovementRuntime.php');
        $testPath = base_path('tests/Feature/Ai/AtlasSelfImprovementRuntimeTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');

        $runtime = PeeledSource::read($runtimePath);
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $docs = $this->primitives->kernelDocumentationCorpus();

        foreach ([
            '$emittedByDedupeKey = [];',
            '$emittedByDedupeKey[$dedupeKey] = $item->id;',
            '$emittedInboxItemId = $emittedByDedupeKey[(string) ($finding[\'dedupe_key\'] ?? \'\')] ?? null;',
            "'emitted_to_inbox' => \$emittedInboxItemId !== null",
            "'emitted_inbox_item_id' => \$emittedInboxItemId",
        ] as $token) {
            if (! str_contains($runtime, $token)) {
                $violations[] = "app/Services/Ai/SelfImprovement/AtlasSelfImprovementRuntime.php: AP-108 LearningProposed must link emitted inbox item to finding [{$token}]";
            }
        }

        foreach ([
            'test_learning_proposed_event_links_emitted_inbox_item_to_finding',
            'emitted_to_inbox',
            'emitted_inbox_item_id',
            'ProposalInboxEmitter::class',
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasSelfImprovementRuntimeTest.php: AP-108 LearningProposed inbox link must be covered [{$token}]";
            }
        }

        foreach ([
            'AP-108',
            'LearningProposed Inbox Link',
            'emitted_inbox_item_id',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: AP-108 LearningProposed inbox link contract must be documented [{$token}]";
            }
        }

        return $violations;
    }
}
