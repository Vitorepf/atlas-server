<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Quarantine;

/**
 * Atlas Programming Tool Families — pure, deterministic family decider.
 *
 * The programming-tool-families doc is a focused map of the nine programming
 * tool families governed by the Atlas Super Tool Runtime. It is NOT the
 * registry: the doc states plainly that "Runtime truth lives in
 * `atlas_tool_definitions` and the Tool Runtime services." This service encodes
 * the documented taxonomy plus the doc's "Authority Pattern" contract as a
 * single source of truth and answers the concrete questions the doc makes
 * decidable, without ever executing a tool, connecting to anything, touching a
 * database or mutating state:
 *
 *   1. Given a tool slug, which family does it belong to?
 *   2. For a family, what are its six required Authority Pattern declarations
 *      (primary tool; complementary/fallback tools; output normalizer; evidence
 *      shape; gate thresholds; duplicate suppression rule) and are they all
 *      present (authority_complete)?
 *   3. Given two findings in the same family, must the non-primary one be
 *      suppressed (the documented "duplicate suppression rule")?
 *
 * Encoded contract (directly from the doc):
 *
 *   FAMILIES (nine, in doc table order):
 *     code_and_context, php_quality, ts_frontend, security_supply_chain,
 *     containers_iac, api_contracts, visual_a11y_perf, architecture,
 *     external_agents.
 *
 *   AUTHORITY PATTERN ("Each family must declare"):
 *     primary, complementary_fallback, output_normalizer, evidence_shape,
 *     gate_thresholds, duplicate_suppression — all six are mandatory.
 *
 *   REGISTRY RULE: this map advises; it is never runtime authority. Every
 *     verdict carries `is_registry => false`. The map cannot bypass the
 *     registry, so a family's tools are "advisory" until the registry and the
 *     Tool Runtime services confirm them.
 *
 *   EXTERNAL AGENTS: the External agents family (Aider, Continue, OpenHands,
 *     Claude Code, Codex CLI, Cursor) holds the `executor` authority role —
 *     these are engines that run behind Atlas indexing, policy, evidence and
 *     approval. The doc lists them as a family but they never become the
 *     primary authority that decides; that is why this family's primary is the
 *     control plane itself and its tools are executors.
 *
 * @see docs/engineering-knowledge-base/tool-runtime/programming-tool-families.md
 */
final class AtlasProgrammingToolFamiliesService
{
    /** Stable receipt schema id this decider emits. */
    public const SCHEMA = 'atlas.tool_runtime.programming_tool_families.v1';

    /** Families, in doc table order (closed set). */
    public const FAMILY_CODE_AND_CONTEXT = 'code_and_context';
    public const FAMILY_PHP_QUALITY = 'php_quality';
    public const FAMILY_TS_FRONTEND = 'ts_frontend';
    public const FAMILY_SECURITY_SUPPLY_CHAIN = 'security_supply_chain';
    public const FAMILY_CONTAINERS_IAC = 'containers_iac';
    public const FAMILY_API_CONTRACTS = 'api_contracts';
    public const FAMILY_VISUAL_A11Y_PERF = 'visual_a11y_perf';
    public const FAMILY_ARCHITECTURE = 'architecture';
    public const FAMILY_EXTERNAL_AGENTS = 'external_agents';

    /** Authority roles a tool can hold inside its family. */
    public const ROLE_PRIMARY = 'primary';
    public const ROLE_COMPLEMENTARY = 'complementary';
    public const ROLE_FALLBACK = 'fallback';
    public const ROLE_EXECUTOR = 'executor';

    /**
     * The six declarations the doc's "Authority Pattern" requires every family
     * to provide. Order is the doc's bullet order. A family is only
     * authority-complete when all six keys are non-empty.
     *
     * @var list<string>
     */
    public const REQUIRED_AUTHORITY_DECLARATIONS = [
        'primary',
        'complementary_fallback',
        'output_normalizer',
        'evidence_shape',
        'gate_thresholds',
        'duplicate_suppression',
    ];

