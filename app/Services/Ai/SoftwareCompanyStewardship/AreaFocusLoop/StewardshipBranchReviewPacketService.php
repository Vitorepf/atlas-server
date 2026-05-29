<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\Mission\MissionCanonicalHash;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * AP-780 · Stewardship Branch Review Packet.
 *
 * Read-only packet that turns AP-769/AP-772 branch governance into one
 * operator-reviewable GitKraken/Product Mode decision object.
 */
final class StewardshipBranchReviewPacketService
{
    public const PACKET_SCHEMA = 'atlas.software_company_stewardship.branch_review_packet.v1';

    public const STATUS_READY_FOR_OPERATOR_REVIEW = 'ready_for_operator_review';

    public const STATUS_AUTO_MERGE_CANDIDATE = 'auto_merge_candidate';

    public const STATUS_MERGED = 'merged';

    public const STATUS_BLOCKED = 'blocked';

    public const DEFAULT_AREA_ID = 'agentic_engineering_os';

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function build(array $input): array
    {
        $areaId = $this->slug((string) ($input['area_id'] ?? self::DEFAULT_AREA_ID));
        $governance = $this->governanceReport($input);
        if ($governance === []) {
            return $this->blocked($areaId, 'branch_governance_report_required', 'AP-780 requires an AP-769 governance report or AP-772 queue item.');
        }

        $gitkraken = (array) ($governance['gitkraken_review_surface'] ?? []);
        $branchRef = (string) data_get($governance, 'repo.branch_ref', $gitkraken['visible_branch_ref'] ?? '');
        $baseRef = (string) data_get($governance, 'repo.base_ref', $gitkraken['visible_base_ref'] ?? 'main');
        $governanceStatus = (string) ($governance['status'] ?? data_get($input, 'queue_item.governance_status', ''));
        $autoMergeEligible = (bool) data_get($governance, 'auto_merge_policy.eligible', false);
        $blockers = array_values(array_filter((array) ($governance['blockers'] ?? []), 'is_string'));
        $status = $this->status($governanceStatus, $autoMergeEligible, $blockers);

        $packet = [
            'schema_version' => self::PACKET_SCHEMA,
            'ap_contract' => 'AP-780',
            'status' => $status,
            'area_id' => $areaId,
            'stack' => 'Atlas Software Company Stewardship Stack',
            'source_ap_contracts' => ['AP-769', 'AP-772', 'AP-773', 'AP-774', 'AP-780'],
            'branch_identity' => [
                'branch_ref' => $branchRef,
                'base_ref' => $baseRef,
                'branch_commit' => (string) data_get($governance, 'repo.branch_commit', ''),
                'base_commit' => (string) data_get($governance, 'repo.base_commit', ''),
                'repo_root_hash' => (string) data_get($governance, 'repo.repo_root_hash', ''),
            ],
            'gitkraken_review_surface' => $gitkraken,
            'cycle_traceability' => (array) ($gitkraken['cycle_traceability'] ?? []),
            'classification' => (array) ($governance['classification'] ?? []),
            'risk_summary' => $this->riskSummary($governance, $blockers, $autoMergeEligible),
            'decision_options' => $this->decisionOptions($status, $branchRef, $baseRef, $governance),
            'operator_next_action' => $this->operatorNextAction($status, $branchRef, $baseRef),
            'queue_context' => (array) ($input['queue_context'] ?? []),
            'evidence_refs' => array_values(array_filter((array) ($input['evidence_refs'] ?? []), 'is_string')),
            'blockers' => $blockers,
            'claim_policy' => [
                'read_only' => true,
                'provider_invoked' => false,
                'branch_created' => false,
                'worktree_created' => false,
                'merge_performed' => false,
                'push_performed' => false,
                'deploy_performed' => false,
                'secrets_accessed' => false,
                'operator_review_required_before_code_or_mixed_merge' => true,
                'auto_merge_execution_requires_ap769_or_ap772_command' => true,
            ],
            'generated_at' => $this->now(),
        ];
        $packet['packet_hash'] = 'sha256:'.MissionCanonicalHash::sha256($this->identity($packet));

        return $packet;
    }

