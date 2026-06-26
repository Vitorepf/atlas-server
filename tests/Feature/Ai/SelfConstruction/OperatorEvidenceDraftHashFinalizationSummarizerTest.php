<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AtlasSelfConstructionOperatorEvidenceDraftHashFinalizerService;
use App\Services\Ai\SelfConstruction\OperatorEvidenceDraftHashFinalizationSummarizer;
use Tests\TestCase;

final class OperatorEvidenceDraftHashFinalizationSummarizerTest extends TestCase
{
    public function test_not_requested_summary_has_status_not_requested_and_empty_write_command(): void
    {
        $summarizer = new OperatorEvidenceDraftHashFinalizationSummarizer;

        $result = $summarizer->notRequested();

        $this->assertSame(AtlasSelfConstructionOperatorEvidenceDraftHashFinalizerService::SCHEMA_VERSION, $result['schema_version']);
        $this->assertSame(AtlasSelfConstructionOperatorEvidenceDraftHashFinalizerService::MODE, $result['mode']);
        $this->assertSame('not_requested', $result['status']);
        $this->assertFalse($result['workspace_loaded']);
        $this->assertSame(0, $result['artifact_count']);
        $this->assertSame(0, $result['ready_artifact_count']);
        $this->assertSame(0, $result['blocked_artifact_count']);
        $this->assertSame(0, $result['written_artifact_count']);
        $this->assertSame([], $result['artifacts']);
        $this->assertSame('', $result['finalizer_hash']);
    }

    public function test_summary_emits_write_command_only_when_workspace_path_is_non_empty(): void
    {
        $summarizer = new OperatorEvidenceDraftHashFinalizationSummarizer;

        $resultEmpty = $summarizer->summary([], '');
        $this->assertSame('', $resultEmpty['write_command']);

        $resultSet = $summarizer->summary([], '/tmp/workspace');
        $this->assertStringContainsString('--operator-draft-workspace-path=/tmp/workspace', $resultSet['write_command']);
        $this->assertStringContainsString('--write-computed-operator-draft-hashes', $resultSet['write_command']);
    }

    public function test_required_returns_true_when_an_artifact_can_write_hash_and_input_does_not_match(): void
    {
        $summarizer = new OperatorEvidenceDraftHashFinalizationSummarizer;

        $finalization = [
            'artifacts' => [
                'runtime_promotion_receipt' => [
                    'can_write_hash_to_draft' => true,
                    'input_hash_matches_computed_hash' => false,
                ],
            ],
        ];

        $this->assertTrue($summarizer->required($finalization));
    }

    public function test_required_returns_false_when_artifacts_match_or_cannot_write(): void
    {
        $summarizer = new OperatorEvidenceDraftHashFinalizationSummarizer;

        $this->assertFalse($summarizer->required([
            'artifacts' => [
                'a' => ['can_write_hash_to_draft' => true, 'input_hash_matches_computed_hash' => true],
            ],
        ]));

        $this->assertFalse($summarizer->required([
            'artifacts' => [
                'a' => ['can_write_hash_to_draft' => false, 'input_hash_matches_computed_hash' => false],
            ],
        ]));

        $this->assertFalse($summarizer->required(['artifacts' => []]));
        $this->assertFalse($summarizer->required([]));
    }

    public function test_summary_normalizes_each_artifact_with_byte_identical_keys(): void
    {
        $summarizer = new OperatorEvidenceDraftHashFinalizationSummarizer;

        $finalization = [
            'status' => 'loaded_for_read_only_submission_readiness',
            'workspace_loaded' => true,
            'artifact_count' => 2,
            'ready_artifact_count' => 1,
            'blocked_artifact_count' => 1,
            'written_artifact_count' => 0,
            'finalizer_hash' => 'abc123',
            'artifacts' => [
                'ready_one' => [
                    'status' => 'ready',
                    'hash_field' => 'computed_runtime_promotion_receipt_sha256',
                    'original_hash' => 'orig',
                    'computed_hash' => 'comp',
                    'input_hash_matches_computed_hash' => false,
                    'can_write_hash_to_draft' => true,
                    'write_blocker' => '',
                    'placeholder_fields' => ['x' => 'y'],
                    'invalid_hash_fields' => [],
                    'forbidden_flags_true' => [],
                    'extra_field_to_drop' => 'ignored',
                ],
            ],
        ];

        $result = $summarizer->summary($finalization, '/tmp/ws');

        $expectedKeys = [
            'status', 'hash_field', 'original_hash', 'computed_hash',
            'input_hash_matches_computed_hash', 'can_write_hash_to_draft',
            'write_blocker', 'placeholder_fields', 'invalid_hash_fields',
            'forbidden_flags_true',
        ];
        $this->assertSame($expectedKeys, array_keys($result['artifacts']['ready_one']));
        $this->assertSame(2, $result['artifact_count']);
        $this->assertSame(1, $result['ready_artifact_count']);
        $this->assertSame(1, $result['blocked_artifact_count']);
        $this->assertSame('abc123', $result['finalizer_hash']);
        $this->assertFalse($result['can_write_from_submission_readiness']);
        $this->assertFalse($result['can_persist_from_submission_readiness']);
    }
}
