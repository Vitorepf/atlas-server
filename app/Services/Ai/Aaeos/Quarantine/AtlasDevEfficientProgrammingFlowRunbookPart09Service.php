<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Quarantine;

/**
 * Atlas Dev Efficient Programming Flow Runbook v1 · Parte 9 — operator decider.
 *
 * Pure, deterministic runtime for the three operator-facing decision contracts
 * the runbook slice declares verbatim in sections 15.1.10, 15.1.12 and 15.1.15.
 * The doc is prose; this service is the missing piece that turns its numbered
 * rules into a verdict callers can enforce. It NEVER executes commands, mints a
 * token, routes, calls a provider or touches a database — every method is a pure
 * function over a candidate payload.
 *
 * Anti-duplication boundary: this is NOT
 * {@see \App\Services\Ai\Programming\AtlasDev\Gate\VerificationGate}. That class
 * is the runtime *executor* — it actually runs `validation_commands`, talks to
 * storage and the provider DTOs. Part09 only encodes the runbook's *operator
 * gating rules* (is this release releasable? does this project slug resolve? is
 * this visual-QA verdict legal?), which have no decider today. The two are
 * complementary, not overlapping.
 *
 * Rules enforced:
 *
 *  15.1.10 Release checklist — a release is releasable iff every MANDATORY item
 *    is confirmed. The doc lists nine checklist items; the four that gate real
 *    safety (APP_KEY, migrations applied, readiness=passed, no /Users/ leak in
 *    the Plan smoke) are mandatory. plan_enabled must be on AND validated before
 *    run_enabled (sequencing rule). Returns the exact unmet items.
 *
 *  15.1.12 Project profiles — the Desktop surface points at a workspace ONLY via
 *    project slug. Resolution: a slug must exist in profiles[] and its
 *    workspace_path must exist on the host. A slug with a missing/inexistent
 *    workspace_path makes Plan return 422 BEFORE minting a token (no side
 *    effect, no receipt). When the Desktop payload omits a slug, the
 *    ATLAS_CODE_DEFAULT_PROJECT fallback slug is used. Non-Desktop surfaces may
 *    pass an absolute path directly and skip slug resolution.
 *
 *  15.1.15 Visual QA — Atlas Dev fast-path does NOT run Browser/Playwright
 *    inside the executor. With no visual tooling on the host the verdict is
 *    declared generic_no_test and the gate may settle as no_patch_needed or
 *    needs_review, but NEVER as a silent `passed`. Frontend changes that depend
 *    on visual QA escalate to at least R3 by default and require human review
 *    before `completed`.
 *
 * @see docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-runbook-v1-part-09.md
 */
final class AtlasDevEfficientProgrammingFlowRunbookPart09Service
{
    /** Stable schema id for the verdicts this decider emits. */
    public const SCHEMA = 'atlas.dev.flow_runbook_part09_gate.v1';

    // ---------------------------------------------------------------------
    // 15.1.10 Release checklist
    // ---------------------------------------------------------------------

    /**
     * Doc 15.1.10: the checklist items that MUST be confirmed before a release
     * is releasable. Subset of the nine listed; these four gate real safety.
     *
     * @var list<string>
     */
    public const MANDATORY_CHECKLIST_ITEMS = [
        'app_key_base64_min_32_bytes',
        'migrations_applied',
        'readiness_passed',
        'smoke_no_absolute_path_leak',
    ];

    /**
     * Doc 15.1.10: the full nine-item checklist surface (mandatory + advisory),
     * preserved in doc order so callers can render the whole list.
     *
     * @var list<string>
     */
    public const CHECKLIST_ITEMS = [
        'app_key_base64_min_32_bytes',
        'migrations_applied',
        'receipts_dir_writable',
        'plan_enabled_validated_before_run_enabled',
        'readiness_passed',
        'smoke_no_absolute_path_leak',
        'show_returns_workspace_label_no_absolute_path',
        'run_rejects_truthy_string_hash_mismatch_token_reuse',
        'stream_emits_stream_closed_then_rest_fallback',
    ];

    // ---------------------------------------------------------------------
    // 15.1.12 Project profiles
    // ---------------------------------------------------------------------

    /** Doc 15.1.12: only this surface resolves a workspace via project slug. */
    public const DESKTOP_SURFACE_ID = 'atlas_desktop_ai';

