<?php

namespace App\Services\Ai\Mission;

use App\Models\AiMission;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

/**
 * Atlas AiWorker → Kernel integration · Phase 1 bridge.
 *
 * Wires the canonical Mission Kernel (Meta 1) into the real HTTP prompt path
 * (`POST /ai/interactions` → `AiGatewayService::enqueueInteraction`) WITHOUT
 * touching AiWorker, AiProviderManager, AtlasProgrammingOrchestrator or any
 * Programming/Dev/Forge runtime. The bridge only RECORDS a canonical
 * `atlas.ai.aiworker.kernel_envelope.v1` and persists it on the trace +
 * job metadata so subsequent phases can attach Policy / Evidence /
 * Certification without re-deriving the mission.
 *
 * STRICT INVARIANTS (audit-mandated):
 *
 *   1. The bridge MUST be safe to invoke even when the Mission tables are
 *      absent (`ai_missions`, `ai_objectives`, `ai_work_orders`). When
 *      missing it returns `null` — the gateway keeps its legacy path.
 *   2. The bridge MUST NEVER throw back into the gateway: failures convert
 *      into a structured `Log::warning` + a stub envelope under
 *      `kernel_bridge_error` so the legacy path proceeds AND the failure
 *      remains visible in trace metadata. Silent degradation is impossible.
 *   3. Trivial prompts (`MissionFactoryService::TYPE_TRIVIAL`) skip
 *      decomposition / work_order creation so `ai_missions` is not flooded
 *      with ping/explain traffic. The mission row is still recorded for
 *      observability when `trivial_skips_kernel` is false; otherwise the
 *      bridge returns null for trivials.
 *   4. The bridge does NOT call PermissionGate, Evidence, Certification or
 *      ProgrammingDomainRuntimeAdapter — those wires live in Phases 4-6.
 *
 * Canon: `docs/engineering-knowledge-base/atlas-aiworker-kernel-integration-adr.md`
 */
class AiGatewayMissionBridge
{
    public const ENVELOPE_SCHEMA = 'atlas.ai.aiworker.kernel_envelope.v1';

    public const ENVELOPE_SOURCE = 'ai_gateway.enqueue_interaction';

    private const REQUIRED_TABLES = [
        'ai_missions',
        'ai_objectives',
        'ai_work_orders',
        'ai_mission_events',
    ];

    public function __construct(private readonly Container $container) {}

    public function enabled(): bool
    {
        return (bool) config('atlas_ai.kernel_http_integration.enabled', false);
    }

    public function trivialSkipsKernel(): bool
    {
        return (bool) config('atlas_ai.kernel_http_integration.trivial_skips_kernel', true);
    }

