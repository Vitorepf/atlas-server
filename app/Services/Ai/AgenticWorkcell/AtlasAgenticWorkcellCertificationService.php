<?php

declare(strict_types=1);

namespace App\Services\Ai\AgenticWorkcell;

use App\Services\Ai\Mission\MissionCanonicalHash;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class AtlasAgenticWorkcellCertificationService
{
    public const SCHEMA_VERSION = 'atlas.agentic_workcell.certification.v1';

    public function __construct(
        private readonly AtlasAgenticWorkcellRuntimeService $runtime,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function certify(): array
    {
        $checks = [
            $this->canonicalDoc(),
            $this->namingContract(),
            $this->persistenceSurface(),
            $this->runtimeDesignSmoke(),
            $this->forgeCrewSmoke(),
            $this->researchMapReduceSmoke(),
            $this->redBlueSmoke(),
            $this->toolBuilderSmoke(),
            $this->outcomeLearningSmoke(),
            $this->controlPlaneSmoke(),
            $this->hyperflowWiring(),
            $this->commandsPresent(),
            $this->testsPresent(),
            $this->claimPolicy(),
        ];
        $status = collect($checks)->contains(fn (array $check): bool => ($check['status'] ?? null) === 'fail')
            ? 'blocked'
            : 'passed';
        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'generated_at' => CarbonImmutable::now()->toJSON(),
            'summary' => [
                'total' => count($checks),
                'pass' => collect($checks)->where('status', 'pass')->count(),
                'fail' => collect($checks)->where('status', 'fail')->count(),
            ],
            'checks' => $checks,
            'blockers' => array_values(array_filter($checks, fn (array $check): bool => ($check['status'] ?? null) === 'fail')),
            'claim_policy' => $this->runtime->claimPolicy(),
            'scope' => [
                'covers' => 'AAWR organizational design, topology selection, role contracts, context isolation, task graph, schedule, verification, evidence, outcome learning, org patterns, control plane and Hyperflow planning integration.',
                'does_not_cover' => 'spawning real agents, provider invocation, external execution, benchmark claims or bypassing Dev/Forge/AREG policy.',
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
        $path = base_path('docs/engineering-knowledge-base/atlas-agentic-workcell-runtime.md');
        $ok = $this->contains($path, 'Atlas Agentic Workcell Runtime')
            && $this->contains($path, 'AAWR')
            && $this->contains($path, 'Atlas Cognitive Workcell')
            && $this->contains($path, 'AtlasAgenticWorkcellRuntimeService');

        return $this->check('canonical_doc', $ok, [$this->relative($path)], 'Create or repair AAWR canonical doc.');
    }

    /**
     * @return array<string,mixed>
     */
    private function namingContract(): array
    {
        $path = base_path('docs/engineering-knowledge-base/atlas-agentic-workcell-runtime.md');
        $ok = $this->contains($path, 'product_name: Atlas Agentic Workcell Runtime')
            && $this->contains($path, 'runtime_acronym: AAWR')
            && $this->contains($path, 'internal_product_name: Atlas Cognitive Workcell')
            && $this->contains($path, 'technical_runtime: AtlasAgenticWorkcellRuntimeService');

        return $this->check('naming_contract', $ok, [$this->relative($path)], 'AAWR must declare product/acronym/surface/runtime names.');
    }

    /**
     * @return array<string,mixed>
     */
    private function persistenceSurface(): array
    {
        $paths = [
            base_path('database/migrations/2026_05_20_180000_create_atlas_agentic_workcell_tables.php'),
            base_path('app/Models/AtlasAgenticWorkcell.php'),
            base_path('app/Models/AtlasAgenticWorkcellEvent.php'),
            base_path('app/Models/AtlasAgenticWorkcellOutcome.php'),
            base_path('app/Models/AtlasAgenticWorkcellOrgPattern.php'),
        ];
        $ok = collect($paths)->every(fn (string $path): bool => File::exists($path))
            && $this->contains($paths[0], 'atlas_agentic_workcells')
            && $this->contains($paths[0], 'atlas_agentic_workcell_org_patterns');

        return $this->check('persistence_surface', $ok, array_map($this->relative(...), $paths), 'Restore AAWR persistence.');
    }

    /**
     * @return array<string,mixed>
     */
    private function runtimeDesignSmoke(): array
    {
        try {
            $workcell = $this->withoutPersisting(fn (): array => $this->runtime->design([
                'objective' => 'Corrija um bug médio com contexto mínimo, testes e verificação independente.',
                'domain' => 'programming',
                'flow_id' => 'atlas_dev',
                'evidence_refs' => ['test:aawr:runtime'],
            ]));
        } catch (Throwable $exception) {
            return $this->check('runtime_design_smoke', false, ['exception' => $exception->getMessage()], 'Fix AAWR runtime smoke.');
        }

        $ok = ($workcell['schema_version'] ?? null) === AtlasAgenticWorkcellRuntimeService::WORKCELL_SCHEMA
            && isset($workcell['workcell_hash'], $workcell['org_design'], $workcell['role_roster'], $workcell['context_packs'])
            && ($workcell['maturity_level'] ?? null) === AtlasAgenticWorkcellRuntimeService::LEVEL_L5;

        return $this->check('runtime_design_smoke', $ok, [
            'workcell_hash' => $workcell['workcell_hash'] ?? null,
            'topology' => $workcell['topology'] ?? null,
            'maturity_level' => $workcell['maturity_level'] ?? null,
        ], 'AAWR must emit the full L5 workcell contract.');
    }

    /**
     * @return array<string,mixed>
     */
    private function forgeCrewSmoke(): array
    {
        $workcell = $this->withoutPersisting(fn (): array => $this->runtime->design([
            'objective' => 'Implemente uma Obra enterprise completa por meses com milestones, Dev, Forge e certificacao.',
            'domain' => 'programming',
            'flow_id' => 'atlas_forge',
            'evidence_refs' => ['doc:forge'],
        ]));
        $roleIds = collect($workcell['role_roster'] ?? [])->pluck('role_id')->all();
        $ok = ($workcell['topology'] ?? null) === 'forge_milestone_crew'
            && in_array('lead_architect', $roleIds, true)
            && in_array('final_certifier', $roleIds, true)
            && count($roleIds) >= 8;

        return $this->check('forge_crew_smoke', $ok, ['topology' => $workcell['topology'] ?? null, 'roles' => $roleIds], 'Forge-scale work must produce a milestone crew.');
    }

    /**
     * @return array<string,mixed>
     */
    private function researchMapReduceSmoke(): array
    {
        $workcell = $this->withoutPersisting(fn (): array => $this->runtime->design([
            'objective' => 'Faça pesquisa profunda sobre mercado global com fontes contrárias e síntese.',
            'domain' => 'research',
            'evidence_refs' => ['source:seed'],
        ]));
        $ok = ($workcell['topology'] ?? null) === 'mapreduce_research'
            && (bool) data_get($workcell, 'verification_plan.evidence_auditor_required', false) === true;

        return $this->check('research_mapreduce_smoke', $ok, ['topology' => $workcell['topology'] ?? null], 'Research work must use map-reduce style source coverage.');
    }

    /**
     * @return array<string,mixed>
     */
    private function redBlueSmoke(): array
    {
        $workcell = $this->withoutPersisting(fn (): array => $this->runtime->design([
            'objective' => 'Decida a prioridade estratégica da empresa com risco e premissas explícitas.',
            'domain' => 'strategy',
            'evidence_refs' => ['context:strategy'],
        ]));
        $roleIds = collect($workcell['role_roster'] ?? [])->pluck('role_id')->all();
        $ok = ($workcell['topology'] ?? null) === 'red_blue_team'
            && in_array('red_team_critic', $roleIds, true)
            && in_array('final_adjudicator', $roleIds, true);

        return $this->check('red_blue_smoke', $ok, ['topology' => $workcell['topology'] ?? null, 'roles' => $roleIds], 'Strategy/high-risk work must use adversarial roles.');
    }

    /**
     * @return array<string,mixed>
     */
    private function toolBuilderSmoke(): array
    {
        $workcell = $this->withoutPersisting(fn (): array => $this->runtime->design([
            'objective' => 'Crie uma ferramenta nova quando faltar capability para processar arquivos difíceis.',
            'domain' => 'programming',
            'evidence_refs' => ['capability:gap'],
        ]));
        $ok = ($workcell['topology'] ?? null) === 'tool_builder_loop'
            && in_array('simulation_verifier', collect($workcell['role_roster'] ?? [])->pluck('role_id')->all(), true);

        return $this->check('tool_builder_smoke', $ok, ['topology' => $workcell['topology'] ?? null], 'Capability gaps must route through tool-builder loop.');
    }

    /**
     * @return array<string,mixed>
     */
    private function outcomeLearningSmoke(): array
    {
        if (! Schema::hasTable('atlas_agentic_workcells')) {
            $service = base_path('app/Services/Ai/AgenticWorkcell/AtlasAgenticWorkcellRuntimeService.php');
            $migration = base_path('database/migrations/2026_05_20_180000_create_atlas_agentic_workcell_tables.php');
            $ok = $this->contains($service, 'compileOrgPattern')
                && $this->contains($service, 'learningCandidates')
                && $this->contains($migration, 'atlas_agentic_workcell_org_patterns');

            return $this->check('outcome_learning_smoke', $ok, [$this->relative($service), $this->relative($migration)], 'AAWR must support outcome learning and org pattern compilation.');
        }

        [$workcell, $outcome] = $this->withoutPersisting(function (): array {
            $workcell = $this->runtime->design([
                'objective' => 'Planeje uma feature média com verificação.',
                'domain' => 'programming',
                'flow_id' => 'atlas_dev',
                'evidence_refs' => ['test:aawr:learning'],
            ]);
            $outcome = $this->runtime->closeOutcome([
                'workcell_id' => $workcell['workcell_id'] ?? null,
                'quality_score' => 0.88,
                'coordination_roi_score' => 0.72,
                'evidence_refs' => ['outcome:aawr:learning'],
            ]);

            return [$workcell, $outcome];
        });
        $ok = ($outcome['schema_version'] ?? null) === AtlasAgenticWorkcellRuntimeService::OUTCOME_SCHEMA
            && ($outcome['compiled_org_pattern']['schema_version'] ?? null) === AtlasAgenticWorkcellRuntimeService::ORG_PATTERN_SCHEMA;

        return $this->check('outcome_learning_smoke', $ok, [
            'workcell_hash' => $workcell['workcell_hash'] ?? null,
            'outcome_hash' => $outcome['outcome_hash'] ?? null,
            'pattern_hash' => $outcome['compiled_org_pattern']['pattern_hash'] ?? null,
        ], 'AAWR must compile organization patterns from outcomes.');
    }

    /**
     * @return array<string,mixed>
     */
    private function controlPlaneSmoke(): array
    {
        $controlPlane = $this->withoutPersisting(fn (): array => $this->runtime->controlPlane());
        $ok = ($controlPlane['schema_version'] ?? null) === AtlasAgenticWorkcellRuntimeService::CONTROL_PLANE_SCHEMA
            && isset($controlPlane['control_plane_hash']);

        return $this->check('control_plane_smoke', $ok, ['control_plane_hash' => $controlPlane['control_plane_hash'] ?? null], 'AAWR control plane must emit canonical read model.');
    }

    /**
     * @return array<string,mixed>
     */
    private function hyperflowWiring(): array
    {
        $path = base_path('app/Services/Ai/RouterRuntime/AtlasHyperflowEntryService.php');
        $ok = $this->contains($path, 'AtlasAgenticWorkcellRuntimeService')
            && $this->contains($path, 'agentic_workcell');

        return $this->check('hyperflow_wiring', $ok, [$this->relative($path)], 'Wire AAWR into Hyperflow planning envelope.');
    }

    /**
     * @return array<string,mixed>
     */
    private function commandsPresent(): array
    {
        $paths = [
            base_path('app/Console/Commands/AtlasAgenticWorkcellCommand.php'),
            base_path('app/Console/Commands/AtlasAgenticWorkcellCertifyCommand.php'),
        ];
        $ok = collect($paths)->every(fn (string $path): bool => File::exists($path));

        return $this->check('commands_present', $ok, array_map($this->relative(...), $paths), 'Restore AAWR commands.');
    }

    /**
     * @return array<string,mixed>
     */
    private function testsPresent(): array
    {
        $paths = [
            base_path('tests/Feature/Ai/AgenticWorkcell/AtlasAgenticWorkcellRuntimeServiceTest.php'),
            base_path('tests/Feature/Ai/AgenticWorkcell/AtlasAgenticWorkcellCertificationServiceTest.php'),
        ];
        $ok = collect($paths)->every(fn (string $path): bool => File::exists($path));

        return $this->check('tests_present', $ok, array_map($this->relative(...), $paths), 'Add AAWR tests.');
    }

    /**
     * @return array<string,mixed>
     */
    private function claimPolicy(): array
    {
        $policy = $this->runtime->claimPolicy();
        $ok = ($policy['planning_only'] ?? false) === true
            && ($policy['provider_invoked'] ?? true) === false
            && ($policy['agents_spawned'] ?? true) === false
            && ($policy['requires_independent_verification'] ?? false) === true;

        return $this->check('claim_policy', $ok, ['claim_policy' => $policy], 'AAWR must plan only, never spawn agents directly, and require verification.');
    }

    /**
     * @template T
     *
     * @param  callable():T  $callback
     * @return T
     */
    private function withoutPersisting(callable $callback): mixed
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
