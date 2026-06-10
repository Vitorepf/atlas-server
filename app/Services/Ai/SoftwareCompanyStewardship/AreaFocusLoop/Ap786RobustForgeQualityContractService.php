<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\Mission\MissionCanonicalHash;

/**
 * AP-786 · executable robust Forge quality contract.
 *
 * AP-786's "Robust Flow Contract" lists 12 capabilities that every real
 * autonomous-evolution execution must prove BEFORE a provider runs. The
 * AutonomousEvolutionSessionService only *names* those capabilities inside its
 * flow_integrity_gate; nothing turns them into a machine-readable accept/block
 * decision for a concrete finding.
 *
 * This service is that evaluator. Given a selected finding, its allowed files,
 * the owner, the sandbox/release metadata and validation commands (plus the
 * capability evidence the orchestrator can supply), it produces a deterministic
 * `ready|blocked` packet that the flow_integrity_gate can list and consume.
 *
 * It is a pure read-only evaluator: it never invokes a provider, never mutates
 * the repo, never merges and never executes anything. It only decides whether
 * the robust flow contract is satisfied and, if not, exactly which capabilities
 * are missing.
 */
final class Ap786RobustForgeQualityContractService
{
    public const CONTRACT_SCHEMA = 'atlas.software_company_stewardship.ap786_robust_flow_contract.v1';

    public const STATUS_READY = 'ready';

    public const STATUS_BLOCKED = 'blocked';

    /**
     * Canonical 12 capabilities from AP-786 (docs/ap/AP-786-...). Kept in this
     * order so the packet is deterministic and matches the flow_integrity_gate
     * `required_robust_flow_capabilities` list.
     *
     * @var list<string>
     */
    public const REQUIRED_CAPABILITIES = [
        'native_obra_or_work_packet',
        'self_directed_spec_or_sdd_packet',
        'tdd_test_contract',
        'bdd_acceptance_contract',
        'atlas_decide_provider_topology',
        'aawr_or_multi_agent_workcell',
        'universal_gates_and_programming_governance',
        'deterministic_validation_suite',
        'repair_loop_with_failed_gate_capsule',
        'evidence_ledger_and_decision_receipts',
        'replay_or_reproduction_packet',
        'ap769_ap774_merge_governance',
    ];

    /** Owner-flow chain that must own provider execution (never a direct driver). */
    private const REQUIRED_OWNER_FLOW_APS = ['AP-747', 'AP-756', 'AP-757', 'AP-749', 'AP-758', 'AP-759', 'AP-750'];

    /** The six AAWR / multi-agent workcell roles. */
    private const WORKCELL_ROLES = ['context_scout', 'architect', 'implementer', 'reviewer', 'repair_agent', 'certifier'];

