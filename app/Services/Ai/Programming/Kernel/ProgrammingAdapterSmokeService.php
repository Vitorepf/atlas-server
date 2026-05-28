<?php

namespace App\Services\Ai\Programming\Kernel;

use App\Models\AiMission;
use App\Services\Ai\Mission\MissionEvidenceService;
use App\Services\Ai\Mission\MissionLifecycleService;

class ProgrammingAdapterSmokeService
{
    public const DEFAULT_DEV_PROMPT = 'corrigir bug pequeno no endpoint /healthz com phpunit teste de regressao';

    public const DEFAULT_FORGE_PROMPT = 'planejar obra de migracao multi-modulo com sdd e multiagente para reescrever provider router';

    public function __construct(
        private readonly ProgrammingDomainManifestSeeder $seeder,
        private readonly AtlasDevMissionAdapter $dev,
        private readonly AtlasForgeHandoffAdapter $forge,
        private readonly ProgrammingDomainRuntimeAdapter $runtime,
        private readonly ProgrammingPolicyBridge $policy,
        private readonly ProgrammingEvidenceBridge $evidence,
        private readonly ProgrammingToolBridge $tools,
        private readonly ProgrammingControlPlaneProjection $controlPlane,
        private readonly MissionLifecycleService $lifecycle,
    ) {}

    /**
     * End-to-end smoke for the Programming adapter:
     *  1. seed programming manifest (idempotent)
     *  2. dev prompt -> mission/objectives/work_orders
     *  3. domain runtime record + policy evaluate + tool advisory
     *  4. attach evidence + transition mission running->certifying
     *  5. certify mission and complete domain runtime record
     *  6. forge prompt -> mission obra -> handoff to forge
     *  7. control-plane snapshot
     *
     * @return array<string,mixed>
     */
    public function run(?string $devPrompt = null, ?string $forgePrompt = null): array
    {
        $manifest = $this->seeder->seed();
        $prompts = self::resolvePrompts($devPrompt, $forgePrompt);

        $devReport = $this->runDev($prompts['dev']);
        $forgeReport = $this->runForge($prompts['forge']);
        $snapshot = $this->controlPlane->snapshot();

        return [
            'ok' => self::isSmokeSuccessful($devReport, $forgeReport),
            'manifest' => [
                'domain_id' => $manifest->domain_id,
                'manifest_hash' => $manifest->manifest_hash,
                'capability_count' => $manifest->capabilities()->count(),
            ],
            'dev' => $devReport,
            'forge' => $forgeReport,
            'control_plane_summary' => $snapshot['programming'] ?? [],
            'bridges' => $snapshot['bridges'] ?? [],
        ];
    }

    /**
     * @return array{dev: string, forge: string}
     */
    public static function resolvePrompts(?string $devPrompt = null, ?string $forgePrompt = null): array
    {
        return [
            'dev' => $devPrompt ?? self::DEFAULT_DEV_PROMPT,
            'forge' => $forgePrompt ?? self::DEFAULT_FORGE_PROMPT,
        ];
    }

    /**
     * @param  array<string,mixed>  $devReport
     * @param  array<string,mixed>  $forgeReport
     */
    public static function isSmokeSuccessful(array $devReport, array $forgeReport): bool
    {
        return ($devReport['mission_status'] ?? null) === MissionLifecycleService::STATUS_COMPLETED
            && ($devReport['certification_status'] ?? null) === 'passed'
            && ($forgeReport['handoff_receipt_hash'] ?? null) !== null;
    }

    /**
     * @return array<string,mixed>
     */
    private function runDev(string $prompt): array
    {
        $adapted = $this->dev->adapt($prompt);
        /** @var AiMission $mission */
        $mission = $adapted['mission'];
        $workOrder = $adapted['work_orders']->first();

        $runtimeRecord = $this->runtime->plan($mission, $workOrder, [
            'capability' => $adapted['capability'],
        ]);

        $policy = $this->policy->evaluate($adapted['capability'], $mission, [
            'work_order_id' => $workOrder?->id,
            'tool_id' => 'test.local_command',
        ]);

        $tool = $this->tools->requestTool('test.local_command', $mission, ['command' => 'phpunit --filter=Smoke'], [
            'work_order_id' => $workOrder?->id,
        ]);

        $this->lifecycle->transition($mission, MissionLifecycleService::STATUS_RUNNING, ['actor_type' => 'programming_adapter']);

        $evidence = $this->evidence->attach(
            $mission,
            MissionEvidenceService::TYPE_TEST,
            'programming.adapter.smoke:'.$mission->uuid,
            ['scope' => 'dev_smoke', 'tool_invocation_id' => $tool['invocation_id'] ?? null],
            $workOrder,
        );

        $this->lifecycle->transition($mission, MissionLifecycleService::STATUS_CERTIFYING, ['actor_type' => 'programming_adapter']);
        $certificationReport = $this->runtime->certify($mission, $runtimeRecord);

        if ($certificationReport['mission_certification_status'] === 'passed') {
            $this->lifecycle->transition($mission, MissionLifecycleService::STATUS_COMPLETED, ['actor_type' => 'programming_adapter']);
        }

        return [
            'mission_id' => $mission->id,
            'mission_uuid' => $mission->uuid,
            'mission_type' => $mission->mission_type,
            'mission_status' => $mission->refresh()->status,
            'capability' => $adapted['capability'],
            'work_order_id' => $workOrder?->id,
            'task_contract_hash' => $adapted['task_contract_hash'],
            'policy_decision' => $policy['decision'],
            'policy_source' => $policy['source'],
            'tool_decision' => $tool['decision'],
            'tool_source' => $tool['source'],
            'evidence_runtime' => $evidence['evidence_runtime']['kind'],
            'certification_status' => $certificationReport['mission_certification_status'],
            'certification_hash' => $certificationReport['mission_certification_hash'],
            'runtime_record_id' => $runtimeRecord->id,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function runForge(string $prompt): array
    {
        $adapted = $this->dev->adapt($prompt);
        /** @var AiMission $mission */
        $mission = $adapted['mission'];
        $workOrder = $adapted['work_orders']->first();

        $reason = $this->forge->shouldPromote($mission) ?? 'scope_too_large';
        $handoff = $this->forge->promote($mission, $workOrder, $reason, [
            'capability' => $adapted['capability'],
            'workspace' => 'atlas-server',
            'risk_register' => ['high_risk_keywords' => ProgrammingDomainKernelCanon::detectHighRiskActions($prompt)],
        ]);

        return [
            'mission_id' => $mission->id,
            'mission_type' => $mission->mission_type,
            'mission_status' => $mission->refresh()->status,
            'why_escalated' => $reason,
            'handoff_id' => $handoff->id,
            'handoff_receipt_hash' => $handoff->receipt_hash,
            'handoff_status' => $handoff->status,
            'context_pack_keys' => array_keys((array) $handoff->context_pack),
        ];
    }
}
