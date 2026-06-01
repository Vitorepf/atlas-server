<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Generated;

/**
 * Atlas AI Evolution Lineage And Target State decider.
 *
 * Pure, deterministic runtime that turns the canonical evolution-lineage doc
 * into an enforceable contract instead of prose. The doc is the macro compass:
 * it exists to block three errors (doc "Papel no Atlas"):
 *
 *   1. treating Atlas as an LLM wrapper;
 *   2. declaring maturity just because documentation or a scaffold exists;
 *   3. losing the evolutionary line by inventing new names instead of wiring
 *      capabilities into the real flow.
 *
 * This service does NOT re-implement the sibling maturity-model service. It
 * encodes the specific decision tables this doc owns, carried as literally as a
 * pure function allows:
 *
 *   A. Identity contract (doc "Contratos / Identidade"): Atlas is an AI
 *      Operating System; Atlas is NOT AGI and NOT ASI. It may orchestrate ever
 *      stronger engines, but Atlas itself is the governed operational layer.
 *
 *   B. The eleven-level canonical lineage (doc "Fluxo") PLUS the per-level
 *      operational status from doc "Estado atual conservador" (exists / partial
 *      / needs-runtime / north-star). The conservative current state is
 *      "Nivel 4/5 em consolidacao, com inicio operacional de Nivel 6".
 *
 *   C. The "Unidade de evolucao" table (doc): a level only moves when the
 *      OPERATIONAL UNIT changes. Eight named change types map to a hard Yes/No
 *      on "counts as a patamar". New button / new prompt / new doc / new flow
 *      without a caller => No. Runtime in the standard flow with evidence,
 *      memory that changes future decisions with receipts, coordinated
 *      subagents with handoff/leases, governed external execution => Yes.
 *
 *   D. The claim policy (doc "Claim policy"): six full claims are forbidden
 *      without an evidence pack (AGI, ASI, complete, replaces Claude/Codex
 *      everywhere, externally superior, acts in the world without approval);
 *      the internal-runtime claim is permitted WHEN tests and gates prove it.
 *
 *   E. The promotion evidence contract (doc "Evidencias"): twelve evidence
 *      items are the minimum, seven "signals that do not suffice" can never
 *      promote on their own, and the overriding rule is
 *      "Promocao so vale quando o fluxo padrao usa a capacidade".
 *
 *   F. The horizon map (doc "Proximas Acoes / Horizonte"): Agora, 2 meses,
 *      6 meses, 1 ano, 5 anos -> the documented macro target for each.
 *
 * Stateless and DB-free: every method is a pure function of its arguments.
 * NEVER calls a provider. NEVER promotes a level or allows a forbidden claim
 * without the documented evidence.
 *
 * @see docs/engineering-knowledge-base/atlas-ai-evolution-lineage-and-target-state.md
 */
final class AtlasAiEvolutionLineageAndTargetStateService
{
    public const SCHEMA_VERSION = 'atlas.aaeos.ai_evolution_lineage_and_target_state.v1';

    public const MIN_LEVEL = 0;

    public const MAX_LEVEL = 10;

    /**
     * Doc "Escopo de Implementacao / Estado atual conservador":
     * "Atlas atual = Nivel 4/5 em consolidacao, com inicio operacional de Nivel 6".
     * The committed band is 4..5; level 6 is the operationally-emerging level.
     */
    public const CURRENT_FLOOR = 4;

    public const CURRENT_CEILING = 5;

    public const CURRENT_EMERGING_LEVEL = 6;

    /**
     * Doc "Proximas Acoes": the next macro patamar to build after the current
     * 4/5(+6) band is Nivel 7 — Atlas Swarm Company Runtime.
     */
    public const NEXT_MACRO_LEVEL = 7;

    /** Operational status of a level in the doc's "Estado atual conservador". */
    public const STATUS_EXISTS = 'exists';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_NEEDS_RUNTIME = 'needs_runtime';

    public const STATUS_NORTH_STAR = 'north_star';

