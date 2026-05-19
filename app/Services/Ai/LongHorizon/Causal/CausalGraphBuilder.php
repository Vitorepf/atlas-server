<?php

declare(strict_types=1);

namespace App\Services\Ai\LongHorizon\Causal;

use App\Services\Ai\LongHorizon\AtlasLongHorizonCanon;
use InvalidArgumentException;

/**
 * Internal helper for {@see LongHorizonCausalDecisionGraphService}.
 *
 * - Mantém set de nodes/edges únicos (chave estável `node:<kind>:<source_id>`).
 * - Anti-leak: rejeita props com chaves suspeitas (`raw_prompt`, `payload`,
 *   `decision_reason`, `prompt_text`, `response_text`).
 * - Anti-cycle: edges são DAG por construção (não detecta ciclos
 *   transitivos, mas evita self-loop e edges duplicados).
 * - Determinismo: ordens estáveis garantem mesmo hash em mesmo state.
 */
final class CausalGraphBuilder
{
    /**
     * Props banidas em todo `meta` de node — protege contra leak de texto raw
     * de prompts, payloads de eventos, decisões etc.
     *
     * @var list<string>
     */
    private const FORBIDDEN_META_KEYS = [
        'raw_prompt',
        'payload',
        'decision_reason',
        'prompt_text',
        'response_text',
        'normalized_intent',
        'blocker_reason',
        'context_summary',
        'instructions',
        'description',
        'input_text',
    ];

    /** @var array<string,array<string,mixed>> */
    private array $nodes = [];

    /** @var array<string,array<string,mixed>> */
    private array $edges = [];

    /** @var array<string,array{code:string,message:string}> */
    private array $gaps = [];

    /** @var list<string> */
    private array $blockerNodeIds = [];

    /** @var list<string> */
    private array $evidenceRefs = [];

    public function __construct(
        private readonly string $scopeType,
        private readonly string $scopeId,
    ) {}

    /**
     * Adds a node and returns its canonical id. Idempotent: same kind+key
     * yields the same id and overwrites previous meta with new (caller is
     * expected to provide stable meta).
     *
     * @param  array<string,mixed>  $meta
     */
    public function addNode(string $kind, string $key, array $meta = []): string
    {
        if (! in_array($kind, AtlasLongHorizonCanon::ALLOWED_CAUSAL_NODE_KINDS, true)) {
            throw new InvalidArgumentException("node kind [{$kind}] not in canon");
        }

        $this->assertCleanMeta($meta);
        $id = "node:{$kind}:{$key}";

        $this->nodes[$id] = [
            'id' => $id,
            'kind' => $kind,
            'key' => $key,
            'meta' => $meta,
        ];

        return $id;
    }

    public function addEdge(string $kind, string $fromNodeId, string $toNodeId): void
    {
        if ($fromNodeId === $toNodeId) {
            // Anti-cycle: self-loop silenciosamente ignorado.
            return;
        }
        if (! in_array($kind, AtlasLongHorizonCanon::ALLOWED_CAUSAL_EDGE_KINDS, true)) {
            throw new InvalidArgumentException("edge kind [{$kind}] not in canon");
        }

        $id = "edge:{$kind}:{$fromNodeId}->{$toNodeId}";
        $this->edges[$id] = [
            'id' => $id,
            'kind' => $kind,
            'from' => $fromNodeId,
            'to' => $toNodeId,
        ];
    }

    public function addGap(string $code, string $message): void
    {
        $key = "{$code}:{$message}";
        $this->gaps[$key] = ['code' => $code, 'message' => $message];
    }

    public function addBlocker(string $nodeId): void
    {
        if (! in_array($nodeId, $this->blockerNodeIds, true)) {
            $this->blockerNodeIds[] = $nodeId;
        }
    }

    public function addEvidenceRef(?string $ref): void
    {
        if ($ref === null) {
            return;
        }
        $clean = trim($ref);
        if ($clean === '') {
            return;
        }
        if (! in_array($clean, $this->evidenceRefs, true)) {
            $this->evidenceRefs[] = $clean;
        }
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function nodesArray(): array
    {
        $values = array_values($this->nodes);
        usort($values, static fn (array $a, array $b): int => strcmp($a['id'], $b['id']));

        return $values;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function edgesArray(): array
    {
        $values = array_values($this->edges);
        usort($values, static fn (array $a, array $b): int => strcmp($a['id'], $b['id']));

        return $values;
    }

    /**
     * @return list<array{code:string,message:string}>
     */
    public function gaps(): array
    {
        $values = array_values($this->gaps);
        usort($values, static fn (array $a, array $b): int => strcmp($a['code'].$a['message'], $b['code'].$b['message']));

        return $values;
    }

    /**
     * @return list<string>
     */
    public function blockers(): array
    {
        $sorted = $this->blockerNodeIds;
        sort($sorted);

        return $sorted;
    }

    /**
     * @return list<string>
     */
    public function evidenceRefs(): array
    {
        $sorted = $this->evidenceRefs;
        sort($sorted);

        return $sorted;
    }

    public function scopeType(): string
    {
        return $this->scopeType;
    }

    public function scopeId(): string
    {
        return $this->scopeId;
    }

    /**
     * @param  array<string,mixed>  $meta
     */
    private function assertCleanMeta(array $meta): void
    {
        foreach ($meta as $key => $_value) {
            if (in_array((string) $key, self::FORBIDDEN_META_KEYS, true)) {
                throw new InvalidArgumentException(
                    "causal graph meta MUST NOT include raw text key [{$key}] — use hash/uuid instead",
                );
            }
        }
    }
}
