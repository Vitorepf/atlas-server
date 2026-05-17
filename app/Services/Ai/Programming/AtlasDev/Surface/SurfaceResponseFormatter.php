<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Surface;

use App\Services\Ai\Programming\AtlasDev\Pipeline\PlanOnlyResult;

/**
 * Canonical, surface-agnostic projection of a {@see PlanOnlyResult}.
 *
 * Every surface adapter starts from the same payload produced here. Surface-
 * specific concerns (Desktop ui_hints, CLI text rendering) are layered on top
 * by the adapter, never inside this formatter.
 *
 * Rules:
 *  - Never decide anything. Routing, risk, provider and completion are
 *    already decided by the core; the formatter only projects.
 *  - Never inject surface strings (panel names, tab ids, button labels).
 *  - Always return canonical artifact arrays, never DTO instances.
 *  - Never leak absolute filesystem paths (F-04). Every artifact projection
 *    is run through {@see HttpResponseRedactor} so workspace and storage
 *    paths are replaced by `<label>/...` and `receipts/<run_id>/...` refs.
 */
final class SurfaceResponseFormatter
{
    public function __construct(
        private readonly HttpResponseRedactor $redactor = new HttpResponseRedactor,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function formatPlanOnly(PlanOnlyResult $result): array
    {
        $summary = $result->toSummaryArray();
        $workspace = $result->envelope->workspace;

        $artifacts = [
            'operation_envelope' => $this->redactor->redactWorkspaceIn(
                $this->envelopePayloadWithoutAbsoluteWorkspace($result),
                $workspace,
            ),
            'compact_sdd' => $this->redactor->redactWorkspaceIn(
                $result->compactSdd->toCanonicalArray(),
                $workspace,
            ),
            'context_retrieval_plan' => $this->redactor->redactWorkspaceIn(
                $result->contextPlan->toCanonicalArray(),
                $workspace,
            ),
            'code_discovery_manifest' => $this->redactor->redactWorkspaceIn(
                $result->discovery->toCanonicalArray(),
                $workspace,
            ),
            'open_brain_projection' => $this->redactor->redactWorkspaceIn(
                $result->projection->toCanonicalArray(),
                $workspace,
            ),
            'mini_programming_spec' => $this->redactor->redactWorkspaceIn(
                $result->miniSpec->toCanonicalArray(),
                $workspace,
            ),
            'task_contract' => $this->redactor->redactWorkspaceIn(
                $result->taskContract->toCanonicalArray(),
                $workspace,
            ),
            'prompt_projection' => $this->redactor->redactWorkspaceIn(
                $result->promptProjection->toCanonicalArray(),
                $workspace,
            ),
        ];

        return [
            'kind' => 'plan_only',
            'run_id' => $summary['run_id'],
            'surface_id' => $summary['surface_id'],
            'workspace_label' => $summary['workspace_label'],
            'workspace_hash' => $summary['workspace_hash'],
            'routing_decision' => $summary['routing_decision'],
            'routing_reasons' => $summary['routing_reasons'],
            'blockers' => $summary['blockers'],
            'task_kind' => $summary['task_kind'],
            'risk_level' => $summary['risk_level'],
            'intent_clarity_level' => $summary['intent_clarity_level'],
            'mode' => $summary['mode'],
            'discovery_confidence' => $summary['discovery_confidence'],
            'expected_files' => $summary['expected_files'],
            'allowed_files' => $summary['allowed_files'],
            'verification_commands' => $summary['verification_commands'],
            'hashes' => $summary['hashes'],
            'persisted_artifact_refs' => $summary['persisted_artifact_refs'],
            'prompt_sendable' => $summary['prompt_sendable'],
            'read_only_answer' => $summary['read_only_answer'],
            'artifacts' => $artifacts,
        ];
    }

    /**
     * Replace the absolute `workspace` key in the envelope projection with the
     * label-only field so the canonical artifact mirrors the summary view.
     *
     * @return array<string, mixed>
     */
    private function envelopePayloadWithoutAbsoluteWorkspace(PlanOnlyResult $result): array
    {
        $envelope = $result->envelope->toCanonicalArray();
        $envelope['workspace_label'] = $this->redactor->workspaceLabel($result->envelope->workspace);
        unset($envelope['workspace']);

        return $envelope;
    }
}
