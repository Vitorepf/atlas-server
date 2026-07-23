<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Quarantine;

/**
 * Atlas AI Cognitive Plane — Multiplier Edge runtime.
 *
 * Turns the Multiplier Edge doc (the 10 cardinal cognitive capabilities that
 * only the Atlas can have for structural reasons) into deterministic, pure
 * decision logic. The doc is mostly an executive registry plus a set of hard
 * operational invariants per capability; this service enforces those invariants
 * instead of restating the prose:
 *
 *  - Capability registry (doc "As 10 Capabilities Cardinais"): the ten rows with
 *    their documented phase, maturity and AP. capabilities() exposes the registry;
 *    capability() looks one up by id.
 *  - Roadmap golden rule (doc "Roadmap dominante" + "Regra de ouro"): Fase 1
 *    (Dreyfus Dynamic Pedagogy) ships before any other phase. gatePhaseStart()
 *    blocks every other capability while Dreyfus (Fase 1) is not delivered.
 *  - Dreyfus dynamic pedagogy (doc "Capability 1" table, 5 levels): each detected
 *    level maps to exactly one pedagogy. resolveDreyfusPedagogy() returns the
 *    documented pedagogy for a level and reports an unknown level rather than
 *    guessing.
 *  - Multi-Provider Discord Detector restrictions (doc "Capability 4", cardinal):
 *    NEVER default; allowed only in the four documented surfaces; PROHIBITED in
 *    active recall, flashcard, daily_plan, micro_session. gateDiscordDetector()
 *    enforces all three constraints, with default-mode always blocked.
 *  - Personal Worked Examples fading (doc "Capability 8" table): the example
 *    fades with operator stage (novato=full, competente=2-3 missing steps,
 *    proficiente=hidden reasoning, expert=problem only). resolveWorkedExampleFading().
 *  - Cross-Domain Evidence Routing scope (doc "Capability 6" + anti-pattern):
 *    routing only re-prioritises the EXISTING learning queue and may NEVER enrol
 *    in a new area. gateCrossDomainRouting() blocks new-area enrolment.
 *  - Predictive Failure Insertion target guard (doc "Capability 9": "alvo
 *    explicito obrigatorio, nunca `unknown`"): an insertion must carry an explicit
 *    target node; `unknown` (or empty) is rejected. gatePredictiveFailureInsertion().
 *
 * Stateless and DB-free: every method is a pure function of its arguments.
 *
 * @see docs/engineering-knowledge-base/cognitive/multiplier-edge.md
 */
final class AtlasCognitiveMultiplierEdgeService
{
    public const SCHEMA_VERSION = 'atlas.cognitive.multiplier_edge.v1';

    /**
     * The phase-1 capability id of the golden rule. Dreyfus must ship before any
     * other phase (doc "Regra de ouro": "Fase 1 sai antes de qualquer outra").
     */
    public const DREYFUS_CAPABILITY = 'dreyfus_dynamic_pedagogy';

