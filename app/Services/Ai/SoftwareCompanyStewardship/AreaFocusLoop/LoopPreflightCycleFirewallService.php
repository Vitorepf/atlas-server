<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\Mission\MissionCanonicalHash;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * AP-810 / LHL-01 — Loop Preflight + Cycle Firewall (AP-807 Part 1).
 *
 * The LAST gate before cost. It answers exactly one question:
 *
 *   > Is this exact next cycle allowed to spend a provider call?
 *
 * If the answer is not an explicit `allow`, the cycle must stop as `block`
 * BEFORE provider invocation. This service is read-only / deterministic /
 * input-seam driven. It NEVER invokes a provider, NEVER runs the loop, NEVER
 * merges, NEVER deletes a branch/worktree, NEVER mutates code.
 *
 * It implements AP-807 Part 1 Gates A-E exactly:
 *   A Environment State    — kill switch / stale lock / dirty unrelated worktree /
 *                            orphan sandbox / lease conflict / lane head resolvable /
 *                            factory-scoped for main / provider leftover cleanup plan.
 *   B Candidate Truth      — refuse starvation recovery in factory_max, missing-test
 *                            filler in high-power mode, benchmark/rivals as
 *                            implementation, review-locked w/o changed blocker,
 *                            non-transient quarantine, duplicate spin, missing
 *                            source/evidence/canonical reason, planned-as-executable.
 *   C Packet & Slice Fit   — bounded packet has all required fields; reject
 *                            "implement the whole feature".
 *   D Authority & Merge    — cross-system needs armed envelope; cross-system
 *                            autonomous routes to lane NOT main; main auto-merge only
 *                            factory-scoped within risk ceiling; forge plan-only is
 *                            not real execution; risk <= ceiling; owner can produce code.
 *   E Cost & Provider      — per-run budget not exhausted; provider timeout >= floor;
 *                            route present when required; same candidate didn't already
 *                            spend a call + hit same blocker; not over max_blocked_in_row;
 *                            preflight can write a receipt.
 *
 * Honesty rules (operator does not accept false claims):
 *   - blocked is NEVER dressed as allow;
 *   - starvation recovery / filler / benchmark are NEVER counted as admissible work;
 *   - a sandbox commit is not a merge and a plan-only Forge path is not implementation
 *     (those are enforced post-cycle by LHL-02; here we refuse to send them to a provider
 *     as if they were real implementation);
 *   - status=allow ONLY when every gate passed.
 *
 * Contract: AP-807 Part 1; AP-810 build contract slice LHL-01.
 * Reference shape: AreaFocusSelfConstructionAdmissionBridgeService (packet shape).
 */
final class LoopPreflightCycleFirewallService
{
    public const REPORT_SCHEMA = 'atlas.software_company_stewardship.loop_preflight_firewall.v1';

    public const STATUS_ALLOW = 'allow';

    public const STATUS_BLOCK = 'block';

    /** Block-status taxonomy (AP-807 Gate B). status=allow OR one of these — never `success`. */
    public const BLOCK_BACKLOG_EXHAUSTED = 'backlog_exhausted';

    public const BLOCK_ADMISSION_BLOCKED = 'admission_blocked';

    public const BLOCK_REVIEW_LOCKED = 'review_locked';

    public const BLOCK_QUARANTINED = 'quarantined';

    public const BLOCK_DUPLICATE_BLOCKED = 'duplicate_blocked';

    /** Minimum provider-call timeout (seconds) required for a real cycle (AP-805 floor). */
    public const MIN_PROVIDER_TIMEOUT_SECONDS = 300;

    /** A bounded packet may touch at most this many files; bigger is not "bounded". */
    public const MAX_PACKET_ALLOWED_FILES = 8;

    /** The five hard gates (all must pass for status=allow). */
    private const GATE_ENVIRONMENT = 'environment';

    private const GATE_CANDIDATE_TRUTH = 'candidate_truth';

    private const GATE_PACKET_FITNESS = 'packet_fitness';

    private const GATE_AUTHORITY = 'authority';

    private const GATE_COST = 'cost';

    /** Required bounded-packet fields (AP-807 Gate C). */
    private const REQUIRED_PACKET_FIELDS = [
        'parent_finding_id',
        'packet_id',
        'slice_sequence',
        'active_slice_id',
        'allowed_files',
        'forbidden_files',
        'required_tests',
        'expected_diff_shape',
        'stop_conditions',
        'owner_runtime',
    ];

    /** Owner runtimes that can actually produce code today (AP-807 Gate D). */
    private const CODE_CAPABLE_OWNERS = ['atlas_dev', 'cursor', 'claude', 'codex', 'multi_agent_workcell'];

