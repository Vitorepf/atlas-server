<?php

declare(strict_types=1);

namespace App\Services\Ai\Context;

use App\Services\Ai\AtlasOpenBrainContextInjectionService;
use App\Services\Ai\AtlasOpenBrainContextPackService;
use App\Services\Ai\Context\ValueObjects\ContextPackContract;
use App\Services\Ai\Context\ValueObjects\GateVerdict;
use App\Services\Ai\ValueObjects\AiTaskRequest;

/**
 * Single ACOS context facade for the 3 elite executors (Dev · Forge · Autônomos).
 *
 * compose() = live retrieval path (Open Brain + context pack builder).
 * certify() = AUCRI governance gate (policies internal — executors must NOT import AUCRI blocks).
 */
final class AtlasContextRuntime
{
    public const SCHEMA_VERSION = 'atlas.context_runtime.v1';

    public function __construct(
        private readonly AiContextPackBuilder $packBuilder,
        private readonly AtlasOpenBrainContextInjectionService $openBrain,
        private readonly AtlasAucriRuntimeEnforcementService $aucriGate,
        private readonly AtlasContextQualityCertificationService $qualityCert,
        private readonly AtlasOpenBrainContextPackService $fusedContext,
    ) {}

    /**
     * Compose a provider-bound context pack for a task.
     *
     * @param  array<string, mixed>  $options
     */
    public function compose(string $input, AiTaskRequest $task, array $options = []): ContextPackContract
    {
        $workspace = (string) ($options['workspace'] ?? base_path());
        $pack = $this->packBuilder->build($input, $task, $options);
        $enabled = (bool) config('atlas.context_runtime.unified_retrieval_enabled', false);
        $rolloutMode = AtlasIntelligenceRolloutMode::resolve([
            'enabled' => $enabled,
            'mode' => (string) config('atlas.context_runtime.unified_retrieval_mode', AtlasIntelligenceRolloutMode::OFFLINE),
            'canary_percent' => (int) config('atlas.context_runtime.unified_retrieval_canary_percent', 0),
        ], [
            'workspace' => $workspace,
            'flow_id' => (string) ($options['flow_id'] ?? ''),
            'actor' => (string) ($options['actor'] ?? 'context_runtime'),
        ]);
        $fused = null;
        if (AtlasIntelligenceRolloutMode::shouldRecordShadow($rolloutMode)) {
            $fused = $this->fusedContext->packFor($input, [
                'workspace' => $workspace,
                'changed_files' => array_values((array) ($options['changed_files'] ?? [])),
                'flow_id' => (string) ($options['flow_id'] ?? ''),
                'task_type' => $task->taskType(),
                'domain' => (string) ($options['domain'] ?? data_get($options, 'payload.domain', '')),
                // ContextRuntime is the parent; never recurse back into it.
                'include_runtime_compose' => false,
            ]);
        }
        $liveUnified = AtlasIntelligenceRolloutMode::shouldExecuteLive($rolloutMode) && $fused !== null;
        $injectionOptions = $liveUnified
            ? array_merge($options, ['precomputed_aobg_pack' => $fused])
            : $options;
        $injection = $this->openBrain->inject($input, $task, $pack, $injectionOptions);

        return ContextPackContract::fromArray([
            'schema' => self::SCHEMA_VERSION,
            'context_pack' => $pack->toArray(),
            'retrieval_core_status' => $liveUnified ? 'unified' : ($fused !== null ? 'shadow' : 'legacy'),
            'retrieval_core' => $fused,
            'retrieval_rollout' => AtlasIntelligenceRolloutMode::receipt($rolloutMode, $enabled),
            'open_brain_injection' => $injection,
        ], $input, $workspace);
    }

    /**
     * Certify context quality via AUCRI policies (gate only — not retrieval).
     *
     * @param  array<string, mixed>  $input
     */
    public function certify(array $input): GateVerdict
    {
        $enforcement = $this->aucriGate->enforce($input);
        $verdict = GateVerdict::fromEnforcement($enforcement);

        if ($verdict->passed() && (bool) ($input['run_quality_cert'] ?? false)) {
            $quality = $this->qualityCert->certify($input);
            if (($quality['status'] ?? 'blocked') !== 'passed') {
                return new GateVerdict('blocked', ['context_quality_cert'], array_merge($enforcement, ['quality' => $quality]));
            }
        }

        return $verdict;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function certifyEnforcement(array $input): array
    {
        return $this->certify($input)->audit;
    }
}
