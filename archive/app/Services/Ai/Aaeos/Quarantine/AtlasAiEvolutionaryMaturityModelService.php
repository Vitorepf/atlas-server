<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Quarantine;

/**
 * Atlas AI Evolutionary Maturity Model decider.
 *
 * Pure, deterministic runtime that turns the canonical evolutionary-maturity
 * doc into a contract instead of prose. The doc exists to block three errors:
 * thinking Atlas is "just a wrapper", declaring Atlas "done" because it has many
 * modules, and confusing Atlas with AGI/ASI. This service enforces exactly the
 * rules the doc states, never lying and never inflating a level.
 *
 * Documented mechanisms modelled here (and never violated):
 *
 *  1. The canonical evolution lineage (doc "Fluxo"): eleven ordered, named
 *     levels Nivel 0..10 — LLM Wrapper, Atlas Copilot, Atlas Router OS, Atlas
 *     Specialist Runtime, Atlas Persistent Intelligence OS, Atlas
 *     Outcome-Learning OS, Atlas Intelligence Factory OS, Atlas Swarm Company
 *     Runtime, Atlas Autonomous Company OS, Atlas World Action Engine, Atlas
 *     Civilization Intelligence Engine.
 *
 *  2. The official current classification (doc "Classificacao oficial atual" /
 *     "Evidencias"): conservative baseline is "Nivel 4.5 a 5, com inicio forte
 *     de Nivel 6". The next macro patamar is Nivel 7 (Swarm Company Runtime).
 *     Atlas is NOT yet an autonomous company, NOT AGI, NOT ASI.
 *
 *  3. Per-level dependencies (doc "Dependencias" table): each level names what
 *     it depends on; Nivel 10 depends on all previous levels plus ecosystem
 *     governance.
 *
 *  4. The promotion gate (doc "Definition of Done" + "Evidencia minima para
 *     promover nivel"): a level may only be promoted when EVERY one of the eight
 *     documented proofs is present. A single missing proof blocks promotion and
 *     the gate names the missing proofs. "Nao promover nivel por existencia de
 *     doc ou scaffold" — a doc/scaffold alone is rejected.
 *
 *  5. The claim contract (doc "Contrato de claim" + "Regras para IA" 1):
 *     "Atlas nao e AGI. Atlas nao e ASI." Any claim that Atlas is AGI or ASI is
 *     rejected. Atlas may be described as the operating system that orchestrates
 *     ever-stronger cognitive engines.
 *
 *  6. The level-movement rule (doc "Fluxo" + "Regras para IA" 5/6): a level only
 *     moves when the operational unit changes. An isolated feature/button/flow
 *     without evidence wiring does NOT promote a level — it stays inside the
 *     current level. Connecting a flow to Hyperflow/APCR/AEMOR/Control Plane and
 *     tests may change maturity WITHIN a level.
 *
 *  7. The classification oracle (doc "Exemplos"): documented scenario -> level
 *     mapping, carried verbatim so chat / routed / learning / swarm scenarios
 *     classify to the same level the doc states.
 *
 * Stateless and DB-free: every method is a pure function of its arguments.
 * NEVER calls a provider. NEVER promotes a level without its evidence.
 *
 * @see docs/engineering-knowledge-base/atlas-ai-evolutionary-maturity-model.md
 */
final class AtlasAiEvolutionaryMaturityModelService
{
    public const SCHEMA_VERSION = 'atlas.aaeos.ai_evolutionary_maturity_model.v1';

    public const MIN_LEVEL = 0;

    public const MAX_LEVEL = 10;

    /**
     * The next macro patamar after the current classification (doc: "O proximo
     * grande patamar de produto e Atlas Swarm Company Runtime").
     */
    public const NEXT_MACRO_LEVEL = 7;

    /**
     * Conservative current classification (doc "Evidencias": "Atlas atual: Nivel
     * 4.5 a 5, com inicio forte de Nivel 6"). Floor/ceiling are the committed
     * (fully-promoted) band; `emerging` is the level with strong early signal
     * but not yet promoted.
     */
    public const CURRENT_FLOOR = 4.5;

    public const CURRENT_CEILING = 5;

    public const CURRENT_EMERGING_LEVEL = 6;