    /**
     * Per-family Authority Pattern declarations plus the representative tool
     * roster transcribed from the doc's Families table.
     *
     * `primary` is the single tool that wins authority for the family; every
     * other roster tool is `complementary` or `fallback` (or `executor` for the
     * external-agents family). The duplicate-suppression rule is the doc's
     * "duplicate suppression rule" obligation: complementary/fallback findings
     * that the primary already covers are suppressed.
     *
     * @var array<string,array{
     *   title:string,
     *   tools:array<string,string>,
     *   declarations:array{
     *     primary:string,
     *     complementary_fallback:list<string>,
     *     output_normalizer:string,
     *     evidence_shape:string,
     *     gate_thresholds:string,
     *     duplicate_suppression:string
     *   }
     * }>
     */
    private const FAMILIES = [
        self::FAMILY_CODE_AND_CONTEXT => [
            'title' => 'Code and context',
            'tools' => [
                'atlas_code_intelligence' => self::ROLE_PRIMARY,
                'ripgrep' => self::ROLE_COMPLEMENTARY,
                'tree_sitter' => self::ROLE_COMPLEMENTARY,
                'ast_grep' => self::ROLE_COMPLEMENTARY,
                'serena' => self::ROLE_COMPLEMENTARY,
                'universal_ctags' => self::ROLE_FALLBACK,
            ],
            'declarations' => [
                'primary' => 'atlas_code_intelligence',
                'complementary_fallback' => ['ripgrep', 'tree_sitter', 'ast_grep', 'serena', 'universal_ctags'],
                'output_normalizer' => 'code_context_symbols.v1',
                'evidence_shape' => 'symbol_and_reference_index',
                'gate_thresholds' => 'index_freshness',
                'duplicate_suppression' => 'prefer_atlas_index_over_raw_grep',
            ],
        ],
        self::FAMILY_PHP_QUALITY => [
            'title' => 'PHP quality/types/refactor',
            'tools' => [
                'phpstan' => self::ROLE_PRIMARY,
                'composer' => self::ROLE_COMPLEMENTARY,
                'laravel_pint' => self::ROLE_COMPLEMENTARY,
                'psalm' => self::ROLE_COMPLEMENTARY,
                'phpmd' => self::ROLE_COMPLEMENTARY,
                'phpcpd' => self::ROLE_COMPLEMENTARY,
                'composer_require_checker' => self::ROLE_FALLBACK,
                'composer_unused' => self::ROLE_FALLBACK,
                'rector' => self::ROLE_FALLBACK,
            ],
            'declarations' => [
                'primary' => 'phpstan',
                'complementary_fallback' => ['composer', 'laravel_pint', 'psalm', 'phpmd', 'phpcpd', 'composer_require_checker', 'composer_unused', 'rector'],
                'output_normalizer' => 'php_static_findings.v1',
                'evidence_shape' => 'typed_finding_list',
                'gate_thresholds' => 'critical_high_blocks_medium_warns',
                'duplicate_suppression' => 'suppress_psalm_finding_when_phpstan_covers_it',
            ],
        ],
        self::FAMILY_TS_FRONTEND => [
            'title' => 'TypeScript/frontend/dead code',
            'tools' => [
                'typescript' => self::ROLE_PRIMARY,
                'eslint' => self::ROLE_COMPLEMENTARY,
                'biome' => self::ROLE_COMPLEMENTARY,
                'knip' => self::ROLE_FALLBACK,
                'ts_prune' => self::ROLE_FALLBACK,
            ],
            'declarations' => [
                'primary' => 'typescript',
                'complementary_fallback' => ['eslint', 'biome', 'knip', 'ts_prune'],
                'output_normalizer' => 'ts_js_findings.v1',
                'evidence_shape' => 'typed_finding_list',
                'gate_thresholds' => 'critical_high_blocks_medium_warns',
                'duplicate_suppression' => 'suppress_biome_lint_when_eslint_covers_it',
            ],
        ],
        self::FAMILY_SECURITY_SUPPLY_CHAIN => [
            'title' => 'Security/supply chain/licenses',
            'tools' => [
                'semgrep' => self::ROLE_PRIMARY,
                'gitleaks' => self::ROLE_COMPLEMENTARY,
                'codeql' => self::ROLE_COMPLEMENTARY,
                'osv_scanner' => self::ROLE_COMPLEMENTARY,
                'trivy' => self::ROLE_COMPLEMENTARY,
                'grype' => self::ROLE_FALLBACK,
                'syft' => self::ROLE_FALLBACK,
                'license_scanner' => self::ROLE_FALLBACK,
            ],
            'declarations' => [
                'primary' => 'semgrep',
                'complementary_fallback' => ['gitleaks', 'codeql', 'osv_scanner', 'trivy', 'grype', 'syft', 'license_scanner'],
                'output_normalizer' => 'security_findings.v1',
                'evidence_shape' => 'severity_scored_finding_list',
                'gate_thresholds' => 'critical_high_blocks_medium_warns',
                'duplicate_suppression' => 'suppress_grype_advisory_when_osv_scanner_covers_it',
            ],
        ],
        self::FAMILY_CONTAINERS_IAC => [
            'title' => 'Containers/IaC/Kubernetes',
            'tools' => [
                'checkov' => self::ROLE_PRIMARY,
                'hadolint' => self::ROLE_COMPLEMENTARY,
                'trivy' => self::ROLE_COMPLEMENTARY,
                'kubernetes_scanner' => self::ROLE_FALLBACK,
            ],
            'declarations' => [
                'primary' => 'checkov',
                'complementary_fallback' => ['hadolint', 'trivy', 'kubernetes_scanner'],
                'output_normalizer' => 'iac_findings.v1',
                'evidence_shape' => 'severity_scored_finding_list',
                'gate_thresholds' => 'critical_high_blocks_medium_warns',
                'duplicate_suppression' => 'suppress_trivy_misconfig_when_checkov_covers_it',
            ],
        ],
        self::FAMILY_API_CONTRACTS => [
            'title' => 'APIs/contracts/mocking',
            'tools' => [
                'openapi_validator' => self::ROLE_PRIMARY,
                'schemathesis' => self::ROLE_COMPLEMENTARY,
                'contract_test_tool' => self::ROLE_FALLBACK,
            ],
            'declarations' => [
                'primary' => 'openapi_validator',
                'complementary_fallback' => ['schemathesis', 'contract_test_tool'],
                'output_normalizer' => 'api_contract_findings.v1',
                'evidence_shape' => 'contract_violation_list',
                'gate_thresholds' => 'critical_high_blocks_medium_warns',
                'duplicate_suppression' => 'suppress_schemathesis_finding_when_openapi_validator_covers_it',
            ],
        ],
        self::FAMILY_VISUAL_A11Y_PERF => [
            'title' => 'Visual/accessibility/performance',
            'tools' => [
                'playwright' => self::ROLE_PRIMARY,
                'visual_smoke' => self::ROLE_COMPLEMENTARY,
                'axe_core' => self::ROLE_COMPLEMENTARY,
                'lighthouse' => self::ROLE_COMPLEMENTARY,
                'pa11y' => self::ROLE_FALLBACK,
            ],
            'declarations' => [
                'primary' => 'playwright',
                'complementary_fallback' => ['visual_smoke', 'axe_core', 'lighthouse', 'pa11y'],
                'output_normalizer' => 'visual_a11y_perf_findings.v1',
                'evidence_shape' => 'visual_and_a11y_report',
                'gate_thresholds' => 'critical_high_blocks_medium_warns',
                'duplicate_suppression' => 'suppress_pa11y_finding_when_axe_core_covers_it',
            ],
        ],
        self::FAMILY_ARCHITECTURE => [
            'title' => 'Architecture',
            'tools' => [
                'deptrac' => self::ROLE_PRIMARY,
                'dependency_cruiser' => self::ROLE_COMPLEMENTARY,
                'madge' => self::ROLE_FALLBACK,
            ],
            'declarations' => [
                'primary' => 'deptrac',
                'complementary_fallback' => ['dependency_cruiser', 'madge'],
                'output_normalizer' => 'architecture_findings.v1',
                'evidence_shape' => 'boundary_violation_list',
                'gate_thresholds' => 'critical_high_blocks_medium_warns',
                'duplicate_suppression' => 'suppress_madge_cycle_when_deptrac_covers_it',
            ],
        ],
        self::FAMILY_EXTERNAL_AGENTS => [
            'title' => 'External agents',
            'tools' => [
                'aider' => self::ROLE_EXECUTOR,
                'continue' => self::ROLE_EXECUTOR,
                'openhands' => self::ROLE_EXECUTOR,
                'claude_code' => self::ROLE_EXECUTOR,
                'codex_cli' => self::ROLE_EXECUTOR,
                'cursor' => self::ROLE_EXECUTOR,
            ],
            'declarations' => [
                // External agents are engines; the Atlas control plane holds the
                // primary authority, the agents only execute behind it.
                'primary' => 'atlas_control_plane',
                'complementary_fallback' => ['aider', 'continue', 'openhands', 'claude_code', 'codex_cli', 'cursor'],
                'output_normalizer' => 'agent_action_receipt.v1',
                'evidence_shape' => 'action_receipt_with_approval',
                'gate_thresholds' => 'policy_and_approval_required',
                'duplicate_suppression' => 'collapse_redundant_agent_edits_under_atlas_index',
            ],
        ],
    ];

