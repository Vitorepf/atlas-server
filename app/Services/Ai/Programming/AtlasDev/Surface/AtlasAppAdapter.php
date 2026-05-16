<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Surface;

use App\Http\Controllers\AtlasDev\Support\RunExecutionResult;
use App\Services\Ai\Programming\AtlasDev\Pipeline\IntakeNormalizer;
use App\Services\Ai\Programming\AtlasDev\Pipeline\PlanOnlyResult;
use App\Services\Ai\Programming\AtlasDev\Schemas\OperationEnvelope;
use InvalidArgumentException;

/**
 * Atlas App (mobile, `atlas_app`) surface adapter.
 *
 * Mobile clients render a compact plan summary: short status string, badges
 * for risk/task_kind, file counts, and the relative artifact refs. There is
 * no diff preview, no command runner, no Desktop-style cockpit panels.
 *
 * Contract (per Atlas Dev efficient flow doc): the adapter is the ONLY layer
 * that knows mobile-specific wire keys. The IntakeNormalizer accepts the
 * generic 4-arg signature; mobile payload shape is translated here.
 *
 * Atlas AI > Router > Atlas Dev: when the Router has resolved a command
 * intent, the mobile payload carries `flow_origin=atlas_ai_router` plus
 * `command_intent`. Direct CLI/dev clients (rare on mobile, but possible
 * via an SSH gateway) stamp `flow_origin=direct`. The runtime treats both
 * paths identically; the field is auditable metadata only.
 */
final class AtlasAppAdapter implements AtlasDevSurfaceAdapter
{
    use SurfacePayloadFields;

    public const SURFACE_ID = 'atlas_app';

    /**
     * Mobile clients normally send the canonical id. We don't accept any
     * legacy aliases here — anything else is a misrouted request and 400.
     */
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
            throw new InvalidArgumentException('atlas_app payload requires non-empty raw_intent.');
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

        return [
            'kind' => 'plan_only',
            'status' => $status,
            'run_id' => $base['run_id'],
            'surface_id' => self::SURFACE_ID,
            'flow_id' => $result->envelope->flowId,
            'flow_origin' => $result->envelope->flowOrigin,
            'command_intent' => $result->envelope->commandIntent,
            'routing_decision' => $base['routing_decision'],
            'suggested_flow' => $result->suggestedFlow(),
            'workspace_label' => $base['workspace_label'],
            'workspace_hash' => $base['workspace_hash'],
            'summary' => [
                'task_kind' => $base['task_kind'],
                'risk_level' => $base['risk_level'],
                'mode' => $base['mode'],
                'discovery_confidence' => $base['discovery_confidence'],
                'intent_clarity_level' => $base['intent_clarity_level'],
                'files_affected' => count($base['allowed_files']),
                'verification_commands_count' => count($base['verification_commands']),
            ],
            'badges' => $this->badges($base, $status),
            'blockers' => $base['blockers'],
            'hashes' => $base['hashes'],
            'artifact_refs' => $base['persisted_artifact_refs'],
            'prompt_sendable' => $base['prompt_sendable'],
        ];
    }

    /**
     * Format the result of `POST .../run`. Mobile-friendly version of
     * {@see RunExecutionResult::toArray()}.
     *
     * The caller passes the array form of `RunExecutionResult` so this
     * service does not import from the Http layer. Absolute paths inside
     * `persisted_receipt_paths` are redacted to `receipts/<run_id>/...`.
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
            'kind' => 'run',
            'status' => $completion,
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
            'badges' => $this->runBadges($runResult, $completion),
            'hashes' => [
                'verification_receipt' => $runResult['verification_receipt_hash'] ?? null,
                'scope_guard_receipt' => $runResult['scope_guard_receipt_hash'] ?? null,
                'diff' => $runResult['diff_hash'] ?? null,
            ],
            'receipt_refs' => $this->redactor->artifactRefs($runId, $absolute),
            'provider_call' => $this->compactProviderCall(
                is_array($runResult['provider_call'] ?? null) ? $runResult['provider_call'] : [],
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $base
     * @return list<array{label:string,tone:string}>
     */
    private function badges(array $base, string $status): array
    {
        $badges = [
            ['label' => (string) $base['risk_level'], 'tone' => $this->riskTone((string) $base['risk_level'])],
            ['label' => (string) $base['task_kind'], 'tone' => 'info'],
        ];
        if ($base['blockers'] !== []) {
            $badges[] = ['label' => 'blockers:'.count($base['blockers']), 'tone' => 'danger'];
        }
        if ($status === SurfaceStatusMapper::STATUS_DELEGATED) {
            $badges[] = ['label' => 'delegated', 'tone' => 'warning'];
        }

        return $badges;
    }

    /**
     * @param  array<string, mixed>  $runResult
     * @return list<array{label:string,tone:string}>
     */
    private function runBadges(array $runResult, string $completion): array
    {
        $badges = [['label' => $completion, 'tone' => $completion === 'passed' ? 'success' : 'warning']];
        $verification = (string) ($runResult['verification_status'] ?? '');
        if ($verification !== '' && $verification !== 'passed') {
            $badges[] = ['label' => 'verify:'.$verification, 'tone' => 'warning'];
        }
        $scope = (string) ($runResult['scope_guard_status'] ?? '');
        if ($scope !== '' && $scope !== 'passed') {
            $badges[] = ['label' => 'scope:'.$scope, 'tone' => 'warning'];
        }

        return $badges;
    }

    /**
     * @param  array<string, mixed>  $provider
     * @return array<string, mixed>
     */
    private function compactProviderCall(array $provider): array
    {
        return [
            'provider' => $provider['provider'] ?? null,
            'model_family' => $provider['model_family'] ?? null,
            'duration_ms' => $provider['duration_ms'] ?? null,
            'tokens_in' => $provider['tokens_in'] ?? null,
            'tokens_out' => $provider['tokens_out'] ?? null,
        ];
    }

    private function riskTone(string $risk): string
    {
        return match ($risk) {
            'R0', 'R1' => 'info',
            'R2', 'R3' => 'warning',
            'R4', 'R5' => 'danger',
            default => 'info',
        };
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
                "Unsupported surface_id '{$candidate}' for Atlas App adapter."
            );
        }

        return self::SURFACE_ID;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function extractSurfaceHints(array $payload): array
    {
        $hints = [];
        // Mobile vocabulary: same keys as Desktop, no Desktop-only ones.
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
