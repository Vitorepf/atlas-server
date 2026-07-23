<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Quarantine;

use InvalidArgumentException;

/**
 * Pure, deterministic decider for the Atlas Tool Runtime Catalog Roadmap.
 *
 * This is NOT a tool registry, a doctor, an executor or a sandbox. It never runs
 * a binary, never touches the database and never spends tokens. It encodes the
 * concrete decision rules the doc states over plain typed arrays — no side
 * effects — so the catalog contract can be pinned and reused independently of the
 * live Super Tool Runtime.
 *
 * Two documented contracts are enforced:
 *
 *   1. PRIORITY TIER / FAMILY CLASSIFICATION. The doc enumerates the cataloged
 *      tool families under five headings and an explicit priority order:
 *        - P0 "Code Semantics"              (Serena/LSP/MCP, Tree-sitter, ast-grep,
 *                                            universal-ctags, IDE bridges)
 *        - P0 "Quality And Static Analysis" (PHPStan/Psalm, Pint/Biome/Prettier,
 *                                            ESLint/TypeScript, Rector, PHPMD/PHPCPD,
 *                                            Composer Require Checker/Unused, Knip/ts-prune)
 *        - P0 "Security And Supply Chain"   (Gitleaks, Semgrep, CodeQL, OSV-Scanner,
 *                                            Trivy/Grype, Syft, Checkov/Terrascan,
 *                                            kube-linter/kube-score, Dockle, ScanCode/ORT/licensee)
 *        - P1 "API, Testing, Frontend And Architecture"
 *                                           (Schemathesis, Pact, Prism, WireMock, Bruno,
 *                                            Infection/Stryker, fast-check/Hypothesis,
 *                                            axe-core, Pa11y, Lighthouse CI, bundle analyzers,
 *                                            pixelmatch, Deptrac, dependency-cruiser, Madge,
 *                                            OpenRewrite, comby, jscodeshift, ts-morph)
 *        - P2 "External Agents"             (Aider, Continue, OpenHands, Serena/MCP write ops)
 *      Per-tier invariants the doc states are surfaced:
 *        - Code Semantics: "Reads are cheap. Writes require sandbox/approval and evidence."
 *        - External Agents: "Agents must run behind Atlas context, policy, worktree/sandbox,
 *          tests, gates, Evidence Store and operator approval where needed."
 *
 *   2. PROMOTION RULE. The doc's "Promotion Rule" states a cataloged tool becomes
 *      operational only AFTER all of:
 *        - registry definition;
 *        - doctor detection state;
 *        - safe recipe WHEN EXECUTABLE;          (conditional on executability)
 *        - normalizer OR explicit generic fallback;
 *        - authority group role;
 *        - gate behavior;
 *        - tests with fake binary OR fixture output.
 *      This service caps "operational" behind every required requirement and lists
 *      exactly which are missing, so a tool is never declared operational early.
 *
 * Two canonical decisions from the frontmatter are also enforced:
 *   - "Tool backlog is optional/local/open-source first when possible."
 *   - "Tool families enter as governed capabilities, not parallel flows."
 *
 * Stateless and DB-free: every method is a pure function of its arguments.
 *
 * @see docs/engineering-knowledge-base/tool-runtime/catalog-roadmap.md
 */
final class AtlasCatalogRoadmapService
{
    public const SCHEMA = 'atlas.tool_runtime.catalog_roadmap.v1';

    /** Priority tiers, in the doc's heading order. */
    public const TIER_P0 = 'P0';
    public const TIER_P1 = 'P1';
    public const TIER_P2 = 'P2';

    /** Family ids (one per doc heading). */
    public const FAMILY_CODE_SEMANTICS = 'code_semantics';
    public const FAMILY_QUALITY_STATIC_ANALYSIS = 'quality_static_analysis';
    public const FAMILY_SECURITY_SUPPLY_CHAIN = 'security_supply_chain';
    public const FAMILY_API_TESTING_FRONTEND_ARCH = 'api_testing_frontend_architecture';
    public const FAMILY_EXTERNAL_AGENTS = 'external_agents';

