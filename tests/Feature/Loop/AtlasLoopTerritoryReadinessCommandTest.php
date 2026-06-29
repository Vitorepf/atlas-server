<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\AtlasLoopTerritoryLadder;
use App\Services\Ai\AutonomousEvolution\V2\AtlasLoopV2AuditJournal;
use App\Services\Ai\AutonomousEvolution\V2\AtlasLoopV2ScopeManifestRegistry;
use App\Services\Ai\AutonomousEvolution\V2\AtlasLoopV2TerritoryPromotionOrchestrator;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the territory-promotion orchestrator is live at the operator surface: an absent manifest reports
 * promotable=false with a missing-manifest violation; a present manifest whose counts meet the promotion rule
 * reports promotable=true. The orchestrator is injected with a controlled manifest registry.
 */
final class AtlasLoopTerritoryReadinessCommandTest extends TestCase
{
    private string $journal = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->journal = sys_get_temp_dir().'/atlas-territory-readiness-'.bin2hex(random_bytes(5)).'.jsonl';
    }

    protected function tearDown(): void
    {
        @unlink($this->journal);
        parent::tearDown();
    }

    /**
     * @param  list<array<string,mixed>>  $scopes
     */
    private function bindOrchestrator(array $scopes): void
    {
        $this->app->bind(
            AtlasLoopV2TerritoryPromotionOrchestrator::class,
            fn (): AtlasLoopV2TerritoryPromotionOrchestrator => new AtlasLoopV2TerritoryPromotionOrchestrator(
                new AtlasLoopV2ScopeManifestRegistry($scopes),
                new AtlasLoopTerritoryLadder(),
                new AtlasLoopV2AuditJournal($this->journal),
            ),
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function manifest(): array
    {
        return [
            'id' => 'marketing',
            'territory_name' => 'Marketing',
            'repo_root_absolute' => '/tmp/atlas-marketing',
            'discovery_roots' => ['app/Services/Ai/Marketing'],
            'frozen_safety_files' => ['app/Services/Ai/Marketing/MarketingFrozenJudge.php'],
            'risk_tier' => 'medium',
            'max_parallel_workers' => 2,
            'promotion_required_certified_leaps' => 1,
        ];
    }

    public function test_absent_manifest_is_not_promotable(): void
    {
        $this->bindOrchestrator([]);

        $exit = Artisan::call('atlas:loop:territory-readiness', ['--scope' => 'ghost', '--json' => true]);
        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exit);
        $this->assertFalse($decoded['promotable']);
        $this->assertContains('scope_manifest_missing:ghost', $decoded['violations']);
    }

    public function test_present_manifest_meeting_rule_is_promotable(): void
    {
        $this->bindOrchestrator([$this->manifest()]);

        $exit = Artisan::call('atlas:loop:territory-readiness', [
            '--scope' => 'marketing',
            '--robustness-cases' => 3,
            '--certified-leaps' => 5,
            '--json' => true,
        ]);
        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exit);
        $this->assertTrue($decoded['promotable'], (string) json_encode($decoded));
        $this->assertTrue($decoded['invariant_holds']);
        $this->assertTrue($decoded['promotion_rule_met']);
        $this->assertSame([], $decoded['violations']);
    }

    public function test_missing_scope_is_usage_error(): void
    {
        $exit = Artisan::call('atlas:loop:territory-readiness', ['--json' => true]);

        $this->assertNotSame(0, $exit);
        $this->assertStringContainsString('usage_error', Artisan::output());
    }
}