    /**
     * Classify a single tool slug against the programming-tool-families map.
     * Pure: same slug always yields the same verdict.
     *
     * @return array{
     *   schema:string,
     *   tool:string,
     *   known:bool,
     *   family:?string,
     *   family_title:?string,
     *   authority_role:?string,
     *   is_primary:bool,
     *   is_registry:false,
     *   runtime_authority:false,
     *   reason:string
     * }
     */
    public function classify(string $toolSlug): array
    {
        $slug = $this->normalizeSlug($toolSlug);

        foreach (self::FAMILIES as $family => $spec) {
            if (! isset($spec['tools'][$slug])) {
                continue;
            }

            $role = $spec['tools'][$slug];

            return [
                'schema' => self::SCHEMA,
                'tool' => $slug,
                'known' => true,
                'family' => $family,
                'family_title' => $spec['title'],
                'authority_role' => $role,
                'is_primary' => $role === self::ROLE_PRIMARY,
                // Registry Rule: the family map is advisory, never the registry.
                'is_registry' => false,
                'runtime_authority' => false,
                'reason' => 'tool_mapped_to_programming_family_advisory',
            ];
        }

        return [
            'schema' => self::SCHEMA,
            'tool' => $slug,
            'known' => false,
            'family' => null,
            'family_title' => null,
            'authority_role' => null,
            'is_primary' => false,
            'is_registry' => false,
            'runtime_authority' => false,
            'reason' => 'tool_not_in_programming_tool_families_map',
        ];
    }

