<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Quarantine;

/**
 * Runtime for the Atlas AI Evolutionary Target And Implementation Goal briefing.
 *
 * This doc is deliberately the *operational briefing* that sits above the
 * implementation roadmaps and below the level model. It states explicitly that
 * "o modelo de niveis continua em atlas-ai-evolutionary-maturity-model.md", so
 * this service intentionally does NOT re-own the level lineage / promotion-proof
 * gate (those belong to AtlasAiEvolutionaryMaturityModelService). It enforces the
 * contracts that are unique to this doc and are not implemented anywhere else:
 *
 *  1. The claim guard (doc "Claims proibidas sem evidence pack"): SIX claims are
 *     forbidden unless an evidence pack is presented — AGI, ASI, "complete",
 *     "already replaces Claude/Codex in all cases", "external advantage
 *     measured", "can auto-execute without approval". A forbidden claim is only
 *     allowed when an evidence pack is explicitly supplied AND the claim is one
 *     that evidence can ever unlock; the two existential claims (AGI/ASI) can
 *     never be unlocked by an evidence pack at this stage.
 *
 *  2. The promotion dependency chain (doc "Promocao proibida"): a STRICT ladder
 *     doc -> runtime -> caller -> test -> standard-flow -> control-plane ->
 *     real-use-evidence. Each rung requires every lower rung. The gate reports
 *     the FIRST broken rung — "doc existe, mas nao ha runtime" etc. — and only
 *     promotes when the whole chain holds.
 *
 *  3. The artifact contract taxonomy (doc "Contratos"): classifies an artifact
 *     as scaffold / runtime_real / standard_flow / operational_evidence /
 *     patamar from its observable properties, enforcing "scaffold = doc, classe
 *     ou migration que ainda nao prova uso real".
 *
 *  4. The ordered operational action sequence (doc "Proximas Acoes" 1..7) and
 *     the single next action given which steps are already done.
 *
 *  5. The Definition of Done for the immediate meta (doc "Definition of Done
 *     desta meta") — eight checks, all of which must hold, plus the hard
 *     invariant that no external claim / external-measurement may be declared.
 *
 * All methods are pure and deterministic. No database, no IO.
 *
 * @see docs/engineering-knowledge-base/atlas-ai-evolutionary-target-and-implementation-goal.md
 */
final class AtlasAiEvolutionaryTargetAndImplementationGoalService
{
    public const SCHEMA_VERSION = 'atlas.aaeos.ai_evolutionary_target_and_implementation_goal.v1';

    /**
     * Doc "Claims proibidas sem evidence pack". Each forbidden claim is keyed by
     * a stable semantic id. `evidence_unlockable` marks whether presenting an
     * evidence pack could ever flip the claim to allowed: the two existential
     * claims (AGI/ASI) are NEVER unlockable here (frontmatter forbidden_changes:
     * "Declarar Atlas AGI, ASI ... sem evidence pack e gates proprios" — and at
     * this maturity no such pack exists, so they stay blocked); the operational
     * claims become allowed only with an explicit evidence pack.
     *
     * @var array<string,array{label:string,evidence_unlockable:bool}>
     */
    public const FORBIDDEN_CLAIMS = [
        'is_agi' => ['label' => 'Atlas e AGI', 'evidence_unlockable' => false],
        'is_asi' => ['label' => 'Atlas e ASI', 'evidence_unlockable' => false],
        'is_complete' => ['label' => 'Atlas esta completo', 'evidence_unlockable' => true],
        'replaces_providers_everywhere' => ['label' => 'Atlas ja substitui os providers em todos os casos', 'evidence_unlockable' => true],
        'external_advantage_measured' => ['label' => 'Atlas tem vantagem externa medida', 'evidence_unlockable' => true],
        'auto_execute_without_approval' => ['label' => 'Atlas pode autoexecutar sem approval', 'evidence_unlockable' => true],
    ];