    public function tablesAvailable(): bool
    {
        foreach (self::REQUIRED_TABLES as $table) {
            if (! Schema::hasTable($table)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Build the canonical kernel envelope for a single HTTP interaction.
     *
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>|null null when the feature flag is off,
     *                                  the Mission tables are absent, or the
     *                                  prompt is trivial (when
     *                                  `trivial_skips_kernel` is true). The
     *                                  gateway treats null as "no kernel
     *                                  linkage; keep legacy behaviour".
     */
    public function buildEnvelope(string $input, array $options = []): ?array
    {
        if (! $this->enabled()) {
            return null;
        }
        if (! $this->tablesAvailable()) {
            // Audit-mandated: missing tables are surfaced as a structured log
            // so the operator can see the kill-switch was effectively forced
            // by environment state, not by config alone.
            Log::warning('atlas.ai_gateway.mission_bridge.skipped_missing_tables', [
                'enabled' => true,
                'required_tables' => self::REQUIRED_TABLES,
            ]);

            return null;
        }
        if (trim($input) === '') {
            return null;
        }

        try {
            return $this->recordMission($input, $options);
        } catch (Throwable $e) {
            // NEVER bubble; the gateway must keep working. Emit a structured
            // log and return a stub envelope so the legacy path proceeds and
            // the failure stays attached to trace/job metadata.
            Log::warning('atlas.ai_gateway.mission_bridge.failed', [
                'reason' => $e->getMessage(),
                'exception_class' => $e::class,
                'input_length' => mb_strlen($input),
            ]);

            return $this->errorEnvelope($e);
        }
    }

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    private function recordMission(string $input, array $options): ?array
    {
        $factory = $this->container->make(MissionFactoryService::class);

        // Classify first so we can short-circuit trivial without inserting
        // a mission row when the operator opted into that policy.
        $missionType = (string) ($options['mission_type'] ?? $factory->classify($input));
        if ($missionType === MissionFactoryService::TYPE_TRIVIAL && $this->trivialSkipsKernel()) {
            return $this->trivialSkippedEnvelope($factory, $input);
        }

        $mission = $factory->create($input, $this->factoryOptions($options, $missionType));
        $missionType = (string) $mission->mission_type;

        $envelope = [
            'schema' => self::ENVELOPE_SCHEMA,
            'enabled_by_flag' => true,
            'source' => self::ENVELOPE_SOURCE,
            'mission_id' => $mission->id,
            'mission_uuid' => $mission->uuid,
            'mission_type' => $missionType,
            'mission_status' => $mission->status,
            'normalized_intent' => $mission->normalized_intent,
            'autonomy_level' => $mission->autonomy_level,
            'risk_level' => $mission->risk_level,
            'objective_id' => null,
            'work_order_id' => null,
            'domain_id' => $options['domain_id'] ?? null,
            'capability' => $options['capability'] ?? null,
            'recorded_at' => now()->toJSON(),
        ];

        if ($missionType !== MissionFactoryService::TYPE_TRIVIAL) {
            try {
                $decomposer = $this->container->make(ObjectiveDecomposerService::class);
                $workOrders = $this->container->make(WorkOrderFactoryService::class);
                $lifecycle = $this->container->make(MissionLifecycleService::class);

                $objectives = $decomposer->decompose($mission);
                $orders = $workOrders->plan($mission);
                $lifecycle->transition(
                    $mission,
                    MissionLifecycleService::STATUS_PLANNED,
                    [
                        'source' => self::ENVELOPE_SOURCE,
                        'decomposed_objectives' => $objectives->count(),
                        'planned_work_orders' => $orders->count(),
                    ],
                );

                $envelope['objective_id'] = optional($objectives->first())->id;
                $envelope['work_order_id'] = optional($orders->first())->id;
                $envelope['mission_status'] = MissionLifecycleService::STATUS_PLANNED;
                $envelope['objectives_count'] = $objectives->count();
                $envelope['work_orders_count'] = $orders->count();
            } catch (Throwable $e) {
                // Decomposition / work_order failures are non-fatal for the
                // bridge: the mission row exists, lifecycle stays at `draft`,
                // and the envelope keeps the mission link so later phases
                // can repair. Surface a structured log.
                Log::warning('atlas.ai_gateway.mission_bridge.decompose_failed', [
                    'mission_id' => $mission->id,
                    'reason' => $e->getMessage(),
                    'exception_class' => $e::class,
                ]);
                $envelope['decompose_error'] = $e->getMessage();
            }
        }

        return $envelope;
    }

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    private function factoryOptions(array $options, string $missionType): array
    {
        return array_filter([
            'mission_type' => $missionType,
            'autonomy_level' => $options['autonomy_level'] ?? null,
            'risk_level' => $options['risk_level'] ?? null,
            'title' => $options['title'] ?? null,
            'primary_domain' => $options['primary_domain'] ?? null,
            'secondary_domains' => $options['secondary_domains'] ?? null,
            'context_summary' => $options['context_summary'] ?? null,
            'actor_type' => $options['actor_type'] ?? 'ai_gateway',
        ], static fn ($v): bool => $v !== null);
    }

    /**
     * @return array<string,mixed>
     */
    private function trivialSkippedEnvelope(MissionFactoryService $factory, string $input): array
    {
        return [
            'schema' => self::ENVELOPE_SCHEMA,
            'enabled_by_flag' => true,
            'source' => self::ENVELOPE_SOURCE,
            'mission_id' => null,
            'mission_uuid' => null,
            'mission_type' => MissionFactoryService::TYPE_TRIVIAL,
            'mission_status' => 'skipped',
            'normalized_intent' => $factory->normalizeIntent($input),
            'autonomy_level' => null,
            'risk_level' => null,
            'objective_id' => null,
            'work_order_id' => null,
            'domain_id' => null,
            'capability' => null,
            'recorded_at' => now()->toJSON(),
            'skipped_reason' => 'trivial_skips_kernel',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function errorEnvelope(Throwable $e): array
    {
        return [
            'schema' => self::ENVELOPE_SCHEMA,
            'enabled_by_flag' => true,
            'source' => self::ENVELOPE_SOURCE,
            'mission_id' => null,
            'mission_status' => 'bridge_error',
            'recorded_at' => now()->toJSON(),
            'kernel_bridge_error' => [
                'reason' => $e->getMessage(),
                'exception_class' => $e::class,
                'fallback' => 'legacy_path',
                'incident_id' => (string) Str::uuid(),
            ],
        ];
    }

    /**
     * Convenience accessor for tests / control-plane probes.
     */
    public function findMissionForEnvelope(?array $envelope): ?AiMission
    {
        if (! is_array($envelope) || empty($envelope['mission_id'])) {
            return null;
        }

        return AiMission::query()->find($envelope['mission_id']);
    }
}
