<?php

namespace App\Services\Ai\Programming\Forge\Intelligence;

use App\Models\AiForgeMultiAgentSchedule;
use App\Models\AiForgeWorkPacket;
use App\Models\AiForgeWorkPacketExecutionCycle;
use App\Models\AiForgeWorkPacketWorkcellRoute;
use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\Programming\Frontend\AtlasFrontendDesignRuntimeService;
use App\Services\Ai\Programming\Frontend\AtlasFrontendExecutionGateService;
use Illuminate\Support\Str;

final class ForgeSpecialistWorkcellRouterService
{
    public const SCHEMA_VERSION = 'atlas.forge.specialist_workcell_route.v1';

    /**
     * @return array<string,mixed>
     */
    public function route(AiForgeWorkPacket $packet): array
    {
        $text = strtolower((string) $packet->title.' '.(string) $packet->objective.' '.(string) $packet->scope);
        $route = 'implementation';
        if (str_contains($text, 'test') || str_contains($text, 'teste') || str_contains($text, 'qa')) {
            $route = 'qa_test';
        } elseif (str_contains($text, 'debug') || str_contains($text, 'bug') || str_contains($text, 'repair') || str_contains($text, 'corrig')) {
            $route = 'repair_debug';
        } elseif (str_contains($text, 'doc') || str_contains($text, 'cartografia')) {
            $route = 'documentation';
        } elseif (str_contains($text, 'frontend') || str_contains($text, 'mobile') || str_contains($text, 'ui')) {
            $route = 'surface_ui';
        } elseif (str_contains($text, 'security') || str_contains($text, 'segur')) {
            $route = 'security_review';
        } elseif (str_contains($text, 'migration') || str_contains($text, 'schema') || str_contains($text, 'database')) {
            $route = 'database_migration';
        } elseif (str_contains($text, 'architecture') || str_contains($text, 'arquitet')) {
            $route = 'architecture';
        }

        $atlasFrontendRuntime = $route === 'surface_ui'
            ? app(AtlasFrontendDesignRuntimeService::class)->contract([
                'task' => trim($text),
                'surface' => 'atlas_forge',
            ])
            : null;
        $atlasFrontendGate = $route === 'surface_ui'
            ? app(AtlasFrontendExecutionGateService::class)->evaluate([
                'task' => trim($text),
                'surface' => 'atlas_forge',
                'acceptance_criteria' => false,
                'test_plan' => false,
                'visual_quality_plan' => false,
                'evidence_plan' => false,
                'senior_design_review' => in_array((string) $packet->risk_band, ['high', 'critical'], true),
            ])
            : null;

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'packet_id' => (string) $packet->packet_id,
            'workcell' => $route,
            'agent_profile' => 'forge_'.$route,
            'parallelizable' => in_array($route, ['documentation', 'qa_test', 'security_review'], true),
            'requires_human_review' => in_array((string) $packet->risk_band, ['high', 'critical'], true),
            'route_reasons' => ['objective_keyword_match', 'risk_band:'.(string) $packet->risk_band],
        ];
        if ($atlasFrontendRuntime !== null) {
            $payload['atlas_frontend_runtime'] = $atlasFrontendRuntime;
            $payload['atlas_frontend_pre_execution_gate'] = $atlasFrontendGate;
            $payload['route_reasons'][] = 'atlas_frontend_runtime_contract_attached';
            $payload['route_reasons'][] = 'atlas_frontend_pre_execution_gate_attached';
        }
        $payload['route_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $route
     */
    public function persistRoute(
        AiForgeWorkPacket $packet,
        array $route,
        ?AiForgeWorkPacketExecutionCycle $cycle = null,
        ?AiForgeMultiAgentSchedule $schedule = null,
    ): AiForgeWorkPacketWorkcellRoute {
        $payload = [
            'schema_version' => 'atlas.forge.work_packet_workcell_route.v1',
            'intake_id' => $packet->intake_id,
            'work_packet_id' => $packet->id,
            'work_packet_canonical_id' => (string) $packet->packet_id,
            'execution_cycle_id' => $cycle?->id,
            'multi_agent_schedule_id' => $schedule?->id,
            'workcell' => (string) ($route['workcell'] ?? 'implementation'),
            'agent_profile' => (string) ($route['agent_profile'] ?? 'forge_implementation'),
            'parallelizable' => (bool) ($route['parallelizable'] ?? false),
            'requires_human_review' => (bool) ($route['requires_human_review'] ?? false),
            'route_reasons' => array_values((array) ($route['route_reasons'] ?? [])),
            'ownership_paths' => array_values((array) ($packet->expected_files ?? [])),
            'status' => $schedule !== null ? 'scheduled' : 'planned',
        ];
        $payload['route_hash'] = MissionCanonicalHash::sha256($payload);

        return AiForgeWorkPacketWorkcellRoute::query()->updateOrCreate(
            [
                'execution_cycle_id' => $cycle?->id,
                'work_packet_canonical_id' => (string) $packet->packet_id,
            ],
            [
                'schema_version' => (string) $payload['schema_version'],
                'uuid' => (string) Str::uuid(),
                'intake_id' => $payload['intake_id'],
                'work_packet_id' => $payload['work_packet_id'],
                'multi_agent_schedule_id' => $payload['multi_agent_schedule_id'],
                'workcell' => $payload['workcell'],
                'agent_profile' => $payload['agent_profile'],
                'parallelizable' => $payload['parallelizable'],
                'requires_human_review' => $payload['requires_human_review'],
                'route_reasons' => $payload['route_reasons'],
                'ownership_paths' => $payload['ownership_paths'],
                'status' => $payload['status'],
                'route_hash' => $payload['route_hash'],
            ],
        );
    }
}
