<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Quarantine;

/**
 * Atlas Programming Self-Construction Forge Map decider.
 *
 * Pure, deterministic implementation of the short orientation map that tells an
 * agent the canonical hierarchy between Self-Construction OS, Self-Programming
 * OS, Forge Continuum OS, Atlas Code, Programming Obra and providers — so the
 * agent never invents a new programming OS, never calls Atlas Code the whole
 * system, and never treats a provider as a fixed role.
 *
 * The doc is an "AI read-first" map, but it carries three enforceable contracts
 * that this service turns into runtime:
 *
 *   Contract 1 — "Hierarquia Canonica" / "Fluxo": the closed, ordered layer
 *     stack (Self-Construction OS = mother-law, then Self-Programming safety,
 *     then Forge Continuum operational, then Atlas Code surface, then
 *     Programming Obra unit, then Provider executor). `rankOf` resolves a layer
 *     to its rank (1 = highest authority) and `compareLayers` decides which of
 *     two layers governs the other — the doc's "X does not replace Y" rules.
 *
 *   Contract 2 — "Ordem De Leitura Para IA": route a question topic to the
 *     exact ordered list of canonical docs to read, this map always first.
 *
 *   Contract 3 — "O Que Cada Nome Quer Dizer" + "Anti-Confusoes" +
 *     forbidden_changes/quality_gates: resolve a canonical name to what it IS
 *     and what it is NOT, and evaluate a stated claim to an allowed/blocked
 *     verdict (Atlas Code is the whole system → blocked; Forge replaces
 *     Self-Construction → blocked; Self-Programming is free runtime → blocked;
 *     provider is a fixed role → blocked; a new parallel programming OS →
 *     blocked).
 *
 * The service NEVER scans the filesystem, parses frontmatter, performs IO,
 * calls a provider, mutates storage or touches the database. The caller passes
 * a layer key, a topic, a name or a claim id and small flags; this decider
 * returns the single auditable verdict.
 *
 * @see docs/engineering-knowledge-base/atlas-programming-self-construction-forge-map-v1.md
 */
final class AtlasProgrammingSelfConstructionForgeMapService
{
    /** Stable schema id for the verdict envelope this service emits. */
    public const VERDICT_SCHEMA = 'atlas.programming.self_construction_forge_map.v1';

    /** This map's own canonical doc (always read first per "Ordem De Leitura"). */
    public const MAP_DOC = 'atlas-programming-self-construction-forge-map-v1';

    /** Highest authority rank (the mother-law tier). */
    public const TOP_RANK = 1;

    /** Rank returned when a layer is not part of the canonical stack. */
    public const UNRANKED = 999;

    public const LAYER_SELF_CONSTRUCTION = 'self-construction-os';
    public const LAYER_SELF_PROGRAMMING = 'self-programming-os';
    public const LAYER_FORGE_CONTINUUM = 'forge-continuum-os';
    public const LAYER_ATLAS_CODE = 'atlas-code';
    public const LAYER_PROGRAMMING_OBRA = 'programming-obra';
    public const LAYER_PROVIDER = 'provider';

    public const STATUS_ALLOWED = 'allowed';
    public const STATUS_BLOCKED = 'blocked';

    /**
     * Contract 1 — the canonical layer stack, top-down.
     *
     * Rank is the array order + 1: rank 1 is the mother-law (highest authority),
     * the rest descend to the substitutable executor. "kind" carries the doc's
     * one-line "Fluxo" meaning. Self-Construction OS is the lei-mae; every layer
     * below it is governed by it and may not replace it.
     *
     * @var array<int, array{key:string, name:string, kind:string}>
     */
    public const HIERARCHY = [
        [
            'key' => self::LAYER_SELF_CONSTRUCTION,
            'name' => 'Self-Construction OS',
            'kind' => 'mother-law for Atlas building and evolving Atlas',
        ],
        [
            'key' => self::LAYER_SELF_PROGRAMMING,
            'name' => 'Self-Programming OS',
            'kind' => 'governed self-modification safety layer (not free runtime)',
        ],
        [
            'key' => self::LAYER_FORGE_CONTINUUM,
            'name' => 'Forge Continuum OS',
            'kind' => 'operational specialization for heavy programming',
        ],
        [
            'key' => self::LAYER_ATLAS_CODE,
            'name' => 'Atlas Code',
            'kind' => 'human surface of Forge Continuum (not the whole system)',
        ],
        [
            'key' => self::LAYER_PROGRAMMING_OBRA,
            'name' => 'Programming Obra',
            'kind' => 'productive software unit inside a Project/Workspace',
        ],
        [
            'key' => self::LAYER_PROVIDER,
            'name' => 'Provider',
            'kind' => 'substitutable executor, never the owner of the architecture',
        ],
    ];

