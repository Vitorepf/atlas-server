<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\Mission\MissionCanonicalHash;

/**
 * AP-791 · Autonomous loop inbox/merge/receipt integrity.
 *
 * For a reliable 24h loop the operator must be able to review every cycle later.
 * This service makes each AP-786 cycle auditable: it normalizes a cycle into a
 * canonical loop receipt that always carries the pre-merge inbox, the merge
 * decision + hash, the post-cycle inbox/receipt, evidence refs, a replay command
 * and a next_action — for every lifecycle state (completed/merged, planned,
 * blocked, failed, skipped).
 *
 * It is a pure JUDGE/normalizer: it never runs git, providers, merge, scheduler
 * or inbox. Its `preMergeGate()` is the hard rule that a cycle must not merge
 * without an operator-visible pre-merge inbox.
 */
final class AutonomousLoopReceiptIntegrityService
{
    public const RECEIPT_SCHEMA = 'atlas.software_company_stewardship.autonomous_loop_cycle_receipt.v1';

    public const INBOX_SUMMARY_SCHEMA = 'atlas.software_company_stewardship.autonomous_loop_cycle_inbox_summary.v1';

    // Canonical lifecycle states. `planned` (incl. Forge runtime-dispatch plans)
    // is NEVER reported as completed.
    public const STATE_MERGED = 'merged';

    public const STATE_PLANNED = 'planned';

    public const STATE_BLOCKED = 'blocked';

    public const STATE_FAILED = 'failed';

    public const STATE_SKIPPED = 'skipped';

    public const INTEGRITY_OK = 'ok';

    public const INTEGRITY_INCOMPLETE = 'incomplete';

    public const PRE_MERGE_INBOX_REQUIRED = 'pre_merge_inbox_required';

    /** Blockers that mean the cycle actively failed (vs was governance-blocked). */
    private const FAILURE_BLOCKERS = [
        'validation_failed',
        'commit_failed',
        'provider_produced_no_changes',
        'provider_not_called',
        'provider_scope_violation',
        'ap759_owner_command_failed',
        'sandbox_materialization_failed',
        'git_worktree_add_failed',
        'created_worktree_not_git',
        'owner_runtime_result_not_completed',
    ];

    /**
     * Hard pre-merge rule: a cycle may only merge when an operator-visible
     * pre-merge inbox (or AP-750 result-bridge evidence) was emitted first.
     *
     * @param  array<string,mixed>  $cycle
     * @return array{merge_allowed:bool,reason:?string,inbox_pre_merge:array<string,mixed>}
     */
    public function preMergeGate(array $cycle): array
    {
        $inbox = $this->preMergeInbox($cycle);

        return [
            'merge_allowed' => $inbox['present'],
            'reason' => $inbox['present'] ? null : self::PRE_MERGE_INBOX_REQUIRED,
            'inbox_pre_merge' => $inbox,
        ];
    }

