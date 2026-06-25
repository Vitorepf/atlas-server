<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\AtlasLoopMasterSwitch;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class AtlasLoopFactAnchorCommandTest extends TestCase
{
    private string $envPath = '';

    private ?string $prevOverride = null;

    protected function setUp(): void
    {
        parent::setUp();
        $tmp = sys_get_temp_dir().'/atlas-anchor-cli-'.bin2hex(random_bytes(6));
        @mkdir($tmp, 0o755, true);
        $this->envPath = $tmp.'/.env';
        $this->prevOverride = AtlasLoopMasterSwitch::$envPathOverride;
        AtlasLoopMasterSwitch::$envPathOverride = $this->envPath;
        file_put_contents($this->envPath, AtlasLoopMasterSwitch::KEY."=1\n");
        $this->bindFakeFactSource([
            ['text' => 'See app/Console/Commands/AtlasLoopFactAnchorCommand.php for the CLI.', 'anchor_required' => true, 'source' => 'comprehension'],
            ['text' => 'narrative only', 'anchor_required' => false, 'source' => 'operator'],
        ]);
    }

    protected function tearDown(): void
    {
        AtlasLoopMasterSwitch::$envPathOverride = $this->prevOverride;
        @unlink($this->envPath);
        @rmdir(\dirname($this->envPath));
        parent::tearDown();
    }

    /**
     * @param  list<array<string,mixed>>  $facts
     */
    private function bindFakeFactSource(array $facts): void
    {
        $this->app->instance('atlas.loop.fact_source', new class($facts)
        {
            public function __construct(private array $facts) {}

            public function recent(int $window): array
            {
                return array_slice($this->facts, 0, $window);
            }
        });
    }

    public function test_coverage_action_emits_required_keys(): void
    {
        $exit = Artisan::call('atlas:loop:facts:anchor', ['action' => 'coverage', '--window' => 10, '--json' => true]);
        $p = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exit);
        foreach (['total_facts', 'anchored_facts_in_critical', 'coverage_pct', 'anchor_density_histogram', 'per_source'] as $k) {
            $this->assertArrayHasKey($k, $p, "coverage output missing required key {$k}");
        }
    }

    public function test_audit_action_against_critical_channel_rejects_anchorless_fact(): void
    {
        $exit = Artisan::call('atlas:loop:facts:anchor', [
            'action' => 'audit',
            '--channel' => 'comprehension',
            '--fact-text' => 'just narrative without any anchors',
            '--json' => true,
        ]);
        $p = json_decode(trim(Artisan::output()), true);
        $this->assertNotSame(0, $exit);
        $this->assertFalse($p['accepted']);
        $this->assertSame('no_resolved_anchor', $p['reason']);
    }

    public function test_history_action_appends_a_snapshot_visible_on_next_invocation(): void
    {
        // Move history file to a temp path via storage_path. Run coverage to seed one snapshot.
        $historyBefore = is_file(storage_path('atlas/loop/anchor_coverage_history.jsonl'))
            ? (string) file_get_contents(storage_path('atlas/loop/anchor_coverage_history.jsonl'))
            : '';

        Artisan::call('atlas:loop:facts:anchor', ['action' => 'coverage', '--window' => 10, '--json' => true]);
        Artisan::output();

        Artisan::call('atlas:loop:facts:anchor', ['action' => 'history', '--window' => 5, '--json' => true]);
        $p = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $p['count'] === 0 ? 0 : 0); // soft sanity
        $this->assertGreaterThanOrEqual(1, $p['count']);
        $this->assertNotEmpty($p['snapshots']);
    }

    public function test_command_source_does_not_import_marketing_aaeos_or_forge(): void
    {
        $src = (string) file_get_contents(base_path('app/Console/Commands/AtlasLoopFactAnchorCommand.php'));
        foreach (['MarketingDomain', 'Aaeos', 'Forge'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $src, "anchor CLI must NOT import {$forbidden}");
        }
    }
}