    /** Promotion verdicts (closed set). */
    public const VERDICT_OPERATIONAL = 'operational';
    public const VERDICT_CATALOGED = 'cataloged';

    /**
     * Family id -> {tier, members}. `members` are normalized tool keys (lowercase,
     * '-'/'_'/space/'.'/'/' collapsed) so "ast-grep", "ast_grep" and "AST grep" all
     * match the same catalog entry. Members listed exactly as the doc enumerates them.
     *
     * @var array<string,array{tier:string,members:list<string>}>
     */
    private const CATALOG = [
        self::FAMILY_CODE_SEMANTICS => [
            'tier' => self::TIER_P0,
            'members' => [
                'serena', 'lsp', 'mcp', 'treesitter', 'astgrep', 'universalctags', 'idebridges',
            ],
        ],
        self::FAMILY_QUALITY_STATIC_ANALYSIS => [
            'tier' => self::TIER_P0,
            'members' => [
                'phpstan', 'psalm', 'psalmtaint', 'pint', 'biome', 'prettier',
                'eslint', 'typescript', 'rector', 'phpmd', 'phpcpd',
                'composerrequirechecker', 'composerunused', 'knip', 'tsprune',
            ],
        ],
        self::FAMILY_SECURITY_SUPPLY_CHAIN => [
            'tier' => self::TIER_P0,
            'members' => [
                'gitleaks', 'semgrep', 'codeql', 'osvscanner', 'trivy', 'grype', 'syft',
                'checkov', 'terrascan', 'kubelinter', 'kubescore', 'dockle',
                'scancode', 'ort', 'licensee',
            ],
        ],
        self::FAMILY_API_TESTING_FRONTEND_ARCH => [
            'tier' => self::TIER_P1,
            'members' => [
                'schemathesis', 'pact', 'prism', 'wiremock', 'bruno',
                'infection', 'stryker', 'fastcheck', 'hypothesis', 'coveragetools',
                'axecore', 'pa11y', 'lighthouseci', 'bundleanalyzers', 'pixelmatch',
                'deptrac', 'dependencycruiser', 'madge',
                'openrewrite', 'comby', 'jscodeshift', 'tsmorph',
            ],
        ],
        self::FAMILY_EXTERNAL_AGENTS => [
            'tier' => self::TIER_P2,
            'members' => [
                'aider', 'continue', 'openhands', 'serenamcpwrite', 'mcpwrite',
            ],
        ],
    ];

    /**
     * The seven Promotion-Rule requirements, in the doc's listed order. `safe_recipe`
     * is conditional: it is only required when the tool is executable (the doc states
     * "safe recipe WHEN executable"). All others are unconditional.
     *
     * @var list<string>
     */
    public const PROMOTION_REQUIREMENTS = [
        'registry_definition',
        'doctor_detection_state',
        'safe_recipe',
        'normalizer_or_generic_fallback',
        'authority_group_role',
        'gate_behavior',
        'tests',
    ];

    /** The requirement that only applies to executable tools. */
    public const CONDITIONAL_REQUIREMENT = 'safe_recipe';

