<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\Mission\MissionCanonicalHash;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Atlas Software Company Stewardship Stack · Area Focus Loop ·
 * Safety Gate Evaluator (Slice 8, AP-723).
 *
 * Atlas Software Company Stewardship Stack is a stack/capability family inside
 * the Atlas Autonomous Software Company Runtime, NOT a new OS.
 *
 * A PURE, read-only decision service: given a run context assembled from the
 * orchestrator / work orders / evidence, it evaluates the canonical Area Focus
 * Loop safety gates and decides `allow` / `warn` / `block`. It executes NOTHING:
 * no work, no provider, no branch, no merge, no deploy, no secrets, no
 * destructive change, no state write. It only decides.
 *
 * `max_governed` is interpreted strictly as "maximum useful throughput permitted
 * by these gates", never unrestricted autonomy.
 *
 * Fail-safe posture:
 *   - Gates that CONFIRM a required condition (owner docs, evidence, inbox)
 *     default to BLOCK when the condition cannot be affirmatively confirmed.
 *   - Gates that FORBID a dangerous request (secrets/merge/deploy/destructive)
 *     default to PASS only because absence of a request means it was not
 *     requested; any explicit request blocks.
 */
class AreaFocusGateEvaluatorService
{
    public const REPORT_SCHEMA = 'atlas.software_company_stewardship.area_focus_gate_report.v1';

    public const DEFAULT_AREA_ID = 'agentic_engineering_os';

    public const DECISION_ALLOW = 'allow';

    public const DECISION_WARN = 'warn';

    public const DECISION_BLOCK = 'block';

    public const GATE_PASS = 'pass';

    public const GATE_WARN = 'warn';

    public const GATE_BLOCK = 'block';

    public const GATE_AREA_OWNER_DOCS_PRESENT = 'area_owner_docs_present';

    public const GATE_BUDGET_WITHIN_LIMIT = 'budget_within_limit';

    public const GATE_WIP_WITHIN_LIMIT = 'wip_within_limit';

    public const GATE_RISK_POLICY_SATISFIED = 'risk_policy_satisfied';

    public const GATE_KILL_SWITCH_OPEN = 'kill_switch_open';

    public const GATE_NO_SECRETS_REQUESTED = 'no_secrets_requested';

    public const GATE_NO_MERGE_REQUESTED = 'no_merge_requested';

    public const GATE_NO_DEPLOY_REQUESTED = 'no_deploy_requested';

    public const GATE_NO_DESTRUCTIVE_CHANGE_REQUESTED = 'no_destructive_change_requested';

    public const GATE_EVIDENCE_PACK_PRESENT = 'evidence_pack_present';

    public const GATE_OPERATOR_INBOX_PRESENT = 'operator_inbox_present';

    public const GATE_ATLAS_INTERNAL_FIRST = 'atlas_internal_first';

    /** Canonical, ordered list of gates (drives deterministic evaluation). */
    public const GATES = [
        self::GATE_AREA_OWNER_DOCS_PRESENT,
        self::GATE_BUDGET_WITHIN_LIMIT,
        self::GATE_WIP_WITHIN_LIMIT,
        self::GATE_RISK_POLICY_SATISFIED,
        self::GATE_KILL_SWITCH_OPEN,
        self::GATE_NO_SECRETS_REQUESTED,
        self::GATE_NO_MERGE_REQUESTED,
        self::GATE_NO_DEPLOY_REQUESTED,
        self::GATE_NO_DESTRUCTIVE_CHANGE_REQUESTED,
        self::GATE_EVIDENCE_PACK_PRESENT,
        self::GATE_OPERATOR_INBOX_PRESENT,
        self::GATE_ATLAS_INTERNAL_FIRST,
    ];

    /** Budget usage ratio at/above which a (non-blocking) warning is raised. */
    private const BUDGET_WARN_RATIO = 0.8;

    /** Repos considered Atlas-internal for the `atlas_internal_first` gate. */
    private const ATLAS_REPO_PREFIX = 'atlas-';

