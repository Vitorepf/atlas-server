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
 * Proves the V2 territory-promotion orchestrator is live at the operator surface: an absent manifest reports a
 * scope_manifest_missing verdict; a present manifest whose file-supplied state meets the rule is promotable;
 * a red main window in the supplied state blocks promotion.
 */
final class AtlasLoopV2TerritoryPromotionCommandTest extends TestCase
{
    private string $journal = '';

    private string $input = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->journal = sys_get_temp_dir().'/atlas-v2-territory-'.bin2hex(random_bytes(5)).'.jsonl';
        $this->input = sys_get_temp_dir().'/atlas-v2-state-'.bin2hex(random_bytes(5)).'.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->journal);
        @unlink($this->input);
        parent::tearDown();
    }

    /** @param list<array<string,mixed>> $scopes */
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

    /** @return array<string,mixed> */
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

    private function promote(string $scope, array $state): array
    {
        file_put_contents($this->input, (string) json_encode($state));
        $exit = Artisan::call('atlas:loop:v2-territory-promotion', ['--scope' => $scope, '--input' => $this->input, '--json' => true]);

        return ['exit' => $exit, 'd' => json_decode(trim(Artisan::output()), true)];
    }

    public function test_absent_manifest_is_not_promotable(): void
    {
        $this->bindOrchestrator([]);

        ['exit' => $exit, 'd' => $d] = $this->promote('ghost', []);

        $this->assertSame(0, $exit);
        $this->assertFalse($d['promotable']);
        $this->assertContains('scope_manifest_missing:ghost', $d['violations']);
    }

    public function test_state_meeting_rule_is_promotable(): void
    {
        $this->bindOrchestrator([$this->manifest()]);

        ['exit' => $exit, 'd' => $d] = $this->promote('marketing', [
            'robustness_cases' => 3,
            'certified_leaps' => 5,
            'red_main_in_window' => 0,
            'compounding_trend_up' => true,
        ]);

        $this->assertSame(0, $exit);
        $this->assertTrue($d['promotable'], (string) json_encode($d));
        $this->assertTrue($d['invariant_holds']);
        $this->assertTrue($d['promotion_rule_met']);
        $this->assertSame([], $d['violations']);
    }

    public function test_red_main_in_supplied_state_blocks_promotion(): void
    {
        $this->bindOrchestrator([$this->manifest()]);

        ['d' => $d] = $this->promote('marketing', [
            'robustness_cases' => 3,
            'certified_leaps' => 5,
            'red_main_in_window' => 2, // red main ⇒ promotion rule fails
            'compounding_trend_up' => true,
        ]);

        $this->assertFalse($d['promotable'], (string) json_encode($d));
        $this->assertFalse($d['promotion_rule_met']);
    }

    public function test_missing_scope_is_usage_error(): void
    {
        $exit = Artisan::call('atlas:loop:v2-territory-promotion', ['--json' => true]);

        $this->assertNotSame(0, $exit);
        $this->assertStringContainsString('usage_error', Artisan::output());
    }
}
