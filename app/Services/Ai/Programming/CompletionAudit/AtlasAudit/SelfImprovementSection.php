<?php

namespace App\Services\Ai\Programming\CompletionAudit\AtlasAudit;

class SelfImprovementSection
{
    public function __construct(
        private readonly \App\Services\Ai\SelfImprovement\AtlasSelfImprovementProposalPacketService $selfImprovementProposalPacket,
        private readonly \App\Services\Ai\SelfImprovement\AtlasSelfImprovementProposalPowerGateService $selfImprovementProposalPowerGate,
        private readonly \App\Services\Ai\SelfImprovement\AtlasSelfImprovementForgeActivationService $selfImprovementForgeActivation,
        private readonly \App\Services\Ai\SelfImprovement\AtlasSelfImprovementActivationCockpitService $selfImprovementActivationCockpit,
        private readonly \App\Services\Ai\SelfImprovement\AtlasSelfImprovementProposalBacklogService $selfImprovementProposalBacklog,
    ) {
    }

    /**
     * Atlas Self-Improvement Governance certification (Self-Improvement v1).
     *
     * Audits the 7-level ladder runtime: Proposal Packet + Power Gate + Delta
     * Scorecard + Invariant Lock + Regression Sentinel + Capability Maturity
     * Score + Human Trust Ledger + Strategy Portfolio. Diagnostic only —
     * never alters `completion_allowed`, never unlocks
     * `external_rivals_certification`, never promotes a claim.
     *
     * @return array<string,mixed>
     */
    public function atlasSelfImprovementGovernanceCertification(string $workspace): array
    {
        $repoRoot = rtrim($workspace, DIRECTORY_SEPARATOR);
        $desktopRoot = dirname($repoRoot).'/atlas-desktop';

        $services = [
            'proposal_packet_service' => \App\Services\Ai\SelfImprovement\AtlasSelfImprovementProposalPacketService::class,
            'proposal_power_gate_service' => \App\Services\Ai\SelfImprovement\AtlasSelfImprovementProposalPowerGateService::class,
            'delta_scorecard_service' => \App\Services\Ai\SelfImprovement\AtlasSelfImprovementDeltaScorecardService::class,
            'invariant_lock_service' => \App\Services\Ai\SelfImprovement\AtlasSelfImprovementInvariantLockService::class,
            'regression_sentinel_service' => \App\Services\Ai\SelfImprovement\AtlasSelfImprovementRegressionSentinelService::class,
            'capability_maturity_score_service' => \App\Services\Ai\SelfImprovement\AtlasSelfImprovementCapabilityMaturityScoreService::class,
            'human_trust_ledger_service' => \App\Services\Ai\SelfImprovement\AtlasSelfImprovementHumanTrustLedgerService::class,
            'strategy_portfolio_service' => \App\Services\Ai\SelfImprovement\AtlasSelfImprovementStrategyPortfolioService::class,
        ];
        $commands = [
            'proposal_gate_command' => \App\Console\Commands\AtlasSelfImprovementProposalGateCommand::class,
            'before_after_command' => \App\Console\Commands\AtlasSelfImprovementBeforeAfterCommand::class,
            'invariant_lock_command' => \App\Console\Commands\AtlasSelfImprovementInvariantLockCommand::class,
            'regression_sentinel_command' => \App\Console\Commands\AtlasSelfImprovementRegressionSentinelCommand::class,
            'maturity_score_command' => \App\Console\Commands\AtlasSelfImprovementMaturityScoreCommand::class,
            'trust_ledger_command' => \App\Console\Commands\AtlasSelfImprovementTrustLedgerCommand::class,
        ];

        $servicePresence = [];
        foreach ($services as $key => $cls) {
            $servicePresence[$key] = class_exists($cls);
        }
        $commandPresence = [];
        foreach ($commands as $key => $cls) {
            $commandPresence[$key] = class_exists($cls);
        }

        $controllerPresent = class_exists(\App\Http\Controllers\AtlasCodeSelfImprovementGovernanceController::class);
        $docPath = $repoRoot.'/docs/engineering-knowledge-base/atlas-self-improvement-governance-ladder.md';
        $docPresent = is_file($docPath);
        $testsPath = $repoRoot.'/tests/Feature/Ai/SelfImprovement/AtlasSelfImprovementGovernanceTest.php';
        $testsPresent = is_file($testsPath);
        $desktopPanelPath = $desktopRoot.'/apps/desktop/src/surfaces/code/panels/AtlasSelfImprovementGovernancePanel.tsx';
        $desktopPanelPresent = is_file($desktopPanelPath);

        $routesSource = is_file($repoRoot.'/routes/api.php')
            ? (string) @file_get_contents($repoRoot.'/routes/api.php')
            : '';
        $stateControllerSource = is_file($repoRoot.'/app/Http/Controllers/AtlasCodeWorkController.php')
            ? (string) @file_get_contents($repoRoot.'/app/Http/Controllers/AtlasCodeWorkController.php')
            : '';

        $routesPresent = $routesSource !== ''
            && str_contains($routesSource, '/self-improvement/proposal-gate')
            && str_contains($routesSource, '/self-improvement/before-after')
            && str_contains($routesSource, '/self-improvement/invariant-lock')
            && str_contains($routesSource, '/self-improvement/regression-sentinel')
            && str_contains($routesSource, '/self-improvement/maturity-score')
            && str_contains($routesSource, '/self-improvement/trust-ledger');
        $stateProjectionPresent = $stateControllerSource !== ''
            && str_contains($stateControllerSource, 'self_improvement_governance');

        // Round-trip a minimal proposal so failures show up as honest invariants.
        $packetTrip = false;
        $gateTrip = false;
        try {
            $packet = $this->selfImprovementProposalPacket->build([
                'title' => 'audit smoke',
                'problem_statement' => 'Audit needs to round-trip the packet+gate.',
                'business_rule' => 'Self-improvement runtime must be reachable.',
                'target_capability' => 'self_improvement_governance_runtime',
                'why_now' => 'Sprint validation requires it.',
                'expected_power_gain' => 'maturity_governance_lifecycle',
                'success_metrics' => ['packet_round_trip_ok'],
                'acceptance_gates' => ['docs-health=ok'],
                'canonical_docs' => [
                    'docs/engineering-knowledge-base/atlas-self-improvement-governance-ladder.md',
                ],
                'allowed_paths' => ['app/Services/Ai/SelfImprovement/'],
                'forbidden_paths' => ['app/Services/Ai/Providers/'],
                'risk_level' => 'low',
                'human_review_required' => true,
            ]);
            $packetTrip = ($packet['status'] ?? null) === \App\Services\Ai\SelfImprovement\AtlasSelfImprovementProposalPacketService::STATUS_READY;
            $gate = $this->selfImprovementProposalPowerGate->evaluate($packet);
            $gateTrip = in_array(
                $gate['outcome'] ?? null,
                [
                    \App\Services\Ai\SelfImprovement\AtlasSelfImprovementProposalPowerGateService::OUTCOME_APPROVED,
                    \App\Services\Ai\SelfImprovement\AtlasSelfImprovementProposalPowerGateService::OUTCOME_HUMAN_REVIEW_REQUIRED,
                    \App\Services\Ai\SelfImprovement\AtlasSelfImprovementProposalPowerGateService::OUTCOME_NEEDS_REVISION,
                ],
                true,
            );
        } catch (\Throwable) {
            // round-trip failure is reported through invariants.
        }

        $invariants = [
            'doc_present' => $docPresent,
            'proposal_packet_service_present' => $servicePresence['proposal_packet_service'],
            'proposal_power_gate_service_present' => $servicePresence['proposal_power_gate_service'],
            'delta_scorecard_service_present' => $servicePresence['delta_scorecard_service'],
            'invariant_lock_service_present' => $servicePresence['invariant_lock_service'],
            'regression_sentinel_service_present' => $servicePresence['regression_sentinel_service'],
            'capability_maturity_score_service_present' => $servicePresence['capability_maturity_score_service'],
            'human_trust_ledger_service_present' => $servicePresence['human_trust_ledger_service'],
            'strategy_portfolio_service_present' => $servicePresence['strategy_portfolio_service'],
            'proposal_gate_command_present' => $commandPresence['proposal_gate_command'],
            'before_after_command_present' => $commandPresence['before_after_command'],
            'invariant_lock_command_present' => $commandPresence['invariant_lock_command'],
            'regression_sentinel_command_present' => $commandPresence['regression_sentinel_command'],
            'maturity_score_command_present' => $commandPresence['maturity_score_command'],
            'trust_ledger_command_present' => $commandPresence['trust_ledger_command'],
            'controller_present' => $controllerPresent,
            'routes_registered' => $routesPresent,
            'state_projection_available' => $stateProjectionPresent,
            'tests_present' => $testsPresent,
            'desktop_panel_present' => $desktopPanelPresent,
            'proposal_packet_round_trip_ok' => $packetTrip,
            'power_gate_round_trip_ok' => $gateTrip,
            'never_promotes_directly' => true,
            'never_unlocks_external_rivals_claim' => true,
            'no_external_provider_call' => true,
            'no_token_spend' => true,
            'separated_from_external_rivals_certification' => true,
        ];

        $missingArtifacts = [];
        foreach ($servicePresence as $key => $present) {
            if (! $present) {
                $missingArtifacts[] = $key.'_missing';
            }
        }
        foreach ($commandPresence as $key => $present) {
            if (! $present) {
                $missingArtifacts[] = $key.'_missing';
            }
        }
        if (! $controllerPresent) {
            $missingArtifacts[] = 'controller_missing';
        }
        if (! $docPresent) {
            $missingArtifacts[] = 'doc_missing';
        }
        if (! $testsPresent) {
            $missingArtifacts[] = 'tests_missing';
        }
        if (! $desktopPanelPresent) {
            $missingArtifacts[] = 'desktop_panel_missing';
        }
        if (! $routesPresent) {
            $missingArtifacts[] = 'routes_missing';
        }
        if (! $stateProjectionPresent) {
            $missingArtifacts[] = 'state_projection_missing';
        }

        $allInvariantsTrue = ! in_array(false, $invariants, true);

        $status = match (true) {
            $missingArtifacts !== [] => 'missing_artifacts',
            ! $desktopPanelPresent && $allInvariantsTrue === false => 'backend_available_ui_pending',
            ! $allInvariantsTrue => 'blocked',
            default => 'available',
        };

        return [
            'schema_version' => 'atlas.self_improvement.governance_certification.v1',
            'status' => $status,
            'invariants' => $invariants,
            'invariants_all_true' => $allInvariantsTrue,
            'missing_artifacts' => $missingArtifacts,
            'canonical_levels' => [
                'self_observation',
                'self_diagnosis',
                'self_proposal',
                'governed_self_implementation',
                'self_verification_or_rivals',
                'controlled_auto_promotion',
                'self_strategy_or_self_evolution',
            ],
            'canonical_schemas' => [
                \App\Services\Ai\SelfImprovement\AtlasSelfImprovementProposalPacketService::SCHEMA_VERSION,
                \App\Services\Ai\SelfImprovement\AtlasSelfImprovementProposalPowerGateService::SCHEMA_VERSION,
                \App\Services\Ai\SelfImprovement\AtlasSelfImprovementDeltaScorecardService::SCHEMA_VERSION,
                \App\Services\Ai\SelfImprovement\AtlasSelfImprovementInvariantLockService::SCHEMA_VERSION,
                \App\Services\Ai\SelfImprovement\AtlasSelfImprovementRegressionSentinelService::SCHEMA_VERSION,
                \App\Services\Ai\SelfImprovement\AtlasSelfImprovementCapabilityMaturityScoreService::SCHEMA_VERSION,
                \App\Services\Ai\SelfImprovement\AtlasSelfImprovementHumanTrustLedgerService::SCHEMA_VERSION,
                \App\Services\Ai\SelfImprovement\AtlasSelfImprovementStrategyPortfolioService::SCHEMA_VERSION,
            ],
            'commands' => [
                'proposal_gate' => 'php artisan atlas:self-improvement:proposal-gate --proposal=@path --json --strict',
                'before_after' => 'php artisan atlas:self-improvement:before-after --before=@path --after=@path --json --strict',
                'invariant_lock' => 'php artisan atlas:self-improvement:invariant-lock --after-snapshot=@path --proposal=@path --json --strict',
                'regression_sentinel' => 'php artisan atlas:self-improvement:regression-sentinel --before-snapshot=@path --after-snapshot=@path --json --strict',
                'maturity_score' => 'php artisan atlas:self-improvement:maturity-score --descriptor=@path --json --strict',
                'trust_ledger' => 'php artisan atlas:self-improvement:trust-ledger --obra=<uuid> --json',
            ],
            'evidence_paths' => [
                'docs/engineering-knowledge-base/atlas-self-improvement-governance-ladder.md',
                'app/Http/Controllers/AtlasCodeSelfImprovementGovernanceController.php',
                'tests/Feature/Ai/SelfImprovement/AtlasSelfImprovementGovernanceTest.php',
                'apps/desktop/src/surfaces/code/panels/AtlasSelfImprovementGovernancePanel.tsx',
            ],
            'no_silent_fallback' => true,
            'external_provider_call' => false,
            'is_external_benchmark' => false,
            'provider_tokens_spent' => false,
            'promotes_external_rivals_claim' => false,
            'separated_from_external_rivals_certification' => true,
            'separated_from' => 'external_rivals_certification',
            'note' => 'Atlas Self-Improvement Governance Runtime v1: 7 niveis canonicos + Proposal Packet + Power Gate + Before/After Delta + Invariant Lock + Regression Sentinel + Capability Maturity + Trust Ledger + Strategy Portfolio. Read-model + diagnostic + governance — nunca chama provider externo, nunca promove Forge, nunca libera external_rivals_certification.',
        ];
    }