    /**
     * Doc "Promocao proibida" — the ordered dependency chain. Each rung's `needs`
     * is the rung immediately below it; a rung can only hold if its predecessor
     * holds. `broken_reason` is the documented sentence for "this rung missing".
     *
     * @var array<int,array{key:string,broken_reason:string}>
     */
    public const PROMOTION_CHAIN = [
        ['key' => 'doc', 'broken_reason' => 'sem doc canonica'],
        ['key' => 'runtime', 'broken_reason' => 'doc existe, mas nao ha runtime'],
        ['key' => 'caller', 'broken_reason' => 'runtime existe, mas nao ha caller'],
        ['key' => 'test', 'broken_reason' => 'caller existe, mas nao ha teste'],
        ['key' => 'standard_flow', 'broken_reason' => 'teste existe, mas nao cobre fluxo padrao'],
        ['key' => 'control_plane', 'broken_reason' => 'control plane nao mostra estado'],
        ['key' => 'real_use_evidence', 'broken_reason' => 'uso real nao gera evidence'],
    ];

    /**
     * Doc "Contratos" — the five canonical artifact kinds, ranked from weakest
     * (scaffold) to strongest (patamar). Used by classifyArtifact.
     *
     * @var array<int,string>
     */
    public const ARTIFACT_KINDS = [
        'scaffold',
        'runtime_real',
        'standard_flow',
        'operational_evidence',
        'patamar',
    ];

    /**
     * Doc "Proximas Acoes" — the ordered operational sequence (1..7).
     *
     * @var array<int,array{step:int,key:string,label:string}>
     */
    public const ACTION_SEQUENCE = [
        ['step' => 1, 'key' => 'close_context_layers', 'label' => 'Fechar APCR/ACIE/ACOL/TEOS no fluxo padrao'],
        ['step' => 2, 'key' => 'close_aemor_outcomes', 'label' => 'Fechar AEMOR com outcome real, memory feedback e negative knowledge'],
        ['step' => 3, 'key' => 'close_aseif_capability_loop', 'label' => 'Fechar ASEIF com capability usage/evolution loop verificavel'],
        ['step' => 4, 'key' => 'close_desktop_control_plane', 'label' => 'Fechar Desktop Control Plane como cockpit operacional'],
        ['step' => 5, 'key' => 'implement_swarm_company', 'label' => 'Implementar Swarm Company Runtime'],
        ['step' => 6, 'key' => 'implement_company_os', 'label' => 'Implementar Autonomous Company OS multi-dominio'],
        ['step' => 7, 'key' => 'implement_world_action_engine', 'label' => 'Implementar World Action Engine governado'],
    ];

    /**
     * Doc "Definition of Done desta meta" — eight checks; the immediate meta is
     * done only when ALL hold. `external_execution_blocked` is the safety
     * invariant: external execution must remain governed/blocked, and no
     * external claim/measurement may be declared.
     *
     * @var array<int,array{key:string,label:string}>
     */
    public const DEFINITION_OF_DONE = [
        ['key' => 'persistent_context_default', 'label' => 'Atlas AI/Dev/Forge usam contexto persistente por padrao'],
        ['key' => 'aemor_outcomes_influence', 'label' => 'AEMOR registra outcomes e influencia novas execucoes sem auto-trust'],
        ['key' => 'aseif_real_usage', 'label' => 'ASEIF registra uso real de capability e cria melhoria candidata'],
        ['key' => 'control_plane_shows_state', 'label' => 'Control Plane mostra APCR, AEMOR, ASEIF, agents, blockers e approvals'],
        ['key' => 'subagents_governed', 'label' => 'Subagentes usam handoff, lease, context pack e receipts'],
        ['key' => 'external_execution_blocked', 'label' => 'External execution continua bloqueada/governada por mandato'],
        ['key' => 'gates_pass', 'label' => 'Docs-health, tests e build impactados passam'],
        ['key' => 'no_external_claims', 'label' => 'Nenhuma medicao externa e nenhuma claim externa sao executadas ou declaradas'],
    ];

    /**
     * Claim guard (doc "Claims proibidas sem evidence pack"). A claim is allowed
     * when it is not in the forbidden set, OR when it is forbidden-but-unlockable
     * and an evidence pack is explicitly supplied. Existential claims (AGI/ASI)
     * are never allowed here.
     *
     * @return array{
     *     claim:string,
     *     known_forbidden:bool,
     *     evidence_pack:bool,
     *     allowed:bool,
     *     reason:string
     * }
     */
    public function evaluateClaim(string $claimKey, bool $evidencePack = false): array
    {
        $key = strtolower(trim($claimKey));
        $row = self::FORBIDDEN_CLAIMS[$key] ?? null;

        if ($row === null) {
            return [
                'claim' => $key,
                'known_forbidden' => false,
                'evidence_pack' => $evidencePack,
                'allowed' => true,
                'reason' => 'claim_not_in_forbidden_set',
            ];
        }

        if ($row['evidence_unlockable'] === false) {
            return [
                'claim' => $key,
                'known_forbidden' => true,
                'evidence_pack' => $evidencePack,
                'allowed' => false,
                'reason' => 'existential_claim_never_allowed',
            ];
        }

        $allowed = $evidencePack === true;

        return [
            'claim' => $key,
            'known_forbidden' => true,
            'evidence_pack' => $evidencePack,
            'allowed' => $allowed,
            'reason' => $allowed ? 'unlocked_by_evidence_pack' : 'forbidden_without_evidence_pack',
        ];
    }