    /**
     * Doc "Fluxo" — the eleven ordered, named canonical levels (Nivel 0..10).
     *
     * @var array<int,string>
     */
    public const LEVELS = [
        0 => 'LLM Wrapper',
        1 => 'Atlas Copilot',
        2 => 'Atlas Router OS',
        3 => 'Atlas Specialist Runtime',
        4 => 'Atlas Persistent Intelligence OS',
        5 => 'Atlas Outcome-Learning OS',
        6 => 'Atlas Intelligence Factory OS',
        7 => 'Atlas Swarm Company Runtime',
        8 => 'Atlas Autonomous Company OS',
        9 => 'Atlas World Action Engine',
        10 => 'Atlas Civilization Intelligence Engine',
    ];

    /**
     * Doc "Dependencias" table — what each level depends on. Levels 0 and 1 have
     * no upstream runtime dependency in the table (they are the pre-OS rungs).
     *
     * @var array<int,string>
     */
    public const DEPENDENCIES = [
        0 => '',
        1 => '',
        2 => 'Hyperflow, Router Runtime, receipts',
        3 => 'Specialist flows, Dev/Forge, domain runtimes',
        4 => 'APCR, ACIE, ACOL, TEOS, memory',
        5 => 'AEMOR, evidence, outcome tracking',
        6 => 'ASEIF, simulation, capability registry',
        7 => 'agent scheduler, handoff, meta-agents',
        8 => 'mission/company runtime, milestones, work packets',
        9 => 'governed external execution, policy, approvals',
        10 => 'all previous levels plus ecosystem governance',
    ];

    /**
     * Doc "Evidencia minima para promover nivel" + "Definition of Done" — the
     * eight proofs every promotion requires. Order is the documented order.
     *
     * @var array<int,array{key:string,proof:string}>
     */
    public const PROMOTION_PROOFS = [
        ['key' => 'canonical_doc', 'proof' => 'doc canonica'],
        ['key' => 'runtime_in_standard_flow', 'proof' => 'runtime conectado ao fluxo padrao'],
        ['key' => 'persistence_or_versioned_contract', 'proof' => 'persistencia ou contrato versionado quando houver estado'],
        ['key' => 'cli_api_control_plane', 'proof' => 'CLI/API/control plane'],
        ['key' => 'focused_tests_and_regression', 'proof' => 'testes focados e regressao impactada'],
        ['key' => 'readiness_certification', 'proof' => 'readiness/certification'],
        ['key' => 'verifiable_evidence_refs', 'proof' => 'evidence refs verificaveis'],
        ['key' => 'real_use_in_a_main_flow', 'proof' => 'uso real em pelo menos um flow principal'],
    ];

    /**
     * Doc "Exemplos" — the classification oracle. Scenario key -> documented
     * level (we pin the lower bound of any documented range as the conservative
     * floor, matching the doc's "baseline conservador" rule).
     *
     * @var array<string,array{label:string,level:int}>
     */
    public const CLASSIFICATION_EXAMPLES = [
        'simple_chat_answer' => ['label' => 'Pergunta simples respondida por chat', 'level' => 1],
        'ambiguous_routed_to_research' => ['label' => 'Prompt ambiguo roteado para Research', 'level' => 2],
        'code_with_automatic_context' => ['label' => 'Pedido de codigo com contexto automatico', 'level' => 3],
        'patch_learns_from_failure' => ['label' => 'Patch que aprende com falha anterior', 'level' => 5],
        'atlas_creates_workflow' => ['label' => 'Atlas cria workflow para ingestao', 'level' => 6],
        'five_agents_with_handoff' => ['label' => 'Cinco agentes trabalham em obra longa com handoff', 'level' => 7],
        'atlas_runs_business_for_months' => ['label' => 'Atlas conduz projeto de negocio por meses', 'level' => 8],
        'atlas_operates_real_apis' => ['label' => 'Atlas opera APIs, vendas e automacoes reais', 'level' => 9],
        'atlas_coordinates_ecosystem' => ['label' => 'Atlas coordena ecossistema empresarial completo', 'level' => 10],
    ];

    /**
     * Claims the doc forbids unconditionally (doc "Contrato de claim" +
     * "Regras para IA" 1 + frontmatter forbidden_changes).
     *
     * @var array<int,string>
     */
    public const FORBIDDEN_CLAIMS = ['agi', 'asi'];