    /**
     * Classify a cataloged tool into its priority tier and family, surfacing the
     * per-tier governance invariants the doc states.
     *
     * Code Semantics writes require sandbox/approval+evidence (reads are cheap).
     * External Agents must always run behind Atlas context, policy, worktree/sandbox,
     * tests, gates, Evidence Store and operator approval.
     *
     * @return array{
     *   schema:string,
     *   tool:string,
     *   recognized:bool,
     *   tier:?string,
     *   family:?string,
     *   reads_are_cheap:bool,
     *   writes_require_sandbox_approval_evidence:bool,
     *   must_run_behind_atlas:bool,
     *   requires_operator_approval:bool,
     *   reason:string
     * }
     */
    public function classifyTool(string $tool): array
    {
        $key = $this->normalizeToken($tool);
        if ($key === null) {
            throw new InvalidArgumentException('Tool name must not be empty.');
        }

        $family = null;
        $tier = null;
        foreach (self::CATALOG as $familyId => $spec) {
            if (in_array($key, $spec['members'], true)) {
                $family = $familyId;
                $tier = $spec['tier'];
                break;
            }
        }

        $recognized = $family !== null;
        $isCodeSemantics = $family === self::FAMILY_CODE_SEMANTICS;
        $isExternalAgents = $family === self::FAMILY_EXTERNAL_AGENTS;

        return [
            'schema' => self::SCHEMA,
            'tool' => trim($tool),
            'recognized' => $recognized,
            'tier' => $tier,
            'family' => $family,
            // "Reads are cheap" — true for the code-semantics family (read-first tools).
            'reads_are_cheap' => $isCodeSemantics,
            // Code Semantics: "Writes require sandbox/approval and evidence."
            'writes_require_sandbox_approval_evidence' => $isCodeSemantics,
            // External Agents: "Agents must run behind Atlas context, policy,
            // worktree/sandbox, tests, gates, Evidence Store and operator approval."
            'must_run_behind_atlas' => $isExternalAgents,
            'requires_operator_approval' => $isExternalAgents,
            'reason' => $this->classificationReason($recognized, $family),
        ];
    }

    /**
     * The required Promotion-Rule requirements for a tool of the given executability.
     * Non-executable tools drop the conditional `safe_recipe` requirement
     * ("safe recipe when executable"); everything else is always required.
     *
     * @return list<string>
     */
    public function requiredRequirementsFor(bool $executable): array
    {
        if ($executable) {
            return self::PROMOTION_REQUIREMENTS;
        }

        return array_values(array_filter(
            self::PROMOTION_REQUIREMENTS,
            static fn (string $req): bool => $req !== self::CONDITIONAL_REQUIREMENT,
        ));
    }

    /**
     * Decide whether a cataloged tool may be declared operational, applying the doc's
     * Promotion Rule: a tool is operational only AFTER every required requirement is
     * satisfied. Returns the verdict plus the exact missing requirements so a tool is
     * never declared operational before the catalog contract is genuinely met.
     *
     * The `tests` requirement is satisfied by EITHER a fake binary OR fixture output
     * (the doc: "tests with fake binary or fixture output"). It can be supplied as a
     * single bool, or implicitly via `has_fake_binary` / `has_fixture_output`.
     *
     * @param array{
     *   tool?:string,
     *   executable?:bool,
     *   registry_definition?:bool,
     *   doctor_detection_state?:bool,
     *   safe_recipe?:bool,
     *   normalizer?:bool,
     *   generic_fallback?:bool,
     *   normalizer_or_generic_fallback?:bool,
     *   authority_group_role?:bool,
     *   gate_behavior?:bool,
     *   tests?:bool,
     *   has_fake_binary?:bool,
     *   has_fixture_output?:bool
     * } $candidate
     * @return array<string,mixed>
     */
    public function evaluatePromotion(array $candidate): array
    {
        $executable = (bool) ($candidate['executable'] ?? false);
        $required = $this->requiredRequirementsFor($executable);

        // "normalizer OR explicit generic fallback" — either one satisfies the slot.
        $normalizerOrFallback = ($candidate['normalizer_or_generic_fallback'] ?? false) === true
            || ($candidate['normalizer'] ?? false) === true
            || ($candidate['generic_fallback'] ?? false) === true;

        // "tests with fake binary OR fixture output" — either path satisfies the slot.
        $tests = ($candidate['tests'] ?? false) === true
            || ($candidate['has_fake_binary'] ?? false) === true
            || ($candidate['has_fixture_output'] ?? false) === true;

        $satisfied = [
            'registry_definition' => ($candidate['registry_definition'] ?? false) === true,
            'doctor_detection_state' => ($candidate['doctor_detection_state'] ?? false) === true,
            'safe_recipe' => ($candidate['safe_recipe'] ?? false) === true,
            'normalizer_or_generic_fallback' => $normalizerOrFallback,
            'authority_group_role' => ($candidate['authority_group_role'] ?? false) === true,
            'gate_behavior' => ($candidate['gate_behavior'] ?? false) === true,
            'tests' => $tests,
        ];

        $missing = [];
        foreach ($required as $req) {
            if (($satisfied[$req] ?? false) !== true) {
                $missing[] = $req;
            }
        }

        $verdict = $missing === [] ? self::VERDICT_OPERATIONAL : self::VERDICT_CATALOGED;

        return [
            'schema' => self::SCHEMA,
            'tool' => isset($candidate['tool']) && is_string($candidate['tool'])
                ? trim($candidate['tool'])
                : null,
            'executable' => $executable,
            'verdict' => $verdict,
            'operational' => $verdict === self::VERDICT_OPERATIONAL,
            'required_requirements' => $required,
            'satisfied_requirements' => array_values(array_filter(
                $required,
                static fn (string $req): bool => ($satisfied[$req] ?? false) === true,
            )),
            'missing_requirements' => $missing,
            // Canonical decision: a tool family "enters as governed capability, not a
            // parallel flow" — operational always means governed-capability entry.
            'enters_as_governed_capability' => true,
            'reason' => $missing === []
                ? 'All required Promotion-Rule requirements are satisfied: tool may be promoted to operational as a governed capability.'
                : 'Promotion Rule not met; tool stays cataloged until satisfied: '.implode(', ', $missing),
        ];
    }

