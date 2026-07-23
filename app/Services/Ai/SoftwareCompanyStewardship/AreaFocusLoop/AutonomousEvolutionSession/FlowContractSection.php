<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AutonomousEvolutionSession;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\Ap786RobustForgeQualityContractService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusStringListNormalizer;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AutonomousEvolutionSessionService;
use App\Services\Ai\Mission\MissionCanonicalHash;

/**
 * Owner-flow contract section, extracted VERBATIM from
 * AutonomousEvolutionSessionService by the GOD-DEBULK split. Builds the AP-786
 * robust-flow capability contract, the flow-integrity gate (native owner chain
 * vs legacy direct-driver opt-in), the required test set and validation commands
 * per finding, and the acceptance criteria. Deterministic and provider-free;
 * required-AP/capability constants are referenced qualified on the parent.
 */
final class FlowContractSection
{
    public function __construct(
        private readonly AutonomousEvolutionSessionService $parent,
    ) {}

    /**
     * AP-786 must not silently degrade into "provider + Atlas prompt". Until
     * the native owner chain is wired for this session, direct driver execution
     * is a legacy diagnostic path that requires an explicit caller opt-in.
     *
     * @return array<string,mixed>
     */
    public function flowIntegrityGate(string $owner, bool $allowDirectProviderDriver): array
    {
        // The default AP-786 execute path now routes through the real owner
        // runtime chain (AP-747 -> AP-756 -> AP-757 -> AP-749 -> AP-758 ->
        // AP-759 -> AP-750) via the Ap786OwnerFlowRunner. The direct provider
        // driver only runs when the caller explicitly opts into the legacy
        // diagnostic path.
        $usesFullOwnerRuntimeChain = ! $allowDirectProviderDriver;
        $directProviderDriverPath = $allowDirectProviderDriver;
        $ok = $usesFullOwnerRuntimeChain || $allowDirectProviderDriver;

        return [
            'schema_version' => 'atlas.software_company_stewardship.ap786_flow_integrity_gate.v1',
            'ok' => $ok,
            'owner' => $owner,
            'uses_full_owner_runtime_chain' => $usesFullOwnerRuntimeChain,
            'direct_provider_driver_path' => $directProviderDriverPath,
            'direct_provider_driver_allowed' => $allowDirectProviderDriver,
            'blocked_reason' => $ok ? null : 'full_atlas_forge_flow_required',
            'required_chain' => AutonomousEvolutionSessionService::REQUIRED_FULL_OWNER_FLOW_APS,
            'required_robust_flow_capabilities' => AutonomousEvolutionSessionService::REQUIRED_ROBUST_FLOW_CAPABILITIES,
            'robust_flow_contract' => [
                'schema' => Ap786RobustForgeQualityContractService::CONTRACT_SCHEMA,
                'service' => Ap786RobustForgeQualityContractService::class,
                'evaluates' => 'per-finding capability ok/missing/evidence_refs (ready|blocked) before provider execution',
            ],
            'forbidden_claim' => 'Do not claim full Atlas Forge or Atlas Dev execution when AP-786 is only invoking a provider driver with an Atlas-shaped prompt.',
            'next_action' => $directProviderDriverPath
                ? 'legacy_direct_provider_driver_path_explicitly_allowed'
                : 'execute through the native Atlas owner runtime chain (AP-747 -> AP-756 -> AP-757 -> AP-749 -> AP-758 -> AP-759 -> AP-750) via Ap786OwnerFlowRunner before any merge.',
        ];
    }

    /**
     * Enforce the robust Forge quality contract on the default owner-flow path.
     * This is intentionally evaluated before sandbox/provider/owner execution so
     * AP-786 cannot spend a cycle without SDD/TDD/BDD, workcell, repair,
     * evidence/replay and merge-governance proof.
     *
     * @param  array<string,mixed>  $finding
     * @param  list<string>  $allowedFiles
     * @param  list<string>  $validationCommands
     * @return array<string,mixed>
     */
    public function robustFlowContract(string $areaId, string $focus, array $finding, array $allowedFiles, string $owner, array $validationCommands): array
    {
        $testsRequired = $this->testsRequiredForFinding($finding, $allowedFiles);
        $acceptance = $this->acceptanceForFinding($finding);
        $specId = (string) (data_get($finding, 'spec_seed.candidate_id') ?: ($finding['finding_id'] ?? ''));
        $decisionReceiptId = 'AP-786:'.(string) ($finding['finding_id'] ?? substr(MissionCanonicalHash::sha256($finding), 0, 12));

        return $this->parent->robustContract->build([
            'area_id' => $areaId,
            'focus' => $focus,
            'owner' => $owner,
            'selected_finding' => $finding,
            'allowed_files' => $allowedFiles,
            'validation_commands' => $validationCommands !== [] ? $validationCommands : ['git diff --check'],
            'sdd_packet' => [
                'spec_id' => $specId,
                'objective' => (string) ($finding['why_it_matters'] ?? $finding['detail'] ?? $finding['title'] ?? ''),
                'scope' => (string) ($finding['title'] ?? 'AP-786 autonomous evolution work'),
                'acceptance' => $acceptance,
                'owner_docs' => AreaFocusStringListNormalizer::preserveNonBlankStrings(data_get($finding, 'spec_seed.owner_doc_refs', [])),
            ],
            'tdd_contract' => [
                'tests_required' => $testsRequired,
                'focused_test' => $testsRequired[0] ?? '',
                'test_first' => $testsRequired !== [],
            ],
            'bdd_contract' => [
                'behavior_acceptance' => $acceptance,
                'operator_visible_outcome' => (string) ($finding['why_it_matters'] ?? $finding['proposed_next_action'] ?? ''),
            ],
            'provider_topology' => [
                'source' => 'atlas_decide',
                'chosen_by_atlas_decide' => true,
                'owner_runtime_authority' => 'AP-759',
                'target_owner' => $owner,
            ],
            'workcell' => [
                'context_scout' => 'AP-748 deep finding scan',
                'architect' => 'Self-Directed Evolution spec seed / SDD packet',
                'implementer' => 'AP-759 owner runtime command',
                'reviewer' => 'AP-750 owner runtime result bridge',
                'repair_agent' => 'Atlas Dev Senior Loop failure capsule',
                'certifier' => 'AP-786/AP-769/AP-774 certification gates',
            ],
            'repair_policy' => [
                'max_attempts' => 2,
                'failed_gate_capsule_schema' => 'atlas.software_company_stewardship.ap786_failed_gate_capsule.v1',
                'stop_conditions' => ['validation_still_failing', 'diff_outside_allowed_files', 'no_progress_between_attempts'],
            ],
            'evidence' => [
                'decision_receipt_id' => $decisionReceiptId,
                'evidence_ledger_ref' => 'AP-750:owner_runtime_result_bridge',
                'ap750_result_bridge' => 'required_before_merge',
                'replay_ref' => 'AP-786:autonomous_evolution_session_jsonl',
                'programming_governance' => true,
            ],
            'merge_requirements' => [
                'governed_by' => ['AP-769', 'AP-774'],
            ],
        ]);
    }

