<?php

declare(strict_types=1);

namespace App\Services\Engineering\CodeGraph;

use App\Models\AiCodebaseWorldModel;
use App\Models\AiCodebaseWorldModelEdge;
use App\Models\AiCodebaseWorldModelNode;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Support\Collection;

/**
 * Atlas-as-language-server: a MINIMAL but real LSP surface over the AP-811 code
 * graph. It speaks JSON-RPC 2.0 for three methods and answers them straight from
 * the world-model nodes/edges — turning the symbol graph into the same
 * "go-to-definition / find-references" primitives an editor language server
 * exposes, but sourced from Atlas's own governed graph instead of an in-editor
 * indexer.
 *
 *  - `initialize`            → server capabilities (definition + references).
 *  - `textDocument/definition` → given a symbol name/FQN, the defining node's
 *                                location (path + range) from the graph.
 *  - `textDocument/references` → given a symbol, the incoming edges (who
 *                                references it) as locations.
 *
 * Pure-ish: reads the graph only, never writes, no clock. Fail-safe by contract:
 * an unknown method returns a JSON-RPC method-not-found error; a missing symbol
 * or absent graph returns an empty result (never an exception). Strictly
 * additive — a NEW standalone reader; it touches no existing class and changes
 * no existing behavior.
 *
 * Scope resolution reuses {@see CodeGraphWorkspaceModelResolver} so a request
 * always reads the RIGHT workspace's symbol graph (scope "<workspace>-symbols"),
 * never the global-latest model that a second workspace would shadow. The
 * workspace is taken from the request params (`workspace`) and defaults to the
 * primary workspace; an explicit `worldModelId` param pins an exact model.
 *
 * [php] Kernel-grade reader. No flag gates the in-process handle() — it is a
 * pure read with no side effects; the runtime stdio entrypoint (the optional
 * Artisan command) is the place that is opt-in.
 */
class CodeGraphLanguageServer
{
    public const JSONRPC_VERSION = '2.0';

    public const SERVER_NAME = 'atlas-code-graph-lsp';

    /** Symbol node ids are stored as "sym:<FQN>" by the symbol builder. */
    private const SYMBOL_PREFIX = 'sym:';

    /** JSON-RPC 2.0 standard error codes (subset we emit). */
    private const ERROR_METHOD_NOT_FOUND = -32601;

    private const ERROR_INVALID_REQUEST = -32600;

    public function __construct(
        private readonly CodeGraphWorkspaceModelResolver $resolver = new CodeGraphWorkspaceModelResolver,
    ) {}

    /**
     * Handle a single JSON-RPC request and return its JSON-RPC response.
     *
     * @param  array<string,mixed>  $request
     * @return array<string,mixed>
     */
    public function handle(array $request): array
    {
        $id = $request['id'] ?? null;
        $method = $request['method'] ?? null;

        if (! is_string($method) || $method === '') {
            return $this->error($id, self::ERROR_INVALID_REQUEST, 'Invalid Request: missing method.');
        }

        /** @var array<string,mixed> $params */
        $params = is_array($request['params'] ?? null) ? $request['params'] : [];

        return match ($method) {
            'initialize' => $this->success($id, $this->initialize()),
            'textDocument/definition' => $this->success($id, $this->definition($params)),
            'textDocument/references' => $this->success($id, $this->references($params)),
            default => $this->error($id, self::ERROR_METHOD_NOT_FOUND, "Method not found: {$method}"),
        };
    }

    /**
     * LSP `initialize` result — advertise only the capabilities we actually back.
     *
     * @return array<string,mixed>
     */
    private function initialize(): array
    {
        return [
            'capabilities' => [
                'definitionProvider' => true,
                'referencesProvider' => true,
                // We resolve whole-symbol locations, not character positions, so
                // we declare no positionEncoding-sensitive providers beyond these.
            ],
            'serverInfo' => [
                'name' => self::SERVER_NAME,
                'version' => CodeGraphSymbolResolver::SCHEMA,
            ],
        ];
    }

    /**
     * `textDocument/definition` — resolve the defining node for a symbol and
     * return its Location (or [] when unknown).
     *
     * The symbol may be supplied as `params.symbol` (a name / FQN / node_id).
     * Returns a single LSP Location, or an empty array (LSP "no definition").
     *
     * @param  array<string,mixed>  $params
     * @return array<int,array<string,mixed>>
     */
    private function definition(array $params): array
    {
        $symbol = $this->symbolArg($params);
        if ($symbol === null) {
            return [];
        }

        $context = $this->graphContext($params);
        if ($context === null) {
            return [];
        }

        $node = $this->resolveSymbolNode($symbol, $context['nodes']);
        if ($node === null) {
            return [];
        }

        $location = $this->locationFor($node);

        return $location === null ? [] : [$location];
    }