    /**
     * Convenience predicate: may this candidate be promoted to operational?
     *
     * @param array<string,mixed> $candidate
     */
    public function isOperational(array $candidate): bool
    {
        return $this->evaluatePromotion($candidate)['verdict'] === self::VERDICT_OPERATIONAL;
    }

    /**
     * Backlog ordering decision: the doc's canonical decision is "optional/local/
     * open-source first when possible". Given a candidate's posture, decide whether it
     * is a preferred-first intake or a deferred one, and why.
     *
     * @return array{
     *   schema:string,
     *   local:bool,
     *   open_source:bool,
     *   optional:bool,
     *   preferred_first:bool,
     *   intake:string,
     *   reason:string
     * }
     */
    public function backlogPriority(bool $local, bool $openSource, bool $optional): array
    {
        // "optional/local/open-source first when possible" — any of these postures
        // qualifies the tool for preferred-first intake.
        $preferredFirst = $local || $openSource || $optional;

        return [
            'schema' => self::SCHEMA,
            'local' => $local,
            'open_source' => $openSource,
            'optional' => $optional,
            'preferred_first' => $preferredFirst,
            'intake' => $preferredFirst ? 'preferred_first' : 'deferred',
            'reason' => $preferredFirst
                ? 'Optional/local/open-source posture: intake first per the catalog backlog decision.'
                : 'No optional/local/open-source posture: deferred behind preferred-first intake.',
        ];
    }

    /**
     * The full catalog as canonical reference data: every family with its tier and
     * normalized member keys.
     *
     * @return list<array{family:string,tier:string,members:list<string>}>
     */
    public function catalog(): array
    {
        $rows = [];
        foreach (self::CATALOG as $familyId => $spec) {
            $rows[] = [
                'family' => $familyId,
                'tier' => $spec['tier'],
                'members' => $spec['members'],
            ];
        }

        return $rows;
    }

    private function classificationReason(bool $recognized, ?string $family): string
    {
        if (! $recognized) {
            return 'Tool is not in the catalog roadmap; it has no governed family or tier yet.';
        }

        if ($family === self::FAMILY_CODE_SEMANTICS) {
            return 'P0 Code Semantics: reads are cheap; writes require sandbox/approval and evidence.';
        }

        if ($family === self::FAMILY_EXTERNAL_AGENTS) {
            return 'P2 External Agents: must run behind Atlas context, policy, worktree/sandbox, tests, gates, Evidence Store and operator approval.';
        }

        return 'Governed P0/P1 capability: enters the runtime as a governed tool, not a parallel flow.';
    }

    /**
     * Normalize a tool token for catalog membership: lowercase and collapse '-', '_',
     * space, '.' and '/' so "OSV-Scanner", "osv_scanner" and "osv scanner" all match.
     */
    private function normalizeToken(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return str_replace(['-', '_', ' ', '.', '/'], '', strtolower(trim($value)));
    }
}
