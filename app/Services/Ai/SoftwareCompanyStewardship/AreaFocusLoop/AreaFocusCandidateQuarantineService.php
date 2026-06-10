<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\OwnerFlow\ZeroProviderPreflightGate;

/**
 * AP-790 · append-only quarantine ledger for area/focus loop candidates.
 *
 * Findings that waste provider/sandbox budget or fail repair policy are recorded
 * here so AP-786 selection and AP-790 duplicate protection never repeat them.
 * Historical session JSONL remains authoritative for audit; this ledger is the
 * fast machine-readable skip list for the 24h runner.
 */
final class AreaFocusCandidateQuarantineService
{
    public const SCHEMA = 'atlas.software_company_stewardship.ap790_candidate_quarantine.v1';

    public const FAILED_GATE_CAPSULE_SCHEMA = 'atlas.software_company_stewardship.ap786_failed_gate_capsule.v1';

    /** Blockers that permanently skip re-selection until operator clears quarantine. */
    public const PERMANENT_BLOCKERS = [
        'owner_runtime_no_patch_needed',
        'owner_runtime_result_failed',
        'owner_runtime_repeated_repair_no_progress',
        'owner_runtime_review_locked',
        'owner_runtime_php_syntax_error_after_max_repairs',
        'owner_runtime_senior_loop_execution_not_passed',
        'provider_diff_quality_gate_failed',
        'owner_runtime_provider_diff_quality_gate_failed',
        'owner_runtime_large_product_diff_without_test_update',
        'owner_runtime_large_product_deletion_without_test_update',
        'owner_runtime_large_single_file_deletion_without_test_update',
        'owner_runtime_deletion_heavy_product_diff_without_test_update',
        FinalDeliveryQualityGateService::BLOCKER,
        'owner_runtime_'.FinalDeliveryQualityGateService::BLOCKER,
        // Inert "new class + green test" that no runtime path consumes (and was
        // not honestly declared library_pending_wiring). Registered so the
        // InertNewClassDeliveryGate block cannot spin: shouldQuarantine() returns
        // true for it instead of leaving it to be reselected and reblocked.
        InertNewClassDeliveryGate::BLOCKER,
        'owner_runtime_'.InertNewClassDeliveryGate::BLOCKER,
        'minimax_no_code_extracted',
        'owner_runtime_minimax_no_code_extracted',
        'owner_runtime_routing_not_executable',
        'branch_already_merged_or_ancestor_of_base',
        'provider_scope_violation',
        'finding_false_positive',
        'ap748_false_positive',
        'ap748_interface_false_positive_test',
        ZeroProviderPreflightGate::REASON_PRIOR_NON_RETRYABLE_FAILURE_PATTERN,
        ZeroProviderPreflightGate::REASON_TEST_SUBJECT_NOT_AUTONOMOUSLY_TESTABLE,
        ZeroProviderPreflightGate::REASON_LARGE_EXISTING_RUNTIME_SURFACE_NEEDS_NARROWER_SLICE,
        ZeroProviderPreflightGate::REASON_EXISTING_RUNTIME_SURFACE_NEEDS_STRUCTURED_ANCHOR,
    ];

    /** Blockers quarantined after controlled repair is exhausted. */
    public const POST_REPAIR_BLOCKERS = [
        'validation_failed',
    ];

    /**
     * Transient infrastructure blockers. These describe the attempt failing for
     * environmental reasons (provider/runtime never produced a verdict), NOT a
     * property of the finding. A finding must never be permanently quarantined
     * on a transient blocker — doing so burns good work whenever the provider
     * times out or rate-limits. Historically a provider timeout co-occurred with
     * `owner_runtime_senior_loop_execution_not_passed`, so a single 120s timeout
     * permanently quarantined real findings and starved the loop into synthetic
     * recovery work. Transient blockers take precedence: the attempt is retried.
     */
    public const TRANSIENT_BLOCKERS = [
        'owner_runtime_provider_timeout',
        'owner_runtime_provider_rate_limited',
        'owner_runtime_provider_unavailable',
    ];

    private const ROUTING_RETRY_AFTER_SECONDS = 600;

    private ?string $storageRootOverride = null;