    /**
     * Contract 2 — the "Ordem De Leitura Para IA" router.
     *
     * Each topic maps to the documented ordered reading list. The map doc is
     * prepended at read time so it is always first, exactly as the doc states
     * ("1. Este doc.").
     *
     * @var array<string, list<string>>
     */
    public const READING_ORDER = [
        // "Para pergunta sobre Atlas Code ou programacao pesada"
        'atlas-code' => [
            'atlas-programming-forge-flow',
            'atlas-forge-continuum-os',
            'atlas-code-programming-obras-operating-system',
        ],
        // "Para pergunta sobre Atlas construindo Atlas"
        'building-atlas' => [
            'atlas-ai-self-construction-os',
            'atlas-self-directed-evolution-layer',
            'self-construction/self-programming-safety-contract',
            'self-construction/agent-control-plane-contract',
            'self-construction/multi-provider-agent-orchestration-contract',
        ],
        // "Para pergunta sobre varios providers na mesma Obra"
        'multi-provider' => [
            'atlas-code-adaptive-provider-operating-room-v1',
            'self-construction/multi-provider-agent-orchestration-contract',
            'self-construction/ai-implementation-packet-contract',
            'self-construction/work-splitter-contract',
        ],
    ];

    /**
     * Contract 3 — "O Que Cada Nome Quer Dizer": each canonical name with what
     * it IS and the doc's explicit "Nao e".
     *
     * @var array<string, array{is:string, is_not:string}>
     */
    public const NAME_MEANINGS = [
        self::LAYER_SELF_CONSTRUCTION => [
            'is' => 'how Atlas evolves Atlas with docs, SDD, packets, gates, evidence and learning',
            'is_not' => 'a programming screen',
        ],
        self::LAYER_SELF_PROGRAMMING => [
            'is' => 'governed self-modification layer with safety contracts',
            'is_not' => 'free runtime or permission to self-edit everything',
        ],
        'self-directed-evolution-layer' => [
            'is' => 'gap/proposal/roadmap/curation layer over existing owners',
            'is_not' => 'a new OS, a new builder or a self-approver',
        ],
        self::LAYER_FORGE_CONTINUUM => [
            'is' => 'complete heavy-programming system: Obra, Forge Workspace, Atlas Decide, providers, fallback, review, repair, evidence',
            'is_not' => 'only a provider router or a prompt',
        ],
        'programming-forge-flow' => [
            'is' => 'map/taxonomy of the whole heavy-programming flow',
            'is_not' => 'an executor',
        ],
        self::LAYER_ATLAS_CODE => [
            'is' => 'human desktop surface to operate Programming Obras',
            'is_not' => 'the whole system',
        ],
        self::LAYER_PROGRAMMING_OBRA => [
            'is' => 'governed productive unit of a software delivery',
            'is_not' => 'a chat, branch, ticket or terminal',
        ],
        self::LAYER_PROVIDER => [
            'is' => 'Claude, Codex, Gemini, local or a future executor',
            'is_not' => 'a permanent owner of a role',
        ],
    ];

    /**
     * Contract 3 — the closed set of forbidden claims (forbidden_changes +
     * "Anti-Confusoes" + named quality_gates). Each maps to the gate it breaks
     * and the canonical correction.
     *
     * @var array<string, array{gate:string, correction:string}>
     */
    public const FORBIDDEN_CLAIMS = [
        'atlas-code-is-whole-system' => [
            'gate' => 'correct-canonical-name',
            'correction' => 'Atlas Code is the human surface; Forge Continuum OS is the heavy-programming operating system.',
        ],
        'forge-replaces-self-construction' => [
            'gate' => 'no-duplicate-os',
            'correction' => 'Forge Continuum OS specializes heavy programming; it does not replace Self-Construction OS.',
        ],
        'self-programming-is-free-runtime' => [
            'gate' => 'self-programming-not-overclaimed',
            'correction' => 'Self-Programming OS requires safety contracts, receipts, gates and evidence; it is not unrestricted autonomy.',
        ],
        'provider-is-fixed-role' => [
            'gate' => 'provider-neutrality-preserved',
            'correction' => 'Providers are substitutable executors; Claude, Codex, Gemini and local can swap roles per Atlas Decide.',
        ],
        'new-parallel-programming-os' => [
            'gate' => 'no-duplicate-os',
            'correction' => 'Do not create a parallel programming OS; the operational role already belongs to Forge Continuum OS.',
        ],
        'self-directed-evolution-is-new-os' => [
            'gate' => 'no-duplicate-os',
            'correction' => 'Self-Directed Evolution normalizes proposals over existing owners and goes through human curation; it is not a new OS.',
        ],
        'atlas-code-programs-alone' => [
            'gate' => 'correct-canonical-name',
            'correction' => 'Atlas Code does not program by itself; it shows and governs the human operation.',
        ],
    ];