    private const FORBIDDEN_FILES = ['.env', 'storage/secrets', 'config/secrets', 'vendor/', 'node_modules/'];

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function build(array $input): array
    {
        $areaId = $this->str($input['area_id'] ?? 'agentic_engineering_os') ?: 'agentic_engineering_os';
        $focus = $this->str($input['focus'] ?? 'dev_forge') ?: 'dev_forge';
        $owner = $this->normalizeOwner($this->str($input['owner'] ?? ''));
        $finding = is_array($input['selected_finding'] ?? null) ? $input['selected_finding'] : [];
        $allowedFiles = AreaFocusStringListNormalizer::trimmedUniqueStrings($input['allowed_files'] ?? data_get($finding, 'allowed_files', []));

        $sdd = $this->sddPacket($input, $finding, $allowedFiles, $areaId, $focus);
        $tdd = $this->tddContract($input, $finding, $allowedFiles);
        $bdd = $this->bddContract($input, $finding);
        $providerTopology = $this->providerTopologyRequirement($input, $owner);
        $workcell = $this->workcellRoles($input);
        $repair = $this->repairPolicy($input);
        $evidence = $this->evidenceRequirements($input);
        $merge = $this->mergeRequirements($input);
        $validationCommands = AreaFocusStringListNormalizer::trimmedUniqueStrings($input['validation_commands'] ?? data_get($input, 'validation.commands', []));

        $capabilities = $this->capabilities($finding, $allowedFiles, $sdd, $tdd, $bdd, $providerTopology, $workcell, $repair, $evidence, $merge, $validationCommands);
        $missing = array_values(array_map(
            static fn (array $c): string => (string) $c['name'],
            array_filter($capabilities, static fn (array $c): bool => $c['ok'] !== true),
        ));
        $blockers = $this->blockers($capabilities, $providerTopology, $tdd);
        $status = $blockers === [] ? self::STATUS_READY : self::STATUS_BLOCKED;

        $payload = [
            'schema_version' => self::CONTRACT_SCHEMA,
            'ap_contract' => 'AP-786',
            'status' => $status,
            'stack' => 'Atlas Software Company Stewardship Stack',
            'area_id' => $areaId,
            'focus' => $focus,
            'owner' => $owner,
            'selected_finding' => $this->findingSummary($finding),
            'sandbox' => $this->sandboxSummary($input),
            'capabilities' => $capabilities,
            'sdd_packet' => $sdd,
            'tdd_contract' => $tdd,
            'bdd_contract' => $bdd,
            'provider_topology_requirement' => $providerTopology,
            'workcell_roles' => $workcell,
            'repair_policy' => $repair,
            'evidence_requirements' => $evidence,
            'merge_requirements' => $merge,
            'validation_commands' => $validationCommands,
            'required_owner_flow_chain' => self::REQUIRED_OWNER_FLOW_APS,
            'missing_capabilities' => $missing,
            'blockers' => $blockers,
            'next_actions' => $this->nextActions($status, $blockers),
            'claim_policy' => $this->claimPolicy(),
        ];
        $payload['contract_hash'] = 'sha256:'.MissionCanonicalHash::sha256($this->identity($payload));
        $payload['generated_at'] = AreaFocusUtcClock::atomNow();

        return $payload;
    }

    // ------------------------------------------------------------------
    // Capability evaluation
    // ------------------------------------------------------------------

