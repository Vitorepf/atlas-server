<?php

declare(strict_types=1);

namespace App\Services\Ai\IntelligenceFactory;

use App\Services\Ai\Mission\MissionCanonicalHash;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;
use Throwable;

final class AtlasIntelligenceFactoryCertificationService
{
    public const SCHEMA_VERSION = 'atlas.intelligence_factory.certification.v1';

    public const STATUS_PASSED = 'passed';

    public const STATUS_BLOCKED = 'blocked';

    public function __construct(
        private readonly AtlasIntelligenceFactoryRuntimeService $runtime,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function certify(): array
    {
        $checks = [
            $this->canonicalDoc(),
            $this->persistenceSurface(),
            $this->runtimeSmoke(),
            $this->commandsPresent(),
            $this->integrationWiring(),
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
                'covers' => 'ASEIF local capability gap detection, build/buy/borrow decisions, sandbox simulation, capability registry, certifications and runtime wiring.',
                'does_not_cover' => 'external execution, provider invocation, benchmark claims or automatic trusted capability promotion.',
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
        $path = base_path('docs/engineering-knowledge-base/atlas-intelligence-factory-os.md');
        $ok = $this->contains($path, 'Atlas Intelligence Factory OS')
            && $this->contains($path, 'Atlas Self-Evolving Intelligence Factory Runtime')
            && $this->contains($path, AtlasIntelligenceFactoryRuntimeService::CAPABILITY_SCHEMA)
            && $this->contains($path, AtlasIntelligenceFactoryRuntimeService::DECISION_SCHEMA);

        return $this->check('canonical_doc', $ok, [$this->relative($path)], 'Update Atlas Intelligence Factory OS canonical doc.');
    }

    /**
     * @return array<string,mixed>
     */
    private function persistenceSurface(): array
    {
        $migration = base_path('database/migrations/2026_05_20_140000_create_atlas_intelligence_factory_tables.php');
        $models = [
            base_path('app/Models/AtlasIntelligenceFactoryCapability.php'),
            base_path('app/Models/AtlasIntelligenceFactoryGap.php'),
            base_path('app/Models/AtlasIntelligenceFactoryDecision.php'),
            base_path('app/Models/AtlasIntelligenceFactorySimulation.php'),
            base_path('app/Models/AtlasIntelligenceFactoryCertification.php'),
            base_path('app/Models/AtlasIntelligenceFactoryEvolutionEvent.php'),
        ];
        $ok = $this->contains($migration, 'atlas_intelligence_factory_capabilities')
            && $this->contains($migration, 'atlas_intelligence_factory_evolution_events')
            && collect($models)->every(fn (string $path): bool => File::exists($path));

        return $this->check('persistence_surface', $ok, array_map($this->relative(...), [$migration, ...$models]), 'Restore ASEIF migration and models.');
    }

    /**
     * @return array<string,mixed>
     */
    private function runtimeSmoke(): array
    {
        try {
            $gap = $this->runtime->detectGap([
                'objective' => 'Build a safe reusable YouTube transcript intelligence capability.',
                'domain' => 'research',
                'evidence_refs' => ['cert:aseif:gap'],
            ]);
            $decision = $this->runtime->decide([
                'objective' => 'Build a safe reusable YouTube transcript intelligence capability.',
                'gap_type' => $gap['gap_type'] ?? null,
                'evidence_refs' => ['cert:aseif:decision'],
            ]);
            $simulation = $this->runtime->simulate([
                'objective' => 'Build a safe reusable YouTube transcript intelligence capability.',
                'decision_id' => $decision['decision_id'] ?? null,
                'decision' => $decision['decision'] ?? null,
                'evidence_refs' => ['cert:aseif:simulation'],
            ]);
            $capability = $this->runtime->registerCapability([
                'capability_key' => 'certified-youtube-transcript-intelligence',
                'name' => 'Certified YouTube Transcript Intelligence',
                'capability_type' => 'workflow',
                'domain' => 'research',
                'evidence_refs' => ['cert:aseif:capability'],
            ]);
            $certification = $this->runtime->certifyCapability((string) ($capability['capability_id'] ?? ''));
        } catch (Throwable $exception) {
            return $this->check('runtime_smoke', false, ['exception' => $exception->getMessage()], 'Fix ASEIF runtime smoke.');
        }

        $ok = ($gap['schema_version'] ?? null) === AtlasIntelligenceFactoryRuntimeService::GAP_SCHEMA
            && in_array(($decision['decision'] ?? null), ['build', 'borrow', 'use', 'promote', 'block'], true)
            && ($simulation['schema_version'] ?? null) === AtlasIntelligenceFactoryRuntimeService::SIMULATION_SCHEMA
            && ($capability['schema_version'] ?? null) === AtlasIntelligenceFactoryRuntimeService::CAPABILITY_SCHEMA
            && ($certification['status'] ?? null) === 'passed';

        return $this->check('runtime_smoke', $ok, [
            'gap_hash' => $gap['gap_hash'] ?? null,
            'decision_hash' => $decision['decision_hash'] ?? null,
            'simulation_hash' => $simulation['simulation_hash'] ?? null,
            'capability_hash' => $capability['capability_hash'] ?? null,
            'certification_hash' => $certification['certification_hash'] ?? null,
        ], 'Make ASEIF detect/decide/simulate/register/certify work with evidence.');
    }

    /**
     * @return array<string,mixed>
     */
    private function commandsPresent(): array
    {
        $paths = [
            base_path('app/Console/Commands/AtlasIntelligenceFactoryCommand.php'),
            base_path('app/Console/Commands/AtlasIntelligenceFactoryReadinessCommand.php'),
            base_path('app/Console/Commands/AtlasIntelligenceFactoryCertifyCommand.php'),
            base_path('app/Console/Commands/AtlasIntelligenceFactoryControlPlaneCommand.php'),
        ];
        $ok = collect($paths)->every(fn (string $path): bool => File::exists($path));

        return $this->check('commands_present', $ok, array_map($this->relative(...), $paths), 'Restore ASEIF commands.');
    }

    /**
     * @return array<string,mixed>
     */
    private function integrationWiring(): array
    {
        $hyperflow = base_path('app/Services/Ai/RouterRuntime/AtlasHyperflowEntryService.php');
        $persistentContext = base_path('app/Services/Ai/PersistentContext/AtlasPersistentContextRuntimeService.php');
        $controlPlane = base_path('app/Services/Ai/ControlPlane/AtlasAiControlPlaneService.php');
        $ok = $this->contains($hyperflow, 'AtlasIntelligenceFactoryRuntimeService')
            && $this->contains($persistentContext, 'intelligence_factory')
            && $this->contains($controlPlane, 'intelligence_factory');

        return $this->check('integration_wiring', $ok, array_map($this->relative(...), [$hyperflow, $persistentContext, $controlPlane]), 'Wire ASEIF into Hyperflow, APCR and Control Plane.');
    }

    /**
     * @return array<string,mixed>
     */
    private function testsPresent(): array
    {
        $paths = [
            base_path('tests/Feature/Ai/IntelligenceFactory/AtlasIntelligenceFactoryRuntimeServiceTest.php'),
            base_path('tests/Feature/Ai/IntelligenceFactory/AtlasIntelligenceFactoryCertificationServiceTest.php'),
        ];
        $ok = collect($paths)->every(fn (string $path): bool => File::exists($path));

        return $this->check('tests_present', $ok, array_map($this->relative(...), $paths), 'Add ASEIF feature tests.');
    }

    /**
     * @return array<string,mixed>
     */
    private function claimPolicy(): array
    {
        $policy = $this->runtime->claimPolicy();
        $ok = ($policy['external_execution_performed'] ?? true) === false
            && ($policy['provider_invoked'] ?? true) === false
            && ($policy['auto_trust_disabled'] ?? false) === true
            && ($policy['benchmark_not_run'] ?? false) === true;

        return $this->check('claim_policy', $ok, $policy, 'ASEIF must not execute external providers, auto-trust capabilities or run benchmarks.');
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
        return str_replace(base_path().'/', '', $path);
    }
}
