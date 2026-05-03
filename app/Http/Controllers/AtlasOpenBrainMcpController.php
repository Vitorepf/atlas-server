<?php

namespace App\Http\Controllers;

use App\Services\Ai\AtlasOpenBrainMcpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AtlasOpenBrainMcpController extends Controller
{
    public function __invoke(Request $request, AtlasOpenBrainMcpService $mcp): JsonResponse|Response
    {
        if (! (bool) config('atlas.open_brain.mcp.http_enabled', true)) {
            return response()->json([
                'error' => [
                    'code' => 'MCP_HTTP_DISABLED',
                    'message' => 'Atlas Open Brain MCP HTTP endpoint is disabled.',
                ],
            ], 404);
        }

        if (! $this->originAllowed($request)) {
            return response()->json([
                'error' => [
                    'code' => 'ORIGIN_NOT_ALLOWED',
                    'message' => 'Origin is not allowed for Atlas Open Brain MCP.',
                ],
            ], 403);
        }

        if (! $this->protocolVersionAllowed($request)) {
            return $this->jsonRpcError(null, -32600, 'Unsupported MCP protocol version.', 400);
        }

        if ($request->isMethod('GET')) {
            if (str_contains((string) $request->headers->get('Accept'), 'text/event-stream')) {
                return response('', 405)
                    ->header('Allow', 'POST')
                    ->header('MCP-Protocol-Version', AtlasOpenBrainMcpService::PROTOCOL_VERSION);
            }

            return response()->json([
                'ok' => true,
                'server' => 'atlas-open-brain',
                'transport' => 'http_json_rpc',
                'protocol_version' => AtlasOpenBrainMcpService::PROTOCOL_VERSION,
                'methods' => ['initialize', 'ping', 'tools/list', 'tools/call'],
                'read_only' => true,
            ])->header('MCP-Protocol-Version', AtlasOpenBrainMcpService::PROTOCOL_VERSION);
        }

        $raw = trim($request->getContent());
        if ($raw === '') {
            return $this->jsonRpcError(null, -32700, 'Empty JSON-RPC request.', 400);
        }

        try {
            $payload = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->jsonRpcError(null, -32700, 'Invalid JSON body.', 400);
        }

        if (! is_array($payload) || array_is_list($payload)) {
            return $this->jsonRpcError(null, -32600, 'MCP HTTP endpoint expects one JSON-RPC request object.', 400);
        }

        $response = $mcp->handleJsonRpc($payload);
        if ($response === null) {
            return response('', 202)
                ->header('MCP-Protocol-Version', AtlasOpenBrainMcpService::PROTOCOL_VERSION);
        }

        return response()
            ->json($response)
            ->header('MCP-Protocol-Version', AtlasOpenBrainMcpService::PROTOCOL_VERSION);
    }

    private function originAllowed(Request $request): bool
    {
        $origin = $request->headers->get('Origin');
        if (! is_string($origin) || trim($origin) === '') {
            return true;
        }

        $allowed = collect((array) config('atlas.open_brain.mcp.allowed_origins', []))
            ->filter(fn (mixed $value): bool => is_string($value) && trim($value) !== '')
            ->map(fn (string $value): string => trim($value))
            ->values();

        return $allowed->contains('*') || $allowed->contains($origin);
    }

    private function protocolVersionAllowed(Request $request): bool
    {
        $version = $request->headers->get('MCP-Protocol-Version');

        return ! is_string($version)
            || trim($version) === ''
            || trim($version) === AtlasOpenBrainMcpService::PROTOCOL_VERSION;
    }

    private function jsonRpcError(mixed $id, int $code, string $message, int $status): JsonResponse
    {
        return response()->json([
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ], $status)->header('MCP-Protocol-Version', AtlasOpenBrainMcpService::PROTOCOL_VERSION);
    }
}
