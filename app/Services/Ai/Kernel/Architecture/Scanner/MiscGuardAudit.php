<?php

namespace App\Services\Ai\Kernel\Architecture\Scanner;

use App\Support\PeeledSource;
use Illuminate\Support\Facades\File;

class MiscGuardAudit
{
    public function __construct(private ScanPrimitivesSupport $primitives) {}

    /**
     * @return array<string,callable(): array<int,string>>
     */
    public function checks(): array
    {
        return [
            'ap6_decision_receipt_propagation' => fn (): array => $this->scanGatewayDecisionReceiptPropagation(),
            'ap13_decision_receipt_runtime_guard' => fn (): array => $this->scanWorkerDecisionReceiptRuntimeGuard(),
            'ap145_documentation_health_curator_review' => fn (): array => $this->scanDocumentationHealthCuratorReview(),
        ];
    }

    /**
     * @return array<int,string>
     */
    private function scanGatewayDecisionReceiptPropagation(): array
    {
        $path = app_path('Services/Ai/AiGatewayService.php');
        if (! File::exists($path)) {
            return ["missing gateway [{$path}]"];
        }

        $contents = File::get($path);
        $checks = [
            'gateway emits a trace-level receipt' => 'decisionReceiptForTrace(',
            'trace metadata persists the receipt' => "'decision_receipt' => \$decisionReceipt",
            'scout job accepts the receipt as an explicit parameter' => 'array $decisionReceipt',
            'scout job is called with the same receipt' => 'decisionReceipt: $decisionReceipt',
        ];

        $violations = [];
        foreach ($checks as $label => $token) {
            if (! str_contains($contents, $token)) {
                $violations[] = "app/Services/Ai/AiGatewayService.php: missing {$label} [{$token}]";
            }
        }

        $receiptWrites = substr_count($contents, "'decision_receipt' => \$decisionReceipt");
        if ($receiptWrites < 5) {
            $violations[] = "app/Services/Ai/AiGatewayService.php: expected receipt propagation to trace, primary job, council jobs and scout jobs; found {$receiptWrites} writes";
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanWorkerDecisionReceiptRuntimeGuard(): array
    {
        $path = app_path('Services/Ai/AiWorker.php');
        if (! File::exists($path)) {
            return ["missing worker [{$path}]"];
        }

        $contents = File::get($path);
        $guardPath = app_path('Services/Ai/Kernel/Decision/DecisionReceiptRuntimeGuard.php');
        $guardContents = PeeledSource::read($guardPath);
        $checks = [
            'worker delegates receipt validation to the kernel guard' => 'DecisionReceiptRuntimeGuard',
            'worker evaluates receipt before provider lookup' => 'violationForJob($job, $providerKey, $job->model)',
            'worker persists receipt metadata through the kernel guard' => 'receiptForJob($job)',
            'kernel guard class exists' => 'class DecisionReceiptRuntimeGuard',
            'worker blocks expired receipts' => 'decision_receipt_expired',
            'worker blocks dry-run receipts' => 'decision_receipt_dry_run',
            'worker blocks invalid receipts' => 'decision_receipt_invalid',
            'worker blocks provider mismatches' => 'decision_receipt_provider_mismatch',
            'worker blocks model mismatches' => 'decision_receipt_model_mismatch',
            'kernel guard validates runtime provider' => 'providerSelectionViolation(',
            'worker parses expires_at as immutable time' => 'CarbonImmutable::parse($expiresAt)',
            'worker marks receipt blocks as no provider call' => "'decision_receipt_expired'",
        ];

        $violations = [];
        foreach ($checks as $label => $token) {
            if (! str_contains($contents, $token) && ! str_contains($guardContents, $token)) {
                $violations[] = "app/Services/Ai/AiWorker.php: missing {$label} [{$token}]";
            }
        }

        if ($guardContents === '') {
            $violations[] = 'app/Services/Ai/Kernel/Decision/DecisionReceiptRuntimeGuard.php: missing dedicated runtime guard';
        }

        $guardPosition = strpos($contents, 'violationForJob($job, $providerKey, $job->model)');
        $providerLookupPosition = strpos($contents, '$provider = $this->providers->get($providerKey);');
        if ($guardPosition === false || $providerLookupPosition === false || $guardPosition > $providerLookupPosition) {
            $violations[] = 'app/Services/Ai/AiWorker.php: decision receipt runtime guard must run before provider lookup/execution';
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanDocumentationHealthCuratorReview(): array
    {
        $runtimePath = app_path('Services/Ai/SelfImprovement/AtlasSelfImprovementRuntime.php');
        $testPath = base_path('tests/Feature/Ai/AtlasSelfImprovementRuntimeTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $apDocPath = base_path('docs/ap/AP-145-documentation-health-curator-review.md');
        $docOsPath = base_path('docs/engineering-knowledge-base/atlas-ai-documentation-operating-system.md');

        $runtime = PeeledSource::read($runtimePath);
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $docs = $this->primitives->kernelDocumentationCorpus();
        $apDoc = File::exists($apDocPath) ? File::get($apDocPath) : '';
        $docOs = File::exists($docOsPath) ? File::get($docOsPath) : '';
        $violations = [];

        foreach ([
            'private function documentationHealthFindings(array $filters = []): array',
            'atlas.self_improvement.documentation_health_gap.v1',
            'split_oversized_active_docs',
            'documentation.oversized_docs',
            'split_required_grandfathered',
            'self-improvement:documentation-health:',
        ] as $token) {
            if (! str_contains($runtime, $token)) {
                $violations[] = "app/Services/Ai/SelfImprovement/AtlasSelfImprovementRuntime.php: AP-145 Documentation Health must become Curator findings [{$token}]";
            }
        }

        foreach ([
            'test_self_improvement_detects_oversized_active_documentation_from_architecture_validation',
            'atlas.self_improvement.documentation_health_gap.v1',
            'split_oversized_active_docs',
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasSelfImprovementRuntimeTest.php: AP-145 Documentation Health Curator review must be tested [{$token}]";
            }
        }

        foreach ([
            'AP-145',
            'Documentation Health Curator Review',
            'atlas.self_improvement.documentation_health_gap.v1',
            'split_oversized_active_docs',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: AP-145 Documentation Health Curator review must be documented [{$token}]";
            }
            if (! str_contains($apDoc, $token)) {
                $violations[] = "docs/ap/AP-145-documentation-health-curator-review.md: AP-145 contract doc must exist [{$token}]";
            }
        }

        foreach ([
            'Documentation Health Curator Review',
            'atlas.self_improvement.documentation_health_gap.v1',
            'split_oversized_active_docs',
        ] as $token) {
            if (! str_contains($docOs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-documentation-operating-system.md: AP-145 must be named in Documentation OS [{$token}]";
            }
        }

        return $violations;
    }
}