    /**
     * @param  array<string,mixed>  $finding
     * @param  list<string>  $allowedFiles
     * @param  array<string,mixed>  $sdd
     * @param  array<string,mixed>  $tdd
     * @param  array<string,mixed>  $bdd
     * @param  array<string,mixed>  $providerTopology
     * @param  array<string,mixed>  $workcell
     * @param  array<string,mixed>  $repair
     * @param  array<string,mixed>  $evidence
     * @param  array<string,mixed>  $merge
     * @param  list<string>  $validationCommands
     * @return list<array<string,mixed>>
     */
    private function capabilities(array $finding, array $allowedFiles, array $sdd, array $tdd, array $bdd, array $providerTopology, array $workcell, array $repair, array $evidence, array $merge, array $validationCommands): array
    {
        $caps = [];

        $caps[] = $this->capability(
            'native_obra_or_work_packet',
            $finding !== [] && (string) ($finding['finding_id'] ?? '') !== '' && $allowedFiles !== [],
            'Work is a governed Obra/work packet (finding + allowed_files), not a loose prompt.',
            $this->refs([
                'finding:'.(string) ($finding['finding_id'] ?? ''),
                $allowedFiles !== [] ? 'allowed_files:'.count($allowedFiles) : '',
            ]),
        );

        $caps[] = $this->capability(
            'self_directed_spec_or_sdd_packet',
            (bool) ($sdd['ok'] ?? false),
            'A spec/SDD packet (objective + scope + acceptance) exists before implementation.',
            $this->refs([(string) ($sdd['spec_id'] ?? ''), (string) ($sdd['source'] ?? '')]),
        );

        $caps[] = $this->capability(
            'tdd_test_contract',
            (bool) ($tdd['ok'] ?? false),
            'Tests are declared before the implementation path runs (test-first).',
            $this->refs($tdd['tests_required'] ?? []),
        );

        $caps[] = $this->capability(
            'bdd_acceptance_contract',
            (bool) ($bdd['ok'] ?? false),
            'User-visible acceptance behavior is explicit.',
            $this->refs([(string) ($bdd['operator_visible_outcome'] ?? '')]),
        );

        $caps[] = $this->capability(
            'atlas_decide_provider_topology',
            (bool) ($providerTopology['ok'] ?? false),
            'Provider/model/role choice comes from Atlas Decide, not a hardcoded/direct shortcut.',
            $this->refs([(string) ($providerTopology['source'] ?? '')]),
        );

        $caps[] = $this->capability(
            'aawr_or_multi_agent_workcell',
            (bool) ($workcell['ok'] ?? false),
            'Context scout, architect, implementer, reviewer, repair and certifier roles are explicit.',
            $this->refs($workcell['assigned_roles'] ?? []),
        );

        $caps[] = $this->capability(
            'universal_gates_and_programming_governance',
            $validationCommands !== [] && (bool) ($evidence['programming_governance'] ?? false),
            'Universal gates and programming governance run before any result claim.',
            $this->refs(['programming_governance:'.($evidence['programming_governance'] ? 'declared' : 'missing')]),
        );

        $caps[] = $this->capability(
            'deterministic_validation_suite',
            $validationCommands !== [],
            'Validation is executable and replayable (explicit validation commands).',
            $this->refs($validationCommands),
        );

        $caps[] = $this->capability(
            'repair_loop_with_failed_gate_capsule',
            (bool) ($repair['ok'] ?? false),
            'Failed gates feed repair attempts with a captured failed-gate capsule.',
            $this->refs([(string) ($repair['failed_gate_capsule_schema'] ?? '')]),
        );

        $caps[] = $this->capability(
            'evidence_ledger_and_decision_receipts',
            (bool) ($evidence['ok'] ?? false),
            'Result is evidenced by Decision Receipts and Evidence Ledger, not chat narration.',
            $this->refs([(string) ($evidence['decision_receipt_id'] ?? ''), (string) ($evidence['evidence_ledger_ref'] ?? '')]),
        );

        $caps[] = $this->capability(
            'replay_or_reproduction_packet',
            (bool) ($evidence['replay_ok'] ?? false),
            'The work can be replayed or reproduced later (replay/reproduction packet).',
            $this->refs([(string) ($evidence['replay_ref'] ?? '')]),
        );

        $caps[] = $this->capability(
            'ap769_ap774_merge_governance',
            (bool) ($merge['ok'] ?? false),
            'Merge is governed by AP-769/AP-774, ff-only when eligible, and never hidden.',
            $this->refs($merge['governed_by'] ?? []),
        );

        return $caps;
    }

