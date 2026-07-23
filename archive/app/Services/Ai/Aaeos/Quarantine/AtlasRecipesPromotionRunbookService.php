<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Quarantine;

/**
 * Atlas AI Cyber Recipes Promotion Runbook — pure, deterministic promotion gate.
 *
 * The runbook is the operational checklist for promoting an individual cyber
 * recipe into the canonical Super Tool Runtime. It does NOT execute a tool, touch
 * a registry, open a sandbox or write to a database. It defines the CONTRACT a
 * promotion attempt must satisfy and renders one verdict (`promote` | `hold`) plus
 * the documented obligations, so a recipe is only declared "runtime-promoted" when
 * every documented step, gate and Definition-of-Done item is genuinely green.
 *
 * Enforced contract (directly from the doc):
 *
 *   "Promotion Steps" (1..8): every step must be completed before promotion —
 *     1 not_already_registered  (the recipe's tool must not already exist)
 *     2 registry_entry          (migration/seeder entry for atlas_tool_definitions)
 *     3 recipe_metadata         (dry-run, sandbox, privacy, task type, authority group)
 *     4 wrapper_class           (wrapper implemented under the Tool Runtime service area)
 *     5 sandbox_profile         (sandbox profile added)
 *     6 tests                   (argv rendering, refusal/scope gates, evidence emission)
 *     7 doctor_and_dry_run      (tool doctor + dry-run executed clean)
 *     8 docs_and_registry_refs  (docs + registry references updated)
 *
 *   "Required Gates" — each gate must hold:
 *     - scope_proof      : target matches approved scope.
 *     - refusal_matrix   : unsafe/unauthorized activity blocks before execution.
 *     - decision_receipt : runtime refuses without a valid receipt.
 *     - sandbox          : offensive recipes require a sandbox.  (conditional)
 *     - evidence         : run creates normalized evidence + ledger event.
 *     - approval         : active exploit, C2, distributed scan and external MCP
 *                          require extra approval.                (conditional)
 *
 *   "Definition Of Done" (7 items): registry entry exists; wrapper renders argv
 *     safely; scope/refusal tests pass; sandbox profile exists; evidence is
 *     normalized; docs link to the recipe owner; NO direct provider or raw MCP
 *     bypass exists. The last item is a hard safety invariant: any provider/raw-MCP
 *     bypass forces `hold` regardless of everything else.
 *
 * @see docs/engineering-knowledge-base/cyber-security/recipes-promotion-runbook.md
 */
final class AtlasRecipesPromotionRunbookService
{
    /** Stable receipt schema id this gate emits. */
    public const SCHEMA = 'atlas.cyber.recipes_promotion_runbook.v1';

    /** Promotion verdicts (closed set). */
    public const VERDICT_PROMOTE = 'promote';
    public const VERDICT_HOLD = 'hold';

    /**
     * The eight "Promotion Steps", in doc order. Every step is mandatory before a
     * recipe can be promoted into the Super Tool Runtime.
     *
     * @var list<string>
     */
    public const PROMOTION_STEPS = [
        'not_already_registered',
        'registry_entry',
        'recipe_metadata',
        'wrapper_class',
        'sandbox_profile',
        'tests',
        'doctor_and_dry_run',
        'docs_and_registry_refs',
    ];

    /**
     * Gates that always apply, from the "Required Gates" table.
     *
     * @var list<string>
     */
    public const UNCONDITIONAL_GATES = [
        'scope_proof',
        'refusal_matrix',
        'decision_receipt',
        'evidence',
    ];

    /**
     * The seven "Definition Of Done" items, in doc order. `no_provider_or_mcp_bypass`
     * is a hard safety invariant (see DOD_HARD_INVARIANT).
     *
     * @var list<string>
     */
    public const DEFINITION_OF_DONE = [
        'registry_entry_exists',
        'wrapper_renders_argv_safely',
        'scope_refusal_tests_pass',
        'sandbox_profile_exists',
        'evidence_normalized',
        'docs_link_owner',
        'no_provider_or_mcp_bypass',
    ];

    /**
     * Definition-of-Done item that is a non-negotiable safety invariant: if a direct
     * provider call or raw MCP bypass exists, promotion is held no matter what.
     */
    public const DOD_HARD_INVARIANT = 'no_provider_or_mcp_bypass';

    /**
     * Normalized ('-'/'_'/space collapsed, lowercased) recipe categories that always
     * require the conditional `sandbox` + `approval` gates: active exploitation, C2,
     * distributed scan, external (offensive) MCP.
     *
     * @var list<string>
     */
    public const HIGH_RISK_CATEGORIES = [
        'activeexploit',
        'c2',
        'distributedscan',
        'externalmcp',
    ];

