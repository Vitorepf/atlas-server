<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\FinalOperatorClosureCorridor;

/**
 * Resolves step-status and missing-inputs for the draft-workspace finalization
 * and publishing steps of the operator closure corridor.
 *
 * Extracted from AtlasSelfConstructionFinalOperatorEvidenceClosureCorridorService
 * to reduce the god-class. All methods are pure — no instance state.
 */
final class DraftWorkspaceStepStatusResolver
{
    public static function draftHashFinalizationStepStatus(
        bool $runtimeReceiptReady,
        bool $realProviderSmokeReady,
        bool $humanReceiptReady,
        bool $draftWorkspaceLoaded,
        bool $draftHashFinalizationRequired,
        string $draftHashFinalizationStatus,
    ): string {
        if ($runtimeReceiptReady && $realProviderSmokeReady && $humanReceiptReady) {
            return 'completed';
        }
        if (! $draftWorkspaceLoaded) {
            return 'waiting_for_operator_edited_draft_workspace';
        }
        if ($draftHashFinalizationRequired) {
            return 'ready_to_finalize_operator_draft_workspace_hashes';
        }
        if (in_array($draftHashFinalizationStatus, ['ready_to_write_computed_hashes', 'partially_ready_to_write_computed_hashes'], true)) {
            return 'ready_for_operator_hash_finalization_review';
        }
        if ($draftHashFinalizationStatus === 'blocked_operator_drafts_not_ready_for_hash_write') {
            return 'blocked_until_operator_drafts_are_hashable';
        }

        return 'operator_draft_workspace_loaded_no_hash_write_required';
    }

    /**
     * @return list<string>
     */
    public static function draftHashFinalizationMissingInputs(
        bool $runtimeReceiptReady,
        bool $realProviderSmokeReady,
        bool $humanReceiptReady,
        bool $draftWorkspaceLoaded,
        bool $draftHashFinalizationRequired,
    ): array {
        if ($runtimeReceiptReady && $realProviderSmokeReady && $humanReceiptReady) {
            return [];
        }
        if (! $draftWorkspaceLoaded) {
            return ['operator_edited_draft_workspace_with_all_required_artifacts'];
        }
        if ($draftHashFinalizationRequired) {
            return ['operator_must_run_workspace_hash_finalizer_write_command'];
        }

        return ['operator_must_finish_draft_workspace_or_run_readiness'];
    }

    public static function draftWorkspacePublisherStepStatus(
        bool $runtimeReceiptReady,
        bool $realProviderSmokeReady,
        bool $humanReceiptReady,
        bool $draftWorkspaceLoaded,
        string $draftWorkspacePublisherStatus,
        int $draftWorkspacePublishableCount,
        int $draftWorkspacePublishedCount,
        bool $draftWorkspaceAtomicBundleReady,
    ): string {
        if ($runtimeReceiptReady && $realProviderSmokeReady && $humanReceiptReady) {
            return 'completed';
        }
        if ($draftWorkspacePublisherStatus === 'operator_draft_workspace_published' || $draftWorkspacePublishedCount >= 3) {
            return 'completed_submission_json_staged';
        }
        if (! $draftWorkspaceLoaded) {
            return 'waiting_for_operator_edited_draft_workspace';
        }
        if ($draftWorkspaceAtomicBundleReady && $draftWorkspacePublishableCount >= 3) {
            return 'ready_to_publish_finalized_operator_draft_workspace';
        }
        if ($draftWorkspacePublishableCount > 0) {
            return 'blocked_until_all_operator_draft_artifacts_are_publishable';
        }

        return 'blocked_until_operator_draft_hashes_are_finalized';
    }

    /**
     * @return list<string>
     */
    public static function draftWorkspacePublisherMissingInputs(
        bool $runtimeReceiptReady,
        bool $realProviderSmokeReady,
        bool $humanReceiptReady,
        bool $draftWorkspaceLoaded,
        int $draftWorkspacePublishableCount,
        int $draftWorkspacePublishedCount,
        bool $draftWorkspaceAtomicBundleReady,
    ): array {
        if ($runtimeReceiptReady && $realProviderSmokeReady && $humanReceiptReady) {
            return [];
        }
        if ($draftWorkspacePublishedCount >= 3) {
            return [];
        }
        if (! $draftWorkspaceLoaded) {
            return ['operator_edited_draft_workspace_with_all_required_artifacts'];
        }
        if ($draftWorkspaceAtomicBundleReady && $draftWorkspacePublishableCount >= 3) {
            return ['operator_must_run_draft_workspace_publisher_write_command'];
        }
        if ($draftWorkspacePublishableCount > 0) {
            return ['operator_must_make_all_three_draft_artifacts_publishable_before_atomic_publish'];
        }

        return ['operator_must_finalize_all_draft_hashes_before_publishing'];
    }
}
