<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AutonomousEvolutionSession;

use App\Services\Ai\SoftwareCompanyStewardship\AgentExecution\AgentExecutionProviderPortService;
use App\Services\Ai\SoftwareCompanyStewardship\AgentExecution\AgentExecutionSessionStoreService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusLoopPayloadNormalizer;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusStringListNormalizer;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AutonomousEvolutionSessionService;
use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Support\AtlasSecurity;
use Throwable;

/**
 * AP-801/AP-806 multi-agent workcell + AP-795 agent-execution substrate section,
 * extracted VERBATIM from AutonomousEvolutionSessionService by the GOD-DEBULK
 * split. Composes each executed cycle through the workcell (lanes + integration
 * judge + certification) and projects provider-port facts into the session
 * substrate. Provider-free and defensive: it never invokes a provider and every
 * projection is wrapped so a failure can never break the AP-786 session. Back-
 * calls to the parent's lazily-built services (workcell/provider-port/session-
 * store) go through {@see AutonomousEvolutionSessionService}; taxonomy constants
 * are referenced qualified.
 */
final class WorkcellSection
{
    public function __construct(
        private readonly AutonomousEvolutionSessionService $parent,
    ) {}

    /**
     * AP-801 · When the multi-agent workcell flag is on, project each executed
     * cycle through MultiAgentLiveCycleExecutorService (lanes + judge + repair +
     * certification). Purely additive and defensive: it composes the cycle's real
     * owner-runtime facts, never invokes a provider, and never alters the existing
     * cycle/owner-flow path. Flag off => this is a no-op and the cycle is unchanged.
     *
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    public function attachMultiAgentWorkcell(array $payload): array
    {
        try {
            $sessionId = (string) ($payload['session_id'] ?? '');
            $areaId = (string) ($payload['area_id'] ?? '');
            $focus = (string) ($payload['focus'] ?? '');
            $executor = $this->parent->multiAgentWorkcell();

            $cycles = AreaFocusLoopPayloadNormalizer::listOfArrays($payload['cycles'] ?? []);
            $summaries = [];
            foreach ($cycles as $i => $cycle) {
                // AP-806: the pre-merge judge gate already ran the workcell for this
                // cycle (merged or judge-blocked). Reuse that real result — never
                // re-run the lanes — so the summary matches the verdict that gated
                // the merge.
                $gated = $cycle['multi_agent_workcell'] ?? null;
                if (is_array($gated) && array_key_exists('judge_decision', $gated)) {
                    $summaries[] = [
                        'cycle_id' => (string) ($gated['cycle_id'] ?? ''),
                        'status' => (string) ($gated['status'] ?? ''),
                        'lane_count' => (int) ($gated['lane_count'] ?? 0),
                        'provider_invoked' => (bool) ($gated['provider_invoked'] ?? false),
                        'judge_status' => (string) data_get($gated, 'judge_decision.status', ''),
                        'merge_eligible' => (bool) ($gated['merge_eligible'] ?? false),
                        'production_certified' => (bool) ($gated['production_certified'] ?? false),
                    ];

                    continue;
                }

                $execute = $this->cycleHadRealProviderInvocation($cycle);

                // The workcell projects EXECUTED cycles (a real owner-runtime result
                // exists). For dry-run / pre-provider-blocked cycles there is nothing
                // to compose; mark it honestly instead of slicing a thin summary.
                if (! $execute) {
                    $cycles[$i]['multi_agent_workcell'] = [
                        'schema_version' => 'atlas.agent_execution.multi_agent_workcell_summary.v1',
                        'ap_contract' => 'AP-801',
                        'status' => 'not_executed',
                        'reason' => 'cycle did not run an owner-runtime provider; no multi-agent composition.',
                    ];
                    $summaries[] = [
                        'cycle_id' => (string) ($cycle['cycle_id'] ?? ''),
                        'status' => 'not_executed',
                        'lane_count' => 0,
                        'provider_invoked' => false,
                        'judge_status' => '',
                        'merge_eligible' => false,
                        'production_certified' => false,
                    ];

                    continue;
                }

                $workcell = $executor->execute([
                    'execute' => $execute,
                    'area_id' => $areaId,
                    'focus' => $focus,
                    'session_id' => $sessionId,
                    'cycle_id' => (string) ($cycle['cycle_id'] ?? ''),
                    'scope_profile' => (string) ($cycle['scope_profile'] ?? $payload['scope_profile'] ?? 'balanced'),
                    'finding' => is_array($cycle['selected_finding'] ?? null) ? $cycle['selected_finding'] : [],
                    'slice_plan' => is_array($cycle['finding_slice_plan'] ?? null) ? $cycle['finding_slice_plan'] : null,
                    'executable_slice' => $execute ? $this->workcellSliceFromCycle($cycle) : null,
                    'allowed_files' => AreaFocusStringListNormalizer::coercedStringValues($cycle['allowed_files'] ?? []),
                    'owner_runtime_result' => $execute ? $this->workcellOwnerRuntimeFromCycle($cycle) : null,
                ]);

                $cycles[$i]['multi_agent_workcell'] = $workcell;
                $summaries[] = [
                    'cycle_id' => (string) ($workcell['cycle_id'] ?? ''),
                    'status' => (string) ($workcell['status'] ?? ''),
                    'lane_count' => (int) ($workcell['lane_count'] ?? 0),
                    'provider_invoked' => (bool) ($workcell['provider_invoked'] ?? false),
                    'judge_status' => (string) data_get($workcell, 'judge_decision.status', ''),
                    'merge_eligible' => (bool) ($workcell['merge_eligible'] ?? false),
                    'production_certified' => (bool) ($workcell['production_certified'] ?? false),
                ];
            }

            $payload['cycles'] = $cycles;
            $payload['multi_agent_workcell'] = [
                'schema_version' => 'atlas.agent_execution.multi_agent_workcell_summary.v1',
                'ap_contract' => 'AP-801',
                'enabled' => true,
                'cycle_count' => count($summaries),
                'cycles' => $summaries,
            ];
        } catch (Throwable $e) {
            $payload['multi_agent_workcell'] = [
                'schema_version' => 'atlas.agent_execution.multi_agent_workcell_summary.v1',
                'ap_contract' => 'AP-801',
                'enabled' => true,
                'status' => 'workcell_projection_unavailable',
                'reason' => substr(AtlasSecurity::redactString($e->getMessage()), 0, 200),
            ];
        }

        return $payload;
    }

    /**
     * AP-806 · HARD pre-merge integration-judge gate. When the multi-agent
     * workcell is engaged, the AP-801 workcell (lanes + AP-797 integration judge)
     * is run on the EXECUTED, committed cycle BEFORE the merge, and the judge
     * verdict becomes a precondition for merging: a cycle the judge did not ACCEPT
     * (repair_required / rejected / operator_review / blocked) must NOT merge — its
     * evidence/inbox are still emitted for audit. This closes the proven
     * false-success path where a merge landed while the judge said repair_required
     * (AP-790 ledger cycles 251-254, 259). The workcell never invokes a provider,
     * so the gate adds zero provider cost; on any workcell error it fails CLOSED
     * (no merge) so an uncertifiable cycle can never slip through.
     *
     * @param  array<string,mixed>  $cycleLike  executed + committed cycle facts
     * @param  array<string,mixed>  $input
     * @return array{engaged:bool,accept:bool,status:string,workcell:array<string,mixed>|null}
     */
    public function workcellMergeGate(array $cycleLike, array $input): array
    {
        $on = (bool) ($input['multi_agent_workcell']
            ?? config('atlas.software_company_stewardship.multi_agent_workcell', false));
        if (! $on || ! $this->cycleHadRealProviderInvocation($cycleLike)) {
            // Flag off, or no real execution to certify => no gate (the executed-cycle
            // gates upstream already blocked anything that did not run a provider).
            return ['engaged' => false, 'accept' => true, 'status' => '', 'workcell' => null];
        }

        try {
            $workcell = $this->parent->multiAgentWorkcell()->execute([
                'execute' => true,
                'area_id' => (string) ($input['area_id'] ?? ''),
                'focus' => (string) ($input['focus'] ?? AutonomousEvolutionSessionService::DEFAULT_FOCUS),
                'session_id' => (string) ($cycleLike['cycle_id'] ?? ''),
                'cycle_id' => (string) ($cycleLike['cycle_id'] ?? ''),
                'scope_profile' => (string) ($cycleLike['scope_profile'] ?? 'balanced'),
                'finding' => is_array($cycleLike['selected_finding'] ?? null) ? $cycleLike['selected_finding'] : [],
                'slice_plan' => is_array($cycleLike['finding_slice_plan'] ?? null) ? $cycleLike['finding_slice_plan'] : null,
                'executable_slice' => $this->workcellSliceFromCycle($cycleLike),
                'allowed_files' => AreaFocusStringListNormalizer::coercedStringValues($cycleLike['allowed_files'] ?? []),
                'owner_runtime_result' => $this->workcellOwnerRuntimeFromCycle($cycleLike),
            ]);
        } catch (Throwable $e) {
            // Fail closed: an uncertifiable cycle never merges.
            return [
                'engaged' => true,
                'accept' => false,
                'status' => 'workcell_unavailable',
                'workcell' => [
                    'schema_version' => 'atlas.agent_execution.multi_agent_workcell_summary.v1',
                    'ap_contract' => 'AP-801',
                    'enabled' => true,
                    'status' => 'workcell_gate_unavailable',
                    'reason' => substr(AtlasSecurity::redactString($e->getMessage()), 0, 200),
                ],
            ];
        }

        return [
            'engaged' => true,
            'accept' => (bool) ($workcell['merge_eligible'] ?? false),
            'status' => (string) data_get($workcell, 'judge_decision.status', ''),
            'workcell' => $workcell,
        ];
    }

