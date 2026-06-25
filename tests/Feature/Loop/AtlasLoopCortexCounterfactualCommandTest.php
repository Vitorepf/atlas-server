<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Active\Depth\AtlasCortexCounterfactualHypothesisLedger;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class AtlasLoopCortexCounterfactualCommandTest extends TestCase
{
    private string $tmpRoot = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpRoot = sys_get_temp_dir().'/atlas-cf-cli-'.bin2hex(random_bytes(6));
        @mkdir($this->tmpRoot, 0o755, true);
        AtlasCortexCounterfactualHypothesisLedger::setRootForTesting($this->tmpRoot);
        AtlasCortexCounterfactualHypothesisLedger::$flagOverride = true;
        putenv('ATLAS_LOOP_MASTER_ENABLED=true');
    }

    protected function tearDown(): void
    {
        AtlasCortexCounterfactualHypothesisLedger::setRootForTesting(null);
        AtlasCortexCounterfactualHypothesisLedger::$flagOverride = null;
        putenv('ATLAS_LOOP_MASTER_ENABLED');
        foreach ((array) glob($this->tmpRoot.'/*') as $f) {
            @unlink((string) $f);
        }
        @rmdir($this->tmpRoot);
        parent::tearDown();
    }

    private function assertNoForbiddenKeysInOutput(string $raw): void
    {
        foreach (['"score"', '"rank"', '"best"', '"recommendation"'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $raw, "output must not contain {$forbidden}");
        }
    }

    public function test_each_action_emits_facts_only(): void
    {
        app()->bind('atlas.cortex.counterfactual.snapshot', fn () => ['reachability' => ['app/Foo.php' => []]]);
        app()->bind('atlas.cortex.counterfactual.recent_walks', fn () => [
            ['walk_id' => 'w1', 'steps' => [['site' => 'app/Foo.php', 'mutation_kind' => 'k']]],
            ['walk_id' => 'w2', 'steps' => [['site' => 'app/Foo.php', 'mutation_kind' => 'k']]],
        ]);

        Artisan::call('atlas:loop:cortex:counterfactual', ['action' => 'multi', '--site' => 'app/Foo.php', '--depth' => 2, '--json' => true]);
        $this->assertNoForbiddenKeysInOutput(trim(Artisan::output()));

        Artisan::call('atlas:loop:cortex:counterfactual', ['action' => 'impact', '--json' => true]);
        $this->assertNoForbiddenKeysInOutput(trim(Artisan::output()));

        Artisan::call('atlas:loop:cortex:counterfactual', ['action' => 'hypothesis', '--site' => 'app/Foo.php', '--walk' => 'w1', '--json' => true]);
        $this->assertNoForbiddenKeysInOutput(trim(Artisan::output()));

        Artisan::call('atlas:loop:cortex:counterfactual', ['action' => 'history', '--site' => 'app/Foo.php', '--json' => true]);
        $this->assertNoForbiddenKeysInOutput(trim(Artisan::output()));
    }

    public function test_master_disabled_exits_zero_without_writes(): void
    {
        putenv('ATLAS_LOOP_MASTER_ENABLED=false');
        AtlasCortexCounterfactualHypothesisLedger::$flagOverride = false;

        Artisan::call('atlas:loop:cortex:counterfactual', ['action' => 'hypothesis', '--site' => 'x', '--walk' => 'w', '--json' => true]);
        $payload = json_decode(trim(Artisan::output()), true);

        $this->assertTrue($payload['disabled']);
        $this->assertSame([], glob($this->tmpRoot.'/*.json') ?: []);
    }

    public function test_unknown_action_exits_non_zero_with_clear_error(): void
    {
        $exit = Artisan::call('atlas:loop:cortex:counterfactual', ['action' => 'bogus', '--json' => true]);
        $payload = json_decode(trim(Artisan::output()), true);

        $this->assertNotSame(0, $exit);
        $this->assertStringContainsString('multi', (string) ($payload['error'] ?? ''));
        $this->assertStringContainsString('impact', (string) ($payload['error'] ?? ''));
        $this->assertStringContainsString('hypothesis', (string) ($payload['error'] ?? ''));
        $this->assertStringContainsString('history', (string) ($payload['error'] ?? ''));
    }
}
