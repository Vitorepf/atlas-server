<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Skills;

use App\Models\AtlasIntelligenceFactoryCapability;
use App\Services\Ai\Skills\AtlasSkillEvolutionRuntimeService;
use App\Services\Ai\Skills\SkillDiscoveryService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesIntelligenceFactoryTables;
use Tests\TestCase;

final class AtlasSkillEvolutionRuntimeServiceTest extends TestCase
{
    use CreatesIntelligenceFactoryTables;

    private string $workspace;

    private string $home;

    private string $previousHome;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createIntelligenceFactoryTables();

        $root = sys_get_temp_dir().'/atlas-skill-evolution-'.Str::random(10);
        $this->workspace = $root.'/workspace';
        $this->home = $root.'/home';
        $this->previousHome = (string) ($_SERVER['HOME'] ?? getenv('HOME') ?: '');
        File::ensureDirectoryExists($this->workspace);
        File::ensureDirectoryExists($this->home);
        File::ensureDirectoryExists($root.'/vault/_skills');
        $_SERVER['HOME'] = $this->home;
        putenv('HOME='.$this->home);
        config(['atlas.semantic_memory.vault_path' => $root.'/vault']);
    }

    protected function tearDown(): void
    {
        $root = dirname($this->workspace);
        File::deleteDirectory($root);
        if ($this->previousHome !== '') {
            $_SERVER['HOME'] = $this->previousHome;
            putenv('HOME='.$this->previousHome);
        }

        $this->dropIntelligenceFactoryTables();
        parent::tearDown();
    }

    // GOD-DEBULK 3b: IntelligenceFactory quarantined to archive/ (blueprint 91c334a27 §2.2).
    // The proposal path must SURVIVE the missing factory (nullable ctor) — skill candidate still
    // certifies, but no factory capability is registered.
    public function test_proposes_skill_candidate_from_verified_outcome_without_factory_capability(): void
    {
        $payload = app(AtlasSkillEvolutionRuntimeService::class)->propose([
            'workspace' => $this->workspace,
            'objective' => 'Normalize rare lunar invoice telemetry without existing bundle overlap',
            'domain' => 'billing_ops',
            'flow_id' => 'invoice_telemetry',
            'evidence_refs' => ['test:skill-evolution'],
        ]);

        $this->assertSame(AtlasSkillEvolutionRuntimeService::PROPOSAL_SCHEMA, $payload['schema_version']);
        $this->assertSame('candidate', $payload['status']);
        $this->assertSame('create_new_skill', $payload['action']);
        $this->assertSame('passed', data_get($payload, 'certification.status'));
        $this->assertFalse(data_get($payload, 'claim_policy.auto_installs_skill'));
        $this->assertStringContainsString('## Evidence', $payload['draft_markdown']);
        $this->assertNull($payload['intelligence_factory_capability']);
        $this->assertSame(0, AtlasIntelligenceFactoryCapability::query()->count());
    }

    public function test_blocks_skill_candidate_without_evidence_refs(): void
    {
        $payload = app(AtlasSkillEvolutionRuntimeService::class)->propose([
            'workspace' => $this->workspace,
            'objective' => 'Create skill without evidence',
        ]);

        $this->assertSame('blocked', $payload['status']);
        $this->assertSame('fail', collect(data_get($payload, 'certification.checks'))->firstWhere('id', 'has_evidence_refs')['status']);
        $this->assertDatabaseCount('atlas_intelligence_factory_capabilities', 0);
    }

    public function test_refactor_plan_reads_existing_skill_without_rewriting_it(): void
    {
        $skillDir = $this->workspace.'/.atlas/skills/local-review';
        File::ensureDirectoryExists($skillDir);
        File::put($skillDir.'/SKILL.md', <<<'MD'
---
name: local-review
description: Local review workflow.
---

# local-review

Procedure for local review.
MD);

        app(SkillDiscoveryService::class)->trustWorkspace($this->workspace);

        $payload = app(AtlasSkillEvolutionRuntimeService::class)->refactorPlan([
            'workspace' => $this->workspace,
            'skill' => 'local-review',
        ]);

        $this->assertSame(AtlasSkillEvolutionRuntimeService::REFACTOR_SCHEMA, $payload['schema_version']);
        $this->assertSame('watch', $payload['status']);
        $this->assertSame('local-review', data_get($payload, 'skill.name'));
        $this->assertNotEmpty($payload['recommendations']);
        $this->assertFileExists($skillDir.'/SKILL.md');
    }

    public function test_cli_emits_json_for_propose_and_refactor_plan(): void
    {
        Artisan::call('atlas:skills:evolve', [
            'action' => 'propose',
            '--workspace' => $this->workspace,
            '--objective' => 'Improve Atlas Forge verification skill',
            '--evidence' => ['test:cli'],
            '--json' => true,
        ]);
        $proposal = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(AtlasSkillEvolutionRuntimeService::PROPOSAL_SCHEMA, $proposal['schema_version']);
        $this->assertSame('candidate', $proposal['status']);

        Artisan::call('atlas:skills:evolve', [
            'action' => 'refactor-plan',
            '--workspace' => $this->workspace,
            '--skill' => 'missing-skill',
            '--json' => true,
        ]);
        $blocked = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('blocked', $blocked['status']);
    }
}