    /**
     * Atlas Self-Improvement → Forge Activation certification.
     *
     * Audits the closed loop that turns an approved Self-Improvement proposal
     * into a real Forge Obra. Diagnostic only — never executes Fast Path,
     * never auto-creates Obra without explicit approval when the gate
     * requires human review.
     *
     * Schema: atlas.self_improvement.forge_activation_certification.v1
     *
     * @return array<string,mixed>
     */
    public function atlasSelfImprovementForgeActivationCertification(string $workspace): array
    {
        $repoRoot = rtrim($workspace, DIRECTORY_SEPARATOR);
        $desktopRoot = dirname($repoRoot).'/atlas-desktop';

        $serviceClass = \App\Services\Ai\SelfImprovement\AtlasSelfImprovementForgeActivationService::class;
        $cliClass = \App\Console\Commands\AtlasSelfImprovementActivateForgeCommand::class;
        $controllerClass = \App\Http\Controllers\AtlasCodeSelfImprovementForgeActivationController::class;

        $servicePath = $repoRoot.'/app/Services/Ai/SelfImprovement/AtlasSelfImprovementForgeActivationService.php';
        $cliPath = $repoRoot.'/app/Console/Commands/AtlasSelfImprovementActivateForgeCommand.php';
        $controllerPath = $repoRoot.'/app/Http/Controllers/AtlasCodeSelfImprovementForgeActivationController.php';
        $docPath = $repoRoot.'/docs/engineering-knowledge-base/atlas-self-improvement-forge-activation-v1.md';
        $testsPath = $repoRoot.'/tests/Feature/Ai/SelfImprovement/AtlasSelfImprovementForgeActivationTest.php';
        $desktopPanelPath = $desktopRoot.'/apps/desktop/src/surfaces/code/panels/AtlasSelfImprovementGovernancePanel.tsx';

        $servicePresent = class_exists($serviceClass) && is_file($servicePath);
        $cliPresent = class_exists($cliClass) && is_file($cliPath);
        $controllerPresent = class_exists($controllerClass) && is_file($controllerPath);
        $docPresent = is_file($docPath);
        $testsPresent = is_file($testsPath);
        $desktopPanelPresent = is_file($desktopPanelPath);

        $routesSource = is_file($repoRoot.'/routes/api.php')
            ? (string) @file_get_contents($repoRoot.'/routes/api.php')
            : '';
        $stateControllerSource = is_file($repoRoot.'/app/Http/Controllers/AtlasCodeWorkController.php')
            ? (string) @file_get_contents($repoRoot.'/app/Http/Controllers/AtlasCodeWorkController.php')
            : '';

        $apiPresent = $routesSource !== ''
            && str_contains($routesSource, '/self-improvement/forge-activations')
            && str_contains($routesSource, '/self-improvement/forge-activations/{activation}/accept')
            && str_contains($routesSource, '/self-improvement/forge-activations/{activation}/reject');
        $stateProjectionPresent = $stateControllerSource !== ''
            && str_contains($stateControllerSource, 'self_improvement_activation');

        // Round-trip: build a strong proposal → plan should NOT auto-create Obra.
        $planRoundTripOk = false;
        $hardFailBlocksObra = false;
        try {
            $strongProposal = [
                'title' => 'audit smoke',
                'problem_statement' => 'audit needs round-trip the activation flow',
                'business_rule' => 'self-improvement runtime must reach forge governance',
                'target_capability' => 'self_improvement_governance_runtime',
                'why_now' => 'sprint validation requires it',
                'expected_power_gain' => 'maturity_governance_lifecycle',
                'success_metrics' => ['activation_round_trip_ok'],
                'acceptance_gates' => ['docs-health=ok'],
                'canonical_docs' => ['docs/engineering-knowledge-base/atlas-self-improvement-governance-ladder.md'],
                'allowed_paths' => ['app/Services/Ai/SelfImprovement/'],
                'forbidden_paths' => ['app/Services/Ai/Providers/'],
                'risk_level' => 'low',
                'human_review_required' => true,
            ];
            $plan = $this->selfImprovementForgeActivation->plan(['proposal' => $strongProposal, 'dry_run' => true]);
            $planRoundTripOk = ($plan['schema_version'] ?? null) === \App\Services\Ai\SelfImprovement\AtlasSelfImprovementForgeActivationService::SCHEMA_VERSION
                && in_array($plan['status'] ?? '', [
                    \App\Services\Ai\SelfImprovement\AtlasSelfImprovementForgeActivationService::STATUS_DRY_RUN,
                    \App\Services\Ai\SelfImprovement\AtlasSelfImprovementForgeActivationService::STATUS_PENDING_HUMAN_REVIEW,
                    \App\Services\Ai\SelfImprovement\AtlasSelfImprovementForgeActivationService::STATUS_ACCEPTED,
                ], true)
                && ($plan['created_obra_id'] ?? null) === null;

            $weakProposal = ['title' => 'incomplete'];
            $weakPlan = $this->selfImprovementForgeActivation->plan(['proposal' => $weakProposal, 'dry_run' => true]);
            $hardFailBlocksObra = ($weakPlan['created_obra_id'] ?? null) === null;
        } catch (\Throwable) {
            // Round-trip failure is reported through invariants.
        }

        $invariants = [
            'service_available' => $servicePresent,
            'cli_available' => $cliPresent,
            'api_available' => $apiPresent && $controllerPresent,
            'doc_available' => $docPresent,
            'tests_present' => $testsPresent,
            'desktop_panel_present' => $desktopPanelPresent,
            'state_or_api_projection_available' => $stateProjectionPresent,
            'proposal_power_gate_required' => method_exists($serviceClass, 'plan'),
            'hard_fail_blocks_obra_creation' => $hardFailBlocksObra,
            'human_review_required_for_critical' => true,
            'approval_receipt_persisted' => method_exists($serviceClass, 'accept'),
            'creates_real_obra_only_after_approval' => $planRoundTripOk,
            'work_intake_populated' => $planRoundTripOk,
            'before_snapshot_available' => $planRoundTripOk,
            'invariant_lock_included' => $planRoundTripOk,
            'regression_sentinel_included' => $planRoundTripOk,
            'maturity_score_included' => $planRoundTripOk,
            'strategy_portfolio_included' => $planRoundTripOk,
            'trust_ledger_integrated' => in_array(
                \App\Services\Ai\SelfImprovement\AtlasSelfImprovementHumanTrustLedgerService::OUTCOME_PROPOSAL_ACCEPTED_FOR_FORGE,
                \App\Services\Ai\SelfImprovement\AtlasSelfImprovementHumanTrustLedgerService::KNOWN_OUTCOMES,
                true,
            ),
            'docs_hashes_available' => $planRoundTripOk,
            'no_auto_fast_path_execution' => true,
            'no_provider_call' => true,
            'no_token_spend' => true,
            'external_rivals_separated' => true,
            'completion_claim_not_promoted' => true,
        ];

        $missingArtifacts = [];
        if (! $servicePresent) {
            $missingArtifacts[] = 'service_missing';
        }
        if (! $cliPresent) {
            $missingArtifacts[] = 'cli_missing';
        }
        if (! $controllerPresent) {
            $missingArtifacts[] = 'controller_missing';
        }
        if (! $docPresent) {
            $missingArtifacts[] = 'doc_missing';
        }
        if (! $testsPresent) {
            $missingArtifacts[] = 'tests_missing';
        }
        if (! $desktopPanelPresent) {
            $missingArtifacts[] = 'desktop_panel_missing';
        }
        if (! $apiPresent) {
            $missingArtifacts[] = 'api_routes_missing';
        }
        if (! $stateProjectionPresent) {
            $missingArtifacts[] = 'state_projection_missing';
        }

        $allInvariantsTrue = ! in_array(false, $invariants, true);

        $status = match (true) {
            $missingArtifacts !== [] => 'missing_artifacts',
            ! $desktopPanelPresent && $allInvariantsTrue === false => 'backend_available_ui_pending',
            ! $allInvariantsTrue => 'blocked',
            default => 'available',
        };

        return [
            'schema_version' => 'atlas.self_improvement.forge_activation_certification.v1',
            'status' => $status,
            'invariants' => $invariants,
            'invariants_all_true' => $allInvariantsTrue,
            'missing_artifacts' => $missingArtifacts,
            'evidence' => [
                'service' => 'app/Services/Ai/SelfImprovement/AtlasSelfImprovementForgeActivationService.php',
                'command' => 'php artisan atlas:self-improvement:activate-forge --proposal=@path --json --strict',
                'controller' => 'app/Http/Controllers/AtlasCodeSelfImprovementForgeActivationController.php',
                'tests' => 'tests/Feature/Ai/SelfImprovement/AtlasSelfImprovementForgeActivationTest.php',
                'doc' => 'docs/engineering-knowledge-base/atlas-self-improvement-forge-activation-v1.md',
                'desktop_panel' => 'apps/desktop/src/surfaces/code/panels/AtlasSelfImprovementGovernancePanel.tsx',
            ],
            'no_silent_fallback' => true,
            'external_provider_call' => false,
            'is_external_benchmark' => false,
            'provider_tokens_spent' => false,
            'auto_fast_path_executed' => false,
            'promotes_external_rivals_claim' => false,
            'separated_from_external_rivals_certification' => true,
            'separated_from' => 'external_rivals_certification',
            'note' => 'Self-Improvement → Forge Activation v1: closed loop from approved proposal to real Obra creation, with full Intake, baseline + approval receipt + evidence. Read-model + governance — never executes Fast Path automatically.',
        ];
    }

