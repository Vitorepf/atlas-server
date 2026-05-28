<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AgentExecution;

use App\Services\Ai\Mission\MissionCanonicalHash;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * AP-800 — Multi-Agent Cycle Certification and Product Mode Visibility.
 *
 * Read-only certification of a multi-agent-per-task cycle. It NEVER runs a
 * provider, agent, lane, judge or repair runtime (AP-795..AP-799). It detects
 * capabilities (overridable class/interface probes), inspects a recorded cycle
 * receipt or fixture, and emits a deterministic verdict plus an operator-facing
 * Product Mode projection.
 *
 * Honesty invariants (the operator does not accept false claims):
 *   - `test_mode` can never certify production. Mode is decided by how AP-800 is
 *     invoked (`use_real_services`), never by what the cycle fixture claims.
 *   - A merge counts only when `main` advanced (AP-793 merge truth).
 *   - A broad factory_max finding without an AP-794 slice plan is blocked.
 *   - Validation failure without a repair lane/planner is blocked.
 *   - Missing capabilities/evidence are reported precisely, never a false pass.
 *
 * Contract: docs/ap/AP-800-multi-agent-cycle-certification-visibility-contract.md
 */
final class MultiAgentCycleCertificationService
{
    public const REPORT_SCHEMA = 'atlas.agent_execution.multi_agent_cycle_certification.v1';

    public const PRODUCT_MODE_SCHEMA = 'atlas.agent_execution.multi_agent_cycle_product_mode.v1';

    public const STATUS_PASSED = 'passed';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_BLOCKED = 'blocked';

    public const MODE_TEST = 'test_mode';

    public const MODE_RUNTIME_REAL = 'runtime_real';

    /** Lanes that a real multi-agent-per-task cycle must contain. */
    public const REQUIRED_LANES = ['context_scout', 'architect', 'implementer', 'reviewer', 'judge'];

    /** Capability key => candidate class/interface names. Overridable via input. */
    private const CAPABILITY_CLASSES = [
        'provider_port_session_store' => [
            'App\\Services\\Ai\\SoftwareCompanyStewardship\\AgentExecution\\AgentProviderPort',
            'App\\Services\\Ai\\SoftwareCompanyStewardship\\AgentExecution\\AgentProviderPortService',
            'App\\Services\\Ai\\SoftwareCompanyStewardship\\AgentExecution\\ProviderSessionStore',
            'App\\Services\\Ai\\SoftwareCompanyStewardship\\AgentExecution\\AgentExecutionSessionStore',
        ],
        'finding_slice_planner' => [
            'App\\Services\\Ai\\SoftwareCompanyStewardship\\AgentExecution\\FindingSlicePlanner',
            'App\\Services\\Ai\\SoftwareCompanyStewardship\\AgentExecution\\FindingSlicePlannerService',
            'App\\Services\\Ai\\SoftwareCompanyStewardship\\AreaFocusLoop\\FindingSlicePlannerService',
        ],
        'lane_orchestrator' => [
            'App\\Services\\Ai\\SoftwareCompanyStewardship\\AgentExecution\\LaneOrchestrator',
            'App\\Services\\Ai\\SoftwareCompanyStewardship\\AgentExecution\\LaneOrchestratorService',
            'App\\Services\\Ai\\SoftwareCompanyStewardship\\AgentExecution\\MultiAgentLaneOrchestratorService',
        ],
        'integration_judge' => [
            'App\\Services\\Ai\\SoftwareCompanyStewardship\\AgentExecution\\IntegrationJudge',
            'App\\Services\\Ai\\SoftwareCompanyStewardship\\AgentExecution\\IntegrationJudgeService',
        ],
        'repair_planner' => [
            'App\\Services\\Ai\\SoftwareCompanyStewardship\\AgentExecution\\RepairPlanner',
            'App\\Services\\Ai\\SoftwareCompanyStewardship\\AgentExecution\\RepairPlannerService',
        ],
        'ap792_harness' => [
            'App\\Services\\Ai\\SoftwareCompanyStewardship\\AreaFocusLoop\\Loop24hCertificationHarnessService',
        ],
    ];

