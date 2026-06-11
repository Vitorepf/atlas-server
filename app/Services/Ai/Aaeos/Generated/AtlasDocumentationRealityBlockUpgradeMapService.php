<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Support\AtlasAaeosValueNormalizer;

/**
 * Atlas Documentation Reality Block Upgrade Map (ADR-BUM) decider.
 *
 * Pure, deterministic classifier that turns the upgrade-map doc into a contract.
 * The doc's hard claim is that a documentation/reality block "so vira
 * implementacao quando tiver saida verificavel, owner, evidencia, gate e risco
 * declarado" and that a block must never be called ready "sem output schema e
 * prova". This service enforces exactly that, and never the block's good name.
 *
 * Three documented mechanisms are modelled and never lie:
 *
 *  1. The Readiness levels ladder (doc "Contratos" -> "Readiness levels"): six
 *     ordered, named levels L0..L5 — L0_named, L1_specified, L2_testable,
 *     L3_read_only, L4_integrated, L5_self_improving. The doc frames each level
 *     as a strict superset of the one below (a block is testable only once it is
 *     specified, integrated only once it is read-only, etc.), so the classifier
 *     returns the highest *contiguous* level reached: the first unmet gate caps
 *     the level and names the exact blocking promotion. A higher signal present
 *     while a lower one is missing does NOT skip the gap.
 *
 *  2. The Block Readiness Gate (doc "F - Governanca" + "Riscos"/"Mitigacao"):
 *     "Block Readiness Gate | impede runtime antes de L2/L3" and "Nenhum bloco
 *     entra em runtime sem readiness level e gate". A block may only enter
 *     runtime once it has reached at least L2_testable; a read-only service
 *     (L3) requires the same floor. Below L2 the gate refuses and names the
 *     missing step. ACRUI read-only is required before any mutating change.
 *
 *  3. The Promotion evidence rule (doc "Evidencias"): "Um bloco so pode subir de
 *     nivel com: schema ou contrato; input sources declaradas; output
 *     verificavel; teste ou comando; evidence refs; owner e failure mode;
 *     impacto em IA, humano ou governanca." A promotion is authorized only when
 *     every one of these proofs is present; the check lists what is missing.
 *
 * The ten mandatory block-contract fields (doc "Contratos") are the input
 * surface: owner_plane, primary_runtime_or_doc, input_sources, output_schema,
 * evidence_refs, quality_gate, failure_mode, human_surface, ai_context_impact,
 * readiness_level. Each level gate is expressed purely in terms of which of
 * these fields (plus an integration signal and a telemetry signal) are present.
 *
 * Evidence gate (doc frontmatter `forbidden_changes`: "Autorizar codigo
 * mutativo a partir deste mapa."): this map is read-only. The decider classifies
 * and gates; it NEVER authorizes a mutation, NEVER calls a provider, NEVER
 * touches a database. A field only counts when it is explicitly, non-emptily
 * present — silence never inflates the ladder.
 *
 * @see docs/engineering-knowledge-base/atlas-documentation-reality-block-upgrade-map.md
 */
class AtlasDocumentationRealityBlockUpgradeMapService
{
    public const SCHEMA_VERSION = 'atlas.aaeos.documentation_reality_block_upgrade_map.v1';

    public const MAX_LEVEL = 5;

    /** Doc "F - Governanca": Block Readiness Gate floor for runtime entry. */
    public const RUNTIME_ENTRY_FLOOR = 2;

    /**
     * Doc "Readiness levels" — the six ordered, named readiness levels.
     *
     * @var array<int,string>
     */
    public const LEVELS = [
        0 => 'L0_named',
        1 => 'L1_specified',
        2 => 'L2_testable',
        3 => 'L3_read_only',
        4 => 'L4_integrated',
        5 => 'L5_self_improving',
    ];

    /**
     * Doc "Readiness levels" — the one-line meaning of each level.
     *
     * @var array<int,string>
     */
    public const LEVEL_MEANINGS = [
        0 => 'so conceito',
        1 => 'contrato e saida definidos',
        2 => 'gate ou teste definido',
        3 => 'service/comando read-only',
        4 => 'usado por ACRUI, AURC ou context pack',
        5 => 'telemetria real melhora o bloco',
    ];

    /**
     * Doc "Contratos" — the ten mandatory block-contract fields every block
     * must declare.
     *
     * @var array<int,string>
     */
    public const CONTRACT_FIELDS = [
        'owner_plane',
        'primary_runtime_or_doc',
        'input_sources',
        'output_schema',
        'evidence_refs',
        'quality_gate',
        'failure_mode',
        'human_surface',
        'ai_context_impact',
        'readiness_level',
    ];