    /**
     * Evaluate every Area Focus Loop safety gate for one run context.
     *
     * `$input` (all read-only; the evaluator performs no IO):
     *   - area_id:        string
     *   - area_contract:  array  governed limits the operator authorized for the area
     *       { area_owner_docs[], dev_budget{limit?}, forge_budget{limit?}, wip_limit,
     *         risk_policy{ inbox_only_domains[], ... }, inbox_destination, repo_scope{ repos[] } }
     *   - run:            array  the proposed run assembled from orchestrator/work orders/evidence
     *       { owner_docs[]|owner_docs_present{}, budget_used{dev?,forge?}, wip_used,
     *         requested{ secrets, merge, deploy, destructive_change },
     *         risk{ unresolved_high_risk, sensitive_domains_touched[] },
     *         kill_switch_engaged, evidence_pack{ present, required, complete },
     *         operator_inbox{ present }, target_repos[] }
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function evaluate(array $input = []): array
    {
        $areaId = is_string($input['area_id'] ?? null) && $input['area_id'] !== ''
            ? (string) $input['area_id']
            : self::DEFAULT_AREA_ID;

        $contract = is_array($input['area_contract'] ?? null) ? $input['area_contract'] : [];
        $run = is_array($input['run'] ?? null) ? $input['run'] : [];

        $gates = [];
        foreach (self::GATES as $gate) {
            $gates[] = $this->evaluateGate($gate, $contract, $run);
        }

        $blocked = array_values(array_filter($gates, fn (array $g): bool => $g['status'] === self::GATE_BLOCK));
        $warned = array_values(array_filter($gates, fn (array $g): bool => $g['status'] === self::GATE_WARN));

        $decision = match (true) {
            $blocked !== [] => self::DECISION_BLOCK,
            $warned !== [] => self::DECISION_WARN,
            default => self::DECISION_ALLOW,
        };

        $requiredNextActions = [];
        foreach ($gates as $g) {
            if ($g['status'] !== self::GATE_PASS && ($g['required_next_action'] ?? '') !== '') {
                $requiredNextActions[] = $g['required_next_action'];
            }
        }

        return $this->finalize([
            'schema_version' => self::REPORT_SCHEMA,
            'decision' => $decision,
            'mode' => 'read_only',
            'area_id' => $areaId,
            'gates' => $gates,
            'gate_summary' => [
                'total' => count($gates),
                'pass' => count($gates) - count($blocked) - count($warned),
                'warn' => count($warned),
                'block' => count($blocked),
            ],
            'blocked_when' => array_map(static fn (array $g): array => [
                'gate' => $g['gate'],
                'reason' => $g['reason'],
            ], $blocked),
            'warnings' => array_map(static fn (array $g): array => [
                'gate' => $g['gate'],
                'reason' => $g['reason'],
            ], $warned),
            'required_next_actions' => array_values(array_unique($requiredNextActions)),
            'max_governed' => $this->maxGoverned(),
            'claim_policy' => $this->claimPolicy(),
        ]);
    }

    // ---------- per-gate evaluation ----------

    /**
     * @param  array<string,mixed>  $contract
     * @param  array<string,mixed>  $run
     * @return array<string,mixed>
     */
    private function evaluateGate(string $gate, array $contract, array $run): array
    {
        return match ($gate) {
            self::GATE_AREA_OWNER_DOCS_PRESENT => $this->gateOwnerDocs($contract, $run),
            self::GATE_BUDGET_WITHIN_LIMIT => $this->gateBudget($contract, $run),
            self::GATE_WIP_WITHIN_LIMIT => $this->gateWip($contract, $run),
            self::GATE_RISK_POLICY_SATISFIED => $this->gateRiskPolicy($contract, $run),
            self::GATE_KILL_SWITCH_OPEN => $this->gateKillSwitch($run),
            self::GATE_NO_SECRETS_REQUESTED => $this->gateNoRequest($run, 'secrets', self::GATE_NO_SECRETS_REQUESTED, 'Secret access requested; not allowed without operator.'),
            self::GATE_NO_MERGE_REQUESTED => $this->gateNoRequest($run, 'merge', self::GATE_NO_MERGE_REQUESTED, 'Merge requested; not allowed without operator.'),
            self::GATE_NO_DEPLOY_REQUESTED => $this->gateNoRequest($run, 'deploy', self::GATE_NO_DEPLOY_REQUESTED, 'Deploy requested; not allowed without operator.'),
            self::GATE_NO_DESTRUCTIVE_CHANGE_REQUESTED => $this->gateNoRequest($run, 'destructive_change', self::GATE_NO_DESTRUCTIVE_CHANGE_REQUESTED, 'Destructive change requested; not allowed without operator.'),
            self::GATE_EVIDENCE_PACK_PRESENT => $this->gateEvidence($run),
            self::GATE_OPERATOR_INBOX_PRESENT => $this->gateOperatorInbox($contract, $run),
            self::GATE_ATLAS_INTERNAL_FIRST => $this->gateAtlasInternalFirst($contract, $run),
            default => $this->gate($gate, self::GATE_BLOCK, 'unknown_gate', [], 'Remove unknown gate.'),
        };
    }