    /**
     * Promotion dependency chain gate (doc "Promocao proibida"). Walks the chain
     * doc -> runtime -> caller -> test -> standard_flow -> control_plane ->
     * real_use_evidence. The chain holds only while every visited rung is true;
     * the first false rung is the blocker and everything above it is unreachable.
     *
     * @param array<string,bool> $rungs key => present, keys from PROMOTION_CHAIN
     *
     * @return array{
     *     promote:bool,
     *     satisfied:array<int,string>,
     *     first_broken:string|null,
     *     first_broken_reason:string|null,
     *     unreachable:array<int,string>
     * }
     */
    public function gatePromotionChain(array $rungs): array
    {
        $satisfied = [];
        $firstBroken = null;
        $firstBrokenReason = null;
        $unreachable = [];

        foreach (self::PROMOTION_CHAIN as $rung) {
            $key = $rung['key'];

            if ($firstBroken !== null) {
                // Everything above the first break is unreachable, regardless of
                // its own flag — a higher rung cannot exist without its base.
                $unreachable[] = $key;

                continue;
            }

            if (($rungs[$key] ?? false) === true) {
                $satisfied[] = $key;

                continue;
            }

            $firstBroken = $key;
            $firstBrokenReason = $rung['broken_reason'];
        }

        return [
            'promote' => $firstBroken === null,
            'satisfied' => $satisfied,
            'first_broken' => $firstBroken,
            'first_broken_reason' => $firstBrokenReason,
            'unreachable' => $unreachable,
        ];
    }

    /**
     * Artifact contract taxonomy (doc "Contratos"). Classifies an artifact by
     * its observable properties into the strongest kind it qualifies for. The
     * ladder is monotone: a higher kind requires every property of the kinds
     * below it.
     *
     *  - scaffold: exists as doc/class/migration only, no real use.
     *  - runtime_real: executable code + tests + a usage path.
     *  - standard_flow: a runtime_real that a main domain (AI/Dev/Forge) consumes.
     *  - operational_evidence: a standard_flow that emits test/command/receipt/
     *    control-plane/hash/certification/persisted evidence.
     *  - patamar: an operational change of nature, not a feature name.
     *
     * @param array{
     *     executable?:bool,
     *     tested?:bool,
     *     has_usage_path?:bool,
     *     consumed_by_main_flow?:bool,
     *     emits_evidence?:bool,
     *     changes_operational_nature?:bool
     * } $artifact
     *
     * @return array{kind:string,is_real_runtime:bool,reason:string}
     */
    public function classifyArtifact(array $artifact): array
    {
        $executable = ($artifact['executable'] ?? false) === true;
        $tested = ($artifact['tested'] ?? false) === true;
        $usage = ($artifact['has_usage_path'] ?? false) === true;
        $mainFlow = ($artifact['consumed_by_main_flow'] ?? false) === true;
        $evidence = ($artifact['emits_evidence'] ?? false) === true;
        $changesNature = ($artifact['changes_operational_nature'] ?? false) === true;

        $isRuntimeReal = $executable && $tested && $usage;

        if (! $isRuntimeReal) {
            // Doc: "Scaffold: doc, classe ou migration que ainda nao prova uso real."
            return [
                'kind' => 'scaffold',
                'is_real_runtime' => false,
                'reason' => 'no_executable_tested_usage_path',
            ];
        }

        if ($changesNature && $mainFlow && $evidence) {
            return [
                'kind' => 'patamar',
                'is_real_runtime' => true,
                'reason' => 'operational_nature_change_in_flow_with_evidence',
            ];
        }

        if ($mainFlow && $evidence) {
            return [
                'kind' => 'operational_evidence',
                'is_real_runtime' => true,
                'reason' => 'standard_flow_emitting_evidence',
            ];
        }

        if ($mainFlow) {
            return [
                'kind' => 'standard_flow',
                'is_real_runtime' => true,
                'reason' => 'runtime_consumed_by_main_flow',
            ];
        }

        return [
            'kind' => 'runtime_real',
            'is_real_runtime' => true,
            'reason' => 'executable_tested_with_usage_path_but_no_main_flow',
        ];
    }

