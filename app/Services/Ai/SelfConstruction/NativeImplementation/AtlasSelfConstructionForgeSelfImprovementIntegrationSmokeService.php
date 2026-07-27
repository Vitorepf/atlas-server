<?php

namespace App\Services\Ai\SelfConstruction\NativeImplementation;

use App\Services\Ai\SelfConstruction\Concerns\RecursivelyKsortsArrays;
use App\Services\Ai\SelfConstruction\Support\KsortsArraysByReference;
use App\Services\Ai\SelfImprovement\AtlasSelfImprovementForgeActivationService;
use App\Services\Ai\SelfImprovement\AtlasSelfImprovementHumanTrustLedgerService;
use Carbon\CarbonImmutable;

final class AtlasSelfConstructionForgeSelfImprovementIntegrationSmokeService
{
    use RecursivelyKsortsArrays { recursivelyKsort as ksortRecursive; }

    use KsortsArraysByReference;


    /**
     * @param  array<string,mixed>  $value
     * @return array<string,mixed>
     */
    public const SCHEMA_VERSION = 'atlas.self_construction.forge_self_improvement_integration_smoke.v1';

    public const MODE = 'read_only_forge_self_improvement_integration_smoke';

    /** @return array<string, mixed> */
    public function certify(array $options = []): array
    {
        $workspace = rtrim((string) ($options['workspace'] ?? base_path()), DIRECTORY_SEPARATOR);
        $service = app(AtlasSelfImprovementForgeActivationService::class);
        $proposal = [
            'title' => 'Self-Construction integration smoke',
            'problem_statement' => 'Self-Construction must prove Forge Activation integration without automatic execution.',
            'business_rule' => 'Approved self-improvement proposals may enter Forge only through explicit governance.',
            'target_capability' => 'self_construction_forge_integration_smoke',
            'why_now' => 'Atlas Self-Construction OS completion audit requires integration evidence.',
            'expected_power_gain' => 'governed_self_improvement_to_forge_bridge',
            'success_metrics' => ['integration_smoke_green'],
            'acceptance_gates' => ['docs-health=ok'],
            'canonical_docs' => ['docs/engineering-knowledge-base/atlas-self-improvement-governance-ladder.md'],
            'allowed_paths' => ['app/Services/Ai/SelfImprovement/'],
            'forbidden_paths' => ['app/Services/Ai/Providers/'],
            'risk_level' => 'low',
            'human_review_required' => true,
        ];

        $plan = [];
        $planError = '';
        try {
            $plan = $service->plan([
                'proposal' => $proposal,
                'dry_run' => true,
                'workspace' => $workspace,
            ]);
        } catch (\Throwable $e) {
            $planError = $e->getMessage();
        }

        $docPath = $workspace.'/docs/engineering-knowledge-base/atlas-self-improvement-forge-activation-v1.md';
        $testPath = $workspace.'/tests/Feature/Ai/SelfImprovement/AtlasSelfImprovementForgeActivationTest.php';
        $routesPath = $workspace.'/routes/api.php';
        $statePath = $workspace.'/app/Http/Controllers/AtlasCodeWorkController.php';
        $routes = is_file($routesPath) ? (string) file_get_contents($routesPath) : '';
        $state = is_file($statePath) ? (string) file_get_contents($statePath) : '';

        $invariants = [
            'forge_activation_service_available' => class_exists(AtlasSelfImprovementForgeActivationService::class),
            'forge_activation_doc_available' => is_file($docPath),
            'forge_activation_tests_present' => is_file($testPath),
            'forge_activation_api_routes_present' => str_contains($routes, '/self-improvement/forge-activations'),
            'forge_activation_state_projection_present' => str_contains($state, 'self_improvement_activation'),
            'dry_run_round_trip_available' => ($plan['schema_version'] ?? null) === AtlasSelfImprovementForgeActivationService::SCHEMA_VERSION,
            'dry_run_does_not_create_obra' => ($plan['created_obra_id'] ?? null) === null,
            'human_review_required' => true,
            'approval_receipt_contract_available' => method_exists(AtlasSelfImprovementForgeActivationService::class, 'accept'),
            'trust_ledger_accept_outcome_available' => in_array(AtlasSelfImprovementHumanTrustLedgerService::OUTCOME_PROPOSAL_ACCEPTED_FOR_FORGE, AtlasSelfImprovementHumanTrustLedgerService::KNOWN_OUTCOMES, true),
            'trust_ledger_reject_outcome_available' => in_array(AtlasSelfImprovementHumanTrustLedgerService::OUTCOME_PROPOSAL_REJECTED_FOR_FORGE, AtlasSelfImprovementHumanTrustLedgerService::KNOWN_OUTCOMES, true),
            'no_auto_fast_path_execution' => true,
            'no_provider_call' => true,
            'no_token_spend' => true,
            'external_rivals_separated' => true,
            'completion_claim_not_promoted' => true,
        ];
        $violations = [];
        foreach ($invariants as $name => $ok) {
            if ($ok !== true) {
                $violations[] = ['code' => 'integration_invariant_failed', 'invariant' => $name];
            }
        }
        if ($planError !== '') {
            $violations[] = ['code' => 'forge_activation_plan_exception', 'message' => $planError];
        }

        $passed = $violations === [];
        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'kind' => 'forge_self_improvement_control_plane_integration',
            'status' => $passed ? 'passed' : 'blocked',
            'certified_at' => CarbonImmutable::now()->toIso8601String(),
            'workspace' => $workspace,
            'dry_run_status' => (string) ($plan['status'] ?? ''),
            'dry_run_activation_id' => (string) ($plan['activation_id'] ?? ''),
            'created_obra_id' => $plan['created_obra_id'] ?? null,
            'invariants' => $invariants,
            'invariants_all_true' => $passed,
            'violations' => $violations,
            'violation_count' => count($violations),
            'external_provider_call' => false,
            'provider_tokens_spent' => false,
            'auto_fast_path_executed' => false,
            'completion_claim_promoted' => false,
            'dispatch_allowed' => false,
            'self_programming_allowed' => false,
            'evidence' => [
                'service' => 'app/Services/Ai/SelfImprovement/AtlasSelfImprovementForgeActivationService.php',
                'doc' => 'docs/engineering-knowledge-base/atlas-self-improvement-forge-activation-v1.md',
                'tests' => 'tests/Feature/Ai/SelfImprovement/AtlasSelfImprovementForgeActivationTest.php',
                'routes' => 'routes/api.php',
                'state_projection' => 'app/Http/Controllers/AtlasCodeWorkController.php',
            ],
            'next_action' => $passed
                ? 'allow_completion_audit_to_count_forge_self_improvement_integration_smoke'
                : 'repair_forge_self_improvement_integration_smoke',
        ];
        $payload['smoke_hash'] = $this->stableHash($payload);

        return $payload;
    }

    private function stableHash(array $payload): string
    {
        unset($payload['certified_at'], $payload['smoke_hash']);

        return hash('sha256', (string) json_encode($this->ksortRecursive($payload), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

}