    /**
     * `textDocument/references` — who references the symbol (incoming edges,
     * i.e. edges whose `to_node_id` is the symbol's node). Returns the Locations
     * of the referencing nodes, or [] when none / unknown.
     *
     * @param  array<string,mixed>  $params
     * @return array<int,array<string,mixed>>
     */
    private function references(array $params): array
    {
        $symbol = $this->symbolArg($params);
        if ($symbol === null) {
            return [];
        }

        $context = $this->graphContext($params);
        if ($context === null) {
            return [];
        }

        $node = $this->resolveSymbolNode($symbol, $context['nodes']);
        if ($node === null) {
            return [];
        }

        /** @var Collection<int,AiCodebaseWorldModelNode> $nodes */
        $nodes = $context['nodes'];
        $nodeByNodeId = $nodes->keyBy('node_id');

        $includeDeclaration = (bool) ($params['context']['includeDeclaration'] ?? false);

        $locations = [];
        $seen = [];

        foreach ($this->incomingEdges($context['model'], $node->node_id) as $edge) {
            $referrer = $nodeByNodeId->get($edge->from_node_id);
            if (! $referrer instanceof AiCodebaseWorldModelNode) {
                continue;
            }
            $location = $this->locationFor($referrer);
            if ($location === null) {
                continue;
            }
            $key = $location['uri'].'|'.$edge->from_node_id;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $location['edgeType'] = $edge->edge_type;
            $locations[] = $location;
        }

        if ($includeDeclaration) {
            $declaration = $this->locationFor($node);
            if ($declaration !== null) {
                $key = $declaration['uri'].'|'.$node->node_id;
                if (! isset($seen[$key])) {
                    $locations[] = $declaration;
                }
            }
        }

        // Deterministic ordering so identical graphs return byte-identical results.
        usort(
            $locations,
            static fn (array $a, array $b): int => strcmp((string) $a['uri'], (string) $b['uri'])
                ?: strcmp((string) ($a['edgeType'] ?? ''), (string) ($b['edgeType'] ?? '')),
        );

        return $locations;
    }

    /**
     * Resolve the symbol argument to a node within the loaded set. Tries, in
     * order: exact node_id, the "sym:<symbol>" form, then a unique class-name
     * suffix match (so a bare class name resolves to its single FQN node).
     *
     * @param  Collection<int,AiCodebaseWorldModelNode>  $nodes
     */
    private function resolveSymbolNode(string $symbol, Collection $nodes): ?AiCodebaseWorldModelNode
    {
        $byNodeId = $nodes->keyBy('node_id');

        $exact = $byNodeId->get($symbol);
        if ($exact instanceof AiCodebaseWorldModelNode) {
            return $exact;
        }

        $fqn = ltrim($symbol, '\\');
        $symId = self::SYMBOL_PREFIX.$fqn;
        $bySymId = $byNodeId->get($symId);
        if ($bySymId instanceof AiCodebaseWorldModelNode) {
            return $bySymId;
        }

        // Unique class-name suffix match: "Bar" → "sym:App\Foo\Bar" iff exactly one.
        $needle = $this->classTail($fqn);
        if ($needle === '') {
            return null;
        }

        $candidates = $nodes->filter(static function (AiCodebaseWorldModelNode $node) use ($needle): bool {
            if (! is_string($node->node_id) || ! str_starts_with($node->node_id, self::SYMBOL_PREFIX)) {
                return false;
            }
            $tail = self::classTailStatic(substr($node->node_id, strlen(self::SYMBOL_PREFIX)));

            return strcasecmp($tail, $needle) === 0;
        })->values();

        return $candidates->count() === 1 ? $candidates->first() : null;
    }

    /**
     * Build an LSP Location from a node: its path (column first, then
     * metadata.path) plus a range from metadata.range when the graph carries
     * one, else a zero range (a valid LSP range pointing at the file head).
     *
     * @return array<string,mixed>|null
     */
    private function locationFor(AiCodebaseWorldModelNode $node): ?array
    {
        $metadata = is_array($node->metadata) ? $node->metadata : [];

        $path = is_string($node->path) && trim($node->path) !== ''
            ? $node->path
            : (is_string($metadata['path'] ?? null) && trim((string) $metadata['path']) !== '' ? (string) $metadata['path'] : null);

        if ($path === null) {
            return null;
        }

        return [
            'uri' => $this->toUri($path),
            'range' => $this->rangeFor($metadata['range'] ?? null),
            'nodeId' => $node->node_id,
            'nodeType' => $node->node_type,
        ];
    }