    /**
     * Single entrypoint. Every key is optional; the diagnostic default analyzes a
     * clean/empty state and never crashes.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function evaluate(array $input = []): array
    {
        // A wiring-phase `fixture` (from --fixture-file) may carry a whole cycle
        // record; merge it under the explicit input so direct keys still win.
        $input = $this->mergeFixture($input);

        $area = trim((string) ($input['area'] ?? 'agentic_engineering_os')) ?: 'agentic_engineering_os';
        $focus = trim((string) ($input['focus'] ?? 'dev_forge')) ?: 'dev_forge';
        $runId = trim((string) ($input['run_id'] ?? ''));
        $cycleIndex = (int) ($input['cycle_index'] ?? 0);

        $candidate = is_array($input['candidate'] ?? null) ? $input['candidate'] : [];
        $packet = is_array($input['packet'] ?? null)
            ? $input['packet']
            : (is_array($candidate['self_construction_packet'] ?? null) ? $candidate['self_construction_packet'] : []);
        $scopeProfile = strtolower(trim((string) ($input['scope_profile'] ?? 'factory_max'))) ?: 'factory_max';
        $mergeTarget = $this->normalizeMergeTarget((string) ($input['merge_target'] ?? 'integration_lane'));

        $blockers = [];
        $warnings = [];
        $gateResults = [];
        /** @var array<string,string> $blockStatuses */
        $blockStatuses = [];

        $env = $this->gateEnvironment($input, $mergeTarget, $blockers, $warnings);
        $gateResults[self::GATE_ENVIRONMENT] = $env;

        $candidateTruth = $this->gateCandidateTruth($input, $candidate, $scopeProfile, $blockers, $warnings, $blockStatuses);
        $gateResults[self::GATE_CANDIDATE_TRUTH] = $candidateTruth;

        $packetFitness = $this->gatePacketFitness($input, $candidate, $packet, $blockers, $warnings, $blockStatuses);
        $gateResults[self::GATE_PACKET_FITNESS] = $packetFitness;

        $authority = $this->gateAuthority($input, $candidate, $packet, $mergeTarget, $scopeProfile, $blockers, $warnings);
        $gateResults[self::GATE_AUTHORITY] = $authority;

        $cost = $this->gateCost($input, $candidate, $blockers, $warnings);
        $gateResults[self::GATE_COST] = $cost;

        $allPassed = $env && $candidateTruth && $packetFitness && $authority && $cost;
        $status = $allPassed ? self::STATUS_ALLOW : self::STATUS_BLOCK;
        // HARD INVARIANT #1: no provider call before preflight `allow`. provider_allowed
        // tracks status exactly — it is false whenever status=block.
        $providerAllowed = $status === self::STATUS_ALLOW;

        $blockStatus = $this->resolveBlockStatus($status, $blockStatuses, $blockers);

        $candidateOut = [
            'finding_id' => trim((string) ($candidate['finding_id'] ?? '')),
            'packet_id' => $this->nullableString($packet['packet_id'] ?? ($candidate['packet_id'] ?? null)),
            'active_slice_id' => $this->nullableString($candidate['active_slice_id'] ?? ($packet['active_slice_id'] ?? null)),
            'source' => $this->normalizeSource((string) ($candidate['source'] ?? ($candidate['origin_type'] ?? ''))),
        ];

        $payload = [
            'schema_version' => self::REPORT_SCHEMA,
            'ap_contract' => 'AP-807',
            'slice_id' => 'LHL-01',
            'status' => $status,
            'block_status' => $blockStatus,
            'firewall_id' => 'lpf_'.substr(MissionCanonicalHash::sha256([
                $area,
                $focus,
                $runId,
                $cycleIndex,
                $candidateOut['finding_id'],
                $candidateOut['packet_id'] ?? '',
                $mergeTarget,
            ]), 0, 16),
            'run_id' => $runId,
            'cycle_index' => $cycleIndex,
            'area' => $area,
            'focus' => $focus,
            'checked_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM),
            'provider_allowed' => $providerAllowed,
            'candidate' => $candidateOut,
            'merge_target' => $mergeTarget,
            'scope_profile' => $scopeProfile,
            'gates' => [
                self::GATE_ENVIRONMENT => $env ? 'passed' : 'blocked',
                self::GATE_CANDIDATE_TRUTH => $candidateTruth ? 'passed' : 'blocked',
                self::GATE_PACKET_FITNESS => $packetFitness ? 'passed' : 'blocked',
                self::GATE_AUTHORITY => $authority ? 'passed' : 'blocked',
                self::GATE_COST => $cost ? 'passed' : 'blocked',
            ],
            'gate_details' => $gateResults,
            'blockers' => array_values(array_unique($blockers)),
            'warnings' => array_values(array_unique($warnings)),
            'next_action' => $status === self::STATUS_ALLOW ? 'continue' : 'stop_'.$blockStatus,
            'claim_policy' => [
                'read_only' => true,
                'runs_provider' => false,
                'runs_loop' => false,
                'runs_merge' => false,
                'deletes_branches' => false,
                'blocked_never_dressed_as_ready' => true,
            ],
        ];

        $payload['report_hash'] = 'sha256:'.MissionCanonicalHash::sha256($this->withoutVolatile($payload));