    /**
     * One descriptor row for a level: number, canonical name, dependencies and
     * whether it is the current committed band, emerging, or the next macro
     * patamar.
     *
     * @return array{
     *     level:int,
     *     name:string,
     *     depends_on:string,
     *     is_current_band:bool,
     *     is_emerging:bool,
     *     is_next_macro:bool
     * }
     */
    public function describeLevel(int $level): array
    {
        if (! array_key_exists($level, self::LEVELS)) {
            return [
                'level' => $level,
                'name' => 'unknown',
                'depends_on' => '',
                'is_current_band' => false,
                'is_emerging' => false,
                'is_next_macro' => false,
            ];
        }

        return [
            'level' => $level,
            'name' => self::LEVELS[$level],
            'depends_on' => self::DEPENDENCIES[$level],
            'is_current_band' => $level >= (int) floor(self::CURRENT_FLOOR) && $level <= self::CURRENT_CEILING,
            'is_emerging' => $level === self::CURRENT_EMERGING_LEVEL,
            'is_next_macro' => $level === self::NEXT_MACRO_LEVEL,
        ];
    }

    /**
     * The full ordered lineage as a read model.
     *
     * @return array{
     *     count:int,
     *     min:int,
     *     max:int,
     *     levels:array<int,array{level:int,name:string,depends_on:string,is_current_band:bool,is_emerging:bool,is_next_macro:bool}>
     * }
     */
    public function lineage(): array
    {
        $levels = [];
        foreach (array_keys(self::LEVELS) as $level) {
            $levels[] = $this->describeLevel($level);
        }

        return [
            'count' => count(self::LEVELS),
            'min' => self::MIN_LEVEL,
            'max' => self::MAX_LEVEL,
            'levels' => $levels,
        ];
    }

    /**
     * The conservative current classification (doc "Classificacao oficial
     * atual" / "Evidencias"). This is a fixed baseline, NOT promotable by this
     * method — promotion requires `gatePromotion`.
     *
     * @return array{
     *     floor:float,
     *     ceiling:int,
     *     emerging_level:int,
     *     statement:string,
     *     is_agi:bool,
     *     is_asi:bool,
     *     is_autonomous_company:bool,
     *     next_macro_level:int,
     *     next_macro_name:string
     * }
     */
    public function currentClassification(): array
    {
        return [
            'floor' => self::CURRENT_FLOOR,
            'ceiling' => self::CURRENT_CEILING,
            'emerging_level' => self::CURRENT_EMERGING_LEVEL,
            'statement' => 'Atlas e um AI Operating System em transicao de Nivel 4/5 para Nivel 6.',
            'is_agi' => false,
            'is_asi' => false,
            'is_autonomous_company' => false,
            'next_macro_level' => self::NEXT_MACRO_LEVEL,
            'next_macro_name' => self::LEVELS[self::NEXT_MACRO_LEVEL],
        ];
    }

    /**
     * Promotion gate (doc "Definition of Done" + "Evidencia minima para promover
     * nivel"). A level is promotable ONLY when all eight documented proofs are
     * present as explicit truthy booleans. A missing or falsey proof blocks
     * promotion and is named. A doc/scaffold alone never promotes.
     *
     * @param int $targetLevel the level being claimed
     * @param array<string,bool> $proofs key => present, keys from PROMOTION_PROOFS
     *
     * @return array{
     *     target_level:int,
     *     target_name:string,
     *     promote:bool,
     *     satisfied:array<int,string>,
     *     missing:array<int,string>,
     *     reason:string
     * }
     */
    public function gatePromotion(int $targetLevel, array $proofs): array
    {
        $name = self::LEVELS[$targetLevel] ?? 'unknown';

        if (! array_key_exists($targetLevel, self::LEVELS)) {
            return [
                'target_level' => $targetLevel,
                'target_name' => $name,
                'promote' => false,
                'satisfied' => [],
                'missing' => array_column(self::PROMOTION_PROOFS, 'key'),
                'reason' => 'unknown_level',
            ];
        }

        $satisfied = [];
        $missing = [];
        foreach (self::PROMOTION_PROOFS as $proof) {
            $key = $proof['key'];
            // Strict: only an explicit boolean true counts. Absent or falsey
            // proof never inflates the level ("nao promover por doc ou scaffold").
            if (($proofs[$key] ?? false) === true) {
                $satisfied[] = $key;
            } else {
                $missing[] = $key;
            }
        }

        $promote = $missing === [];

        return [
            'target_level' => $targetLevel,
            'target_name' => $name,
            'promote' => $promote,
            'satisfied' => $satisfied,
            'missing' => $missing,
            'reason' => $promote ? 'all_proofs_present' : 'missing_promotion_proofs',
        ];
    }

