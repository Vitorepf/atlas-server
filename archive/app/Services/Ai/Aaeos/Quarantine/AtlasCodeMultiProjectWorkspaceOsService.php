<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Quarantine;

use App\Services\Ai\Aaeos\Support\AtlasAaeosValueNormalizer;

/**
 * Deterministic work-router for the Atlas Code Multi-Project Workspace OS.
 *
 * The source doc is the canonical contract that Atlas Cartografia and Atlas Code
 * must operate INSIDE a selected software Project/Workspace, so Atlas can program
 * Atlas, Blackink and any other repository without confusing Project with Obra.
 * This service turns that doc's "Regras para IA", "Contratos" and "Fluxo"
 * sections into runtime: given an active Project/Workspace profile and a single
 * code-work request, it decides which work tier the request belongs to
 * (Consulta / Intervencao Rapida / Candidato de Obra / Obra), whether execution
 * is allowed, and which documented invariants are currently violated.
 *
 * It is pure and read-only: it never selects a project, never runs a command,
 * never creates an Obra. It only renders a typed routing decision over the
 * inputs so the caller (UI/CLI/agent) can act inside the right workspace.
 *
 * Documented contract this code genuinely enforces:
 *
 *  - Active-project gate ("Fluxo" step 1 + quality gate `active-project-selected`
 *    + failure mode "Rodar comando no repo errado"): with no active project the
 *    router refuses to route any code work; only a project-selection action is
 *    offered. This is the doc's first invariant — "Sempre pergunte ou infira o
 *    Projeto ativo antes de orientar trabalho de codigo."
 *
 *  - Project-vs-Obra invariant ("Hierarquia proibida: Obra -> Projeto inteiro";
 *    "Atlas e Blackink nao sao Obras. Eles sao Projetos/Workspaces"; failure mode
 *    "Confundir Projeto com Obra"): a request that asks to treat the whole
 *    product/repository as a single Obra is rejected as `project_not_obra`,
 *    never routed to `obra`.
 *
 *  - "Regras para IA" classification (the heart of the doc):
 *      * light conversation / question         -> consulta
 *      * pequeno + claro + reversivel           -> intervencao_rapida
 *      * ambiguo / precisa descoberta           -> candidato_de_obra
 *      * longo / arriscado / estrutural / multi -> obra
 *    "Se o trabalho e pequeno e claro, sugerir Intervencao Rapida, nao Obra"
 *    is enforced literally: a small clear reversible single-area change can NEVER
 *    be escalated to Obra by this router.
 *
 *  - Production risk floor (failure mode "Ajuste pequeno em produto production
 *    virar Obra pesada" + Blackink example "Risco padrao: maior por estar em
 *    producao"): when the active project is in production, the effective risk is
 *    floored to at least `high` and any non-trivial change requires explicit
 *    intervention review — but a genuinely small/clear/reversible change still
 *    stays an Intervencao Rapida (with the review flag) rather than auto-Obra.
 *
 *  - Execution gate ("Nunca rode comando sem workspace/repo explicitamente
 *    resolvido" + "Qualquer execucao usa o workspace/repo correto"): execution is
 *    allowed only when the active project resolves a workspace/repo path. With no
 *    resolved path, only Consulta/Descoberta is permitted and execution is
 *    blocked.
 *
 *  - Minimal-profile guard ("Se o Projeto nao tem docs organizadas, criar Project
 *    Profile minimo antes de trabalho arriscado"): when docs are incomplete and
 *    the routed tier is risky (candidato_de_obra or obra), the router requires a
 *    minimal Project Profile first and surfaces the missing required fields from
 *    the documented "Project Profile minimo" schema.
 *
 * @see docs/engineering-knowledge-base/atlas-code-multi-project-workspace-os.md
 */
final class AtlasCodeMultiProjectWorkspaceOsService
{
    /** Stable schema id this router emits. */
    public const SCHEMA_VERSION = 'atlas.code_multi_project_workspace_os.work_route.v1';