    /** Doc 15.1.12: HTTP status Plan returns for an unresolvable slug (pre-token). */
    public const SLUG_UNRESOLVED_HTTP_STATUS = 422;

    // ---------------------------------------------------------------------
    // 15.1.15 Visual QA
    // ---------------------------------------------------------------------

    /** Doc 15.1.15: the profile declared when no Browser/Playwright is available. */
    public const VISUAL_PROFILE_GENERIC_NO_TEST = 'generic_no_test';

    /** Doc 15.1.15: legal gate verdicts for the no-visual-tool path. */
    public const VISUAL_VERDICT_NO_PATCH_NEEDED = 'no_patch_needed';

    public const VISUAL_VERDICT_NEEDS_REVIEW = 'needs_review';

    /** Doc 15.1.15: the verdict that is NEVER allowed silently for generic_no_test. */
    public const VISUAL_VERDICT_FORBIDDEN_SILENT = 'passed';

    /** Doc 15.1.15: minimum risk floor a visual-dependent frontend change escalates to. */
    public const FRONTEND_VISUAL_RISK_FLOOR = 'R3';

    /** Ordered risk ladder (R0..R5 -> 0..5) so R3 == index 3. */
    private const RISK_LEVELS = ['R0', 'R1', 'R2', 'R3', 'R4', 'R5'];

    /**
     * Decide whether a release is releasable from the 15.1.10 checklist state.
     *
     * @param  array<string,bool>  $confirmed  item slug => confirmed?
     * @return array{
     *     schema:string,
     *     section:string,
     *     releasable:bool,
     *     unmet_mandatory:list<string>,
     *     sequencing_violation:bool,
     *     reason:?string
     * }
     */
    public function evaluateReleaseChecklist(array $confirmed): array
    {
        $unmet = [];
        foreach (self::MANDATORY_CHECKLIST_ITEMS as $item) {
            if (($confirmed[$item] ?? false) !== true) {
                $unmet[] = $item;
            }
        }

        // Doc 15.1.10: plan_enabled must be on AND validated before run_enabled.
        // We model the unmet sequencing item as its own flag so the operator
        // sees the ordering failure distinctly from a plain missing checkbox.
        $sequencingItem = 'plan_enabled_validated_before_run_enabled';
        $runEnabled = ($confirmed['run_enabled'] ?? false) === true;
        $sequencingOk = ($confirmed[$sequencingItem] ?? false) === true;
        $sequencingViolation = $runEnabled && ! $sequencingOk;

        $releasable = $unmet === [] && ! $sequencingViolation;

        $reason = null;
        if ($sequencingViolation) {
            $reason = 'run_enabled_set_before_plan_enabled_validated';
        } elseif ($unmet !== []) {
            $reason = 'mandatory_checklist_items_unmet';
        }

        return [
            'schema' => self::SCHEMA,
            'section' => '15.1.10',
            'releasable' => $releasable,
            'unmet_mandatory' => array_values($unmet),
            'sequencing_violation' => $sequencingViolation,
            'reason' => $reason,
        ];
    }