    /** Capability key => contract-doc evidence path (relative to base path). */
    private const CAPABILITY_DOCS = [
        'finding_slice_planner' => 'docs/ap/AP-794-finding-slice-planner-contract.md',
        'ap793_substrate_facts' => 'docs/ap/AP-793-atlas-isolated-agent-execution-substrate-contract.md',
    ];

    /** Required AP-793 substrate facts a completed cycle must carry. */
    private const SUBSTRATE_FACTS = [
        'provider_invoked',
        'provider_authority',
        'sandbox_kind',
        'worktree_materialized',
        'owner_runtime_chain',
        'product_diff_exists',
        'focused_validation_ran',
        'inbox_item_emitted',
        'evidence_refs_present',
        'merge_governor_evaluated',
    ];

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function certify(array $input = []): array
    {
        $mode = $this->resolveMode($input);
        $cycle = $this->extractCycle($input);

        $capabilities = $this->detectCapabilities($input);
        $missingCapabilities = $this->missingCapabilities($capabilities);

        $facts = $this->substrateFacts($cycle);
        $providerReal = $this->providerWasReal($cycle);
        $finding = $this->findingContext($cycle, $input);
        $slice = $this->slicePlanStatus($cycle, $finding);
        $lanePlan = $this->lanePlan($cycle);
        $judge = $this->judgeDecision($cycle);
        $repair = $this->repairStatus($cycle);
        $merge = $this->mergeStatus($cycle);
        $evidence = $this->evidenceObligations($cycle);

        $blockers = $this->hardBlockers($cycle, $facts, $finding, $slice, $lanePlan, $judge, $repair, $merge);

        $productionCertified = $mode === self::MODE_RUNTIME_REAL
            && $providerReal
            && $blockers === []
            && $missingCapabilities === []
            && $facts['all_present']
            && ($slice['satisfied'])
            && $lanePlan['all_required_present']
            && ($repair['satisfied'])
            && $judge['present']
            && $evidence['all_present'];

        $status = match (true) {
            $blockers !== [] => self::STATUS_BLOCKED,
            $productionCertified => self::STATUS_PASSED,
            default => self::STATUS_PARTIAL,
        };

        $invariants = $this->invariants($providerReal, $facts, $slice, $lanePlan, $judge, $repair, $merge, $evidence, $missingCapabilities);
        $nextAction = $this->nextOperatorAction($status, $mode, $blockers, $missingCapabilities, $slice, $lanePlan, $judge, $repair);
        $productMode = $this->productModeProjection($status, $lanePlan, $slice, $judge, $repair, $missingCapabilities, $nextAction);

        $payload = [
            'schema_version' => self::REPORT_SCHEMA,
            'ap_contract' => 'AP-800',
            'substrate_contract' => 'AP-793',
            'slice_contract' => 'AP-794',
            'status' => $status,
            'certification_mode' => $mode,
            'production_certified' => $productionCertified,
            'provider_was_real' => $providerReal,
            'capabilities' => $capabilities,
            'missing_capabilities' => $missingCapabilities,
            'substrate_facts' => $facts,
            'finding' => $finding,
            'slice_plan' => $slice,
            'lane_plan' => $lanePlan,
            'judge_decision' => $judge,
            'repair' => $repair,
            'merge' => $merge,
            'evidence_obligations' => $evidence,
            'blockers' => $blockers,
            'invariants' => $invariants,
            'product_mode_projection' => $productMode,
            'next_operator_action' => $nextAction,
            'claim_policy' => $this->claimPolicy(),
        ];

        $payload['report_hash'] = 'sha256:'.MissionCanonicalHash::sha256($payload);
        $payload['generated_at'] = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);