    /** Closed-set work tiers from the doc's "Contratos" / "Regras para IA". */
    public const TIER_CONSULTA = 'consulta';
    public const TIER_INTERVENCAO_RAPIDA = 'intervencao_rapida';
    public const TIER_CANDIDATO_DE_OBRA = 'candidato_de_obra';
    public const TIER_OBRA = 'obra';

    /** Returned when no work tier can be assigned (e.g. no active project). */
    public const TIER_NONE = 'none';

    /**
     * The documented "Project Profile minimo" required fields. A risky route
     * against a docs-incomplete project must have these present first.
     *
     * @var array<int, string>
     */
    private const PROFILE_MINIMUM_FIELDS = [
        'project_id',
        'name',
        'repo_root',
        'workspace_path',
        'production_status',
    ];

    /**
     * Route a single code-work request inside the active Project/Workspace.
     *
     * @param array<string, mixed> $activeProject Project/Workspace profile (may be empty / null when nothing selected). Recognised keys:
     *   project_id        (string)  stable id; presence => a project is selected
     *   name              (string)
     *   repo_root         (string)
     *   workspace_path    (string)  resolved => execution allowed
     *   production_status (string)  'production' floors risk to >= high
     *   docs_status       (string)  'incomplete'/'unknown' triggers profile guard
     *   default_risk      (string)  low|medium|high (default medium)
     * @param array<string, mixed> $request The code-work request. Recognised keys:
     *   targets_whole_project (bool)  asks to treat the whole product/repo as one Obra
     *   is_question_only      (bool)  light conversation / pure question => consulta
     *   is_small              (bool)  small change
     *   is_clear              (bool)  unambiguous, well-understood
     *   is_reversible         (bool)  easily reverted
     *   is_ambiguous          (bool)  needs structured discovery first
     *   is_structural         (bool)  redesign / architecture-level
     *   files_touched         (int)   number of files/modules the work spans
     *
     * @return array{
     *   schema_version: string,
     *   tier: string,
     *   active_project: bool,
     *   execution_allowed: bool,
     *   execution_blocked_reason: string|null,
     *   effective_risk: string,
     *   requires_explicit_intervention_review: bool,
     *   requires_minimal_profile: bool,
     *   missing_profile_fields: array<int, string>,
     *   invariant_violations: array<int, string>,
     *   rationale: array<int, string>,
     *   required_next_action: string
     * }
     */
    public function routeWork(array $activeProject, array $request): array
    {
        $rationale = [];
        $violations = [];

        $hasActiveProject = $this->str($activeProject, 'project_id') !== '';
        $workspacePath = $this->str($activeProject, 'workspace_path');
        $repoRoot = $this->str($activeProject, 'repo_root');
        $executionPathResolved = $workspacePath !== '' || $repoRoot !== '';
        $isProduction = strtolower($this->str($activeProject, 'production_status')) === 'production';
        $docsIncomplete = in_array(
            strtolower($this->str($activeProject, 'docs_status', 'unknown')),
            ['incomplete', 'incompletas', 'unknown', 'missing', ''],
            true
        );

        // ── Active-project gate. No project => refuse to route code work. ──
        if (! $hasActiveProject) {
            return [
                'schema_version' => self::SCHEMA_VERSION,
                'tier' => self::TIER_NONE,
                'active_project' => false,
                'execution_allowed' => false,
                'execution_blocked_reason' => 'no_active_project: selecione um Projeto/Workspace antes de orientar trabalho de codigo.',
                'effective_risk' => 'unknown',
                'requires_explicit_intervention_review' => false,
                'requires_minimal_profile' => false,
                'missing_profile_fields' => [],
                'invariant_violations' => ['no_active_project: regra "Sempre pergunte ou infira o Projeto ativo antes de orientar trabalho de codigo".'],
                'rationale' => ['Nenhum Projeto ativo: o roteador nao classifica trabalho sem contexto de Projeto/Workspace.'],
                'required_next_action' => 'Selecionar Projeto/Workspace ativo no Atlas Desktop.',
            ];
        }

        // Flags from the request.
        $targetsWholeProject = (bool) ($request['targets_whole_project'] ?? false);
        $questionOnly = (bool) ($request['is_question_only'] ?? false);
        $small = (bool) ($request['is_small'] ?? false);
        $clear = (bool) ($request['is_clear'] ?? false);
        $reversible = (bool) ($request['is_reversible'] ?? false);
        $ambiguous = (bool) ($request['is_ambiguous'] ?? false);
        $structural = (bool) ($request['is_structural'] ?? false);
        $filesTouched = max(0, (int) ($request['files_touched'] ?? 0));

        // ── Project-vs-Obra invariant: a product/repo is never one Obra. ──
        if ($targetsWholeProject) {
            $violations[] = 'project_not_obra: "Hierarquia proibida: Obra -> Projeto inteiro". Atlas/Blackink sao Projetos, nao Obras.';
        }

        // ── "Regras para IA" classification ──
        // A small + clear + reversible single-area change is the canonical
        // Intervencao Rapida and must NEVER be auto-escalated to Obra.
        $isQuickIntervention = $small && $clear && $reversible && ! $structural && $filesTouched <= 1 && ! $ambiguous;

        if ($questionOnly) {
            $tier = self::TIER_CONSULTA;
            $rationale[] = 'Pergunta/conversa leve: Consulta dentro do Projeto (sem mudanca de codigo).';
        } elseif ($targetsWholeProject) {
            // Whole-product framing is rejected from Obra; the safe landing is a
            // structured discovery (Candidato), never "Obra = produto inteiro".
            $tier = self::TIER_CANDIDATO_DE_OBRA;
            $rationale[] = 'Pedido enquadra o produto inteiro como Obra: rebaixado para Candidato de Obra (descoberta), nunca Obra = Projeto.';
        } elseif ($isQuickIntervention) {
            $tier = self::TIER_INTERVENCAO_RAPIDA;
            $rationale[] = 'Pequeno + claro + reversivel + 1 area: Intervencao Rapida (regra "nao virar Obra").';
        } elseif ($ambiguous) {
            $tier = self::TIER_CANDIDATO_DE_OBRA;
            $rationale[] = 'Ambiguo / precisa descoberta estruturada: Candidato de Obra.';
        } elseif ($structural || $filesTouched >= 3) {
            $tier = self::TIER_OBRA;
            $rationale[] = 'Estrutural / multi-arquivo (>=3): Obra de Programacao governada.';
        } else {
            // Non-trivial but not clearly small/clear: discovery before commit.
            $tier = self::TIER_CANDIDATO_DE_OBRA;
            $rationale[] = 'Nao e claramente pequeno e claro: Candidato de Obra antes de promover.';
        }

        // ── Production risk floor ──
        $baseRisk = $this->normalizeRisk($this->str($activeProject, 'default_risk', 'medium'));
        $effectiveRisk = $baseRisk;
        $requiresReview = false;
        if ($isProduction) {
            $effectiveRisk = $this->maxRisk($baseRisk, 'high');
            $rationale[] = 'Projeto em producao: risco efetivo elevado para no minimo "high".';
            // Any non-Consulta change in production needs explicit review, but a
            // genuine Intervencao Rapida stays Intervencao Rapida (flagged), not Obra.
            if ($tier !== self::TIER_CONSULTA) {
                $requiresReview = true;
                $rationale[] = 'Producao: mudanca exige revisao explicita de intervencao (sem virar Obra automaticamente).';
            }
        }

        // ── Execution gate ──
        $executionAllowed = $executionPathResolved;
        $executionBlockedReason = null;
        if (! $executionAllowed) {
            $executionBlockedReason = 'unresolved_workspace: sem workspace/repo resolvido; apenas Consulta/Descoberta liberada (regra "nunca rode comando sem workspace/repo resolvido").';
            $rationale[] = 'Workspace/repo nao resolvido: execucao bloqueada.';
        }

        // ── Minimal-profile guard for risky routes on docs-incomplete projects ──
        $riskyTier = in_array($tier, [self::TIER_CANDIDATO_DE_OBRA, self::TIER_OBRA], true);
        $requiresMinimalProfile = false;
        $missingProfileFields = [];
        if ($riskyTier && $docsIncomplete) {
            $missingProfileFields = $this->missingProfileFields($activeProject);
            if ($missingProfileFields !== []) {
                $requiresMinimalProfile = true;
                $rationale[] = 'Docs incompletas + trabalho arriscado: exigir Project Profile minimo antes (faltam campos).';
            }
        }

        $requiredNextAction = $this->requiredNextAction(
            $tier,
            $violations,
            $requiresMinimalProfile,
            $executionAllowed,
            $requiresReview
        );

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'tier' => $tier,
            'active_project' => true,
            'execution_allowed' => $executionAllowed,
            'execution_blocked_reason' => $executionBlockedReason,
            'effective_risk' => $effectiveRisk,
            'requires_explicit_intervention_review' => $requiresReview,
            'requires_minimal_profile' => $requiresMinimalProfile,
            'missing_profile_fields' => array_values($missingProfileFields),
            'invariant_violations' => array_values($violations),
            'rationale' => array_values($rationale),
            'required_next_action' => $requiredNextAction,
        ];
    }

    /**
     * The four documented work tiers, in escalation order (read-only).
     *
     * @return array<int, string>
     */
    public function workTiers(): array
    {
        return [
            self::TIER_CONSULTA,
            self::TIER_INTERVENCAO_RAPIDA,
            self::TIER_CANDIDATO_DE_OBRA,
            self::TIER_OBRA,
        ];
    }

    /**
     * The documented "Project Profile minimo" required field keys (read-only).
     *
     * @return array<int, string>
     */
    public function minimumProfileFields(): array
    {
        return self::PROFILE_MINIMUM_FIELDS;
    }

    /**
     * @param array<string, mixed> $activeProject
     * @return array<int, string>
     */
    private function missingProfileFields(array $activeProject): array
    {
        $missing = [];
        foreach (self::PROFILE_MINIMUM_FIELDS as $field) {
            if ($this->str($activeProject, $field) === '') {
                $missing[] = $field;
            }
        }

        return $missing;
    }

    /**
     * @param array<int, string> $violations
     */
    private function requiredNextAction(
        string $tier,
        array $violations,
        bool $requiresMinimalProfile,
        bool $executionAllowed,
        bool $requiresReview
    ): string {
        if ($violations !== []) {
            return 'Corrigir enquadramento: produto/repo nao pode ser uma Obra; reescrever como Projeto com Candidato/Obra dentro.';
        }
        if ($requiresMinimalProfile) {
            return 'Criar Project Profile minimo antes de iniciar trabalho arriscado.';
        }
        if (! $executionAllowed && $tier !== self::TIER_CONSULTA) {
            return 'Resolver workspace/repo do Projeto antes de qualquer execucao.';
        }

        return match ($tier) {
            self::TIER_CONSULTA => 'Responder como Consulta dentro do Projeto, sem alterar codigo.',
            self::TIER_INTERVENCAO_RAPIDA => $requiresReview
                ? 'Executar como Intervencao Rapida com revisao explicita (producao).'
                : 'Executar como Intervencao Rapida (mudanca pequena, clara, reversivel).',
            self::TIER_CANDIDATO_DE_OBRA => 'Abrir Candidato de Obra: descoberta estruturada antes de promover.',
            self::TIER_OBRA => 'Promover para Obra de Programacao governada (gates + evidence).',
            default => 'Selecionar Projeto/Workspace ativo.',
        };
    }

    private function normalizeRisk(string $risk): string
    {
        return AtlasAaeosValueNormalizer::lowMediumHighRisk($risk);
    }

    private function maxRisk(string $a, string $b): string
    {
        $rank = ['low' => 1, 'medium' => 2, 'high' => 3];
        $ra = $rank[$this->normalizeRisk($a)] ?? 2;
        $rb = $rank[$this->normalizeRisk($b)] ?? 2;

        return $ra >= $rb ? $this->normalizeRisk($a) : $this->normalizeRisk($b);
    }

    /**
     * @param array<string, mixed> $source
     */
    private function str(array $source, string $key, string $default = ''): string
    {
        $value = $source[$key] ?? $default;

        return is_string($value) ? trim($value) : $default;
    }
}