    /**
     * @param  list<string>  $evidenceRefs
     * @return array<string,mixed>
     */
    private function capability(string $name, bool $ok, string $meaning, array $evidenceRefs): array
    {
        return [
            'name' => $name,
            'ok' => $ok,
            'missing' => ! $ok,
            'meaning' => $meaning,
            'evidence_refs' => $evidenceRefs,
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $capabilities
     * @param  array<string,mixed>  $providerTopology
     * @param  array<string,mixed>  $tdd
     * @return list<string>
     */
    private function blockers(array $capabilities, array $providerTopology, array $tdd): array
    {
        $blockers = [];
        foreach ($capabilities as $cap) {
            if ($cap['ok'] !== true) {
                $blockers[] = 'missing_capability:'.(string) $cap['name'];
            }
        }
        // A direct/hardcoded provider claim is an explicit, named violation on top of
        // the missing atlas_decide_provider_topology capability.
        if (($providerTopology['direct_provider_claim'] ?? false) === true) {
            $blockers[] = 'direct_provider_path_claimed_forbidden';
        }
        if (($tdd['test_first_claim'] ?? null) === false && ($tdd['ok'] ?? false) === true) {
            $blockers[] = 'tests_declared_but_not_test_first';
        }

        return AreaFocusStringListNormalizer::uniqueStringValues($blockers);
    }

    // ------------------------------------------------------------------
    // Packets
    // ------------------------------------------------------------------

    /**
     * @param  array<string,mixed>  $input
     * @param  array<string,mixed>  $finding
     * @param  list<string>  $allowedFiles
     * @return array<string,mixed>
     */
    private function sddPacket(array $input, array $finding, array $allowedFiles, string $areaId, string $focus): array
    {
        $spec = is_array($input['sdd_packet'] ?? null) ? $input['sdd_packet']
            : (is_array($input['spec'] ?? null) ? $input['spec'] : []);
        $seed = is_array($finding['spec_seed'] ?? null) ? $finding['spec_seed'] : [];
        $objective = $this->str($spec['objective'] ?? $finding['why_it_matters'] ?? $seed['objective'] ?? '');
        $acceptance = AreaFocusStringListNormalizer::trimmedUniqueStrings($spec['acceptance'] ?? $seed['acceptance'] ?? []);
        $ownerDocs = AreaFocusStringListNormalizer::trimmedUniqueStrings($spec['owner_docs'] ?? $seed['owner_docs'] ?? []);
        $specId = $this->str($spec['spec_id'] ?? $seed['candidate_id'] ?? '');
        $source = $spec !== [] ? 'sdd_packet' : ($seed !== [] ? 'finding_spec_seed' : 'none');

        return [
            'objective' => $objective,
            'scope' => $this->str($spec['scope'] ?? $finding['title'] ?? '').' ('.$areaId.'/'.$focus.')',
            'allowed_files' => $allowedFiles,
            'forbidden_files' => self::FORBIDDEN_FILES,
            'owner_docs' => $ownerDocs,
            'acceptance' => $acceptance,
            'spec_id' => $specId,
            'source' => $source,
            'ok' => $objective !== '' && $allowedFiles !== [] && ($specId !== '' || $acceptance !== []),
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     * @param  array<string,mixed>  $finding
     * @param  list<string>  $allowedFiles
     * @return array<string,mixed>
     */
    private function tddContract(array $input, array $finding, array $allowedFiles): array
    {
        $tdd = is_array($input['tdd'] ?? null) ? $input['tdd'] : (is_array($input['tdd_contract'] ?? null) ? $input['tdd_contract'] : []);
        $testsRequired = AreaFocusStringListNormalizer::trimmedUniqueStrings($tdd['tests_required'] ?? $tdd['tests'] ?? data_get($finding, 'spec_seed.tests_required', []));
        $focusedTest = $this->str($tdd['focused_test'] ?? data_get($finding, 'expected_test_path', ''));
        // test_first defaults to true only when tests are actually declared.
        $testFirst = array_key_exists('test_first', $tdd) ? (bool) $tdd['test_first'] : ($testsRequired !== []);

        return [
            'tests_required' => $testsRequired,
            'test_first_claim' => $testsRequired !== [] ? $testFirst : false,
            'focused_test_mapping' => [
                'focused_test' => $focusedTest,
                'maps_to_allowed_files' => $focusedTest !== '' && $allowedFiles !== [],
            ],
            // Capability is satisfied when tests are declared; test-first is enforced
            // separately as a named blocker so "tests written after" is rejected too.
            'ok' => $testsRequired !== [],
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     * @param  array<string,mixed>  $finding
     * @return array<string,mixed>
     */
    private function bddContract(array $input, array $finding): array
    {
        $bdd = is_array($input['bdd'] ?? null) ? $input['bdd'] : (is_array($input['bdd_contract'] ?? null) ? $input['bdd_contract'] : []);
        $acceptance = AreaFocusStringListNormalizer::trimmedUniqueStrings($bdd['behavior_acceptance'] ?? $bdd['acceptance'] ?? data_get($finding, 'spec_seed.acceptance', []));
        $outcome = $this->str($bdd['operator_visible_outcome'] ?? $finding['why_it_matters'] ?? '');

        return [
            'behavior_acceptance' => $acceptance,
            'operator_visible_outcome' => $outcome,
            'ok' => $acceptance !== [] && $outcome !== '',
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function providerTopologyRequirement(array $input, string $owner): array
    {
        $topology = is_array($input['provider_topology'] ?? null) ? $input['provider_topology'] : [];
        $source = strtolower($this->str($topology['source'] ?? ''));
        $directClaim = (bool) ($input['direct_provider_claim'] ?? $topology['direct_provider_claim'] ?? false)
            || in_array($source, ['direct', 'hardcoded', 'direct_provider_driver'], true)
            || (string) ($input['provider_path'] ?? '') === 'direct_provider_driver';
        $allowDirectDriverDiagnostic = (bool) ($input['allow_direct_provider_driver'] ?? false);
        $chosenByAtlasDecide = $source === 'atlas_decide' || (bool) ($topology['chosen_by_atlas_decide'] ?? false);

        return [
            'must_use_atlas_decide' => true,
            'no_hardcoded_provider_path' => true,
            'owner_flow_chain' => self::REQUIRED_OWNER_FLOW_APS,
            'source' => $source !== '' ? $source : 'none',
            'chosen_by_atlas_decide' => $chosenByAtlasDecide,
            'direct_provider_claim' => $directClaim,
            'direct_provider_driver_diagnostic_allowed' => $allowDirectDriverDiagnostic,
            'owner' => $owner,
            // Atlas Decide / owner runtime must choose the provider; AP-786 cannot
            // hardcode a direct provider path. A direct claim fails this requirement.
            'ok' => $chosenByAtlasDecide && ! $directClaim,
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function workcellRoles(array $input): array
    {
        $workcell = is_array($input['workcell'] ?? null) ? $input['workcell']
            : (is_array($input['workcell_roles'] ?? null) ? $input['workcell_roles'] : []);
        $assigned = [];
        foreach (self::WORKCELL_ROLES as $role) {
            $value = $workcell[$role] ?? null;
            if (is_string($value) && trim($value) !== '') {
                $assigned[] = $role;
            } elseif (is_array($value) && $value !== []) {
                $assigned[] = $role;
            } elseif ($value === true) {
                $assigned[] = $role;
            }
        }
        $missing = array_values(array_diff(self::WORKCELL_ROLES, $assigned));

        return [
            'required_roles' => self::WORKCELL_ROLES,
            'assigned_roles' => $assigned,
            'missing_roles' => $missing,
            'ok' => $missing === [],
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function repairPolicy(array $input): array
    {
        $repair = is_array($input['repair_policy'] ?? null) ? $input['repair_policy'] : [];
        $maxAttempts = (int) ($repair['max_attempts'] ?? 2);
        $maxAttempts = max(1, min(5, $maxAttempts));
        $capsuleSchema = $this->str($repair['failed_gate_capsule_schema'] ?? 'atlas.software_company_stewardship.ap786_failed_gate_capsule.v1');
        $stopConditions = AreaFocusStringListNormalizer::trimmedUniqueStrings($repair['stop_conditions'] ?? [
            'max_attempts_reached',
            'validation_still_failing',
            'diff_outside_allowed_files',
            'no_progress_between_attempts',
        ]);

        return [
            'max_attempts' => $maxAttempts,
            'failed_gate_capsule_schema' => $capsuleSchema,
            'stop_conditions' => $stopConditions,
            'ok' => $capsuleSchema !== '' && $stopConditions !== [],
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function evidenceRequirements(array $input): array
    {
        $evidence = is_array($input['evidence'] ?? null) ? $input['evidence'] : [];
        $decisionReceipt = $this->str($evidence['decision_receipt_id'] ?? $evidence['decision_receipt'] ?? '');
        $evidenceLedger = $this->str($evidence['evidence_ledger_ref'] ?? $evidence['evidence_ledger'] ?? '');
        $ap750 = $this->str($evidence['ap750_result_bridge'] ?? $evidence['ap750'] ?? '');
        $replay = $this->str($evidence['replay_ref'] ?? $evidence['replay_packet'] ?? '');
        $programmingGovernance = (bool) ($evidence['programming_governance'] ?? $input['programming_governance'] ?? false);

        return [
            'decision_receipt_required' => true,
            'decision_receipt_id' => $decisionReceipt,
            'evidence_ledger_required' => true,
            'evidence_ledger_ref' => $evidenceLedger,
            'ap750_result_bridge_required' => true,
            'ap750_result_bridge' => $ap750,
            'replay_packet_required' => true,
            'replay_ref' => $replay,
            'programming_governance' => $programmingGovernance,
            'ok' => $decisionReceipt !== '' && $evidenceLedger !== '' && $ap750 !== '',
            'replay_ok' => $replay !== '',
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function mergeRequirements(array $input): array
    {
        $merge = is_array($input['merge'] ?? null) ? $input['merge'] : (is_array($input['merge_requirements'] ?? null) ? $input['merge_requirements'] : []);
        $governedBy = AreaFocusStringListNormalizer::trimmedUniqueStrings($merge['governed_by'] ?? ['AP-769', 'AP-774']);
        $hasBoth = in_array('AP-769', $governedBy, true) && in_array('AP-774', $governedBy, true);

        return [
            'governed_by' => $governedBy,
            'ff_only' => true,
            'no_hidden_merge' => true,
            'human_review_for_code_or_mixed' => true,
            'ok' => $hasBoth,
        ];
    }

    // ------------------------------------------------------------------
    // Summaries / helpers
    // ------------------------------------------------------------------

    /**
     * @param  array<string,mixed>  $finding
     * @return array<string,mixed>
     */
    private function findingSummary(array $finding): array
    {
        return [
            'finding_id' => $this->str($finding['finding_id'] ?? ''),
            'finding_hash' => $this->str($finding['finding_hash'] ?? ''),
            'title' => $this->str($finding['title'] ?? ''),
            'kind' => $this->str($finding['kind'] ?? ''),
            'severity' => $this->str($finding['severity'] ?? ''),
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function sandboxSummary(array $input): array
    {
        $sandbox = is_array($input['sandbox'] ?? null) ? $input['sandbox'] : [];
        $release = is_array($input['release'] ?? null) ? $input['release'] : [];

        return [
            'sandbox_id' => $this->str($sandbox['sandbox_id'] ?? ''),
            'branch_name' => $this->str($sandbox['branch_name'] ?? data_get($sandbox, 'materialization.branch_name', '')),
            'worktree_path' => $this->str($sandbox['worktree_path'] ?? data_get($sandbox, 'materialization.worktree_path', '')),
            'release_id' => $this->str($release['release_id'] ?? ''),
            'present' => $sandbox !== [] || $release !== [],
        ];
    }

    /**
     * @param  list<string>  $blockers
     * @return list<string>
     */
    private function nextActions(string $status, array $blockers): array
    {
        if ($status === self::STATUS_READY) {
            return [
                'Robust flow contract satisfied: route execution through the owner-flow chain AP-747 -> AP-750.',
                'Provider runs only through Atlas Dev/Forge owner authority, never a direct driver.',
            ];
        }

        return array_merge(
            ['Resolve the missing robust-flow capabilities before any provider execution; downgrade to diagnostic evidence otherwise.'],
            array_map(static fn (string $b): string => 'blocker: '.$b, $blockers),
        );
    }

    /**
     * @return array<string,bool>
     */
    private function claimPolicy(): array
    {
        return [
            'provider_invoked' => false,
            'mutates_repo' => false,
            'merge_performed' => false,
            'branch_created' => false,
            'worktree_created' => false,
            'deploy_performed' => false,
            'secret_access' => false,
            'destructive_change' => false,
            'read_only_evaluator' => true,
        ];
    }

    private function normalizeOwner(string $owner): string
    {
        $owner = strtolower(trim($owner));

        return match ($owner) {
            'atlas_forge', 'forge' => 'forge',
            'atlas_dev', 'dev' => 'atlas_dev',
            default => $owner !== '' ? $owner : 'atlas_dev',
        };
    }

    /**
     * @param  list<string>  $values
     * @return list<string>
     */
    private function refs(array $values): array
    {
        return AreaFocusStringListNormalizer::trimmedScalarValues($values);
    }

    private function str(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function identity(array $payload): array
    {
        $copy = $payload;
        unset($copy['generated_at'], $copy['contract_hash']);

        return $copy;
    }
}