    /**
     * Contract 1 — rank of a canonical layer (1 = highest authority / mother-law).
     * Returns UNRANKED for anything outside the canonical stack.
     */
    public function rankOf(string $layer): int
    {
        $key = $this->normalizeKey($layer);

        foreach (self::HIERARCHY as $index => $row) {
            if ($row['key'] === $key) {
                return $index + 1;
            }
        }

        return self::UNRANKED;
    }

    /**
     * Contract 1 — the full canonical stack with resolved ranks, top-down.
     *
     * @return array{schema:string, root:string, layers:list<array{rank:int, key:string, name:string, kind:string}>}
     */
    public function hierarchy(): array
    {
        $layers = [];
        foreach (self::HIERARCHY as $index => $row) {
            $layers[] = [
                'rank' => $index + 1,
                'key' => $row['key'],
                'name' => $row['name'],
                'kind' => $row['kind'],
            ];
        }

        return [
            'schema' => self::VERDICT_SCHEMA,
            'root' => self::LAYER_SELF_CONSTRUCTION,
            'layers' => $layers,
        ];
    }

    /**
     * Contract 1 — which of two layers governs the other.
     *
     * Lower rank wins (governs). Equal rank → same layer. An unranked key never
     * governs a ranked one. This encodes the doc's "X does not replace Y" and
     * "Atlas Code is just a surface" rules deterministically.
     *
     * @return array{schema:string, a:array{key:string, rank:int}, b:array{key:string, rank:int}, governs:?string, relation:string, reason:string}
     */
    public function compareLayers(string $a, string $b): array
    {
        $keyA = $this->normalizeKey($a);
        $keyB = $this->normalizeKey($b);
        $rankA = $this->rankOf($keyA);
        $rankB = $this->rankOf($keyB);

        if ($rankA === self::UNRANKED && $rankB === self::UNRANKED) {
            return $this->comparison($keyA, $rankA, $keyB, $rankB, null, 'both-unknown', 'Neither layer is part of the canonical programming stack.');
        }

        if ($rankA === $rankB) {
            return $this->comparison($keyA, $rankA, $keyB, $rankB, $keyA, 'same-layer', 'Both refer to the same canonical layer.');
        }

        if ($rankA < $rankB) {
            return $this->comparison(
                $keyA,
                $rankA,
                $keyB,
                $rankB,
                $keyA,
                'governs',
                sprintf('%s sits above %s in the canonical hierarchy and governs it.', $this->nameOf($keyA), $this->nameOf($keyB)),
            );
        }

        return $this->comparison(
            $keyA,
            $rankA,
            $keyB,
            $rankB,
            $keyB,
            'governs',
            sprintf('%s sits above %s in the canonical hierarchy and governs it.', $this->nameOf($keyB), $this->nameOf($keyA)),
        );
    }

    /**
     * Contract 2 — the ordered reading list for a question topic, this map
     * always first. Unknown topics fall back to read this map only.
     *
     * @return array{schema:string, topic:string, known:bool, read_first:string, order:list<string>}
     */
    public function readingOrder(string $topic): array
    {
        $key = $this->normalizeTopic($topic);
        $known = array_key_exists($key, self::READING_ORDER);
        $order = $known
            ? array_merge([self::MAP_DOC], self::READING_ORDER[$key])
            : [self::MAP_DOC];

        return [
            'schema' => self::VERDICT_SCHEMA,
            'topic' => $known ? $key : 'unknown',
            'known' => $known,
            'read_first' => self::MAP_DOC,
            'order' => $order,
        ];
    }

    /**
     * Contract 3 — resolve a canonical name to what it IS and what it is NOT.
     *
     * @return array{schema:string, name:string, known:bool, is:?string, is_not:?string}
     */
    public function resolveName(string $name): array
    {
        $key = $this->normalizeKey($name);
        $meaning = self::NAME_MEANINGS[$key] ?? null;

        return [
            'schema' => self::VERDICT_SCHEMA,
            'name' => $key,
            'known' => $meaning !== null,
            'is' => $meaning['is'] ?? null,
            'is_not' => $meaning['is_not'] ?? null,
        ];
    }