    /**
     * Build a bounded executable slice from an executed cycle so the workcell can
     * judge the produced diff. Reuses the cycle's own scope (allowed files) and
     * validation; never widens scope.
     *
     * @param  array<string,mixed>  $cycle
     * @return array<string,mixed>|null
     */
    public function workcellSliceFromCycle(array $cycle): ?array
    {
        $plannedSlices = AreaFocusLoopPayloadNormalizer::listOfArrays(data_get($cycle, 'finding_slice_plan.slices', []));
        if ($plannedSlices !== []) {
            $activeSliceId = (string) data_get($cycle, 'selected_finding.active_slice_id', '');
            if ($activeSliceId !== '') {
                foreach ($plannedSlices as $slice) {
                    if ((string) ($slice['slice_id'] ?? '') === $activeSliceId) {
                        return $slice;
                    }
                }
            }

            return $plannedSlices[0];
        }

        $allowed = AreaFocusStringListNormalizer::coercedStringValues($cycle['allowed_files'] ?? []);
        $changed = $this->workcellChangedFilesFromCycle($cycle);
        $allowed = $allowed !== [] ? $allowed : $changed;
        if ($allowed === []) {
            return null;
        }
        $finding = is_array($cycle['selected_finding'] ?? null) ? $cycle['selected_finding'] : [];
        $validationCommands = AreaFocusStringListNormalizer::coercedStringValues(data_get($cycle, 'validation.commands', []));

        return [
            'slice_id' => 'mas_'.substr(MissionCanonicalHash::sha256([$cycle['cycle_id'] ?? '', $allowed]), 0, 16),
            'sequence' => 1,
            'owner' => (string) ($cycle['owner'] ?? 'atlas_dev'),
            'risk_level' => (string) ($finding['severity'] ?? 'medium') ?: 'medium',
            'objective' => (string) ($finding['title'] ?? 'Bounded stewardship slice'),
            'allowed_files' => $allowed,
            'forbidden_files' => AutonomousEvolutionSessionService::FORBIDDEN_PATHS,
            'expected_diff_shape' => $this->workcellDiffShape($changed),
            'validation_commands' => $validationCommands !== [] ? $validationCommands : ['git diff --check'],
            'evidence_obligations' => ['test_results', 'changed_files'],
            'merge_policy' => 'review_required',
            'max_runtime_seconds' => 900,
            'retry_policy' => ['max_attempts' => 1],
        ];
    }

