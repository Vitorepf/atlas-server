<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Surface;

use App\Http\Controllers\AtlasDev\Support\RunExecutionResult;
use App\Services\Ai\Programming\AtlasDev\Pipeline\IntakeNormalizer;
use App\Services\Ai\Programming\AtlasDev\Pipeline\PlanOnlyResult;
use App\Services\Ai\Programming\AtlasDev\Schemas\OperationEnvelope;
use InvalidArgumentException;

/**
 * Atlas API Interaction (`atlas_api_interaction`) surface adapter.
 *
 * Raw API surface used by third-party integrations and the Atlas AI Router
 * itself. The response is OpenAPI-friendly: top-level `status`, canonical
 * `routing_decision`, full hash bundle, artifact refs, and the inline
 * artifact bodies (already path-redacted by SurfaceResponseFormatter). No
 * ui_hints — that vocabulary belongs to Desktop only.
 *
 * Atlas AI > Router > Atlas Dev: the Router is the dominant caller of this
 * adapter. It posts `flow_origin=atlas_ai_router` plus the resolved
 * `command_intent`. Direct API consumers (third parties) stamp
 * `flow_origin=direct` and typically leave `command_intent` null.
 */
final class AtlasApiInteractionAdapter implements AtlasDevSurfaceAdapter
{
    public const SURFACE_ID = 'atlas_api_interaction';

    private const ACCEPTED_SURFACE_IDS = [
        self::SURFACE_ID,
    ];

    public function __construct(
        private readonly IntakeNormalizer $intake,
        private readonly SurfaceResponseFormatter $formatter,
        private readonly HttpResponseRedactor $redactor = new HttpResponseRedactor,
        private readonly SurfaceStatusMapper $status = new SurfaceStatusMapper,
    ) {}

    public function surfaceId(): string
    {
        return self::SURFACE_ID;
    }

    public function buildEnvelope(array $payload): OperationEnvelope
    {
        $surfaceId = $this->resolveSurfaceId($payload);
        $workspace = $this->stringField($payload, 'workspace');
        $rawIntent = $this->stringField($payload, 'raw_intent');
        $userConstraints = $this->stringListField($payload, 'user_constraints');
        $surfaceHints = $this->extractSurfaceHints($payload);

        if ($rawIntent === '') {
            throw new InvalidArgumentException('atlas_api_interaction payload requires non-empty raw_intent.');
        }

        return $this->intake->normalize(
            surfaceId: $surfaceId,
            workspace: $workspace,
            rawIntent: $rawIntent,
            userConstraints: $userConstraints,
            surfaceHints: $surfaceHints,
        );
    }

    public function formatPlanOnly(PlanOnlyResult $result): array
    {
        $base = $this->formatter->formatPlanOnly($result);
        $status = $this->status->planOnlyStatus($result);
        $workspace = $result->envelope->workspace;

        // The base formatter still surfaces a few raw fields whose values can
        // contain absolute paths (e.g. mini_spec.expected_files written by the
        // SpecComposer using the resolved workspace prefix). The API surface
        // exposes ONLY refs and redacted file lists so the client never sees
        // the operator's home directory.
        $expectedFiles = $this->redactPathList($base['expected_files'], $workspace);
        $allowedFiles = $this->redactPathList($base['allowed_files'], $workspace);

        return [
            'status' => $status,
            'kind' => 'plan_only',
            'run_id' => $base['run_id'],
            'surface_id' => self::SURFACE_ID,
            'flow_id' => $result->envelope->flowId,
            'flow_origin' => $result->envelope->flowOrigin,
            'command_intent' => $result->envelope->commandIntent,
            'routing_decision' => $base['routing_decision'],
            'routing_reasons' => $base['routing_reasons'],
            'suggested_flow' => $result->suggestedFlow(),
            'completion_state' => null,
            'workspace_label' => $base['workspace_label'],
            'workspace_hash' => $base['workspace_hash'],
            'task_kind' => $base['task_kind'],
            'risk_level' => $base['risk_level'],
            'intent_clarity_level' => $base['intent_clarity_level'],
            'mode' => $base['mode'],
            'discovery_confidence' => $base['discovery_confidence'],
            'expected_files' => $expectedFiles,
            'allowed_files' => $allowedFiles,
            'verification_commands' => $base['verification_commands'],
            'blockers' => $base['blockers'],
            'hashes' => $base['hashes'],
            'artifacts' => [
                'refs' => $base['persisted_artifact_refs'],
            ],
            'prompt_sendable' => $base['prompt_sendable'],
        ];
    }

