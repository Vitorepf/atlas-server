<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Ai\AgentGovernance\AtlasAgentDesiredStateStore;
use App\Services\Ai\AgentGovernance\AtlasAgentEventLedger;
use App\Services\Ai\AgentGovernance\AtlasAgentRegistry;
use App\Services\Ai\AgentGovernance\AtlasFleetCatalog;
use App\Services\Ai\AgentGovernance\AtlasFleetMasterSwitch;
use App\Services\Ai\AutonomousEvolution\AtlasLoopMasterSwitch;
use App\Services\Ai\SelfConstruction\AtlasTaskServingStack;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The API the mobile + desktop apps poll to SEE the fleet and to turn it OFF — the operator's "loops ativos +
 * histórico + DESLIGAR" surface. By design it only EXPOSES state and turns things OFF: there is deliberately no
 * turn-ON endpoint (starting an agent stays a deliberate CLI/operator act), so a tapped app can only ever
 * reduce spend, never originate it. Read endpoints never start/stop anything.
 */
final class AtlasAgentGovernanceController extends Controller
{
    /** GET /api/agents/active — only the agents actually running (what the "🔴 N ativos" badge counts). */
    public function active(AtlasAgentRegistry $registry): JsonResponse
    {
        return response()->json($registry->active());
    }

    /** GET /api/agents/status — the full fleet (running + desired + off), for the detail screen. */
    public function status(AtlasAgentRegistry $registry): JsonResponse
    {
        return response()->json($registry->snapshot());
    }

    /** GET /api/agents/history — append-only governance history (start/stop/on/off/expire). */
    public function history(Request $request, AtlasAgentEventLedger $ledger): JsonResponse
    {
        $limit = max(1, min(500, (int) $request->query('limit', '100')));
        $agentKey = $request->query('agent');
        $agentKey = is_string($agentKey) && $agentKey !== '' ? $agentKey : null;

        return response()->json([
            'schema_version' => 'atlas.agents.history.v1',
            // O ledger é auditável e pode guardar `detail` interno. A frota
            // móvel recebe somente a cronologia governada, nunca prompt,
            // path, configuração ou qualquer payload de worker.
            'events' => array_map(
                fn (array $event): array => $this->publicHistoryEvent($event),
                $ledger->recent($limit, $agentKey),
            ),
        ]);
    }

    /**
     * @param array<string,mixed> $event
     * @return array<string,mixed>
     */
    private function publicHistoryEvent(array $event): array
    {
        return [
            'agent_key' => $this->publicHistoryCode($event['agent_key'] ?? null) ?? '',
            'event' => $this->publicHistoryCode($event['event'] ?? null) ?? '',
            'at' => is_string($event['at'] ?? null) ? (string) $event['at'] : '',
            'by' => $this->publicHistoryCode($event['by'] ?? null),
            'account' => $this->publicHistoryCode($event['account'] ?? null),
            'pid' => is_int($event['pid'] ?? null) ? $event['pid'] : null,
            'duration_seconds' => is_int($event['duration_seconds'] ?? null) ? max(0, $event['duration_seconds']) : null,
            // O motivo é um código público, não uma justificativa livre que
            // poderia reintroduzir conteúdo de prompt ou segredo no app.
            'reason' => $this->publicHistoryCode($event['reason'] ?? null),
        ];
    }

    private function publicHistoryCode(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : '';

        return preg_match('/^[a-z0-9][a-z0-9_:@.-]{0,119}$/i', $value) === 1 ? $value : null;
    }

    /**
     * GET /api/agents/task-health — the task-serving facts the mobile Frota
     * Viva may render. This is deliberately a bounded projection of the
     * coordination-health read model: no task packets, objectives, paths,
     * provider inputs, interventions or executable instructions cross HTTP.
     */
    public function taskHealth(): JsonResponse
    {
        $health = AtlasTaskServingStack::coordinationHealth()->snapshot();
        $distribution = (array) ($health['queue_status_distribution'] ?? []);
        $flags = array_keys(array_filter((array) ($health['health_flags'] ?? [])));
        $forecast = (array) ($health['worker_drain_forecast'] ?? []);

        return response()->json([
            'schema_version' => 'atlas.autonomos.task_health.v1',
            'observed_at' => now()->toIso8601String(),
            'provider_safe' => true,
            'healthy' => (bool) ($health['healthy'] ?? false),
            'tasks' => [
                'claimable' => max(0, (int) ($health['claimable_depth'] ?? 0)),
                'servable_now' => max(0, (int) ($health['servable_now'] ?? 0)),
                'claimed' => max(0, (int) ($health['claimed_records'] ?? 0)),
                'blocked' => max(0, (int) ($health['quarantined_count'] ?? 0)),
                'completed' => max(0, (int) ($distribution['completed_dry_run'] ?? 0)),
                'recoverable' => max(0, (int) data_get($health, 'recoverable.total', 0)),
            ],
            'leases' => [
                'active' => max(0, (int) ($health['active_leases'] ?? 0)),
                'matches_claimed' => (bool) ($health['leases_match_claimed'] ?? false),
            ],
            'incidents' => [
                'present' => ! (bool) ($health['healthy'] ?? false),
                'flags' => array_values(array_map('strval', $flags)),
            ],
            'operating' => [
                'recommended_action' => (string) ($health['self_healing_action'] ?? $forecast['replenish_recommendation'] ?? 'monitor'),
                'queue_pressure' => (string) ($forecast['queue_pressure'] ?? 'unknown'),
            ],
        ]);
    }

    /** POST /api/agents/{key}/off — DESLIGAR a single agent (declare desired-OFF; the babá stops it). */
    public function off(string $key, AtlasAgentDesiredStateStore $store, AtlasAgentRegistry $registry): JsonResponse
    {
        if (! AtlasFleetCatalog::has($key)) {
            return response()->json(['ok' => false, 'error' => 'unknown_agent', 'known' => AtlasFleetCatalog::keys()], 404);
        }
        $store->setOff($key, by: 'app', reason: 'desligar_via_app');

        return response()->json(['ok' => true, 'agent' => $key, 'desired' => 'off', 'fleet' => $registry->snapshot()]);
    }

    /** POST /api/agents/off-all — PANIC: desired-OFF for the whole fleet + both master switches OFF. */
    public function offAll(AtlasAgentDesiredStateStore $store, AtlasAgentRegistry $registry): JsonResponse
    {
        foreach (AtlasFleetCatalog::keys() as $key) {
            $store->setOff($key, by: 'app', reason: 'panic_off_all_via_app');
        }
        AtlasFleetMasterSwitch::off();
        AtlasLoopMasterSwitch::off();

        return response()->json([
            'ok' => true,
            'scope' => 'all',
            'fleet_master' => AtlasFleetMasterSwitch::state(),
            'loop_master' => AtlasLoopMasterSwitch::state(),
            'fleet' => $registry->snapshot(),
        ]);
    }
}