    /**
     * Doc "Fluxo" — the eleven ordered, named canonical levels (Nivel 0..10),
     * each annotated with the operational status the doc's "Estado atual
     * conservador" reading assigns it. Levels 0-1 are the pre-OS rungs (assistance);
     * the doc's reading starts the explicit status at Nivel 2.
     *
     * @var array<int,array{name:string,status:string,reading:string}>
     */
    public const LINEAGE = [
        0 => [
            'name' => 'LLM Wrapper',
            'status' => self::STATUS_EXISTS,
            'reading' => 'prompt para provider; nunca a arquitetura principal',
        ],
        1 => [
            'name' => 'Atlas Copilot',
            'status' => self::STATUS_EXISTS,
            'reading' => 'assistente util sem autonomia profunda',
        ],
        2 => [
            'name' => 'Atlas Router OS',
            'status' => self::STATUS_EXISTS,
            'reading' => 'existe via Hyperflow, Intent Kernel, Router e Decision Receipts',
        ],
        3 => [
            'name' => 'Atlas Specialist Runtime',
            'status' => self::STATUS_PARTIAL,
            'reading' => 'existe parcialmente via specialist flows, Atlas Dev e Atlas Forge',
        ],
        4 => [
            'name' => 'Atlas Persistent Intelligence OS',
            'status' => self::STATUS_PARTIAL,
            'reading' => 'existe parcialmente via APCR, ACIE, ACOL, TEOS, context packs, continuation e compaction',
        ],
        5 => [
            'name' => 'Atlas Outcome-Learning OS',
            'status' => self::STATUS_PARTIAL,
            'reading' => 'existe parcialmente via AEMOR, outcome memory e judgment guard',
        ],
        6 => [
            'name' => 'Atlas Intelligence Factory OS',
            'status' => self::STATUS_PARTIAL,
            'reading' => 'existe parcialmente via Atlas Intelligence Factory OS / ASEIF',
        ],
        7 => [
            'name' => 'Atlas Swarm Company Runtime',
            'status' => self::STATUS_NEEDS_RUNTIME,
            'reading' => 'ainda precisa virar runtime padrao de subagentes/metagentes',
        ],
        8 => [
            'name' => 'Atlas Autonomous Company OS',
            'status' => self::STATUS_NEEDS_RUNTIME,
            'reading' => 'ainda precisa Company OS multi-dominio completo',
        ],
        9 => [
            'name' => 'Atlas World Action Engine',
            'status' => self::STATUS_NEEDS_RUNTIME,
            'reading' => 'ainda precisa external execution governado em escala',
        ],
        10 => [
            'name' => 'Atlas Civilization Intelligence Engine',
            'status' => self::STATUS_NORTH_STAR,
            'reading' => 'north-star, nao meta de sprint',
        ],
    ];

    /**
     * Doc "Unidade de evolucao" table — change TYPE -> whether it counts as a
     * patamar promotion. This is the doc's signature decision contract: an
     * operational change only counts when the operational UNIT changes. Carried
     * verbatim from the documented table.
     *
     * @var array<string,array{label:string,counts_as_patamar:bool}>
     */
    public const EVOLUTION_UNIT = [
        'new_button' => ['label' => 'Novo botao', 'counts_as_patamar' => false],
        'new_prompt' => ['label' => 'Novo prompt', 'counts_as_patamar' => false],
        'new_doc' => ['label' => 'Nova doc', 'counts_as_patamar' => false],
        'new_flow_without_caller' => ['label' => 'Novo flow sem caller', 'counts_as_patamar' => false],
        'runtime_in_standard_flow_with_evidence' => ['label' => 'Runtime usado no fluxo padrao com evidence', 'counts_as_patamar' => true],
        'memory_changes_future_decisions_with_receipts' => ['label' => 'Memoria que altera decisoes futuras com receipts', 'counts_as_patamar' => true],
        'coordinated_subagents_handoff_leases' => ['label' => 'Subagentes coordenados com handoff e leases', 'counts_as_patamar' => true],
        'external_execution_mandate_approval_rollback' => ['label' => 'Execucao externa com mandato, approval e rollback', 'counts_as_patamar' => true],
    ];