    /**
     * Attach the canonical loop receipt to a cycle (non-destructive).
     *
     * @param  array<string,mixed>  $cycle
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    public function attach(array $cycle, array $context = []): array
    {
        $cycle['loop_receipt'] = $this->receiptFor($cycle, $context);

        return $cycle;
    }

    /**
     * Build a normalized, auditable receipt for a single cycle.
     *
     * @param  array<string,mixed>  $cycle
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    /**
     * Operator-facing per-cycle inbox summary for Product Mode / 24h observability.
     *
     * @param  array<string,mixed>  $cycle
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    public function cycleInboxSummary(array $cycle, array $context = []): array
    {
        $receipt = $this->receiptFor($cycle, $context);
        $finding = is_array($cycle['selected_finding'] ?? null) ? $cycle['selected_finding'] : [];
        $validation = $this->validationSummary($cycle);
        $merge = is_array($cycle['merge_governance'] ?? null) ? $cycle['merge_governance'] : [];
        $provider = is_array($cycle['provider_result'] ?? null) ? $cycle['provider_result'] : [];
        $blockers = array_values(array_filter((array) ($cycle['blockers'] ?? []), 'is_string'));
        $commit = is_array($cycle['commit'] ?? null) ? $cycle['commit'] : [];

        return [
            'schema_version' => self::INBOX_SUMMARY_SCHEMA,
            'ap_contract' => 'AP-791',
            'cycle_id' => (string) ($cycle['cycle_id'] ?? ''),
            'session_id' => (string) ($context['session_id'] ?? $cycle['session_id'] ?? ''),
            'achado' => (string) ($finding['title'] ?? $finding['summary'] ?? ''),
            'finding_id' => (string) ($finding['finding_id'] ?? $finding['id'] ?? ''),
            'decisao' => (string) ($receipt['lifecycle_state'] ?? ''),
            'owner' => (string) ($cycle['owner'] ?? ''),
            'provider' => (string) ($provider['provider'] ?? data_get($cycle, 'owner_flow.provider', '')),
            'model' => (string) ($provider['model'] ?? $provider['resolved_model_id'] ?? data_get($cycle, 'owner_flow.model', '')),
            'changed_files' => array_values(array_filter((array) ($cycle['changed_files'] ?? []), 'is_string')),
            'tests' => [
                'status' => (string) ($validation['status'] ?? 'not_run'),
                'passed' => $validation['passed'],
                'command_count' => (int) ($validation['command_count'] ?? 0),
            ],
            'merge_status' => [
                'merged' => (bool) ($cycle['merge_performed'] ?? false),
                'status' => (string) ($merge['status'] ?? data_get($receipt, 'merge.status', 'not_evaluated')),
                'auto_merge_class' => (string) ($cycle['auto_merge_class'] ?? data_get($merge, 'auto_merge_class', '')),
            ],
            'commit' => [
                'status' => (string) ($commit['status'] ?? ''),
                'commit_hash' => (string) ($commit['commit_hash'] ?? data_get($merge, 'merge_commit', '')),
            ],
            'blocker' => $blockers === [] ? null : $blockers[0],
            'blockers' => $blockers,
            'inbox_item_id' => (string) ($cycle['inbox_item_id'] ?? ''),
            'result_bridge_id' => (string) ($cycle['result_bridge_id'] ?? ''),
            'next_operator_action' => (string) ($receipt['next_action'] ?? ''),
            'loop_receipt' => $receipt,
        ];
    }

    /**
     * Build a normalized, auditable receipt for a single cycle.
     *
     * @param  array<string,mixed>  $cycle
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    public function receiptFor(array $cycle, array $context = []): array
    {
        $finalStatus = (string) ($cycle['final_status'] ?? '');
        $blockers = array_values(array_filter((array) ($cycle['blockers'] ?? []), 'is_string'));
        $merged = (bool) ($cycle['merge_performed'] ?? false);
        $state = $this->lifecycleState($finalStatus, $blockers, $merged, $cycle);

        $finding = is_array($cycle['selected_finding'] ?? null) ? $cycle['selected_finding'] : [];
        $findingId = (string) ($finding['finding_id'] ?? $finding['id'] ?? '');
        $findingTitle = (string) ($finding['title'] ?? $finding['summary'] ?? '');

        $preMergeInbox = $this->preMergeInbox($cycle);
        $merge = is_array($cycle['merge_governance'] ?? null) ? $cycle['merge_governance'] : [];
        $mergeHash = $this->mergeHash($merge);
        $evidenceRefs = $this->evidenceRefs($cycle);
        $sessionId = (string) ($context['session_id'] ?? $cycle['session_id'] ?? '');
        $area = (string) ($context['area_id'] ?? 'agentic_engineering_os');
        $focus = (string) ($context['focus'] ?? 'dev_forge');

        $warnings = $this->warnings($cycle, $context, $findingId, $findingTitle);
        $failureReasons = $state === self::STATE_FAILED ? $blockers : [];

        $receipt = [
            'schema_version' => self::RECEIPT_SCHEMA,
            'ap_contract' => 'AP-791',
            'cycle_id' => (string) ($cycle['cycle_id'] ?? ''),
            'session_id' => $sessionId,
            'cycle_index' => (int) ($cycle['cycle_index'] ?? 0),
            'lifecycle_state' => $state,
            'completed' => $state === self::STATE_MERGED,
            'merged' => $merged,
            'selected_finding' => ['finding_id' => $findingId, 'title' => $findingTitle],
            'owner' => (string) ($cycle['owner'] ?? ''),
            'sandbox_id' => (string) ($cycle['sandbox_id'] ?? ''),
            'branch_ref' => (string) ($cycle['branch_ref'] ?? ''),
            'worktree_path' => (string) ($cycle['worktree_path'] ?? ''),
            'changed_files' => array_values(array_filter((array) ($cycle['changed_files'] ?? []), 'is_string')),
            'validation' => $this->validationSummary($cycle),
            'inbox_pre_merge' => $preMergeInbox,
            'merge' => [
                'status' => (string) ($merge['status'] ?? ($merged ? 'merged' : 'not_evaluated')),
                'merged' => $merged,
                'auto_merge_class' => (string) ($cycle['auto_merge_class'] ?? data_get($merge, 'auto_merge_class', '')),
            ],
            'merge_hash' => $mergeHash,
            'inbox_post_merge' => $this->postMergeInbox($cycle, $state),
            'evidence_refs' => $evidenceRefs,
            'replay_command' => $this->replayCommand($sessionId, $area, $focus),
            'failure_reasons' => $failureReasons,
            'blockers' => $blockers,
            'next_action' => $this->nextAction($state, $blockers),
            'warnings' => $warnings,
            'provider_router_used' => (bool) data_get($cycle, 'owner_flow.provider_router_used', false),
        ];

        $missing = $this->missing($receipt, $state);
        $receipt['integrity'] = $missing === [] ? self::INTEGRITY_OK : self::INTEGRITY_INCOMPLETE;
        $receipt['missing'] = $missing;
        $receipt['receipt_hash'] = 'sha256:'.MissionCanonicalHash::sha256($this->identity($receipt));

        return $receipt;
    }

    /**
     * Normalize a whole AP-786 session: attach a receipt to each cycle, detect
     * cross-cycle duplicate titles as warnings, and summarize integrity.
     *
     * @param  array<string,mixed>  $session
     * @return array<string,mixed>
     */
    public function normalizeSession(array $session): array
    {
        $sessionId = (string) ($session['session_id'] ?? '');
        $area = (string) ($session['area_id'] ?? 'agentic_engineering_os');
        $focus = (string) ($session['focus'] ?? 'dev_forge');

        $seenTitles = [];
        $cycles = [];
        $incomplete = 0;
        $withWarnings = 0;

        foreach (array_values(array_filter((array) ($session['cycles'] ?? []), 'is_array')) as $cycle) {
            $cycle = $this->attach($cycle, [
                'session_id' => $sessionId,
                'area_id' => $area,
                'focus' => $focus,
                'seen_titles' => $seenTitles,
            ]);
            $receipt = $cycle['loop_receipt'];
            if (($receipt['integrity'] ?? '') === self::INTEGRITY_INCOMPLETE) {
                $incomplete++;
            }
            if (($receipt['warnings'] ?? []) !== []) {
                $withWarnings++;
            }
            foreach ($this->titlesOf($cycle) as $title) {
                $seenTitles[$title] = ($seenTitles[$title] ?? 0) + 1;
            }
            $cycles[] = $cycle;
        }

        $session['cycles'] = $cycles;
        $session['loop_receipt_integrity'] = [
            'schema_version' => self::RECEIPT_SCHEMA,
            'cycles' => count($cycles),
            'incomplete_receipts' => $incomplete,
            'cycles_with_warnings' => $withWarnings,
            'all_receipts_complete' => $incomplete === 0,
        ];

        return $session;
    }

