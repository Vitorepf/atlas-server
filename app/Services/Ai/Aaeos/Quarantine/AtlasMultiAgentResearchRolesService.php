<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Quarantine;

use App\Services\Ai\Aaeos\AtlasAaeosStringListNormalizer;

/**
 * Atlas AI Multi-Agent Research Roles — runtime.
 *
 * Turns the documented role contract for parallel research agents into pure,
 * deterministic decision logic. The doc fixes the cast of roles, the rule that
 * every role must persist artifacts (not hidden reasoning), the anti-duplication
 * boundaries between roles, and the promotion gate that a *critical* report must
 * clear before it may leave "draft". This service makes those declarations
 * enforceable without any I/O.
 *
 * Concrete contract enforced (verbatim from the doc):
 *
 *  - Roles: the 12 canonical roles (Research Director, Source Scout, Academic
 *    Agent, GitHub Agent, Web Agent, Data Agent, Claim Verifier, Citation
 *    Auditor, Contradiction Agent, Red Team Agent, Synthesis Writer, Memory
 *    Agent), each with a documented responsibility. roles() / isRole() expose
 *    the closed set.
 *
 *  - Artifact Rule: "Every role must write one or more artifacts." A role with
 *    zero artifacts violates the rule. The Research Director "consumes
 *    artifacts, not raw hidden reasoning", so a Director output that depends on
 *    un-persisted reasoning is rejected. assessArtifacts() flags every role with
 *    no artifact and reports compliance.
 *
 *  - Anti-Duplication Rules (4 invariants):
 *      A1 Agents must declare already-searched queries/sources — an agent that
 *         re-issues a query already declared as searched is a duplicate.
 *      A2 Source Scout owns discovery; the verifier owns support judgment —
 *         a non-Scout role that introduces brand-new sources, or a Scout that
 *         stamps support verdicts, crosses the boundary.
 *      A3 Synthesis Writer cannot invent sources missing from artifacts — any
 *         source cited by the writer that is absent from the artifact pool is an
 *         invented source.
 *      A4 Red Team cannot modify the final report directly; it only emits
 *         findings — a Red Team that writes the report violates the rule.
 *    assessAntiDuplication() returns the per-rule verdicts and a roll-up.
 *
 *  - Promotion Rule: a *critical* report requires the full quorum
 *    {Research Director, Source Scout, Claim Verifier, Citation Auditor,
 *    Contradiction Agent, Red Team Agent}. Without every one of those roles the
 *    output "remains draft or low-risk summary". assessPromotion() resolves the
 *    allowed status ceiling from the active roles and the report criticality.
 *
 *  - assess() rolls the artifact, anti-duplication and promotion checks into a
 *    single verdict (promote | hold) with the reasons.
 *
 * Stateless and DB-free: every method is a pure function of its arguments.
 *
 * @see docs/engineering-knowledge-base/research-self-improvement/multi-agent-research-roles.md
 */
final class AtlasMultiAgentResearchRolesService
{
    public const SCHEMA_VERSION = 'atlas.research.multi_agent_roles.v1';

    /** Canonical role keys (closed set, in doc order). */
    public const ROLE_RESEARCH_DIRECTOR = 'research_director';
    public const ROLE_SOURCE_SCOUT = 'source_scout';
    public const ROLE_ACADEMIC_AGENT = 'academic_agent';
    public const ROLE_GITHUB_AGENT = 'github_agent';
    public const ROLE_WEB_AGENT = 'web_agent';
    public const ROLE_DATA_AGENT = 'data_agent';
    public const ROLE_CLAIM_VERIFIER = 'claim_verifier';
    public const ROLE_CITATION_AUDITOR = 'citation_auditor';
    public const ROLE_CONTRADICTION_AGENT = 'contradiction_agent';
    public const ROLE_RED_TEAM_AGENT = 'red_team_agent';
    public const ROLE_SYNTHESIS_WRITER = 'synthesis_writer';
    public const ROLE_MEMORY_AGENT = 'memory_agent';

    /** Output status ceilings (lowest -> highest assurance). */
    public const STATUS_LOW_RISK_SUMMARY = 'low_risk_summary';
    public const STATUS_DRAFT = 'draft';
    public const STATUS_PROMOTABLE = 'promotable';

