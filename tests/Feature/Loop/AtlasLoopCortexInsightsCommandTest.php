<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Insights\AtlasCortexInsightObserverContract;
use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Insights\AtlasCortexInsightObserverRegistry;
use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Insights\AtlasCortexInsightReceiptLedger;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class AtlasLoopCortexInsightsCommandTest extends TestCase
{
    private string $ledgerRoot = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->ledgerRoot = sys_get_temp_dir().'/atlas-cortex-insights-'.bin2hex(random_bytes(6));
        @mkdir($this->ledgerRoot, 0o755, true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->ledgerRoot);
        parent::tearDown();
    }

    private function rrmdir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach ((array) scandir($dir) as $f) {
            if ($f === '.' || $f === '..') {
                continue;
            }
            $full = $dir.'/'.$f;
            is_dir($full) ? $this->rrmdir($full) : @unlink($full);
        }
        @rmdir($dir);
    }

    private function bindLedger(): void
    {
        $root = $this->ledgerRoot;
        app()->singleton(AtlasCortexInsightReceiptLedger::class, fn () => new AtlasCortexInsightReceiptLedger($root));
    }

    public function test_disabled_flag_emits_disabled_envelope_and_writes_nothing(): void
    {
        config(['atlas.loop.cortex.insights.enabled' => false]);
        $this->bindLedger();

        Artisan::call('atlas:loop:cortex:insights', ['action' => 'inspect', '--json' => true]);
        $out = trim(Artisan::output());

        $this->assertSame(['disabled' => true], json_decode($out, true));
        $this->assertSame([], glob($this->ledgerRoot.'/*') ?: []);
    }

    public function test_inspect_with_flag_on_emits_orphan_spike_observation_and_appends_ledger(): void
    {
        config(['atlas.loop.cortex.insights.enabled' => true]);
        $this->bindLedger();

        $orphanObserver = new class implements AtlasCortexInsightObserverContract
        {
            public function observe(array $facts): array
            {
                return [
                    'witnesses' => $facts['orphan_files'] ?? [],
                ];
            }
        };
        $observerClass = $orphanObserver::class;
        app()->singleton($observerClass, fn () => $orphanObserver);

        app()->singleton(AtlasCortexInsightObserverRegistry::class, fn () => new AtlasCortexInsightObserverRegistry([
            'orphan_spike' => [
                'fqcn' => $observerClass,
                'required_fact_keys' => ['orphan_files'],
                'envelope_shape' => ['witnesses' => 'list<string>'],
            ],
        ]));

        app()->bind('atlas.loop.cortex.insights.facts', fn () => [
            'snapshot_id' => 'snap-1',
            'orphan_files' => ['app/Services/Old.php', 'app/Services/Stale.php'],
        ]);

        Artisan::call('atlas:loop:cortex:insights', ['action' => 'inspect', '--json' => true]);
        $payload = json_decode(trim(Artisan::output()), true);

        $this->assertSame('snap-1', $payload['snapshot_id']);
        $this->assertCount(1, $payload['observations']);
        $this->assertSame('orphan_spike', $payload['observations'][0]['axis_id']);
        $this->assertSame(['app/Services/Old.php', 'app/Services/Stale.php'], $payload['observations'][0]['witnesses']);

        // Ledger row appended.
        $rows = app(AtlasCortexInsightReceiptLedger::class)->history('orphan_spike', 10);
        $this->assertCount(1, $rows);
    }

    public function test_axis_only_invokes_one_observer_and_unknown_axis_exits_non_zero(): void
    {
        config(['atlas.loop.cortex.insights.enabled' => true]);
        $this->bindLedger();

        $touched = ['orphan_spike' => 0, 'intent_drift' => 0];
        $orphan = new class($touched) implements AtlasCortexInsightObserverContract
        {
            public function __construct(public array &$touched) {}

            public function observe(array $facts): array
            {
                $this->touched['orphan_spike']++;

                return ['witnesses' => []];
            }
        };
        $intent = new class($touched) implements AtlasCortexInsightObserverContract
        {
            public function __construct(public array &$touched) {}

            public function observe(array $facts): array
            {
                $this->touched['intent_drift']++;

                return ['witnesses' => []];
            }
        };
        app()->singleton($orphan::class, fn () => $orphan);
        app()->singleton($intent::class, fn () => $intent);
        app()->singleton(AtlasCortexInsightObserverRegistry::class, fn () => new AtlasCortexInsightObserverRegistry([
            'orphan_spike' => ['fqcn' => $orphan::class, 'required_fact_keys' => [], 'envelope_shape' => []],
            'intent_drift' => ['fqcn' => $intent::class, 'required_fact_keys' => [], 'envelope_shape' => []],
        ]));

        Artisan::call('atlas:loop:cortex:insights', ['action' => 'axis', '--axis' => 'intent_drift', '--json' => true]);
        $this->assertSame(0, $touched['orphan_spike']);
        $this->assertSame(1, $touched['intent_drift']);

        $exit = Artisan::call('atlas:loop:cortex:insights', ['action' => 'axis', '--axis' => 'unknown_axis_id', '--json' => true]);
        $this->assertNotSame(0, $exit);
        $payload = json_decode(trim(Artisan::output()), true);
        $this->assertStringContainsString('unknown_axis', $payload['error']);
    }

    public function test_history_returns_rows_in_deterministic_order_and_contains_no_score_tokens(): void
    {
        config(['atlas.loop.cortex.insights.enabled' => true]);
        $this->bindLedger();

        $ledger = app(AtlasCortexInsightReceiptLedger::class);
        $ledger->append('snap-1', ['axis_id' => 'orphan_spike', 'witnesses' => ['a']]);
        $ledger->append('snap-2', ['axis_id' => 'orphan_spike', 'witnesses' => ['b']]);

        Artisan::call('atlas:loop:cortex:insights', ['action' => 'history', '--axis' => 'orphan_spike', '--limit' => 5, '--json' => true]);
        $raw = trim(Artisan::output());
        $payload = json_decode($raw, true);

        $this->assertSame('orphan_spike', $payload['axis_id']);
        $this->assertCount(2, $payload['rows']);
        foreach (['"score"', '"rank"', '"weight"', '"level"'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $raw, "history output must not contain {$forbidden}");
        }
    }
}
