<?php

namespace App\Services\Ai\Mcp;

/**
 * Atlas Cognition Operating System — Absorcao 4 (Progressive Disclosure MCP).
 *
 * Schema canon: `atlas.mcp.tier.v1`.
 * Doc canon: `atlas-cognition-operating-system.md`.
 * Doc absorcao: `atlas-external-memory-pattern-absorptions-v1.md` (Absorcao 4).
 *
 * Inspirado em claude-mem progressive disclosure: organiza tools MCP do Atlas
 * Open Brain em TRES TIERS custo-crescente:
 *
 *  - Tier 1 Discovery (~50 tok/item, low cost)
 *      Tools que listam IDs + metadata minima.
 *      Ex: `atlas_memory_search_brief`, `atlas_docs_search_brief`.
 *
 *  - Tier 2 Context (cost medio, requires_anchor)
 *      Tools que retornam cronologia/contexto ao redor de anchor.
 *      Ex: `atlas_memory_timeline`, `atlas_evidence_timeline`.
 *
 *  - Tier 3 Detail (~500-1000 tok/item, high cost, batch obrigatorio)
 *      Tools que retornam payload completo por ID.
 *      Ex: `atlas_memory_get_full`, `atlas_evidence_get_full`.
 *
 * Fluxo cliente MCP recomendado (provider economy):
 *    Tier 1 search_brief -> seleciona IDs interessantes
 * -> Tier 2 timeline (anchor=ID) -> contexto cronologico
 * -> Tier 3 get_full (ids=[a,b,c]) -> payload completo apenas dos IDs relevantes
 *
 * Claim claude-mem: 10x token savings vs full dump.
 *
 * Phase 1 (esta versao):
 *  - Classificacao + manifest de cada tool MCP existente em seu tier.
 *  - tierManifest() retorna lista canonica para inclusao no MCP server descriptor.
 *  - getTier(toolName) retorna tier de um tool especifico.
 *  - Tools legadas mantem registro tier=null (back-compat).
 *
 * Phase 2 (proximo AP):
 *  - Reorganizar `AtlasOpenBrainMcpService` em 3 endpoints fisicos: _brief, _timeline, _get_full.
 *  - Wire ACPFR (Pareto Frontier) para usar tier como cost signal em utility function.
 *  - Wire ARCLG (Cost Latency Governor) para tracking de tier consumption por flow.
 *  - Adicionar tool especial `__atlas_mcp_workflow_hint` que explica progressive disclosure ao cliente MCP.
 */
class AtlasMcpTierService
{
    public const TIER_DISCOVERY = 1;
    public const TIER_CONTEXT = 2;
    public const TIER_DETAIL = 3;

    public const COST_LOW = 'low';
    public const COST_MEDIUM = 'medium';
    public const COST_HIGH = 'high';

    public const ALLOWED_TIERS = [self::TIER_DISCOVERY, self::TIER_CONTEXT, self::TIER_DETAIL];
    public const ALLOWED_COST_CLASSES = [self::COST_LOW, self::COST_MEDIUM, self::COST_HIGH];

    /**
     * Registry estatico de classificacao de tools.
     * Phase 1: somente declarativo. Phase 2 reorganiza implementacao real.
     *
     * @var array<string,array{tier:int,cost_class:string,token_estimate:int,requires_anchor:bool,batch:bool}>
     */
    private const TIER_REGISTRY = [
        // ============================================================
        // TIER 1 — Discovery (low cost, ~50 tokens/item)
        // ============================================================
        'atlas_memory_search_brief' => [
            'tier' => self::TIER_DISCOVERY,
            'cost_class' => self::COST_LOW,
            'token_estimate' => 50,
            'requires_anchor' => false,
            'batch' => false,
        ],
        'atlas_docs_search_brief' => [
            'tier' => self::TIER_DISCOVERY,
            'cost_class' => self::COST_LOW,
            'token_estimate' => 50,
            'requires_anchor' => false,
            'batch' => false,
        ],
        'atlas_code_search_brief' => [
            'tier' => self::TIER_DISCOVERY,
            'cost_class' => self::COST_LOW,
            'token_estimate' => 60,
            'requires_anchor' => false,
            'batch' => false,
        ],
        'atlas_evidence_search_brief' => [
            'tier' => self::TIER_DISCOVERY,
            'cost_class' => self::COST_LOW,
            'token_estimate' => 50,
            'requires_anchor' => false,
            'batch' => false,
        ],
        'atlas_capabilities' => [
            'tier' => self::TIER_DISCOVERY,
            'cost_class' => self::COST_LOW,
            'token_estimate' => 200,
            'requires_anchor' => false,
            'batch' => false,
        ],

        // ============================================================
        // TIER 2 — Context (medium cost, requires anchor)
        // ============================================================
        'atlas_memory_timeline' => [
            'tier' => self::TIER_CONTEXT,
            'cost_class' => self::COST_MEDIUM,
            'token_estimate' => 400,
            'requires_anchor' => true,
            'batch' => false,
        ],
        'atlas_evidence_timeline' => [
            'tier' => self::TIER_CONTEXT,
            'cost_class' => self::COST_MEDIUM,
            'token_estimate' => 400,
            'requires_anchor' => true,
            'batch' => false,
        ],
        'atlas_decision_timeline' => [
            'tier' => self::TIER_CONTEXT,
            'cost_class' => self::COST_MEDIUM,
            'token_estimate' => 350,
            'requires_anchor' => true,
            'batch' => false,
        ],

        // ============================================================
        // TIER 3 — Detail (high cost, batch obrigatorio)
        // ============================================================
        'atlas_memory_get_full' => [
            'tier' => self::TIER_DETAIL,
            'cost_class' => self::COST_HIGH,
            'token_estimate' => 800,
            'requires_anchor' => false,
            'batch' => true,
        ],
        'atlas_evidence_get_full' => [
            'tier' => self::TIER_DETAIL,
            'cost_class' => self::COST_HIGH,
            'token_estimate' => 1000,
            'requires_anchor' => false,
            'batch' => true,
        ],
        'atlas_code_get_full' => [
            'tier' => self::TIER_DETAIL,
            'cost_class' => self::COST_HIGH,
            'token_estimate' => 1200,
            'requires_anchor' => false,
            'batch' => true,
        ],
        'atlas_open_brain_context_pack' => [
            'tier' => self::TIER_DETAIL,
            'cost_class' => self::COST_HIGH,
            'token_estimate' => 2000,
            'requires_anchor' => false,
            'batch' => false,
        ],
    ];

