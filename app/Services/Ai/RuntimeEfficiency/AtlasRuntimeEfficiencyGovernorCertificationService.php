<?php

declare(strict_types=1);

namespace App\Services\Ai\RuntimeEfficiency;

use App\Services\Ai\Mission\MissionCanonicalHash;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Throwable;

final class AtlasRuntimeEfficiencyGovernorCertificationService
{
    public const SCHEMA_VERSION = 'atlas.runtime_efficiency_governor.certification.v1';

    public const STATUS_PASSED = 'passed';

    public const STATUS_BLOCKED = 'blocked';

    public function __construct(
        private readonly AtlasRuntimeEfficiencyGovernorService $runtime,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function certify(): array
    {
        $checks = [
            $this->canonicalDoc(),
            $this->macroNamingContract(),
            $this->persistenceSurface(),
            $this->runtimeSmoke(),
            $this->fastPathSmoke(),
            $this->forgePathSmoke(),
            $this->blockedPathSmoke(),
            $this->controlPlaneSanitizationSmoke(),
            $this->adaptivePolicySmoke(),
            $this->counterfactualReplaySmoke(),
            $this->selfOptimizationSmoke(),
            $this->integrationWiring(),
            $this->commandsPresent(),
            $this->testsPresent(),
            $this->claimPolicy(),
        ];

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => collect($checks)->contains(fn (array $check): bool => ($check['status'] ?? null) === 'fail') ? self::STATUS_BLOCKED : self::STATUS_PASSED,
            'generated_at' => CarbonImmutable::now()->toJSON(),
            'summary' => [
                'total' => count($checks),
                'pass' => collect($checks)->where('status', 'pass')->count(),
                'fail' => collect($checks)->where('status', 'fail')->count(),
            ],
            'checks' => $checks,
            'blockers' => array_values(array_filter($checks, static fn (array $check): bool => ($check['status'] ?? null) === 'fail')),
            'claim_policy' => $this->runtime->claimPolicy(),
            'scope' => [
                'covers' => 'AREG runtime budget decision, context minimum pack, layer admission, tool/provider budgets, outcomes, adaptive policies, counterfactual replay, control plane and Hyperflow integration.',
                'does_not_cover' => 'provider execution, external tool execution, benchmark/rivals or bypassing router/policy/evidence gates.',
            ],
        ];
        $payload['certification_hash'] = MissionCanonicalHash::sha256(array_diff_key($payload, ['generated_at' => true]));

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    private function canonicalDoc(): array
    {
        $path = base_path('docs/engineering-knowledge-base/atlas-runtime-efficiency-governor.md');
        $ok = $this->contains($path, 'Atlas Runtime Efficiency Governor')
            && $this->contains($path, 'Atlas Cognitive Budget Controller')
            && $this->contains($path, 'AtlasRuntimeEfficiencyGovernorService')
            && $this->contains($path, 'atlas.runtime_efficiency_governor.v1');

        return $this->check('canonical_doc', $ok, [$this->relative($path)], 'Repair AREG canonical doc.');
    }

    /**
     * @return array<string,mixed>
     */
    private function macroNamingContract(): array
    {
        $path = base_path('docs/engineering-knowledge-base/atlas-runtime-efficiency-governor.md');
        $ok = $this->contains($path, 'product_name: Atlas Runtime Efficiency Governor')
            && $this->contains($path, 'runtime_acronym: AREG')
            && $this->contains($path, 'internal_product_name: Atlas Cognitive Budget Controller')
            && $this->contains($path, 'technical_runtime: AtlasRuntimeEfficiencyGovernorService');

        return $this->check('macro_naming_contract', $ok, [$this->relative($path)], 'AREG must declare product/surface/acronym/runtime names.');
    }

    /**
     * @return array<string,mixed>
     */
    private function persistenceSurface(): array
    {
        $paths = [
            base_path('database/migrations/2026_05_20_170000_create_atlas_runtime_efficiency_tables.php'),
            base_path('app/Models/AtlasRuntimeEfficiencyDecision.php'),
            base_path('app/Models/AtlasRuntimeEfficiencyOutcome.php'),
            base_path('app/Models/AtlasRuntimeEfficiencyPolicy.php'),
            base_path('app/Models/AtlasRuntimeEfficiencyReplay.php'),
        ];
        $ok = collect($paths)->every(fn (string $path): bool => File::exists($path))
            && $this->contains($paths[0], 'atlas_runtime_efficiency_decisions')
            && $this->contains($paths[0], 'atlas_runtime_efficiency_outcomes')
            && $this->contains($paths[0], 'atlas_runtime_efficiency_policies')
            && $this->contains($paths[0], 'atlas_runtime_efficiency_replays');

        return $this->check('persistence_surface', $ok, array_map($this->relative(...), $paths), 'Restore AREG migration and models.');
    }

    /**
     * @return array<string,mixed>
     */
    private function runtimeSmoke(): array
    {
        try {
            [$decision, $controlPlane] = $this->withoutPersistingSmoke(function (): array {
                $decision = $this->runtime->govern([
                    'prompt' => 'Corrija este bug no controller com testes e evidencias.',
                    'domain' => 'programming',
                    'flow_id' => 'atlas_dev',
                    'evidence_refs' => ['test:areg:runtime'],
                ]);
                $controlPlane = $this->runtime->controlPlane();

                return [$decision, $controlPlane];
            });
        } catch (Throwable $exception) {
            return $this->check('runtime_smoke', false, ['exception' => $exception->getMessage()], 'Fix AREG runtime smoke.');
        }

        $ok = ($decision['schema_version'] ?? null) === AtlasRuntimeEfficiencyGovernorService::SCHEMA_VERSION
            && in_array(($decision['status'] ?? null), ['ready', 'watch'], true)
            && isset($decision['decision_hash'], $decision['context_minimum_pack'], $decision['layer_admissions'])
            && ($controlPlane['schema_version'] ?? null) === AtlasRuntimeEfficiencyGovernorService::CONTROL_PLANE_SCHEMA;

        return $this->check('runtime_smoke', $ok, [
            'decision_hash' => $decision['decision_hash'] ?? null,
            'path' => $decision['path'] ?? null,
            'control_plane_hash' => $controlPlane['control_plane_hash'] ?? null,
        ], 'AREG must emit canonical runtime and control-plane envelopes.');
    }

    /**
     * @return array<string,mixed>
     */
    private function fastPathSmoke(): array
    {
        $decision = $this->withoutPersistingSmoke(fn (): array => $this->runtime->govern([
            'prompt' => 'oi',
            'domain' => 'conversation',
        ]));

        $ok = ($decision['path'] ?? null) === AtlasRuntimeEfficiencyGovernorService::PATH_FAST
            && (int) ($decision['context_budget_tokens'] ?? 0) <= 1200
            && collect($decision['layer_admissions'] ?? [])->every(fn (array $layer): bool => ($layer['admitted'] ?? true) === false);

        return $this->check('fast_path_smoke', $ok, [
            'path' => $decision['path'] ?? null,
            'context_budget_tokens' => $decision['context_budget_tokens'] ?? null,
        ], 'Simple prompts must stay cheap and skip heavy layers.');
    }

    /**
     * @return array<string,mixed>
     */
    private function forgePathSmoke(): array
    {
        $decision = $this->withoutPersistingSmoke(fn (): array => $this->runtime->govern([
            'prompt' => 'Implemente uma Obra enterprise completa com milestones, testes, receipts e certificacao.',
            'domain' => 'programming',
            'flow_id' => 'atlas_forge',
            'evidence_refs' => ['doc:forge', 'goal:enterprise'],
        ]));
        $admitted = collect($decision['layer_admissions'] ?? [])->where('admitted', true)->pluck('layer_id')->all();

        $ok = ($decision['path'] ?? null) === AtlasRuntimeEfficiencyGovernorService::PATH_FORGE
            && in_array('teos', $admitted, true)
            && in_array('aemor', $admitted, true)
            && in_array('apcr', $admitted, true)
            && (int) ($decision['subagent_budget'] ?? 0) >= 5;

        return $this->check('forge_path_smoke', $ok, [
            'path' => $decision['path'] ?? null,
            'admitted_layers' => $admitted,
        ], 'Forge path must admit long-horizon, APCR and outcome layers.');
    }

    /**
     * @return array<string,mixed>
     */
    private function blockedPathSmoke(): array
    {
        $decision = $this->withoutPersistingSmoke(fn (): array => $this->runtime->govern([
            'prompt' => 'Comprar e vender automaticamente ativos externos agora.',
            'domain' => 'finance',
            'external_execution_requested' => true,
        ]));

        $ok = ($decision['status'] ?? null) === AtlasRuntimeEfficiencyGovernorService::STATUS_BLOCKED
            && ($decision['path'] ?? null) === AtlasRuntimeEfficiencyGovernorService::PATH_BLOCKED
            && ($decision['claim_policy']['external_execution_performed'] ?? true) === false;

        return $this->check('blocked_path_smoke', $ok, [
            'path' => $decision['path'] ?? null,
            'status' => $decision['status'] ?? null,
        ], 'External side-effect requests must block before provider/tool execution.');
    }

    /**
     * @return array<string,mixed>
     */
    private function controlPlaneSanitizationSmoke(): array
    {
        $rawPrompt = 'Prompt sensivel AREG que nao pode aparecer no control plane.';
        [$decision, $controlPlane] = $this->withoutPersistingSmoke(function () use ($rawPrompt): array {
            $decision = $this->runtime->govern([
                'prompt' => $rawPrompt,
                'domain' => 'programming',
                'evidence_refs' => ['test:areg:sanitization'],
            ]);
            $controlPlane = $this->runtime->controlPlane();

            return [$decision, $controlPlane];
        });
        $encoded = json_encode($controlPlane, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '';

        $tableBacked = ($controlPlane['status'] ?? null) !== 'missing';
        $ok = ! str_contains($encoded, $rawPrompt)
            && (! $tableBacked || str_contains($encoded, 'prompt_hash'))
            && ($controlPlane['schema_version'] ?? null) === AtlasRuntimeEfficiencyGovernorService::CONTROL_PLANE_SCHEMA;

        return $this->check('control_plane_sanitization_smoke', $ok, [
            'decision_hash' => $decision['decision_hash'] ?? null,
            'raw_prompt_exposed' => str_contains($encoded, $rawPrompt),
            'table_backed' => $tableBacked,
        ], 'AREG control plane must expose hashes, not raw prompts.');
    }

    /**
     * @return array<string,mixed>
     */
    private function adaptivePolicySmoke(): array
    {
        $policy = $this->withoutPersistingSmoke(fn (): array => $this->runtime->compilePolicy([
            'flow_id' => 'atlas_dev',
            'domain' => 'programming',
            'min_samples' => 1,
        ]));

        $ok = ($policy['schema_version'] ?? null) === AtlasRuntimeEfficiencyGovernorService::POLICY_SCHEMA
            && isset($policy['policy_hash'], $policy['quality_stats'], $policy['policy_rules'])
            && ($this->runtime->claimPolicy()['provider_invoked'] ?? true) === false;

        return $this->check('adaptive_policy_smoke', $ok, [
            'policy_hash' => $policy['policy_hash'] ?? null,
            'recommended_path' => $policy['recommended_path'] ?? null,
        ], 'AREG must compile deterministic adaptive policies without provider calls.');
    }

    /**
     * @return array<string,mixed>
     */
    private function counterfactualReplaySmoke(): array
    {
        $replay = $this->withoutPersistingSmoke(fn (): array => $this->runtime->counterfactualReplay([
            'prompt' => 'Corrija bug complexo com testes e evidencias.',
            'domain' => 'programming',
            'flow_id' => 'atlas_dev',
            'evidence_refs' => ['test:areg:replay'],
        ]));

        $ok = ($replay['schema_version'] ?? null) === AtlasRuntimeEfficiencyGovernorService::COUNTERFACTUAL_REPLAY_SCHEMA
            && isset($replay['replay_hash'])
            && count((array) ($replay['candidates'] ?? [])) >= 4
            && isset($replay['winning_candidate']);

        return $this->check('counterfactual_replay_smoke', $ok, [
            'replay_hash' => $replay['replay_hash'] ?? null,
            'recommended_path' => $replay['recommended_path'] ?? null,
        ], 'AREG must compare alternate runtime paths before tuning policy.');
    }

    /**
     * @return array<string,mixed>
     */
    private function selfOptimizationSmoke(): array
    {
        [$decision, $outcome, $compiledPolicy] = $this->withoutPersistingSmoke(function (): array {
            $decision = $this->runtime->govern([
                'prompt' => 'Corrija bug com testes.',
                'domain' => 'programming',
                'flow_id' => 'atlas_dev',
                'evidence_refs' => ['test:areg:self-optimization'],
            ]);
            $outcome = $this->runtime->recordOutcome([
                'decision_id' => $decision['decision_id'] ?? null,
                'quality_score' => 0.92,
                'context_roi_score' => 0.80,
                'evidence_refs' => ['outcome:areg:self-optimization'],
            ]);
            $compiledPolicy = $outcome['compiled_policy'] ?? $this->runtime->compilePolicy([
                'flow_id' => 'atlas_dev',
                'domain' => 'programming',
                'min_samples' => 1,
                'source' => 'certification_self_optimization_smoke',
            ]);

            return [$decision, $outcome, $compiledPolicy];
        });

        $ok = array_key_exists('adaptive_policy', $decision)
            && array_key_exists('counterfactual_replay', $decision)
            && array_key_exists('quality_prediction', $decision)
            && ($compiledPolicy['schema_version'] ?? null) === AtlasRuntimeEfficiencyGovernorService::POLICY_SCHEMA;

        return $this->check('self_optimization_smoke', $ok, [
            'decision_hash' => $decision['decision_hash'] ?? null,
            'outcome_hash' => $outcome['outcome_hash'] ?? null,
            'compiled_policy_hash' => $compiledPolicy['policy_hash'] ?? null,
        ], 'AREG must connect outcomes back into deterministic policy compilation.');
    }

    /**
     * @return array<string,mixed>
     */
    private function integrationWiring(): array
    {
        $hyperflow = base_path('app/Services/Ai/RouterRuntime/AtlasHyperflowEntryService.php');
        $controlPlane = base_path('app/Services/Ai/ControlPlane/AtlasAiControlPlaneService.php');
        $ok = $this->contains($hyperflow, 'AtlasRuntimeEfficiencyGovernorService')
            && $this->contains($hyperflow, 'runtime_efficiency')
            && $this->contains($controlPlane, 'runtime_efficiency_governor');

        return $this->check('integration_wiring', $ok, array_map($this->relative(...), [$hyperflow, $controlPlane]), 'Wire AREG into Hyperflow and Atlas AI Control Plane.');
    }

    /**
     * @return array<string,mixed>
     */
    private function commandsPresent(): array
    {
        $paths = [
            base_path('app/Console/Commands/AtlasRuntimeEfficiencyGovernorCommand.php'),
            base_path('app/Console/Commands/AtlasRuntimeEfficiencyGovernorCertifyCommand.php'),
        ];
        $ok = collect($paths)->every(fn (string $path): bool => File::exists($path));
        $ok = $ok && $this->contains($paths[0], 'compile-policy') && $this->contains($paths[0], 'replay');

        return $this->check('commands_present', $ok, array_map($this->relative(...), $paths), 'Restore AREG commands.');
    }

    /**
     * @return array<string,mixed>
     */
    private function testsPresent(): array
    {
        $paths = [
            base_path('tests/Feature/Ai/RuntimeEfficiency/AtlasRuntimeEfficiencyGovernorServiceTest.php'),
            base_path('tests/Feature/Ai/RuntimeEfficiency/AtlasRuntimeEfficiencyGovernorCertificationServiceTest.php'),
        ];
        $ok = collect($paths)->every(fn (string $path): bool => File::exists($path));

        return $this->check('tests_present', $ok, array_map($this->relative(...), $paths), 'Add AREG feature tests.');
    }

    /**
     * @return array<string,mixed>
     */
    private function claimPolicy(): array
    {
        $policy = $this->runtime->claimPolicy();
        $ok = ($policy['governs_only'] ?? false) === true
            && ($policy['provider_invoked'] ?? true) === false
            && ($policy['external_execution_performed'] ?? true) === false
            && ($policy['does_not_bypass_policy_or_evidence_gates'] ?? false) === true;

        return $this->check('claim_policy', $ok, ['claim_policy' => $policy], 'AREG must govern only and never bypass policy/evidence gates.');
    }

    /**
     * @template T
     *
     * @param  callable():T  $callback
     * @return T
     */
    private function withoutPersistingSmoke(callable $callback): mixed
    {
        return DB::transaction(function () use ($callback): mixed {
            $result = $callback();
            DB::rollBack();

            return $result;
        });
    }

    /**
     * @return array<string,mixed>
     */
    private function check(string $id, bool $ok, array $evidence, string $remediation): array
    {
        return [
            'id' => $id,
            'status' => $ok ? 'pass' : 'fail',
            'evidence' => $evidence,
            'remediation' => $ok ? null : $remediation,
        ];
    }

    private function contains(string $path, string $needle): bool
    {
        return File::exists($path) && str_contains((string) File::get($path), $needle);
    }

    private function relative(string $path): string
    {
        return str_replace(base_path().DIRECTORY_SEPARATOR, '', $path);
    }
}