    /**
     * Decide whether a cyber recipe promotion attempt may be promoted into the
     * canonical Super Tool Runtime, or must be held.
     *
     * @param array<string,mixed> $attempt
     *        category       : string  recipe category, e.g. "recon", "active_exploit"
     *        offensive      : bool    true when this is an offensive recipe
     *        steps          : array<string,bool>  completion flag per Promotion Step
     *        gates          : array<string,bool>  pass flag per Required Gate
     *        definition_of_done : array<string,bool>  satisfaction flag per DoD item
     *
     * @return array<string,mixed> the promotion receipt
     */
    public function evaluatePromotion(array $attempt): array
    {
        $steps = $this->boolMap($attempt['steps'] ?? []);
        $gates = $this->boolMap($attempt['gates'] ?? []);
        $dod = $this->boolMap($attempt['definition_of_done'] ?? []);

        $offensive = (bool) ($attempt['offensive'] ?? false);
        $categoryNormalized = $this->normalizeToken($attempt['category'] ?? null);
        $highRisk = $categoryNormalized !== null
            && in_array($categoryNormalized, self::HIGH_RISK_CATEGORIES, true);

        // Offensive OR high-risk recipes activate the conditional sandbox + approval
        // gates ("Offensive recipes require sandbox", "...require extra approval").
        $sandboxRequired = $offensive || $highRisk;
        $approvalRequired = $highRisk;

        $requiredGates = self::UNCONDITIONAL_GATES;
        if ($sandboxRequired) {
            $requiredGates[] = 'sandbox';
        }
        if ($approvalRequired) {
            $requiredGates[] = 'approval';
        }

        $violations = [];

        // 1. Every Promotion Step must be completed.
        $incompleteSteps = $this->unmet(self::PROMOTION_STEPS, $steps);
        if ($incompleteSteps !== []) {
            $violations[] = $this->violation(
                'promotion_steps_incomplete',
                'All eight Promotion Steps must be completed before promotion; incomplete: '.implode(', ', $incompleteSteps),
                ['incomplete' => $incompleteSteps],
            );
        }

        // 2. Every applicable Required Gate must hold.
        $failedGates = $this->unmet($requiredGates, $gates);
        if ($failedGates !== []) {
            $violations[] = $this->violation(
                'required_gates_not_green',
                'Every applicable Required Gate must be green; failing: '.implode(', ', $failedGates),
                ['failing' => $failedGates],
            );
        }

        // 3. Every Definition-of-Done item must be satisfied.
        $openDod = $this->unmet(self::DEFINITION_OF_DONE, $dod);
        if ($openDod !== []) {
            $violations[] = $this->violation(
                'definition_of_done_incomplete',
                'Every Definition Of Done item must be satisfied; open: '.implode(', ', $openDod),
                ['open' => $openDod],
            );
        }

        // 4. Hard safety invariant: no direct provider or raw MCP bypass may exist.
        //    Surfaced as its own dedicated violation (it is the gravest failure) in
        //    addition to being counted in the DoD check above.
        $bypassClear = ($dod[self::DOD_HARD_INVARIANT] ?? false) === true;
        if (! $bypassClear) {
            $violations[] = $this->violation(
                'provider_or_mcp_bypass_present',
                'A direct provider call or raw MCP bypass blocks promotion unconditionally (Definition Of Done: no provider/raw-MCP bypass).',
            );
        }

        $verdict = $violations === [] ? self::VERDICT_PROMOTE : self::VERDICT_HOLD;

        return [
            'schema' => self::SCHEMA,
            'verdict' => $verdict,
            'promoted' => $verdict === self::VERDICT_PROMOTE,
            'category' => $this->str($attempt['category'] ?? null),
            'offensive' => $offensive,
            'high_risk' => $highRisk,
            'sandbox_required' => $sandboxRequired,
            'approval_required' => $approvalRequired,
            'required_gates' => $requiredGates,
            'incomplete_steps' => $incompleteSteps,
            'failed_gates' => $failedGates,
            'open_definition_of_done' => $openDod,
            'violations' => $violations,
        ];
    }

    /**
     * Convenience predicate: may this attempt be promoted into runtime?
     *
     * @param array<string,mixed> $attempt
     */
    public function isPromotable(array $attempt): bool
    {
        return $this->evaluatePromotion($attempt)['verdict'] === self::VERDICT_PROMOTE;
    }

    /**
     * The gate set that applies to a recipe of the given category/offensive posture.
     * Offensive or high-risk recipes additionally require the `sandbox` gate;
     * high-risk categories additionally require the `approval` gate.
     *
     * @return list<string>
     */
    public function requiredGatesFor(?string $category, bool $offensive): array
    {
        $normalized = $this->normalizeToken($category);
        $highRisk = $normalized !== null && in_array($normalized, self::HIGH_RISK_CATEGORIES, true);

        $gates = self::UNCONDITIONAL_GATES;
        if ($offensive || $highRisk) {
            $gates[] = 'sandbox';
        }
        if ($highRisk) {
            $gates[] = 'approval';
        }

        return $gates;
    }

    /**
     * Whether a category is a high-risk category (active exploit / C2 / distributed
     * scan / external MCP) that demands the conditional sandbox + approval gates.
     */
    public function isHighRiskCategory(?string $category): bool
    {
        $normalized = $this->normalizeToken($category);

        return $normalized !== null && in_array($normalized, self::HIGH_RISK_CATEGORIES, true);
    }

    /**
     * Keys in $required whose value in $flags is not strictly true (missing keys
     * count as unmet), preserving $required order.
     *
     * @param list<string>          $required
     * @param array<string,bool>    $flags
     * @return list<string>
     */
    private function unmet(array $required, array $flags): array
    {
        $unmet = [];
        foreach ($required as $key) {
            if (($flags[$key] ?? false) !== true) {
                $unmet[] = $key;
            }
        }

        return $unmet;
    }

    /**
     * Coerce an arbitrary array into a string=>bool map; non-bool truthy values are
     * NOT silently treated as satisfied — only a strict `true` counts.
     *
     * @param mixed $value
     * @return array<string,bool>
     */
    private function boolMap(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $map = [];
        foreach ($value as $key => $flag) {
            if (is_string($key)) {
                $map[$key] = $flag === true;
            }
        }

        return $map;
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
     * Normalize a token for set membership: lowercase and collapse '-', '_' and
     * space so "active-exploit", "active_exploit" and "Active Exploit" all match.
     */
    private function normalizeToken(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return str_replace(['-', '_', ' '], '', strtolower(trim($value)));
    }

    private function str(mixed $v): ?string
    {
        if (is_string($v) && trim($v) !== '') {
            return trim($v);
        }

        return null;
    }
}