    /**
     * Return a family's full Authority Pattern declaration and whether it is
     * authority-complete (all six required declarations present and non-empty).
     *
     * @return array{
     *   schema:string,
     *   family:string,
     *   known:bool,
     *   title:?string,
     *   declarations:?array{
     *     primary:string,
     *     complementary_fallback:list<string>,
     *     output_normalizer:string,
     *     evidence_shape:string,
     *     gate_thresholds:string,
     *     duplicate_suppression:string
     *   },
     *   missing_declarations:list<string>,
     *   authority_complete:bool,
     *   is_registry:false
     * }
     */
    public function authorityPattern(string $family): array
    {
        $family = $this->normalizeSlug($family);

        if (! isset(self::FAMILIES[$family])) {
            return [
                'schema' => self::SCHEMA,
                'family' => $family,
                'known' => false,
                'title' => null,
                'declarations' => null,
                'missing_declarations' => self::REQUIRED_AUTHORITY_DECLARATIONS,
                'authority_complete' => false,
                'is_registry' => false,
            ];
        }

        $spec = self::FAMILIES[$family];
        $declarations = $spec['declarations'];
        $missing = $this->missingDeclarations($declarations);

        return [
            'schema' => self::SCHEMA,
            'family' => $family,
            'known' => true,
            'title' => $spec['title'],
            'declarations' => $declarations,
            'missing_declarations' => $missing,
            'authority_complete' => $missing === [],
            'is_registry' => false,
        ];
    }

