<?php

declare(strict_types=1);

namespace Tests\Feature\CodeGraph;

use App\Models\AiCodebaseWorldModel;
use App\Services\Engineering\CodeGraph\CodeGraphWorkspaceModelResolver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AP-815 · W-3 — proves scope-aware world-model resolution: each workspace resolves to
 * its OWN symbol/module model, a foreign workspace resolves to null, and "latest" is
 * chronological (not by random-UUID id).
 */
final class CodeGraphWorkspaceModelResolverTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        foreach (['ai_codebase_world_model_edges', 'ai_codebase_world_model_nodes', 'ai_codebase_world_models'] as $t) {
            Schema::dropIfExists($t);
        }
        (require database_path('migrations/2026_05_17_220000_create_ai_autonomous_engineering_os_tables.php'))->up();
    }

    protected function tearDown(): void
    {
        foreach (['ai_codebase_world_model_edges', 'ai_codebase_world_model_nodes', 'ai_codebase_world_models'] as $t) {
            Schema::dropIfExists($t);
        }
        parent::tearDown();
    }

    private function model(string $scope, ?Carbon $createdAt = null): AiCodebaseWorldModel
    {
        $token = (string) Str::uuid();

        return AiCodebaseWorldModel::query()->create([
            'goal_record_id' => null,
            'model_id' => 'm-'.substr(hash('sha256', $token), 0, 18),
            'scope' => $scope,
            'status' => 'built',
            'capabilities' => ['code_graph'],
            'risks' => [],
            'receipt' => [],
            'model_hash' => hash('sha256', $token),
            'created_at' => $createdAt ?? now(),
            'updated_at' => $createdAt ?? now(),
        ]);
    }

    public function test_resolves_symbol_model_per_workspace(): void
    {
        $atlas = $this->model('atlas-server-symbols');
        $blackink = $this->model('blackink-symbols');

        $resolver = new CodeGraphWorkspaceModelResolver;

        $this->assertSame($atlas->id, $resolver->symbolModel('atlas-server')?->id);
        $this->assertSame($blackink->id, $resolver->symbolModel('blackink')?->id);
        $this->assertNull($resolver->symbolModel('ghost-workspace'), 'a workspace with no graph resolves to null');
    }

    public function test_module_and_symbol_scopes_are_distinct(): void
    {
        $module = $this->model('atlas-server');
        $symbol = $this->model('atlas-server-symbols');

        $resolver = new CodeGraphWorkspaceModelResolver;

        $this->assertSame($module->id, $resolver->moduleModel('atlas-server')?->id);
        $this->assertSame($symbol->id, $resolver->symbolModel('atlas-server')?->id);
        $this->assertNotSame($resolver->moduleModel('atlas-server')?->id, $resolver->symbolModel('atlas-server')?->id);
    }

    public function test_latest_is_chronological_not_by_id(): void
    {
        // Older first, then newer — created out of id order on purpose (UUIDv4 ids are random).
        $older = $this->model('atlas-server-symbols', now()->subDays(2));
        $newer = $this->model('atlas-server-symbols', now());

        $resolver = new CodeGraphWorkspaceModelResolver;

        $this->assertSame($newer->id, $resolver->symbolModel('atlas-server')?->id, 'must return the chronologically newest model');
        $this->assertNotSame($older->id, $resolver->symbolModel('atlas-server')?->id);
    }

    public function test_blank_workspace_falls_back_to_primary(): void
    {
        $atlas = $this->model('atlas-server-symbols');
        $resolver = new CodeGraphWorkspaceModelResolver;

        $this->assertSame($atlas->id, $resolver->symbolModel('')?->id);
        $this->assertSame('atlas-server-symbols', $resolver->symbolScope(''));
    }
}