    /**
     * Resolve a workspace for a Plan request per doc 15.1.12.
     *
     * Desktop surface: must carry a slug (explicit or via default fallback) that
     * exists in profiles[] with a workspace_path that exists on the host.
     * Otherwise Plan returns 422 BEFORE minting a token (mints_token=false,
     * side_effect=false). Non-Desktop surfaces may pass an absolute workspace
     * path directly.
     *
     * @param  array{
     *     surface_id?:string,
     *     project_slug?:?string,
     *     workspace?:?string
     * }  $request
     * @param  array<string,array{workspace_path:string,workspace_exists:bool}>  $profiles  slug => profile
     * @param  ?string  $defaultProjectSlug  ATLAS_CODE_DEFAULT_PROJECT fallback
     * @return array{
     *     schema:string,
     *     section:string,
     *     resolved:bool,
     *     workspace_path:?string,
     *     used_slug:?string,
     *     used_default_fallback:bool,
     *     mints_token:bool,
     *     side_effect:bool,
     *     http_status:int,
     *     error:?string
     * }
     */
    public function resolveProjectWorkspace(
        array $request,
        array $profiles,
        ?string $defaultProjectSlug = null,
    ): array {
        $surfaceId = (string) ($request['surface_id'] ?? '');

        // Non-Desktop surfaces: an absolute workspace path bypasses slug
        // resolution entirely (doc 15.1.12 first paragraph).
        if ($surfaceId !== self::DESKTOP_SURFACE_ID) {
            $workspace = (string) ($request['workspace'] ?? '');
            if ($this->isAbsolutePath($workspace)) {
                return $this->resolution(true, $workspace, null, false, 200, null);
            }

            return $this->resolution(false, null, null, false, self::SLUG_UNRESOLVED_HTTP_STATUS, 'workspace_path_not_absolute');
        }

        // Desktop: slug is the only pointer. Fall back to the default slug when
        // the payload omits one.
        $requestedSlug = $request['project_slug'] ?? null;
        $usedDefault = false;
        if ($requestedSlug === null || $requestedSlug === '') {
            $requestedSlug = $defaultProjectSlug;
            $usedDefault = $requestedSlug !== null && $requestedSlug !== '';
        }

        if ($requestedSlug === null || $requestedSlug === '') {
            return $this->resolution(false, null, null, $usedDefault, self::SLUG_UNRESOLVED_HTTP_STATUS, 'no_project_slug_and_no_default');
        }

        $profile = $profiles[$requestedSlug] ?? null;
        if ($profile === null) {
            return $this->resolution(false, null, $requestedSlug, $usedDefault, self::SLUG_UNRESOLVED_HTTP_STATUS, 'unknown_project_slug');
        }

        // Doc 15.1.12: slug whose workspace_path does not exist on host -> 422
        // before minting token, no side effect.
        if (($profile['workspace_exists'] ?? false) !== true) {
            return $this->resolution(false, null, $requestedSlug, $usedDefault, self::SLUG_UNRESOLVED_HTTP_STATUS, 'workspace_path_inexistent');
        }

        return $this->resolution(true, (string) $profile['workspace_path'], $requestedSlug, $usedDefault, 200, null);
    }

    /**
     * Decide the legal visual-QA verdict per doc 15.1.15.
     *
     * @param  array{
     *     is_frontend?:bool,
     *     visual_qa_required?:bool,
     *     browser_tooling_available?:bool,
     *     proposed_verdict?:?string,
     *     declared_risk_level?:?string
     * }  $input
     * @return array{
     *     schema:string,
     *     section:string,
     *     verification_profile:?string,
     *     allowed_verdicts:list<string>,
     *     verdict:string,
     *     requires_human_review:bool,
     *     effective_risk_level:string,
     *     silent_pass_blocked:bool
     * }
     */
    public function evaluateVisualQa(array $input): array
    {
        $isFrontend = (bool) ($input['is_frontend'] ?? false);
        $visualRequired = (bool) ($input['visual_qa_required'] ?? false);
        $browserAvailable = (bool) ($input['browser_tooling_available'] ?? false);
        $proposed = $input['proposed_verdict'] ?? null;
        $declaredRisk = (string) ($input['declared_risk_level'] ?? 'R0');

        // Profile is generic_no_test exactly when visual tooling is absent.
        $profile = $browserAvailable ? null : self::VISUAL_PROFILE_GENERIC_NO_TEST;

        $allowed = [self::VISUAL_VERDICT_NO_PATCH_NEEDED, self::VISUAL_VERDICT_NEEDS_REVIEW];

        // Doc 15.1.15: a frontend change that depends on visual QA escalates to
        // at least R3 and requires human review before `completed`.
        $effectiveRisk = $declaredRisk;
        $requiresHumanReview = false;
        if ($isFrontend && $visualRequired) {
            $effectiveRisk = $this->maxRisk($declaredRisk, self::FRONTEND_VISUAL_RISK_FLOOR);
            $requiresHumanReview = true;
        }

        // When there is no browser tooling, a `passed` verdict can NEVER be
        // settled silently — it is downgraded to needs_review. With tooling
        // present, the proposed verdict (if any legal one) is honored.
        $silentPassBlocked = false;
        if ($profile === self::VISUAL_PROFILE_GENERIC_NO_TEST) {
            if ($proposed === self::VISUAL_VERDICT_FORBIDDEN_SILENT) {
                $verdict = self::VISUAL_VERDICT_NEEDS_REVIEW;
                $silentPassBlocked = true;
            } elseif (in_array($proposed, $allowed, true)) {
                $verdict = (string) $proposed;
            } else {
                // No legal proposal -> conservative default.
                $verdict = self::VISUAL_VERDICT_NEEDS_REVIEW;
            }
        } else {
            // Tooling present: honor a legal proposed verdict, else needs_review.
            $verdict = in_array($proposed, [...$allowed, self::VISUAL_VERDICT_FORBIDDEN_SILENT], true)
                ? (string) $proposed
                : self::VISUAL_VERDICT_NEEDS_REVIEW;
        }

        // A visual-required frontend change is never silently complete: force
        // needs_review even if the path otherwise pointed at no_patch_needed.
        if ($requiresHumanReview && $verdict === self::VISUAL_VERDICT_NO_PATCH_NEEDED) {
            $verdict = self::VISUAL_VERDICT_NEEDS_REVIEW;
        }

        return [
            'schema' => self::SCHEMA,
            'section' => '15.1.15',
            'verification_profile' => $profile,
            'allowed_verdicts' => $allowed,
            'verdict' => $verdict,
            'requires_human_review' => $requiresHumanReview,
            'effective_risk_level' => $effectiveRisk,
            'silent_pass_blocked' => $silentPassBlocked,
        ];
    }

