<?php

declare(strict_types=1);

namespace App\Services\Engineering;

use App\Services\Ai\Aemor\AtlasAemorRuntimeService;
use Throwable;

final class AtlasVerifiedEvolutionRuntimeService
{
    public const SCHEMA_VERSION = 'atlas.verified_evolution.v1';

    public const BOUNDARY_SCHEMA_VERSION = 'atlas.verified_evolution.boundary_contract.v1';

    public const PROOF_PLAN_SCHEMA_VERSION = 'atlas.verified_evolution.proof_plan.v1';

    public const QUALITY_SCHEMA_VERSION = 'atlas.verified_evolution.quality_score.v1';

    public const EXECUTION_CONTRACT_SCHEMA_VERSION = 'atlas.verified_evolution.execution_contract.v1';

    public const SCOPE_DRIFT_SCHEMA_VERSION = 'atlas.verified_evolution.scope_drift_watch.v1';

    public const PATCH_SIMULATION_SCHEMA_VERSION = 'atlas.verified_evolution.patch_simulation.v1';

    public const OUTCOME_BRIDGE_SCHEMA_VERSION = 'atlas.verified_evolution.outcome_bridge.v1';

    public function __construct(
        private readonly AtlasSoftwareTwinRuntimeService $softwareTwin,
        private readonly AtlasCodeRealityUsageIntelligenceService $codeReality,
        private readonly ?AtlasAemorRuntimeService $aemor = null,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function intentLock(string $objective, string $target = ''): array
    {
        $objective = trim($objective);
        $target = trim($target);
        $blockers = [];
        if ($objective === '') {
            $blockers[] = ['reason' => 'objective_required'];
        }

        return $this->envelope([
            'action' => 'intent-lock',
            'objective_hash' => $objective === '' ? null : hash('sha256', $objective),
            'objective_excerpt' => $this->excerpt($objective),
            'target' => $target,
            'intent_lock' => [
                'schema_version' => 'atlas.verified_evolution.intent_lock.v1',
                'locked' => $blockers === [],
                'requires_boundary_contract' => true,
                'requires_proof_plan' => true,
                'requires_aver_for_execution' => true,
                'requires_aemor_outcome_learning' => true,
            ],
            'blockers' => $blockers,
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    public function boundaryContract(string $objective, string $target): array
    {
        $intent = $this->intentLock($objective, $target);
        $impact = $this->softwareTwin->impact($target);
        $targetPath = $impact['target_path'] ?? null;
        $ownerDocs = (array) data_get($impact, 'impact.owner_docs', []);
        $requiredTests = (array) data_get($impact, 'impact.required_tests', []);
        $risk = (string) data_get($impact, 'impact.risk_level', 'high');
        $impactGraph = (array) data_get($impact, 'impact.impact_graphrag', []);
        $causalPaths = (array) data_get($impactGraph, 'causal_paths', []);
        $graphReadFirst = (array) data_get($impactGraph, 'selected_context.read_first', []);
        $blockers = (array) ($intent['blockers'] ?? []);
        if ($targetPath === null) {
            $blockers[] = ['reason' => 'target_not_found_for_boundary', 'target' => trim($target)];
        }
        if ($risk === 'high') {
            $blockers[] = ['reason' => 'high_risk_requires_human_or_more_evidence'];
        }

        return $this->envelope([
            'schema_version' => self::BOUNDARY_SCHEMA_VERSION,
            'action' => 'boundary-contract',
            'objective_hash' => $intent['objective_hash'] ?? null,
            'target' => trim($target),
            'boundary_contract' => [
                'schema_version' => self::BOUNDARY_SCHEMA_VERSION,
                'status' => $blockers === [] ? 'ready' : 'review_required',
                'allowed_write_paths' => $targetPath !== null ? [$targetPath] : [],
                'read_first' => EngineeringStringListNormalizer::uniqueNonEmptyStrings(array_merge([
                    'docs/engineering-knowledge-base/atlas-software-twin-verified-evolution-runtime.md',
                    'docs/engineering-knowledge-base/atlas-verified-execution-runtime.md',
                ], $graphReadFirst, $ownerDocs)),
                'do_not_touch_paths' => [
                    '.env',
                    'vendor/',
                    'node_modules/',
                    'storage/logs/',
                    'database/*.sqlite',
                    'unrelated_user_changes',
                ],
                'required_tests' => $requiredTests,
                'required_gates' => (array) data_get($impact, 'impact.required_gates', []),
                'risk_level' => $risk,
                'causal_context' => [
                    'schema_version' => data_get($impactGraph, 'schema_version'),
                    'status' => data_get($impactGraph, 'status', 'degraded'),
                    'confidence' => data_get($impactGraph, 'confidence', ['score' => 0, 'label' => 'none']),
                    'selection_policy' => data_get($impactGraph, 'selection_policy'),
                    'causal_paths' => $causalPaths,
                    'read_first' => $graphReadFirst,
                    'provider_safe' => (bool) data_get($impactGraph, 'provider_safe', true),
                    'bounded' => (bool) data_get($impactGraph, 'bounded', true),
                ],
                'mutation_policy' => [
                    'direct_mutation_authorized' => false,
                    'execution_must_go_through_aver' => true,
                    'delete_requires_acrui_deletion_preflight' => true,
                    'human_escalation_required_when_high_risk' => $risk === 'high',
                ],
            ],
            'impact' => [
                'classification' => $impact['classification'] ?? null,
                'reachability_confidence' => data_get($impact, 'impact.reachability_confidence'),
                'affected_edge_count' => count((array) data_get($impact, 'impact.affected_edges', [])),
            ],
            'blockers' => $blockers,
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    public function proofPlan(string $objective, string $target): array
    {
        $boundary = $this->boundaryContract($objective, $target);
        $contract = (array) ($boundary['boundary_contract'] ?? []);
        $tests = (array) ($contract['required_tests'] ?? []);
        $causalContext = (array) ($contract['causal_context'] ?? []);
        $causalPaths = (array) ($causalContext['causal_paths'] ?? []);
        $blockers = [];
        if (($boundary['status'] ?? null) === 'blocked') {
            $blockers = (array) ($boundary['blockers'] ?? []);
        }
        if ($tests === []) {
            $blockers[] = ['reason' => 'no_required_tests_resolved'];
        }

        return $this->envelope([
            'schema_version' => self::PROOF_PLAN_SCHEMA_VERSION,
            'action' => 'proof-plan',
            'objective_hash' => $boundary['objective_hash'] ?? null,
            'target' => trim($target),
            'proof_plan' => [
                'schema_version' => self::PROOF_PLAN_SCHEMA_VERSION,
                'required_tests' => $tests,
                'required_gates' => EngineeringStringListNormalizer::uniqueNonEmptyStrings(array_merge(
                    (array) ($contract['required_gates'] ?? []),
                    [
                        'git diff --check',
                        'php artisan atlas:engineering:knowledge docs-health --json',
                        'php artisan atlas:software-twin quality-score --json',
                        'php artisan atlas:verified-evolution quality-score --target="'.trim($target).'" --objective="<objective>" --json',
                    ]
                )),
                'aver_bridge' => [
                    'required' => true,
                    'plan_command' => 'php artisan atlas:aver plan --objective="<objective>" --json',
                    'certify_command' => 'php artisan atlas:aver:certify --json --strict',
                ],
                'aemor_bridge' => [
                    'required' => true,
                    'outcome_learning_required' => true,
                ],
                'causal_verification' => [
                    'required' => true,
                    'graph_status' => (string) ($causalContext['status'] ?? 'degraded'),
                    'confidence' => $causalContext['confidence'] ?? ['score' => 0, 'label' => 'none'],
                    'causal_paths' => $causalPaths,
                    'read_first' => (array) ($causalContext['read_first'] ?? []),
                    'completion_requires_review_of' => [
                        'affected_entrypoints',
                        'owner_docs',
                        'required_tests',
                        'scope_drift',
                    ],
                ],
                'completion_requires' => [
                    'boundary_contract_reviewed',
                    'causal_paths_reviewed',
                    'allowed_write_paths_respected',
                    'tests_green_or_blocker_declared',
                    'docs_health_green',
                    'aver_certification_when_execution_happened',
                    'aemor_outcome_recorded_when_available',
                ],
            ],
            'boundary_status' => $contract['status'] ?? 'unknown',
            'blockers' => $blockers,
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    public function executionContract(string $objective, string $target): array
    {
        $boundary = $this->boundaryContract($objective, $target);
        $proof = $this->proofPlan($objective, $target);
        $contract = (array) ($boundary['boundary_contract'] ?? []);
        $proofPlan = (array) ($proof['proof_plan'] ?? []);
        $blockers = array_values(array_merge(
            (array) ($boundary['blockers'] ?? []),
            (array) ($proof['blockers'] ?? [])
        ));

        return $this->envelope([
            'schema_version' => self::EXECUTION_CONTRACT_SCHEMA_VERSION,
            'action' => 'execution-contract',
            'objective_hash' => $boundary['objective_hash'] ?? null,
            'target' => trim($target),
            'execution_contract' => [
                'schema_version' => self::EXECUTION_CONTRACT_SCHEMA_VERSION,
                'status' => $blockers === [] ? 'ready_for_aver_plan' : 'blocked_before_aver',
                'aver_plan_input' => [
                    'objective' => $this->excerpt($objective),
                    'domain' => 'programming',
                    'flow_id' => 'atlas_dev',
                    'surface_id' => 'atlas_ai',
                    'target' => trim($target),
                    'allowed_write_paths' => (array) ($contract['allowed_write_paths'] ?? []),
                    'read_first' => (array) ($contract['read_first'] ?? []),
                    'evidence_refs' => EngineeringStringListNormalizer::uniqueNonEmptyStrings(array_merge(
                        (array) ($contract['read_first'] ?? []),
                        (array) ($proofPlan['required_tests'] ?? []),
                        (array) ($proofPlan['required_gates'] ?? [])
                    )),
                    'verification_plan' => [
                        'required_tests' => (array) ($proofPlan['required_tests'] ?? []),
                        'required_gates' => (array) ($proofPlan['required_gates'] ?? []),
                        'causal_verification' => (array) ($proofPlan['causal_verification'] ?? []),
                    ],
                    'rollback_plan' => [
                        'policy' => 'stop_and_report_before_reverting_user_changes',
                        'requires_scope_drift_watch' => true,
                        'requires_git_diff_check' => true,
                    ],
                ],
                'aver_commands' => [
                    'plan' => 'php artisan atlas:aver plan --objective="<objective>" --json',
                    'certify' => 'php artisan atlas:aver:certify --json --strict',
                ],
                'pre_execution_guards' => [
                    'scope_drift_watch_required',
                    'boundary_contract_required',
                    'proof_plan_required',
                    'direct_mutation_not_authorized_by_this_contract',
                ],
            ],
            'blockers' => $blockers,
        ]);
    }

    /**
     * @param  array<int,string>  $changedFiles
     * @return array<string,mixed>
     */
    public function driftWatch(string $objective, string $target, array $changedFiles = []): array
    {
        $boundary = $this->boundaryContract($objective, $target);
        $contract = (array) ($boundary['boundary_contract'] ?? []);
        $allowed = (array) ($contract['allowed_write_paths'] ?? []);
        $changed = array_values(array_filter(array_map(
            static fn (mixed $path): string => trim((string) $path),
            $changedFiles
        )));
        $outside = array_values(array_filter(
            $changed,
            fn (string $path): bool => ! $this->pathAllowed($path, $allowed)
        ));
        $blockers = (array) ($boundary['blockers'] ?? []);
        foreach ($outside as $path) {
            $blockers[] = [
                'reason' => 'changed_file_outside_boundary',
                'path' => $path,
            ];
        }

        return $this->envelope([
            'schema_version' => self::SCOPE_DRIFT_SCHEMA_VERSION,
            'action' => 'drift-watch',
            'objective_hash' => $boundary['objective_hash'] ?? null,
            'target' => trim($target),
            'scope_drift_watch' => [
                'schema_version' => self::SCOPE_DRIFT_SCHEMA_VERSION,
                'status' => $blockers === [] ? 'ready' : 'blocked',
                'mode' => $changed === [] ? 'pre_execution' : 'changed_files',
                'allowed_write_paths' => $allowed,
                'changed_files' => $changed,
                'outside_boundary_files' => $outside,
                'requires_human_review' => $outside !== [],
            ],
            'blockers' => $blockers,
        ]);
    }

    /**
     * @param  array<int,string>  $changedFiles
     * @return array<string,mixed>
     */
    public function patchSimulation(string $objective, string $target, array $changedFiles = []): array
    {
        $boundary = $this->boundaryContract($objective, $target);
        $drift = $this->driftWatch($objective, $target, $changedFiles);
        $impact = $this->softwareTwin->impact($target);
        $risk = (string) data_get($impact, 'impact.risk_level', 'high');
        $outside = (array) data_get($drift, 'scope_drift_watch.outside_boundary_files', []);
        $blockers = array_values(array_merge(
            (array) ($boundary['blockers'] ?? []),
            (array) ($drift['blockers'] ?? [])
        ));

        return $this->envelope([
            'schema_version' => self::PATCH_SIMULATION_SCHEMA_VERSION,
            'action' => 'patch-simulation',
            'objective_hash' => $boundary['objective_hash'] ?? null,
            'target' => trim($target),
            'patch_simulation' => [
                'schema_version' => self::PATCH_SIMULATION_SCHEMA_VERSION,
                'status' => $blockers === [] ? 'ready' : 'blocked',
                'risk_level' => $risk,
                'predicted_blast_radius' => [
                    'affected_edge_count' => count((array) data_get($impact, 'impact.affected_edges', [])),
                    'required_test_count' => count((array) data_get($impact, 'impact.required_tests', [])),
                    'outside_boundary_count' => count($outside),
                ],
                'expected_safe_files' => (array) data_get($boundary, 'boundary_contract.allowed_write_paths', []),
                'changed_files' => EngineeringStringListNormalizer::uniqueNonEmptyStrings($changedFiles),
                'must_run' => EngineeringStringListNormalizer::uniqueNonEmptyStrings(array_merge(
                    (array) data_get($impact, 'impact.required_tests', []),
                    (array) data_get($impact, 'impact.required_gates', []),
                    ['git diff --check']
                )),
                'recommendation' => $blockers === [] ? 'proceed_to_aver_plan' : 'stop_before_patch',
            ],
            'blockers' => $blockers,
        ]);
    }

    /**
     * @param  array<int,string>  $evidenceRefs
     * @return array<string,mixed>
     */
    public function outcomeBridge(string $objective, string $target, string $status = 'succeeded', array $evidenceRefs = []): array
    {
        $evidenceRefs = array_values(array_filter(array_map(static fn (mixed $ref): string => trim((string) $ref), $evidenceRefs)));
        if ($evidenceRefs === []) {
            return $this->envelope([
                'schema_version' => self::OUTCOME_BRIDGE_SCHEMA_VERSION,
                'action' => 'outcome-bridge',
                'target' => trim($target),
                'writes' => false,
                'outcome_bridge' => [
                    'schema_version' => self::OUTCOME_BRIDGE_SCHEMA_VERSION,
                    'status' => 'blocked',
                    'writes' => false,
                    'reason' => 'missing_evidence_refs',
                ],
                'blockers' => [['reason' => 'missing_evidence_refs']],
            ]);
        }

        try {
            $runtime = $this->aemor ?? app(AtlasAemorRuntimeService::class);
            $episode = $runtime->openEpisode([
                'objective' => $objective,
                'scope_type' => 'software_twin_target',
                'scope_id' => trim($target),
                'domain' => 'programming',
                'flow_id' => 'atlas_dev',
                'source' => 'aveor_outcome_bridge',
                'evidence_refs' => $evidenceRefs,
            ]);
            $outcome = $runtime->closeOutcome([
                'episode_id' => $episode['episode_id'] ?? null,
                'status' => $status,
                'summary' => 'AVEOR outcome bridge closed with evidence.',
                'metrics' => [
                    'tests_passed' => $status === 'succeeded',
                    'attribution_reviewed' => true,
                ],
                'patch_outcome' => [
                    'target' => trim($target),
                    'verified_evolution_runtime' => true,
                ],
                'evidence_refs' => $evidenceRefs,
            ]);

            return $this->envelope([
                'schema_version' => self::OUTCOME_BRIDGE_SCHEMA_VERSION,
                'action' => 'outcome-bridge',
                'target' => trim($target),
                'writes' => (bool) ($episode['writes'] ?? false) && isset($outcome['outcome_id']),
                'outcome_bridge' => [
                    'schema_version' => self::OUTCOME_BRIDGE_SCHEMA_VERSION,
                    'status' => ($outcome['status'] ?? null) === 'succeeded' ? 'recorded' : 'blocked',
                    'episode_id' => $episode['episode_id'] ?? null,
                    'outcome_id' => $outcome['outcome_id'] ?? null,
                    'episode_hash' => $episode['episode_hash'] ?? null,
                    'outcome_hash' => $outcome['outcome_hash'] ?? null,
                    'writes' => (bool) ($episode['writes'] ?? false) && isset($outcome['outcome_id']),
                ],
                'blockers' => ($outcome['status'] ?? null) === 'succeeded' ? [] : [['reason' => 'aemor_outcome_not_succeeded']],
            ]);
        } catch (Throwable $exception) {
            return $this->envelope([
                'schema_version' => self::OUTCOME_BRIDGE_SCHEMA_VERSION,
                'action' => 'outcome-bridge',
                'target' => trim($target),
                'writes' => false,
                'outcome_bridge' => [
                    'schema_version' => self::OUTCOME_BRIDGE_SCHEMA_VERSION,
                    'status' => 'blocked',
                    'writes' => false,
                    'reason' => 'aemor_unavailable',
                    'message_hash' => hash('sha256', $exception->getMessage()),
                ],
                'blockers' => [['reason' => 'aemor_unavailable']],
            ]);
        }
    }

    /**
     * @return array<string,mixed>
     */
    public function qualityScore(string $objective = '', string $target = ''): array
    {
        $twin = $this->softwareTwin->qualityScore();
        $proof = $target !== '' || $objective !== '' ? $this->proofPlan($objective !== '' ? $objective : 'verified evolution quality score', $target !== '' ? $target : 'app/Services/Engineering/AtlasSoftwareTwinRuntimeService.php') : null;
        $proofReady = $proof !== null && ($proof['status'] ?? null) === 'ready';
        $twinReady = data_get($twin, 'quality_score.status') === 'ready';
        $score = (int) round(($twinReady ? 50 : 25) + ($proofReady ? 35 : 15) + 15);

        return $this->envelope([
            'schema_version' => self::QUALITY_SCHEMA_VERSION,
            'action' => 'quality-score',
            'quality_score' => [
                'schema_version' => self::QUALITY_SCHEMA_VERSION,
                'score' => min($score, 100),
                'status' => $score >= 85 ? 'ready' : 'review',
                'quality_floor' => 85,
                'software_twin_ready' => $twinReady,
                'proof_plan_ready' => $proofReady,
                'aver_bridge_required' => true,
                'aemor_bridge_required' => true,
            ],
            'blockers' => [],
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    public function evolutionEnvelope(string $objective, string $target): array
    {
        $intent = $this->intentLock($objective, $target);
        $boundary = $this->boundaryContract($objective, $target);
        $proof = $this->proofPlan($objective, $target);
        $quality = $this->qualityScore($objective, $target);
        $execution = $this->executionContract($objective, $target);
        $drift = $this->driftWatch($objective, $target);
        $simulation = $this->patchSimulation($objective, $target);
        $blockers = array_values(array_merge(
            (array) ($intent['blockers'] ?? []),
            (array) ($boundary['blockers'] ?? []),
            (array) ($proof['blockers'] ?? []),
            (array) ($execution['blockers'] ?? []),
            (array) ($drift['blockers'] ?? []),
            (array) ($simulation['blockers'] ?? [])
        ));

        return $this->envelope([
            'action' => 'evolution-envelope',
            'objective_hash' => $intent['objective_hash'] ?? null,
            'target' => trim($target),
            'intent_lock' => $intent['intent_lock'] ?? [],
            'boundary_contract' => $boundary['boundary_contract'] ?? [],
            'proof_plan' => $proof['proof_plan'] ?? [],
            'execution_contract' => $execution['execution_contract'] ?? [],
            'scope_drift_watch' => $drift['scope_drift_watch'] ?? [],
            'patch_simulation' => $simulation['patch_simulation'] ?? [],
            'quality_score' => $quality['quality_score'] ?? [],
            'blockers' => $blockers,
        ]);
    }

    private function excerpt(string $value): string
    {
        return mb_substr(preg_replace('/\s+/', ' ', trim($value)) ?: '', 0, 180);
    }

    /**
     * @param  array<int,string>  $allowed
     */
    private function pathAllowed(string $path, array $allowed): bool
    {
        foreach ($allowed as $allowedPath) {
            $allowedPath = trim((string) $allowedPath);
            if ($allowedPath !== '' && ($path === $allowedPath || str_starts_with($path, rtrim($allowedPath, '/').'/'))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function envelope(array $payload): array
    {
        $base = array_merge([
            'schema_version' => self::SCHEMA_VERSION,
            'status' => (($payload['blockers'] ?? []) === []) ? 'ready' : 'blocked',
            'writes' => false,
            'claim_policy' => [
                'read_only' => true,
                'providers_invoked' => false,
                'rivals_run' => false,
                'executes_commands' => false,
                'authorizes_mutation' => false,
                'requires_aver_for_execution' => true,
                'verified_evolution_complete_claimed' => false,
            ],
        ], $payload);
        $hashPayload = $base;
        unset($hashPayload['certification_hash']);
        $base['certification_hash'] = hash('sha256', json_encode($hashPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return $base;
    }
}
