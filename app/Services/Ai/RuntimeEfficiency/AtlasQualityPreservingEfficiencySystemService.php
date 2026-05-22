<?php

declare(strict_types=1);

namespace App\Services\Ai\RuntimeEfficiency;

use App\Services\Ai\Context\AtlasCognitiveMemoryFabricService;
use App\Services\Ai\Context\AtlasContextCacheCompilerRuntimeService;
use App\Services\Ai\Context\AtlasContextCompilerRuntimeService;
use App\Services\Ai\Context\AtlasTokenEconomyRuntimeService;
use App\Services\Ai\Mission\MissionCanonicalHash;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;

final class AtlasQualityPreservingEfficiencySystemService
{
    public const CERTIFICATION_SCHEMA = 'atlas.quality_preserving_efficiency.certification.v1';

    public const SHADOW_SCHEMA = 'atlas.quality_preserving_efficiency.shadow.v1';

    public const RESOURCE_POLICY_SCHEMA = 'atlas.quality_preserving_efficiency.resource_policy.v1';

    public const STATUS_READY = 'ready';

    public const STATUS_WATCH = 'watch';

    public const STATUS_BLOCKED = 'blocked';

    private const GB = 1073741824;

    public function __construct(
        private readonly AtlasRuntimeEfficiencyGovernorService $governor,
        private readonly AtlasContextCacheCompilerRuntimeService $contextCache,
        private readonly AtlasContextCompilerRuntimeService $compiler,
        private readonly AtlasTokenEconomyRuntimeService $tokenEconomy,
        private readonly AtlasCognitiveMemoryFabricService $memoryFabric,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function certify(array $input = []): array
    {
        $shadow = $this->shadow($input + [
            'flow_id' => (string) ($input['flow_id'] ?? 'atlas_dev'),
            'domain' => (string) ($input['domain'] ?? 'programming'),
            'risk_level' => (string) ($input['risk_level'] ?? 'medium'),
        ]);
        $checks = [
            $this->check('canonical_doc_present', File::exists(base_path('docs/engineering-knowledge-base/atlas-quality-preserving-efficiency-system.md')), [
                'path' => 'docs/engineering-knowledge-base/atlas-quality-preserving-efficiency-system.md',
            ]),
            $this->check('areg_certification_surface_present', File::exists(base_path('app/Services/Ai/RuntimeEfficiency/AtlasRuntimeEfficiencyGovernorCertificationService.php')), [
                'path' => 'app/Services/Ai/RuntimeEfficiency/AtlasRuntimeEfficiencyGovernorCertificationService.php',
            ]),
            $this->check('context_cache_runtime_ready', (string) data_get($shadow, 'runtime_refs.context_cache.status') === 'ready', [
                'hash' => data_get($shadow, 'runtime_refs.context_cache.hash'),
                'cache_status' => data_get($shadow, 'runtime_refs.context_cache.cache_status'),
            ]),
            $this->check('context_compiler_runtime_ready', (string) data_get($shadow, 'runtime_refs.context_compiler.status') === 'ready', [
                'hash' => data_get($shadow, 'runtime_refs.context_compiler.hash'),
            ]),
            $this->check('token_economy_quality_gate_passed', (string) data_get($shadow, 'runtime_refs.token_economy.status') === 'ready'
                && (string) data_get($shadow, 'quality_contract.quality_gate_status') === 'passed', [
                    'quality_gate_status' => data_get($shadow, 'quality_contract.quality_gate_status'),
                ]),
            $this->check('must_keep_coverage_full', (float) data_get($shadow, 'quality_contract.must_keep_coverage') === 1.0, [
                'must_keep_coverage' => data_get($shadow, 'quality_contract.must_keep_coverage'),
            ]),
            $this->check('operator_resource_floor_respected', (bool) data_get($shadow, 'resource_policy.operator_floor_respected') === true, [
                'operator_reserved_ram_gb' => data_get($shadow, 'resource_policy.operator_reserved_ram_gb'),
                'mode' => data_get($shadow, 'resource_policy.mode'),
            ]),
            $this->check('no_provider_or_external_execution', (bool) data_get($shadow, 'claim_policy.providers_invoked') === false
                && (bool) data_get($shadow, 'claim_policy.external_execution_performed') === false, [
                    'claim_policy' => $shadow['claim_policy'] ?? [],
                ]),
            $this->check('rollback_required_declared', (bool) data_get($shadow, 'quality_contract.rollback_required') === true, [
                'rollback_ref' => data_get($shadow, 'quality_contract.rollback_ref'),
            ]),
            $this->check('aegis_commands_present', File::exists(base_path('app/Console/Commands/AtlasQualityPreservingEfficiencyCommand.php'))
                && File::exists(base_path('app/Console/Commands/AtlasContextCacheCompilerCommand.php'))
                && File::exists(base_path('app/Console/Commands/AtlasContextCompilerRuntimeCommand.php'))
                && File::exists(base_path('app/Console/Commands/AtlasCognitiveMemoryFabricCommand.php'))
                && File::exists(base_path('app/Console/Commands/AtlasTokenEconomyRuntimeCommand.php')), [
                    'commands' => [
                        'atlas:efficiency',
                        'atlas:context:cache-warm',
                        'atlas:context:compile',
                        'atlas:context:cognitive-memory',
                        'atlas:context:token-economy',
                    ],
                ]),
            $this->check('focused_tests_present', File::exists(base_path('tests/Feature/Ai/RuntimeEfficiency/AtlasQualityPreservingEfficiencySystemServiceTest.php'))
                && File::exists(base_path('tests/Feature/Ai/Context/ContextCompilerRuntimeTest.php'))
                && File::exists(base_path('tests/Feature/Ai/Context/CognitiveMemoryFabricTest.php'))
                && File::exists(base_path('tests/Feature/Ai/Context/TokenEconomyRuntimeTest.php')), [
                    'test' => 'tests/Feature/Ai/RuntimeEfficiency/AtlasQualityPreservingEfficiencySystemServiceTest.php',
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
                'shadow_hash' => $shadow['shadow_hash'],
            ],
            'claim_policy' => $this->claimPolicy(),
        ];
        $payload['certification_hash'] = MissionCanonicalHash::sha256(array_diff_key($payload, ['generated_at' => true]));

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function shadow(array $input = []): array
    {
        $flowId = (string) ($input['flow_id'] ?? 'atlas_dev');
        $domain = (string) ($input['domain'] ?? 'programming');
        $risk = (string) ($input['risk_level'] ?? 'medium');
        $provider = (string) ($input['provider'] ?? 'gpt');
        $prompt = trim((string) ($input['prompt'] ?? 'Run quality-preserving efficiency shadow evaluation.'));
        $resourcePolicy = $this->resourcePolicy($input);
        $memory = $this->memoryFabric->plan([
            'memory_total_bytes' => (int) data_get($resourcePolicy, 'snapshot.memory_total_bytes'),
            'memory_available_bytes' => (int) data_get($resourcePolicy, 'snapshot.memory_available_bytes'),
            'swap_used_bytes' => (int) data_get($resourcePolicy, 'snapshot.swap_used_bytes'),
            'cpu_load' => (float) data_get($resourcePolicy, 'snapshot.cpu_load'),
            'repeated_tokens' => (int) ($input['repeated_tokens'] ?? 0),
            'risk_level' => $risk,
        ]);
        $cache = $this->contextCache->warm([
            'flow_id' => $flowId,
            'provider' => $provider,
            'workspace' => (string) ($input['workspace'] ?? 'atlas'),
            'previous_prefix_hash' => (string) ($input['previous_prefix_hash'] ?? ''),
            'expected_prefix_hash' => (string) ($input['expected_prefix_hash'] ?? ''),
            'cache_fresh' => (bool) ($input['cache_fresh'] ?? true),
            'cache_poisoned' => (bool) ($input['cache_poisoned'] ?? false),
            'changed_file_refs' => (array) ($input['changed_file_refs'] ?? []),
            'evidence_refs' => (array) ($input['evidence_refs'] ?? []),
        ]);
        $compiler = $this->compiler->compile([
            'provider' => $provider,
            'risk_level' => $risk,
            'flow_id' => $flowId,
            'memory_available_bytes' => (int) data_get($resourcePolicy, 'snapshot.memory_available_bytes'),
            'repeated_tokens' => (int) ($input['repeated_tokens'] ?? 0),
            'segments' => $input['segments'] ?? null,
        ]);
        $token = $this->tokenEconomy->optimize([
            'provider' => $provider,
            'risk_level' => $risk,
            'flow_id' => $flowId,
            'previous_compiled_hash' => (string) ($input['previous_compiled_hash'] ?? ''),
            'reuse_fresh' => (bool) ($input['reuse_fresh'] ?? true),
            'task_type' => (string) ($input['task_type'] ?? ''),
            'must_keep_coverage' => (float) ($input['must_keep_coverage'] ?? 1.0),
        ]);
        $govern = $this->governor->govern([
            'prompt' => $prompt,
            'domain' => $domain,
            'flow_id' => $flowId,
            'evidence_refs' => (array) ($input['evidence_refs'] ?? ['shadow:aqpes']),
            'persist' => false,
        ]);
        $qualityContract = $this->qualityContract($compiler, $token, $resourcePolicy);
        $status = $this->statusFrom($qualityContract, $resourcePolicy, $cache, $compiler, $token, $memory);
        $payload = [
            'schema_version' => self::SHADOW_SCHEMA,
            'status' => $status,
            'generated_at' => CarbonImmutable::now()->toJSON(),
            'flow_id' => $flowId,
            'domain' => $domain,
            'risk_level' => $risk,
            'provider' => $provider,
            'phase' => 'shadow',
            'runtime_refs' => [
                'areg' => [
                    'schema_version' => AtlasRuntimeEfficiencyGovernorService::SCHEMA_VERSION,
                    'status' => $govern['status'] ?? 'unknown',
                    'hash' => $govern['decision_hash'] ?? '',
                    'path' => $govern['path'] ?? '',
                ],
                'context_cache' => [
                    'schema_version' => AtlasContextCacheCompilerRuntimeService::SCHEMA_VERSION,
                    'status' => $cache['status'] ?? 'unknown',
                    'hash' => $cache['context_cache_hash'] ?? '',
                    'cache_status' => data_get($cache, 'warmup_receipt.cache_status', 'unknown'),
                ],
                'context_compiler' => [
                    'schema_version' => AtlasContextCompilerRuntimeService::SCHEMA_VERSION,
                    'status' => $compiler['status'] ?? 'unknown',
                    'hash' => $compiler['context_compiler_hash'] ?? '',
                ],
                'token_economy' => [
                    'schema_version' => AtlasTokenEconomyRuntimeService::SCHEMA_VERSION,
                    'status' => $token['status'] ?? 'unknown',
                    'hash' => $token['token_economy_hash'] ?? '',
                ],
                'cognitive_memory' => [
                    'schema_version' => AtlasCognitiveMemoryFabricService::SCHEMA_VERSION,
                    'status' => $memory['status'] ?? 'unknown',
                    'hash' => $memory['cognitive_memory_hash'] ?? '',
                ],
            ],
            'quality_contract' => $qualityContract,
            'resource_policy' => $resourcePolicy,
            'metrics' => [
                'input_tokens_before' => (int) data_get($token, 'compression_receipt.input_tokens_before', 0),
                'input_tokens_after' => (int) data_get($token, 'compression_receipt.input_tokens_after', 0),
                'token_savings_estimate' => (int) data_get($token, 'compression_receipt.savings_estimate', 0),
                'context_loss_score' => (float) data_get($token, 'quality_check.loss_score', 1.0),
                'cache_hit_rate' => null,
                'local_verification_value' => null,
                'cpu_minutes_saved_vs_spent' => null,
            ],
            'claim_policy' => $this->claimPolicy(),
        ];
        $payload['shadow_hash'] = MissionCanonicalHash::sha256(array_diff_key($payload, ['generated_at' => true]));

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function resourcePolicy(array $input = []): array
    {
        $total = $this->bytes($input['memory_total_bytes'] ?? null, 48 * self::GB);
        $available = $this->bytes($input['memory_available_bytes'] ?? null, max(0, $total - memory_get_usage(true)));
        $swap = $this->bytes($input['swap_used_bytes'] ?? null, 0);
        $cpuLoad = max(0.0, min(1.0, (float) ($input['cpu_load'] ?? 0.20)));
        $battery = strtolower((string) ($input['power_state'] ?? 'plugged'));
        $floorGb = max(3.0, (float) ($input['operator_resource_floor_gb'] ?? 3.0));
        $availableGb = $available / self::GB;
        $swapGb = $swap / self::GB;
        $mode = match (true) {
            $battery === 'battery_low' => 'battery',
            $availableGb < $floorGb || $swapGb >= 8.0 => 'swap_pressure',
            $availableGb < $floorGb + 3.0 || $cpuLoad >= 0.85 => 'light',
            $availableGb < $floorGb + 9.0 || $swapGb >= 2.0 => 'normal',
            default => 'deep',
        };
        $requestedInput = (float) ($input['requested_ram_gb'] ?? 0.0);
        $requested = $requestedInput > 0.0 ? $requestedInput : match ($mode) {
            'deep' => 8.0,
            'normal' => 4.0,
            'light' => 1.0,
            default => 0.0,
        };
        $allocatableGb = max(0.0, $availableGb - $floorGb);
        $admittedRamGb = min($requested, $allocatableGb, match ($mode) {
            'deep' => 8.0,
            'normal' => 4.0,
            'light' => 1.0,
            default => 0.0,
        });
        $heavyAllowed = in_array($mode, ['normal', 'deep'], true) && $admittedRamGb > 0.0;
        $policy = [
            'schema_version' => self::RESOURCE_POLICY_SCHEMA,
            'mode' => $mode,
            'operator_reserved_ram_gb' => $floorGb,
            'operator_floor_respected' => $availableGb >= $floorGb && $admittedRamGb <= $allocatableGb,
            'requested_ram_gb' => round($requested, 2),
            'admitted_ram_gb' => round($admittedRamGb, 2),
            'heavy_jobs_allowed' => $heavyAllowed,
            'concurrency_limit' => match ($mode) {
                'deep' => 4,
                'normal' => 2,
                'light' => 1,
                default => 0,
            },
            'cpu_limit_pct' => match ($mode) {
                'deep' => 70,
                'normal' => 45,
                'light' => 20,
                default => 0,
            },
            'actions' => $this->resourceActions($mode, $heavyAllowed),
            'snapshot' => [
                'memory_total_bytes' => $total,
                'memory_available_bytes' => $available,
                'swap_used_bytes' => $swap,
                'cpu_load' => $cpuLoad,
                'power_state' => $battery,
            ],
        ];
        $policy['resource_policy_hash'] = MissionCanonicalHash::sha256($policy);

        return $policy;
    }

    /**
     * @return array<string,mixed>
     */
    public function claimPolicy(): array
    {
        return [
            'providers_invoked' => false,
            'external_execution_performed' => false,
            'writes' => false,
            'quality_regression_allowed' => false,
            'evidence_loss_allowed' => false,
            'auto_enforcement_enabled' => false,
            'benchmark_claimed' => false,
        ];
    }

    /**
     * @param  array<string,mixed>  $cache
     * @param  array<string,mixed>  $compiler
     * @param  array<string,mixed>  $token
     * @param  array<string,mixed>  $resourcePolicy
     * @return array<string,mixed>
     */
    private function qualityContract(array $compiler, array $token, array $resourcePolicy): array
    {
        $mustKeep = min(
            (float) data_get($compiler, 'loss_check.must_keep_coverage', 0.0),
            (float) data_get($token, 'quality_check.must_keep_coverage', 0.0),
        );
        $qualityGate = (string) data_get($token, 'quality_check.quality_gate_status', 'blocked');
        $blockers = [];
        if ($mustKeep < 1.0) {
            $blockers[] = 'must_keep_coverage_below_one';
        }
        if ($qualityGate !== 'passed') {
            $blockers[] = 'token_quality_gate_failed';
        }
        if ((bool) data_get($resourcePolicy, 'operator_floor_respected') !== true) {
            $blockers[] = 'operator_resource_floor_not_respected';
        }

        $contract = [
            'must_keep_coverage' => $mustKeep,
            'quality_gate_status' => $blockers === [] ? 'passed' : 'blocked',
            'quality_regression_allowed' => false,
            'evidence_loss_allowed' => false,
            'operator_resource_floor_gb' => (float) data_get($resourcePolicy, 'operator_reserved_ram_gb', 3.0),
            'rollback_required' => true,
            'rollback_ref' => 'baseline_path',
            'blockers' => $blockers,
        ];
        $contract['quality_contract_hash'] = MissionCanonicalHash::sha256($contract);

        return $contract;
    }

    /**
     * @param  array<string,mixed>  $quality
     * @param  array<string,mixed>  $resource
     * @param  array<string,mixed>  $compiler
     * @param  array<string,mixed>  $token
     * @param  array<string,mixed>  $memory
     */
    private function statusFrom(array $quality, array $resource, array $cache, array $compiler, array $token, array $memory): string
    {
        if ((string) ($quality['quality_gate_status'] ?? 'blocked') === 'blocked') {
            return self::STATUS_BLOCKED;
        }
        if ((string) ($cache['status'] ?? 'blocked') === 'blocked') {
            return self::STATUS_BLOCKED;
        }
        if ((string) ($compiler['status'] ?? 'blocked') === 'blocked' || (string) ($token['status'] ?? 'blocked') === 'blocked') {
            return self::STATUS_BLOCKED;
        }
        if ((string) ($memory['status'] ?? 'ready') === 'blocked') {
            return self::STATUS_BLOCKED;
        }
        if ((string) ($memory['status'] ?? 'ready') === 'degraded' || in_array((string) ($resource['mode'] ?? ''), ['battery', 'swap_pressure', 'light'], true)) {
            return self::STATUS_WATCH;
        }

        return self::STATUS_READY;
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
    private function resourceActions(string $mode, bool $heavyAllowed): array
    {
        if ($mode === 'battery') {
            return ['pause_heavy_jobs', 'allow_hash_and_small_diff_only'];
        }
        if ($mode === 'swap_pressure') {
            return ['suspend_alve_heavy', 'spill_rebuildable_context', 'preserve_operator_floor'];
        }
        if ($mode === 'light') {
            return ['allow_small_index', 'allow_targeted_hashing', 'defer_deep_tests'];
        }

        return $heavyAllowed
            ? ['allow_cache_warmup', 'allow_targeted_tests', 'allow_failure_capsules']
            : ['defer_heavy_jobs'];
    }

    private function bytes(mixed $value, int $fallback): int
    {
        if (is_numeric($value)) {
            return max(0, (int) $value);
        }

        return $fallback;
    }
}