    /**
     * Promotion gates: the signal required to ENTER each level, derived strictly
     * from the doc. Index N holds the requirement for the L(N-1) -> LN step.
     * L0 ("named") is the floor and needs only a name.
     *
     *  - L1_specified: contract + output defined  -> owner_plane,
     *    primary_runtime_or_doc, input_sources, output_schema present.
     *  - L2_testable: gate or test defined        -> quality_gate present.
     *  - L3_read_only: service/command read-only   -> primary_runtime_or_doc
     *    names a runtime AND evidence_refs present (doc "Evidencias":
     *    output verificavel + evidence refs).
     *  - L4_integrated: used by ACRUI/AURC/pack    -> integration signal present.
     *  - L5_self_improving: telemetry improves it  -> telemetry signal present.
     *
     * @var array<int,array{signal:string,required:string}>
     */
    public const PROMOTIONS = [
        1 => ['signal' => 'contract_and_output_defined', 'required' => 'owner_plane, primary_runtime_or_doc, input_sources and output_schema declarados.'],
        2 => ['signal' => 'quality_gate_or_test_defined', 'required' => 'quality_gate (gate ou teste) declarado.'],
        3 => ['signal' => 'read_only_runtime_with_evidence', 'required' => 'primary_runtime_or_doc aponta runtime read-only e evidence_refs declarados.'],
        4 => ['signal' => 'integrated_into_acrui_aurc_or_context_pack', 'required' => 'integracao real em ACRUI, AURC ou context pack.'],
        5 => ['signal' => 'real_telemetry_improves_block', 'required' => 'telemetria real de uso alimenta melhoria do bloco.'],
    ];

    /**
     * Doc "Evidencias" — every proof required for any level promotion.
     *
     * @var array<int,string>
     */
    public const PROMOTION_EVIDENCE = [
        'schema_or_contract',
        'input_sources_declared',
        'verifiable_output',
        'test_or_command',
        'evidence_refs',
        'owner_and_failure_mode',
        'ai_human_or_governance_impact',
    ];

    /**
     * Classify a block against the readiness ladder using its declared contract.
     *
     * Returns the highest contiguous level reached (the chain of gates from L0
     * up), the exact blocking promotion (first unmet gate), the per-level
     * checklist, and which of the ten mandatory contract fields are still
     * missing. A block is NEVER "ready" implicitly; the ladder only ever grants
     * a level, and a level above L1 carries no meaning without the lower gates.
     *
     * @param  array{
     *     block?:string,
     *     owner_plane?:mixed,
     *     primary_runtime_or_doc?:mixed,
     *     input_sources?:mixed,
     *     output_schema?:mixed,
     *     evidence_refs?:mixed,
     *     quality_gate?:mixed,
     *     failure_mode?:mixed,
     *     human_surface?:mixed,
     *     ai_context_impact?:mixed,
     *     primary_runtime_is_read_only?:mixed,
     *     integrated_into?:mixed,
     *     real_usage_telemetry?:mixed
     * }  $contract
     * @return array<string,mixed>
     */
    public function classifyBlock(array $contract): array
    {
        $block = AtlasAaeosValueNormalizer::stringOrNull($contract['block'] ?? null) ?? 'unnamed_block';

        $declared = [];
        $missingFields = [];
        foreach (self::CONTRACT_FIELDS as $field) {
            // readiness_level is the OUTPUT of this decider, never an input proof.
            if ($field === 'readiness_level') {
                continue;
            }
            $present = $this->present($contract[$field] ?? null);
            $declared[$field] = $present;
            if (! $present) {
                $missingFields[] = $field;
            }
        }

        // Derived gate signals (doc gates expressed over the declared fields).
        $signals = [
            'contract_and_output_defined' => $declared['owner_plane']
                && $declared['primary_runtime_or_doc']
                && $declared['input_sources']
                && $declared['output_schema'],
            'quality_gate_or_test_defined' => $declared['quality_gate'],
            'read_only_runtime_with_evidence' => $declared['primary_runtime_or_doc']
                && ($contract['primary_runtime_is_read_only'] ?? null) === true
                && $declared['evidence_refs'],
            'integrated_into_acrui_aurc_or_context_pack' => $this->present($contract['integrated_into'] ?? null),
            'real_telemetry_improves_block' => ($contract['real_usage_telemetry'] ?? null) === true,
        ];

        $checklist = [];
        $level = 0; // L0_named floor: a classified block is at least named.
        $contiguousBroken = false;
        $blockingPromotion = null;

        foreach (self::PROMOTIONS as $target => $spec) {
            $present = $signals[$spec['signal']] === true;
            $reached = $present && ! $contiguousBroken;

            if (! $present && ! $contiguousBroken) {
                $contiguousBroken = true;
                $blockingPromotion = [
                    'from' => self::LEVELS[$target - 1],
                    'to' => self::LEVELS[$target],
                    'missing_signal' => $spec['signal'],
                    'required' => $spec['required'],
                ];
            }

            if ($reached) {
                $level = $target;
            }

            $checklist[] = [
                'level' => self::LEVELS[$target],
                'meaning' => self::LEVEL_MEANINGS[$target],
                'signal' => $spec['signal'],
                'required' => $spec['required'],
                'present' => $present,
                'counts' => $reached,
            ];
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'block' => $block,
            'level' => $level,
            'level_label' => self::LEVELS[$level],
            'level_meaning' => self::LEVEL_MEANINGS[$level],
            'is_max' => $level === self::MAX_LEVEL,
            // Doc rule: never call a block "pronto" without output schema and
            // proof. There is no implicit "complete" verdict below L5.
            'complete' => false,
            'next_level' => $level < self::MAX_LEVEL ? self::LEVELS[$level + 1] : null,
            'blocking_promotion' => $blockingPromotion,
            'missing_contract_fields' => $missingFields,
            'declared_contract_fields' => $declared,
            'checklist' => $checklist,
        ];
    }

