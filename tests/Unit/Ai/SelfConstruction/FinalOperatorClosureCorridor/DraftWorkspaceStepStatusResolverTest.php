<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\FinalOperatorClosureCorridor;

use App\Services\Ai\SelfConstruction\FinalOperatorClosureCorridor\DraftWorkspaceStepStatusResolver;
use Tests\TestCase;

class DraftWorkspaceStepStatusResolverTest extends TestCase
{
    public function test_hash_finalization_completed_when_all_receipts_ready(): void
    {
        self::assertSame(
            'completed',
            DraftWorkspaceStepStatusResolver::draftHashFinalizationStepStatus(true, true, true, true, false, ''),
        );
    }

    public function test_hash_finalization_waiting_when_draft_not_loaded(): void
    {
        self::assertSame(
            'waiting_for_operator_edited_draft_workspace',
            DraftWorkspaceStepStatusResolver::draftHashFinalizationStepStatus(false, false, false, false, false, ''),
        );
    }

    public function test_hash_finalization_ready_when_required(): void
    {
        self::assertSame(
            'ready_to_finalize_operator_draft_workspace_hashes',
            DraftWorkspaceStepStatusResolver::draftHashFinalizationStepStatus(false, false, false, true, true, ''),
        );
    }

    public function test_hash_finalization_review_when_status_ready_to_write(): void
    {
        self::assertSame(
            'ready_for_operator_hash_finalization_review',
            DraftWorkspaceStepStatusResolver::draftHashFinalizationStepStatus(false, false, false, true, false, 'ready_to_write_computed_hashes'),
        );
    }

    public function test_hash_finalization_review_when_partially_ready(): void
    {
        self::assertSame(
            'ready_for_operator_hash_finalization_review',
            DraftWorkspaceStepStatusResolver::draftHashFinalizationStepStatus(false, false, false, true, false, 'partially_ready_to_write_computed_hashes'),
        );
    }

    public function test_hash_finalization_blocked_when_drafts_not_hashable(): void
    {
        self::assertSame(
            'blocked_until_operator_drafts_are_hashable',
            DraftWorkspaceStepStatusResolver::draftHashFinalizationStepStatus(false, false, false, true, false, 'blocked_operator_drafts_not_ready_for_hash_write'),
        );
    }

    public function test_hash_finalization_no_write_required_default(): void
    {
        self::assertSame(
            'operator_draft_workspace_loaded_no_hash_write_required',
            DraftWorkspaceStepStatusResolver::draftHashFinalizationStepStatus(false, false, false, true, false, 'some_other_status'),
        );
    }

    public function test_hash_finalization_missing_inputs_completed(): void
    {
        self::assertSame(
            [],
            DraftWorkspaceStepStatusResolver::draftHashFinalizationMissingInputs(true, true, true, true, false),
        );
    }

    public function test_hash_finalization_missing_inputs_draft_not_loaded(): void
    {
        self::assertSame(
            ['operator_edited_draft_workspace_with_all_required_artifacts'],
            DraftWorkspaceStepStatusResolver::draftHashFinalizationMissingInputs(false, false, false, false, false),
        );
    }

    public function test_hash_finalization_missing_inputs_hash_required(): void
    {
        self::assertSame(
            ['operator_must_run_workspace_hash_finalizer_write_command'],
            DraftWorkspaceStepStatusResolver::draftHashFinalizationMissingInputs(false, false, false, true, true),
        );
    }

    public function test_publisher_completed_when_all_receipts_ready(): void
    {
        self::assertSame(
            'completed',
            DraftWorkspaceStepStatusResolver::draftWorkspacePublisherStepStatus(true, true, true, true, '', 0, 0, false),
        );
    }

    public function test_publisher_completed_json_staged_when_published(): void
    {
        self::assertSame(
            'completed_submission_json_staged',
            DraftWorkspaceStepStatusResolver::draftWorkspacePublisherStepStatus(false, false, false, true, 'operator_draft_workspace_published', 0, 0, false),
        );
    }

    public function test_publisher_completed_json_staged_when_count_3(): void
    {
        self::assertSame(
            'completed_submission_json_staged',
            DraftWorkspaceStepStatusResolver::draftWorkspacePublisherStepStatus(false, false, false, true, '', 3, 3, true),
        );
    }

    public function test_publisher_ready_to_publish(): void
    {
        self::assertSame(
            'ready_to_publish_finalized_operator_draft_workspace',
            DraftWorkspaceStepStatusResolver::draftWorkspacePublisherStepStatus(false, false, false, true, '', 3, 0, true),
        );
    }

    public function test_publisher_blocked_until_publishable(): void
    {
        self::assertSame(
            'blocked_until_all_operator_draft_artifacts_are_publishable',
            DraftWorkspaceStepStatusResolver::draftWorkspacePublisherStepStatus(false, false, false, true, '', 1, 0, false),
        );
    }

    public function test_publisher_blocked_until_finalized(): void
    {
        self::assertSame(
            'blocked_until_operator_draft_hashes_are_finalized',
            DraftWorkspaceStepStatusResolver::draftWorkspacePublisherStepStatus(false, false, false, true, '', 0, 0, false),
        );
    }

    public function test_publisher_missing_inputs_completed(): void
    {
        self::assertSame(
            [],
            DraftWorkspaceStepStatusResolver::draftWorkspacePublisherMissingInputs(true, true, true, true, 0, 0, false),
        );
    }

    public function test_publisher_missing_inputs_when_published_3(): void
    {
        self::assertSame(
            [],
            DraftWorkspaceStepStatusResolver::draftWorkspacePublisherMissingInputs(false, false, false, true, 3, 3, true),
        );
    }

    public function test_publisher_missing_inputs_ready_to_publish(): void
    {
        self::assertSame(
            ['operator_must_run_draft_workspace_publisher_write_command'],
            DraftWorkspaceStepStatusResolver::draftWorkspacePublisherMissingInputs(false, false, false, true, 3, 0, true),
        );
    }

    public function test_publisher_missing_inputs_partial_publishable(): void
    {
        self::assertSame(
            ['operator_must_make_all_three_draft_artifacts_publishable_before_atomic_publish'],
            DraftWorkspaceStepStatusResolver::draftWorkspacePublisherMissingInputs(false, false, false, true, 1, 0, false),
        );
    }

    public function test_publisher_missing_inputs_need_finalization(): void
    {
        self::assertSame(
            ['operator_must_finalize_all_draft_hashes_before_publishing'],
            DraftWorkspaceStepStatusResolver::draftWorkspacePublisherMissingInputs(false, false, false, true, 0, 0, false),
        );
    }

    public function test_all_methods_are_deterministic(): void
    {
        $a = DraftWorkspaceStepStatusResolver::draftHashFinalizationStepStatus(false, false, false, true, false, '');
        $b = DraftWorkspaceStepStatusResolver::draftHashFinalizationStepStatus(false, false, false, true, false, '');
        self::assertSame($a, $b);
    }
}