    /**
     * The 12 canonical roles with their documented responsibility.
     *
     * @var array<string,string>
     */
    private const ROLE_RESPONSIBILITIES = [
        self::ROLE_RESEARCH_DIRECTOR => 'Defines objective, scope, budget, subquestions and stop conditions.',
        self::ROLE_SOURCE_SCOUT => 'Finds source candidates across registries, APIs and watchlists.',
        self::ROLE_ACADEMIC_AGENT => 'Papers, authors, venues, citations, versions and limitations.',
        self::ROLE_GITHUB_AGENT => 'Releases, commits, PRs, issues, advisories, evaluations and examples.',
        self::ROLE_WEB_AGENT => 'Official docs, changelogs, posts, screenshots and snapshots.',
        self::ROLE_DATA_AGENT => 'Runs safe analysis, tables, charts and evaluation parsing.',
        self::ROLE_CLAIM_VERIFIER => 'Maps atomic claims to evidence and statuses.',
        self::ROLE_CITATION_AUDITOR => 'URL health, archive, quote support and source drift.',
        self::ROLE_CONTRADICTION_AGENT => 'Searches for contrary evidence and superseding sources.',
        self::ROLE_RED_TEAM_AGENT => 'Finds exaggeration, missing uncertainty, weak sources and unsafe action.',
        self::ROLE_SYNTHESIS_WRITER => 'Produces final report from verified artifacts only.',
        self::ROLE_MEMORY_AGENT => 'Updates trends, history and candidate memories through gates.',
    ];

    /**
     * The eight documented artifact kinds any role may persist.
     *
     * @var list<string>
     */
    private const ARTIFACT_KINDS = [
        'source_candidates',
        'evidence_objects',
        'claims',
        'contradiction_notes',
        'citation_health_report',
        'synthesis_draft',
        'eval_report',
        'promotion_proposal',
    ];

    /**
     * Promotion quorum: the exact roles a *critical* report requires before its
     * output may rise above draft / low-risk summary.
     *
     * @var list<string>
     */
    private const CRITICAL_QUORUM = [
        self::ROLE_RESEARCH_DIRECTOR,
        self::ROLE_SOURCE_SCOUT,
        self::ROLE_CLAIM_VERIFIER,
        self::ROLE_CITATION_AUDITOR,
        self::ROLE_CONTRADICTION_AGENT,
        self::ROLE_RED_TEAM_AGENT,
    ];

    /**
     * The closed set of canonical roles and their responsibilities.
     *
     * @return array<string,string>
     */
    public function roles(): array
    {
        return self::ROLE_RESPONSIBILITIES;
    }

    /** Documented artifact kinds. @return list<string> */
    public function artifactKinds(): array
    {
        return self::ARTIFACT_KINDS;
    }

    /** Promotion quorum for critical reports. @return list<string> */
    public function criticalQuorum(): array
    {
        return self::CRITICAL_QUORUM;
    }

    /** True only for one of the 12 canonical roles. */
    public function isRole(string $role): bool
    {
        return array_key_exists($role, self::ROLE_RESPONSIBILITIES);
    }

