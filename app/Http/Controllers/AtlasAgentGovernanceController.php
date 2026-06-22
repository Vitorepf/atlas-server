<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Ai\AgentGovernance\AtlasAgentDesiredStateStore;
use App\Services\Ai\AgentGovernance\AtlasAgentEventLedger;
use App\Services\Ai\AgentGovernance\AtlasAgentRegistry;
use App\Services\Ai\AgentGovernance\AtlasFleetCatalog;
use App\Services\Ai\AgentGovernance\AtlasFleetMasterSwitch;
use App\Services\Ai\AutonomousEvolution\AtlasLoopMasterSwitch;
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
            'events' => $ledger->recent($limit, $agentKey),
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