    /**
     * Stable contract manifest for callers/tests: the enumerations and floors
     * this decider pins from doc 15.1.10 / 15.1.12 / 15.1.15.
     *
     * @return array{
     *     schema:string,
     *     mandatory_checklist_items:list<string>,
     *     checklist_items:list<string>,
     *     desktop_surface_id:string,
     *     slug_unresolved_http_status:int,
     *     visual_profile_generic_no_test:string,
     *     visual_allowed_verdicts:list<string>,
     *     visual_forbidden_silent_verdict:string,
     *     frontend_visual_risk_floor:string
     * }
     */
    public function manifest(): array
    {
        return [
            'schema' => self::SCHEMA,
            'mandatory_checklist_items' => self::MANDATORY_CHECKLIST_ITEMS,
            'checklist_items' => self::CHECKLIST_ITEMS,
            'desktop_surface_id' => self::DESKTOP_SURFACE_ID,
            'slug_unresolved_http_status' => self::SLUG_UNRESOLVED_HTTP_STATUS,
            'visual_profile_generic_no_test' => self::VISUAL_PROFILE_GENERIC_NO_TEST,
            'visual_allowed_verdicts' => [
                self::VISUAL_VERDICT_NO_PATCH_NEEDED,
                self::VISUAL_VERDICT_NEEDS_REVIEW,
            ],
            'visual_forbidden_silent_verdict' => self::VISUAL_VERDICT_FORBIDDEN_SILENT,
            'frontend_visual_risk_floor' => self::FRONTEND_VISUAL_RISK_FLOOR,
        ];
    }

    // ---------------------------------------------------------------------
    // primitives
    // ---------------------------------------------------------------------

    /**
     * @return array{
     *     schema:string,
     *     section:string,
     *     resolved:bool,
     *     workspace_path:?string,
     *     used_slug:?string,
     *     used_default_fallback:bool,
     *     mints_token:bool,
     *     side_effect:bool,
     *     http_status:int,
     *     error:?string
     * }
     */
    private function resolution(
        bool $resolved,
        ?string $workspacePath,
        ?string $usedSlug,
        bool $usedDefault,
        int $httpStatus,
        ?string $error,
    ): array {
        return [
            'schema' => self::SCHEMA,
            'section' => '15.1.12',
            'resolved' => $resolved,
            'workspace_path' => $workspacePath,
            'used_slug' => $usedSlug,
            'used_default_fallback' => $usedDefault,
            // Doc 15.1.12: an unresolved slug returns 422 *before* minting a
            // token and produces no receipt / side effect.
            'mints_token' => $resolved,
            'side_effect' => $resolved,
            'http_status' => $httpStatus,
            'error' => $error,
        ];
    }

    private function isAbsolutePath(string $value): bool
    {
        return $value !== '' && str_starts_with($value, '/');
    }

    private function riskIndex(string $riskLevel): int
    {
        $index = array_search($riskLevel, self::RISK_LEVELS, true);

        return $index === false ? -1 : $index;
    }

    /** Returns whichever risk level is higher on the R0..R5 ladder. */
    private function maxRisk(string $a, string $b): string
    {
        return $this->riskIndex($a) >= $this->riskIndex($b) ? $a : $b;
    }
}
