<?php

namespace App\Services\Ai\Hermes;

use App\Models\AiJob;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class HermesExecutiveMissionFactory
{
    /**
     * @param  array<string,mixed>  $provider
     * @param  array<string,mixed>  $runtime
     * @return array<string,mixed>
     */
    public function build(AiJob $job, string $prompt, array $provider, array $runtime = []): array
    {
        $payload = is_array($job->payload) ? $job->payload : [];
        $metadata = is_array($job->metadata) ? $job->metadata : [];
        $permissionMode = $this->string(data_get($payload, 'tool_permissions.mode')) ?: 'read';
        $memoryPolicy = $this->memoryPolicy(data_get($payload, 'hermes.memory_policy') ?: ($provider['memory_policy'] ?? 'off'));
        $gatewayAllowed = (bool) data_get($payload, 'hermes.gateway_allowed', false);
        $operatorPromptHash = hash('sha256', $prompt);

        $mission = [
            'schema_version' => 'atlas.hermes.executive_mission.v1',
            'mission_id' => $this->missionId($job, $operatorPromptHash),
            'issued_by' => 'atls',
            'runtime_role' => 'executive_runtime',
            'objective' => $this->objective($job, $payload, $prompt),
            'operator_intent' => $this->firstString([
                data_get($payload, 'task_request.task_type'),
                data_get($payload, 'task_type'),
                data_get($metadata, 'intent'),
                $job->kind,
                'interaction',
            ]),
            'scope' => [
                'permission_mode' => $permissionMode,
                'network_policy' => $this->firstString([
                    data_get($payload, 'hermes.network_policy'),
                    data_get($payload, 'tool_permissions.network_policy'),
                    $gatewayAllowed ? 'limited_gateway_delivery' : null,
                    'limited',
                ]),
                'allowed_paths' => $this->paths(data_get($payload, 'tool_permissions.allowed_roots')),
                'forbidden_paths' => $this->paths(data_get($payload, 'hermes.forbidden_paths')),
                'allowed_tools' => $this->csvList(data_get($payload, 'hermes.toolsets') ?: ($provider['toolsets'] ?? null)),
            ],
            'context_pack' => [
                'prompt_hash' => $operatorPromptHash,
                'context_refs_hash' => $this->hashValue($job->context_refs ?? data_get($payload, 'context_refs')),
                'context_pack_hash' => $this->hashValue(data_get($payload, 'context_pack')),
                'decision_receipt_hash' => $this->hashValue(data_get($payload, 'decision_receipt')),
                'kernel_envelope_hash' => $this->hashValue(data_get($payload, 'kernel')),
            ],
            'runtime' => [
                'adapter' => 'hermes_cli',
                'profile' => $this->profile($payload, $provider),
                'model_policy' => 'decided_by_atls',
                'model' => $this->string($job->model ?: ($provider['model'] ?? null)),
                'provider' => $this->string(data_get($payload, 'hermes.provider') ?: ($provider['provider'] ?? null)),
                'toolsets' => $this->csvList(data_get($payload, 'hermes.toolsets') ?: ($provider['toolsets'] ?? null)),
                'skills' => $this->csvList(data_get($payload, 'hermes.skills') ?: ($provider['skills'] ?? null)),
                'source' => $this->firstString([data_get($payload, 'hermes.source'), $provider['source'] ?? null, 'tool']),
                'max_turns' => $this->positiveInt(data_get($payload, 'hermes.max_turns') ?: ($provider['max_turns'] ?? null)),
                'gateway_allowed' => $gatewayAllowed,
                'resume' => $this->string(data_get($payload, 'hermes.resume')),
                'continue' => data_get($payload, 'hermes.continue') === true || is_string(data_get($payload, 'hermes.continue')),
                'worktree' => (bool) (data_get($payload, 'hermes.worktree') ?? ($provider['worktree'] ?? false)),
                'invocation' => $runtime,
            ],
            'success_criteria' => $this->listOrDefault(data_get($payload, 'hermes.success_criteria') ?: data_get($payload, 'task_request.success_criteria'), [
                'Hermes returns a bounded provider output or a structured failure.',
                'Atlas records a HermesResultPacket before any downstream claim.',
                'No memory is promoted without Atlas Memory Gate review.',
            ]),
            'validation' => [
                'required_commands' => $this->listOrDefault(data_get($payload, 'hermes.validation.required_commands'), []),
                'evidence_required' => true,
                'atlas_verifier_is_authority' => true,
            ],
            'memory_policy' => [
                'hermes_memory' => $memoryPolicy,
                'canonical_memory' => 'atlas',
                'memory_delta_must_return_as_candidate' => true,
                'promotion_requires_atlas_memory_gate' => true,
                'promotion_allowed_now' => false,
            ],
            'approval_policy' => [
                'atlas_permission_mode' => $permissionMode,
                'danger_mode_required_for_yolo' => true,
                'human_required_for_gateway_enablement' => ! $gatewayAllowed,
                'human_required_for_memory_promotion' => true,
            ],
            'response_contract' => [
                'format' => $this->firstString([data_get($payload, 'hermes.response_format'), 'final_report']),
                'result_packet_required' => true,
                'memory_delta_candidates_optional' => true,
            ],
            'sovereignty' => [
                'atlas_is_sovereign' => true,
                'atlas_decide_required' => true,
                'decision_receipt_required' => true,
                'provider_is_executor_only' => true,
                'provider_may_not_be_treated_as_atlas_identity' => true,
                'provider_may_not_promote_memory' => true,
            ],
        ];

        $mission['mission_hash'] = $this->hashValue(Arr::except($mission, ['mission_hash']));

        return $mission;
    }

    private function missionId(AiJob $job, string $promptHash): string
    {
        $jobId = $job->getKey() ? (string) $job->getKey() : 'pending';
        $traceId = $job->trace_id ? (string) $job->trace_id : 'no-trace';

        return 'hermes_mission_'.substr(hash('sha256', implode('|', [$jobId, $traceId, $promptHash])), 0, 24);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function objective(AiJob $job, array $payload, string $prompt): string
    {
        return Str::limit($this->firstString([
            data_get($payload, 'task_request.objective'),
            data_get($payload, 'dev_execution_plan.objective'),
            $job->input_text,
            $prompt,
        ]) ?: 'Hermes executive mission', 420, '');
    }

    /**
     * @param  array<string,mixed>  $payload
     * @param  array<string,mixed>  $provider
     */
    private function profile(array $payload, array $provider): string
    {
        $profile = $this->string(data_get($payload, 'hermes.profile') ?: ($provider['profile'] ?? null));
        if ($profile !== null) {
            return $profile;
        }

        if ((bool) data_get($payload, 'hermes.gateway_allowed', false)) {
            return 'atlas-hermes-gateway';
        }

        $skills = implode(',', $this->csvList(data_get($payload, 'hermes.skills') ?: ($provider['skills'] ?? null)));
        if (str_contains($skills, 'research')) {
            return 'atlas-hermes-researcher';
        }

        $tools = implode(',', $this->csvList(data_get($payload, 'hermes.toolsets') ?: ($provider['toolsets'] ?? null)));
        if (str_contains($tools, 'shell') || str_contains($tools, 'filesystem')) {
            return 'atlas-hermes-coder';
        }

        return 'atlas-hermes-ops';
    }

    /**
     * @param  array<int,mixed>|mixed  $value
     * @return array<int,string>
     */
    private function paths(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn (mixed $path): ?string => $this->string($path),
            $value,
        )));
    }

    /**
     * @return array<int,string>
     */
    private function csvList(mixed $value): array
    {
        if (is_array($value)) {
            $items = $value;
        } elseif (is_string($value) || is_numeric($value)) {
            $items = preg_split('/\s*,\s*/', (string) $value) ?: [];
        } else {
            $items = [];
        }

        return array_values(array_unique(array_filter(array_map(
            fn (mixed $item): ?string => $this->string($item),
            $items,
        ))));
    }

    /**
     * @param  array<int,string>  $default
     * @return array<int,string>
     */
    private function listOrDefault(mixed $value, array $default): array
    {
        $items = $this->csvList($value);

        return $items === [] ? $default : $items;
    }

    /**
     * @param  array<int,mixed>  $values
     */
    private function firstString(array $values): ?string
    {
        foreach ($values as $value) {
            $string = $this->string($value);
            if ($string !== null) {
                return $string;
            }
        }

        return null;
    }

    private function string(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : Str::limit($value, 500, '');
    }

    private function positiveInt(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $value = (int) $value;

        return $value > 0 ? $value : null;
    }

    private function memoryPolicy(mixed $value): string
    {
        $policy = $this->string($value) ?: 'off';

        return in_array($policy, ['off', 'operational_only', 'atlas_adapter'], true) ? $policy : 'off';
    }

    private function hashValue(mixed $value): ?string
    {
        if ($value === null || $value === []) {
            return null;
        }

        try {
            return hash('sha256', json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } catch (\JsonException) {
            return hash('sha256', serialize($value));
        }
    }
}
