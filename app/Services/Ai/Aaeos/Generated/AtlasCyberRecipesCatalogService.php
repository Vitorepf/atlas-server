<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Generated;

/**
 * Atlas AI Cyber Recipes Catalog — pure, deterministic recipe admission decider.
 *
 * The catalog doc is the active index for cyber-security recipes proposed for the
 * canonical Super Tool Runtime. It does not stand up a parallel security executor;
 * it defines the CONTRACT a proposed recipe declaration must satisfy before it can
 * be promoted into the registry. This service enforces that contract as a single
 * verdict (`admit` | `reject`) plus the documented obligations. It never executes a
 * tool, connects to anything, touches a database or mutates state.
 *
 * Enforced contract (directly from the doc):
 *
 *   "Required Recipe Contract" — every promoted cyber recipe MUST declare all of:
 *     tool_slug, recipe_name, category, argv, dry_run_default, creates_evidence,
 *     blocking_capable, execution_tier, sandbox, privacy_level, task_type,
 *     authority_group; plus approvals when active exploitation, C2, distributed
 *     scan or external MCP is involved.
 *
 *   "Hard Safety Rules":
 *     - Defensive tools already in the registry are reused, not re-registered.
 *       (also "Anti-Patterns": recreating gitleaks/semgrep/trivy/osv-scanner/
 *       syft/checkov.) => re-registering a known defensive tool is a hard reject.
 *     - Offensive recipes default to `dry_run_default: true`. => an offensive
 *       recipe declaring dry_run_default=false is a hard reject.
 *     - Active exploit, C2, distributed scan and external offensive MCP require
 *       extra approval. => those categories must carry approval evidence.
 *     - Every run emits evidence through the canonical Evidence Ledger path. =>
 *       creates_evidence must be true.
 *     - Scope proof and refusal matrix run before execution. => surfaced as
 *       mandatory pre-execution obligations on every admitted recipe.
 *
 * @see docs/engineering-knowledge-base/cyber-security/recipes-catalog.md
 */
final class AtlasCyberRecipesCatalogService
{
    /** Stable receipt schema id this decider emits. */
    public const SCHEMA = 'atlas.cyber.recipes_catalog_admission.v1';

    /** Admission verdicts (closed set). */
    public const VERDICT_ADMIT = 'admit';
    public const VERDICT_REJECT = 'reject';

    /**
     * "Required Recipe Contract" — the fields every promoted cyber recipe must
     * declare, in doc order.
     *
     * @var list<string>
     */
    public const REQUIRED_FIELDS = [
        'tool_slug',
        'recipe_name',
        'category',
        'argv',
        'dry_run_default',
        'creates_evidence',
        'blocking_capable',
        'execution_tier',
        'sandbox',
        'privacy_level',
        'task_type',
        'authority_group',
    ];

    /**
     * Defensive / quality tools already in the canonical registry (from
     * `recipes-existing-tools.md` + the catalog "Anti-Patterns" list). A proposed
     * recipe whose tool_slug is one of these is duplication and is rejected — these
     * are reused, never re-registered. Stored normalized (lower, '-'/'_' collapsed).
     *
     * @var list<string>
     */
    public const REGISTERED_DEFENSIVE_TOOLS = [
        'gitleaks',
        'semgrep',
        'trivy',
        'osvscanner',   // matches both osv-scanner and osv_scanner
        'syft',
        'checkov',
        'phpstan',
        'typescript',
        'eslint',
        'biome',
        'hadolint',
    ];

    /**
     * Human-readable labels of the recipe categories that always require extra
     * approval (catalog "Hard Safety Rules" + "Required Recipe Contract" approvals
     * clause): active exploitation, C2, distributed scan, external MCP. Membership
     * is tested on the normalized form (see EXTRA_APPROVAL_CATEGORIES).
     *
     * @var list<string>
     */
    public const EXTRA_APPROVAL_CATEGORY_LABELS = [
        'active_exploit',
        'c2',
        'distributed_scan',
        'external_mcp',
    ];

    /**
     * Normalized ('-'/'_'/space collapsed, lowercased) forms of
     * EXTRA_APPROVAL_CATEGORY_LABELS, so "active_exploit", "active-exploit" and
     * "Active Exploit" all match.
     *
     * @var list<string>
     */
    public const EXTRA_APPROVAL_CATEGORIES = [
        'activeexploit',
        'c2',
        'distributedscan',
        'externalmcp',
    ];

    /**
     * Pre-execution obligations the doc mandates for every admitted recipe:
     * "Scope proof and refusal matrix run before execution" and evidence is
     * written through the canonical Evidence Ledger path.
     *
     * @var list<string>
     */
    public const PRE_EXECUTION_OBLIGATIONS = [
        'scope_proof',
        'refusal_matrix',
        'evidence_ledger',
    ];