    /**
     * Claim contract (doc "Contrato de claim" + "Regras para IA" 1). Rejects any
     * claim that Atlas is AGI or ASI; allows the OS-orchestrator framing.
     *
     * @return array{
     *     claim:string,
     *     allowed:bool,
     *     reason:string,
     *     canonical_statement:string
     * }
     */
    public function evaluateClaim(string $claim): array
    {
        $normalized = strtolower(trim($claim));
        $forbidden = in_array($normalized, self::FORBIDDEN_CLAIMS, true);

        return [
            'claim' => $normalized,
            'allowed' => ! $forbidden,
            'reason' => $forbidden ? 'forbidden_claim_atlas_is_not_'.$normalized : 'allowed',
            'canonical_statement' => 'Atlas nao e AGI. Atlas nao e ASI. Atlas e o sistema operacional que orquestra motores cognitivos cada vez mais fortes.',
        ];
    }

    /**
     * Level-movement rule (doc "Fluxo" + "Regras para IA" 5/6). A level only
     * moves when the operational unit changes. An isolated feature / new button
     * / new flow WITHOUT evidence wiring does not promote — it stays inside the
     * current level. Wiring an existing flow to Hyperflow/APCR/AEMOR/Control
     * Plane + tests may change maturity within a level. A genuinely new
     * operational unit (e.g. coordinated subagents with handoff/ownership) may
     * point to a higher level.
     *
     * @param array{
     *     changes_operational_unit?:bool,
     *     wired_to_standard_flow?:bool,
     *     has_evidence?:bool
     * } $change
     *
     * @return array{
     *     promotes_level:bool,
     *     changes_within_level:bool,
     *     verdict:string,
     *     reason:string
     * }
     */
    public function classifyChange(array $change): array
    {
        $changesUnit = ($change['changes_operational_unit'] ?? false) === true;
        $wired = ($change['wired_to_standard_flow'] ?? false) === true;
        $hasEvidence = ($change['has_evidence'] ?? false) === true;

        // A change of operational unit only counts as a level move when it is
        // also wired and evidenced (the doc forbids promotion without evidence).
        if ($changesUnit && $wired && $hasEvidence) {
            return [
                'promotes_level' => true,
                'changes_within_level' => false,
                'verdict' => 'level_move',
                'reason' => 'operational_unit_changed_wired_and_evidenced',
            ];
        }

        // Wiring/evidence on an existing unit deepens maturity within the level.
        if ($wired && $hasEvidence) {
            return [
                'promotes_level' => false,
                'changes_within_level' => true,
                'verdict' => 'within_level',
                'reason' => 'wired_to_standard_flow_with_evidence',
            ];
        }

        // Isolated feature: no wiring/evidence -> no movement at all.
        return [
            'promotes_level' => false,
            'changes_within_level' => false,
            'verdict' => 'no_movement',
            'reason' => 'isolated_change_without_wiring_or_evidence',
        ];
    }

    /**
     * Classification oracle (doc "Exemplos"). Maps a documented scenario key to
     * its canonical level. Unknown scenarios return level -1.
     *
     * @return array{scenario:string,known:bool,level:int,level_name:string,label:string}
     */
    public function classifyScenario(string $scenario): array
    {
        $row = self::CLASSIFICATION_EXAMPLES[$scenario] ?? null;

        if ($row === null) {
            return [
                'scenario' => $scenario,
                'known' => false,
                'level' => -1,
                'level_name' => 'unknown',
                'label' => '',
            ];
        }

        return [
            'scenario' => $scenario,
            'known' => true,
            'level' => $row['level'],
            'level_name' => self::LEVELS[$row['level']],
            'label' => $row['label'],
        ];
    }

    /**
     * One full read-model snapshot for operators / the command.
     *
     * @return array{
     *     schema_version:string,
     *     current:array{floor:float,ceiling:int,emerging_level:int,statement:string,is_agi:bool,is_asi:bool,is_autonomous_company:bool,next_macro_level:int,next_macro_name:string},
     *     lineage:array{count:int,min:int,max:int,levels:array<int,array<string,mixed>>},
     *     promotion_proofs:array<int,array{key:string,proof:string}>
     * }
     */
    public function snapshot(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'current' => $this->currentClassification(),
            'lineage' => $this->lineage(),
            'promotion_proofs' => self::PROMOTION_PROOFS,
        ];
    }
}