    /**
     * Normalized titles (finding + commit) used for cross-cycle de-duplication.
     *
     * @param  array<string,mixed>  $cycle
     * @return list<string>
     */
    public function titlesOf(array $cycle): array
    {
        $titles = [];
        $findingTitle = $this->normalizeTitle((string) data_get($cycle, 'selected_finding.title', ''));
        if ($findingTitle !== '') {
            $titles[] = 'finding:'.$findingTitle;
        }
        $commitTitle = $this->commitTitle($cycle);
        if ($commitTitle !== '') {
            $titles[] = 'commit:'.$commitTitle;
        }

        return $titles;
    }

    /**
     * @param  array<string,mixed>  $cycle
     * @return array{present:bool,inbox_item_id:string,result_bridge_id:string}
     */
    private function preMergeInbox(array $cycle): array
    {
        $inboxId = (string) ($cycle['inbox_item_id'] ?? '');
        $resultBridgeId = (string) ($cycle['result_bridge_id'] ?? '');

        return [
            'present' => $inboxId !== '' || $resultBridgeId !== '',
            'inbox_item_id' => $inboxId,
            'result_bridge_id' => $resultBridgeId,
            'emitted_before_merge_attempt' => (bool) ($cycle['inbox_emitted_before_merge_attempt'] ?? false),
        ];
    }