    /**
     * The ordered operational action sequence (doc "Proximas Acoes" 1..7).
     *
     * @return array<int,array{step:int,key:string,label:string}>
     */
    public function implementationSequence(): array
    {
        return self::ACTION_SEQUENCE;
    }

    /**
     * The single next operational action given the set of already-completed step
     * keys. The sequence is strictly ordered, so the next action is the lowest
     * step number that is not yet done. When all seven are done, the next macro
     * patamar (Swarm Company Runtime is step 5; world action engine is the tail)
     * is already covered and `all_done` is true.
     *
     * @param array<int,string> $doneKeys completed step keys
     *
     * @return array{
     *     all_done:bool,
     *     next_step:int|null,
     *     next_key:string|null,
     *     next_label:string|null,
     *     remaining:int
     * }
     */
    public function nextOperationalAction(array $doneKeys): array
    {
        $done = array_fill_keys($doneKeys, true);
        $remaining = 0;
        $next = null;

        foreach (self::ACTION_SEQUENCE as $action) {
            if (($done[$action['key']] ?? false) === true) {
                continue;
            }

            $remaining++;
            if ($next === null) {
                $next = $action;
            }
        }

        return [
            'all_done' => $next === null,
            'next_step' => $next['step'] ?? null,
            'next_key' => $next['key'] ?? null,
            'next_label' => $next['label'] ?? null,
            'remaining' => $remaining,
        ];
    }

    /**
     * Definition of Done evaluator for the immediate meta (doc "Definition of
     * Done desta meta"). The meta is done only when ALL eight checks hold. The
     * safety invariant `external_execution_blocked` and `no_external_claims` are
     * hard: if either is false the meta is not done AND a guard violation is
     * flagged (the doc forbids declaring done while external execution is open or
     * an external claim is made).
     *
     * @param array<string,bool> $checks key => satisfied, keys from DEFINITION_OF_DONE
     *
     * @return array{
     *     done:bool,
     *     satisfied:array<int,string>,
     *     missing:array<int,string>,
     *     safety_violation:bool,
     *     reason:string
     * }
     */
    public function evaluateGoalDone(array $checks): array
    {
        $satisfied = [];
        $missing = [];

        foreach (self::DEFINITION_OF_DONE as $check) {
            $key = $check['key'];
            if (($checks[$key] ?? false) === true) {
                $satisfied[] = $key;
            } else {
                $missing[] = $key;
            }
        }

        $externalGoverned = ($checks['external_execution_blocked'] ?? false) === true;
        $noExternalClaims = ($checks['no_external_claims'] ?? false) === true;
        $safetyViolation = ! $externalGoverned || ! $noExternalClaims;

        $done = $missing === [];

        $reason = $done
            ? 'all_definition_of_done_checks_satisfied'
            : ($safetyViolation ? 'blocked_safety_invariant_open' : 'missing_definition_of_done_checks');

        return [
            'done' => $done,
            'satisfied' => $satisfied,
            'missing' => $missing,
            'safety_violation' => $safetyViolation,
            'reason' => $reason,
        ];
    }

    /**
     * One read-model snapshot for operators / the command.
     *
     * @return array{
     *     schema_version:string,
     *     forbidden_claims:array<string,array{label:string,evidence_unlockable:bool}>,
     *     promotion_chain:array<int,array{key:string,broken_reason:string}>,
     *     artifact_kinds:array<int,string>,
     *     action_sequence:array<int,array{step:int,key:string,label:string}>,
     *     definition_of_done:array<int,array{key:string,label:string}>
     * }
     */
    public function snapshot(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'forbidden_claims' => self::FORBIDDEN_CLAIMS,
            'promotion_chain' => self::PROMOTION_CHAIN,
            'artifact_kinds' => self::ARTIFACT_KINDS,
            'action_sequence' => self::ACTION_SEQUENCE,
            'definition_of_done' => self::DEFINITION_OF_DONE,
        ];
    }
}