    /**
     * Doc "Claim policy" — claims forbidden without an evidence pack, carried as
     * the documented sentences (key -> canonical statement).
     *
     * @var array<string,string>
     */
    public const FORBIDDEN_CLAIMS = [
        'agi' => 'Atlas e AGI.',
        'asi' => 'Atlas e ASI.',
        'complete' => 'Atlas esta completo.',
        'replaces_all_scenarios' => 'Atlas substitui Claude/Codex em todos os cenarios.',
        'externally_superior' => 'Atlas e superior por avaliacao externa.',
        'acts_without_approval' => 'Atlas pode executar no mundo sem aprovacao.',
    ];

    /**
     * Doc "Claim policy" — the single claim permitted with local evidence, i.e.
     * an internal runtime claim, but ONLY when tests and gates prove it.
     */
    public const PERMITTED_INTERNAL_CLAIM_KEY = 'internal_runtime';

    public const PERMITTED_INTERNAL_CLAIM_STATEMENT =
        'Atlas possui runtime interno para roteamento, contexto, memoria, outcomes, capabilities, company workflow ou control plane, quando tests e gates provam.';

    /**
     * Doc "Evidencias / Evidencia minima para promover qualquer patamar" — the
     * twelve required evidence items, documented order preserved.
     *
     * @var array<int,string>
     */
    public const EVIDENCE_MINIMUM = [
        'canonical_doc_active',
        'runtime_in_standard_flow',
        'versioned_contract_or_schema_when_state',
        'persistence_or_read_model_when_durable_state',
        'cli_api_or_surface_consumer',
        'control_plane_shows_state',
        'receipts_hashes_evidence_refs',
        'focused_tests',
        'regression_of_impacted_paths',
        'docs_health_green',
        'readiness_certification',
        'declared_limitations',
    ];

    /**
     * Doc "Evidencias / Sinais que nao bastam" — signals that, on their own,
     * never promote a patamar.
     *
     * @var array<int,string>
     */
    public const INSUFFICIENT_SIGNALS = [
        'doc_exists',
        'class_exists',
        'migration_exists',
        'isolated_command_exists',
        'narrow_test_exists',
        'manual_demo_worked_once',
        'provider_answered_well',
    ];

    /**
     * Doc "Proximas Acoes / Horizonte" — horizon key -> documented macro target.
     *
     * @var array<string,string>
     */
    public const HORIZON = [
        'now' => 'Consolidar APCR, ACIE, ACOL, TEOS, AEMOR e ASEIF no fluxo padrao',
        '2_months' => 'Nivel 4-6 robusto, auditavel e visivel no Desktop Control Plane',
        '6_months' => 'Nivel 7 com Swarm Company Runtime operacional',
        '1_year' => 'Nivel 8 com Autonomous Company OS multi-dominio',
        '5_years' => 'Nivel 9/10 progressivo com World Action Engine e ecosystem governance',
    ];

    /**
     * Identity contract (doc "Contratos / Identidade"): Atlas is an AI Operating
     * System, never AGI, never ASI.
     *
     * @return array{
     *     is_ai_operating_system:bool,
     *     is_agi:bool,
     *     is_asi:bool,
     *     statement:string
     * }
     */
    public function identity(): array
    {
        return [
            'is_ai_operating_system' => true,
            'is_agi' => false,
            'is_asi' => false,
            'statement' => 'Atlas = AI Operating System. Atlas nao = AGI. Atlas nao = ASI. '
                .'Atlas pode orquestrar modelos cada vez mais fortes, mas o Atlas em si e a camada operacional governada.',
        ];
    }

    /**
     * The eleven-level lineage with documented operational status per level.
     *
     * @return array{
     *     count:int,
     *     min:int,
     *     max:int,
     *     current_floor:int,
     *     current_ceiling:int,
     *     emerging_level:int,
     *     next_macro_level:int,
     *     next_macro_name:string,
     *     levels:array<int,array{level:int,name:string,status:string,reading:string,is_current_band:bool,is_emerging:bool,is_next_macro:bool}>
     * }
     */
    public function lineage(): array
    {
        $levels = [];
        foreach (self::LINEAGE as $level => $row) {
            $levels[$level] = [
                'level' => $level,
                'name' => $row['name'],
                'status' => $row['status'],
                'reading' => $row['reading'],
                'is_current_band' => $level >= self::CURRENT_FLOOR && $level <= self::CURRENT_CEILING,
                'is_emerging' => $level === self::CURRENT_EMERGING_LEVEL,
                'is_next_macro' => $level === self::NEXT_MACRO_LEVEL,
            ];
        }

        return [
            'count' => count($levels),
            'min' => self::MIN_LEVEL,
            'max' => self::MAX_LEVEL,
            'current_floor' => self::CURRENT_FLOOR,
            'current_ceiling' => self::CURRENT_CEILING,
            'emerging_level' => self::CURRENT_EMERGING_LEVEL,
            'next_macro_level' => self::NEXT_MACRO_LEVEL,
            'next_macro_name' => self::LINEAGE[self::NEXT_MACRO_LEVEL]['name'],
            'levels' => $levels,
        ];
    }