    /**
     * @param  array<string,mixed>  $cycle
     * @return array{present:bool,inbox_item_id:string,result_receipt:string}
     */
    private function postMergeInbox(array $cycle, string $state): array
    {
        $inboxId = (string) ($cycle['inbox_item_id'] ?? '');
        $resultBridgeId = (string) ($cycle['result_bridge_id'] ?? '');

        return [
            'present' => $inboxId !== '' || $resultBridgeId !== '',
            'inbox_item_id' => $inboxId,
            'result_receipt' => $resultBridgeId,
            'kind' => $state === self::STATE_MERGED ? 'post_merge_result_receipt' : 'post_cycle_receipt',
        ];
    }

    /**
     * @param  array<string,mixed>  $merge
     */
    private function mergeHash(array $merge): string
    {
        foreach (['merge_commit', 'merged_commit', 'merge_hash', 'commit', 'merge_governance_hash', 'governance_hash'] as $key) {
            $value = (string) ($merge[$key] ?? '');
            if ($value !== '') {
                return $value;
            }
        }

        foreach (['merge_result.new_head'] as $path) {
            $value = (string) data_get($merge, $path, '');
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * @param  array<string,mixed>  $cycle
     * @return array<string,mixed>
     */
    private function evidenceRefs(array $cycle): array
    {
        return array_filter([
            'result_bridge_id' => (string) ($cycle['result_bridge_id'] ?? ''),
            'inbox_item_id' => (string) ($cycle['inbox_item_id'] ?? ''),
            'owner_execution_id' => (string) data_get($cycle, 'owner_flow.owner_execution_id', ''),
            'owner_sandbox_run_id' => (string) data_get($cycle, 'owner_flow.owner_sandbox_run_id', ''),
            'consumption_id' => (string) data_get($cycle, 'owner_flow.consumption_id', ''),
            'release_id' => (string) data_get($cycle, 'owner_flow.release_id', ''),
        ], static fn (string $v): bool => $v !== '');
    }

    /**
     * @param  array<string,mixed>  $cycle
     * @return array{status:string,passed:bool|null,command_count:int}
     */
    private function validationSummary(array $cycle): array
    {
        $passedRaw = data_get($cycle, 'validation.passed');
        $commands = (array) data_get($cycle, 'validation.commands', []);
        $results = (array) data_get($cycle, 'validation.results', []);
        // A merged owner-flow cycle carries no explicit top-level `validation` block — its
        // authoritative validation is the merge governor's green run that GATED the ff-merge
        // (merge_governance.validation). Surface it so a genuinely merged, validated slice is
        // recorded as validation=passed instead of not_run. This never manufactures a false
        // completion: a non-merged cycle keeps merge_hash null and lifecycle != MERGED, so the
        // completion tracker's provider-proof gate still blocks delivery regardless.
        if (! is_bool($passedRaw)) {
            $govPassed = data_get($cycle, 'merge_governance.validation.passed');
            if (is_bool($govPassed)) {
                $passedRaw = $govPassed;
                if ($commands === []) {
                    $commands = (array) data_get($cycle, 'merge_governance.validation.commands', []);
                }
                if ($results === []) {
                    $results = (array) data_get($cycle, 'merge_governance.validation.results', []);
                }
            }
        }
        $count = max(count($commands), count($results));

        $status = 'not_run';
        if ($passedRaw === true) {
            $status = 'passed';
        } elseif ($passedRaw === false) {
            $status = 'failed';
        }

        return [
            'status' => $status,
            'passed' => is_bool($passedRaw) ? $passedRaw : null,
            'command_count' => $count,
        ];
    }

    /**
     * @param  array<string,mixed>  $cycle
     * @param  list<string>  $blockers
     */
    private function lifecycleState(string $finalStatus, array $blockers, bool $merged, array $cycle): string
    {
        if ($finalStatus === 'cycle_completed' && $merged) {
            return self::STATE_MERGED;
        }
        if ($finalStatus === 'dry_run_planned') {
            return self::STATE_SKIPPED;
        }
        if ($finalStatus === 'cycle_completed_waiting_review_or_merge') {
            // Real owner-flow work that is not merged — waiting review, incl. Forge
            // runtime-dispatch plans. Never "completed".
            return self::STATE_PLANNED;
        }
        if ($finalStatus === 'blocked' || $blockers !== []) {
            return array_intersect($blockers, self::FAILURE_BLOCKERS) !== []
                ? self::STATE_FAILED
                : self::STATE_BLOCKED;
        }

        // Defensive default: an unmerged cycle is never silently "completed".
        return $merged ? self::STATE_MERGED : self::STATE_PLANNED;
    }

    private function nextAction(string $state, array $blockers): string
    {
        return match ($state) {
            self::STATE_MERGED => 'Pull/update main and continue to the next cycle; review the merged change in the operator inbox.',
            self::STATE_PLANNED => 'Operator review required before merge: review the pre-merge inbox item, then approve or reject the branch.',
            self::STATE_FAILED => 'Inspect the failure ('.($blockers[0] ?? 'unknown').'); the sandbox branch is isolated and was NOT merged. Re-run after the fix.',
            self::STATE_BLOCKED => 'Resolve the blocker ('.($blockers[0] ?? 'unknown').') before re-running this finding; nothing was merged.',
            self::STATE_SKIPPED => 'Dry-run only: re-run with --execute to attempt this cycle for real.',
            default => 'Review the cycle receipt before continuing the loop.',
        };
    }

    /**
     * @param  array<string,mixed>  $cycle
     * @param  array<string,mixed>  $context
     * @return list<string>
     */
    private function warnings(array $cycle, array $context, string $findingId, string $findingTitle): array
    {
        $warnings = [];
        $seen = is_array($context['seen_titles'] ?? null) ? $context['seen_titles'] : [];
        foreach ($this->titlesOf($cycle) as $title) {
            if (array_key_exists($title, $seen)) {
                $warnings[] = 'duplicate_commit_or_cycle_title';
                break;
            }
        }

        return array_values(array_unique($warnings));
    }

    /**
     * @param  array<string,mixed>  $receipt
     * @return list<string>
     */
    private function missing(array $receipt, string $state): array
    {
        $missing = [];
        if ((string) $receipt['cycle_id'] === '') {
            $missing[] = 'cycle_id';
        }
        if ((string) $receipt['session_id'] === '') {
            $missing[] = 'session_id';
        }
        if ((string) $receipt['selected_finding']['finding_id'] === '' && (string) $receipt['selected_finding']['title'] === '') {
            $missing[] = 'selected_finding';
        }
        if ((string) $receipt['replay_command'] === '') {
            $missing[] = 'replay_command';
        }
        if ((string) $receipt['next_action'] === '') {
            $missing[] = 'next_action';
        }

        if (in_array($state, [self::STATE_MERGED, self::STATE_PLANNED], true)) {
            if (! (bool) data_get($receipt, 'inbox_pre_merge.present', false)) {
                $missing[] = 'inbox_pre_merge';
            }
            if ($receipt['evidence_refs'] === []) {
                $missing[] = 'evidence_refs';
            }
        }
        if ($state === self::STATE_MERGED && (string) $receipt['merge_hash'] === '') {
            $missing[] = 'merge_hash';
        }
        if (in_array($state, [self::STATE_BLOCKED, self::STATE_FAILED], true) && $receipt['blockers'] === []) {
            $missing[] = 'blocker_reasons';
        }

        return $missing;
    }

    private function replayCommand(string $sessionId, string $area, string $focus): string
    {
        if ($sessionId !== '') {
            return 'php artisan atlas:software-company-stewardship:ap786-cycle replay --session-id='.$sessionId.' --json';
        }

        return 'php artisan atlas:software-company-stewardship:autonomous-evolution-session'
            .' --area='.$area.' --focus='.$focus.' --cycles=1 --execute --record --json';
    }

    /**
     * @param  array<string,mixed>  $cycle
     */
    private function commitTitle(array $cycle): string
    {
        $message = (string) (data_get($cycle, 'commit.message', '') ?: data_get($cycle, 'commit.title', ''));
        $firstLine = trim((string) (explode("\n", $message)[0] ?? ''));

        return $this->normalizeTitle($firstLine);
    }

    private function normalizeTitle(string $value): string
    {
        return trim((string) preg_replace('/\s+/', ' ', strtolower(trim($value))));
    }

    /**
     * @param  array<string,mixed>  $receipt
     * @return array<string,mixed>
     */
    private function identity(array $receipt): array
    {
        $copy = $receipt;
        unset($copy['receipt_hash']);

        return $copy;
    }
}
