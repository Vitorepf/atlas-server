<?php

namespace App\Services\Ai\Kernel\Architecture\Scanner;

use Illuminate\Support\Facades\File;

class RetrievalAudit
{
    public function __construct(private ScanPrimitivesSupport $primitives) {}

    /**
     * @return array<string,callable(): array<int,string>>
     */
    public function checks(): array
    {
        return [
            'ap82_retrieval_rank_input_contract' => fn (): array => $this->scanRetrievalRankInputContract(),
            'ap103_retrieval_required_source_availability_contract' => fn (): array => $this->scanRetrievalRequiredSourceAvailabilityContract(),
            'ap104_retrieval_review_signal_next_action_contract' => fn (): array => $this->scanRetrievalReviewSignalNextActionContract(),
        ];
    }

    /**
     * @return array<int,string>
     */
    private function scanRetrievalRankInputContract(): array
    {
        $inputPath = app_path('Services/Ai/Context/RetrievalRankInput.php');
        $searchPath = app_path('Services/Ai/Search/SessionSearchService.php');
        $promptPath = app_path('Services/Ai/AiPromptBuilder.php');
        $runtimePath = app_path('Services/Ai/Runtime/AiToolRuntime.php');
        $testPath = base_path('tests/Unit/Ai/Context/RetrievalRankInputTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $violations = [];

        $input = File::exists($inputPath) ? File::get($inputPath) : '';
        $search = File::exists($searchPath) ? File::get($searchPath) : '';
        $prompt = File::exists($promptPath) ? File::get($promptPath) : '';
        $runtime = File::exists($runtimePath) ? File::get($runtimePath) : '';
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $docs = $this->primitives->kernelDocumentationCorpus();

        foreach ([
            'final class RetrievalRankInput',
            'public const DEFAULT_SESSION_TOP_N = 3',
            'public const MAX_SESSION_TOP_N = 10',
            'public const MAX_PROMPT_SESSION_TOP_N = 5',
            'public function sessionTopN(',
            'public function promptSessionTopN(',
        ] as $token) {
            if (! str_contains($input, $token)) {
                $violations[] = "app/Services/Ai/Context/RetrievalRankInput.php: retrieval rank input contract is incomplete [{$token}]";
            }
        }

        foreach ([
            'private readonly RetrievalRankInput $input',
            '$this->input->sessionTopN($topN)',
        ] as $token) {
            if (! str_contains($search, $token)) {
                $violations[] = "app/Services/Ai/Search/SessionSearchService.php: session search must use shared retrieval rank contract [{$token}]";
            }
        }

        foreach ([
            '?RetrievalRankInput $retrievalRankInput = null',
            '->promptSessionTopN(data_get($config, \'top_n\'))',
        ] as $token) {
            if (! str_contains($prompt, $token)) {
                $violations[] = "app/Services/Ai/AiPromptBuilder.php: prompt session search must use shared retrieval rank contract [{$token}]";
            }
        }

        foreach ([
            'private readonly RetrievalRankInput $retrievalRankInput',
            '$this->retrievalRankInput->sessionTopN($invocation->argument(\'top_n\'))',
        ] as $token) {
            if (! str_contains($runtime, $token)) {
                $violations[] = "app/Services/Ai/Runtime/AiToolRuntime.php: runtime session.search must use shared retrieval rank contract [{$token}]";
            }
        }

        foreach ([
            'test_normalizes_retrieval_rank_limits_with_canonical_caps',
            'RetrievalRankInput::MAX_SESSION_TOP_N',
            'RetrievalRankInput::MAX_PROMPT_SESSION_TOP_N',
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Unit/Ai/Context/RetrievalRankInputTest.php: retrieval rank input contract must be covered [{$token}]";
            }
        }

        foreach ([
            'retrieval rank input contract',
            'AP-82',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: docs must describe retrieval rank input contract [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanRetrievalRequiredSourceAvailabilityContract(): array
    {
        $violations = [];
        // Pin relocated under GOD-DEBULK D3 (2026-07-22): the AP-103 retrieval-source-availability
        // family moved VERBATIM into RetrievalPlanSection; invariant unchanged, only the file moved.
        $servicePath = app_path('Services/Ai/OpenBrainContextInjection/RetrievalPlanSection.php');
        $testPath = base_path('tests/Unit/Ai/AtlasOpenBrainContextInjectionServiceTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');

        $service = File::exists($servicePath) ? File::get($servicePath) : '';
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $docs = $this->primitives->kernelDocumentationCorpus();

        foreach ([
            'private function retrievalSourceAvailability(array $selected, array $contextRefs, array $knowledgeRefs, array $codeRefs, array $contextPack): array',
            "'available_sources' => array_values(array_keys(array_filter",
            "'unavailable_sources' => array_values(array_keys(array_filter",
            "'required_unavailable_sources' => array_values(array_keys(array_filter",
            'private function evidenceReplayCount(array $contextRefs, array $contextPack): int',
            'private function graphRetrievalCount(array $contextRefs, array $contextPack): int',
            'public function retrievalPlanWarnings(array $retrievalPlan): array',
            "'retrieval_required_source_unavailable'",
        ] as $token) {
            if (! str_contains($service, $token)) {
                $violations[] = "app/Services/Ai/OpenBrainContextInjection/RetrievalPlanSection.php: AP-103 required retrieval source availability gate is incomplete [{$token}]";
            }
        }

        foreach ([
            'test_required_open_brain_fails_closed_when_required_retrieval_source_is_unavailable',
            'summary.retrieval_plan.required_unavailable_sources',
            'retrieval_required_source_unavailable',
            'failed_closed',
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Unit/Ai/AtlasOpenBrainContextInjectionServiceTest.php: AP-103 retrieval availability gate must be covered [{$token}]";
            }
        }

        foreach ([
            'AP-103',
            'Retrieval Required Source Availability',
            'retrieval_required_source_unavailable',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: AP-103 retrieval availability contract must be documented [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanRetrievalReviewSignalNextActionContract(): array
    {
        $violations = [];
        // Pin relocated under GOD-DEBULK D3 (2026-07-22): the retrieval review-signal PRODUCERS
        // moved VERBATIM into RetrievalPlanSection; nextActions (the review_signal -> operator-action
        // CONSUMER) stays on the façade. Invariant unchanged — each token is still required
        // textually in its real new home, and the check still fails if any producer/consumer is removed.
        $sectionPath = app_path('Services/Ai/OpenBrainContextInjection/RetrievalPlanSection.php');
        $servicePath = app_path('Services/Ai/AtlasOpenBrainContextInjectionService.php');
        $testPath = base_path('tests/Unit/Ai/AtlasOpenBrainContextInjectionServiceTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');

        $section = File::exists($sectionPath) ? File::get($sectionPath) : '';
        $service = File::exists($servicePath) ? File::get($servicePath) : '';
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $docs = $this->primitives->kernelDocumentationCorpus();

        foreach ([
            "'review_signal' => \$reviewSignal",
            'private function retrievalReviewSignal(array $availability): array',
            'private function retrievalRecommendedAction(array $sources): string',
            "'status' => 'blocking'",
            "'recommended_action' => \$this->retrievalRecommendedAction(\$requiredUnavailable)",
        ] as $token) {
            if (! str_contains($section, $token)) {
                $violations[] = "app/Services/Ai/OpenBrainContextInjection/RetrievalPlanSection.php: AP-104 retrieval review_signal contract is incomplete [{$token}]";
            }
        }

        foreach ([
            'private function nextActions(array $warnings, array $summary = []): array',
            "data_get(\$summary, 'retrieval_plan.review_signal.recommended_action')",
            'Refresh evidence replay or attach trace/envelope evidence before retrying.',
        ] as $token) {
            if (! str_contains($service, $token)) {
                $violations[] = "app/Services/Ai/AtlasOpenBrainContextInjectionService.php: AP-104 retrieval next_actions contract is incomplete [{$token}]";
            }
        }

        foreach ([
            'summary.retrieval_plan.review_signal.status',
            'summary.retrieval_plan.review_signal.recommended_action',
            'Refresh evidence replay or attach trace/envelope evidence before retrying.',
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Unit/Ai/AtlasOpenBrainContextInjectionServiceTest.php: AP-104 retrieval review_signal/next_actions must be covered [{$token}]";
            }
        }

        foreach ([
            'AP-104',
            'Retrieval Review Signal',
            'refresh_evidence_replay_or_attach_trace_before_retry',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: AP-104 retrieval review_signal contract must be documented [{$token}]";
            }
        }

        return $violations;
    }
}