    /**
     * @param  array<string,mixed>  $finding
     * @param  list<string>  $allowedFiles
     * @return array<string,mixed>
     */
    public function diagnosticRobustFlowContractSkipped(array $finding, array $allowedFiles, string $owner): array
    {
        return [
            'schema_version' => Ap786RobustForgeQualityContractService::CONTRACT_SCHEMA,
            'ap_contract' => 'AP-786',
            'status' => 'diagnostic_skipped',
            'owner' => $owner,
            'selected_finding' => $this->parent->findingSummary($finding),
            'allowed_files' => $allowedFiles,
            'blockers' => ['legacy_direct_provider_driver_diagnostic_path'],
            'claim_policy' => [
                'counts_as_full_atlas_forge_execution' => false,
                'counts_as_robust_obra_forge_quality_flow' => false,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $finding
     * @param  list<string>  $allowedFiles
     * @return list<string>
     */
    public function testsRequiredForFinding(array $finding, array $allowedFiles): array
    {
        $tests = AreaFocusStringListNormalizer::preserveNonBlankStrings(data_get($finding, 'spec_seed.tests_required', []));
        foreach ($allowedFiles as $file) {
            if (str_starts_with($file, 'tests/') || str_ends_with($file, 'Test.php')) {
                $tests[] = $file;
            }
        }

        return AreaFocusStringListNormalizer::uniqueStringValues($tests);
    }

    /**
     * AP-786 is only useful when the owner runtime receives an executable proof
     * contract. A generic `git diff --check` lets providers truthfully return
     * no_patch_needed; thread the selected finding's focused tests into Atlas
     * Dev so the provider sees a concrete patch target and verification gate.
     *
     * @param  list<string>  $inputCommands
     * @param  array<string,mixed>  $finding
     * @param  list<string>  $allowedFiles
     * @return list<string>
     */
    public function ownerValidationCommands(array $inputCommands, array $finding, array $allowedFiles): array
    {
        $commands = AreaFocusStringListNormalizer::preserveNonBlankStrings(array_map(
            fn (mixed $command): string => is_string($command) ? $this->worktreeSafeValidationCommand($command) : '',
            $inputCommands,
        ));

        foreach ($this->testsRequiredForFinding($finding, $allowedFiles) as $test) {
            $test = trim($test);
            if ($test === '' || str_contains($test, "\n") || strlen($test) > 180) {
                continue;
            }
            if (str_starts_with($test, 'php artisan test ')) {
                $commands[] = $this->worktreeSafeValidationCommand($test);
            } elseif (str_starts_with($test, 'tests/') && str_ends_with($test, '.php')) {
                $commands[] = './vendor/bin/phpunit --configuration=phpunit.xml '.$test;
            }
        }

        if (! in_array('git diff --check', $commands, true)) {
            $commands[] = 'git diff --check';
        }

        return array_values(array_slice(array_unique($commands), 0, 4));
    }

    public function worktreeSafeValidationCommand(string $command): string
    {
        $command = trim($command);
        if (preg_match('/^php\s+artisan\s+test(?:\s+(.*))?$/', $command, $matches) === 1) {
            $args = trim((string) ($matches[1] ?? ''));

            return './vendor/bin/phpunit --configuration=phpunit.xml'.($args !== '' ? ' '.$args : '');
        }

        return $command;
    }

    /**
     * @param  array<string,mixed>  $finding
     * @return list<string>
     */
    public function acceptanceForFinding(array $finding): array
    {
        $acceptance = AreaFocusStringListNormalizer::preserveNonBlankStrings(data_get($finding, 'spec_seed.acceptance', []));
        if ($acceptance !== []) {
            return $acceptance;
        }

        $title = trim((string) ($finding['title'] ?? ''));
        $nextAction = trim((string) ($finding['proposed_next_action'] ?? ''));

        return array_values(array_filter([
            $title !== '' ? 'Given the selected AP-786 finding, the owner runtime implements: '.$title : '',
            $nextAction !== '' ? 'Operator can verify the result by the proposed next action: '.$nextAction : '',
        ], static fn (string $line): bool => $line !== ''));
    }
}