    /**
     * Normalize a metadata range into the LSP {start:{line,character}, end:{…}}
     * shape. Accepts a stored LSP-shaped range, a [startLine,endLine] pair, or
     * nothing → a zero range. Lines/characters are zero-based per the LSP spec.
     *
     * @return array<string,array<string,int>>
     */
    private function rangeFor(mixed $range): array
    {
        $zero = ['start' => ['line' => 0, 'character' => 0], 'end' => ['line' => 0, 'character' => 0]];

        if (! is_array($range)) {
            return $zero;
        }

        // Already LSP-shaped.
        if (isset($range['start']['line'], $range['end']['line'])) {
            return [
                'start' => [
                    'line' => max(0, (int) $range['start']['line']),
                    'character' => max(0, (int) ($range['start']['character'] ?? 0)),
                ],
                'end' => [
                    'line' => max(0, (int) $range['end']['line']),
                    'character' => max(0, (int) ($range['end']['character'] ?? 0)),
                ],
            ];
        }

        // [startLine, endLine] (1-based source lines → 0-based LSP).
        if (isset($range['start_line']) || isset($range['startLine'])) {
            $start = max(0, (int) ($range['start_line'] ?? $range['startLine'] ?? 1) - 1);
            $end = max($start, (int) ($range['end_line'] ?? $range['endLine'] ?? ($start + 1)) - 1);

            return [
                'start' => ['line' => $start, 'character' => 0],
                'end' => ['line' => $end, 'character' => 0],
            ];
        }

        return $zero;
    }

    /**
     * Load the world model + its nodes for the request. Returns null (→ empty
     * result) when the tables are absent or no matching model exists.
     *
     * @param  array<string,mixed>  $params
     * @return array{model:AiCodebaseWorldModel, nodes:Collection<int,AiCodebaseWorldModelNode>}|null
     */
    private function graphContext(array $params): ?array
    {
        if (! $this->tablesReady()) {
            return null;
        }

        $model = $this->resolveModel($params);
        if ($model === null) {
            return null;
        }

        /** @var Collection<int,AiCodebaseWorldModelNode> $nodes */
        $nodes = AiCodebaseWorldModelNode::query()
            ->where('world_model_id', $model->id)
            ->orderBy('node_id')
            ->get();

        return ['model' => $model, 'nodes' => $nodes];
    }

    /**
     * Resolve which world model to read: an explicit `worldModelId` param pins a
     * model_id; otherwise the symbol-level model for the requested (or default)
     * workspace, scope-aware so a second workspace never shadows the primary.
     *
     * @param  array<string,mixed>  $params
     */
    private function resolveModel(array $params): ?AiCodebaseWorldModel
    {
        $modelId = $params['worldModelId'] ?? null;
        if (is_string($modelId) && trim($modelId) !== '') {
            return AiCodebaseWorldModel::query()
                ->where('model_id', $modelId)
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->first();
        }

        $workspace = is_string($params['workspace'] ?? null) && trim((string) $params['workspace']) !== ''
            ? (string) $params['workspace']
            : CodeGraphWorkspaceModelResolver::DEFAULT_WORKSPACE;

        return $this->resolver->symbolModel($workspace);
    }

    /**
     * Incoming edges into a node within a model (who references it).
     *
     * @return Collection<int,AiCodebaseWorldModelEdge>
     */
    private function incomingEdges(AiCodebaseWorldModel $model, string $nodeId): Collection
    {
        return AiCodebaseWorldModelEdge::query()
            ->where('world_model_id', $model->id)
            ->where('to_node_id', $nodeId)
            ->orderBy('from_node_id')
            ->orderBy('edge_type')
            ->get();
    }

    /**
     * @param  array<string,mixed>  $params
     */
    private function symbolArg(array $params): ?string
    {
        $symbol = $params['symbol'] ?? null;
        if (! is_string($symbol)) {
            return null;
        }
        $symbol = trim($symbol);

        return $symbol === '' ? null : $symbol;
    }

    private function classTail(string $fqn): string
    {
        return self::classTailStatic($fqn);
    }

    private static function classTailStatic(string $fqn): string
    {
        $fqn = ltrim($fqn, '\\');
        // Drop any "::method" tail then take the segment after the last separator.
        $base = explode('::', $fqn, 2)[0];
        $parts = explode('\\', $base);

        return (string) end($parts);
    }

    private function toUri(string $path): string
    {
        if (str_contains($path, '://')) {
            return $path;
        }

        return 'file://'.(str_starts_with($path, '/') ? $path : '/'.$path);
    }

    /**
     * @return array<string,mixed>
     */
    private function success(mixed $id, mixed $result): array
    {
        return [
            'jsonrpc' => self::JSONRPC_VERSION,
            'id' => $this->normalizeId($id),
            'result' => $result,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function error(mixed $id, int $code, string $message): array
    {
        return [
            'jsonrpc' => self::JSONRPC_VERSION,
            'id' => $this->normalizeId($id),
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ];
    }

    private function normalizeId(mixed $id): string|int|null
    {
        if (is_int($id) || is_string($id)) {
            return $id;
        }

        return null;
    }

    private function tablesReady(): bool
    {
        return DatabaseTableAvailability::all([
            'ai_codebase_world_models',
            'ai_codebase_world_model_nodes',
            'ai_codebase_world_model_edges',
        ]);
    }
}
