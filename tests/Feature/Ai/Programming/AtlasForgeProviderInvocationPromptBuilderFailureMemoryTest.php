<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Programming;

use App\Models\AtlasProject;
use App\Services\Ai\Programming\AtlasDev\RuntimeIntelligence\DevFailureCapsuleRuntimeService;
use App\Services\Ai\Programming\AtlasDev\RuntimeIntelligence\DevTaskPacketRuntimeService;
use App\Services\Ai\Programming\AtlasDev\Support\WorkspaceOriginIdentity;
use App\Services\Ai\Programming\AtlasForgeProviderInvocationPromptBuilder;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Forge × M5 seam: the governed Forge prompt carries the failure-mode memory
 * of its target area (the SAME DevFailureCapsulePromptInjector the Dev fast
 * path uses) — an obra touching files that already failed sees those failure
 * modes before spending a governed provider call. Zero matching capsules keep
 * the evidence contract byte-identical (no fabricated content).
 */
final class AtlasForgeProviderInvocationPromptBuilderFailureMemoryTest extends TestCase
{
    private object $migration;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('atlas.code_graph.auto_context', false);
        $this->ensureProjectsTable();
        $this->migration = require base_path('database/migrations/2026_05_22_160000_create_atlas_dev_runtime_intelligence_tables.php');
        $this->migration->up();
    }

    protected function tearDown(): void
    {
        $this->migration->down();
        parent::tearDown();
    }

    private function ensureProjectsTable(): void
    {
        if (Schema::hasTable('atlas_projects')) {
            return;
        }

        Schema::create('atlas_projects', function (\Illuminate\Database\Schema\Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->string('title')->nullable();
            $t->text('description')->nullable();
            $t->string('status')->default('active');
            $t->string('domain')->default('atlas');
            $t->text('goal')->nullable();
            $t->text('next_action')->nullable();
            $t->text('desired_outcome')->nullable();
            $t->text('definition_of_done')->nullable();
            $t->string('priority')->nullable();
            $t->json('metadata')->nullable();
            $t->timestamps();
        });
    }

    /** @param  list<string>  $allowedFiles */
    private function makeObra(array $allowedFiles, string $workspacePath): AtlasProject
    {
        return AtlasProject::create([
            'id' => (string) Str::uuid(),
            'title' => 'Forge failure-memory seam obra',
            'description' => 'Forge M5 seam',
            'status' => 'active',
            'domain' => 'atlas',
            'goal' => 'harden the parser area',
            'desired_outcome' => 'failure memory reaches the governed prompt',
            'priority' => 'normal',
            'metadata' => [
                'intent' => 'harden the parser area',
                'allowed_files' => $allowedFiles,
                'workspace_path' => $workspacePath,
            ],
        ]);
    }

    /** @return array<string,mixed> */
    private function dispatchPlan(): array
    {
        return [
            'role' => 'primary_builder',
            'provider' => 'atlas-local',
            'model' => 'atlas-runtime',
            'dispatch_id' => 'dispatch_fm_test',
            'decision_receipt_id' => 'rcpt_fm_test',
            'decision_receipt_hash' => str_repeat('a', 64),
            'provider_topology_id' => 'topo_fm_test',
            'quality_gates' => ['composer test'],
        ];
    }

    /** @param  list<string>  $changedFiles */
    private function persistCapsule(array $changedFiles, string $workspaceSlug): void
    {
        $packet = (new DevTaskPacketRuntimeService)->persist([
            'run_id' => 'forge-fm-'.bin2hex(random_bytes(3)),
            'task_id' => 'forge-fm-task-'.bin2hex(random_bytes(3)),
            'objective' => 'Forge failure-memory fixture',
            'risk_band' => 'medium',
            'task_class' => 'feature',
            'suggested_tests' => ['php artisan test'],
            'expected_files' => $changedFiles,
            'workspace_slug' => $workspaceSlug,
        ]);
        (new DevFailureCapsuleRuntimeService)->persist([
            'run_id' => $packet->run_id,
            'task_id' => $packet->task_id,
            'failing_gate' => 'verification_gate',
            'failure_class' => 'test_failure',
            'error' => 'parser regression: plural weeks lost null symmetry',
            'changed_files' => $changedFiles,
            'suggested_repair' => 'restore the weeks plural null symmetry',
        ], $packet);
    }

    public function test_area_capsule_reaches_the_governed_forge_prompt(): void
    {
        $workspacePath = '/tmp/forge-fm-ws-'.bin2hex(random_bytes(3));
        $files = ['app/Services/Ai/Scheduling/ScheduleParser.php'];
        $this->persistCapsule($files, WorkspaceOriginIdentity::slug($workspacePath));

        $prompt = (new AtlasForgeProviderInvocationPromptBuilder)->build(
            $this->makeObra($files, $workspacePath),
            $this->dispatchPlan(),
        );

        $modes = $prompt['evidence_contract']['known_failure_modes'] ?? [];
        self::assertNotEmpty($modes, json_encode($prompt['evidence_contract']));
        self::assertStringContainsString('test_failure', implode("\n", $modes));
    }

    public function test_foreign_area_capsule_never_injects_and_contract_stays_clean(): void
    {
        $workspacePath = '/tmp/forge-fm-ws-'.bin2hex(random_bytes(3));
        $this->persistCapsule(['app/Services/Other/Area.php'], WorkspaceOriginIdentity::slug($workspacePath));

        $prompt = (new AtlasForgeProviderInvocationPromptBuilder)->build(
            $this->makeObra(['app/Services/Ai/Scheduling/ScheduleParser.php'], $workspacePath),
            $this->dispatchPlan(),
        );

        self::assertArrayNotHasKey('known_failure_modes', $prompt['evidence_contract']);
    }
}