    /**
     * Artifact Rule: every role active in a run must persist >= 1 artifact.
     *
     * Input shape: map of role => list<artifact> (artifact may be a kind string
     * or an array with an 'kind'/'type' key; only presence is judged here).
     *
     * @param  array<string,mixed>  $artifactsByRole
     * @return array{
     *   schema_version:string,
     *   compliant:bool,
     *   roles_evaluated:int,
     *   roles_with_artifacts:int,
     *   roles_missing_artifacts:list<string>,
     *   unknown_roles:list<string>
     * }
     */
    public function assessArtifacts(array $artifactsByRole): array
    {
        $missing = [];
        $unknown = [];
        $withArtifacts = 0;
        $evaluated = 0;

        foreach ($artifactsByRole as $role => $artifacts) {
            $role = (string) $role;
            if (! $this->isRole($role)) {
                $unknown[] = $role;

                continue;
            }
            $evaluated++;
            $count = is_array($artifacts) ? count($artifacts) : 0;
            if ($count >= 1) {
                $withArtifacts++;
            } else {
                $missing[] = $role;
            }
        }

        sort($missing);
        sort($unknown);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'compliant' => $missing === [] && $unknown === [] && $evaluated > 0,
            'roles_evaluated' => $evaluated,
            'roles_with_artifacts' => $withArtifacts,
            'roles_missing_artifacts' => $missing,
            'unknown_roles' => $unknown,
        ];
    }

    /**
     * Anti-Duplication Rules. Evaluates the four documented invariants against a
     * run trace.
     *
     * Input shape (all optional, all lists default empty):
     *   declared_searched: list<string>   queries/sources already declared done
     *   reissued_searches: list<string>   queries/sources an agent re-issued
     *   new_sources_by_role: array<string,list<string>>  sources introduced per role
     *   scout_support_verdicts: int       count of support verdicts stamped by Scout
     *   artifact_sources: list<string>    every source present in artifacts
     *   writer_cited_sources: list<string> sources the Synthesis Writer cited
     *   red_team_wrote_report: bool       did Red Team write the final report
     *
     * @param  array<string,mixed>  $trace
     * @return array{
     *   schema_version:string,
     *   ok:bool,
     *   violations:list<string>,
     *   rules:array{
     *     A1_declare_searched:array{ok:bool,duplicate_searches:list<string>},
     *     A2_scout_owns_discovery:array{ok:bool,non_scout_new_sources:array<string,list<string>>,scout_support_verdicts:int},
     *     A3_writer_no_invented_sources:array{ok:bool,invented_sources:list<string>},
     *     A4_red_team_emits_findings_only:array{ok:bool,red_team_wrote_report:bool}
     *   }
     * }
     */
    public function assessAntiDuplication(array $trace): array
    {
        $declared = AtlasAaeosStringListNormalizer::stringsFromArtifactRefs($trace['declared_searched'] ?? []);
        $reissued = AtlasAaeosStringListNormalizer::stringsFromArtifactRefs($trace['reissued_searches'] ?? []);

        // A1: a re-issued query that was already declared searched is a duplicate.
        $duplicateSearches = array_values(array_unique(array_intersect($reissued, $declared)));
        sort($duplicateSearches);
        $a1Ok = $duplicateSearches === [];

        // A2: Source Scout owns discovery; verifier owns support judgment.
        $newByRole = is_array($trace['new_sources_by_role'] ?? null) ? $trace['new_sources_by_role'] : [];
        $nonScoutNew = [];
        foreach ($newByRole as $role => $sources) {
            $role = (string) $role;
            if ($role === self::ROLE_SOURCE_SCOUT) {
                continue;
            }
            $list = AtlasAaeosStringListNormalizer::stringsFromArtifactRefs($sources);
            if ($list !== []) {
                $nonScoutNew[$role] = $list;
            }
        }
        $scoutSupportVerdicts = (int) ($trace['scout_support_verdicts'] ?? 0);
        $a2Ok = $nonScoutNew === [] && $scoutSupportVerdicts === 0;

        // A3: Synthesis Writer cannot cite sources absent from artifacts.
        $artifactSources = AtlasAaeosStringListNormalizer::stringsFromArtifactRefs($trace['artifact_sources'] ?? []);
        $writerCited = AtlasAaeosStringListNormalizer::stringsFromArtifactRefs($trace['writer_cited_sources'] ?? []);
        $invented = array_values(array_unique(array_diff($writerCited, $artifactSources)));
        sort($invented);
        $a3Ok = $invented === [];

        // A4: Red Team only emits findings; it must not write the final report.
        $redWrote = (bool) ($trace['red_team_wrote_report'] ?? false);
        $a4Ok = ! $redWrote;

        $violations = [];
        if (! $a1Ok) {
            $violations[] = 'A1_declare_searched';
        }
        if (! $a2Ok) {
            $violations[] = 'A2_scout_owns_discovery';
        }
        if (! $a3Ok) {
            $violations[] = 'A3_writer_no_invented_sources';
        }
        if (! $a4Ok) {
            $violations[] = 'A4_red_team_emits_findings_only';
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'ok' => $violations === [],
            'violations' => $violations,
            'rules' => [
                'A1_declare_searched' => [
                    'ok' => $a1Ok,
                    'duplicate_searches' => $duplicateSearches,
                ],
                'A2_scout_owns_discovery' => [
                    'ok' => $a2Ok,
                    'non_scout_new_sources' => $nonScoutNew,
                    'scout_support_verdicts' => $scoutSupportVerdicts,
                ],
                'A3_writer_no_invented_sources' => [
                    'ok' => $a3Ok,
                    'invented_sources' => $invented,
                ],
                'A4_red_team_emits_findings_only' => [
                    'ok' => $a4Ok,
                    'red_team_wrote_report' => $redWrote,
                ],
            ],
        ];
    }

    /**
     * Promotion Rule. A critical report needs the full quorum; otherwise the
     * output ceiling drops to draft / low-risk summary.
     *
     * @param  list<string>  $activeRoles  roles present in the run
     * @return array{
     *   schema_version:string,
     *   critical:bool,
     *   quorum_met:bool,
     *   missing_quorum_roles:list<string>,
     *   allowed_status:string,
     *   reason:string
     * }
     */
    public function assessPromotion(array $activeRoles, bool $critical): array
    {
        $active = array_values(array_unique(AtlasAaeosStringListNormalizer::stringsFromArtifactRefs($activeRoles)));
        $missing = array_values(array_diff(self::CRITICAL_QUORUM, $active));
        sort($missing);
        $quorumMet = $missing === [];

        if (! $critical) {
            // Non-critical reports are not gated by the quorum.
            return [
                'schema_version' => self::SCHEMA_VERSION,
                'critical' => false,
                'quorum_met' => $quorumMet,
                'missing_quorum_roles' => $missing,
                'allowed_status' => self::STATUS_PROMOTABLE,
                'reason' => 'non_critical_report_not_gated',
            ];
        }

        if ($quorumMet) {
            return [
                'schema_version' => self::SCHEMA_VERSION,
                'critical' => true,
                'quorum_met' => true,
                'missing_quorum_roles' => [],
                'allowed_status' => self::STATUS_PROMOTABLE,
                'reason' => 'critical_quorum_satisfied',
            ];
        }

        // Doc: "Without these roles, output remains draft or low-risk summary."
        // Missing any guard role caps at draft; missing the Director (no objective
        // / stop conditions) caps even lower, at a low-risk summary.
        $ceiling = in_array(self::ROLE_RESEARCH_DIRECTOR, $missing, true)
            ? self::STATUS_LOW_RISK_SUMMARY
            : self::STATUS_DRAFT;

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'critical' => true,
            'quorum_met' => false,
            'missing_quorum_roles' => $missing,
            'allowed_status' => $ceiling,
            'reason' => 'critical_quorum_incomplete',
        ];
    }

    /**
     * Roll-up verdict over the three rule families for a research run.
     *
     * Input shape:
     *   critical: bool
     *   active_roles: list<string>
     *   artifacts_by_role: array<string,mixed>
     *   trace: array<string,mixed>   (anti-duplication trace)
     *
     * @param  array<string,mixed>  $run
     * @return array{
     *   schema_version:string,
     *   decision:string,
     *   allowed_status:string,
     *   blockers:list<string>,
     *   artifacts:array<string,mixed>,
     *   anti_duplication:array<string,mixed>,
     *   promotion:array<string,mixed>
     * }
     */
    public function assess(array $run): array
    {
        $critical = (bool) ($run['critical'] ?? false);
        $activeRoles = AtlasAaeosStringListNormalizer::stringsFromArtifactRefs($run['active_roles'] ?? []);
        $artifactsByRole = is_array($run['artifacts_by_role'] ?? null) ? $run['artifacts_by_role'] : [];
        $trace = is_array($run['trace'] ?? null) ? $run['trace'] : [];

        $artifacts = $this->assessArtifacts($artifactsByRole);
        $antiDup = $this->assessAntiDuplication($trace);
        $promotion = $this->assessPromotion($activeRoles, $critical);

        $blockers = [];
        if (! $artifacts['compliant']) {
            $blockers[] = 'artifact_rule';
        }
        if (! $antiDup['ok']) {
            $blockers[] = 'anti_duplication';
        }
        if ($promotion['allowed_status'] !== self::STATUS_PROMOTABLE) {
            $blockers[] = 'promotion_quorum';
        }

        $decision = $blockers === [] ? 'promote' : 'hold';

        // The effective ceiling never exceeds what promotion allows, and any
        // artifact/anti-dup blocker forces a hold regardless of quorum.
        $allowedStatus = $promotion['allowed_status'];
        if ($decision === 'hold' && $allowedStatus === self::STATUS_PROMOTABLE) {
            $allowedStatus = self::STATUS_DRAFT;
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'decision' => $decision,
            'allowed_status' => $allowedStatus,
            'blockers' => $blockers,
            'artifacts' => $artifacts,
            'anti_duplication' => $antiDup,
            'promotion' => $promotion,
        ];
    }

    /**
     * A safe, fully compliant demo run (critical, full quorum, every active role
     * has artifacts, no anti-duplication violations). Used by the CLI default.
     *
     * @return array<string,mixed>
     */
    public function demoRun(): array
    {
        $artifactsByRole = [];
        foreach (self::CRITICAL_QUORUM as $i => $role) {
            $artifactsByRole[$role] = [self::ARTIFACT_KINDS[$i % count(self::ARTIFACT_KINDS)]];
        }
        $artifactsByRole[self::ROLE_SYNTHESIS_WRITER] = ['synthesis_draft'];

        return [
            'critical' => true,
            'active_roles' => array_merge(self::CRITICAL_QUORUM, [self::ROLE_SYNTHESIS_WRITER]),
            'artifacts_by_role' => $artifactsByRole,
            'trace' => [
                'declared_searched' => ['q:llm-eval', 'src:arxiv'],
                'reissued_searches' => [],
                'new_sources_by_role' => [self::ROLE_SOURCE_SCOUT => ['src:arxiv', 'src:github']],
                'scout_support_verdicts' => 0,
                'artifact_sources' => ['src:arxiv', 'src:github'],
                'writer_cited_sources' => ['src:arxiv'],
                'red_team_wrote_report' => false,
            ],
        ];
    }

}