    /**
     * Cross-review automatic gate entry (step 3 of 3).
     *
     * Validates input shape. Step-3 first rule: explicit {@code cross_system=true}
     * maps through {@see CrossReviewAutomaticGateContract::fromArray}. Empty input,
     * cross-system derivation from changed_files, and packet wiring are future steps.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function crossReviewAutomaticGate(array $input = []): array
    {
        $this->validateCrossReviewAutomaticGateInput($input);

        if (($input['cross_system'] ?? false) !== true) {
            return CrossReviewAutomaticGateContract::defaults()->toArray();
        }

        return CrossReviewAutomaticGateContract::fromArray($input)->toArray();
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function governanceReport(array $input): array
    {
        $report = (array) ($input['governance_report'] ?? []);
        if (in_array(($report['schema_version'] ?? ''), [
            StewardshipBranchMergeGovernorService::REPORT_SCHEMA,
            StewardshipBranchMergeGovernorService::RECORD_SCHEMA,
        ], true) && ($report['ap_contract'] ?? '') === 'AP-769') {
            return $report;
        }

        $queueItem = (array) ($input['queue_item'] ?? []);
        $governance = (array) ($queueItem['governance'] ?? []);
        if (in_array(($governance['schema_version'] ?? ''), [
            StewardshipBranchMergeGovernorService::REPORT_SCHEMA,
            StewardshipBranchMergeGovernorService::RECORD_SCHEMA,
        ], true) && ($governance['ap_contract'] ?? '') === 'AP-769') {
            return $governance;
        }

        return [];
    }

    /**
     * @param  array<string,mixed>  $governance
     * @param  list<string>  $blockers
     * @return array<string,mixed>
     */
    private function riskSummary(array $governance, array $blockers, bool $autoMergeEligible): array
    {
        $classification = (array) ($governance['classification'] ?? []);
        $gitkraken = (array) ($governance['gitkraken_review_surface'] ?? []);

        return [
            'change_class' => (string) ($classification['kind'] ?? 'unknown'),
            'risk_class' => (string) data_get($governance, 'auto_merge_policy.risk_class', 'unknown'),
            'changed_file_count' => count((array) ($gitkraken['changed_files'] ?? [])),
            'reviewable_commit_count' => (int) ($gitkraken['reviewable_commit_count'] ?? 0),
            'auto_merge_eligible' => $autoMergeEligible,
            'conflict_clean' => (bool) data_get($governance, 'merge_conflict_check.clean', false),
            'blocker_count' => count($blockers),
        ];
    }

    /**
     * @param  array<string,mixed>  $governance
     * @return list<array<string,mixed>>
     */
    private function decisionOptions(string $status, string $branchRef, string $baseRef, array $governance): array
    {
        $options = [
            [
                'decision' => 'defer',
                'label' => 'Defer branch',
                'safe' => true,
                'effect' => 'keeps_branch_isolated',
            ],
            [
                'decision' => 'reject',
                'label' => 'Reject branch',
                'safe' => true,
                'effect' => 'requires_explicit_release_or_cleanup_receipt',
            ],
            [
                'decision' => 'request_changes',
                'label' => 'Request changes',
                'safe' => true,
                'effect' => 'routes_back_to_owner_runtime_or_operator',
            ],
        ];

        if ($status === self::STATUS_READY_FOR_OPERATOR_REVIEW || $status === self::STATUS_AUTO_MERGE_CANDIDATE) {
            array_unshift($options, [
                'decision' => 'accept_for_manual_ff_merge',
                'label' => 'Accept after GitKraken review',
                'safe' => true,
                'effect' => 'operator_runs_ap769_or_ap772_ff_only_merge_command',
                'command' => 'php artisan atlas:software-company-stewardship branch-merge-governor --branch-ref='
                    .$branchRef.' --base-ref='.$baseRef.' --json',
            ]);
        }

        if ($status === self::STATUS_AUTO_MERGE_CANDIDATE) {
            array_unshift($options, [
                'decision' => 'execute_policy_gated_auto_merge',
                'label' => 'Execute policy-gated auto-merge',
                'safe' => true,
                'effect' => 'runs_ap769_ff_only_auto_merge_after_current_policy_recheck',
                'command' => 'php artisan atlas:software-company-stewardship branch-merge-governor --branch-ref='
                    .$branchRef.' --base-ref='.$baseRef.' --auto-merge --execute-merge --record-governance --json',
            ]);
        }

        if ($status === self::STATUS_BLOCKED) {
            array_unshift($options, [
                'decision' => 'repair_branch_before_review',
                'label' => 'Repair branch first',
                'safe' => true,
                'effect' => 'no_merge_allowed_until_blockers_clear',
                'blockers' => array_values(array_filter((array) ($governance['blockers'] ?? []), 'is_string')),
            ]);
        }

        return $options;
    }

