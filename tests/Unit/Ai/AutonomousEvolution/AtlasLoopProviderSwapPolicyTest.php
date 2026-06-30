<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Models\AtlasLoopCampaign;
use App\Models\AtlasLoopExploration;
use App\Models\AtlasLoopProposal;
use App\Models\AtlasLoopTask;
use App\Services\Ai\AutonomousEvolution\AtlasLoopProviderHealthProbe;
use App\Services\Ai\AutonomousEvolution\AtlasLoopProviderSwapPolicy;
use App\Services\Ai\AutonomousEvolution\Campaign\AtlasLoopCampaignSupervisor;
use App\Services\Ai\AutonomousEvolution\LoopExecutionDriver;
use App\Services\Ai\AutonomousEvolution\Persistence\AtlasLoopStore;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * The reversible provider-swap policy: a deterministic state-machine over the provider-health ledger.
 */
final class AtlasLoopProviderSwapPolicyTest extends TestCase
{
    private string $probeRoot = '';

    private string $now = '2026-06-24T12:00:00+00:00';

    private string $storageRoot = '';

    private string $emptyRepo = '';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local'); // isolates the policy's per-campaign state + the supervisor's local-disk writes
        $this->probeRoot = sys_get_temp_dir().'/atlas-swap-probe-'.bin2hex(random_bytes(6));
        $this->storageRoot = sys_get_temp_dir().'/atlas-swap-storage-'.bin2hex(random_bytes(4));
        $this->emptyRepo = sys_get_temp_dir().'/atlas-swap-repo-'.bin2hex(random_bytes(4));
        @mkdir($this->emptyRepo, 0o755, true);
        config([
            'atlas.loop.provider_health_probe_enabled' => true, // so the seeding probe actually writes
            'atlas.loop.provider_swap.window_seconds' => 3600,
            'atlas.loop.provider_swap.p95_ceiling_ms' => 10000,
            'atlas.loop.provider_swap.ok_rate_floor' => 0.6,
            'atlas.loop.provider_swap.degrade_rounds' => 1,
            'atlas.loop.provider_swap.revert_rounds' => 1,
            // Keep the heavier loop paths off so run() drives directly-seeded tasks deterministically and FAST
            // (these otherwise reach for a real provider/conductor and stall on a timeout in the sandbox).
            'atlas.loop.parallel.enabled' => false,
            'atlas.loop.scenario_fanout.enabled' => false,
            'atlas.loop.universal_certification' => false,
            'atlas.loop.conductor_escalation_enabled' => false,
            'atlas.loop.cross_provider_best_of_n' => false,
            'atlas.loop.deep_strategy_portfolio' => false,
            'atlas.loop.refactor_multi_file_via_obra' => false,
            'atlas.loop.pattern_driver_enabled' => false,
        ]);
        if (! Schema::hasTable('atlas_loop_campaigns')) {
            foreach (['2026_06_02_000100_create_atlas_loop_runtime_tables.php', '2026_06_02_000200_complete_atlas_loop_runtime_schema.php'] as $f) {
                (require base_path('database/migrations/'.$f))->up();
            }
        }
        // Deterministic "provider": the bound execution driver just turns 'broken' into 'fixed' (no real LLM),
        // so each seeded grind produces a winner fast and the swap decision is the only variable under test.
        $this->app->bind(LoopExecutionDriver::class, fn () => new class implements LoopExecutionDriver
        {
            public function attempt(string $surfaceId, string $workspace, string $intent, array $userConstraints, array $surfaceHints): array
            {
                foreach (glob($workspace.'/src/*.php') ?: [] as $file) {
                    file_put_contents($file, str_replace("'broken'", "'fixed'", (string) file_get_contents($file)));
                }

                return ['status' => 'completed'];
            }
        });
    }

    protected function tearDown(): void
    {
        if ($this->emptyRepo !== '' && Schema::hasTable('atlas_loop_campaigns')) {
            $campaignIds = AtlasLoopCampaign::query()->where('base_workspace', $this->emptyRepo)->pluck('id')->all();
            if ($campaignIds !== []) {
                AtlasLoopExploration::query()->whereIn('campaign_id', $campaignIds)->delete();
                AtlasLoopProposal::query()->whereIn('campaign_id', $campaignIds)->delete();
                AtlasLoopTask::query()->whereIn('campaign_id', $campaignIds)->delete();
                AtlasLoopCampaign::query()->whereIn('id', $campaignIds)->delete();
            }
        }
        foreach ([$this->probeRoot, $this->storageRoot, $this->emptyRepo] as $dir) {
            if ($dir !== '' && is_dir($dir)) {
                (new Process(['rm', '-rf', $dir]))->run();
            }
        }
        parent::tearDown();
    }

    public function test_hold_when_primary_is_healthy(): void
    {
        $probe = $this->probe();
        $this->seedSamples($probe, 'codex', ok: true, latencyMs: 500, n: 5);

        $decision = $this->policy($probe)->decide('camp-hold', 'codex', ['minimax']);

        $this->assertSame('atlas.loop.provider_swap.v1', $decision['schema']);
        $this->assertSame('hold', $decision['action']);
        $this->assertSame('codex', $decision['from_provider']);
        $this->assertNull($decision['to_provider']);
        $this->assertSame('healthy', $decision['reason']);
        $this->assertSame(0, $decision['consecutive_rounds']);
    }

    public function test_swap_when_primary_p95_breaches_ceiling(): void
    {
        $probe = $this->probe();
        $this->seedSamples($probe, 'codex', ok: true, latencyMs: 90000, n: 5); // p95 >> 10000 ceiling

        $policy = $this->policy($probe);
        $decision = $policy->decide('camp-swap', 'codex', ['minimax']);

        $this->assertSame('swap', $decision['action']);
        $this->assertSame('codex', $decision['from_provider']);
        $this->assertSame('minimax', $decision['to_provider']);
        $this->assertSame('p95_breach', $decision['reason']);
        $this->assertSame(1, $decision['consecutive_rounds']);
        // The swap is persisted: the active provider is now the fallback.
        $this->assertSame('minimax', $policy->activeProvider('camp-swap', 'codex'));
    }

    public function test_revert_when_fallback_recovers_for_k_rounds(): void
    {
        $probe = $this->probe();
        // Round 1: primary degraded (ok-rate breach) ⇒ swap to fallback.
        $this->seedSamples($probe, 'codex', ok: false, latencyMs: 500, n: 5);
        $policy = $this->policy($probe);
        $swap = $policy->decide('camp-revert', 'codex', ['minimax']);
        $this->assertSame('swap', $swap['action']);
        $this->assertSame('ok_rate_breach', $swap['reason']);

        // Round 2: fallback healthy ⇒ revert to primary.
        $this->seedSamples($probe, 'minimax', ok: true, latencyMs: 400, n: 5);
        $revert = $policy->decide('camp-revert', 'codex', ['minimax']);

        $this->assertSame('revert', $revert['action']);
        $this->assertSame('minimax', $revert['from_provider']);
        $this->assertSame('codex', $revert['to_provider']);
        $this->assertSame('recovered', $revert['reason']);
        $this->assertSame(1, $revert['consecutive_rounds']);
        $this->assertSame('codex', $policy->activeProvider('camp-revert', 'codex'));
    }

    public function test_single_failed_sample_does_not_evict_provider(): void
    {
        $probe = $this->probe();
        $this->seedSamples($probe, 'codex', ok: false, latencyMs: 500, n: 1); // 0/1 — below min-sample floor

        $decision = $this->policy($probe)->decide('camp-minsample', 'codex', ['minimax']);

        $this->assertSame('hold', $decision['action'], 'a single failed sample must not evict the provider');
        $this->assertSame('codex', $decision['from_provider']);
    }

    public function test_empty_fallback_chain_is_a_noop_hold(): void
    {
        $probe = $this->probe();
        $this->seedSamples($probe, 'codex', ok: false, latencyMs: 90000, n: 5); // degraded, but nowhere to swap

        $decision = $this->policy($probe)->decide('camp-empty', 'codex', []);

        $this->assertSame('hold', $decision['action']);
        $this->assertSame('codex', $decision['from_provider']);
        $this->assertNull($decision['to_provider']);
        $this->assertSame('healthy', $decision['reason']);
    }

    private function probe(): AtlasLoopProviderHealthProbe
    {
        $probe = new AtlasLoopProviderHealthProbe;
        $probe->setStorageRootForTesting($this->probeRoot);
        $probe->setClockForTesting(fn () => new DateTimeImmutable($this->now, new DateTimeZone('UTC')));

        return $probe;
    }

    private function policy(AtlasLoopProviderHealthProbe $probe): AtlasLoopProviderSwapPolicy
    {
        return new AtlasLoopProviderSwapPolicy($probe);
    }

    private function seedSamples(AtlasLoopProviderHealthProbe $probe, string $provider, bool $ok, int $latencyMs, int $n): void
    {
        for ($i = 0; $i < $n; $i++) {
            $probe->record($provider, null, [
                'ok' => $ok,
                'latency_ms' => $latencyMs,
                'cost_cents' => 1,
                'grind_id' => $provider.'-'.$i.'-'.$latencyMs.'-'.($ok ? '1' : '0'),
            ]);
        }
    }

    public function test_supervisor_flag_off_is_byte_identical_no_swap_and_provider_unchanged(): void
    {
        config(['atlas.loop.provider_swap_policy_enabled' => false]);
        config(['atlas.loop.provider_fallback_chain' => ['minimax']]);

        $campaign = $this->seedCampaignWithProvider('codex');
        $this->seedTask((string) $campaign->id, 'OffA');

        $supervisor = $this->runSupervisor(); // policy bound but flag OFF
        $supervisor->run(['campaign_id' => $campaign->id, 'scenarios' => 1]);

        $ledger = $supervisor->readLedger((string) $campaign->id, 1000);
        $swaps = array_values(array_filter($ledger, static fn (array $e): bool => ($e['event'] ?? null) === 'provider_swap'));
        $this->assertSame([], $swaps, 'flag OFF must emit no provider_swap events (byte-identical)');
        // The chosen provider is unchanged from the campaign input.
        $this->assertSame('codex', (string) $campaign->fresh()->provider);
    }

    public function test_supervisor_flag_on_swaps_to_fallback_once_then_reverts(): void
    {
        config(['atlas.loop.provider_swap_policy_enabled' => true]);
        config(['atlas.loop.provider_fallback_chain' => ['minimax']]);

        // Pre-seed the injected policy's probe: primary degraded, fallback healthy.
        $probe = $this->probe();
        $this->seedSamples($probe, 'codex', ok: true, latencyMs: 90000, n: 5);  // p95 breach
        $this->seedSamples($probe, 'minimax', ok: true, latencyMs: 300, n: 5);   // healthy fallback
        $policy = new AtlasLoopProviderSwapPolicy($probe);

        $campaign = $this->seedCampaignWithProvider('codex');
        $this->seedTask((string) $campaign->id, 'OnA');
        $this->seedTask((string) $campaign->id, 'OnB');

        $supervisor = $this->runSupervisor();
        $supervisor->setProviderSwapPolicyForTesting($policy);
        $supervisor->run(['campaign_id' => $campaign->id, 'scenarios' => 1]);

        $ledger = $supervisor->readLedger((string) $campaign->id, 1000);
        $swaps = array_values(array_filter($ledger, static fn (array $e): bool => ($e['event'] ?? null) === 'provider_swap'));

        $actions = array_map(static fn (array $e): string => (string) $e['action'], $swaps);
        $this->assertContains('swap', $actions, 'the degraded primary must trigger a swap');
        $this->assertContains('revert', $actions, 'the recovered fallback must trigger a revert');
        $this->assertSame(1, count(array_filter($actions, static fn (string $a): bool => $a === 'swap')), 'fallback selected exactly once');

        // The swap precedes the revert, and they carry the right providers.
        $swap = array_values(array_filter($swaps, static fn (array $e): bool => $e['action'] === 'swap'))[0];
        $revert = array_values(array_filter($swaps, static fn (array $e): bool => $e['action'] === 'revert'))[0];
        $this->assertSame('minimax', $swap['to_provider']);
        $this->assertSame('codex', $revert['to_provider']);
    }

    private function runSupervisor(): AtlasLoopCampaignSupervisor
    {
        $s = $this->app->make(AtlasLoopCampaignSupervisor::class);
        $s->setStorageRootForTesting($this->storageRoot);
        $t = 1000;
        $s->setClockForTesting(function () use (&$t): int {
            $now = $t;
            $t += 50;

            return $now;
        });
        $s->setSleeperForTesting(fn (int $secs): null => null);

        return $s;
    }

    private function seedCampaignWithProvider(string $provider): AtlasLoopCampaign
    {
        // Bound the budget tightly: enough to grind the seeded tasks, but no long starvation-idle tail (a
        // max_seconds=0 campaign would tick to the 86400 default, idling ~1700 cycles ⇒ minutes per test).
        $campaign = $this->app->make(AtlasLoopStore::class)->openCampaign('prove swap', $this->emptyRepo, [
            'max_seconds' => 1500,
            'max_tasks' => 2,
        ], ['scenarios_per_task' => 1], $provider);

        return $campaign;
    }

    private function seedTask(string $campaignId, string $class): AtlasLoopTask
    {
        $rel = 'src/'.$class.'.php';

        return AtlasLoopTask::create([
            'campaign_id' => $campaignId,
            'schema_version' => 'atlas.loop.task.v1',
            'status' => AtlasLoopTask::STATUS_PENDING,
            'source' => AtlasLoopTask::SOURCE_SEED,
            'self_contained' => true,
            'target_path' => $rel,
            'objective' => "Make {$class}::v() return fixed. Edit {$rel} directly.",
            'payload' => [
                'target_relative_path' => $rel,
                'target_content' => "<?php\nnamespace S;\nfinal class {$class}{ public function v(): string { return 'broken'; } }\n",
                'frozen_tests' => [[
                    'path' => 'tests/'.$class.'_test.php',
                    'content' => "<?php\nrequire __DIR__.'/../{$rel}';\n\$s=new \\S\\{$class}();\nif (\$s->v() !== 'fixed') { fwrite(STDERR,'red'); exit(1); }\necho 'ok';\n",
                ]],
                'acceptance' => ['commands' => ['php tests/'.$class.'_test.php'], 'allowed_globs' => ['src/**'], 'frozen_globs' => ['tests/**', 'composer.json'], 'metric_kind' => 'gate'],
                'allowed_files' => [$rel],
                'validation_commands' => ['php tests/'.$class.'_test.php'],
            ],
            'priority' => 100,
            'attempts' => 0,
            'max_attempts' => 2,
            'dedupe_key' => 'dk-'.$class,
        ]);
    }
}
