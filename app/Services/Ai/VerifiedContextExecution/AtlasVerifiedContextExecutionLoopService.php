<?php

declare(strict_types=1);

namespace App\Services\Ai\VerifiedContextExecution;

use App\Services\Ai\Aemor\AtlasAemorRuntimeService;
use App\Services\Ai\Context\AtlasContextCacheCompilerRuntimeService;
use App\Services\Ai\Context\AtlasTokenEconomyRuntimeService;
use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\RuntimeEfficiency\AtlasLocalVerificationEngineService;
use App\Services\Ai\RuntimeEfficiency\AtlasQualityPreservingEfficiencySystemService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;

final class AtlasVerifiedContextExecutionLoopService
{
    public const SHADOW_SCHEMA = 'atlas.verified_context_execution_loop.shadow.v1';

    public const CERTIFICATION_SCHEMA = 'atlas.verified_context_execution_loop.certification.v1';

    public const STAGE_SCHEMA = 'atlas.verified_context_execution_loop.stage.v1';

    public const REPAIR_STRATEGY_SCHEMA = 'atlas.verified_context_execution_loop.repair_strategy.v1';

    public const OUTCOME_CANDIDATE_SCHEMA = 'atlas.verified_context_execution_loop.outcome_candidate.v1';

    public const STATUS_READY = 'ready';

    public const STATUS_WATCH = 'watch';

    public const STATUS_BLOCKED = 'blocked';