    /**
     * Doc "Escopo de Implementacao / Estado atual conservador". Conservative
     * current state: Nivel 4/5 in consolidation with operational start of Nivel 6.
     *
     * @return array{
     *     floor:int,
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
    public function currentState(): array
    {
        return [
            'floor' => self::CURRENT_FLOOR,
            'ceiling' => self::CURRENT_CEILING,
            'emerging_level' => self::CURRENT_EMERGING_LEVEL,
            'statement' => 'Atlas atual = Nivel 4/5 em consolidacao, com inicio operacional de Nivel 6.',
            'is_agi' => false,
            'is_asi' => false,
            // Nivel 8 (Autonomous Company OS) "ainda precisa Company OS multi-dominio completo".
            'is_autonomous_company' => false,
            'next_macro_level' => self::NEXT_MACRO_LEVEL,
            'next_macro_name' => self::LINEAGE[self::NEXT_MACRO_LEVEL]['name'],
        ];
    }

    /**
     * Doc "Unidade de evolucao" table. Decide whether a named change TYPE counts
     * as a patamar promotion. Unknown change types are conservatively treated as
     * NOT counting (the doc's default posture: do not promote without proof).
     *
     * @return array{
     *     change_type:string,
     *     known:bool,
     *     label:string,
     *     counts_as_patamar:bool,
     *     reason:string
     * }
     */
    public function classifyEvolutionUnit(string $changeType): array
    {
        $key = strtolower(trim($changeType));
        $row = self::EVOLUTION_UNIT[$key] ?? null;

        if ($row === null) {
            return [
                'change_type' => $key,
                'known' => false,
                'label' => '',
                'counts_as_patamar' => false,
                'reason' => 'unknown_change_type_does_not_count',
            ];
        }

        return [
            'change_type' => $key,
            'known' => true,
            'label' => $row['label'],
            'counts_as_patamar' => $row['counts_as_patamar'],
            'reason' => $row['counts_as_patamar']
                ? 'operational_unit_changed'
                : 'cosmetic_or_unwired_change_does_not_count',
        ];
    }

    /**
     * Doc "Claim policy". Evaluate whether a claim is allowed. The six documented
     * forbidden claims are blocked unless an explicit evidence pack is supplied;
     * the internal-runtime claim is allowed only when tests AND gates prove it.
     * An unknown claim key is treated as allowed (it is not on the forbidden list)
     * but flagged as not-a-known-claim.
     *
     * @return array{
     *     claim_key:string,
     *     allowed:bool,
     *     requires_evidence_pack:bool,
     *     reason:string,
     *     canonical_statement:string
     * }
     */
    public function evaluateClaim(string $claimKey, bool $evidencePack = false): array
    {
        $key = strtolower(trim($claimKey));

        if ($key === self::PERMITTED_INTERNAL_CLAIM_KEY) {
            // Permitted "com evidencia local" — only when tests and gates prove it.
            return [
                'claim_key' => $key,
                'allowed' => $evidencePack === true,
                'requires_evidence_pack' => true,
                'reason' => $evidencePack === true
                    ? 'internal_runtime_claim_allowed_with_local_evidence'
                    : 'internal_runtime_claim_needs_tests_and_gates',
                'canonical_statement' => self::PERMITTED_INTERNAL_CLAIM_STATEMENT,
            ];
        }

        if (array_key_exists($key, self::FORBIDDEN_CLAIMS)) {
            return [
                'claim_key' => $key,
                // Forbidden "sem evidence pack" — an evidence pack is the only gate.
                'allowed' => $evidencePack === true,
                'requires_evidence_pack' => true,
                'reason' => $evidencePack === true
                    ? 'forbidden_claim_unlocked_by_evidence_pack'
                    : 'forbidden_claim_blocked_without_evidence_pack',
                'canonical_statement' => self::FORBIDDEN_CLAIMS[$key],
            ];
        }

        return [
            'claim_key' => $key,
            'allowed' => true,
            'requires_evidence_pack' => false,
            'reason' => 'claim_not_in_forbidden_policy',
            'canonical_statement' => '',
        ];
    }

