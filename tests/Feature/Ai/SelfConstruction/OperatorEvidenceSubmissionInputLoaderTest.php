<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\OperatorEvidenceSubmissionInputLoader;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class OperatorEvidenceSubmissionInputLoaderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_load_canonical_submission_input_with_no_canonical_files_yields_no_canonical_submission_files_loaded_status(): void
    {
        $loader = new OperatorEvidenceSubmissionInputLoader;

        $result = $loader->loadCanonicalSubmissionInput();

        $this->assertSame(
            'atlas.self_construction.operator_evidence_canonical_submission_input.v1',
            $result['schema_version']
        );
        $this->assertSame('no_canonical_submission_files_loaded', $result['status']);
        $this->assertSame(3, $result['artifact_count']);
        $this->assertSame([], $result['loaded_artifacts']);
        $this->assertSame(0, $result['loaded_artifact_count']);
        $this->assertCount(3, $result['files']);
        foreach ($result['files'] as $file) {
            $this->assertFalse($file['exists']);
            $this->assertFalse($file['loaded']);
            $this->assertSame('', $file['json_sha256']);
        }
        $this->assertSame([], $result['violations']);
    }

    public function test_load_canonical_submission_input_picks_up_present_files_with_sha256(): void
    {
        Storage::disk('local')->put(
            'atlas/self-construction/operator-submissions/runtime-promotion.json',
            json_encode(['ok' => true])
        );

        $loader = new OperatorEvidenceSubmissionInputLoader;

        $result = $loader->loadCanonicalSubmissionInput();

        $this->assertSame('loaded_for_read_only_submission_readiness', $result['status']);
        $this->assertSame(['runtime_promotion_receipt'], $result['loaded_artifacts']);
        $this->assertSame(1, $result['loaded_artifact_count']);
        $loaded = collect($result['files'])->firstWhere('artifact', 'runtime_promotion_receipt');
        $this->assertTrue($loaded['exists']);
        $this->assertTrue($loaded['loaded']);
        $this->assertNotSame('', $loaded['json_sha256']);
        $this->assertSame(['ok' => true], $result['payloads']['runtime_promotion_receipt']);
    }

    public function test_load_draft_workspace_input_with_empty_requested_path_yields_not_requested(): void
    {
        $loader = new OperatorEvidenceSubmissionInputLoader;

        $result = $loader->loadDraftWorkspaceInput('');

        $this->assertSame('not_requested', $result['status']);
        $this->assertSame('', $result['requested_path']);
        $this->assertSame('', $result['manifest_path']);
        $this->assertSame(0, $result['artifact_count']);
        $this->assertSame([], $result['loaded_artifacts']);
        $this->assertSame([], $result['violations']);
        $this->assertSame([], $result['warnings']);
        $this->assertSame([], $result['payloads']);
    }

    public function test_load_draft_workspace_input_with_whitespace_only_requested_path_yields_not_requested(): void
    {
        $loader = new OperatorEvidenceSubmissionInputLoader;

        $result = $loader->loadDraftWorkspaceInput('   ');

        $this->assertSame('not_requested', $result['status']);
        $this->assertSame('', $result['requested_path']);
    }
}