    public function __construct(
        private readonly AtlasQualityPreservingEfficiencySystemService $efficiency,
        private readonly AtlasContextCacheCompilerRuntimeService $contextCache,
        private readonly AtlasTokenEconomyRuntimeService $tokenEconomy,
        private readonly AtlasLocalVerificationEngineService $localVerification,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function shadow(array $input = []): array
    {
        $flowId = (string) ($input['flow_id'] ?? 'atlas_dev');
        $domain = (string) ($input['domain'] ?? 'programming');
        $provider = (string) ($input['provider'] ?? 'gpt');
        $risk = (string) ($input['risk_level'] ?? 'medium');
        $workspace = (string) ($input['workspace'] ?? 'atlas');
        $resourcePolicy = $this->efficiency->resourcePolicy($input);
        $cache = $this->contextCache->warm([
            'flow_id' => $flowId,
            'provider' => $provider,
            'workspace' => $workspace,
            'previous_prefix_hash' => (string) ($input['previous_prefix_hash'] ?? ''),
            'expected_prefix_hash' => (string) ($input['expected_prefix_hash'] ?? ''),
            'cache_fresh' => (bool) ($input['cache_fresh'] ?? true),
            'cache_poisoned' => (bool) ($input['cache_poisoned'] ?? false),
            'changed_file_refs' => $this->stringList($input['changed_files'] ?? []),
            'evidence_refs' => $this->stringList($input['evidence_refs'] ?? []),
            'nodes' => $input['nodes'] ?? null,
        ]);
        $token = $this->tokenEconomy->optimize([
            'flow_id' => $flowId,
            'provider' => $provider,
            'risk_level' => $risk,
            'previous_compiled_hash' => (string) ($input['previous_compiled_hash'] ?? ''),
            'reuse_fresh' => (bool) ($input['reuse_fresh'] ?? true),
            'task_type' => (string) ($input['task_type'] ?? ''),
            'must_keep_coverage' => (float) ($input['must_keep_coverage'] ?? data_get($cache, 'quality_contract.must_keep_coverage', 1.0)),
            'segments' => $input['segments'] ?? null,
            'memory_available_bytes' => (int) data_get($resourcePolicy, 'snapshot.memory_available_bytes'),
            'repeated_tokens' => (int) ($input['repeated_tokens'] ?? 0),
        ]);
        $local = $this->localVerification->run([
            'flow_id' => $flowId,
            'risk_level' => $risk,
            'changed_files' => $this->stringList($input['changed_files'] ?? []),
            'allowed_files' => $this->stringList($input['allowed_files'] ?? []),
            'forbidden_files' => $this->stringList($input['forbidden_files'] ?? []),
            'code_graph' => is_array($input['code_graph'] ?? null) ? $input['code_graph'] : [],
            'command' => (string) ($input['command'] ?? ''),
            'exit_code' => $input['exit_code'] ?? null,
            'stderr' => (string) ($input['stderr'] ?? ''),
            'stdout' => (string) ($input['stdout'] ?? ''),
            'failing_test' => (string) ($input['failing_test'] ?? ''),
            'resource_policy' => $resourcePolicy,
        ]);
        $quality = $this->qualityContract($cache, $token, $local, $resourcePolicy);
        $repair = $this->repairStrategy($local, $quality);
        $outcomeCandidate = $this->outcomeCandidate($flowId, $domain, $cache, $token, $local, $repair, $input);
        $stages = $this->stages($cache, $token, $local, $quality, $repair, $outcomeCandidate);
        $status = $this->statusFrom($quality, $cache, $token, $local, $resourcePolicy);

        $payload = [
            'schema_version' => self::SHADOW_SCHEMA,
            'status' => $status,
            'generated_at' => CarbonImmutable::now()->toJSON(),
            'phase' => 'shadow',
            'flow_id' => $flowId,
            'domain' => $domain,
            'provider' => $provider,
            'risk_level' => $risk,
            'workspace' => $workspace,
            'stages' => $stages,
            'runtime_refs' => [
                'context_cache' => $this->runtimeRef(AtlasContextCacheCompilerRuntimeService::SCHEMA_VERSION, $cache['status'] ?? 'unknown', $cache['context_cache_hash'] ?? ''),
                'token_economy' => $this->runtimeRef(AtlasTokenEconomyRuntimeService::SCHEMA_VERSION, $token['status'] ?? 'unknown', $token['token_economy_hash'] ?? ''),
                'local_verification' => $this->runtimeRef(AtlasLocalVerificationEngineService::SCHEMA_VERSION, $local['status'] ?? 'unknown', $local['local_verification_hash'] ?? ''),
                'aemor' => [
                    'schema_version' => AtlasAemorRuntimeService::OUTCOME_SCHEMA,
                    'status' => 'candidate_only',
                    'hash' => $outcomeCandidate['candidate_hash'],
                ],
            ],
            'quality_contract' => $quality,
            'resource_policy' => $resourcePolicy,
            'repair_strategy' => $repair,
            'outcome_memory_candidate' => $outcomeCandidate,
            'metrics' => [
                'stage_count' => count($stages),
                'must_keep_coverage' => (float) $quality['must_keep_coverage'],
                'input_tokens_before' => (int) data_get($token, 'compression_receipt.input_tokens_before', 0),
                'input_tokens_after' => (int) data_get($token, 'compression_receipt.input_tokens_after', 0),
                'token_savings_estimate' => (int) data_get($token, 'compression_receipt.savings_estimate', 0),
                'local_verification_value' => (float) data_get($local, 'metrics.local_verification_value', 0.0),
                'failure_capsule_present' => data_get($local, 'failure_capsule') !== null,
                'selected_test_count' => count((array) data_get($local, 'test_impact.selected_tests', [])),
            ],
            'claim_policy' => $this->claimPolicy(),
        ];
        $payload['avcel_hash'] = MissionCanonicalHash::sha256(array_diff_key($payload, ['generated_at' => true]));

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function certify(array $input = []): array
    {
        $shadow = $this->shadow($input);
        $checks = [
            $this->check('canonical_doc_present', File::exists(base_path('docs/engineering-knowledge-base/atlas-verified-context-execution-loop.md')), [
                'path' => 'docs/engineering-knowledge-base/atlas-verified-context-execution-loop.md',
            ]),
            $this->check('service_present', File::exists(base_path('app/Services/Ai/VerifiedContextExecution/AtlasVerifiedContextExecutionLoopService.php')), [
                'path' => 'app/Services/Ai/VerifiedContextExecution/AtlasVerifiedContextExecutionLoopService.php',
            ]),
            $this->check('command_present', File::exists(base_path('app/Console/Commands/AtlasVerifiedContextExecutionLoopCommand.php')), [
                'command' => 'atlas:verified-context-execution',
            ]),
            $this->check('focused_tests_present', File::exists(base_path('tests/Feature/Ai/VerifiedContextExecution/AtlasVerifiedContextExecutionLoopServiceTest.php')), [
                'test' => 'tests/Feature/Ai/VerifiedContextExecution/AtlasVerifiedContextExecutionLoopServiceTest.php',
            ]),
            $this->check('eight_stage_loop_present', (int) data_get($shadow, 'metrics.stage_count') === 8, [
                'stage_count' => data_get($shadow, 'metrics.stage_count'),
            ]),
            $this->check('must_keep_coverage_full', (float) data_get($shadow, 'quality_contract.must_keep_coverage') === 1.0, [
                'must_keep_coverage' => data_get($shadow, 'quality_contract.must_keep_coverage'),
            ]),
            $this->check('local_verification_connected', in_array((string) data_get($shadow, 'runtime_refs.local_verification.status'), ['ready', 'watch'], true), [
                'hash' => data_get($shadow, 'runtime_refs.local_verification.hash'),
            ]),
            $this->check('outcome_candidate_connected_to_aemor_schema', (string) data_get($shadow, 'outcome_memory_candidate.aemor_schema_ref') === AtlasAemorRuntimeService::OUTCOME_SCHEMA, [
                'candidate_hash' => data_get($shadow, 'outcome_memory_candidate.candidate_hash'),
            ]),
            $this->check('read_only_shadow_policy', (bool) data_get($shadow, 'claim_policy.providers_invoked') === false
                && (bool) data_get($shadow, 'claim_policy.commands_executed') === false
                && (bool) data_get($shadow, 'claim_policy.writes') === false, [
                    'claim_policy' => $shadow['claim_policy'] ?? [],
                ]),
            $this->check('rollback_and_no_quality_regression_declared', (bool) data_get($shadow, 'quality_contract.rollback_required') === true
                && (bool) data_get($shadow, 'quality_contract.quality_regression_allowed') === false
                && (bool) data_get($shadow, 'quality_contract.evidence_loss_allowed') === false, [
                    'quality_contract_hash' => data_get($shadow, 'quality_contract.quality_contract_hash'),
                ]),
        ];
        $failures = array_values(array_filter($checks, static fn (array $check): bool => ($check['status'] ?? null) === 'fail'));
        $payload = [
            'schema_version' => self::CERTIFICATION_SCHEMA,
            'status' => $failures === [] ? self::STATUS_READY : self::STATUS_BLOCKED,
            'generated_at' => CarbonImmutable::now()->toJSON(),
            'summary' => [
                'total' => count($checks),
                'pass' => count($checks) - count($failures),
                'fail' => count($failures),
            ],
            'checks' => $checks,
            'blockers' => $failures,
            'shadow_ref' => [
                'schema_version' => self::SHADOW_SCHEMA,
                'status' => $shadow['status'],
                'avcel_hash' => $shadow['avcel_hash'],
            ],
            'claim_policy' => $this->claimPolicy(),
        ];
        $payload['certification_hash'] = MissionCanonicalHash::sha256(array_diff_key($payload, ['generated_at' => true]));

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $cache
     * @param  array<string,mixed>  $token
     * @param  array<string,mixed>  $local
     * @param  array<string,mixed>  $resourcePolicy
     * @return array<string,mixed>
     */
    private function qualityContract(array $cache, array $token, array $local, array $resourcePolicy): array
    {
        $mustKeep = min(
            (float) data_get($cache, 'quality_contract.must_keep_coverage', 0.0),
            (float) data_get($token, 'quality_check.must_keep_coverage', 0.0),
        );
        $blockers = [];
        if ($mustKeep < 1.0) {
            $blockers[] = 'must_keep_coverage_below_one';
        }
        if ((string) data_get($cache, 'status') === self::STATUS_BLOCKED) {
            $blockers[] = 'context_cache_blocked';
        }
        if ((string) data_get($token, 'status') === self::STATUS_BLOCKED) {
            $blockers[] = 'token_economy_blocked';
        }
        if ((string) data_get($local, 'status') === self::STATUS_BLOCKED) {
            $blockers[] = 'local_verification_blocked';
        }
        if ((bool) data_get($resourcePolicy, 'operator_floor_respected') !== true) {
            $blockers[] = 'operator_resource_floor_not_respected';
        }

        $contract = [
            'must_keep_coverage' => $mustKeep,
            'quality_gate_status' => $blockers === [] ? 'passed' : 'blocked',
            'quality_regression_allowed' => false,
            'evidence_loss_allowed' => false,
            'provider_shortcut_allowed' => false,
            'critical_decision_by_cheap_model_allowed' => false,
            'rollback_required' => true,
            'operator_resource_floor_gb' => (float) data_get($resourcePolicy, 'operator_reserved_ram_gb', 3.0),
            'blockers' => $blockers,
        ];
        $contract['quality_contract_hash'] = MissionCanonicalHash::sha256($contract);

        return $contract;
    }

    /**
     * @param  array<string,mixed>  $local
     * @param  array<string,mixed>  $quality
     * @return array<string,mixed>
     */
    private function repairStrategy(array $local, array $quality): array
    {
        $capsule = data_get($local, 'failure_capsule');
        $qualityBlocked = (string) ($quality['quality_gate_status'] ?? 'blocked') === 'blocked';
        $strategy = match (true) {
            $qualityBlocked => 'stop_until_quality_contract_passes',
            is_array($capsule) && (bool) ($capsule['root_cause_present'] ?? false) => 'repair_with_failure_capsule',
            is_array($capsule) => 'ask_for_better_failure_evidence',
            default => 'no_repair_needed',
        };
        $payload = [
            'schema_version' => self::REPAIR_STRATEGY_SCHEMA,
            'strategy' => $strategy,
            'auto_repair_allowed' => $strategy === 'repair_with_failure_capsule',
            'requires_provider' => $strategy === 'repair_with_failure_capsule',
            'requires_human' => in_array($strategy, ['stop_until_quality_contract_passes', 'ask_for_better_failure_evidence'], true),
            'failure_signature' => is_array($capsule) ? (string) ($capsule['failure_signature'] ?? '') : null,
            'repair_context_policy' => [
                'send_failure_capsule_only' => true,
                'send_raw_log' => false,
                'send_full_context_again' => false,
            ],
        ];
        $payload['repair_strategy_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $cache
     * @param  array<string,mixed>  $token
     * @param  array<string,mixed>  $local
     * @param  array<string,mixed>  $repair
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function outcomeCandidate(string $flowId, string $domain, array $cache, array $token, array $local, array $repair, array $input): array
    {
        $evidenceRefs = array_values(array_filter([
            (string) ($cache['context_cache_hash'] ?? ''),
            (string) ($token['token_economy_hash'] ?? ''),
            (string) ($local['local_verification_hash'] ?? ''),
            (string) ($repair['repair_strategy_hash'] ?? ''),
            ...$this->stringList($input['evidence_refs'] ?? []),
        ]));
        $payload = [
            'schema_version' => self::OUTCOME_CANDIDATE_SCHEMA,
            'aemor_schema_ref' => AtlasAemorRuntimeService::OUTCOME_SCHEMA,
            'status' => (string) ($local['status'] ?? 'ready') === self::STATUS_BLOCKED ? 'blocked_candidate' : 'ready_candidate',
            'flow_id' => $flowId,
            'domain' => $domain,
            'outcome_type' => data_get($local, 'failure_capsule') === null ? 'pre_execution_verification' : 'repair_preparation',
            'summary' => 'AVCEL shadow candidate links context, cache, local verification, repair strategy and AEMOR learning.',
            'evidence_refs' => $evidenceRefs,
            'context_utility' => [
                'context_cache_hash' => (string) ($cache['context_cache_hash'] ?? ''),
                'token_economy_hash' => (string) ($token['token_economy_hash'] ?? ''),
                'must_keep_coverage' => (float) data_get($token, 'quality_check.must_keep_coverage', 0.0),
                'token_savings_estimate' => (int) data_get($token, 'compression_receipt.savings_estimate', 0),
            ],
            'patch_outcome' => [
                'commands_executed' => false,
                'selected_tests' => (array) data_get($local, 'test_impact.selected_tests', []),
                'failure_signature' => data_get($local, 'failure_capsule.failure_signature'),
            ],
            'persist_now' => false,
            'requires_real_execution_before_learning' => true,
        ];
        $payload['candidate_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $cache
     * @param  array<string,mixed>  $token
     * @param  array<string,mixed>  $local
     * @param  array<string,mixed>  $quality
     * @param  array<string,mixed>  $repair
     * @param  array<string,mixed>  $outcomeCandidate
     * @return list<array<string,mixed>>
     */
    private function stages(array $cache, array $token, array $local, array $quality, array $repair, array $outcomeCandidate): array
    {
        return [
            $this->stage('context_compile', (string) ($cache['status'] ?? 'unknown'), ['context_pack_hash' => data_get($cache, 'merkle_pack.context_pack_hash')]),
            $this->stage('must_keep_guard', (string) ($quality['quality_gate_status'] ?? 'blocked'), ['must_keep_coverage' => $quality['must_keep_coverage'] ?? 0.0]),
            $this->stage('cache_delta', (string) ($cache['status'] ?? 'unknown'), ['cache_status' => data_get($cache, 'warmup_receipt.cache_status'), 'delta_hash' => data_get($cache, 'delta_request.delta_hash')]),
            $this->stage('token_economy', (string) ($token['status'] ?? 'unknown'), ['savings_estimate' => data_get($token, 'compression_receipt.savings_estimate')]),
            $this->stage('local_verification', (string) ($local['status'] ?? 'unknown'), ['local_verification_hash' => $local['local_verification_hash'] ?? '']),
            $this->stage('failure_capsule', data_get($local, 'failure_capsule') === null ? 'ready' : 'watch', ['capsule_hash' => data_get($local, 'failure_capsule.capsule_hash')]),
            $this->stage('repair_strategy', (bool) ($repair['auto_repair_allowed'] ?? false) ? 'watch' : 'ready', ['strategy' => $repair['strategy'] ?? 'unknown']),
            $this->stage('outcome_memory_candidate', (string) ($outcomeCandidate['status'] ?? 'unknown'), ['candidate_hash' => $outcomeCandidate['candidate_hash'] ?? '']),
        ];
    }

    /**
     * @param  array<string,mixed>  $evidence
     * @return array<string,mixed>
     */
    private function stage(string $id, string $status, array $evidence): array
    {
        $payload = [
            'schema_version' => self::STAGE_SCHEMA,
            'id' => $id,
            'status' => $status,
            'evidence' => $evidence,
        ];
        $payload['stage_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $quality
     * @param  array<string,mixed>  $cache
     * @param  array<string,mixed>  $token
     * @param  array<string,mixed>  $local
     * @param  array<string,mixed>  $resourcePolicy
     */
    private function statusFrom(array $quality, array $cache, array $token, array $local, array $resourcePolicy): string
    {
        if ((string) ($quality['quality_gate_status'] ?? 'blocked') === 'blocked') {
            return self::STATUS_BLOCKED;
        }
        if ((string) ($cache['status'] ?? 'blocked') === self::STATUS_BLOCKED || (string) ($token['status'] ?? 'blocked') === self::STATUS_BLOCKED) {
            return self::STATUS_BLOCKED;
        }
        if ((string) ($local['status'] ?? 'ready') === self::STATUS_BLOCKED) {
            return self::STATUS_BLOCKED;
        }
        if (in_array((string) ($resourcePolicy['mode'] ?? 'normal'), ['battery', 'swap_pressure', 'light'], true)) {
            return self::STATUS_WATCH;
        }
        if ((string) ($local['status'] ?? 'ready') === self::STATUS_WATCH || data_get($local, 'failure_capsule') !== null) {
            return self::STATUS_WATCH;
        }

        return self::STATUS_READY;
    }

    /**
     * @return array<string,mixed>
     */
    private function runtimeRef(string $schema, mixed $status, mixed $hash): array
    {
        return [
            'schema_version' => $schema,
            'status' => (string) $status,
            'hash' => (string) $hash,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function claimPolicy(): array
    {
        return [
            'providers_invoked' => false,
            'commands_executed' => false,
            'writes' => false,
            'external_execution_performed' => false,
            'quality_regression_allowed' => false,
            'evidence_loss_allowed' => false,
            'rivals_compared' => false,
            'benchmark_claimed' => false,
            'auto_enforcement_enabled' => false,
        ];
    }

    /**
     * @param  array<string,mixed>  $evidence
     * @return array<string,mixed>
     */
    private function check(string $id, bool $ok, array $evidence = []): array
    {
        return [
            'id' => $id,
            'status' => $ok ? 'pass' : 'fail',
            'evidence' => $evidence,
        ];
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        return array_values(array_filter((array) $value, 'is_string'));
    }
}