    /**
     * The ten cardinal capabilities (doc "As 10 Capabilities Cardinais" table),
     * keyed by capability id. `phase` is the documented dominant-roadmap phase,
     * `maturity` the documented evidence/maturity column, `ap` the dedicated AP.
     *
     * @var array<string,array{id:string,number:int,name:string,phase:int,maturity:string,ap:string,opt_in:bool}>
     */
    private const CAPABILITIES = [
        'dreyfus_dynamic_pedagogy' => [
            'id' => 'dreyfus_dynamic_pedagogy', 'number' => 1, 'name' => 'Dreyfus Dynamic Pedagogy',
            'phase' => 1, 'maturity' => 'implemented_operational_read_model', 'ap' => 'AP-163', 'opt_in' => false,
        ],
        'evidence_driven_self_assessment' => [
            'id' => 'evidence_driven_self_assessment', 'number' => 2, 'name' => 'Evidence-driven Self-Assessment',
            'phase' => 2, 'maturity' => 'consensus', 'ap' => 'AP-COG-EDGE-02', 'opt_in' => false,
        ],
        'cross_domain_latticework' => [
            'id' => 'cross_domain_latticework', 'number' => 3, 'name' => 'Cross-Domain Latticework',
            'phase' => 3, 'maturity' => 'emerging', 'ap' => 'AP-COG-EDGE-03', 'opt_in' => false,
        ],
        'multi_provider_discord_detector' => [
            'id' => 'multi_provider_discord_detector', 'number' => 4, 'name' => 'Multi-Provider Discord Detector',
            'phase' => 4, 'maturity' => 'emerging', 'ap' => 'AP-COG-EDGE-04', 'opt_in' => true,
        ],
        'atlas_vitor_socratic_tutor' => [
            'id' => 'atlas_vitor_socratic_tutor', 'number' => 5, 'name' => 'Atlas-Vitor Socratic Tutor',
            'phase' => 5, 'maturity' => 'speculative', 'ap' => 'AP-COG-EDGE-05', 'opt_in' => false,
        ],
        'cross_domain_evidence_routing' => [
            'id' => 'cross_domain_evidence_routing', 'number' => 6, 'name' => 'Cross-Domain Evidence Routing',
            'phase' => 2, 'maturity' => 'emerging', 'ap' => 'AP-COG-EDGE-06', 'opt_in' => false,
        ],
        'temporal_compression_validation' => [
            'id' => 'temporal_compression_validation', 'number' => 7, 'name' => 'Temporal Compression Validation',
            'phase' => 6, 'maturity' => 'emerging', 'ap' => 'AP-COG-EDGE-07', 'opt_in' => false,
        ],
        'personal_worked_examples_generator' => [
            'id' => 'personal_worked_examples_generator', 'number' => 8, 'name' => 'Personal Worked Examples Generator',
            'phase' => 2, 'maturity' => 'implemented_partial', 'ap' => 'AP-169', 'opt_in' => false,
        ],
        'predictive_failure_insertion' => [
            'id' => 'predictive_failure_insertion', 'number' => 9, 'name' => 'Predictive Failure Insertion',
            'phase' => 4, 'maturity' => 'implemented_partial', 'ap' => 'AP-170', 'opt_in' => false,
        ],
        'process_pattern_personal_detector' => [
            'id' => 'process_pattern_personal_detector', 'number' => 10, 'name' => 'Process Pattern Personal Detector',
            'phase' => 3, 'maturity' => 'emerging', 'ap' => 'AP-COG-EDGE-10', 'opt_in' => false,
        ],
    ];

    /**
     * Dreyfus pedagogy table (doc "Capability 1", 5 levels novato -> master). Each
     * detected level maps to exactly one documented pedagogy. Order is the
     * documented progression; index is not load-bearing.
     *
     * @var array<string,string>
     */
    private const DREYFUS_PEDAGOGY = [
        'novato' => 'regras_explicitas_scaffolding_pesado_feedback_em_cada_gesto',
        'competente' => 'casos_com_variacao_controlada_articulacao_de_decisao',
        'proficiente' => 'casos_completos_estrategia_transferencia_adjacente',
        'expert' => 'desafios_mal_estruturados_defesa_adversarial_criacao',
        'master' => 'dialogo_de_pares_criacao_de_doutrina_formacao_de_outros',
    ];

    /**
     * Worked-example fading table (doc "Capability 8"). The output fades as the
     * operator's stage rises: lower stage -> more is shown.
     *
     * @var array<string,string>
     */
    private const WORKED_EXAMPLE_FADING = [
        'novato' => 'exemplo_completo_solucao_mais_raciocinio_passo_a_passo',
        'competente' => 'exemplo_com_2_3_etapas_faltando_para_preencher',
        'proficiente' => 'so_problema_e_solucao_final_raciocinio_escondido',
        'expert' => 'so_problema_voce_reconstroi',
    ];