    /**
     * @param  list<string>  $blockers
     */
    private function status(string $governanceStatus, bool $autoMergeEligible, array $blockers): string
    {
        if ($governanceStatus === StewardshipBranchMergeGovernorService::STATUS_MERGED) {
            return self::STATUS_MERGED;
        }
        if ($blockers !== [] || $governanceStatus === StewardshipBranchMergeGovernorService::STATUS_BLOCKED) {
            return self::STATUS_BLOCKED;
        }
        if ($autoMergeEligible || $governanceStatus === StewardshipBranchMergeGovernorService::STATUS_AUTO_MERGE_ELIGIBLE) {
            return self::STATUS_AUTO_MERGE_CANDIDATE;
        }

        return self::STATUS_READY_FOR_OPERATOR_REVIEW;
    }

    private function operatorNextAction(string $status, string $branchRef, string $baseRef): string
    {
        return match ($status) {
            self::STATUS_AUTO_MERGE_CANDIDATE => 'Review '.$branchRef.' against '.$baseRef.' in GitKraken; then either approve AP-769 ff-only auto-merge or defer.',
            self::STATUS_READY_FOR_OPERATOR_REVIEW => 'Review '.$branchRef.' against '.$baseRef.' in GitKraken; accept, reject, defer or request changes.',
            self::STATUS_MERGED => 'Review '.$baseRef.' in GitKraken; branch was already merged by AP-769/AP-772.',
            default => 'Repair blockers before review or merge; no auto-merge is allowed.',
        };
    }

    /**
     * @return array<string,mixed>
     */
    private function blocked(string $areaId, string $reason, string $detail): array
    {
        return [
            'schema_version' => self::PACKET_SCHEMA,
            'ap_contract' => 'AP-780',
            'status' => self::STATUS_BLOCKED,
            'area_id' => $areaId,
            'reason' => $reason,
            'detail' => $detail,
            'blockers' => [$reason],
            'claim_policy' => [
                'read_only' => true,
                'provider_invoked' => false,
                'merge_performed' => false,
                'push_performed' => false,
                'deploy_performed' => false,
            ],
            'generated_at' => $this->now(),
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     */
    private function validateCrossReviewAutomaticGateInput(array $input): void
    {
        if ($input === []) {
            return;
        }

        $allowedKeys = ['area_id', 'cross_system', 'changed_files'];
        foreach (array_keys($input) as $key) {
            if (! in_array($key, $allowedKeys, true)) {
                throw new \InvalidArgumentException("Unknown cross-review automatic gate input key: {$key}");
            }
        }

        if (array_key_exists('area_id', $input) && ! is_string($input['area_id'])) {
            throw new \InvalidArgumentException('area_id must be a string.');
        }

        if (array_key_exists('cross_system', $input) && ! is_bool($input['cross_system'])) {
            throw new \InvalidArgumentException('cross_system must be a boolean.');
        }

        if (array_key_exists('changed_files', $input)) {
            if (! is_array($input['changed_files'])) {
                throw new \InvalidArgumentException('changed_files must be an array.');
            }

            foreach ($input['changed_files'] as $file) {
                if (! is_string($file)) {
                    throw new \InvalidArgumentException('changed_files entries must be strings.');
                }
            }
        }
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function identity(array $payload): array
    {
        unset($payload['generated_at'], $payload['packet_hash']);

        return $payload;
    }

    private function slug(string $value): string
    {
        $slug = strtolower(trim($value));
        $slug = preg_replace('/[^a-z0-9_\-]+/', '_', $slug) ?: self::DEFAULT_AREA_ID;

        return trim($slug, '_-') ?: self::DEFAULT_AREA_ID;
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);
    }
}