        return $payload;
    }

    /**
     * Mode is decided by HOW AP-800 is invoked, never by the cycle fixture. A
     * fixture cannot self-declare production by setting its own runtime_real.
     *
     * @param  array<string,mixed>  $input
     */
    private function resolveMode(array $input): string
    {
        return (bool) ($input['use_real_services'] ?? false) === true
            ? self::MODE_RUNTIME_REAL
            : self::MODE_TEST;
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function extractCycle(array $input): array
    {
        foreach (['real_recorded_cycle', 'cycle', 'cycle_receipt', 'evidence'] as $key) {
            if (is_array($input[$key] ?? null)) {
                return $input[$key];
            }
        }

        return [];
    }

    // ---------- capability detection ----------

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function detectCapabilities(array $input): array
    {
        $overrides = is_array($input['capability_overrides'] ?? null) ? $input['capability_overrides'] : [];
        $extraProbes = is_array($input['capability_probes'] ?? null) ? $input['capability_probes'] : [];
        $cycle = $this->extractCycle($input);

        $rows = [];
        $keys = array_values(array_unique(array_merge(
            array_keys(self::CAPABILITY_CLASSES),
            array_keys(self::CAPABILITY_DOCS),
            ['ap793_substrate_facts'],
        )));

        foreach ($keys as $key) {
            $rows[$key] = $this->probe($key, $overrides, $extraProbes, $cycle);
        }

        return $rows;
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @param  array<string,mixed>  $extraProbes
     * @param  array<string,mixed>  $cycle
     * @return array<string,mixed>
     */
    private function probe(string $key, array $overrides, array $extraProbes, array $cycle): array
    {
        $classes = self::CAPABILITY_CLASSES[$key] ?? [];
        if (isset($extraProbes[$key])) {
            $classes = array_values(array_unique(array_merge(
                array_values(array_filter((array) $extraProbes[$key], 'is_string')),
                $classes,
            )));
        }
        $docPath = self::CAPABILITY_DOCS[$key] ?? null;

        $present = false;
        $matched = null;
        $via = null;

        if (array_key_exists($key, $overrides)) {
            $present = (bool) $overrides[$key];
            $matched = $present ? ($classes[0] ?? $docPath) : null;
            $via = $present ? 'override' : null;
        } else {
            foreach ($classes as $class) {
                if (is_string($class) && (class_exists($class) || interface_exists($class))) {
                    $present = true;
                    $matched = $class;
                    $via = 'class';
                    break;
                }
            }
            // ap793 facts are evidence-based: present when the cycle carries them.
            if (! $present && $key === 'ap793_substrate_facts' && $this->substrateFacts($cycle)['all_present']) {
                $present = true;
                $matched = 'cycle_evidence';
                $via = 'evidence';
            }
            if (! $present && $docPath !== null && $this->docExists($docPath)) {
                $present = true;
                $matched = $docPath;
                $via = 'contract_doc';
            }
        }

        return [
            'capability' => $key,
            'present' => $present,
            'matched' => $matched,
            'detected_via' => $via,
            'candidate_classes' => array_values($classes),
            'contract_doc' => $docPath,
        ];
    }

    /**
     * @param  array<string,mixed>  $capabilities
     * @return list<string>
     */
    private function missingCapabilities(array $capabilities): array
    {
        $missing = [];
        foreach ($capabilities as $key => $row) {
            if (($row['present'] ?? false) !== true) {
                $missing[] = (string) $key;
            }
        }
        sort($missing);

        return $missing;
    }

    private function docExists(string $relativePath): bool
    {
        $candidates = [];
        if (function_exists('base_path')) {
            $candidates[] = base_path($relativePath);
        }
        $candidates[] = $relativePath;

        foreach ($candidates as $path) {
            if (is_string($path) && is_file($path)) {
                return true;
            }
        }

        return false;
    }

    // ---------- cycle evidence inspection ----------

    /**
     * @param  array<string,mixed>  $cycle
     * @return array<string,mixed>
     */
    private function substrateFacts(array $cycle): array
    {
        $facts = is_array($cycle['substrate_facts'] ?? null) ? $cycle['substrate_facts'] : $cycle;

        $present = [];
        $missing = [];
        foreach (self::SUBSTRATE_FACTS as $fact) {
            $value = $facts[$fact] ?? null;
            $ok = match ($fact) {
                'provider_authority', 'sandbox_kind', 'owner_runtime_chain' => $this->nonEmpty($value),
                'evidence_refs_present' => $value === true || (is_array($value) && $value !== []),
                default => $value === true,
            };
            $present[$fact] = $ok;
            if (! $ok) {
                $missing[] = $fact;
            }
        }

        return [
            'present' => $present,
            'missing' => $missing,
            'all_present' => $missing === [],
        ];
    }

    /**
     * @param  array<string,mixed>  $cycle
     */
    private function providerWasReal(array $cycle): bool
    {
        $facts = is_array($cycle['substrate_facts'] ?? null) ? $cycle['substrate_facts'] : $cycle;
        $simulated = (bool) ($facts['provider_simulated'] ?? $facts['provider_mock'] ?? false);
        $invoked = ($facts['provider_invoked'] ?? null) === true;
        $authority = $this->nonEmpty($facts['provider_authority'] ?? null)
            && in_array((string) ($facts['provider_authority'] ?? ''), ['atlas_decide', 'operator_receipt'], true);
        $calls = (int) ($facts['provider_calls'] ?? ($invoked ? 1 : 0));

        return $invoked && ! $simulated && $authority && $calls >= 1;
    }

    /**
     * @param  array<string,mixed>  $cycle
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function findingContext(array $cycle, array $input): array
    {
        $finding = is_array($cycle['finding'] ?? null) ? $cycle['finding'] : [];
        $scopeProfile = (string) ($cycle['scope_profile'] ?? $finding['scope_profile'] ?? $input['scope_profile'] ?? '');
        $breadth = strtolower((string) ($finding['breadth'] ?? $cycle['finding_breadth'] ?? ''));
        $broadKinds = ['runtime', 'runtime_bottleneck', 'architecture', 'improvement', 'strategic'];
        $kind = strtolower((string) ($finding['kind'] ?? $finding['finding_kind'] ?? ''));
        $isBroad = in_array($breadth, ['broad', 'strategic', 'self_referential', 'self-referential'], true)
            || ($breadth === '' && in_array($kind, $broadKinds, true));

        return [
            'scope_profile' => $scopeProfile,
            'is_factory_max' => $scopeProfile === 'factory_max',
            'kind' => $kind,
            'breadth' => $breadth !== '' ? $breadth : ($isBroad ? 'broad' : 'narrow'),
            'is_broad' => $isBroad,
            'slice_plan_required' => $scopeProfile === 'factory_max' && $isBroad,
        ];
    }

    /**
     * @param  array<string,mixed>  $cycle
     * @param  array<string,mixed>  $finding
     * @return array<string,mixed>
     */
    private function slicePlanStatus(array $cycle, array $finding): array
    {
        $plan = is_array($cycle['slice_plan'] ?? null) ? $cycle['slice_plan'] : [];
        $decomposition = (string) ($plan['decomposition_status'] ?? '');
        $slices = is_array($plan['slices'] ?? null) ? $plan['slices'] : [];
        $present = $plan !== [] && in_array($decomposition, ['sliced', 'blocked', 'operator_review_required'], true);
        $sliced = $decomposition === 'sliced' && $slices !== [];
        $required = (bool) ($finding['slice_plan_required'] ?? false);

        return [
            'required' => $required,
            'present' => $present,
            'decomposition_status' => $decomposition !== '' ? $decomposition : null,
            'slice_count' => count($slices),
            // satisfied = not required, OR (required AND a real sliced plan exists)
            'satisfied' => ! $required || $sliced,
        ];
    }

    /**
     * @param  array<string,mixed>  $cycle
     * @return array<string,mixed>
     */
    private function lanePlan(array $cycle): array
    {
        $lanes = is_array($cycle['lanes'] ?? null) ? $cycle['lanes'] : [];
        $multiAgentClaimed = (bool) ($cycle['multi_agent'] ?? $cycle['multi_agent_claimed'] ?? ($lanes !== []));

        $rows = [];
        $missing = [];
        foreach (self::REQUIRED_LANES as $lane) {
            $entry = $lanes[$lane] ?? null;
            $present = $entry !== null && $entry !== false;
            $rows[$lane] = [
                'lane' => $lane,
                'present' => $present,
                'status' => is_array($entry) ? (string) ($entry['status'] ?? 'present') : ($present ? 'present' : 'absent'),
            ];
            if (! $present) {
                $missing[] = $lane;
            }
        }
        // repair is conditional, reported separately in repairStatus().
        $repairEntry = $lanes['repair'] ?? $lanes['repair_agent'] ?? null;
        $rows['repair'] = [
            'lane' => 'repair',
            'present' => $repairEntry !== null && $repairEntry !== false,
            'status' => is_array($repairEntry) ? (string) ($repairEntry['status'] ?? 'present') : ($repairEntry ? 'present' : 'absent'),
        ];

        return [
            'multi_agent_claimed' => $multiAgentClaimed,
            'required_lanes' => self::REQUIRED_LANES,
            'lanes' => $rows,
            'missing_required_lanes' => $missing,
            'all_required_present' => $missing === [],
        ];
    }

    /**
     * @param  array<string,mixed>  $cycle
     * @return array<string,mixed>
     */
    private function judgeDecision(array $cycle): array
    {
        $judge = is_array($cycle['judge_decision'] ?? null) ? $cycle['judge_decision'] : [];
        $selected = (string) ($judge['selected_candidate'] ?? $judge['selected'] ?? '');
        $present = $judge !== [] && $selected !== '';

        return [
            'present' => $present,
            'selected_candidate' => $selected !== '' ? $selected : null,
            'rationale_present' => $this->nonEmpty($judge['rationale'] ?? null),
        ];
    }

    /**
     * @param  array<string,mixed>  $cycle
     * @return array<string,mixed>
     */
    private function repairStatus(array $cycle): array
    {
        $validation = is_array($cycle['focused_validation'] ?? null) ? $cycle['focused_validation'] : [];
        $validationRan = ($validation['ran'] ?? ($cycle['focused_validation_ran'] ?? null)) === true;
        $validationFailed = $validation !== []
            ? ($validation['passed'] ?? null) === false
            : (bool) ($cycle['validation_failed'] ?? false);

        $lanes = is_array($cycle['lanes'] ?? null) ? $cycle['lanes'] : [];
        $repairEntry = $lanes['repair'] ?? $lanes['repair_agent'] ?? ($cycle['repair'] ?? null);
        $present = $repairEntry !== null && $repairEntry !== false;

        return [
            'validation_ran' => $validationRan,
            'validation_failed' => $validationFailed,
            'required' => $validationFailed,
            'present' => $present,
            'status' => is_array($repairEntry) ? (string) ($repairEntry['status'] ?? 'present') : ($present ? 'present' : 'absent'),
            // satisfied = validation did not fail, OR a repair lane is present
            'satisfied' => ! $validationFailed || $present,
        ];
    }

    /**
     * @param  array<string,mixed>  $cycle
     * @return array<string,mixed>
     */
    private function mergeStatus(array $cycle): array
    {
        $merge = is_array($cycle['merge_governance'] ?? null) ? $cycle['merge_governance'] : [];
        $claimedMerged = ($merge['status'] ?? $cycle['merge_status'] ?? '') === 'merged'
            || ($cycle['merge_performed'] ?? null) === true;
        $before = (string) ($merge['main_before'] ?? $merge['base_head'] ?? '');
        $after = (string) ($merge['main_after'] ?? $merge['new_head'] ?? $cycle['merge_hash'] ?? '');
        // Respect an explicit main_advanced flag (true OR false); only fall back to
        // the before/after heuristic when the cycle did not state it.
        $explicitAdvanced = array_key_exists('main_advanced', $merge) ? (bool) $merge['main_advanced'] : null;
        $mainAdvanced = $explicitAdvanced ?? ($before !== '' && $after !== '' && $before !== $after);

        return [
            'evaluated' => $merge !== [] || ($cycle['merge_governor_evaluated'] ?? null) === true,
            'claimed_merged' => $claimedMerged,
            'main_advanced' => $mainAdvanced,
            // honest: a merge claim with no advance is a violation
            'merge_truth_ok' => ! $claimedMerged || $mainAdvanced,
        ];
    }

    /**
     * @param  array<string,mixed>  $cycle
     * @return array<string,mixed>
     */
    private function evidenceObligations(array $cycle): array
    {
        $facts = is_array($cycle['substrate_facts'] ?? null) ? $cycle['substrate_facts'] : $cycle;
        $evidence = ($facts['evidence_refs_present'] ?? null) === true
            || (is_array($facts['evidence_refs'] ?? null) && $facts['evidence_refs'] !== []);
        $inbox = ($facts['inbox_item_emitted'] ?? null) === true || $this->nonEmpty($facts['inbox_item_id'] ?? null);
        $validation = ($facts['focused_validation_ran'] ?? null) === true
            || (is_array($cycle['focused_validation'] ?? null) && ($cycle['focused_validation']['ran'] ?? null) === true);
        $mergeEval = ($facts['merge_governor_evaluated'] ?? null) === true
            || is_array($cycle['merge_governance'] ?? null);

        $present = ['evidence' => $evidence, 'inbox' => $inbox, 'validation' => $validation, 'merge_governance' => $mergeEval];

        return [
            'present' => $present,
            'all_present' => ! in_array(false, $present, true),
        ];
    }

    // ---------- hard blockers ----------

    /**
     * @param  array<string,mixed>  $cycle
     * @param  array<string,mixed>  $facts
     * @param  array<string,mixed>  $finding
     * @param  array<string,mixed>  $slice
     * @param  array<string,mixed>  $lanePlan
     * @param  array<string,mixed>  $judge
     * @param  array<string,mixed>  $repair
     * @param  array<string,mixed>  $merge
     * @return list<string>
     */
    private function hardBlockers(array $cycle, array $facts, array $finding, array $slice, array $lanePlan, array $judge, array $repair, array $merge): array
    {
        $blockers = [];
        $claimsComplete = ($cycle['claims_complete'] ?? $cycle['completed'] ?? null) === true
            || ($cycle['final_status'] ?? '') === 'cycle_completed'
            || $merge['claimed_merged'];

        if (($finding['slice_plan_required'] ?? false) && ! $slice['satisfied']) {
            $blockers[] = 'factory_max_broad_finding_without_slice_plan';
        }
        if (! $repair['satisfied']) {
            $blockers[] = 'validation_failed_without_repair_lane';
        }
        if (! $merge['merge_truth_ok']) {
            $blockers[] = 'merge_claimed_without_main_advance';
        }
        if ($claimsComplete && ! $facts['all_present']) {
            $blockers[] = 'incomplete_substrate_facts';
        }
        if ($lanePlan['multi_agent_claimed'] && ! $lanePlan['all_required_present']) {
            $blockers[] = 'multi_agent_claimed_without_required_lanes';
        }

        return array_values(array_unique($blockers));
    }

    /**
     * @param  array<string,mixed>  $facts
     * @param  array<string,mixed>  $slice
     * @param  array<string,mixed>  $lanePlan
     * @param  array<string,mixed>  $judge
     * @param  array<string,mixed>  $repair
     * @param  array<string,mixed>  $merge
     * @param  array<string,mixed>  $evidence
     * @param  list<string>  $missingCapabilities
     * @return list<array<string,mixed>>
     */
    private function invariants(bool $providerReal, array $facts, array $slice, array $lanePlan, array $judge, array $repair, array $merge, array $evidence, array $missingCapabilities): array
    {
        return [
            $this->invariant('substrate_facts_present', $facts['all_present'], 'all AP-793 substrate facts present'),
            $this->invariant('slice_plan_when_broad', $slice['satisfied'], 'broad factory_max finding has an AP-794 sliced plan'),
            $this->invariant('required_lanes_present', $lanePlan['all_required_present'], 'context_scout/architect/implementer/reviewer/judge present'),
            $this->invariant('repair_when_validation_failed', $repair['satisfied'], 'repair lane present when validation failed'),
            $this->invariant('judge_decision_present', $judge['present'], 'integration judge decision present'),
            $this->invariant('evidence_inbox_validation_merge_present', $evidence['all_present'], 'evidence, inbox, validation and merge governance present'),
            $this->invariant('merge_truth', $merge['merge_truth_ok'], 'a claimed merge advanced main'),
            $this->invariant('no_missing_capabilities', $missingCapabilities === [], 'all required capabilities present'),
            $this->invariant('runtime_real_provider', $providerReal, 'provider invoked for real (not mock/simulated)'),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function invariant(string $id, bool $ok, string $detail): array
    {
        return ['id' => $id, 'ok' => $ok, 'detail' => $detail];
    }

    // ---------- product mode projection ----------

    /**
     * @param  array<string,mixed>  $lanePlan
     * @param  array<string,mixed>  $slice
     * @param  array<string,mixed>  $judge
     * @param  array<string,mixed>  $repair
     * @param  list<string>  $missingCapabilities
     * @return array<string,mixed>
     */
    private function productModeProjection(string $status, array $lanePlan, array $slice, array $judge, array $repair, array $missingCapabilities, string $nextAction): array
    {
        $lanes = [];
        foreach ($lanePlan['lanes'] as $lane => $row) {
            $lanes[] = ['lane' => $lane, 'present' => (bool) ($row['present'] ?? false), 'status' => (string) ($row['status'] ?? 'absent')];
        }

        return [
            'schema_version' => self::PRODUCT_MODE_SCHEMA,
            'cycle_real_or_blocked' => match ($status) {
                self::STATUS_PASSED => 'real',
                self::STATUS_BLOCKED => 'blocked',
                default => 'partial',
            },
            'lanes' => $lanes,
            'slice_plan' => [
                'required' => (bool) ($slice['required'] ?? false),
                'present' => (bool) ($slice['present'] ?? false),
                'decomposition_status' => $slice['decomposition_status'] ?? null,
                'slice_count' => (int) ($slice['slice_count'] ?? 0),
            ],
            'judge_decision' => [
                'present' => (bool) ($judge['present'] ?? false),
                'selected_candidate' => $judge['selected_candidate'] ?? null,
            ],
            'repair' => [
                'required' => (bool) ($repair['required'] ?? false),
                'present' => (bool) ($repair['present'] ?? false),
                'status' => (string) ($repair['status'] ?? 'absent'),
            ],
            'missing_capabilities' => $missingCapabilities,
            'next_operator_action' => $nextAction,
        ];
    }

    /**
     * @param  list<string>  $blockers
     * @param  list<string>  $missingCapabilities
     * @param  array<string,mixed>  $slice
     * @param  array<string,mixed>  $lanePlan
     * @param  array<string,mixed>  $judge
     * @param  array<string,mixed>  $repair
     */
    private function nextOperatorAction(string $status, string $mode, array $blockers, array $missingCapabilities, array $slice, array $lanePlan, array $judge, array $repair): string
    {
        if (in_array('factory_max_broad_finding_without_slice_plan', $blockers, true)) {
            return 'Block execution: require an AP-794 slice plan (decomposition_status=sliced) before any provider runs on this broad factory_max finding.';
        }
        if (in_array('validation_failed_without_repair_lane', $blockers, true)) {
            return 'Block merge: validation failed and no repair lane ran. Route to AP-799 repair planner or operator review.';
        }
        if (in_array('merge_claimed_without_main_advance', $blockers, true)) {
            return 'Reject cycle: a merge was claimed but main did not advance. Treat as blocked, not merged.';
        }
        if (in_array('multi_agent_claimed_without_required_lanes', $blockers, true)) {
            return 'Block certification: multi-agent cycle is missing required lanes: '.implode(', ', $lanePlan['missing_required_lanes'] ?? []).'.';
        }
        if (in_array('incomplete_substrate_facts', $blockers, true)) {
            return 'Reject completion: the cycle claims completion without full AP-793 substrate facts.';
        }
        if ($missingCapabilities !== []) {
            return 'Implement/wire missing capabilities before production autonomy: '.implode(', ', $missingCapabilities).'.';
        }
        if ($status === self::STATUS_PASSED) {
            return 'Cycle certified real. Safe to record as a production multi-agent cycle.';
        }
        if ($mode !== self::MODE_RUNTIME_REAL) {
            return 'Contract self-test only (test_mode). Re-run with real recorded cycle evidence (use_real_services) to certify production.';
        }

        return 'Provide complete real cycle evidence (substrate facts, lanes, judge, evidence) to reach a production pass.';
    }

    /**
     * @return array<string,mixed>
     */
    private function claimPolicy(): array
    {
        return [
            'read_only' => true,
            'runs_provider' => false,
            'runs_agent_or_lane' => false,
            'runs_merge' => false,
            'fixtures_certify_production' => false,
            'false_pass_possible' => false,
            'test_mode_certifies_production' => false,
        ];
    }

    private function nonEmpty(mixed $value): bool
    {
        if (is_string($value)) {
            return trim($value) !== '';
        }
        if (is_array($value)) {
            return $value !== [];
        }

        return $value !== null && $value !== false;
    }
}
