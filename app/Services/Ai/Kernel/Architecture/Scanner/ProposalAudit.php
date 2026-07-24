<?php

namespace App\Services\Ai\Kernel\Architecture\Scanner;

use Illuminate\Support\Facades\File;

class ProposalAudit
{
    public function __construct(private ScanPrimitivesSupport $primitives) {}

    /**
     * @return array<string,callable(): array<int,string>>
     */
    public function checks(): array
    {
        return [
            'ap107_proposal_inbox_review_signal_contract' => fn (): array => $this->scanProposalInboxReviewSignalContract(),
            'ap117_proposal_inbox_review_signal_severity' => fn (): array => $this->scanProposalInboxReviewSignalSeverity(),
            'ap118_proposal_review_action_contract' => fn (): array => $this->scanProposalReviewActionContract(),
        ];
    }

    /**
     * @return array<int,string>
     */
    private function scanProposalInboxReviewSignalContract(): array
    {
        $violations = [];
        $emitterPath = app_path('Services/Ai/Mobile/ProposalInboxEmitter.php');
        $testPath = base_path('tests/Unit/Ai/ProposalInboxEmitterTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');

        $emitter = File::exists($emitterPath) ? File::get($emitterPath) : '';
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $docs = $this->primitives->kernelDocumentationCorpus();

        foreach ([
            'private function proposalPayload(array $data, string $problem, string $solution, string $worthIt): array',
            "'proposal_contract' => [",
            "'schema_version' => \$metadata['schema_version'] ?? null",
            "'review_signal' => \$this->array(\$metadata['review_signal'] ?? [])",
            "'source_refs' => \$this->array(\$data['source_refs'] ?? [])",
        ] as $token) {
            if (! str_contains($emitter, $token)) {
                $violations[] = "app/Services/Ai/Mobile/ProposalInboxEmitter.php: AP-107 Proposal Inbox must preserve schema/review_signal/source refs [{$token}]";
            }
        }

        foreach ([
            'test_proposal_preserves_review_signal_contract_in_bundle_and_inbox_payload',
            'payload.proposal_contract.schema_version',
            'payload.proposal_contract.review_signal.status',
            'raw_payload.proposal_contract.review_signal.status',
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Unit/Ai/ProposalInboxEmitterTest.php: AP-107 Proposal Inbox review_signal contract must be covered [{$token}]";
            }
        }

        foreach ([
            'AP-107',
            'Proposal Inbox Review Signal',
            'proposal_contract.review_signal',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: AP-107 Proposal Inbox review_signal contract must be documented [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanProposalInboxReviewSignalSeverity(): array
    {
        $violations = [];
        $emitterPath = app_path('Services/Ai/Mobile/ProposalInboxEmitter.php');
        $testPath = base_path('tests/Unit/Ai/ProposalInboxEmitterTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $apDocPath = base_path('docs/ap/AP-117-proposal-inbox-review-signal-severity-contract.md');

        $emitter = File::exists($emitterPath) ? File::get($emitterPath) : '';
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $docs = $this->primitives->kernelDocumentationCorpus();
        $apDoc = File::exists($apDocPath) ? File::get($apDocPath) : '';

        foreach ([
            '$reviewSignal = $this->array($metadata[\'review_signal\'] ?? [])',
            "'severity' => \$this->severityFromReviewSignal(\$reviewSignal)",
            "'priority_score' => \$this->priorityFromReviewSignal(\$reviewSignal)",
            'private function severityFromReviewSignal',
            'private function priorityFromReviewSignal',
        ] as $token) {
            if (! str_contains($emitter, $token)) {
                $violations[] = "app/Services/Ai/Mobile/ProposalInboxEmitter.php: AP-117 Proposal Inbox must map review_signal severity into Inbox severity/priority [{$token}]";
            }
        }

        foreach ([
            'test_proposal_maps_review_signal_to_inbox_severity_and_priority',
            "'severity' => 'high'",
            "data_get(\$inbox->created, 'severity')",
            "data_get(\$inbox->created, 'priority_score')",
            "'critical'",
            '85',
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Unit/Ai/ProposalInboxEmitterTest.php: AP-117 severity/priority mapping must be covered [{$token}]";
            }
        }

        foreach ([
            'AP-117',
            'Proposal Inbox Review Signal Severity',
            'review_signal.severity',
            'priority_score',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: AP-117 proposal severity mapping must be documented [{$token}]";
            }
            if (! str_contains($apDoc, $token)) {
                $violations[] = "docs/ap/AP-117-proposal-inbox-review-signal-severity-contract.md: AP-117 contract doc must exist [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanProposalReviewActionContract(): array
    {
        $violations = [];
        $actionsPath = app_path('Services/Ai/Mobile/InboxActionRegistry.php');
        $testPath = base_path('tests/Feature/MobileGatewayTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $apDocPath = base_path('docs/ap/AP-118-proposal-review-action-contract.md');

        $actions = File::exists($actionsPath) ? File::get($actionsPath) : '';
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $docs = $this->primitives->kernelDocumentationCorpus();
        $apDoc = File::exists($apDocPath) ? File::get($apDocPath) : '';

        foreach ([
            '$proposalContract = $this->array(data_get($payload, \'proposal_contract\'))',
            "'proposal_contract' => \$proposalContract",
            "'review_signal' => \$this->array(data_get(\$proposalContract, 'review_signal'))",
            "'recommended_action' => \$this->string(data_get(\$proposalContract, 'review_signal.recommended_action'))",
            "'diff_refs' => \$this->array(data_get(\$proposalContract, 'diff_refs'))",
        ] as $token) {
            if (! str_contains($actions, $token)) {
                $violations[] = "app/Services/Ai/Mobile/InboxActionRegistry.php: AP-118 review_patch must expose proposal contract fields directly [{$token}]";
            }
        }

        foreach ([
            "'review_patch'",
            'result.payload.action',
            'result.payload.diff_refs.0.path',
            'result.payload.proposal_contract.diff_refs.0.path',
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Feature/MobileGatewayTest.php: AP-118 review_patch action contract must be covered [{$token}]";
            }
        }

        foreach ([
            'AP-118',
            'Proposal Review Action Contract',
            'review_patch',
            'proposal_contract',
            'recommended_action',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: AP-118 review action contract must be documented [{$token}]";
            }
            if (! str_contains($apDoc, $token)) {
                $violations[] = "docs/ap/AP-118-proposal-review-action-contract.md: AP-118 contract doc must exist [{$token}]";
            }
        }

        return $violations;
    }
}
