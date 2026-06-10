<?php

declare(strict_types=1);

namespace App\Http\Controllers;

// Gap5.F2 wiring marker — Programming-adjacent controller.
// Canonical route_decision schema: atlas.dual_core.route_decision.v1
// Method-level emission to be wired per AP per family.

use App\Models\AtlasOpenBrainAccessLog;
use App\Services\Ai\AtlasOpenBrainMcpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use App\Services\Ai\Support\DatabaseTableAvailability;

/**
 * Atlas Code · MCP pill status contract.
 *
 * Single endpoint that the topbar consumes to show "MCP active / degraded /
 * disabled" with real numbers (tools count, docs indexed, last call).
 *
 *   GET /api/atlas-code/mcp/status
 *
 * Shape from atlas-desktop-backend-contract.md section 3.6.
 */
final class AtlasCodeMcpStatusController extends Controller
{
    public function __invoke(AtlasOpenBrainMcpService $mcp): JsonResponse
    {
        $enabled = (bool) config('atlas.open_brain.mcp.http_enabled', true);
        $tools = $this->tools($mcp);
        $docsIndexed = $this->docsIndexed();
        $symbolsIndexed = $this->symbolsIndexed();
        $lastCall = $this->lastCall();

        $status = match (true) {
            ! $enabled => 'disabled',
            count($tools) === 0 => 'degraded',
            default => 'active',
        };

        return response()->json([
            'server' => 'atlas-open-brain',
            'status' => $status,
            'protocol_version' => AtlasOpenBrainMcpService::PROTOCOL_VERSION,
            'transport' => 'http_json_rpc',
            'http_enabled' => $enabled,
            'tools_count' => count($tools),
            'tools' => array_values(array_slice($tools, 0, 64)),
            'docs_indexed' => $docsIndexed,
            'symbols_indexed' => $symbolsIndexed,
            'last_call' => $lastCall,
            'freshness' => [
                'indexed_at' => null,
                'drift' => 'unknown',
            ],
            'route_decision' => \App\Services\Ai\DualCore\CanonicalRouteDecisionEnvelope::emit(
                route: 'mcp_status',
                reason: 'http_atlas_code_mcp_status',
            ),
        ]);
    }

    /**
     * @return array<int, array{name: string, summary?: string}>
     */
    private function tools(AtlasOpenBrainMcpService $mcp): array
    {
        try {
            $response = $mcp->handleJsonRpc([
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/list',
                'params' => [],
            ]);
            if (! is_array($response) || ! isset($response['result']['tools']) || ! is_array($response['result']['tools'])) {
                return [];
            }

            return array_values(array_filter(array_map(
                static fn ($tool): ?array => is_array($tool) && isset($tool['name'])
                    ? [
                        'name' => (string) $tool['name'],
                        'summary' => is_string($tool['description'] ?? null) ? (string) $tool['description'] : null,
                    ]
                    : null,
                $response['result']['tools']
            )));
        } catch (\Throwable) {
            return [];
        }
    }

    private function docsIndexed(): int
    {
        if (! DatabaseTableAvailability::has('engineering_knowledge_documents')) {
            return 0;
        }
        try {
            return (int) DB::table('engineering_knowledge_documents')->count();
        } catch (\Throwable) {
            return 0;
        }
    }

    private function symbolsIndexed(): int
    {
        if (! DatabaseTableAvailability::has('engineering_code_symbols')) {
            return 0;
        }
        try {
            return (int) DB::table('engineering_code_symbols')->count();
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function lastCall(): ?array
    {
        if (! DatabaseTableAvailability::has('atlas_open_brain_access_logs')) {
            return null;
        }
        try {
            $row = AtlasOpenBrainAccessLog::query()
                ->orderByDesc('created_at')
                ->limit(1)
                ->first(['surface', 'requester', 'action', 'status', 'metadata', 'accessed_at', 'created_at']);
            if (! $row) {
                return null;
            }

            return [
                'tool' => (string) ($row->action ?? ''),
                'operator_id' => (string) ($row->requester ?? $row->surface ?? ''),
                'duration_ms' => (int) data_get($row->metadata, 'duration_ms', 0),
                'status' => (string) ($row->status ?? ''),
                'at' => ($row->accessed_at ?? $row->created_at)?->toJSON(),
            ];
        } catch (\Throwable) {
            return null;
        }
    }
}