    public function setStorageRootForTesting(?string $dir): void
    {
        $this->storageRootOverride = $dir;
    }

    public function storageDir(): string
    {
        if ($this->storageRootOverride !== null) {
            return $this->storageRootOverride;
        }

        return function_exists('storage_path')
            ? storage_path('atlas/software_company_stewardship/area_focus_candidate_quarantine')
            : sys_get_temp_dir().'/atlas/software_company_stewardship/area_focus_candidate_quarantine';
    }

    public function ledgerPath(string $areaId, string $focus): string
    {
        return $this->storageDir().DIRECTORY_SEPARATOR.$this->key($areaId, $focus).'.jsonl';
    }

    /**
     * @return array<string,true>
     */
    public function quarantinedFindingKeys(string $areaId, string $focus): array
    {
        $keys = [];
        foreach ($this->readEntries($areaId, $focus) as $entry) {
            if (! $this->entryIsActive($entry)) {
                continue;
            }
            foreach ($this->entryFindingKeys($entry) as $key) {
                $keys[$key] = true;
            }
        }

        return $keys;
    }

    /**
     * @return array<string,true>
     */
    public function quarantinedFindingKeysForProvider(string $areaId, string $focus, string $provider): array
    {
        $keys = [];
        foreach ($this->readEntries($areaId, $focus) as $entry) {
            if (! $this->entryIsActiveForProvider($entry, $provider)) {
                continue;
            }
            foreach ($this->entryFindingKeys($entry) as $key) {
                $keys[$key] = true;
            }
        }

        return $keys;
    }