    /**
     * Redact a flat list of paths via {@see HttpResponseRedactor::redactWorkspaceIn()}.
     *
     * @param  list<mixed>  $paths
     * @return list<string>
     */
    private function redactPathList(array $paths, string $workspace): array
    {
        $redacted = $this->redactor->redactWorkspaceIn(['list' => $paths], $workspace);
        $out = [];
        foreach ($redacted['list'] ?? [] as $item) {
            if (is_string($item)) {
                $out[] = $item;
            }
        }

        return $out;
    }

    /**
     * Format the result of `POST .../run`. OpenAPI-friendly version of
     * {@see RunExecutionResult::toArray()}.
     *
     * Absolute paths inside `persisted_receipt_paths` are redacted to
     * `receipts/<run_id>/...`. The full provider_call sanitized summary is
     * preserved as it already excludes raw stdout/stderr.
     *
     * @param  array<string, mixed>  $runResult
     * @return array<string, mixed>
     */
    public function formatRunResult(array $runResult, OperationEnvelope $envelope, string $runId): array
    {
        $completion = (string) ($runResult['completion_state'] ?? 'unknown');
        $absolute = is_array($runResult['persisted_receipt_paths'] ?? null)
            ? $runResult['persisted_receipt_paths']
            : [];

        return [
            'status' => $completion,
            'kind' => 'run',
            'run_id' => $runId,
            'surface_id' => self::SURFACE_ID,
            'flow_id' => $envelope->flowId,
            'flow_origin' => $envelope->flowOrigin,
            'command_intent' => $envelope->commandIntent,
            'workspace_label' => $this->redactor->workspaceLabel($envelope->workspace),
            'workspace_hash' => $envelope->workspaceHash,
            'completion_state' => $completion,
            'scope_guard_status' => $runResult['scope_guard_status'] ?? null,
            'verification_status' => $runResult['verification_status'] ?? null,
            'hashes' => [
                'verification_receipt' => $runResult['verification_receipt_hash'] ?? null,
                'scope_guard_receipt' => $runResult['scope_guard_receipt_hash'] ?? null,
                'diff' => $runResult['diff_hash'] ?? null,
            ],
            'provider_call' => is_array($runResult['provider_call'] ?? null) ? $runResult['provider_call'] : [],
            'persisted_receipt_refs' => $this->redactor->artifactRefs($runId, $absolute),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function resolveSurfaceId(array $payload): string
    {
        $candidate = $payload['surface_id'] ?? self::SURFACE_ID;
        if (! is_string($candidate) || $candidate === '') {
            return self::SURFACE_ID;
        }
        if (! in_array($candidate, self::ACCEPTED_SURFACE_IDS, true)) {
            throw new InvalidArgumentException(
                "Unsupported surface_id '{$candidate}' for Atlas API Interaction adapter."
            );
        }

        return self::SURFACE_ID;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function stringField(array $payload, string $key): string
    {
        $value = $payload[$key] ?? '';
        if (! is_string($value)) {
            return '';
        }

        return trim($value);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    private function stringListField(array $payload, string $key): array
    {
        $value = $payload[$key] ?? [];
        if (! is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $entry) {
            if (is_string($entry) && trim($entry) !== '') {
                $out[] = trim($entry);
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function extractSurfaceHints(array $payload): array
    {
        $hints = [];
        // Generic API vocabulary (third parties + Router). Composer keys are
        // optional; most API callers won't send them.
        foreach (['thread_id', 'conversation_id', 'composer_mode', 'composer_task', 'provider_choice', 'previous_run_id'] as $key) {
            $value = $payload[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                $hints[$key] = trim($value);
            }
        }
        if (isset($payload['operator_explicit']) && is_bool($payload['operator_explicit'])) {
            $hints['operator_explicit'] = $payload['operator_explicit'];
        }
        if (isset($payload['flow_origin']) && is_string($payload['flow_origin']) && trim($payload['flow_origin']) !== '') {
            $hints['flow_origin'] = trim($payload['flow_origin']);
        }
        if (isset($payload['command_intent']) && is_string($payload['command_intent']) && trim($payload['command_intent']) !== '') {
            $hints['command_intent'] = trim($payload['command_intent']);
        }

        return $hints;
    }
}