    /**
     * @param  list<string>  $changed
     */
    public function workcellDiffShape(array $changed): string
    {
        if ($changed === []) {
            return 'service_and_test';
        }
        $allTests = true;
        foreach ($changed as $file) {
            if (! str_contains($file, 'tests/') && ! str_ends_with($file, 'Test.php')) {
                $allTests = false;
                break;
            }
        }

        return $allTests ? 'test_only' : 'service_and_test';
    }

    /**
     * @param  array<string,mixed>  $cycle
     * @return list<string>
     */
    public function workcellChangedFilesFromCycle(array $cycle): array
    {
        $changed = AreaFocusStringListNormalizer::coercedStringValues($cycle['changed_files'] ?? []);
        if ($changed !== []) {
            return AreaFocusStringListNormalizer::uniqueStringValues($changed);
        }

        return AreaFocusStringListNormalizer::uniqueStringValues(array_filter(
            (array) data_get($cycle, 'owner_flow.execution_result.changed_files', []),
            'is_string',
        ));
    }

    /**
     * Project the executed cycle's real owner-flow/provider facts into the
     * owner_runtime_result shape the workcell composes. This is the cycle's own
     * result, not a new provider call.
     *
     * @param  array<string,mixed>  $cycle
     * @return array<string,mixed>
     */
    /**
     * Did this cycle run a REAL provider invocation? The default AP-786 owner-flow
     * path runs the provider inside the AP-747->AP-750 chain and reports it as
     * `owner_flow.provider_invoked` (true only for a real, non-deterministic owner
     * result); the cycle's top-level `provider_called` stays false there because
     * the runner never calls a provider directly. The legacy direct-provider path
     * sets `provider_called`. Honoring both — and never a deterministic/simulated
     * result — is what lets the AP-801 workcell compose real cycles. The AP-800
     * certification inside the workcell still independently gates production.
     *
     * @param  array<string,mixed>  $cycle
     */
    public function cycleHadRealProviderInvocation(array $cycle): bool
    {
        if (($cycle['provider_called'] ?? data_get($cycle, 'provider_result.provider_called') ?? false) === true) {
            return true;
        }

        return data_get($cycle, 'owner_flow.provider_invoked') === true
            && data_get($cycle, 'owner_flow.provider_router_used') !== true;
    }