    /**
     * Block Readiness Gate (doc "F - Governanca"): "impede runtime antes de
     * L2/L3" and "Nenhum bloco entra em runtime sem readiness level e gate".
     *
     * A block may enter runtime only once it has reached at least L2_testable.
     * Mutating runtime additionally requires ACRUI read-only first (doc
     * "Mitigacao": "ACRUI read-only antes de qualquer mudanca mutativa").
     *
     * @param  array<string,mixed>  $contract  same shape as classifyBlock()
     * @param  'read_only'|'mutating'  $intent  the kind of runtime entry requested
     * @return array<string,mixed>
     */
    public function runtimeEntryGate(array $contract, string $intent = 'read_only'): array
    {
        $classification = $this->classifyBlock($contract);
        $level = (int) $classification['level'];

        $blockers = [];
        if ($level < self::RUNTIME_ENTRY_FLOOR) {
            $blockers[] = 'below_l2_testable_floor';
        }

        $mutating = $intent === 'mutating';
        // Mutating entry demands ACRUI read-only proof first.
        $acruiReadOnlyDone = ($contract['acrui_read_only_done'] ?? null) === true;
        if ($mutating && ! $acruiReadOnlyDone) {
            $blockers[] = 'acrui_read_only_required_before_mutation';
        }

        $allowed = $blockers === [];

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'block' => $classification['block'],
            'intent' => $mutating ? 'mutating' : 'read_only',
            'level' => $level,
            'level_label' => $classification['level_label'],
            'runtime_entry_floor' => self::LEVELS[self::RUNTIME_ENTRY_FLOOR],
            'allowed' => $allowed,
            'decision' => $allowed
                ? ($mutating ? 'runtime_entry_allowed_mutating' : 'runtime_entry_allowed_read_only')
                : 'runtime_entry_blocked',
            'blockers' => $blockers,
            'blocking_promotion' => $classification['blocking_promotion'],
            'rule' => 'block_readiness_gate_min_l2_testable',
        ];
    }

    /**
     * Promotion evidence check (doc "Evidencias"): a block can only level up
     * when every required proof is present. Returns the verdict plus the exact
     * list of missing proofs.
     *
     * @param  array<string,bool>  $evidence  proof key => present
     * @return array<string,mixed>
     */
    public function promotionEvidenceCheck(array $evidence): array
    {
        $present = [];
        $missing = [];
        foreach (self::PROMOTION_EVIDENCE as $proof) {
            if (($evidence[$proof] ?? null) === true) {
                $present[] = $proof;
            } else {
                $missing[] = $proof;
            }
        }

        $authorized = $missing === [];

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'authorized' => $authorized,
            'decision' => $authorized ? 'promotion_authorized' : 'promotion_blocked',
            'required_proofs' => self::PROMOTION_EVIDENCE,
            'present_proofs' => $present,
            'missing_proofs' => $missing,
            'rule' => 'no_level_up_without_full_evidence_tuple',
        ];
    }

    /**
     * A field counts only when explicitly, non-emptily present. Empty strings,
     * empty arrays, null and false never count — silence cannot inflate a level.
     */
    private function present(mixed $value): bool
    {
        if ($value === null || $value === false) {
            return false;
        }
        if (is_string($value)) {
            return trim($value) !== '';
        }
        if (is_array($value)) {
            return $value !== [];
        }

        return (bool) $value;
    }

}