    /**
     * @param  array<string,mixed>  $contract
     * @param  array<string,mixed>  $run
     * @return array<string,mixed>
     */
    private function gateOwnerDocs(array $contract, array $run): array
    {
        $declared = array_values(array_filter((array) ($contract['area_owner_docs'] ?? []), 'is_string'));
        if ($declared === []) {
            return $this->gate(self::GATE_AREA_OWNER_DOCS_PRESENT, self::GATE_BLOCK, 'no_owner_docs_declared', [], 'Declare the area owner docs in the area contract.');
        }

        $presence = $this->ownerDocPresence($run);
        if ($presence === null) {
            return $this->gate(self::GATE_AREA_OWNER_DOCS_PRESENT, self::GATE_BLOCK, 'owner_doc_status_not_provided', [], 'Resolve owner-doc presence before running.');
        }

        $missing = [];
        foreach ($declared as $doc) {
            if (($presence[$doc] ?? false) !== true) {
                $missing[] = $doc;
            }
        }
        if ($missing !== []) {
            return $this->gate(
                self::GATE_AREA_OWNER_DOCS_PRESENT,
                self::GATE_BLOCK,
                count($missing).' owner doc(s) missing',
                array_map(static fn (string $d): string => 'missing_owner_doc:'.$d, array_slice($missing, 0, 8)),
                'Restore the missing owner doc(s) before running.',
            );
        }

        return $this->gate(self::GATE_AREA_OWNER_DOCS_PRESENT, self::GATE_PASS, 'all owner docs present', ['owner_docs:'.count($declared)]);
    }

    /**
     * @param  array<string,mixed>  $contract
     * @param  array<string,mixed>  $run
     * @return array<string,mixed>
     */
    private function gateBudget(array $contract, array $run): array
    {
        $used = is_array($run['budget_used'] ?? null) ? $run['budget_used'] : [];
        $worst = self::GATE_PASS;
        $reasons = [];
        $evidence = [];

        foreach (['dev', 'forge'] as $kind) {
            $limit = $this->numericLimit($contract[$kind.'_budget'] ?? null);
            $consumed = (float) ($used[$kind] ?? 0);
            if ($limit === null) {
                // Governed cap without a hard number — bounded, but not numerically checkable.
                $reasons[] = $kind.'_budget_governed_no_numeric_limit';
                $evidence[] = $kind.'_budget:governed';

                continue;
            }
            $evidence[] = sprintf('%s_budget:%s/%s', $kind, $consumed, $limit);
            if ($consumed > $limit) {
                $worst = self::GATE_BLOCK;
                $reasons[] = $kind.'_budget_exceeded';
            } elseif ($limit > 0 && ($consumed / $limit) >= self::BUDGET_WARN_RATIO && $worst !== self::GATE_BLOCK) {
                $worst = self::GATE_WARN;
                $reasons[] = $kind.'_budget_near_limit';
            }
        }

        $next = $worst === self::GATE_BLOCK ? 'Reduce scope or raise the budget before running.' : '';

        return $this->gate(self::GATE_BUDGET_WITHIN_LIMIT, $worst, implode(', ', $reasons) ?: 'budget within limit', $evidence, $next);
    }

    /**
     * @param  array<string,mixed>  $contract
     * @param  array<string,mixed>  $run
     * @return array<string,mixed>
     */
    private function gateWip(array $contract, array $run): array
    {
        if (! array_key_exists('wip_limit', $contract)) {
            return $this->gate(self::GATE_WIP_WITHIN_LIMIT, self::GATE_BLOCK, 'wip_limit_not_declared', [], 'Declare wip_limit in the area contract.');
        }
        $limit = (int) $contract['wip_limit'];
        $used = (int) ($run['wip_used'] ?? 0);
        $evidence = ["wip:{$used}/{$limit}"];

        if ($used > $limit) {
            return $this->gate(self::GATE_WIP_WITHIN_LIMIT, self::GATE_BLOCK, 'wip_limit_exceeded', $evidence, 'Wait for in-flight work to drain before opening more.');
        }
        if ($used === $limit) {
            return $this->gate(self::GATE_WIP_WITHIN_LIMIT, self::GATE_WARN, 'wip_at_capacity', $evidence, 'At WIP capacity; no new work order may open.');
        }

        return $this->gate(self::GATE_WIP_WITHIN_LIMIT, self::GATE_PASS, 'wip within limit', $evidence);
    }

