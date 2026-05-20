<?php

declare(strict_types=1);

namespace App\Services\Ai\StrategicReality;

use App\Services\Ai\Mission\MissionCanonicalHash;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Throwable;

final class AtlasStrategicRealityCertificationService
{
    public const SCHEMA_VERSION = 'atlas.strategic_reality.certification.v1';

    public const STATUS_PASSED = 'passed';

    public const STATUS_BLOCKED = 'blocked';

    public function __construct(
        private readonly AtlasStrategicRealityRuntimeService $runtime,
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
            $this->freshnessGateSmoke(),
            $this->governedExternalActionSmoke(),
            $this->controlPlaneSanitizationSmoke(),
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
                'covers' => 'ASRE local strategic reality scan, next-best-action decision, assumptions, risks, opportunities, priority ranking, simulation, executive briefing and control plane.',
                'does_not_cover' => 'provider execution, external action, benchmark/rivals or automatic mission execution.',
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
        $path = base_path('docs/engineering-knowledge-base/atlas-strategic-reality-engine.md');
        $ok = $this->contains($path, 'Atlas Strategic Reality Engine')
            && $this->contains($path, 'Atlas Reality Command OS')
            && $this->contains($path, 'AtlasStrategicRealityRuntimeService')
            && $this->contains($path, 'atlas.strategic_reality.decision.v1');

        return $this->check('canonical_doc', $ok, [$this->relative($path)], 'Repair ASRE canonical doc.');
    }

    /**
     * @return array<string,mixed>
     */
    private function macroNamingContract(): array
    {
        $path = base_path('docs/engineering-knowledge-base/atlas-strategic-reality-engine.md');
        $ok = $this->contains($path, 'product_name: Atlas Strategic Reality Engine')
            && $this->contains($path, 'runtime_acronym: ASRE')
            && $this->contains($path, 'internal_product_name: Atlas Reality Command OS')
            && $this->contains($path, 'technical_runtime: AtlasStrategicRealityRuntimeService');

        return $this->check('macro_naming_contract', $ok, [$this->relative($path)], 'ASRE must declare product_name, runtime_acronym, internal_product_name and technical_runtime.');
    }

    /**
     * @return array<string,mixed>
     */
    private function persistenceSurface(): array
    {
        $migration = base_path('database/migrations/2026_05_20_150000_create_atlas_strategic_reality_tables.php');
        $models = [
            base_path('app/Models/AtlasRealityEntity.php'),
            base_path('app/Models/AtlasRealityRelationship.php'),
            base_path('app/Models/AtlasStrategicAssumption.php'),
            base_path('app/Models/AtlasOpportunitySignal.php'),
            base_path('app/Models/AtlasRiskSignal.php'),
            base_path('app/Models/AtlasPriorityRanking.php'),
            base_path('app/Models/AtlasResourceAllocationPlan.php'),
            base_path('app/Models/AtlasStrategicSimulation.php'),
            base_path('app/Models/AtlasStrategicDecision.php'),
            base_path('app/Models/AtlasExecutiveBriefing.php'),
        ];
        $ok = $this->contains($migration, 'atlas_reality_entities')
            && $this->contains($migration, 'atlas_strategic_decisions')
            && $this->contains($migration, 'atlas_executive_briefings')
            && collect($models)->every(fn (string $path): bool => File::exists($path));

        return $this->check('persistence_surface', $ok, array_map($this->relative(...), [$migration, ...$models]), 'Restore ASRE migration and models.');
    }

    /**
     * @return array<string,mixed>
     */
    private function runtimeSmoke(): array
    {
        try {
            [$decision, $controlPlane] = $this->withoutPersistingSmoke(function (): array {
                $decision = $this->runtime->decide([
                    'question' => 'Qual e a melhor proxima acao para consolidar Atlas Intelligence Factory OS?',
                    'domain' => 'strategy',
                    'entities' => [
                        ['type' => 'project', 'name' => 'Atlas'],
                        ['type' => 'capability', 'name' => 'Atlas Intelligence Factory OS'],
                    ],
                    'evidence_refs' => ['doc:atlas-intelligence-factory-os', 'cert:asre:runtime'],
                ]);
                $controlPlane = $this->runtime->controlPlane();

                return [$decision, $controlPlane];
            });
        } catch (Throwable $exception) {
            return $this->check('runtime_smoke', false, ['exception' => $exception->getMessage()], 'Fix ASRE runtime smoke.');
        }

        $ok = ($decision['schema_version'] ?? null) === AtlasStrategicRealityRuntimeService::DECISION_SCHEMA
            && in_array(($decision['status'] ?? null), ['ready', 'watch'], true)
            && isset($decision['decision_hash'], $decision['briefing_id'])
            && ($controlPlane['schema_version'] ?? null) === 'atlas.strategic_reality.control_plane.v1';

        return $this->check('runtime_smoke', $ok, [
            'decision_hash' => $decision['decision_hash'] ?? null,
            'briefing_id' => $decision['briefing_id'] ?? null,
            'control_plane_hash' => $controlPlane['control_plane_hash'] ?? null,
        ], 'Make ASRE decide/control-plane emit canonical evidence.');
    }

    /**
     * @return array<string,mixed>
     */
    private function freshnessGateSmoke(): array
    {
        $decision = $this->withoutPersistingSmoke(fn (): array => $this->runtime->decide([
            'question' => 'Qual decisao estrategica devo tomar com contexto incompleto?',
            'domain' => 'strategy',
        ]));

        $ok = ($decision['status'] ?? null) === 'watch'
            && ($decision['freshness']['status'] ?? null) === 'blocked'
            && in_array('evidence_refs', (array) ($decision['freshness']['missing_required_sources'] ?? []), true)
            && in_array('attach_evidence_refs', (array) ($decision['next_actions'] ?? []), true);

        return $this->check('freshness_gate_smoke', $ok, [
            'decision_hash' => $decision['decision_hash'] ?? null,
            'status' => $decision['status'] ?? null,
            'freshness' => $decision['freshness'] ?? null,
        ], 'ASRE must degrade to watch/blocked when evidence refs are missing.');
    }