    public function isQuarantined(array $finding, string $areaId, string $focus): bool
    {
        $locked = $this->quarantinedFindingKeys($areaId, $focus);
        foreach ($this->findingKeys($finding) as $key) {
            if (isset($locked[$key])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $blockers
     */
    public function shouldQuarantine(array $blockers, array $cycle = []): bool
    {
        if ($blockers === []) {
            return false;
        }
        // A transient infra failure (provider timeout / rate limit / outage)
        // means the attempt never produced a real verdict, so it must never
        // permanently quarantine the finding — it is retried on a later cycle.
        if ($this->hasTransientBlocker($blockers)) {
            return false;
        }
        foreach ($blockers as $blocker) {
            if ($this->isQuarantineBlocker((string) $blocker)) {
                return true;
            }
        }
        if (($cycle['quarantine_after_repair_exhausted'] ?? false) === true) {
            return true;
        }

        return false;
    }

    /**
     * @param  list<string>  $blockers
     */
    public function hasTransientBlocker(array $blockers): bool
    {
        foreach ($blockers as $blocker) {
            if (in_array((string) $blocker, self::TRANSIENT_BLOCKERS, true)) {
                return true;
            }
        }

        return false;
    }

    public function isQuarantineBlocker(string $blocker): bool
    {
        return in_array($blocker, self::PERMANENT_BLOCKERS, true)
            || in_array($blocker, self::POST_REPAIR_BLOCKERS, true);
    }

    /**
     * @param  list<string>  $blockers
     * @return array{action:string,max_retries:int,emit_failure_capsule:bool,stop_session:bool,reason:string}
     */
    public function repairPolicyForBlockers(array $blockers): array
    {
        if (in_array('provider_scope_violation', $blockers, true)) {
            return [
                'action' => 'quarantine_stop',
                'max_retries' => 0,
                'emit_failure_capsule' => true,
                'stop_session' => true,
                'reason' => 'scope_violation',
            ];
        }
        if (in_array('owner_runtime_routing_not_executable', $blockers, true)) {
            return [
                'action' => 'quarantine_continue',
                'max_retries' => 0,
                'emit_failure_capsule' => false,
                'stop_session' => false,
                'reason' => 'routing_not_executable',
            ];
        }
        if (in_array('owner_runtime_no_patch_needed', $blockers, true)) {
            return [
                'action' => 'quarantine_continue',
                'max_retries' => 0,
                'emit_failure_capsule' => true,
                'stop_session' => false,
                'reason' => 'no_patch_needed',
            ];
        }
        if (in_array('owner_runtime_result_failed', $blockers, true)) {
            return [
                'action' => 'quarantine_continue',
                'max_retries' => 0,
                'emit_failure_capsule' => true,
                'stop_session' => false,
                'reason' => 'owner_runtime_result_failed',
            ];
        }
        foreach ([
            'owner_runtime_repeated_repair_no_progress' => 'repeated_repair_no_progress',
            'owner_runtime_review_locked' => 'review_locked',
            'owner_runtime_php_syntax_error_after_max_repairs' => 'php_syntax_error_after_max_repairs',
        ] as $repairBlocker => $reason) {
            if (in_array($repairBlocker, $blockers, true)) {
                return [
                    'action' => 'quarantine_continue',
                    'max_retries' => 0,
                    'emit_failure_capsule' => true,
                    'stop_session' => false,
                    'reason' => $reason,
                ];
            }
        }
        if (in_array('validation_failed', $blockers, true)) {
            return [
                'action' => 'retry_then_quarantine',
                'max_retries' => 1,
                'emit_failure_capsule' => true,
                'stop_session' => false,
                'reason' => 'validation_failed',
            ];
        }
        if (in_array('owner_runtime_senior_loop_execution_not_passed', $blockers, true)) {
            return [
                'action' => 'quarantine_continue',
                'max_retries' => 0,
                'emit_failure_capsule' => true,
                'stop_session' => false,
                'reason' => 'senior_loop_execution_not_passed',
            ];
        }
        foreach ([
            'provider_diff_quality_gate_failed',
            'owner_runtime_provider_diff_quality_gate_failed',
            'runtime_wiring_without_focused_runtime_test',
            'owner_runtime_runtime_wiring_without_focused_runtime_test',
            'large_product_diff_without_test_update',
            'owner_runtime_large_product_diff_without_test_update',
            'large_product_deletion_without_test_update',
            'owner_runtime_large_product_deletion_without_test_update',
            'large_single_file_deletion_without_test_update',
            'owner_runtime_large_single_file_deletion_without_test_update',
            'deletion_heavy_product_diff_without_test_update',
            'owner_runtime_deletion_heavy_product_diff_without_test_update',
        ] as $diffQualityBlocker) {
            if (in_array($diffQualityBlocker, $blockers, true)) {
                return [
                    'action' => 'quarantine_continue',
                    'max_retries' => 0,
                    'emit_failure_capsule' => true,
                    'stop_session' => false,
                    'reason' => 'provider_diff_quality_gate_failed',
                ];
            }
        }
        foreach ([FinalDeliveryQualityGateService::BLOCKER, 'owner_runtime_'.FinalDeliveryQualityGateService::BLOCKER] as $deliveryBlocker) {
            if (in_array($deliveryBlocker, $blockers, true)) {
                return [
                    'action' => 'quarantine_continue',
                    'max_retries' => 0,
                    'emit_failure_capsule' => true,
                    'stop_session' => false,
                    'reason' => 'delivery_not_final_scaffold_or_mock',
                ];
            }
        }
        foreach ([InertNewClassDeliveryGate::BLOCKER, 'owner_runtime_'.InertNewClassDeliveryGate::BLOCKER] as $inertBlocker) {
            if (in_array($inertBlocker, $blockers, true)) {
                return [
                    'action' => 'quarantine_continue',
                    'max_retries' => 0,
                    'emit_failure_capsule' => true,
                    'stop_session' => false,
                    'reason' => 'inert_new_class_not_runtime_wired',
                ];
            }
        }
        foreach (['minimax_no_code_extracted', 'owner_runtime_minimax_no_code_extracted'] as $noCodeBlocker) {
            if (in_array($noCodeBlocker, $blockers, true)) {
                return [
                    'action' => 'quarantine_continue',
                    'max_retries' => 0,
                    'emit_failure_capsule' => true,
                    'stop_session' => false,
                    'reason' => 'no_code_extracted',
                ];
            }
        }
        if (in_array(ZeroProviderPreflightGate::REASON_PRIOR_NON_RETRYABLE_FAILURE_PATTERN, $blockers, true)) {
            return [
                'action' => 'quarantine_continue',
                'max_retries' => 0,
                'emit_failure_capsule' => true,
                'stop_session' => false,
                'reason' => 'prior_non_retryable_failure_pattern',
            ];
        }
        if (in_array(ZeroProviderPreflightGate::REASON_TEST_SUBJECT_NOT_AUTONOMOUSLY_TESTABLE, $blockers, true)) {
            return [
                'action' => 'quarantine_continue',
                'max_retries' => 0,
                'emit_failure_capsule' => false,
                'stop_session' => false,
                'reason' => 'test_subject_not_autonomously_testable',
            ];
        }
        if (in_array(ZeroProviderPreflightGate::REASON_LARGE_EXISTING_RUNTIME_SURFACE_NEEDS_NARROWER_SLICE, $blockers, true)) {
            return [
                'action' => 'quarantine_continue',
                'max_retries' => 0,
                'emit_failure_capsule' => false,
                'stop_session' => false,
                'reason' => 'large_existing_runtime_surface_needs_narrower_slice',
            ];
        }
        if (in_array(ZeroProviderPreflightGate::REASON_EXISTING_RUNTIME_SURFACE_NEEDS_STRUCTURED_ANCHOR, $blockers, true)) {
            return [
                'action' => 'quarantine_continue',
                'max_retries' => 0,
                'emit_failure_capsule' => false,
                'stop_session' => false,
                'reason' => 'existing_runtime_surface_needs_structured_anchor',
            ];
        }
        if (in_array('branch_already_merged_or_ancestor_of_base', $blockers, true)) {
            return [
                'action' => 'quarantine_continue',
                'max_retries' => 0,
                'emit_failure_capsule' => false,
                'stop_session' => false,
                'reason' => 'branch_already_merged_or_ancestor_of_base',
            ];
        }
        foreach (['finding_false_positive', 'ap748_false_positive', 'ap748_interface_false_positive_test'] as $falsePositive) {
            if (in_array($falsePositive, $blockers, true)) {
                return [
                    'action' => 'quarantine_continue',
                    'max_retries' => 0,
                    'emit_failure_capsule' => false,
                    'stop_session' => false,
                    'reason' => 'false_positive',
                ];
            }
        }

        return [
            'action' => 'none',
            'max_retries' => 0,
            'emit_failure_capsule' => false,
            'stop_session' => false,
            'reason' => 'not_repairable',
        ];
    }

    /**
     * @param  list<string>  $blockers
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    public function appendFromCycle(string $areaId, string $focus, array $finding, array $blockers, array $context = []): array
    {
        $primaryBlocker = $this->primaryBlocker($blockers);
        $retryAfter = $context['retry_after'] ?? null;
        if ($retryAfter === null && $primaryBlocker === 'owner_runtime_routing_not_executable') {
            $retryAfter = $this->retryAfter(self::ROUTING_RETRY_AFTER_SECONDS);
        }
        $permanent = $retryAfter === null || $retryAfter === 'permanent';

        $entry = [
            'schema_version' => self::SCHEMA,
            'quarantine_id' => 'afq_'.substr(MissionCanonicalHash::sha256([
                $areaId, $focus, $this->findingKeys($finding), $primaryBlocker, AreaFocusUtcClock::atomNow(),
            ]), 0, 18),
            'area_id' => $areaId,
            'focus' => $focus,
            'finding_id' => (string) ($finding['finding_id'] ?? ''),
            'finding_hash' => (string) ($finding['finding_hash'] ?? ''),
            'title' => (string) ($finding['title'] ?? ''),
            'finding_kind' => (string) ($finding['kind'] ?? ''),
            'origin_type' => (string) ($finding['origin_type'] ?? ''),
            'autonomous_execution_reason' => (string) ($finding['autonomous_execution_reason'] ?? ''),
            'blocker' => $primaryBlocker,
            'blockers' => AreaFocusStringListNormalizer::uniqueStringValues($blockers),
            'owner' => (string) ($context['owner'] ?? ''),
            'provider' => (string) ($context['provider'] ?? ''),
            'model' => (string) ($context['model'] ?? ''),
            'branch_ref' => (string) ($context['branch_ref'] ?? ''),
            'worktree_path' => (string) ($context['worktree_path'] ?? ''),
            'failure_signature' => $this->failureSignature($blockers, $context),
            'retry_after' => $permanent ? 'permanent' : (string) $retryAfter,
            'reason' => (string) ($context['reason'] ?? $this->repairPolicyForBlockers($blockers)['reason']),
            'recorded_at' => AreaFocusUtcClock::atomNow(),
        ];
        $entry['entry_hash'] = 'sha256:'.MissionCanonicalHash::sha256($entry);
        $this->append($areaId, $focus, $entry);

        return $entry;
    }

    /**
     * @param  list<string>  $blockers
     * @param  array<string,mixed>  $context
     */
    public function failureSignature(array $blockers, array $context = []): string
    {
        return 'sha256:'.MissionCanonicalHash::sha256([
            'blockers' => AreaFocusStringListNormalizer::uniqueStringValues($blockers),
            'cycle_id' => (string) ($context['cycle_id'] ?? ''),
            'branch_ref' => (string) ($context['branch_ref'] ?? ''),
            'sandbox_id' => (string) ($context['sandbox_id'] ?? ''),
            'post_execution_skip' => (string) ($context['post_execution_skip'] ?? ''),
        ]);
    }

    /**
     * @param  list<string>  $blockers
     * @param  array<string,mixed>  $finding
     * @param  list<string>  $allowedFiles
     * @param  list<string>  $changedFiles
     * @return array<string,mixed>
     */
    public function buildFailureCapsule(array $blockers, array $finding, array $allowedFiles = [], array $changedFiles = []): array
    {
        $primary = $this->primaryBlocker($blockers);
        $capsule = [
            'schema_version' => self::FAILED_GATE_CAPSULE_SCHEMA,
            'failure_class' => $primary,
            'finding_id' => (string) ($finding['finding_id'] ?? ''),
            'finding_hash' => (string) ($finding['finding_hash'] ?? ''),
            'title' => (string) ($finding['title'] ?? ''),
            'allowed_files' => $allowedFiles,
            'changed_files' => $changedFiles,
            'blockers' => AreaFocusStringListNormalizer::uniqueStringValues($blockers),
            'root_cause_present' => $primary !== '',
            'generated_at' => AreaFocusUtcClock::atomNow(),
        ];
        $capsule['capsule_hash'] = 'sha256:'.MissionCanonicalHash::sha256($capsule);

        return $capsule;
    }

    /**
     * @param  array<string,mixed>  $entry
     */
    public function append(string $areaId, string $focus, array $entry): void
    {
        $path = $this->ledgerPath($areaId, $focus);
        AreaFocusAppendOnlyJsonlRecorder::append($path, $entry);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function readEntries(string $areaId, string $focus): array
    {
        return AreaFocusJsonlReader::rowsWithSchemaVersion($this->ledgerPath($areaId, $focus), self::SCHEMA);
    }

    /**
     * @param  array<string,mixed>  $entry
     * @return list<string>
     */
    private function entryFindingKeys(array $entry): array
    {
        return AreaFocusStringListNormalizer::uniqueStringValues(array_filter([
            (string) ($entry['finding_id'] ?? ''),
            (string) ($entry['finding_hash'] ?? ''),
            (string) ($entry['title'] ?? ''),
        ], static fn (string $value): bool => $value !== ''));
    }

    /**
     * @param  array<string,mixed>  $finding
     * @return list<string>
     */
    private function findingKeys(array $finding): array
    {
        return AreaFocusStringListNormalizer::uniqueStringValues(array_filter([
            (string) ($finding['finding_id'] ?? ''),
            (string) ($finding['finding_hash'] ?? ''),
            (string) ($finding['title'] ?? ''),
        ], static fn (string $value): bool => $value !== ''));
    }

    /** @param list<string> $blockers */
    private function primaryBlocker(array $blockers): string
    {
        foreach (array_merge(self::PERMANENT_BLOCKERS, self::POST_REPAIR_BLOCKERS) as $known) {
            if (in_array($known, $blockers, true)) {
                return $known;
            }
        }

        return (string) ($blockers[0] ?? 'unknown_blocker');
    }

    /**
     * @param  array<string,mixed>  $entry
     */
    private function entryIsActive(array $entry): bool
    {
        $retryAfter = (string) ($entry['retry_after'] ?? 'permanent');
        if ($retryAfter !== '' && $retryAfter !== 'permanent') {
            $retryAt = strtotime($retryAfter) ?: 0;

            return $retryAt === 0 || $retryAt > time();
        }

        if ((string) ($entry['blocker'] ?? '') === ZeroProviderPreflightGate::REASON_TEST_SUBJECT_NOT_AUTONOMOUSLY_TESTABLE
            && ! $this->entryLooksPureTestAuthoring($entry)) {
            return false;
        }

        if ($this->entryLooksLegacyPlanSliceScopeLayerFalsePositive($entry)) {
            return false;
        }
        if ($this->entryLooksExecutableContractGateFalsePositive($entry)) {
            return false;
        }

        if ($this->entryLooksStaleReviewLockWithoutLiveWorktree($entry)) {
            return false;
        }

        if ((string) ($entry['blocker'] ?? '') !== 'owner_runtime_routing_not_executable') {
            return true;
        }

        $recordedAt = strtotime((string) ($entry['recorded_at'] ?? '')) ?: 0;
        if ($recordedAt === 0) {
            return true;
        }

        return ($recordedAt + self::ROUTING_RETRY_AFTER_SECONDS) > time();
    }

    /** @param array<string,mixed> $entry */
    private function entryIsActiveForProvider(array $entry, string $provider): bool
    {
        if (! $this->entryIsActive($entry)) {
            return false;
        }

        $provider = AreaFocusProviderNormalizer::quarantineProviderId($provider);
        if ($provider === '' || ! $this->entryAllowsProviderFallback($entry)) {
            return true;
        }

        $entryProvider = AreaFocusProviderNormalizer::quarantineProviderId((string) ($entry['provider'] ?? ''));
        if ($entryProvider !== '') {
            return $entryProvider === $provider;
        }

        // Legacy provider-quality quarantines predate provider metadata. Treat them
        // as MiniMax-era locks for the explicitly requested Sonnet fallback only;
        // once a Claude/Sonnet attempt records its provider, same-provider locks
        // become active again and the loop will not spin on the same failed slice.
        return $provider !== 'claude_cli';
    }

    /** @param array<string,mixed> $entry */
    private function entryAllowsProviderFallback(array $entry): bool
    {
        if (! $this->entryLooksPlanSlice($entry)) {
            return false;
        }
        if (! $this->entryHasProviderDiffQualityBlocker($entry)) {
            return false;
        }

        return ! $this->entryHasLiveWorktree($entry);
    }

    /** @param array<string,mixed> $entry */
    private function entryLooksStaleReviewLockWithoutLiveWorktree(array $entry): bool
    {
        $blocker = (string) ($entry['blocker'] ?? '');
        $blockers = $this->entryBlockers($entry);
        $worktree = trim((string) ($entry['worktree_path'] ?? ''));

        return ($blocker === 'owner_runtime_review_locked' || in_array('owner_runtime_review_locked', $blockers, true))
            && $worktree !== ''
            && ! $this->entryHasLiveWorktree($entry);
    }

    /** @param array<string,mixed> $entry */
    private function entryHasProviderDiffQualityBlocker(array $entry): bool
    {
        $blocker = (string) ($entry['blocker'] ?? '');
        $blockers = $this->entryBlockers($entry);

        return in_array($blocker, ['provider_diff_quality_gate_failed', 'owner_runtime_provider_diff_quality_gate_failed'], true)
            || in_array('provider_diff_quality_gate_failed', $blockers, true)
            || in_array('owner_runtime_provider_diff_quality_gate_failed', $blockers, true);
    }

    /** @param array<string,mixed> $entry */
    private function entryLooksPlanSlice(array $entry): bool
    {
        $kind = strtolower(trim((string) ($entry['finding_kind'] ?? '')));
        $origin = strtolower(trim((string) ($entry['origin_type'] ?? '')));
        $reason = strtolower(trim((string) ($entry['autonomous_execution_reason'] ?? '')));
        $findingId = trim((string) ($entry['finding_id'] ?? ''));

        return $kind === 'plan_slice'
            || $origin === 'build_plan_decomposition'
            || $reason === 'operator_authorized_plan_execution'
            || preg_match('/^S\d+$/', $findingId) === 1;
    }

    /** @param array<string,mixed> $entry */
    private function entryHasLiveWorktree(array $entry): bool
    {
        $worktree = trim((string) ($entry['worktree_path'] ?? ''));

        return $worktree !== '' && is_dir($worktree);
    }

    /**
     * @param  array<string,mixed>  $entry
     * @return list<string>
     */
    private function entryBlockers(array $entry): array
    {
        return AreaFocusStringListNormalizer::truthyStringifiedScalarValues($entry['blockers'] ?? []);
    }

    /** @param array<string,mixed> $entry */
    private function entryLooksLegacyPlanSliceScopeLayerFalsePositive(array $entry): bool
    {
        if ((string) ($entry['blocker'] ?? '') !== ZeroProviderPreflightGate::REASON_PRIOR_NON_RETRYABLE_FAILURE_PATTERN) {
            return false;
        }

        $blockers = $this->entryBlockers($entry);
        if (! in_array(ZeroProviderPreflightGate::REASON_SCOPE_MULTIPLE_LAYERS, $blockers, true)) {
            return false;
        }

        $kind = strtolower(trim((string) ($entry['finding_kind'] ?? '')));
        $reason = strtolower(trim((string) ($entry['autonomous_execution_reason'] ?? '')));
        $findingId = trim((string) ($entry['finding_id'] ?? ''));

        return $kind === 'plan_slice'
            || $reason === 'operator_authorized_plan_execution'
            || preg_match('/^S\d+$/', $findingId) === 1;
    }

    /** @param array<string,mixed> $entry */
    private function entryLooksExecutableContractGateFalsePositive(array $entry): bool
    {
        $blockers = $this->entryBlockers($entry);
        if (! in_array('contract_only_diff_without_runtime_wiring', $blockers, true)
            || ! in_array('provider_diff_quality_gate_failed', $blockers, true)) {
            return false;
        }

        $kind = strtolower(trim((string) ($entry['finding_kind'] ?? '')));
        $reason = strtolower(trim((string) ($entry['autonomous_execution_reason'] ?? '')));
        $findingId = trim((string) ($entry['finding_id'] ?? ''));
        if ($kind !== 'plan_slice'
            && $reason !== 'operator_authorized_plan_execution'
            && preg_match('/^S\d+$/', $findingId) !== 1) {
            return false;
        }

        $title = (string) ($entry['title'] ?? '');
        if (! str_contains($title, 'Contract.php')) {
            return false;
        }

        foreach (['fromArray', 'toArray', 'defaults', 'score(', 'validate(', 'classify('] as $signal) {
            if (str_contains($title, $signal)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string,mixed> $entry */
    private function entryLooksPureTestAuthoring(array $entry): bool
    {
        $kind = strtolower(trim((string) ($entry['finding_kind'] ?? '')));
        if (in_array($kind, ['test', 'tests', 'coverage', 'missing_test'], true)) {
            return true;
        }

        $originType = strtolower(trim((string) ($entry['origin_type'] ?? '')));
        if ($originType === 'missing_test' || str_ends_with($originType, '_test')) {
            return true;
        }

        $reason = strtolower(trim((string) ($entry['autonomous_execution_reason'] ?? '')));
        $findingId = strtolower(trim((string) ($entry['finding_id'] ?? '')));
        $title = strtolower(trim((string) ($entry['title'] ?? '')));

        return str_contains($reason, 'missing_test')
            || str_contains($findingId, 'missing_test')
            || str_ends_with($findingId, '_test')
            || str_contains($title, 'missing test')
            || str_contains($title, 'focused unit coverage')
            || str_contains($title, 'regression coverage');
    }

    private function key(string $areaId, string $focus): string
    {
        $slug = static fn (string $value): string => AreaFocusSlugNormalizer::lowerFileToken($value, '');

        return $slug($areaId).'__'.$slug($focus);
    }

    private function retryAfter(int $seconds): string
    {
        return AreaFocusUtcClock::atomNow($seconds);
    }
}
