<?php

namespace App\Http\Controllers;

use App\Services\Ai\Policy\AtlasAiPolicyService;
use App\Services\Ai\Policy\AtlasDomainProfilePolicyService;
use App\Services\Ai\Policy\AtlasDomainProfileRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AtlasAiPolicyController extends Controller
{
    public function profiles(AtlasDomainProfileRegistry $profiles): JsonResponse
    {
        return response()->json([
            'generated_at' => now()->toJSON(),
            'profile_registry' => $profiles->catalog(),
        ]);
    }

    public function preview(Request $request, AtlasAiPolicyService $policies): JsonResponse
    {
        $data = $request->validate([
            'profile_id' => ['nullable', 'string', 'max:160'],
            'surface' => ['nullable', 'string', 'max:80'],
            'mode' => ['nullable', 'string', 'max:80'],
            'task' => ['nullable', 'string', 'max:80'],
            'payload' => ['nullable', 'array'],
            'ai_policy_override' => ['nullable', 'array'],
        ]);

        $payload = is_array($data['payload'] ?? null) ? $data['payload'] : [];
        if (is_array($data['ai_policy_override'] ?? null)) {
            $payload['ai_policy_override'] = $data['ai_policy_override'];
        }

        if (is_string($data['profile_id'] ?? null) && trim($data['profile_id']) !== '') {
            [$mode, $task] = $this->modeTaskFromProfileId(trim($data['profile_id']));
            $data['mode'] ??= $mode;
            $data['task'] ??= $task;
            $payload['atlas_profile_id'] = trim($data['profile_id']);
        }

        $profile = $policies->effectiveProfile([
            'surface' => $data['surface'] ?? 'app',
            'mode' => $data['mode'] ?? null,
            'task' => $data['task'] ?? null,
            'payload' => $payload,
        ]);

        return response()->json([
            'generated_at' => now()->toJSON(),
            'profile' => [
                'schema_version' => (int) ($profile['schema_version'] ?? 2),
                'profile_id' => (string) ($profile['profile_id'] ?? 'general.answer'),
                'domain' => (string) ($profile['domain'] ?? 'general'),
                'flow' => (string) ($profile['flow'] ?? 'general.answer'),
                'domain_profile' => $profile['domain_profile'] ?? [],
                'flow_profile' => $profile['flow_profile'] ?? [],
                'domain_profile_registry' => $profile['domain_profile_registry'] ?? [],
            ],
            'effective_policy' => $profile['effective_policy'] ?? [],
            'policy_merge_receipt' => $profile['policy_merge_receipt'] ?? [],
            'legacy_policy' => $profile,
        ]);
    }

    public function updateDomain(
        string $domain,
        Request $request,
        AtlasDomainProfilePolicyService $profiles,
        AtlasDomainProfileRegistry $registry,
    ): JsonResponse {
        $data = $request->validate($this->domainRules());
        $updated = $profiles->updateDomain($domain, $data);

        return response()->json([
            'domain_profile' => $updated,
            'profile_registry' => $registry->catalog(),
        ]);
    }

    public function updateFlow(
        string $flow,
        Request $request,
        AtlasDomainProfilePolicyService $profiles,
        AtlasDomainProfileRegistry $registry,
        AtlasAiPolicyService $policies,
    ): JsonResponse {
        $data = $request->validate($this->flowRules());
        $updated = $profiles->updateFlow($flow, $data);

        return response()->json([
            'flow_profile' => $updated,
            'profile_registry' => $registry->catalog(),
            'effective_policy' => $policies->effectiveProfile([
                'surface' => 'app',
                'payload' => ['atlas_profile_id' => (string) ($updated['id'] ?? $flow)],
            ])['effective_policy'] ?? [],
        ]);
    }

    /**
     * @return array{0:string,1:string}
     */
    private function modeTaskFromProfileId(string $profileId): array
    {
        $parts = explode('.', strtolower($profileId), 2);
        $mode = trim($parts[0] ?? '') ?: 'general';
        $task = trim($parts[1] ?? '') ?: 'answer';

        return [$mode, $task];
    }

    /**
     * @return array<string,array<int,string>>
     */
    private function domainRules(): array
    {
        return [
            'label' => ['sometimes', 'string', 'max:160'],
            'status' => ['sometimes', 'string', 'in:active,draft,disabled,archived'],
            'default_flow' => ['sometimes', 'nullable', 'string', 'max:160'],
            'orchestrator' => ['sometimes', 'nullable', 'string', 'max:160'],
            'runtime_family' => ['sometimes', 'nullable', 'string', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'autonomy_default' => ['sometimes', 'string', 'max:40'],
            'background_allowed' => ['sometimes', 'boolean'],
            'model_policy' => ['sometimes', 'array'],
            'context_policy' => ['sometimes', 'array'],
            'skill_policy' => ['sometimes', 'array'],
            'tool_policy' => ['sometimes', 'array'],
            'memory_policy' => ['sometimes', 'array'],
            'gate_policy' => ['sometimes', 'array'],
            'metadata' => ['sometimes', 'array'],
        ];
    }

    /**
     * @return array<string,array<int,string>>
     */
    private function flowRules(): array
    {
        return [
            'label' => ['sometimes', 'string', 'max:160'],
            'status' => ['sometimes', 'string', 'in:active,draft,disabled,archived'],
            'orchestrator' => ['sometimes', 'nullable', 'string', 'max:160'],
            'runtime' => ['sometimes', 'nullable', 'string', 'max:160'],
            'description' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'autonomy' => ['sometimes', 'string', 'max:40'],
            'background_allowed' => ['sometimes', 'boolean'],
            'requires_human_approval_for_destructive' => ['sometimes', 'boolean'],
            'model_policy' => ['sometimes', 'array'],
            'context_policy' => ['sometimes', 'array'],
            'skill_policy' => ['sometimes', 'array'],
            'tool_policy' => ['sometimes', 'array'],
            'memory_policy' => ['sometimes', 'array'],
            'gate_policy' => ['sometimes', 'array'],
            'execution_policy' => ['sometimes', 'array'],
            'metadata' => ['sometimes', 'array'],
        ];
    }
}