    /**
     * Derive the REAL validation result for the AP-801 workcell/judge from an
     * AP-786 owner-flow cycle. The legacy direct-provider path fills
     * $cycle['validation']; the owner-flow path does NOT — its validation
     * authority is the merge governor (run_validation=true; it only reaches
     * merged / review_required / auto_merge_eligible AFTER validation passes) plus
     * the senior-loop verification. Without this the judge received passed=null and
     * returned a FALSE repair_required on cycles that actually validated and merged.
     *
     * It NEVER fabricates a pass: a real merge / governor-validation-pass /
     * verification-pass sets passed=true; a validation_failed signal sets false;
     * truly unknown stays null (so the judge still withholds, honestly).
     *
     * @param  array<string,mixed>  $cycle
     * @return array<string,mixed>
     */
    public function workcellValidationFromCycle(array $cycle): array
    {
        $explicit = is_array($cycle['validation'] ?? null) ? $cycle['validation'] : [];
        $commands = array_values(array_filter(
            (array) ($explicit['commands'] ?? data_get($cycle, 'merge_governance.validation.commands', [])),
            'is_string',
        ));
        $base = ['ran' => true, 'commands' => $commands, 'results' => array_values((array) ($explicit['results'] ?? []))];

        // 1) Explicit validation result (legacy direct-provider path).
        if (array_key_exists('passed', $explicit)) {
            return $base + ['passed' => (bool) $explicit['passed'], 'source' => 'cycle_validation'];
        }
        // 2) A real merge means the merge governor ran validation and it passed.
        if (($cycle['merge_performed'] ?? false) === true) {
            return $base + ['passed' => true, 'source' => 'merge_governor_validated_and_merged'];
        }
        // 3) Merge governor's own recorded validation result.
        $mgValidation = data_get($cycle, 'merge_governance.validation', null);
        if (is_array($mgValidation) && array_key_exists('passed', $mgValidation)) {
            return $base + ['passed' => (bool) $mgValidation['passed'], 'source' => 'merge_governor_validation'];
        }
        // 4) Clean, validated diff the governor withheld only for review.
        if (in_array((string) data_get($cycle, 'merge_governance.status', ''), ['review_required', 'auto_merge_eligible'], true)) {
            return $base + ['passed' => true, 'source' => 'merge_governor_validated_review_withheld'];
        }
        // 5) Owner-flow senior-loop verification.
        $verification = (string) data_get($cycle, 'owner_flow.execution_result.verification_status', data_get($cycle, 'owner_flow.verification_status', ''));
        if ($verification === 'passed') {
            return $base + ['passed' => true, 'source' => 'owner_flow_verification'];
        }
        if ($verification !== '') {
            return $base + ['passed' => false, 'source' => 'owner_flow_verification'];
        }
        // 6) Explicit validation-failure blocker.
        if (in_array('validation_failed', AreaFocusStringListNormalizer::coercedStringValues($cycle['blockers'] ?? []), true)) {
            return $base + ['passed' => false, 'source' => 'cycle_blocker_validation_failed'];
        }

        // 7) Unknown — never fabricate a pass.
        return ['ran' => false, 'passed' => null, 'commands' => $commands, 'results' => [], 'source' => 'unknown'];
    }