    /**
     * Doc "Evidencias". Decide whether a promotion's evidence is sufficient. The
     * overriding documented rule is "Promocao so vale quando o fluxo padrao usa a
     * capacidade": the `runtime_in_standard_flow` item is mandatory and a
     * promotion that rests only on "signals that do not suffice" is rejected.
     * Every one of the twelve minimum evidence items must be present.
     *
     * @param  array<string,bool>  $provided  evidence item key -> present?
     *
     * @return array{
     *     sufficient:bool,
     *     satisfied:array<int,string>,
     *     missing:array<int,string>,
     *     standard_flow_uses_capability:bool,
     *     reason:string
     * }
     */
    public function isEvidenceSufficient(array $provided): array
    {
        $satisfied = [];
        $missing = [];
        foreach (self::EVIDENCE_MINIMUM as $item) {
            // Strict: only an explicit boolean true counts. Absent or falsey
            // evidence never inflates a promotion.
            if (($provided[$item] ?? false) === true) {
                $satisfied[] = $item;
            } else {
                $missing[] = $item;
            }
        }

        $standardFlowUses = in_array('runtime_in_standard_flow', $satisfied, true);
        $sufficient = $missing === [];

        if ($sufficient) {
            $reason = 'all_minimum_evidence_present_and_standard_flow_uses_capability';
        } elseif (! $standardFlowUses) {
            // The doc's strongest gate: without the standard flow using the
            // capability, no amount of other signals promotes.
            $reason = 'standard_flow_does_not_use_capability';
        } else {
            $reason = 'missing_minimum_evidence_items';
        }

        return [
            'sufficient' => $sufficient,
            'satisfied' => $satisfied,
            'missing' => $missing,
            'standard_flow_uses_capability' => $standardFlowUses,
            'reason' => $reason,
        ];
    }

    /**
     * Doc "Evidencias / Sinais que nao bastam". Returns true when a signal is one
     * of the seven that can never, by itself, promote a patamar.
     */
    public function isInsufficientSignal(string $signal): bool
    {
        return in_array(strtolower(trim($signal)), self::INSUFFICIENT_SIGNALS, true);
    }

    /**
     * Doc "Proximas Acoes / Horizonte". Resolve the documented macro target for a
     * horizon key. Unknown horizons return known=false.
     *
     * @return array{horizon:string,known:bool,target:string}
     */
    public function horizon(string $horizonKey): array
    {
        $key = strtolower(trim($horizonKey));
        $target = self::HORIZON[$key] ?? null;

        return [
            'horizon' => $key,
            'known' => $target !== null,
            'target' => $target ?? '',
        ];
    }

    /**
     * One full read-model snapshot for operators / the command.
     *
     * @return array{
     *     schema_version:string,
     *     identity:array<string,mixed>,
     *     current_state:array<string,mixed>,
     *     lineage:array<string,mixed>,
     *     evolution_unit:array<string,array{label:string,counts_as_patamar:bool}>,
     *     forbidden_claims:array<string,string>,
     *     evidence_minimum:array<int,string>,
     *     insufficient_signals:array<int,string>,
     *     horizon:array<string,string>
     * }
     */
    public function snapshot(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'identity' => $this->identity(),
            'current_state' => $this->currentState(),
            'lineage' => $this->lineage(),
            'evolution_unit' => self::EVOLUTION_UNIT,
            'forbidden_claims' => self::FORBIDDEN_CLAIMS,
            'evidence_minimum' => self::EVIDENCE_MINIMUM,
            'insufficient_signals' => self::INSUFFICIENT_SIGNALS,
            'horizon' => self::HORIZON,
        ];
    }
}