    /**
     * Retorna o tier descriptor para um tool especifico.
     * Tools nao classificados retornam null (back-compat).
     *
     * @return array{schema_version:string,tier:int,cost_class:string,token_estimate:int,requires_anchor:bool,batch:bool,provider_safe:bool,tool_name:string}|null
     */
    public function getTier(string $toolName): ?array
    {
        if (! isset(self::TIER_REGISTRY[$toolName])) {
            return null;
        }

        $entry = self::TIER_REGISTRY[$toolName];

        return [
            'schema_version' => 'atlas.mcp.tier.v1',
            'tier' => $entry['tier'],
            'cost_class' => $entry['cost_class'],
            'token_estimate' => $entry['token_estimate'],
            'requires_anchor' => $entry['requires_anchor'],
            'batch' => $entry['batch'],
            'provider_safe' => true,
            'tool_name' => $toolName,
        ];
    }

    /**
     * Retorna manifest completo de todos tools classificados.
     * Para inclusao no descriptor MCP do servidor.
     *
     * @return array{schema_version:string,tiers:array<int,array<int,array<string,mixed>>>,workflow_hint:string,total_tools:int}
     */
    public function tierManifest(): array
    {
        $tiers = [
            self::TIER_DISCOVERY => [],
            self::TIER_CONTEXT => [],
            self::TIER_DETAIL => [],
        ];

        foreach (self::TIER_REGISTRY as $toolName => $entry) {
            $tiers[$entry['tier']][] = [
                'tool_name' => $toolName,
                'cost_class' => $entry['cost_class'],
                'token_estimate' => $entry['token_estimate'],
                'requires_anchor' => $entry['requires_anchor'],
                'batch' => $entry['batch'],
            ];
        }

        return [
            'schema_version' => 'atlas.mcp.tier.v1',
            'tiers' => $tiers,
            'workflow_hint' => 'Prefira Tier 1 -> Tier 2 -> Tier 3 para economizar tokens. Use _brief para listar, _timeline para contexto, _get_full apenas para IDs relevantes.',
            'total_tools' => count(self::TIER_REGISTRY),
        ];
    }

    /**
     * Lista tools de um tier especifico.
     *
     * @return array<int,string>
     */
    public function toolsInTier(int $tier): array
    {
        $tools = [];
        foreach (self::TIER_REGISTRY as $toolName => $entry) {
            if ($entry['tier'] === $tier) {
                $tools[] = $toolName;
            }
        }

        return $tools;
    }

    /**
     * Estimativa de tokens economizada usando fluxo progressivo
     * vs full dump direto em Tier 3.
     *
     * Exemplo: cliente quer 5 memorias.
     *  - Sem progressive: 5 x get_full = 5 x 800 = 4000 tokens.
     *  - Com progressive: search_brief (250) + timeline (400) + get_full(5) = 4650 (SO se precisar todos).
     *  - Mas tipicamente apenas 1-2 sao relevantes apos discovery -> 250 + 400 + 1600 = 2250 (~44% economia).
     *
     * @param  array{full_dump_count:int}|null  $scenarioOverrides
     * @return array{full_dump_tokens:int,progressive_tokens:int,savings_pct:float}
     */
    public function estimateSavings(?array $scenarioOverrides = null): array
    {
        $count = (int) ($scenarioOverrides['full_dump_count'] ?? 5);

        // Heuristica conservadora: 30% dos items descobertos viram detail.
        $detailFraction = 0.3;
        $detailCount = max(1, (int) ceil($count * $detailFraction));

        $fullDumpTokens = $count * 800; // Assume 800 tokens em Tier 3.
        $progressiveTokens =
            50 * $count // Tier 1 search_brief
            + ($detailCount * 400) // Tier 2 timeline para detail candidates
            + ($detailCount * 800); // Tier 3 get_full para detail batch

        $savings = $fullDumpTokens - $progressiveTokens;
        $pct = $fullDumpTokens > 0 ? round(($savings / $fullDumpTokens) * 100, 1) : 0.0;

        return [
            'full_dump_tokens' => $fullDumpTokens,
            'progressive_tokens' => $progressiveTokens,
            'savings_pct' => $pct,
        ];
    }

    public static function isValidTier(int $tier): bool
    {
        return in_array($tier, self::ALLOWED_TIERS, true);
    }

    public static function isValidCostClass(string $costClass): bool
    {
        return in_array($costClass, self::ALLOWED_COST_CLASSES, true);
    }
}