        return $payload;
    }

    /**
     * Department maturity check entry (step 3/3 — first rule only).
     *
     * Validates input shape. Empty input returns {@see DepartmentMaturityCheckContract::defaults}.
     * Step-3 first rule: a {@code department_maturity_snapshot} maps through
     * {@see DepartmentMaturityCheckContract::fromArray}. Preflight wiring is a future step.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function departmentMaturityCheck(array $input = []): array
    {
        $this->validateDepartmentMaturityCheckInput($input);

        if ($input === [] || ! array_key_exists('department_maturity_snapshot', $input)) {
            return DepartmentMaturityCheckContract::defaults()->toArray();
        }

        return DepartmentMaturityCheckContract::fromArray($input)->toArray();
    }

    // ---------------------------------------------------------------- Gate A

    /**
     * Gate A — Environment State. Hard block when the host is not safe to spend a
     * provider call on the next cycle.
     *
     * @param  array<string,mixed>  $input
     * @param  list<string>  $blockers
     * @param  list<string>  $warnings
     * @return bool true=passed
     */
    private function gateEnvironment(array $input, string $mergeTarget, array &$blockers, array &$warnings): bool
    {
        $ok = true;

        if ((bool) ($input['kill_switch_active'] ?? false)) {
            $blockers[] = 'kill_switch_active';
            $ok = false;
        }

        if ((bool) ($input['stale_lock'] ?? false)) {
            $blockers[] = 'stale_loop_lock_held';
            $ok = false;
        }
        if ((bool) ($input['loop_lock_held'] ?? false)) {
            $blockers[] = 'loop_lock_held';
            $ok = false;
        }

        // Unrelated dirty worktree blocks; classified/related dirty is only a warning.
        $dirtyUnrelated = $this->stringList($input['dirty_unrelated_paths'] ?? []);
        if ($dirtyUnrelated !== []) {
            $blockers[] = 'dirty_unrelated_worktree';
            $ok = false;
        }
        if ($this->stringList($input['dirty_classified_paths'] ?? []) !== []) {
            $warnings[] = 'dirty_worktree_classified_present';
        }

        // An orphan sandbox branch that blocks the selected packet must be cleaned first.
        if ((bool) ($input['orphan_sandbox_blocks_packet'] ?? false)) {
            $blockers[] = 'orphan_sandbox_branch_blocks_packet';
            $ok = false;
        }

        if ((int) ($input['open_merge_leases'] ?? 0) > 0 || (bool) ($input['merge_lease_conflict'] ?? false)) {
            $blockers[] = 'open_merge_lease_conflict';
            $ok = false;
        }

        // Lane head must be resolvable when targeting the integration lane.
        if ($mergeTarget === 'integration_lane') {
            $laneHead = $this->nullableString($input['lane_head'] ?? null);
            $laneResolvable = array_key_exists('lane_head_resolvable', $input)
                ? (bool) $input['lane_head_resolvable']
                : ($laneHead !== null && $laneHead !== '');
            if (! $laneResolvable) {
                $blockers[] = 'integration_lane_head_unresolvable';
                $ok = false;
            }
        }

        // main target requires factory-scoped-only work (full check in Gate D too;
        // here we refuse a non-factory-scoped main target at the environment level).
        if ($mergeTarget === 'main') {
            $factoryScoped = (bool) ($input['factory_scoped'] ?? false);
            if (! $factoryScoped) {
                $blockers[] = 'main_target_not_factory_scoped';
                $ok = false;
            }
        }

        // Provider leftovers from a prior run must be absent OR have a cleanup plan
        // that runs before provider invocation.
        $leftovers = (int) ($input['provider_processes_remaining'] ?? 0);
        if ($leftovers > 0) {
            $cleanupPlanned = (bool) ($input['provider_leftover_cleanup_planned'] ?? false);
            if (! $cleanupPlanned) {
                $blockers[] = 'provider_process_leftovers_without_cleanup_plan';
                $ok = false;
            } else {
                $warnings[] = 'provider_leftovers_cleanup_planned_before_invocation';
            }
        }

        return $ok;
    }

    // ---------------------------------------------------------------- Gate B

    /**
     * Gate B — Candidate Truth. Hard block inadmissible candidates. NEVER counts
     * starvation recovery / filler / benchmark as admissible work.
     *
     * @param  array<string,mixed>  $input
     * @param  array<string,mixed>  $candidate
     * @param  list<string>  $blockers
     * @param  list<string>  $warnings
     * @param  array<string,string>  $blockStatuses
     */
    private function gateCandidateTruth(array $input, array $candidate, string $scopeProfile, array &$blockers, array &$warnings, array &$blockStatuses): bool
    {
        $ok = true;

        $autonomous = (bool) ($input['autonomous'] ?? true);
        $highPower = $scopeProfile === 'factory_max' || (bool) ($input['high_power_cert_mode'] ?? false);

        // Empty candidate => honest backlog exhaustion, NEVER synthetic recovery.
        $findingId = trim((string) ($candidate['finding_id'] ?? ''));
        if ($candidate === [] || $findingId === '') {
            $blockers[] = 'no_admissible_candidate_backlog_exhausted';
            $blockStatuses[self::GATE_CANDIDATE_TRUTH] = self::BLOCK_BACKLOG_EXHAUSTED;

            return false;
        }

        $origin = strtolower((string) ($candidate['origin_type'] ?? ($candidate['source'] ?? '')));
        $kind = strtolower((string) ($candidate['kind'] ?? ''));
        $title = strtolower((string) ($candidate['title'] ?? ''));
        $detail = strtolower((string) ($candidate['detail'] ?? ''));

        // NEGATIVE INVARIANT: starvation/recovery/filler in autonomous factory_max.
        $isRecovery = (bool) ($candidate['is_recovery'] ?? false)
            || (bool) ($candidate['is_starvation_recovery'] ?? false)
            || in_array($origin, ['starvation_recovery', 'recovery', 'filler'], true)
            || str_contains($title, 'starvation recovery')
            || str_contains($title, 'filler');
        if ($isRecovery && $autonomous && $scopeProfile === 'factory_max') {
            $blockers[] = 'starvation_recovery_in_autonomous_factory_max';
            $blockStatuses[self::GATE_CANDIDATE_TRUTH] = self::BLOCK_BACKLOG_EXHAUSTED;
            $ok = false;
        }

        // NEGATIVE INVARIANT: routine missing-test filler with no strategic value in
        // high-power / certification mode.
        $isMissingTestFiller = (bool) ($candidate['is_missing_test_filler'] ?? false)
            || $kind === 'missing_test'
            || (str_contains($title, 'missing test') && ! (bool) ($candidate['strategic_value'] ?? false));
        $noStrategicValue = ! (bool) ($candidate['strategic_value'] ?? false);
        if ($isMissingTestFiller && $noStrategicValue && $highPower) {
            $blockers[] = 'routine_missing_test_filler_in_high_power_mode';
            $blockStatuses[self::GATE_CANDIDATE_TRUTH] = self::BLOCK_ADMISSION_BLOCKED;
            $ok = false;
        }

        // NEGATIVE INVARIANT: benchmark/rivals masquerading as implementation.
        $isBenchmark = (bool) ($candidate['is_benchmark'] ?? false)
            || in_array($kind, ['benchmark', 'rivals'], true)
            || str_contains($origin, 'benchmark')
            || str_contains($origin, 'rivals')
            || str_contains($title, 'benchmark')
            || str_contains($title, 'rivals')
            || str_contains($detail, 'benchmark')
            || str_contains($detail, 'rivals');
        if ($isBenchmark) {
            $blockers[] = 'benchmark_or_rivals_as_implementation';
            $blockStatuses[self::GATE_CANDIDATE_TRUTH] = self::BLOCK_ADMISSION_BLOCKED;
            $ok = false;
        }

        // Review-locked without a NEW changed blocker reason.
        if ((bool) ($candidate['review_locked'] ?? false)) {
            $changedBlocker = trim((string) ($candidate['changed_blocker_reason'] ?? ''));
            if ($changedBlocker === '') {
                $blockers[] = 'review_locked_without_changed_blocker';
                $blockStatuses[self::GATE_CANDIDATE_TRUTH] = self::BLOCK_REVIEW_LOCKED;
                $ok = false;
            }
        }

        // Quarantined for a NON-transient failure (transient is allowed to retry).
        if ((bool) ($candidate['quarantined'] ?? false)) {
            $transient = (bool) ($candidate['quarantine_transient'] ?? false);
            if (! $transient) {
                $blockers[] = 'quarantined_non_transient';
                $blockStatuses[self::GATE_CANDIDATE_TRUTH] = self::BLOCK_QUARANTINED;
                $ok = false;
            } else {
                $warnings[] = 'candidate_transient_quarantine_retry';
            }
        }

        // Duplicate spin: same finding_id + same blocker + same slice already seen.
        if ($this->isDuplicateSpin($input, $candidate)) {
            $blockers[] = 'duplicate_finding_blocker_slice_spin';
            $blockStatuses[self::GATE_CANDIDATE_TRUTH] = self::BLOCK_DUPLICATE_BLOCKED;
            $ok = false;
        }

        // Missing source doc / evidence reference / canonical reason.
        $hasSource = trim((string) ($candidate['source_doc'] ?? '')) !== ''
            || $this->stringList($candidate['evidence_refs'] ?? []) !== []
            || trim((string) ($candidate['canonical_reason'] ?? ($candidate['why_it_matters'] ?? ''))) !== '';
        if (! $hasSource) {
            $blockers[] = 'missing_source_doc_evidence_or_canonical_reason';
            $blockStatuses[self::GATE_CANDIDATE_TRUTH] = self::BLOCK_ADMISSION_BLOCKED;
            $ok = false;
        }

        // NEGATIVE INVARIANT: planned-counted-as-executable.
        $lifecycle = strtolower((string) ($candidate['status'] ?? ($candidate['lifecycle'] ?? '')));
        $countedExecutable = (bool) ($candidate['counted_as_executable'] ?? false) || (bool) ($candidate['auto_execution_allowed'] ?? false);
        if ($lifecycle === 'planned' && $countedExecutable) {
            $blockers[] = 'planned_candidate_counted_as_executable';
            $blockStatuses[self::GATE_CANDIDATE_TRUTH] = self::BLOCK_ADMISSION_BLOCKED;
            $ok = false;
        }

        return $ok;
    }

    // ---------------------------------------------------------------- Gate C

    /**
     * Gate C — Packet & Slice Fitness. High-value AAEOS work is allowed only as a
     * bounded packet, never the whole strategic finding.
     *
     * @param  array<string,mixed>  $input
     * @param  array<string,mixed>  $candidate
     * @param  array<string,mixed>  $packet
     * @param  list<string>  $blockers
     * @param  list<string>  $warnings
     * @param  array<string,string>  $blockStatuses
     */
    private function gatePacketFitness(array $input, array $candidate, array $packet, array &$blockers, array &$warnings, array &$blockStatuses): bool
    {
        // A packet is required only for high-value work that must be bounded.
        $requiresPacket = (bool) ($candidate['requires_bounded_packet'] ?? false)
            || in_array(strtolower((string) ($candidate['severity'] ?? '')), ['high', 'critical'], true)
            || (bool) ($candidate['cross_system'] ?? false)
            || $packet !== [];

        if (! $requiresPacket) {
            // Low-value bounded work without a packet requirement passes fitness.
            return true;
        }

        if ($packet === []) {
            $blockers[] = 'high_value_work_without_bounded_packet';
            $blockStatuses[self::GATE_PACKET_FITNESS] = self::BLOCK_ADMISSION_BLOCKED;

            return false;
        }

        $ok = true;
        $missing = [];
        foreach (self::REQUIRED_PACKET_FIELDS as $field) {
            if (! $this->packetFieldPresent($packet, $field)) {
                $missing[] = $field;
            }
        }
        if ($missing !== []) {
            $blockers[] = 'packet_missing_required_fields:'.implode(',', $missing);
            $ok = false;
        }

        // allowed_files must be a SMALL explicit scope.
        $allowed = $this->stringList($packet['allowed_files'] ?? []);
        if ($allowed === []) {
            $blockers[] = 'packet_allowed_files_empty';
            $ok = false;
        } elseif (count($allowed) > self::MAX_PACKET_ALLOWED_FILES) {
            $blockers[] = 'packet_allowed_files_not_bounded';
            $ok = false;
        }

        // claim / lease metadata from Self-Construction.
        $hasClaim = (array) ($packet['claim'] ?? []) !== [] || array_key_exists('claim', $packet);
        $hasLease = (array) ($packet['lease'] ?? []) !== [] || array_key_exists('lease', $packet);
        if (! $hasClaim || ! $hasLease) {
            $blockers[] = 'packet_missing_claim_or_lease';
            $ok = false;
        }

        // Dependency state proving prior packets are merged-or-not-required.
        if (! $this->dependencyStateProven($packet)) {
            $blockers[] = 'packet_dependency_state_not_proven';
            $ok = false;
        }

        // NEGATIVE INVARIANT: reject "implement the whole feature" packet text.
        $text = strtolower(trim(
            (string) ($packet['objective'] ?? '').' '.(string) ($packet['detail'] ?? '').' '.implode(' ', $this->stringList($packet['acceptance_criteria'] ?? []))
        ));
        foreach (['implement the whole feature', 'implement whole feature', 'implement the entire feature', 'build the whole feature'] as $needle) {
            if ($text !== '' && str_contains($text, $needle)) {
                $blockers[] = 'packet_text_says_implement_whole_feature';
                $ok = false;
                break;
            }
        }

        if ($ok && (string) ($packet['status'] ?? '') !== 'planned') {
            $warnings[] = 'packet_status_not_planned';
        }

        return $ok;
    }

    // ---------------------------------------------------------------- Gate D

    /**
     * Gate D — Authority & Merge Target. Hard block when authority does not match
     * blast radius.
     *
     * @param  array<string,mixed>  $input
     * @param  array<string,mixed>  $candidate
     * @param  array<string,mixed>  $packet
     * @param  list<string>  $blockers
     * @param  list<string>  $warnings
     */
    private function gateAuthority(array $input, array $candidate, array $packet, string $mergeTarget, string $scopeProfile, array &$blockers, array &$warnings): bool
    {
        $ok = true;

        $crossSystem = (bool) ($candidate['cross_system'] ?? false) || (bool) ($input['cross_system'] ?? false);
        $autonomous = (bool) ($input['autonomous'] ?? true);
        $envelopeArmed = (bool) ($input['envelope_armed'] ?? false);
        $riskCeiling = strtolower(trim((string) ($input['envelope_risk_ceiling'] ?? 'medium'))) ?: 'medium';
        $candidateRisk = strtolower(trim((string) ($packet['risk_level'] ?? ($candidate['severity'] ?? 'medium')))) ?: 'medium';

        // Cross-system AAEOS work requires an armed envelope.
        if ($crossSystem && ! $envelopeArmed) {
            $blockers[] = 'cross_system_without_armed_envelope';
            $ok = false;
        }

        // NEGATIVE INVARIANT: cross-system autonomous work must route to the
        // integration lane, NOT main.
        if ($crossSystem && $autonomous && $mergeTarget === 'main') {
            $blockers[] = 'cross_system_autonomous_to_main';
            $ok = false;
        }

        // main auto-merge only for factory-scoped work inside the allowed risk ceiling.
        if ($mergeTarget === 'main') {
            if (! (bool) ($input['factory_scoped'] ?? false)) {
                $blockers[] = 'main_auto_merge_not_factory_scoped';
                $ok = false;
            }
            if ($this->riskRank($candidateRisk) > $this->riskRank($riskCeiling)) {
                $blockers[] = 'main_auto_merge_risk_over_ceiling';
                $ok = false;
            }
        }

        // Risk must be <= envelope risk ceiling whenever an envelope governs the cycle.
        if ($envelopeArmed && $this->riskRank($candidateRisk) > $this->riskRank($riskCeiling)) {
            $blockers[] = 'candidate_risk_over_envelope_ceiling';
            $ok = false;
        }

        // NEGATIVE INVARIANT: forge plan-only / fixture path is NOT real execution.
        $ownerRuntime = strtolower(trim((string) ($packet['owner_runtime'] ?? ($candidate['owner_candidate'] ?? ($input['owner_runtime'] ?? '')))));
        $forgeMode = strtolower(trim((string) ($input['forge_dispatch_mode'] ?? '')));
        $forgePlanOnly = (bool) ($input['forge_plan_only'] ?? false)
            || (bool) ($input['forge_fixture'] ?? false)
            || in_array($forgeMode, ['plan_only', 'plan-only', 'fixture', 'runtime-dispatch', 'runtime_dispatch'], true);
        if ($ownerRuntime === 'forge' && $forgePlanOnly) {
            $blockers[] = 'forge_plan_only_not_real_execution';
            $ok = false;
        }

        // Provider owner must be a runtime that can actually produce code today.
        $ownerCanProduceCode = array_key_exists('owner_can_produce_code', $input)
            ? (bool) $input['owner_can_produce_code']
            : ($ownerRuntime !== '' && in_array($ownerRuntime, self::CODE_CAPABLE_OWNERS, true));
        if (! $ownerCanProduceCode) {
            $blockers[] = 'owner_runtime_cannot_produce_code';
            $ok = false;
        }

        return $ok;
    }

    // ---------------------------------------------------------------- Gate E

    /**
     * Gate E — Cost & Provider Readiness. Hard block before provider invocation
     * when cost / provider readiness is not satisfied.
     *
     * @param  array<string,mixed>  $input
     * @param  array<string,mixed>  $candidate
     * @param  list<string>  $blockers
     * @param  list<string>  $warnings
     */
    private function gateCost(array $input, array $candidate, array &$blockers, array &$warnings): bool
    {
        $ok = true;

        // per-run budget not exhausted.
        if ((bool) ($input['per_run_budget_exhausted'] ?? false)) {
            $blockers[] = 'per_run_budget_exhausted';
            $ok = false;
        }
        $remaining = $input['per_run_calls_remaining'] ?? null;
        if ($remaining !== null && (int) $remaining <= 0) {
            $blockers[] = 'per_run_budget_exhausted';
            $ok = false;
        }

        // provider timeout >= floor (300s).
        $timeout = (int) ($input['provider_timeout_seconds'] ?? self::MIN_PROVIDER_TIMEOUT_SECONDS);
        if ($timeout < self::MIN_PROVIDER_TIMEOUT_SECONDS) {
            $blockers[] = 'provider_timeout_below_floor';
            $ok = false;
        }

        // route present when routing is required.
        $routingRequired = (bool) ($input['provider_routing_required'] ?? false);
        if ($routingRequired) {
            $route = trim((string) ($input['provider_route'] ?? ''));
            if ($route === '') {
                $blockers[] = 'provider_route_absent_when_required';
                $ok = false;
            }
        }

        // NEGATIVE INVARIANT: the exact same candidate already spent a call and hit
        // the same blocker (no paying twice for the same dead end).
        if ((bool) ($candidate['already_spent_call_same_blocker'] ?? false)
            || (bool) ($input['candidate_already_spent_call_same_blocker'] ?? false)) {
            $blockers[] = 'same_candidate_already_spent_call_same_blocker';
            $ok = false;
        }

        // not over max_blocked_in_row.
        $blockedInRow = (int) ($input['blocked_in_row'] ?? 0);
        $maxBlockedInRow = (int) ($input['max_blocked_in_row'] ?? 14);
        if ($maxBlockedInRow > 0 && $blockedInRow >= $maxBlockedInRow) {
            $blockers[] = 'max_blocked_in_row_exceeded';
            $ok = false;
        }

        // preflight can write a receipt for this cycle.
        $canWriteReceipt = array_key_exists('preflight_can_write_receipt', $input)
            ? (bool) $input['preflight_can_write_receipt']
            : true;
        if (! $canWriteReceipt) {
            $blockers[] = 'preflight_cannot_write_receipt';
            $ok = false;
        }

        return $ok;
    }

    // ---------------------------------------------------------------- helpers

    /**
     * @param  array<string,mixed>  $input
     * @param  array<string,mixed>  $candidate
     */
    private function isDuplicateSpin(array $input, array $candidate): bool
    {
        if ((bool) ($candidate['is_duplicate_spin'] ?? false)) {
            return true;
        }

        $findingId = trim((string) ($candidate['finding_id'] ?? ''));
        $blocker = trim((string) ($candidate['last_blocker'] ?? ($candidate['blocker'] ?? '')));
        $slice = trim((string) ($candidate['active_slice_id'] ?? ($candidate['slice_id'] ?? '')));
        if ($findingId === '' || $blocker === '') {
            return false;
        }

        $key = $findingId.'|'.$blocker.'|'.$slice;
        $seen = [];
        foreach ((array) ($input['seen_finding_blocker_slice'] ?? []) as $row) {
            if (is_string($row)) {
                $seen[] = $row;

                continue;
            }
            if (is_array($row)) {
                $seen[] = trim((string) ($row['finding_id'] ?? '')).'|'.trim((string) ($row['blocker'] ?? '')).'|'.trim((string) ($row['active_slice_id'] ?? ($row['slice_id'] ?? '')));
            }
        }

        return in_array($key, $seen, true);
    }

    /**
     * @param  array<string,mixed>  $packet
     */
    private function packetFieldPresent(array $packet, string $field): bool
    {
        if (! array_key_exists($field, $packet)) {
            return false;
        }
        $value = $packet[$field];
        if (is_array($value)) {
            return $value !== [];
        }
        if (is_int($value)) {
            return $value > 0;
        }

        return trim((string) $value) !== '';
    }

    /**
     * Prior packets must be proven merged-or-not-required.
     *
     * @param  array<string,mixed>  $packet
     */
    private function dependencyStateProven(array $packet): bool
    {
        if (! array_key_exists('dependency_state', $packet)) {
            // First packet (sequence 1) with no dependencies is proven by definition.
            return (int) ($packet['slice_sequence'] ?? 0) <= 1;
        }
        $dep = $packet['dependency_state'];
        if (is_string($dep)) {
            return in_array(strtolower($dep), ['merged', 'not_required', 'none'], true);
        }
        if (is_array($dep)) {
            foreach ($dep as $row) {
                $state = is_array($row) ? strtolower((string) ($row['state'] ?? '')) : strtolower((string) $row);
                if (! in_array($state, ['merged', 'not_required', 'none'], true)) {
                    return false;
                }
            }

            return true;
        }

        return false;
    }

    /**
     * @param  array<string,string>  $blockStatuses
     * @param  list<string>  $blockers
     */
    private function resolveBlockStatus(string $status, array $blockStatuses, array $blockers): ?string
    {
        if ($status === self::STATUS_ALLOW) {
            return null;
        }
        // Prefer the precise Gate-B taxonomy when present.
        $priority = [
            self::BLOCK_BACKLOG_EXHAUSTED,
            self::BLOCK_REVIEW_LOCKED,
            self::BLOCK_QUARANTINED,
            self::BLOCK_DUPLICATE_BLOCKED,
            self::BLOCK_ADMISSION_BLOCKED,
        ];
        $present = array_values($blockStatuses);
        foreach ($priority as $candidate) {
            if (in_array($candidate, $present, true)) {
                return $candidate;
            }
        }

        // Environment/authority/cost blocks map to admission_blocked — NEVER success.
        return $blockers !== [] ? self::BLOCK_ADMISSION_BLOCKED : self::BLOCK_ADMISSION_BLOCKED;
    }

    private function normalizeMergeTarget(string $value): string
    {
        $value = strtolower(trim($value));

        return in_array($value, ['integration_lane', 'main', 'none'], true) ? $value : 'integration_lane';
    }

    private function normalizeSource(string $value): string
    {
        $value = strtolower(trim($value));

        return match (true) {
            $value === 'canonical_backlog' => 'canonical_backlog',
            str_contains($value, 'self_construction') => 'self_construction_packet',
            $value === 'scanner' => 'scanner',
            $value === '' => 'canonical_backlog',
            default => $value,
        };
    }

    private function riskRank(string $risk): int
    {
        return match (strtolower($risk)) {
            'low' => 1,
            'medium' => 2,
            'high' => 3,
            'critical' => 4,
            default => 2,
        };
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * @param  mixed  $value
     * @return list<string>
     */
    private function stringList($value): array
    {
        return array_values(array_filter(array_map(
            static fn ($item): string => is_string($item) ? $item : '',
            is_array($value) ? $value : [],
        ), static fn (string $item): bool => $item !== ''));
    }

    /**
     * A wiring-phase `fixture` may be a single cycle record; fold it under the
     * explicit input so direct keys still take precedence (input-seam composition).
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function mergeFixture(array $input): array
    {
        $fixture = $input['fixture'] ?? null;
        if (! is_array($fixture) || $fixture === []) {
            return $input;
        }
        unset($input['fixture']);

        return array_merge($fixture, $input);
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function withoutVolatile(array $payload): array
    {
        unset($payload['checked_at'], $payload['report_hash']);

        return $payload;
    }

    // ----------------------------------------------------------------
    // Per-cycle sandbox pre-validation (AP-810 guard)
    // ----------------------------------------------------------------

    /**
     * Validate the sandbox worktree BEFORE calling the senior loop.
     * Returns immediately with tier=1 setup_failure when the sandbox is not
     * execution-ready so the caller never reaches the provider invocation path.
     *
     * This prevents silent "not_executed" outcomes caused by:
     *   a) Missing/orphaned worktree directory.
     *   b) Vendor autoload pointing back to the main repo (isolation broken).
     *   c) Uncommitted merge conflicts that would corrupt the diff.
     *   d) PHP parse errors in files that the cycle intends to test.
     *   e) Artisan bootstrap failure (framework or composer issue).
     *
     * Contract:
     *   - read_only when valid (only reads files and runs read-only commands)
     *   - Never modifies code, never calls a provider
     *   - Max 3 s timeout on the artisan check (shell_exec with timeout wrapper)
     *
     * @param  list<string>  $modifiedFiles  relative paths to check for syntax errors
     * @return array{valid:bool,failed_checks:list<string>,specific_reason:string}
     */
    public function validateSandboxBeforeExecution(
        string $sandboxPath,
        string $repoRoot,
        array $modifiedFiles = [],
    ): array {
        $failedChecks = [];

        // a) Sandbox directory must exist.
        if (! is_dir($sandboxPath)) {
            return [
                'valid' => false,
                'failed_checks' => ['sandbox_directory_missing'],
                'specific_reason' => 'Sandbox worktree directory does not exist: '.$sandboxPath.'. Run atlas:dev:senior-loop sandbox materialize first.',
            ];
        }

        // b) Vendor autoload isolation: sandbox vendor must differ from main vendor.
        $sandboxVendor = $sandboxPath.DIRECTORY_SEPARATOR.'vendor';
        $mainVendor = rtrim($repoRoot, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'vendor';
        if (is_dir($sandboxVendor) && is_dir($mainVendor)) {
            $realSandbox = realpath($sandboxVendor);
            $realMain = realpath($mainVendor);
            if ($realSandbox !== false && $realMain !== false && $realSandbox === $realMain) {
                $failedChecks[] = 'vendor_isolation_broken';
            }
        }

        // c) No uncommitted conflicts: `git status --porcelain` must return clean.
        $statusOutput = $this->shellSafe('git -C '.escapeshellarg($sandboxPath).' status --porcelain 2>/dev/null');
        foreach (explode("\n", $statusOutput) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            // Lines starting with 'UU', 'AA', 'DD' or 'U?' indicate merge conflicts.
            if (in_array(substr($line, 0, 2), ['UU', 'AA', 'DD', 'AU', 'UA', 'DU', 'UD'], true)) {
                $failedChecks[] = 'uncommitted_merge_conflict';
                break;
            }
        }

        // d) PHP syntax check on modified files (only files that exist in the sandbox).
        foreach ($modifiedFiles as $relPath) {
            $absPath = rtrim($sandboxPath, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.ltrim($relPath, DIRECTORY_SEPARATOR);
            if (! is_file($absPath) || ! str_ends_with($absPath, '.php')) {
                continue;
            }
            $lintOut = $this->shellSafe('php -l '.escapeshellarg($absPath).' 2>&1');
            if (! str_contains($lintOut, 'No syntax errors')) {
                $failedChecks[] = 'php_syntax_error:'.$relPath;
            }
        }

        // e) Artisan can bootstrap (≤ 3 s).
        $sandboxArtisan = $sandboxPath.DIRECTORY_SEPARATOR.'artisan';
        if (is_file($sandboxArtisan)) {
            $artisanOut = $this->shellSafe(
                'timeout 3 php '.escapeshellarg($sandboxArtisan).' list 2>&1',
            );
            if (! str_contains($artisanOut, 'Available commands')) {
                $failedChecks[] = 'artisan_bootstrap_failed';
            }
        }

        if ($failedChecks !== []) {
            return [
                'valid' => false,
                'failed_checks' => array_values(array_unique($failedChecks)),
                'specific_reason' => 'Sandbox pre-validation failed: '.implode(', ', $failedChecks).'. Fix the setup before invoking the owner runtime.',
            ];
        }

        return [
            'valid' => true,
            'failed_checks' => [],
            'specific_reason' => '',
        ];
    }

    /**
     * Execute a shell command and return its output; never throws.
     * Isolated here so the class remains unit-testable without exec calls.
     */
    private function shellSafe(string $cmd): string
    {
        try {
            $out = @shell_exec($cmd);

            return is_string($out) ? $out : '';
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * @param  array<string,mixed>  $input
     */
    private function validateDepartmentMaturityCheckInput(array $input): void
    {
        if ($input === []) {
            return;
        }

        $allowedKeys = [
            'area_id',
            'focus',
            'routing_tier',
            'required_department_ids',
            'department_maturity_snapshot',
        ];
        foreach (array_keys($input) as $key) {
            if (! in_array($key, $allowedKeys, true)) {
                throw new \InvalidArgumentException("Unknown department maturity check input key: {$key}");
            }
        }

        if (array_key_exists('area_id', $input) && ! is_string($input['area_id'])) {
            throw new \InvalidArgumentException('area_id must be a string.');
        }

        if (array_key_exists('focus', $input) && ! is_string($input['focus'])) {
            throw new \InvalidArgumentException('focus must be a string.');
        }

        if (array_key_exists('routing_tier', $input) && ! is_string($input['routing_tier'])) {
            throw new \InvalidArgumentException('routing_tier must be a string.');
        }

        if (array_key_exists('required_department_ids', $input)) {
            if (! is_array($input['required_department_ids'])) {
                throw new \InvalidArgumentException('required_department_ids must be an array.');
            }

            foreach ($input['required_department_ids'] as $departmentId) {
                if (! is_string($departmentId)) {
                    throw new \InvalidArgumentException('required_department_ids entries must be strings.');
                }
            }
        }

        if (array_key_exists('department_maturity_snapshot', $input)) {
            if (! is_array($input['department_maturity_snapshot'])) {
                throw new \InvalidArgumentException('department_maturity_snapshot must be an array.');
            }

            foreach ($input['department_maturity_snapshot'] as $departmentId => $level) {
                if (! is_string($departmentId) || ! is_string($level)) {
                    throw new \InvalidArgumentException('department_maturity_snapshot entries must be string=>string.');
                }
            }
        }
    }
}