    /**
     * Surfaces where the Multi-Provider Discord Detector MAY run (doc
     * "Capability 4": Pareto Discovery validation, controversial mastery_review,
     * critical first_principles_decompose, explicit debate).
     *
     * @var list<string>
     */
    public const DISCORD_ALLOWED_SURFACES = [
        'pareto_discovery_validation',
        'mastery_review',
        'first_principles_decompose',
        'debate',
    ];

    /**
     * Surfaces where the Multi-Provider Discord Detector is PROHIBITED (doc
     * "Capability 4": "Proibido em: active recall, flashcard, daily_plan,
     * micro_session").
     *
     * @var list<string>
     */
    public const DISCORD_FORBIDDEN_SURFACES = [
        'active_recall',
        'flashcard',
        'daily_plan',
        'micro_session',
    ];

    /**
     * Sentinel rejected by the Predictive Failure Insertion target guard (doc
     * "Capability 9": "alvo explicito obrigatorio, nunca `unknown`").
     */
    public const PREDICTIVE_FAILURE_FORBIDDEN_TARGET = 'unknown';

    /**
     * The full cardinal-capability registry as a list (doc table order).
     *
     * @return array{schema_version:string,total:int,capabilities:list<array{id:string,number:int,name:string,phase:int,maturity:string,ap:string,opt_in:bool}>}
     */
    public function capabilities(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'total' => count(self::CAPABILITIES),
            'capabilities' => array_values(self::CAPABILITIES),
        ];
    }

    /**
     * Look one capability up by id. An unknown id is reported as not found rather
     * than guessed.
     *
     * @return array{schema_version:string,id:string,found:bool,capability:?array<string,mixed>}
     */
    public function capability(string $id): array
    {
        $key = strtolower(trim($id));
        $found = self::CAPABILITIES[$key] ?? null;

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'id' => $key,
            'found' => $found !== null,
            'capability' => $found,
        ];
    }

    /**
     * Golden rule (doc "Regra de ouro": "Fase 1 sai antes de qualquer outra. Sem
     * Dreyfus, todas as outras capabilities operam sobre suposicoes erradas de
     * nivel"). Starting Dreyfus (Fase 1) is always allowed; starting any other
     * cardinal capability while Dreyfus is not delivered is blocked.
     *
     * @return array{
     *   schema_version:string,
     *   capability:string,
     *   is_dreyfus:bool,
     *   dreyfus_done:bool,
     *   allowed:bool,
     *   reason:string
     * }
     */
    public function gatePhaseStart(string $capabilityId, bool $dreyfusDone): array
    {
        $cap = strtolower(trim($capabilityId));
        $isDreyfus = $cap === self::DREYFUS_CAPABILITY;

        if ($isDreyfus) {
            return [
                'schema_version' => self::SCHEMA_VERSION,
                'capability' => $cap,
                'is_dreyfus' => true,
                'dreyfus_done' => $dreyfusDone,
                'allowed' => true,
                'reason' => 'dreyfus_is_phase_1_always_first',
            ];
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'capability' => $cap,
            'is_dreyfus' => false,
            'dreyfus_done' => $dreyfusDone,
            'allowed' => $dreyfusDone,
            'reason' => $dreyfusDone
                ? 'dreyfus_delivered_other_capabilities_unblocked'
                : 'golden_rule_phase_1_dreyfus_must_ship_first',
        ];
    }

    /**
     * Dreyfus dynamic pedagogy (doc "Capability 1", 5 levels). Maps a detected
     * level to its single documented pedagogy. An unrecognised level returns no
     * pedagogy and is flagged unknown (never fall back to a wrong stage's
     * pedagogy — that is the whole point of the capability).
     *
     * @return array{
     *   schema_version:string,
     *   level:string,
     *   known:bool,
     *   pedagogy:?string
     * }
     */
    public function resolveDreyfusPedagogy(string $level): array
    {
        $key = strtolower(trim($level));
        $pedagogy = self::DREYFUS_PEDAGOGY[$key] ?? null;

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'level' => $key,
            'known' => $pedagogy !== null,
            'pedagogy' => $pedagogy,
        ];
    }

    /**
     * Multi-Provider Discord Detector gate (doc "Capability 4", cardinal opt-in).
     * Enforces, in order:
     *   1. NEVER default — running in default mode is always blocked.
     *   2. Surface allow-list — only the four documented surfaces may run it.
     *   3. Surface deny-list — active recall / flashcard / daily_plan /
     *      micro_session are explicitly prohibited even if requested.
     *
     * @return array{
     *   schema_version:string,
     *   surface:string,
     *   is_default:bool,
     *   surface_allowed:bool,
     *   surface_forbidden:bool,
     *   allowed:bool,
     *   reason:string
     * }
     */
    public function gateDiscordDetector(string $surface, bool $isDefault): array
    {
        $key = strtolower(trim($surface));
        $allowed = in_array($key, self::DISCORD_ALLOWED_SURFACES, true);
        $forbidden = in_array($key, self::DISCORD_FORBIDDEN_SURFACES, true);

        // Cardinal rule: NUNCA default.
        if ($isDefault) {
            return [
                'schema_version' => self::SCHEMA_VERSION,
                'surface' => $key,
                'is_default' => true,
                'surface_allowed' => $allowed,
                'surface_forbidden' => $forbidden,
                'allowed' => false,
                'reason' => 'discord_detector_never_default',
            ];
        }

        // Explicit deny-list wins over anything else.
        if ($forbidden) {
            return [
                'schema_version' => self::SCHEMA_VERSION,
                'surface' => $key,
                'is_default' => false,
                'surface_allowed' => $allowed,
                'surface_forbidden' => true,
                'allowed' => false,
                'reason' => 'discord_detector_forbidden_surface',
            ];
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'surface' => $key,
            'is_default' => false,
            'surface_allowed' => $allowed,
            'surface_forbidden' => false,
            'allowed' => $allowed,
            'reason' => $allowed
                ? 'discord_detector_allowed_opt_in_surface'
                : 'discord_detector_surface_not_in_allow_list',
        ];
    }

    /**
     * Personal Worked Examples fading (doc "Capability 8" table). Returns the
     * documented output shape for the operator's stage; the example fades as the
     * stage rises. An unknown stage is flagged rather than guessed.
     *
     * @return array{
     *   schema_version:string,
     *   stage:string,
     *   known:bool,
     *   output:?string
     * }
     */
    public function resolveWorkedExampleFading(string $stage): array
    {
        $key = strtolower(trim($stage));
        $output = self::WORKED_EXAMPLE_FADING[$key] ?? null;

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'stage' => $key,
            'known' => $output !== null,
            'output' => $output,
        ];
    }

    /**
     * Cross-Domain Evidence Routing scope gate (doc "Capability 6" +
     * anti-pattern "Cross-Domain Routing matricular automaticamente"). Routing
     * may ONLY re-prioritise the existing learning queue; enrolling in a NEW area
     * is a governance violation and is blocked.
     *
     * @return array{
     *   schema_version:string,
     *   action:string,
     *   allowed:bool,
     *   reason:string
     * }
     */
    public function gateCrossDomainRouting(string $action): array
    {
        $key = strtolower(trim($action));

        // The only sanctioned action is reprioritising the existing queue.
        $isReprioritise = in_array($key, ['reprioritize_existing_queue', 'reprioritise_existing_queue', 'prioritize_existing_queue'], true);
        $isEnrolNewArea = in_array($key, ['enrol_new_area', 'enroll_new_area', 'matricular_area_nova'], true);

        if ($isReprioritise) {
            return [
                'schema_version' => self::SCHEMA_VERSION,
                'action' => $key,
                'allowed' => true,
                'reason' => 'routing_reprioritises_existing_queue_only',
            ];
        }

        if ($isEnrolNewArea) {
            return [
                'schema_version' => self::SCHEMA_VERSION,
                'action' => $key,
                'allowed' => false,
                'reason' => 'routing_must_not_enrol_in_new_area',
            ];
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'action' => $key,
            'allowed' => false,
            'reason' => 'routing_action_outside_documented_scope',
        ];
    }

    /**
     * Predictive Failure Insertion target guard (doc "Capability 9": "alvo
     * explicito obrigatorio, nunca `unknown`"). An insertion is only valid with
     * an explicit, non-empty target node; an empty target or the `unknown`
     * sentinel is rejected.
     *
     * @return array{
     *   schema_version:string,
     *   target:string,
     *   has_explicit_target:bool,
     *   allowed:bool,
     *   reason:string
     * }
     */
    public function gatePredictiveFailureInsertion(string $target): array
    {
        $key = strtolower(trim($target));

        if ($key === '') {
            return [
                'schema_version' => self::SCHEMA_VERSION,
                'target' => $key,
                'has_explicit_target' => false,
                'allowed' => false,
                'reason' => 'predictive_failure_requires_explicit_target',
            ];
        }

        if ($key === self::PREDICTIVE_FAILURE_FORBIDDEN_TARGET) {
            return [
                'schema_version' => self::SCHEMA_VERSION,
                'target' => $key,
                'has_explicit_target' => false,
                'allowed' => false,
                'reason' => 'predictive_failure_target_unknown_forbidden',
            ];
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'target' => $key,
            'has_explicit_target' => true,
            'allowed' => true,
            'reason' => 'predictive_failure_explicit_target_ok',
        ];
    }

    /**
     * Primary entry point: produce the full Multiplier Edge governance snapshot
     * used by the command and as a single source of the doc's contract.
     *
     * @return array{
     *   schema_version:string,
     *   capabilities:array<string,mixed>,
     *   dreyfus_levels:list<string>,
     *   discord_allowed_surfaces:list<string>,
     *   discord_forbidden_surfaces:list<string>,
     *   golden_rule_example:array<string,mixed>,
     *   dreyfus_pedagogy_example:array<string,mixed>,
     *   discord_default_example:array<string,mixed>,
     *   discord_forbidden_example:array<string,mixed>,
     *   worked_example_fading_example:array<string,mixed>,
     *   routing_new_area_example:array<string,mixed>,
     *   predictive_failure_unknown_example:array<string,mixed>
     * }
     */
    public function snapshot(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'capabilities' => $this->capabilities(),
            'dreyfus_levels' => array_keys(self::DREYFUS_PEDAGOGY),
            'discord_allowed_surfaces' => self::DISCORD_ALLOWED_SURFACES,
            'discord_forbidden_surfaces' => self::DISCORD_FORBIDDEN_SURFACES,
            // Worked example: starting Latticework before Dreyfus (Fase 1) is delivered is blocked.
            'golden_rule_example' => $this->gatePhaseStart('cross_domain_latticework', false),
            // Worked example: a novato gets explicit-rule, heavy-scaffolding pedagogy.
            'dreyfus_pedagogy_example' => $this->resolveDreyfusPedagogy('novato'),
            // Worked example: the discord detector is blocked when invoked as default.
            'discord_default_example' => $this->gateDiscordDetector('debate', true),
            // Worked example: the discord detector is prohibited inside flashcard surfaces.
            'discord_forbidden_example' => $this->gateDiscordDetector('flashcard', false),
            // Worked example: an expert only gets the problem and reconstructs the rest.
            'worked_example_fading_example' => $this->resolveWorkedExampleFading('expert'),
            // Worked example: routing may not enrol the operator into a new area.
            'routing_new_area_example' => $this->gateCrossDomainRouting('enrol_new_area'),
            // Worked example: a predictive-failure insertion with an unknown target is rejected.
            'predictive_failure_unknown_example' => $this->gatePredictiveFailureInsertion('unknown'),
        ];
    }
}