    /**
     * Decide whether a proposed cyber recipe declaration may be admitted into the
     * Super Tool Runtime registry.
     *
     * @param array<string,mixed> $recipe
     *        tool_slug         : string
     *        recipe_name       : string
     *        category          : string   e.g. "recon", "active_exploit", "c2"
     *        argv              : array     argv schema (non-empty)
     *        dry_run_default   : bool
     *        creates_evidence  : bool
     *        blocking_capable  : bool
     *        execution_tier    : string|int
     *        sandbox           : string
     *        privacy_level     : string
     *        task_type         : string
     *        authority_group   : string
     *        offensive         : bool      true when this is an offensive recipe
     *        approval_recorded : bool      extra-approval evidence (when required)
     *
     * @return array<string,mixed> the admission receipt
     */
    public function evaluateRecipe(array $recipe): array
    {
        $violations = [];

        // 1. Required Recipe Contract — every field present and non-empty.
        $missingFields = [];
        foreach (self::REQUIRED_FIELDS as $field) {
            if (! $this->isDeclared($recipe, $field)) {
                $missingFields[] = $field;
            }
        }
        if ($missingFields !== []) {
            $violations[] = $this->violation(
                'missing_required_fields',
                'Recipe must declare every field of the Required Recipe Contract; missing: '.implode(', ', $missingFields),
                ['missing' => $missingFields],
            );
        }

        $toolSlug = $this->str($recipe['tool_slug'] ?? null);
        $category = $this->str($recipe['category'] ?? null);
        $categoryNormalized = $this->normalizeToken($recipe['category'] ?? null);
        $offensive = (bool) ($recipe['offensive'] ?? false);

        // 2. Anti-duplication: a defensive tool already in the registry is reused,
        //    not re-registered.
        if ($toolSlug !== null && in_array($this->normalizeToken($toolSlug), self::REGISTERED_DEFENSIVE_TOOLS, true)) {
            $violations[] = $this->violation(
                'reregisters_existing_defensive_tool',
                "Tool '{$toolSlug}' is already in the canonical registry and must be reused, not re-registered as a new recipe.",
            );
        }

        // 3. Evidence is mandatory: every run emits Evidence Ledger evidence.
        if (array_key_exists('creates_evidence', $recipe) && $recipe['creates_evidence'] !== true) {
            $violations[] = $this->violation(
                'creates_evidence_must_be_true',
                'Every cyber recipe run must emit evidence through the canonical Evidence Ledger path (creates_evidence must be true).',
            );
        }

        // 4. Offensive recipes default to dry_run_default: true.
        if ($offensive && array_key_exists('dry_run_default', $recipe) && $recipe['dry_run_default'] !== true) {
            $violations[] = $this->violation(
                'offensive_requires_dry_run_default',
                'Offensive recipes must default to dry_run_default: true.',
            );
        }

        // 5. Extra-approval categories must carry approval evidence.
        $needsExtraApproval = $categoryNormalized !== null
            && in_array($categoryNormalized, self::EXTRA_APPROVAL_CATEGORIES, true);
        if ($needsExtraApproval && ($recipe['approval_recorded'] ?? false) !== true) {
            $violations[] = $this->violation(
                'extra_approval_required',
                "Category '{$category}' (active exploit, C2, distributed scan or external MCP) requires extra approval before promotion.",
            );
        }

        $verdict = $violations === [] ? self::VERDICT_ADMIT : self::VERDICT_REJECT;

        return [
            'schema' => self::SCHEMA,
            'verdict' => $verdict,
            'admitted' => $verdict === self::VERDICT_ADMIT,
            'tool_slug' => $toolSlug,
            'recipe_name' => $this->str($recipe['recipe_name'] ?? null),
            'category' => $category,
            'offensive' => $offensive,
            'requires_extra_approval' => $needsExtraApproval,
            'missing_fields' => $missingFields,
            'pre_execution_obligations' => self::PRE_EXECUTION_OBLIGATIONS,
            'violations' => $violations,
        ];
    }

    /**
     * Convenience predicate: is this recipe admissible into the registry?
     *
     * @param array<string,mixed> $recipe
     */
    public function isAdmissible(array $recipe): bool
    {
        return $this->evaluateRecipe($recipe)['verdict'] === self::VERDICT_ADMIT;
    }

    /**
     * Whether a tool_slug names a defensive tool already in the canonical registry
     * (and therefore must be reused, not re-registered).
     */
    public function isExistingDefensiveTool(string $toolSlug): bool
    {
        return in_array($this->normalizeToken($toolSlug), self::REGISTERED_DEFENSIVE_TOOLS, true);
    }

    /**
     * Whether a category always requires extra approval per the Hard Safety Rules.
     */
    public function categoryRequiresExtraApproval(string $category): bool
    {
        return in_array($this->normalizeToken($category), self::EXTRA_APPROVAL_CATEGORIES, true);
    }

    /**
     * A field counts as declared when the key exists and the value is non-empty.
     * `false` is a valid declared boolean (e.g. blocking_capable=false).
     *
     * @param array<string,mixed> $recipe
     */
    private function isDeclared(array $recipe, string $field): bool
    {
        if (! array_key_exists($field, $recipe)) {
            return false;
        }

        $value = $recipe[$field];

        if (is_bool($value)) {
            return true;
        }
        if (is_string($value)) {
            return trim($value) !== '';
        }
        if (is_array($value)) {
            return $value !== [];
        }

        return $value !== null;
    }

    /**
     * @return array{code:string,message:string,detail?:array<string,mixed>}
     */
    private function violation(string $code, string $message, ?array $detail = null): array
    {
        $row = ['code' => $code, 'message' => $message];
        if ($detail !== null) {
            $row['detail'] = $detail;
        }

        return $row;
    }

    /**
     * Normalize a token for set membership: lowercase and collapse '-' and '_' so
     * "osv-scanner", "osv_scanner", "Active Exploit" and "active_exploit" compare
     * equal to their canonical form.
     */
    private function normalizeToken(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $token = strtolower(trim($value));

        return str_replace(['-', '_', ' '], '', $token);
    }

    private function str(mixed $v): ?string
    {
        if (is_string($v) && trim($v) !== '') {
            return trim($v);
        }

        return null;
    }
}