    public function workcellOwnerRuntimeFromCycle(array $cycle): array
    {
        $usesOwnerChain = (bool) data_get($cycle, 'owner_flow.uses_full_owner_runtime_chain', false);
        $changedFiles = $this->workcellChangedFilesFromCycle($cycle);
        $validation = $this->workcellValidationFromCycle($cycle);
        $evidenceRefs = array_values(array_filter([
            ...array_map(static fn (string $ref): array => ['kind' => 'owner_receipt', 'ref' => $ref], array_values(array_filter([
                (string) ($cycle['result_bridge_id'] ?? ''),
                (string) ($cycle['inbox_item_id'] ?? ''),
            ], static fn (string $v): bool => $v !== ''))),
            $changedFiles !== [] ? ['kind' => 'changed_files', 'ref' => 'cycle:'.(string) ($cycle['cycle_id'] ?? '').':changed-files'] : null,
            ($validation['ran'] ?? false) === true ? ['kind' => 'test_results', 'ref' => 'cycle:'.(string) ($cycle['cycle_id'] ?? '').':validation'] : null,
        ], static fn (mixed $ref): bool => is_array($ref) && ($ref['ref'] ?? '') !== ''));

        return [
            'provider' => (string) data_get($cycle, 'provider_result.provider', 'cursor_cli'),
            'model' => (string) data_get($cycle, 'provider_result.model', ''),
            'provider_invoked' => $this->cycleHadRealProviderInvocation($cycle),
            'provider_authority' => $usesOwnerChain ? 'atlas_decide' : '',
            'auth_mode' => 'local_account',
            'changed_files' => $changedFiles,
            'diff_shape' => $this->workcellDiffShape($changedFiles),
            'validation' => $validation,
            'worktree_path' => (string) ($cycle['worktree_path'] ?? ''),
            'branch_ref' => (string) ($cycle['branch_ref'] ?? ''),
            'inbox_item_id' => (string) ($cycle['inbox_item_id'] ?? ''),
            'result_bridge_id' => (string) ($cycle['result_bridge_id'] ?? ''),
            'evidence_refs' => $evidenceRefs,
            'owner_runtime_chain' => $usesOwnerChain ? 'AP-747->AP-748->AP-749->AP-758->AP-759->AP-750' : '',
            'merge_governance' => is_array($cycle['merge_governance'] ?? null) ? $cycle['merge_governance'] : [],
        ];
    }

    /**
     * AP-795/AP-793 · Project each cycle's already-present provider facts through
     * the provider port and, when recording, into the durable session store.
     *
     * Purely additive and defensive: it never mutates the existing cycle/receipt
     * structure, never invokes a provider, and is wrapped so a substrate failure
     * can never break the AP-786 session. Persistence is idempotent
     * (session_hash) so re-runs are safe.
     *
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    public function attachAgentExecutionSubstrate(array $payload, bool $record): array
    {
        try {
            $port = $this->parent->agentProviderPort();
            $sessionId = (string) ($payload['session_id'] ?? '');
            $areaId = (string) ($payload['area_id'] ?? '');
            $focus = (string) ($payload['focus'] ?? '');

            $ports = [];
            foreach (AreaFocusLoopPayloadNormalizer::listOfArrays($payload['cycles'] ?? []) as $cycle) {
                $facts = $port->normalize(['cycle' => $cycle]);
                $cycleId = (string) ($cycle['cycle_id'] ?? '');
                $ports[] = [
                    'cycle_id' => $cycleId,
                    'provider_id' => $facts['provider_id'],
                    'model_family' => $facts['model_family'],
                    'invocation_state' => $facts['invocation_state'],
                    'provider_invoked' => $facts['provider_invoked'],
                    'auth_mode' => $facts['auth_mode'],
                    'port_status' => $facts['port_status'],
                    'port_hash' => $facts['port_hash'],
                ];

                if ($record) {
                    $this->parent->agentSessionStore()->record([
                        'provider_port' => $facts,
                        'cycle_id' => $cycleId,
                        'session_id' => $sessionId,
                        'area_id' => $areaId,
                        'focus' => $focus,
                        'worktree_path' => (string) ($cycle['worktree_path'] ?? ''),
                    ]);
                }
            }

            $payload['agent_execution'] = [
                'schema_version' => 'atlas.agent_execution.session_summary.v1',
                'substrate_contract' => 'AP-793',
                'ap_contract' => 'AP-795',
                'provider_port_schema' => AgentExecutionProviderPortService::SCHEMA,
                'session_store_schema' => AgentExecutionSessionStoreService::SCHEMA,
                'persisted' => $record,
                'cycle_count' => count($ports),
                'ports' => $ports,
            ];
        } catch (Throwable $e) {
            $payload['agent_execution'] = [
                'schema_version' => 'atlas.agent_execution.session_summary.v1',
                'substrate_contract' => 'AP-793',
                'ap_contract' => 'AP-795',
                'status' => 'substrate_projection_unavailable',
                'reason' => substr(AtlasSecurity::redactString($e->getMessage()), 0, 200),
            ];
        }

        return $payload;
    }
}
