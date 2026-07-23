<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Memory;

use App\Models\AtlasEngineeringRun;
use App\Models\AtlasMemoryEntry;
use App\Services\Ai\Memory\AtlasMemoryRegistryService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AtlasMemoryRationaleGuardTest extends TestCase
{
    /** @var list<object> */
    private array $migrations = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            'database/migrations/2026_05_02_000000_create_atlas_memory_entries_table.php',
        ] as $path) {
            $migration = require base_path($path);
            $migration->down();
            $migration->up();
            $this->migrations[] = $migration;
        }

        Schema::dropIfExists('atlas_engineering_runs');
        Schema::create('atlas_engineering_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('task_id')->nullable()->index();
            $table->uuid('project_id')->nullable()->index();
            $table->string('workspace_path_hash', 64);
            $table->string('workspace_label', 180);
            $table->json('provider_strategy_json')->default('{}');
            $table->string('context_pack_hash', 64)->nullable();
            $table->string('status', 32)->default('queued')->index();
            $table->string('decision', 32)->nullable();
            $table->unsignedSmallInteger('score')->nullable();
            $table->unsignedSmallInteger('max_attempts')->default(1);
            $table->unsignedSmallInteger('attempt_count')->default(0);
            $table->json('metadata')->default('{}');
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('atlas_engineering_runs');
        foreach (array_reverse($this->migrations) as $migration) {
            $migration->down();
        }

        parent::tearDown();
    }

    public function test_record_without_rationale_is_accepted_fail_open_with_flag_and_warning(): void
    {
        $entry = app(AtlasMemoryRegistryService::class)->record([
            'memory_type' => 'technical_context',
            'title' => 'Thin memory',
            'summary' => 'Thin memory summary',
            'body' => 'This memory has useful-looking content but no rationale marker.',
        ]);

        $this->assertSame('active', $entry->status);
        $this->assertTrue((bool) data_get($entry->metadata, 'quality.needs_rationale'));
        $this->assertContains('missing_rationale_marker', data_get($entry->metadata, 'quality.warnings', []));
    }

    public function test_record_with_rationale_is_clean(): void
    {
        $entry = app(AtlasMemoryRegistryService::class)->record([
            'memory_type' => 'technical_context',
            'title' => 'Rationale memory',
            'summary' => 'Rationale memory summary',
            'body' => 'motivo: the write path needs a real explanation. provenance: "the write path needs a real explanation."',
        ]);

        $this->assertFalse((bool) data_get($entry->metadata, 'quality.needs_rationale', false));
        $this->assertNotContains('missing_rationale_marker', data_get($entry->metadata, 'quality.warnings', []));
    }

    public function test_curate_uses_the_same_rationale_guard_when_body_is_changed(): void
    {
        $entry = app(AtlasMemoryRegistryService::class)->record([
            'memory_type' => 'technical_context',
            'title' => 'Original rationale memory',
            'summary' => 'Original rationale memory summary',
            'body' => 'motivo: original body is explained. provenance: "original body is explained."',
        ]);

        app(AtlasMemoryRegistryService::class)->curate($entry, [
            'body' => 'Replacement body dropped rationale markers.',
        ]);

        $this->assertTrue((bool) data_get($entry->fresh()->metadata, 'quality.needs_rationale'));
    }

    public function test_engineering_run_harness_learning_includes_a_real_rationale_from_blocking_reasons(): void
    {
        $run = AtlasEngineeringRun::query()->create([
            'id' => (string) Str::uuid(),
            'task_id' => null,
            'workspace_path_hash' => hash('sha256', base_path()),
            'workspace_label' => 'atlas-server',
            'provider_strategy_json' => [],
            'status' => 'completed',
            'decision' => 'unsafe',
            'score' => 42,
            'metadata' => [
                'blocking_reasons' => ['phpunit failed in memory quality suite'],
            ],
        ]);

        $entry = app(AtlasMemoryRegistryService::class)->recordHarnessLearning($run);

        $this->assertInstanceOf(AtlasMemoryEntry::class, $entry);
        $this->assertStringContainsString('motivo:', $entry->body);
        $this->assertStringContainsString('phpunit failed in memory quality suite', $entry->body);
        $this->assertFalse((bool) data_get($entry->metadata, 'quality.needs_rationale', false));
    }
}