    /**
     * @param  array<string,mixed>  $contract
     * @param  array<string,mixed>  $run
     * @return array<string,mixed>
     */
    private function gateRiskPolicy(array $contract, array $run): array
    {
        $risk = is_array($run['risk'] ?? null) ? $run['risk'] : [];
        $unresolvedHighRisk = (int) ($risk['unresolved_high_risk'] ?? 0);
        $touched = array_values(array_filter((array) ($risk['sensitive_domains_touched'] ?? []), 'is_string'));

        $policy = is_array($contract['risk_policy'] ?? null) ? $contract['risk_policy'] : [];
        $inboxOnly = array_values(array_filter((array) ($policy['inbox_only_domains'] ?? []), 'is_string'));
        $sensitiveHits = array_values(array_intersect($touched, $inboxOnly));

        if ($unresolvedHighRisk > 0) {
            return $this->gate(
                self::GATE_RISK_POLICY_SATISFIED,
                self::GATE_BLOCK,
                "{$unresolvedHighRisk} unresolved high-risk finding(s)",
                ['unresolved_high_risk:'.$unresolvedHighRisk],
                'Route high-risk findings to the operator inbox before running.',
            );
        }
        if ($sensitiveHits !== []) {
            return $this->gate(
                self::GATE_RISK_POLICY_SATISFIED,
                self::GATE_WARN,
                'touches inbox-only domain(s): '.implode(',', $sensitiveHits),
                array_map(static fn (string $d): string => 'sensitive_domain:'.$d, $sensitiveHits),
                'Sensitive-domain work must stay inbox-only (no autonomous execution).',
            );
        }

        return $this->gate(self::GATE_RISK_POLICY_SATISFIED, self::GATE_PASS, 'risk policy satisfied', []);
    }

    /**
     * @param  array<string,mixed>  $run
     * @return array<string,mixed>
     */
    private function gateKillSwitch(array $run): array
    {
        if (($run['kill_switch_engaged'] ?? false) === true) {
            return $this->gate(self::GATE_KILL_SWITCH_OPEN, self::GATE_BLOCK, 'kill_switch_engaged', ['kill_switch:engaged'], 'Operator must disengage the kill switch to run.');
        }
        if (! array_key_exists('kill_switch_engaged', $run)) {
            return $this->gate(self::GATE_KILL_SWITCH_OPEN, self::GATE_WARN, 'kill_switch_state_not_reported', [], 'Report the kill-switch state with the run.');
        }

        return $this->gate(self::GATE_KILL_SWITCH_OPEN, self::GATE_PASS, 'kill switch open', ['kill_switch:open']);
    }

    /**
     * Generic forbidden-request gate. Absence of a request = not requested = pass.
     *
     * @param  array<string,mixed>  $run
     * @return array<string,mixed>
     */
    private function gateNoRequest(array $run, string $key, string $gate, string $blockReason): array
    {
        $requested = is_array($run['requested'] ?? null) ? $run['requested'] : [];
        if (($requested[$key] ?? false) === true) {
            return $this->gate($gate, self::GATE_BLOCK, $blockReason, ['requested:'.$key], 'Remove the '.$key.' request; operator-gated action.');
        }

        return $this->gate($gate, self::GATE_PASS, 'not requested', []);
    }

    /**
     * @param  array<string,mixed>  $run
     * @return array<string,mixed>
     */
    private function gateEvidence(array $run): array
    {
        $pack = is_array($run['evidence_pack'] ?? null) ? $run['evidence_pack'] : [];
        if (! array_key_exists('evidence_pack', $run)) {
            return $this->gate(self::GATE_EVIDENCE_PACK_PRESENT, self::GATE_BLOCK, 'evidence_pack_not_provided', [], 'Attach an evidence pack before claiming the run.');
        }
        if (($pack['present'] ?? false) !== true) {
            return $this->gate(self::GATE_EVIDENCE_PACK_PRESENT, self::GATE_BLOCK, 'evidence_pack_absent', [], 'Build the evidence pack before running.');
        }
        // present but flagged required-and-incomplete -> non-blocking warn.
        if (($pack['required'] ?? true) === true && ($pack['complete'] ?? false) !== true) {
            return $this->gate(self::GATE_EVIDENCE_PACK_PRESENT, self::GATE_WARN, 'evidence_pack_incomplete', ['evidence:present_incomplete'], 'Complete the evidence pack for full certification.');
        }

        return $this->gate(self::GATE_EVIDENCE_PACK_PRESENT, self::GATE_PASS, 'evidence pack present', ['evidence:present']);
    }