    /**
     * @return array<string,mixed>
     */
    private function governedExternalActionSmoke(): array
    {
        $decision = $this->withoutPersistingSmoke(fn (): array => $this->runtime->decide([
            'question' => 'Comprar e vender automaticamente ativos em day trade agora.',
            'domain' => 'finance',
            'evidence_refs' => ['policy:external-action-governance'],
        ]));
        $ok = ($decision['status'] ?? null) === 'blocked'
            && str_contains((string) ($decision['recommended_action'] ?? ''), 'approval')
            && ($decision['claim_policy']['external_execution_performed'] ?? true) === false;

        return $this->check('governed_external_action_smoke', $ok, [
            'decision_hash' => $decision['decision_hash'] ?? null,
            'status' => $decision['status'] ?? null,
            'claim_policy' => $decision['claim_policy'] ?? null,
        ], 'ASRE must block external/financial action and recommend governed Mission Mode.');
    }

    /**
     * @return array<string,mixed>
     */
    private function controlPlaneSanitizationSmoke(): array
    {
        $rawQuestion = 'Pergunta ASRE sensivel que nao pode aparecer no control plane.';
        [$decision, $controlPlane] = $this->withoutPersistingSmoke(function () use ($rawQuestion): array {
            $decision = $this->runtime->decide([
                'question' => $rawQuestion,
                'domain' => 'strategy',
                'evidence_refs' => ['cert:asre:sanitization'],
            ]);
            $controlPlane = $this->runtime->controlPlane();

            return [$decision, $controlPlane];
        });
        $encoded = json_encode($controlPlane, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '';

        $ok = ($controlPlane['schema_version'] ?? null) === 'atlas.strategic_reality.control_plane.v1'
            && ! str_contains($encoded, $rawQuestion)
            && str_contains($encoded, 'question_hash');

        return $this->check('control_plane_sanitization_smoke', $ok, [
            'decision_hash' => $decision['decision_hash'] ?? null,
            'control_plane_hash' => $controlPlane['control_plane_hash'] ?? null,
            'raw_question_exposed' => str_contains($encoded, $rawQuestion),
        ], 'ASRE control plane must expose hashes/excerpts only, never raw strategic questions.');
    }

    /**
     * @return array<string,mixed>
     */
    private function integrationWiring(): array
    {
        $hyperflow = base_path('app/Services/Ai/RouterRuntime/AtlasHyperflowEntryService.php');
        $controlPlane = base_path('app/Services/Ai/ControlPlane/AtlasAiControlPlaneService.php');
        $ok = $this->contains($hyperflow, 'AtlasStrategicRealityRuntimeService')
            && $this->contains($hyperflow, 'strategic_reality')
            && $this->contains($controlPlane, 'strategic_reality_engine');

        return $this->check('integration_wiring', $ok, array_map($this->relative(...), [$hyperflow, $controlPlane]), 'Wire ASRE into Hyperflow and Atlas AI Control Plane.');
    }

    /**
     * @return array<string,mixed>
     */
    private function commandsPresent(): array
    {
        $paths = [
            base_path('app/Console/Commands/AtlasStrategicRealityCommand.php'),
            base_path('app/Console/Commands/AtlasStrategicRealityCertifyCommand.php'),
            base_path('app/Console/Commands/AtlasStrategicRealityControlPlaneCommand.php'),
        ];
        $ok = collect($paths)->every(fn (string $path): bool => File::exists($path));

        return $this->check('commands_present', $ok, array_map($this->relative(...), $paths), 'Restore ASRE commands.');
    }

    /**
     * @return array<string,mixed>
     */
    private function testsPresent(): array
    {
        $paths = [
            base_path('tests/Feature/Ai/StrategicReality/AtlasStrategicRealityRuntimeServiceTest.php'),
            base_path('tests/Feature/Ai/StrategicReality/AtlasStrategicRealityCertificationServiceTest.php'),
        ];
        $ok = collect($paths)->every(fn (string $path): bool => File::exists($path));

        return $this->check('tests_present', $ok, array_map($this->relative(...), $paths), 'Add ASRE feature tests.');
    }

    /**
     * @return array<string,mixed>
     */
    private function claimPolicy(): array
    {
        $policy = $this->runtime->claimPolicy();
        $ok = ($policy['recommends_only'] ?? false) === true
            && ($policy['external_execution_performed'] ?? true) === false
            && ($policy['provider_invoked'] ?? true) === false
            && ($policy['benchmark_not_run'] ?? false) === true
            && ($policy['requires_approval_for_external_action'] ?? false) === true;

        return $this->check('claim_policy', $ok, $policy, 'ASRE claim policy must remain recommendation-only and no-provider/no-benchmark.');
    }

    /**
     * Run certification smoke writes inside a rollback-only transaction so
     * certifying ASRE does not contaminate the operator control plane.
     *
     * @template T
     *
     * @param  callable():T  $callback
     * @return T
     */
    private function withoutPersistingSmoke(callable $callback): mixed
    {
        DB::beginTransaction();
        try {
            return $callback();
        } finally {
            DB::rollBack();
        }
    }

    /**
     * @param  array<int|string,mixed>  $evidence
     * @return array<string,mixed>
     */
    private function check(string $id, bool $ok, array $evidence, string $remediation): array
    {
        return [
            'id' => $id,
            'status' => $ok ? 'pass' : 'fail',
            'severity' => 'critical',
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