    /**
     * Apply the documented "duplicate suppression rule": within one family, a
     * finding from a non-primary tool that the family's primary already covers
     * must be suppressed. Different families never suppress each other.
     *
     * @return array{
     *   schema:string,
     *   family:?string,
     *   incoming_tool:string,
     *   primary_tool:?string,
     *   decision:string,
     *   suppressed:bool,
     *   reason:string,
     *   is_registry:false
     * }
     */
    public function shouldSuppressFinding(string $incomingTool, bool $coveredByPrimary): array
    {
        $slug = $this->normalizeSlug($incomingTool);
        $verdict = $this->classify($slug);

        if (! $verdict['known']) {
            return [
                'schema' => self::SCHEMA,
                'family' => null,
                'incoming_tool' => $slug,
                'primary_tool' => null,
                'decision' => 'keep',
                'suppressed' => false,
                'reason' => 'unknown_tool_not_subject_to_family_suppression',
                'is_registry' => false,
            ];
        }

        $family = (string) $verdict['family'];
        $primary = (string) self::FAMILIES[$family]['declarations']['primary'];

        // The primary itself is never suppressed by its own coverage.
        if ($verdict['is_primary']) {
            return [
                'schema' => self::SCHEMA,
                'family' => $family,
                'incoming_tool' => $slug,
                'primary_tool' => $primary,
                'decision' => 'keep',
                'suppressed' => false,
                'reason' => 'primary_tool_finding_is_authoritative',
                'is_registry' => false,
            ];
        }

        if ($coveredByPrimary) {
            return [
                'schema' => self::SCHEMA,
                'family' => $family,
                'incoming_tool' => $slug,
                'primary_tool' => $primary,
                'decision' => 'suppress',
                'suppressed' => true,
                'reason' => 'duplicate_of_primary_tool_finding',
                'is_registry' => false,
            ];
        }

        return [
            'schema' => self::SCHEMA,
            'family' => $family,
            'incoming_tool' => $slug,
            'primary_tool' => $primary,
            'decision' => 'keep',
            'suppressed' => false,
            'reason' => 'complementary_finding_not_covered_by_primary',
            'is_registry' => false,
        ];
    }

    /**
     * Deterministic snapshot of the whole map: every family in doc order with
     * its primary, roster roles, and authority completeness. Also surfaces the
     * Registry Rule so callers never mistake this for runtime authority.
     *
     * @return array{
     *   schema:string,
     *   is_registry:false,
     *   registry_rule:string,
     *   family_order:list<string>,
     *   family_count:int,
     *   tool_count:int,
     *   all_families_authority_complete:bool,
     *   families:array<string,array{
     *     title:string,
     *     primary:string,
     *     tools:array<string,string>,
     *     authority_complete:bool,
     *     missing_declarations:list<string>
     *   }>
     * }
     */
    public function map(): array
    {
        $families = [];
        $toolCount = 0;
        $allComplete = true;

        foreach (self::familyOrder() as $family) {
            $spec = self::FAMILIES[$family];
            $missing = $this->missingDeclarations($spec['declarations']);
            $complete = $missing === [];
            $allComplete = $allComplete && $complete;
            $toolCount += count($spec['tools']);

            $families[$family] = [
                'title' => $spec['title'],
                'primary' => $spec['declarations']['primary'],
                'tools' => $spec['tools'],
                'authority_complete' => $complete,
                'missing_declarations' => $missing,
            ];
        }

        return [
            'schema' => self::SCHEMA,
            'is_registry' => false,
            'registry_rule' => 'runtime_truth_lives_in_atlas_tool_definitions_and_tool_runtime_services',
            'family_order' => self::familyOrder(),
            'family_count' => count(self::FAMILIES),
            'tool_count' => $toolCount,
            'all_families_authority_complete' => $allComplete,
            'families' => $families,
        ];
    }

    /**
     * Families in canonical doc table order.
     *
     * @return list<string>
     */
    public static function familyOrder(): array
    {
        return [
            self::FAMILY_CODE_AND_CONTEXT,
            self::FAMILY_PHP_QUALITY,
            self::FAMILY_TS_FRONTEND,
            self::FAMILY_SECURITY_SUPPLY_CHAIN,
            self::FAMILY_CONTAINERS_IAC,
            self::FAMILY_API_CONTRACTS,
            self::FAMILY_VISUAL_A11Y_PERF,
            self::FAMILY_ARCHITECTURE,
            self::FAMILY_EXTERNAL_AGENTS,
        ];
    }

    /**
     * @param  array<string,mixed>  $declarations
     * @return list<string>
     */
    private function missingDeclarations(array $declarations): array
    {
        $missing = [];

        foreach (self::REQUIRED_AUTHORITY_DECLARATIONS as $key) {
            $value = $declarations[$key] ?? null;

            $present = is_array($value) ? $value !== [] : is_string($value) && trim($value) !== '';

            if (! $present) {
                $missing[] = $key;
            }
        }

        return $missing;
    }

    private function normalizeSlug(string $slug): string
    {
        $slug = strtolower(trim($slug));

        return str_replace('-', '_', $slug);
    }
}
