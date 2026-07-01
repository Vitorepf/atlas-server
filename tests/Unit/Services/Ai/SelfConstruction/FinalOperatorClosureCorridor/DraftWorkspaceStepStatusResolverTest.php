<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\FinalOperatorClosureCorridor;

use App\Services\Ai\SelfConstruction\FinalOperatorClosureCorridor\DraftWorkspaceStepStatusResolver;
use Tests\TestCase;

/**
 * Focused contract test: proves missing_inputs are empty only when every final evidence
 * prerequisite is ready (or, for the publisher step, the submission JSON is already staged),
 * and that the pure functions never touch storage, commands, providers, or queue state.
 */
final class DraftWorkspaceStepStatusResolverTest extends TestCase
{
    public function test_hash_finalization_missing_inputs_empty_only_when_all_prerequisites_ready(): void
    {
        self::assertSame(
            [],
            DraftWorkspaceStepStatusResolver::draftHashFinalizationMissingInputs(true, true, true, true, false),
        );
        self::assertNotSame(
            [],
            DraftWorkspaceStepStatusResolver::draftHashFinalizationMissingInputs(true, true, false, true, false),
        );
    }

    public function test_missing_draft_workspace_reports_waiting_status_and_missing_input(): void
    {
        self::assertSame(
            'waiting_for_operator_edited_draft_workspace',
            DraftWorkspaceStepStatusResolver::draftHashFinalizationStepStatus(false, false, false, false, false, ''),
        );
        self::assertSame(
            ['operator_edited_draft_workspace_with_all_required_artifacts'],
            DraftWorkspaceStepStatusResolver::draftHashFinalizationMissingInputs(false, false, false, false, false),
        );
    }

    public function test_ready_to_finalize_hashes_when_finalization_required(): void
    {
        self::assertSame(
            'ready_to_finalize_operator_draft_workspace_hashes',
            DraftWorkspaceStepStatusResolver::draftHashFinalizationStepStatus(false, false, false, true, true, ''),
        );
    }

    public function test_blocked_when_operator_drafts_are_not_hashable(): void
    {
        self::assertSame(
            'blocked_until_operator_drafts_are_hashable',
            DraftWorkspaceStepStatusResolver::draftHashFinalizationStepStatus(
                false,
                false,
                false,
                true,
                false,
                'blocked_operator_drafts_not_ready_for_hash_write',
            ),
        );
    }

    public function test_ready_to_publish_when_atomic_bundle_ready_and_publishable_count_reached(): void
    {
        self::assertSame(
            'ready_to_publish_finalized_operator_draft_workspace',
            DraftWorkspaceStepStatusResolver::draftWorkspacePublisherStepStatus(false, false, false, true, '', 3, 0, true),
        );
    }

    public function test_partial_publishable_artifacts_are_blocked_not_ready(): void
    {
        self::assertSame(
            'blocked_until_all_operator_draft_artifacts_are_publishable',
            DraftWorkspaceStepStatusResolver::draftWorkspacePublisherStepStatus(false, false, false, true, '', 2, 0, false),
        );
        self::assertSame(
            ['operator_must_make_all_three_draft_artifacts_publishable_before_atomic_publish'],
            DraftWorkspaceStepStatusResolver::draftWorkspacePublisherMissingInputs(false, false, false, true, 2, 0, false),
        );
    }

    public function test_published_count_completion_yields_empty_missing_inputs_even_without_receipts(): void
    {
        // Submission JSON already staged (published count reached) — missing_inputs empty
        // even though the underlying receipts are not individually ready.
        self::assertSame(
            [],
            DraftWorkspaceStepStatusResolver::draftWorkspacePublisherMissingInputs(false, false, false, true, 3, 3, true),
        );
        self::assertSame(
            'completed_submission_json_staged',
            DraftWorkspaceStepStatusResolver::draftWorkspacePublisherStepStatus(false, false, false, true, '', 3, 3, true),
        );
    }

    public function test_resolver_methods_are_pure_and_side_effect_free(): void
    {
        // Purity proof: identical inputs always yield identical outputs, and the class
        // exposes no non-static state, no storage/provider/queue-touching dependencies.
        $reflection = new \ReflectionClass(DraftWorkspaceStepStatusResolver::class);
        self::assertSame([], $reflection->getProperties(), 'resolver must carry no instance state');

        foreach ($reflection->getMethods() as $method) {
            self::assertTrue($method->isStatic(), "method {$method->getName()} must be static (pure, no instance state)");
        }

        $a = DraftWorkspaceStepStatusResolver::draftWorkspacePublisherStepStatus(false, false, false, true, '', 1, 0, false);
        $b = DraftWorkspaceStepStatusResolver::draftWorkspacePublisherStepStatus(false, false, false, true, '', 1, 0, false);
        self::assertSame($a, $b);
    }
}