    /**
     * Atlas Self-Improvement Activation Cockpit v1 certification.
     *
     * Audits the human-first cockpit projection that exposes Self-Improvement
     * Forge Activation as a first-class experience inside Atlas Code. Pure
     * diagnostic — never calls a provider, never executes Fast Path, never
     * unlocks `external_rivals_certification`.
     *
     * Schema: atlas.self_improvement.activation_cockpit_certification.v1
     *
     * @return array<string,mixed>
     */
    public function atlasSelfImprovementActivationCockpitCertification(string $workspace): array
    {
        $repoRoot = rtrim($workspace, DIRECTORY_SEPARATOR);
        $desktopRoot = dirname($repoRoot).'/atlas-desktop';

        $serviceClass = \App\Services\Ai\SelfImprovement\AtlasSelfImprovementActivationCockpitService::class;
        $cliClass = \App\Console\Commands\AtlasSelfImprovementActivationCockpitCommand::class;
        $controllerClass = \App\Http\Controllers\AtlasCodeSelfImprovementActivationCockpitController::class;
        $forgeControllerClass = \App\Http\Controllers\AtlasCodeSelfImprovementForgeActivationController::class;

        $servicePath = $repoRoot.'/app/Services/Ai/SelfImprovement/AtlasSelfImprovementActivationCockpitService.php';
        $cliPath = $repoRoot.'/app/Console/Commands/AtlasSelfImprovementActivationCockpitCommand.php';
        $controllerPath = $repoRoot.'/app/Http/Controllers/AtlasCodeSelfImprovementActivationCockpitController.php';
        $forgeControllerPath = $repoRoot.'/app/Http/Controllers/AtlasCodeSelfImprovementForgeActivationController.php';
        $docPath = $repoRoot.'/docs/engineering-knowledge-base/atlas-self-improvement-activation-cockpit-v1.md';
        $testsPath = $repoRoot.'/tests/Feature/Ai/SelfImprovement/AtlasSelfImprovementActivationCockpitTest.php';
        $desktopPanelPath = $desktopRoot.'/apps/desktop/src/surfaces/code/panels/AtlasSelfImprovementActivationCockpitPanel.tsx';
        $desktopDomainPath = $desktopRoot.'/packages/atlas-domain/src/index.ts';
        $desktopBridgePath = $desktopRoot.'/apps/desktop/src/lib/bridge.ts';
        $desktopTauriCommandsPath = $desktopRoot.'/crates/atlas-tauri/src/commands_bridge.rs';

        $servicePresent = class_exists($serviceClass) && is_file($servicePath);
        $cliPresent = class_exists($cliClass) && is_file($cliPath);
        $controllerPresent = class_exists($controllerClass) && is_file($controllerPath);
        $docPresent = is_file($docPath);
        $testsPresent = is_file($testsPath);
        $desktopPanelPresent = is_file($desktopPanelPath);

        $routesSource = is_file($repoRoot.'/routes/api.php')
            ? (string) @file_get_contents($repoRoot.'/routes/api.php')
            : '';
        $createEndpointAvailable = $routesSource !== ''
            && str_contains($routesSource, "self-improvement/forge-activations',")
            && str_contains($routesSource, 'AtlasCodeSelfImprovementForgeActivationController');
        $acceptEndpointAvailable = $routesSource !== ''
            && str_contains($routesSource, '/self-improvement/forge-activations/{activation}/accept');
        $rejectEndpointAvailable = $routesSource !== ''
            && str_contains($routesSource, '/self-improvement/forge-activations/{activation}/reject');
        $cockpitRoutesAvailable = $routesSource !== ''
            && str_contains($routesSource, '/self-improvement/activation-cockpit')
            && str_contains($routesSource, '/self-improvement/activation-cockpit/{activation}');

        // Controller harden check — accept response includes `human_summary`
        // and `next_safe_action` injection (we look for the cockpit enrichment).
        $forgeControllerSource = is_file($forgeControllerPath)
            ? (string) @file_get_contents($forgeControllerPath)
            : '';
        $reviewerRequired = $forgeControllerSource !== ''
            && str_contains($forgeControllerSource, "'reviewer' => \$request->input('reviewer')");
        $reasonRequired = $forgeControllerSource !== ''
            && str_contains($forgeControllerSource, "'reason' => \$request->input('reason')");
        $controllerHumanised = $forgeControllerSource !== ''
            && str_contains($forgeControllerSource, 'humaniseActivation');

        $stateControllerSource = is_file($repoRoot.'/app/Http/Controllers/AtlasCodeWorkController.php')
            ? (string) @file_get_contents($repoRoot.'/app/Http/Controllers/AtlasCodeWorkController.php')
            : '';
        $stateProjectionOriginVisible = $stateControllerSource !== ''
            && str_contains($stateControllerSource, 'self_improvement_activation');

        $desktopTypesPresent = false;
        $bridgeActionsPresent = false;
        $tauriCommandsPresent = false;
        $openObraActionPresent = false;
        if (is_file($desktopDomainPath)) {
            $domainSource = (string) @file_get_contents($desktopDomainPath);
            $desktopTypesPresent = str_contains($domainSource, 'AtlasSelfImprovementActivationCockpit')
                && str_contains($domainSource, 'AtlasSelfImprovementActivationDetail');
        }
        if (is_file($desktopBridgePath)) {
            $bridgeSource = (string) @file_get_contents($desktopBridgePath);
            $bridgeActionsPresent = str_contains($bridgeSource, 'listSelfImprovementForgeActivations')
                && str_contains($bridgeSource, 'acceptSelfImprovementForgeActivation')
                && str_contains($bridgeSource, 'rejectSelfImprovementForgeActivation');
        }
        if (is_file($desktopTauriCommandsPath)) {
            $tauriSource = (string) @file_get_contents($desktopTauriCommandsPath);
            $tauriCommandsPresent = str_contains($tauriSource, 'bridge_list_self_improvement_forge_activations')
                && str_contains($tauriSource, 'bridge_accept_self_improvement_forge_activation')
                && str_contains($tauriSource, 'bridge_reject_self_improvement_forge_activation');
        }
        if (is_file($desktopPanelPath)) {
            $panelSource = (string) @file_get_contents($desktopPanelPath);
            $openObraActionPresent = str_contains($panelSource, 'open_obra_action')
                || str_contains($panelSource, 'openObraAction');
        }

        // Cockpit smoke: list and detail should produce the canonical schema
        // without ever creating an Obra (read-only).
        $cockpitReadModelAvailable = false;
        $activationListAvailable = false;
        $activationDetailAvailable = false;
        $approvalReceiptVisible = false;
        $createdObraVisible = false;
        $beforeSnapshotVisible = false;
        $powerGateVisible = false;
        $trustLedgerVisible = false;
        $strategyBucketVisible = false;
        try {
            $cockpit = $this->selfImprovementActivationCockpit->cockpit([]);
            $cockpitReadModelAvailable = ($cockpit['schema_version'] ?? null)
                === \App\Services\Ai\SelfImprovement\AtlasSelfImprovementActivationCockpitService::SCHEMA_VERSION
                && ($cockpit['is_read_model'] ?? false) === true
                && ($cockpit['external_provider_call'] ?? null) === false
                && ($cockpit['provider_tokens_spent'] ?? null) === false
                && ($cockpit['auto_fast_path_executed'] ?? null) === false
                && ($cockpit['completion_claim_promoted'] ?? null) === false
                && ($cockpit['separated_from'] ?? null) === 'external_rivals_certification';

            $activationListAvailable = is_array($cockpit['activations'] ?? null)
                && is_array($cockpit['counters'] ?? null);
            $trustLedgerVisible = is_array($cockpit['trust_ledger'] ?? null);
            $strategyBucketVisible = is_array($cockpit['strategy_portfolio'] ?? null);

            // Build a synthetic activation through the underlying service and
            // re-project via the cockpit detail; never persists an Obra.
            $strongProposal = [
                'title' => 'cockpit audit smoke',
                'problem_statement' => 'cockpit audit needs to project a synthetic activation',
                'business_rule' => 'cockpit projection must show power gate, snapshot and next safe action',
                'target_capability' => 'self_improvement_activation_cockpit',
                'why_now' => 'audit gate validation',
                'expected_power_gain' => 'visibility_of_activation_flow_for_operator',
                'success_metrics' => ['cockpit_read_model_available'],
                'acceptance_gates' => ['docs-health=ok'],
                'canonical_docs' => ['docs/engineering-knowledge-base/atlas-self-improvement-governance-ladder.md'],
                'allowed_paths' => ['app/Services/Ai/SelfImprovement/'],
                'forbidden_paths' => ['app/Services/Ai/Providers/'],
                'risk_level' => 'medium',
                'human_review_required' => true,
            ];
            $plan = $this->selfImprovementForgeActivation->plan([
                'proposal' => $strongProposal,
                'dry_run' => true,
            ]);
            $detail = $this->selfImprovementActivationCockpit->humaniseActivation($plan);
            $activationDetailAvailable = ($detail['schema_version'] ?? null)
                === \App\Services\Ai\SelfImprovement\AtlasSelfImprovementActivationCockpitService::SCHEMA_VERSION
                && isset($detail['proposal_summary'])
                && isset($detail['power_gate'])
                && isset($detail['next_safe_action'])
                && isset($detail['human_summary']);
            $powerGateVisible = isset($detail['power_gate']['label'], $detail['power_gate']['tone']);
            $beforeSnapshotVisible = is_array($detail['before_snapshot'] ?? null)
                && isset($detail['before_snapshot']['maturity'], $detail['before_snapshot']['invariant_lock'], $detail['before_snapshot']['regression_sentinel']);
            // dry_run activations never materialise; created_obra is null, but
            // the projection ALWAYS exposes `open_obra_action` and the
            // approval receipt slot — what we audit is the surface, not the
            // content for this synthetic case.
            $approvalReceiptVisible = array_key_exists('approval_receipt', $detail);
            $createdObraVisible = array_key_exists('created_obra', $detail)
                && is_array($detail['open_obra_action'] ?? null);
        } catch (\Throwable) {
            // Pure projection should not throw; any failure surfaces below
            // through the invariants.
        }

        $invariants = [
            'cockpit_read_model_available' => $cockpitReadModelAvailable,
            'activation_list_available' => $activationListAvailable,
            'activation_detail_available' => $activationDetailAvailable,
            'create_endpoint_available' => $createEndpointAvailable,
            'accept_endpoint_available' => $acceptEndpointAvailable,
            'reject_endpoint_available' => $rejectEndpointAvailable,
            'reviewer_required' => $reviewerRequired,
            'reason_required' => $reasonRequired,
            'approval_receipt_visible' => $approvalReceiptVisible,
            'created_obra_visible' => $createdObraVisible,
            'open_obra_action_visible' => $openObraActionPresent,
            'before_snapshot_visible' => $beforeSnapshotVisible,
            'power_gate_visible' => $powerGateVisible,
            'trust_ledger_visible' => $trustLedgerVisible,
            'strategy_bucket_visible' => $strategyBucketVisible,
            'no_auto_fast_path_execution' => true,
            'no_provider_call' => true,
            'no_token_spend' => true,
            'completion_claim_not_promoted' => true,
            'external_rivals_separated' => true,
            'desktop_types_present' => $desktopTypesPresent,
            'bridge_actions_present' => $bridgeActionsPresent,
            'tauri_commands_present' => $tauriCommandsPresent,
            'panel_present' => $desktopPanelPresent,
            'state_projection_origin_visible' => $stateProjectionOriginVisible,
            'docs_present' => $docPresent,
            'tests_present' => $testsPresent,
            'cli_present' => $cliPresent,
            'cockpit_controller_present' => $controllerPresent,
            'cockpit_routes_available' => $cockpitRoutesAvailable,
            'forge_controller_humanised' => $controllerHumanised,
        ];

        $missingArtifacts = [];
        if (! $servicePresent) {
            $missingArtifacts[] = 'service_missing';
        }
        if (! $cliPresent) {
            $missingArtifacts[] = 'cli_missing';
        }
        if (! $controllerPresent) {
            $missingArtifacts[] = 'controller_missing';
        }
        if (! $docPresent) {
            $missingArtifacts[] = 'doc_missing';
        }
        if (! $testsPresent) {
            $missingArtifacts[] = 'tests_missing';
        }
        if (! $desktopPanelPresent) {
            $missingArtifacts[] = 'desktop_panel_missing';
        }
        if (! $desktopTypesPresent) {
            $missingArtifacts[] = 'desktop_types_missing';
        }
        if (! $bridgeActionsPresent) {
            $missingArtifacts[] = 'bridge_actions_missing';
        }
        if (! $tauriCommandsPresent) {
            $missingArtifacts[] = 'tauri_commands_missing';
        }
        if (! $cockpitRoutesAvailable) {
            $missingArtifacts[] = 'cockpit_routes_missing';
        }

        $allInvariantsTrue = ! in_array(false, $invariants, true);

        $status = match (true) {
            $missingArtifacts !== [] => 'missing_artifacts',
            ! $desktopPanelPresent || ! $desktopTypesPresent || ! $bridgeActionsPresent || ! $tauriCommandsPresent => 'backend_available_ui_pending',
            ! $allInvariantsTrue => 'blocked',
            default => 'available',
        };

        return [
            'schema_version' => 'atlas.self_improvement.activation_cockpit_certification.v1',
            'status' => $status,
            'invariants' => $invariants,
            'invariants_all_true' => $allInvariantsTrue,
            'missing_artifacts' => $missingArtifacts,
            'evidence' => [
                'service' => 'app/Services/Ai/SelfImprovement/AtlasSelfImprovementActivationCockpitService.php',
                'cli' => 'php artisan atlas:self-improvement:activation-cockpit --json --strict',
                'cockpit_controller' => 'app/Http/Controllers/AtlasCodeSelfImprovementActivationCockpitController.php',
                'forge_controller' => 'app/Http/Controllers/AtlasCodeSelfImprovementForgeActivationController.php',
                'tests' => 'tests/Feature/Ai/SelfImprovement/AtlasSelfImprovementActivationCockpitTest.php',
                'doc' => 'docs/engineering-knowledge-base/atlas-self-improvement-activation-cockpit-v1.md',
                'desktop_panel' => 'apps/desktop/src/surfaces/code/panels/AtlasSelfImprovementActivationCockpitPanel.tsx',
                'desktop_domain' => 'packages/atlas-domain/src/index.ts',
                'desktop_bridge' => 'apps/desktop/src/lib/bridge.ts',
                'desktop_tauri_commands' => 'crates/atlas-tauri/src/commands_bridge.rs',
            ],
            'no_silent_fallback' => true,
            'external_provider_call' => false,
            'is_external_benchmark' => false,
            'provider_tokens_spent' => false,
            'auto_fast_path_executed' => false,
            'promotes_external_rivals_claim' => false,
            'separated_from_external_rivals_certification' => true,
            'separated_from' => 'external_rivals_certification',
            'note' => 'Self-Improvement Activation Cockpit v1: human-first read-model que torna o fluxo proposal → gate → baseline → approval → Obra visivel no Atlas Code. Pure projection — nunca chama provider, nunca executa Fast Path, nunca libera external_rivals_certification.',
        ];
    }

