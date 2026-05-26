<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Compounding;

use App\Services\Ai\Compounding\AtlasLearningMutationRuntimeService;
use App\Services\Ai\Governance\AtlasAutonomyAdmissionService;
use App\Services\Ai\Governance\AtlasConstitutionalKernelService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class AtlasLearningMutationRuntimeServiceTest extends TestCase
{
    private string $tempDir;

    private string $kernelLog;

    private string $admissionLog;

    private string $evaluationsLog;

    private string $applicationsLog;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir().'/atlas-learning-mutation-'.uniqid('', true);
        @mkdir($this->tempDir, 0777, true);

        $this->kernelLog = $this->tempDir.'/kernel-violations.jsonl';
        $this->admissionLog = $this->tempDir.'/admission-tickets.jsonl';
        $this->evaluationsLog = $this->tempDir.'/evaluations.jsonl';
        $this->applicationsLog = $this->tempDir.'/applications.jsonl';
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            foreach (glob($this->tempDir.'/*') ?: [] as $path) {
                @unlink($path);
            }
            @rmdir($this->tempDir);
        }

        parent::tearDown();
    }

    private function runtime(): AtlasLearningMutationRuntimeService
    {
        $kernel = new AtlasConstitutionalKernelService;
        $kernel->setViolationsLogPathForTesting($this->kernelLog);

        $admission = new AtlasAutonomyAdmissionService($kernel);
        $admission->setTicketsLogPathForTesting($this->admissionLog);

        $runtime = new AtlasLearningMutationRuntimeService($kernel, $admission);
        $runtime->setEvaluationsLogPathForTesting($this->evaluationsLog);
        $runtime->setApplicationsLogPathForTesting($this->applicationsLog);

        return $runtime;
    }

    public function test_evaluate_rejects_petreo_blacklisted_targets(): void
    {
        $evaluation = $this->runtime()->evaluate('proposal-1', [
            'target_kind' => 'constitutional_kernel_invariant',
            'proposal_payload' => ['summary' => 'try to mutate petreo'],
        ]);

        $this->assertSame(AtlasLearningMutationRuntimeService::EVALUATION_SCHEMA, $evaluation['schema_version']);
        $this->assertSame(AtlasLearningMutationRuntimeService::RECOMMENDATION_REJECT, $evaluation['recommendation']);
        $this->assertTrue($evaluation['target_is_blacklisted']);
        $this->assertFalse($evaluation['claim_policy']['auto_apply_allowed']);
        $this->assertTrue($evaluation['claim_policy']['requires_operator_approval']);
        $this->assertSame(['target_in_petreo_blacklist'], $evaluation['reason']);
    }

    public function test_evaluate_allowed_doc_skeleton_stays_receipt_only(): void
    {
        $runtime = $this->runtime();

        $evaluation = $runtime->evaluate('proposal-2', [
            'target_kind' => AtlasLearningMutationRuntimeService::TARGET_DOC_SKELETON,
            'proposal_payload' => ['summary' => 'tighten canonical doc skeleton'],
        ]);

        $this->assertSame(AtlasLearningMutationRuntimeService::EVALUATION_SCHEMA, $evaluation['schema_version']);
        $this->assertSame(AtlasLearningMutationRuntimeService::TARGET_DOC_SKELETON, $evaluation['target_kind']);
        $this->assertFalse($evaluation['target_is_blacklisted']);
        $this->assertContains($evaluation['recommendation'], [
            AtlasLearningMutationRuntimeService::RECOMMENDATION_SAFE_TO_APPLY,
            AtlasLearningMutationRuntimeService::RECOMMENDATION_REQUIRES_REVIEW,
        ]);
        $this->assertCount(1, $runtime->listEvaluations());
    }

    public function test_apply_requires_operator_hmac_receipt_and_records_hashes(): void
    {
        $runtime = $this->runtime();

        $this->expectException(InvalidArgumentException::class);
        $runtime->apply(
            proposalId: 'proposal-3',
            proposalHash: 'sha256:proposal',
            approverActor: 'agent',
            approvalReceipt: 'hmac:receipt',
            mutation: [
                'target_kind' => AtlasLearningMutationRuntimeService::TARGET_DOC_SKELETON,
                'target_path' => 'docs/template.md',
                'original_content' => 'old',
                'new_content' => 'new',
            ],
        );
    }

    public function test_apply_with_operator_records_append_only_receipt_without_mutating_target(): void
    {
        $runtime = $this->runtime();

        $receipt = $runtime->apply(
            proposalId: 'proposal-4',
            proposalHash: 'sha256:proposal',
            approverActor: 'operator',
            approvalReceipt: 'hmac:receipt',
            mutation: [
                'target_kind' => AtlasLearningMutationRuntimeService::TARGET_DOC_SKELETON,
                'target_path' => 'docs/template.md',
                'original_content' => 'old',
                'new_content' => 'new',
                'rollback_hint' => 'restore old',
            ],
        );

        $this->assertSame(AtlasLearningMutationRuntimeService::APPLICATION_SCHEMA, $receipt['schema_version']);
        $this->assertSame('operator', $receipt['approver_actor']);
        $this->assertSame('docs/template.md', $receipt['target_path']);
        $this->assertStringStartsWith('sha256:', $receipt['original_content_hash']);
        $this->assertStringStartsWith('sha256:', $receipt['new_content_hash']);
        $this->assertCount(1, $runtime->listApplications());
    }
}