    /**
     * @param  array<string,mixed>  $contract
     * @param  array<string,mixed>  $run
     * @return array<string,mixed>
     */
    private function gateOperatorInbox(array $contract, array $run): array
    {
        $inbox = is_array($run['operator_inbox'] ?? null) ? $run['operator_inbox'] : [];
        $present = ($inbox['present'] ?? null) === true
            || (is_string($contract['inbox_destination'] ?? null) && $contract['inbox_destination'] !== '');

        if (! $present) {
            return $this->gate(self::GATE_OPERATOR_INBOX_PRESENT, self::GATE_BLOCK, 'operator_inbox_absent', [], 'Wire a Morning/Live operator inbox destination before running.');
        }

        return $this->gate(self::GATE_OPERATOR_INBOX_PRESENT, self::GATE_PASS, 'operator inbox present', []);
    }

    /**
     * @param  array<string,mixed>  $contract
     * @param  array<string,mixed>  $run
     * @return array<string,mixed>
     */
    private function gateAtlasInternalFirst(array $contract, array $run): array
    {
        $targets = array_values(array_filter((array) ($run['target_repos'] ?? []), 'is_string'));
        if ($targets === []) {
            $scope = is_array($contract['repo_scope'] ?? null) ? $contract['repo_scope'] : [];
            $targets = array_values(array_filter((array) ($scope['repos'] ?? []), 'is_string'));
        }

        $external = array_values(array_filter($targets, fn (string $r): bool => ! $this->isAtlasInternal($r)));
        if ($external !== []) {
            return $this->gate(
                self::GATE_ATLAS_INTERNAL_FIRST,
                self::GATE_BLOCK,
                'ns_v2_requires_v1_promotion_receipt: external repo(s) '.implode(',', $external),
                array_map(static fn (string $r): string => 'external_repo:'.$r, $external),
                'Run on Atlas-internal repos first; external companies need a v1→v2 promotion receipt.',
            );
        }

        return $this->gate(self::GATE_ATLAS_INTERNAL_FIRST, self::GATE_PASS, 'atlas-internal only', $targets === [] ? [] : ['repos:'.implode(',', $targets)]);
    }

    // ---------- helpers ----------

    private function isAtlasInternal(string $repo): bool
    {
        $repo = strtolower(trim($repo));

        return $repo === 'atlas' || str_starts_with($repo, self::ATLAS_REPO_PREFIX);
    }

    /**
     * Owner-doc presence map from the run, accepting either a `{path:bool}` map
     * or a list of `{path, exists}` rows. Returns null when not provided at all.
     *
     * @param  array<string,mixed>  $run
     * @return array<string,bool>|null
     */
    private function ownerDocPresence(array $run): ?array
    {
        if (is_array($run['owner_docs_present'] ?? null)) {
            $map = [];
            foreach ($run['owner_docs_present'] as $path => $exists) {
                if (is_string($path)) {
                    $map[$path] = (bool) $exists;
                }
            }

            return $map;
        }

        if (is_array($run['owner_docs'] ?? null)) {
            $map = [];
            foreach ($run['owner_docs'] as $row) {
                if (is_array($row) && is_string($row['path'] ?? null)) {
                    $map[$row['path']] = ($row['exists'] ?? false) === true;
                }
            }

            return $map;
        }

        return null;
    }

    private function numericLimit(mixed $budget): ?float
    {
        if (is_array($budget) && is_numeric($budget['limit'] ?? null)) {
            return (float) $budget['limit'];
        }
        if (is_numeric($budget)) {
            return (float) $budget;
        }

        return null;
    }

    /**
     * @param  list<string>  $evidence
     * @return array<string,mixed>
     */
    private function gate(string $gate, string $status, string $reason, array $evidence = [], string $nextAction = ''): array
    {
        return [
            'gate' => $gate,
            'status' => $status,
            'reason' => $reason,
            'evidence_refs' => $evidence,
            'required_next_action' => $nextAction,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function maxGoverned(): array
    {
        return [
            'mode' => 'max_governed',
            'meaning' => 'maximum useful throughput permitted by these gates, never unrestricted autonomy',
            'autonomy_unbounded' => false,
            'bounded_by' => self::GATES,
        ];
    }

    /**
     * @return array<string,bool>
     */
    private function claimPolicy(): array
    {
        return [
            'read_only' => true,
            'decides_only' => true,
            'executes_work' => false,
            'writes_state' => false,
            'provider_invoked' => false,
            'opens_branch' => false,
            'merges' => false,
            'deploys' => false,
            'accesses_secrets' => false,
            'destructive_change' => false,
            'max_governed_bounded' => true,
            'parallel_runtime_created' => false,
            'is_new_os' => false,
            'operator_review_required' => true,
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function finalize(array $payload): array
    {
        $payload['report_hash'] = 'sha256:'.MissionCanonicalHash::sha256($payload);
        $payload['generated_at'] = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->format(DateTimeInterface::ATOM);

        return $payload;
    }
}