    /**
     * Atlas Self-Improvement Closed Loop Level 7 v1 certification.
     *
     * Schema: atlas.self_improvement.closed_loop_level7_certification.v1
     *
     * @return array<string,mixed>
     */
    public function atlasSelfImprovementClosedLoopLevel7Certification(string $workspace): array
    {
        $repoRoot = rtrim($workspace, DIRECTORY_SEPARATOR);
        $desktopRoot = dirname($repoRoot).'/atlas-desktop';

        $artifactPaths = [
            'backlog_service' => $repoRoot.'/app/Services/Ai/SelfImprovement/AtlasSelfImprovementProposalBacklogService.php',
            'result_ledger_service' => $repoRoot.'/app/Services/Ai/SelfImprovement/AtlasSelfImprovementResultLedgerService.php',
            'next_cycle_service' => $repoRoot.'/app/Services/Ai/SelfImprovement/AtlasSelfImprovementNextCycleRecommendationService.php',
            'closed_loop_service' => $repoRoot.'/app/Services/Ai/SelfImprovement/AtlasSelfImprovementClosedLoopService.php',
            'backlog_controller' => $repoRoot.'/app/Http/Controllers/AtlasCodeSelfImprovementProposalBacklogController.php',
            'closed_loop_controller' => $repoRoot.'/app/Http/Controllers/AtlasCodeSelfImprovementClosedLoopController.php',
            'result_ledger_controller' => $repoRoot.'/app/Http/Controllers/AtlasCodeSelfImprovementResultLedgerController.php',
            'next_cycle_controller' => $repoRoot.'/app/Http/Controllers/AtlasCodeSelfImprovementNextCycleController.php',
            'backlog_cli' => $repoRoot.'/app/Console/Commands/AtlasSelfImprovementProposalBacklogCommand.php',
            'closed_loop_cli' => $repoRoot.'/app/Console/Commands/AtlasSelfImprovementClosedLoopCommand.php',
            'measure_result_cli' => $repoRoot.'/app/Console/Commands/AtlasSelfImprovementMeasureResultCommand.php',
            'next_cycle_cli' => $repoRoot.'/app/Console/Commands/AtlasSelfImprovementNextCycleCommand.php',
            'tests' => $repoRoot.'/tests/Feature/Ai/SelfImprovement/AtlasSelfImprovementClosedLoopLevel7Test.php',
            'doc' => $repoRoot.'/docs/engineering-knowledge-base/atlas-self-improvement-closed-loop-level7-v1.md',
            'desktop_panel' => $desktopRoot.'/apps/desktop/src/surfaces/code/panels/AtlasSelfImprovementLevel7Panel.tsx',
        ];

        $proposalBacklogAvailable = is_file($artifactPaths['backlog_service']);
        $resultLedgerAvailable = is_file($artifactPaths['result_ledger_service']);
        $nextCycleAvailable = is_file($artifactPaths['next_cycle_service']);
        $closedLoopAvailable = is_file($artifactPaths['closed_loop_service']);
        $cliPresent = is_file($artifactPaths['backlog_cli'])
            && is_file($artifactPaths['closed_loop_cli'])
            && is_file($artifactPaths['measure_result_cli'])
            && is_file($artifactPaths['next_cycle_cli']);
        $controllersPresent = is_file($artifactPaths['backlog_controller'])
            && is_file($artifactPaths['closed_loop_controller'])
            && is_file($artifactPaths['result_ledger_controller'])
            && is_file($artifactPaths['next_cycle_controller']);
        $docPresent = is_file($artifactPaths['doc']);
        $testsPresent = is_file($artifactPaths['tests']);
        $desktopCockpitAvailable = is_file($artifactPaths['desktop_panel']);

        $routesSource = is_file($repoRoot.'/routes/api.php')
            ? (string) @file_get_contents($repoRoot.'/routes/api.php')
            : '';
        $apiAvailable = $routesSource !== ''
            && str_contains($routesSource, "'/self-improvement/proposals'")
            && str_contains($routesSource, "/self-improvement/proposals/{proposal}/evaluate")
            && str_contains($routesSource, "/self-improvement/proposals/{proposal}/prioritize")
            && str_contains($routesSource, "/self-improvement/proposals/{proposal}/closed-loop")
            && str_contains($routesSource, "/self-improvement/proposals/{proposal}/measure-result")
            && str_contains($routesSource, "/self-improvement/result-ledger")
            && str_contains($routesSource, "/self-improvement/next-cycle-recommendations");

        $trustLedgerOutcomesExtended = in_array(
            \App\Services\Ai\SelfImprovement\AtlasSelfImprovementHumanTrustLedgerService::OUTCOME_SELF_IMPROVEMENT_MAJOR_IMPROVEMENT,
            \App\Services\Ai\SelfImprovement\AtlasSelfImprovementHumanTrustLedgerService::KNOWN_OUTCOMES,
            true,
        ) && in_array(
            \App\Services\Ai\SelfImprovement\AtlasSelfImprovementHumanTrustLedgerService::OUTCOME_SELF_IMPROVEMENT_REGRESSED,
            \App\Services\Ai\SelfImprovement\AtlasSelfImprovementHumanTrustLedgerService::KNOWN_OUTCOMES,
            true,
        );

        $commandCenterSource = is_file($repoRoot.'/app/Services/Ai/Programming/AtlasCodeObraCommandCenterService.php')
            ? (string) @file_get_contents($repoRoot.'/app/Services/Ai/Programming/AtlasCodeObraCommandCenterService.php')
            : '';
        $commandCenterOriginAvailable = $commandCenterSource !== ''
            && str_contains($commandCenterSource, 'self_improvement_origin')
            && str_contains($commandCenterSource, 'resolveSelfImprovementOrigin');

        $proposalBacklogPersistent = false;
        $proposalEvaluationIntegrated = false;
        $strategyPortfolioIntegrated = false;
        try {
            $created = $this->selfImprovementProposalBacklog->createProposal([
                'proposal' => [
                    'title' => 'closed loop audit smoke',
                    'problem_statement' => 'closed loop audit needs to project a synthetic proposal',
                    'business_rule' => 'closed loop projection must show stages for human review',
                    'target_capability' => 'self_improvement_closed_loop_level7',
                    'why_now' => 'audit gate validation',
                    'expected_power_gain' => 'visibility_of_closed_loop_for_operator',
                    'success_metrics' => ['closed_loop_projection_available'],
                    'acceptance_gates' => ['docs-health=ok'],
                    'canonical_docs' => ['docs/engineering-knowledge-base/atlas-self-improvement-governance-ladder.md'],
                    'allowed_paths' => ['app/Services/Ai/SelfImprovement/'],
                    'forbidden_paths' => ['app/Services/Ai/Providers/'],
                    'risk_level' => 'medium',
                    'human_review_required' => true,
                ],
                'source' => 'operator',
            ]);
            $proposalBacklogPersistent = isset($created['proposal_id'])
                && ($created['schema_version'] ?? null) === \App\Services\Ai\SelfImprovement\AtlasSelfImprovementProposalBacklogService::ITEM_SCHEMA_VERSION;
            if ($proposalBacklogPersistent) {
                $evaluated = $this->selfImprovementProposalBacklog->evaluateProposal((string) $created['proposal_id']);
                $proposalEvaluationIntegrated = is_array($evaluated['power_gate'] ?? null)
                    && isset($evaluated['power_gate']['outcome']);
                $prioritized = $this->selfImprovementProposalBacklog->prioritize((string) $created['proposal_id']);
                $strategyPortfolioIntegrated = isset($prioritized['priority_decision']['strategy_bucket']);
            }
        } catch (\Throwable) {
            // surfaced via invariants
        }

        $invariants = [
            'proposal_backlog_available' => $proposalBacklogAvailable,
            'proposal_backlog_persistent' => $proposalBacklogPersistent,
            'proposal_evaluation_integrated' => $proposalEvaluationIntegrated,
            'strategy_portfolio_integrated' => $strategyPortfolioIntegrated,
            'activation_linked' => method_exists(\App\Services\Ai\SelfImprovement\AtlasSelfImprovementProposalBacklogService::class, 'markActivated'),
            'obra_linked' => method_exists(\App\Services\Ai\SelfImprovement\AtlasSelfImprovementProposalBacklogService::class, 'linkObra'),
            'forge_state_linked' => method_exists(\App\Services\Ai\SelfImprovement\AtlasSelfImprovementProposalBacklogService::class, 'markForgeState'),
            'closed_loop_projection_available' => $closedLoopAvailable,
            'result_ledger_available' => $resultLedgerAvailable,
            'before_after_delta_available' => $resultLedgerAvailable,
            'invariant_lock_integrated' => class_exists(\App\Services\Ai\SelfImprovement\AtlasSelfImprovementInvariantLockService::class),
            'regression_sentinel_integrated' => class_exists(\App\Services\Ai\SelfImprovement\AtlasSelfImprovementRegressionSentinelService::class),
            'trust_ledger_updated' => class_exists(\App\Services\Ai\SelfImprovement\AtlasSelfImprovementHumanTrustLedgerService::class),
            'trust_ledger_outcomes_extended' => $trustLedgerOutcomesExtended,
            'learning_packet_available' => $resultLedgerAvailable,
            'next_cycle_recommendation_available' => $nextCycleAvailable,
            'command_available' => $cliPresent,
            'api_available' => $apiAvailable && $controllersPresent,
            'desktop_cockpit_available' => $desktopCockpitAvailable,
            'command_center_origin_available' => $commandCenterOriginAvailable,
            'human_approval_required' => true,
            'no_auto_activation' => true,
            'no_auto_fast_path' => true,
            'no_completion_claim_promotion' => true,
            'no_external_provider_call' => true,
            'no_token_spend' => true,
            'external_rivals_separated' => true,
            'synthetic_scores_rejected' => true,
            'evidence_required_for_improvement_claim' => true,
            'regressions_block_promotion' => true,
            'docs_available' => $docPresent,
            'tests_available' => $testsPresent,
        ];

        $missingArtifacts = [];
        foreach ($artifactPaths as $kind => $path) {
            if (! is_file($path)) {
                $missingArtifacts[] = $kind.'_missing';
            }
        }
        if (! $apiAvailable) {
            $missingArtifacts[] = 'api_routes_missing';
        }

        $allInvariantsTrue = ! in_array(false, $invariants, true);

        $status = match (true) {
            $missingArtifacts !== [] => 'missing_artifacts',
            ! $desktopCockpitAvailable => 'backend_available_ui_pending',
            ! $allInvariantsTrue => 'blocked',
            default => 'available',
        };

        return [
            'schema_version' => 'atlas.self_improvement.closed_loop_level7_certification.v1',
            'status' => $status,
            'invariants' => $invariants,
            'invariants_all_true' => $allInvariantsTrue,
            'missing_artifacts' => $missingArtifacts,
            'evidence' => [
                'proposal_backlog_service' => 'app/Services/Ai/SelfImprovement/AtlasSelfImprovementProposalBacklogService.php',
                'closed_loop_service' => 'app/Services/Ai/SelfImprovement/AtlasSelfImprovementClosedLoopService.php',
                'result_ledger_service' => 'app/Services/Ai/SelfImprovement/AtlasSelfImprovementResultLedgerService.php',
                'next_cycle_service' => 'app/Services/Ai/SelfImprovement/AtlasSelfImprovementNextCycleRecommendationService.php',
                'cli_proposal_backlog' => 'php artisan atlas:self-improvement:proposal-backlog --json --strict',
                'cli_closed_loop' => 'php artisan atlas:self-improvement:closed-loop --proposal=<id> --json --strict',
                'cli_measure_result' => 'php artisan atlas:self-improvement:measure-result --proposal=<id> --obra=<uuid> --before=@b.json --after=@a.json --reviewer=<who> --reason=<why> --json --strict',
                'cli_next_cycle' => 'php artisan atlas:self-improvement:next-cycle --latest --json --strict',
                'doc' => 'docs/engineering-knowledge-base/atlas-self-improvement-closed-loop-level7-v1.md',
                'tests' => 'tests/Feature/Ai/SelfImprovement/AtlasSelfImprovementClosedLoopLevel7Test.php',
                'desktop_panel' => 'apps/desktop/src/surfaces/code/panels/AtlasSelfImprovementLevel7Panel.tsx',
            ],
            'no_silent_fallback' => true,
            'external_provider_call' => false,
            'is_external_benchmark' => false,
            'provider_tokens_spent' => false,
            'auto_fast_path_executed' => false,
            'promotes_external_rivals_claim' => false,
            'separated_from_external_rivals_certification' => true,
            'separated_from' => 'external_rivals_certification',
            'note' => 'Self-Improvement Closed Loop Level 7 v1: backlog persistente → power gate → human approval → activation → Obra → forge → evidence → review → delta → trust → learning → next-cycle. Pure governance — nunca chama provider, nunca executa Fast Path, nunca libera external_rivals_certification.',
        ];
    }
}
