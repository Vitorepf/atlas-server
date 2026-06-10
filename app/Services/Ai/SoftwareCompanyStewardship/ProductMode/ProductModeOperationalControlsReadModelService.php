<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\ProductMode;

use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\SoftwareCompanyStewardship\StewardshipStringListNormalizer;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Product Mode operational controls read model (AP-754).
 *
 * This is the control-plane projection for the Product Mode cockpit. It
 * exposes repo onboarding, autonomy tier, budget, risk, branch-review,
 * evidence-inspector and kill-switch state without mutating any repo or
 * granting execution authority.
 */
final class ProductModeOperationalControlsReadModelService
{
    public const SCHEMA = 'atlas.software_company.product_mode_operational_controls.v1';

    public const STATUS_READY = 'ready';

    public const STATUS_BLOCKED = 'blocked';

    public const STATUS_REVIEW = 'review_required';

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function project(string $areaId = 'agentic_engineering_os', string $portfolioId = 'atlas_software_company', array $input = []): array
    {
        $receiptPolicy = $this->controlReceiptPolicy($input);
        if (is_array($receiptPolicy['policy'] ?? null)) {
            $input = array_merge($input, $receiptPolicy['policy']);
        }

        $repo = $this->repoOnboarding($input);
        $controls = $this->safetyControls($input);
        $tiers = $this->autonomyTiers($repo, $controls, $input);
        $budget = $this->budgetPolicy($input);
        $branchReview = $this->branchReviewCenter($input);
        $evidence = $this->evidenceInspector($input);
        $risk = $this->riskPolicy($input);

        $blockers = StewardshipStringListNormalizer::uniqueMergedStrings(
            $repo['blockers'],
            $controls['blockers'],
            $tiers['blockers'],
            $budget['blockers'],
            $branchReview['blockers'],
            $evidence['blockers'],
            $risk['blockers'],
        );

        $status = $blockers !== [] ? self::STATUS_BLOCKED : self::STATUS_READY;
        if ($status === self::STATUS_READY && $this->reviewRequired($repo, $controls, $tiers, $budget, $branchReview, $evidence, $risk)) {
            $status = self::STATUS_REVIEW;
        }

        return $this->finalize([
            'schema_version' => self::SCHEMA,
            'status' => $status,
            'ap_contract' => 'AP-754',
            'area_id' => $this->nonEmpty($areaId, 'agentic_engineering_os'),
            'portfolio_id' => $this->nonEmpty($portfolioId, 'atlas_software_company'),
            'mode' => 'read_only_control_projection',
            'repo_onboarding' => $repo,
            'autonomy_tiers' => $tiers,
            'budget_policy' => $budget,
            'safety_controls' => $controls,
            'control_receipts' => $receiptPolicy,
            'branch_review_center' => $branchReview,
            'evidence_inspector' => $evidence,
            'risk_policy' => $risk,
            'operator_controls' => [
                'repo_onboarding_command' => 'php artisan atlas:software-company-stewardship product-mode-controls --repo=<repo> --json',
                'tier_review_command' => 'php artisan atlas:software-company-stewardship product-mode-controls --autonomy-tier=<0-6> --json',
                'kill_switch_command_anchor' => 'php artisan atlas:software-company-stewardship continuous-stewardship-loop --kill-switch --json',
                'budget_review_command' => 'php artisan atlas:software-company-stewardship product-mode-cockpit --json',
                'owner_sandbox_runtime_review_command' => 'php artisan atlas:software-company-stewardship owner-sandbox-runtime-run --execution-file=<ap758.jsonl> --owner-execution-id=<owner_execution_id> --runtime-command-receipt-file=<ap759-command-receipt.json> --json',
                'branch_review_owner' => 'Atlas Dev / Forge owner sandbox runtime review + owner runtime result review',
                'evidence_review_owner' => 'Evidence Certification Runtime + AP-740/AP-748/AP-759/AP-750',
                'risk_policy_owner' => 'Night Shift Product Mode + operator policy',
            ],
            'next_actions' => $this->nextActions($status, $repo, $controls, $tiers, $budget, $branchReview, $evidence, $risk),
            'blockers' => $blockers,
            'claim_policy' => $this->claimPolicy(),
        ]);
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function controlReceiptPolicy(array $input): array
    {
        $policy = is_array($input['control_policy'] ?? null) ? $input['control_policy'] : [];
        $innerPolicy = is_array($policy['policy'] ?? null) ? $policy['policy'] : [];
        $decisionIds = array_values(array_filter((array) ($policy['applied_decision_ids'] ?? []), 'is_string'));

        return [
            'schema_version' => 'atlas.software_company.product_mode_control_policy_projection.v1',
            'source_ap_contract' => (string) ($policy['source_ap_contract'] ?? 'AP-755'),
            'ledger_ap_contract' => (string) ($policy['ledger_ap_contract'] ?? 'AP-731'),
            'applied_receipt_count' => (int) ($policy['applied_receipt_count'] ?? count($decisionIds)),
            'applied_decision_ids' => $decisionIds,
            'policy' => $innerPolicy,
            'policy_hash' => (string) ($policy['policy_hash'] ?? 'sha256:'.MissionCanonicalHash::sha256($innerPolicy)),
            'status' => $innerPolicy === [] ? 'not_attached' : 'applied',
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function repoOnboarding(array $input): array
    {
        $repo = $this->nonEmpty((string) ($input['repo'] ?? $input['repository'] ?? 'atlas-server'), 'atlas-server');
        $status = $this->nonEmpty((string) ($input['repo_authorization_status'] ?? 'authorized_for_atlas_internal'), 'authorized_for_atlas_internal');
        $allowedRepos = array_values(array_filter((array) ($input['authorized_repositories'] ?? ['atlas-server']), 'is_string'));
        $authorized = in_array($status, ['authorized', 'authorized_for_atlas_internal'], true)
            && in_array($repo, $allowedRepos, true);

        $blockers = [];
        if (! $authorized) {
            $blockers[] = 'repository_not_authorized_for_product_mode';
        }

        return [
            'schema_version' => 'atlas.software_company.product_mode.repo_onboarding.v1',
            'repository' => $repo,
            'authorization_status' => $status,
            'authorization_scope' => $status === 'authorized_for_atlas_internal' ? 'atlas_internal_only' : 'operator_authorized_repository',
            'authorized_repositories' => $allowedRepos,
            'is_authorized' => $authorized,
            'allowed_operations' => $authorized ? [
                'read_repository_state',
                'project_findings',
                'draft_specs',
                'prepare_branch_handoff',
                'review_owner_results',
                'review_owner_sandbox_runtime_commands',
            ] : [],
            'forbidden_operations' => [
                'merge_without_operator',
                'deploy_without_operator',
                'touch_secrets',
                'destructive_change',
                'external_repo_mutation_without_onboarding',
            ],
            'required_evidence' => [
                'repo_authorization_receipt',
                'risk_policy',
                'budget_policy',
                'kill_switch_available',
            ],
            'blockers' => $blockers,
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function safetyControls(array $input): array
    {
        $killSwitch = (bool) ($input['kill_switch'] ?? false);
        $paused = (bool) ($input['paused'] ?? false);
        $lockActive = (bool) ($input['lock_active'] ?? false);
        $rateLimited = (bool) ($input['rate_limited'] ?? false);

        $blockers = [];
        if ($killSwitch) {
            $blockers[] = 'product_mode_kill_switch_active';
        }
        if ($paused) {
            $blockers[] = 'product_mode_paused';
        }
        if ($lockActive) {
            $blockers[] = 'product_mode_lock_active';
        }
        if ($rateLimited) {
            $blockers[] = 'product_mode_rate_limited';
        }

        return [
            'schema_version' => 'atlas.software_company.product_mode.safety_controls.v1',
            'kill_switch_active' => $killSwitch,
            'paused' => $paused,
            'lock_active' => $lockActive,
            'rate_limited' => $rateLimited,
            'pause_until' => $input['pause_until'] ?? null,
            'max_one_tick_per_invocation' => true,
            'requires_operator_for_irreversible_actions' => true,
            'blockers' => $blockers,
        ];
    }

    /**
     * @param  array<string,mixed>  $repo
     * @param  array<string,mixed>  $controls
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function autonomyTiers(array $repo, array $controls, array $input): array
    {
        $requested = max(0, min(6, (int) ($input['autonomy_tier'] ?? 2)));
        $maxAllowed = (int) ($input['max_allowed_autonomy_tier'] ?? 2);
        $maxAllowed = max(0, min(6, $maxAllowed));
        if (! (bool) ($repo['is_authorized'] ?? false) || (bool) ($controls['kill_switch_active'] ?? false)) {
            $maxAllowed = 0;
        }

        $blockers = [];
        if ($requested > $maxAllowed) {
            $blockers[] = 'requested_autonomy_tier_exceeds_current_policy';
        }

        return [
            'schema_version' => 'atlas.software_company.product_mode.autonomy_tiers.v1',
            'current_tier' => $requested,
            'max_allowed_tier' => $maxAllowed,
            'tier_status' => $requested <= $maxAllowed ? 'allowed' : 'blocked',
            'tiers' => [
                ['tier' => 0, 'name' => 'scan_only', 'allows_branch' => false, 'allows_provider' => false],
                ['tier' => 1, 'name' => 'scan_plus_findings', 'allows_branch' => false, 'allows_provider' => false],
                ['tier' => 2, 'name' => 'spec_drafts', 'allows_branch' => false, 'allows_provider' => false],
                ['tier' => 3, 'name' => 'branch_sandbox_handoff', 'allows_branch' => false, 'allows_provider' => false],
                ['tier' => 4, 'name' => 'low_risk_implementation', 'allows_branch' => false, 'allows_provider' => false],
                ['tier' => 5, 'name' => 'continuous_stewardship_loop', 'allows_branch' => false, 'allows_provider' => false],
                ['tier' => 6, 'name' => 'multi_company_supervised', 'allows_branch' => false, 'allows_provider' => false],
            ],
            'tier_3_plus_requires' => [
                'repo_authorization_receipt',
                'branch_sandbox_policy',
                'budget_policy',
                'evidence_inspector',
                'operator_review',
            ],
            'merge_deploy_secrets_default' => 'forbidden',
            'blockers' => $blockers,
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function budgetPolicy(array $input): array
    {
        $cycleBudget = max(0, (int) ($input['cycle_budget'] ?? 3));
        $branchLimit = max(0, (int) ($input['branch_wip_limit'] ?? 2));
        $providerCallLimit = max(0, (int) ($input['provider_call_limit'] ?? 0));
        $usedCycles = max(0, (int) ($input['used_cycles'] ?? 0));
        $activeBranches = max(0, (int) ($input['active_branch_count'] ?? 0));
        $providerCalls = max(0, (int) ($input['provider_calls_used'] ?? 0));

        $blockers = [];
        if ($usedCycles > $cycleBudget) {
            $blockers[] = 'cycle_budget_exceeded';
        }
        if ($activeBranches > $branchLimit) {
            $blockers[] = 'branch_wip_limit_exceeded';
        }
        if ($providerCalls > $providerCallLimit) {
            $blockers[] = 'provider_call_budget_exceeded';
        }

        return [
            'schema_version' => 'atlas.software_company.product_mode.budget_policy.v1',
            'cycle_budget' => $cycleBudget,
            'used_cycles' => $usedCycles,
            'remaining_cycles' => max(0, $cycleBudget - $usedCycles),
            'branch_wip_limit' => $branchLimit,
            'active_branch_count' => $activeBranches,
            'provider_call_limit' => $providerCallLimit,
            'provider_calls_used' => $providerCalls,
            'provider_calls_default' => 'zero_until_owner_runtime_gate',
            'blockers' => $blockers,
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function branchReviewCenter(array $input): array
    {
        $branches = array_values(array_filter((array) ($input['branches'] ?? []), 'is_array'));
        $packets = array_values(array_filter((array) ($input['branch_review_packets'] ?? $input['review_packets'] ?? []), 'is_array'));
        foreach ($packets as $packet) {
            $branches[] = $this->branchFromReviewPacket($packet);
        }

        $pending = array_values(array_filter($branches, static fn (array $branch): bool => ($branch['status'] ?? '') !== 'reviewed'));
        $autoMergeCandidates = array_values(array_filter($branches, static fn (array $branch): bool => ($branch['status'] ?? '') === 'auto_merge_candidate'));
        $blocked = array_values(array_filter($branches, static fn (array $branch): bool => ($branch['status'] ?? '') === 'blocked'));
        $readyForOperator = array_values(array_filter($branches, static fn (array $branch): bool => ($branch['status'] ?? '') === 'ready_for_operator_review'));

        return [
            'schema_version' => 'atlas.software_company.product_mode.branch_review_center.v1',
            'branch_count' => count($branches),
            'pending_review_count' => count($pending),
            'review_packet_count' => count($packets),
            'auto_merge_candidate_count' => count($autoMergeCandidates),
            'ready_for_operator_review_count' => count($readyForOperator),
            'blocked_branch_count' => count($blocked),
            'branches' => $branches,
            'requires_operator_before_merge' => true,
            'merge_allowed_from_product_mode' => false,
            'deploy_allowed_from_product_mode' => false,
            'blockers' => $blocked === [] ? [] : ['branch_review_packet_blocked'],
        ];
    }

    /**
     * @param  array<string,mixed>  $packet
     * @return array<string,mixed>
     */
    private function branchFromReviewPacket(array $packet): array
    {
        $identity = (array) ($packet['branch_identity'] ?? []);
        $surface = (array) ($packet['gitkraken_review_surface'] ?? []);

        return [
            'schema_version' => 'atlas.software_company.product_mode.branch_review_item.v1',
            'source_schema_version' => (string) ($packet['schema_version'] ?? ''),
            'source_ap_contract' => (string) ($packet['ap_contract'] ?? 'AP-780'),
            'status' => (string) ($packet['status'] ?? 'ready_for_operator_review'),
            'branch_ref' => (string) ($identity['branch_ref'] ?? $surface['visible_branch_ref'] ?? ''),
            'base_ref' => (string) ($identity['base_ref'] ?? $surface['visible_base_ref'] ?? 'main'),
            'branch_commit' => (string) ($identity['branch_commit'] ?? ''),
            'base_commit' => (string) ($identity['base_commit'] ?? ''),
            'changed_files' => array_values(array_filter((array) ($surface['changed_files'] ?? []), 'is_string')),
            'reviewable_commits' => array_values(array_filter((array) ($surface['reviewable_commits'] ?? []), 'is_array')),
            'cycle_traceability' => (array) ($packet['cycle_traceability'] ?? $surface['cycle_traceability'] ?? []),
            'risk_summary' => (array) ($packet['risk_summary'] ?? []),
            'decision_options' => array_values(array_filter((array) ($packet['decision_options'] ?? []), 'is_array')),
            'operator_next_action' => (string) ($packet['operator_next_action'] ?? ''),
            'packet_hash' => (string) ($packet['packet_hash'] ?? ''),
            'blockers' => array_values(array_filter((array) ($packet['blockers'] ?? []), 'is_string')),
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function evidenceInspector(array $input): array
    {
        $packs = array_values(array_filter((array) ($input['evidence_packs'] ?? []), 'is_array'));
        $required = [
            'docs_health',
            'architecture_validate',
            'focused_tests',
            'owner_sandbox_runtime_run',
            'owner_runtime_result',
        ];
        $present = array_values(array_filter((array) ($input['evidence_refs'] ?? []), 'is_string'));
        $missing = array_values(array_diff($required, $present));

        return [
            'schema_version' => 'atlas.software_company.product_mode.evidence_inspector.v1',
            'evidence_pack_count' => count($packs),
            'evidence_packs' => $packs,
            'required_refs' => $required,
            'present_refs' => $present,
            'missing_refs' => $missing,
            'inspector_status' => $missing === [] ? 'complete' : 'incomplete',
            'completion_claim_allowed' => $missing === [],
            'blockers' => [],
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function riskPolicy(array $input): array
    {
        $maxRisk = $this->nonEmpty((string) ($input['max_risk_without_operator'] ?? 'low'), 'low');
        $sensitive = array_values(array_filter((array) ($input['sensitive_domains'] ?? ['finance', 'legal', 'healthcare', 'trading', 'cyber']), 'is_string'));

        return [
            'schema_version' => 'atlas.software_company.product_mode.risk_policy.v1',
            'max_risk_without_operator' => $maxRisk,
            'sensitive_domains' => $sensitive,
            'mandatory_sensitive_domain_gates' => [
                'jurisdiction_check',
                'consent_chain_verified',
                'audit_trail_complete',
                'liability_boundary_documented',
                'policy_gate_passed',
            ],
            'no_autonomous_professional_advice' => true,
            'irreversible_actions_require_operator' => true,
            'blockers' => [],
        ];
    }

    /**
     * @param  array<string,mixed>  ...$sections
     */
    private function reviewRequired(array ...$sections): bool
    {
        foreach ($sections as $section) {
            if ((int) ($section['pending_review_count'] ?? 0) > 0) {
                return true;
            }
            if (($section['inspector_status'] ?? '') === 'incomplete') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string,mixed>  $repo
     * @param  array<string,mixed>  $controls
     * @param  array<string,mixed>  $tiers
     * @param  array<string,mixed>  $budget
     * @param  array<string,mixed>  $branchReview
     * @param  array<string,mixed>  $evidence
     * @param  array<string,mixed>  $risk
     * @return list<string>
     */
    private function nextActions(string $status, array $repo, array $controls, array $tiers, array $budget, array $branchReview, array $evidence, array $risk): array
    {
        $actions = [];

        if (! (bool) ($repo['is_authorized'] ?? false)) {
            $actions[] = 'Complete repository onboarding before Product Mode can operate on this repo.';
        }
        if ((bool) ($controls['kill_switch_active'] ?? false)) {
            $actions[] = 'Clear the Product Mode kill switch only after the operator reviews why it was activated.';
        }
        if (($tiers['tier_status'] ?? '') === 'blocked') {
            $actions[] = 'Lower the requested autonomy tier or raise policy after explicit operator approval.';
        }
        if (($budget['blockers'] ?? []) !== []) {
            $actions[] = 'Resolve Product Mode budget blockers before admitting more stewardship work.';
        }
        if ((int) ($branchReview['pending_review_count'] ?? 0) > 0) {
            $actions[] = 'Review pending branches in the owner runtime before any merge or deploy decision.';
        }
        if (($evidence['inspector_status'] ?? '') !== 'complete') {
            $actions[] = 'Attach missing evidence refs before claiming Product Mode delivery complete.';
        }
        if ((bool) ($risk['irreversible_actions_require_operator'] ?? true)) {
            $actions[] = 'Keep irreversible actions behind operator approval and owner runtime gates.';
        }
        if ($actions === [] && $status === self::STATUS_READY) {
            $actions[] = 'Product Mode controls are ready for read-only cockpit display; execution remains with owner runtimes.';
        }

        return $actions;
    }

    /**
     * @return array<string,bool>
     */
    private function claimPolicy(): array
    {
        return [
            'read_only' => true,
            'writes_repo' => false,
            'authorizes_repository' => false,
            'changes_autonomy_tier' => false,
            'updates_budget_policy' => false,
            'toggles_kill_switch' => false,
            'applies_control_receipts_as_projection_only' => true,
            'creates_branch' => false,
            'merges_branch' => false,
            'deploys_code' => false,
            'touches_secrets' => false,
            'invokes_provider' => false,
            'invokes_dev' => false,
            'invokes_forge' => false,
            'installs_scheduler' => false,
            'operator_review_required_for_irreversible_actions' => true,
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function finalize(array $payload): array
    {
        $hashPayload = $payload;
        unset($hashPayload['generated_at'], $hashPayload['controls_hash']);

        $payload['controls_hash'] = 'sha256:'.MissionCanonicalHash::sha256($hashPayload);
        $payload['generated_at'] = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->format(DateTimeInterface::ATOM);

        return $payload;
    }

    private function nonEmpty(string $value, string $fallback): string
    {
        $value = trim($value);

        return $value !== '' ? $value : $fallback;
    }
}