    /**
     * Contract 3 — evaluate a stated claim against the forbidden-claim set.
     *
     * A claim id present in FORBIDDEN_CLAIMS is blocked with the gate it breaks
     * and the canonical correction. Anything else is allowed (the map only
     * forbids the documented confusions; it does not gate arbitrary statements).
     *
     * @return array{schema:string, claim:string, status:string, gate:?string, correction:?string}
     */
    public function evaluateClaim(string $claim): array
    {
        $key = $this->normalizeKey($claim);
        $forbidden = self::FORBIDDEN_CLAIMS[$key] ?? null;

        if ($forbidden !== null) {
            return [
                'schema' => self::VERDICT_SCHEMA,
                'claim' => $key,
                'status' => self::STATUS_BLOCKED,
                'gate' => $forbidden['gate'],
                'correction' => $forbidden['correction'],
            ];
        }

        return [
            'schema' => self::VERDICT_SCHEMA,
            'claim' => $key,
            'status' => self::STATUS_ALLOWED,
            'gate' => null,
            'correction' => null,
        ];
    }

    /**
     * Convenience aggregate: the whole map as one auditable envelope (hierarchy,
     * the documented quality gates, and the canonical one-liner). Used as the
     * command's safe default.
     *
     * @return array{schema:string, map_doc:string, root:string, hierarchy:list<array{rank:int, key:string, name:string, kind:string}>, gates:list<string>, canonical_phrase:string}
     */
    public function summary(): array
    {
        $gates = [];
        foreach (self::FORBIDDEN_CLAIMS as $row) {
            $gates[] = $row['gate'];
        }
        $gates = array_values(array_unique($gates));

        return [
            'schema' => self::VERDICT_SCHEMA,
            'map_doc' => self::MAP_DOC,
            'root' => self::LAYER_SELF_CONSTRUCTION,
            'hierarchy' => $this->hierarchy()['layers'],
            'gates' => $gates,
            'canonical_phrase' => 'Atlas Code is the surface. Forge Continuum OS is the heavy-programming operating system. '
                .'Self-Construction OS is the mother-law of Atlas evolution. Self-Programming OS is the governed '
                .'self-modification layer. Providers are substitutable executors, Obras are the productive units, '
                .'and Self-Directed Evolution is the governed inbox of proposals over existing owners.',
        ];
    }

    /**
     * @return array{schema:string, a:array{key:string, rank:int}, b:array{key:string, rank:int}, governs:?string, relation:string, reason:string}
     */
    private function comparison(string $keyA, int $rankA, string $keyB, int $rankB, ?string $governs, string $relation, string $reason): array
    {
        return [
            'schema' => self::VERDICT_SCHEMA,
            'a' => ['key' => $keyA, 'rank' => $rankA],
            'b' => ['key' => $keyB, 'rank' => $rankB],
            'governs' => $governs,
            'relation' => $relation,
            'reason' => $reason,
        ];
    }

    private function nameOf(string $key): string
    {
        foreach (self::HIERARCHY as $row) {
            if ($row['key'] === $key) {
                return $row['name'];
            }
        }

        return $key;
    }

    /**
     * Normalize a layer/name/claim identifier to a stable kebab key: lowercase,
     * strip a known doc prefix/suffix, collapse non-alphanumerics to single
     * dashes. Tolerant of spaces, ".md", path prefixes and the "-v1" suffix.
     */
    private function normalizeKey(string $value): string
    {
        $key = strtolower(trim($value));
        $key = preg_replace('#\.md$#', '', $key) ?? $key;
        // keep nested contract paths (e.g. self-construction/...) intact, but
        // drop a leading docs path if one was passed.
        $key = preg_replace('#^docs/engineering-knowledge-base/#', '', $key) ?? $key;
        $key = preg_replace('#[^a-z0-9/]+#', '-', $key) ?? $key;
        $key = trim($key, '-');

        // canonical layer aliases
        return match ($key) {
            'self-construction', 'self-construction-os', 'atlas-ai-self-construction-os' => self::LAYER_SELF_CONSTRUCTION,
            'self-programming', 'self-programming-os' => self::LAYER_SELF_PROGRAMMING,
            'forge', 'forge-continuum', 'forge-continuum-os', 'atlas-forge-continuum-os' => self::LAYER_FORGE_CONTINUUM,
            'atlas-code', 'code' => self::LAYER_ATLAS_CODE,
            'obra', 'programming-obra' => self::LAYER_PROGRAMMING_OBRA,
            'provider', 'providers' => self::LAYER_PROVIDER,
            default => $key,
        };
    }

    private function normalizeTopic(string $topic): string
    {
        $key = strtolower(trim($topic));
        $key = preg_replace('#[^a-z0-9]+#', '-', $key) ?? $key;
        $key = trim($key, '-');

        return match ($key) {
            'atlas-code', 'heavy-programming', 'programming', 'programacao', 'programacao-pesada' => 'atlas-code',
            'building-atlas', 'self-construction', 'atlas-building-atlas', 'self-construct' => 'building-atlas',
            'multi-provider', 'providers', 'provider', 'multiprovider' => 'multi-provider',
            default => $key,
        };
    }
}
