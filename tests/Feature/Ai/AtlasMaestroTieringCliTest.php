<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\AutonomousEvolution\AtlasLoopMasterSwitch;
use App\Services\Ai\SelfConstruction\Maestro\Tiering\AtlasMaestroTierMismatchLedger;
use App\Services\Ai\SelfConstruction\Maestro\Tiering\AtlasMaestroTieredRoutingPolicy;
use App\Services\Ai\SelfConstruction\Maestro\Tiering\AtlasMaestroWorkerTierRegistry;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the atlas:task maestro:tiering CLI: register-worker writes; policy with a hardest fixture
 * returns refuse_tier_mismatch + non-zero exit; history filters to client; master-OFF turns every verb
 * into a byte-identical no-op with status='disabled'.
 */
final class AtlasMaestroTieringCliTest extends TestCase
{
    private string $envFile;

    private string $registryPath;

    private string $ledgerPath;

    protected function setUp(): void
    {
        parent::setUp();
        $tag = bin2hex(random_bytes(6));
        $this->envFile = sys_get_temp_dir().'/atlas_tiering_env_'.$tag.'.env';
        $this->registryPath = sys_get_temp_dir().'/atlas_tiering_reg_'.$tag.'.json';
        $this->ledgerPath = sys_get_temp_dir().'/atlas_tiering_ledger_'.$tag.'.jsonl';

        AtlasLoopMasterSwitch::$envPathOverride = $this->envFile;
        file_put_contents($this->envFile, "ATLAS_LOOP_MASTER_ENABLED=true\n");

        $this->app->instance(AtlasMaestroWorkerTierRegistry::class, new AtlasMaestroWorkerTierRegistry($this->registryPath));
        $this->app->instance(AtlasMaestroTierMismatchLedger::class, new AtlasMaestroTierMismatchLedger($this->ledgerPath, static fn (): string => '2026-06-25T00:00:00Z'));
    }

    protected function tearDown(): void
    {
        AtlasLoopMasterSwitch::$envPathOverride = null;
        @unlink($this->envFile);
        @unlink($this->registryPath);
        @unlink($this->ledgerPath);
        parent::tearDown();
    }

    private function hardestFixture(): array
    {
        return [
            'packet_id' => 'p-hardest',
            'objective' => 'edit constitution',
            'allowed_files' => ['app/Services/Ai/AutonomousEvolution/Constitution/Bylaw.php'],
            'acceptance_criteria' => ['ok'],
        ];
    }

    private function bindPacketLookup(array $packetMap): void
    {
        $this->app->instance('atlas.maestro.tiering.packet_lookup', static fn (string $id): ?array => $packetMap[$id] ?? null);
    }

    public function test_register_worker_writes_to_registry_snapshot(): void
    {
        $exit = Artisan::call('atlas:task', [
            'action' => 'maestro:tiering',
            'verb' => 'register-worker',
            '--client' => 'sonnet-cli-1',
            '--tier' => 'easy',
            '--json' => true,
        ]);
        $this->assertSame(0, $exit);

        $rec = $this->app->make(AtlasMaestroWorkerTierRegistry::class)->lookup('sonnet-cli-1');
        $this->assertNotNull($rec);
        $this->assertSame('easy', $rec->declaredMaxTier);
    }

    public function test_policy_for_hardest_packet_against_easy_worker_returns_refuse_with_non_zero_exit(): void
    {
        $this->bindPacketLookup(['p-hardest' => $this->hardestFixture()]);
        $this->app->make(AtlasMaestroWorkerTierRegistry::class)->register('sonnet-cli-1', 'easy');

        $exit = Artisan::call('atlas:task', [
            'action' => 'maestro:tiering',
            'verb' => 'policy',
            '--client' => 'sonnet-cli-1',
            '--packet' => 'p-hardest',
            '--json' => true,
        ]);
        $output = json_decode(trim(Artisan::output()), true);

        $this->assertNotSame(0, $exit, 'refuse_tier_mismatch ⇒ non-zero exit');
        $this->assertSame('refuse_tier_mismatch', $output['status']);
    }

    public function test_history_filters_by_client_and_caps_at_limit(): void
    {
        // Pre-seed the ledger with 3 refusals across two clients.
        $ledger = $this->app->make(AtlasMaestroTierMismatchLedger::class);
        for ($i = 1; $i <= 3; $i++) {
            $ledger->record([
                'verdict' => \App\Services\Ai\SelfConstruction\Maestro\Tiering\AtlasMaestroTieredRoutingPolicy::VERDICT_REFUSE,
                'packet_tier' => 'hardest',
                'packet_fact_basis' => ['x'],
                'worker_declared_max_tier' => 'easy',
                'client_id' => 'sonnet-cli-1',
                'reason' => 'r',
            ], ['packet_id' => 'p'.$i]);
        }
        $ledger->record([
            'verdict' => \App\Services\Ai\SelfConstruction\Maestro\Tiering\AtlasMaestroTieredRoutingPolicy::VERDICT_REFUSE,
            'packet_tier' => 'hard',
            'packet_fact_basis' => [],
            'worker_declared_max_tier' => 'easy',
            'client_id' => 'codex-1',
            'reason' => 'r',
        ], ['packet_id' => 'p4']);

        $exit = Artisan::call('atlas:task', [
            'action' => 'maestro:tiering',
            'verb' => 'history',
            '--client' => 'sonnet-cli-1',
            '--limit' => 3,
            '--json' => true,
        ]);
        $output = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exit);
        $this->assertSame('ok', $output['status']);
        $this->assertCount(3, $output['rows']);
        foreach ($output['rows'] as $r) {
            $this->assertSame('sonnet-cli-1', $r['client_id']);
        }
    }

    public function test_master_off_yields_disabled_status_and_byte_identical_no_op(): void
    {
        file_put_contents($this->envFile, "ATLAS_LOOP_MASTER_ENABLED=false\n");

        $beforeReg = is_file($this->registryPath) ? filesize($this->registryPath) : -1;
        $beforeLedger = is_file($this->ledgerPath) ? filesize($this->ledgerPath) : -1;

        $exit = Artisan::call('atlas:task', [
            'action' => 'maestro:tiering',
            'verb' => 'register-worker',
            '--client' => 'sonnet-cli-1',
            '--tier' => 'easy',
            '--json' => true,
        ]);
        $output = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exit);
        $this->assertSame('disabled', $output['status']);

        $afterReg = is_file($this->registryPath) ? filesize($this->registryPath) : -1;
        $afterLedger = is_file($this->ledgerPath) ? filesize($this->ledgerPath) : -1;
        $this->assertSame($beforeReg, $afterReg, 'master-off must not touch the registry');
        $this->assertSame($beforeLedger, $afterLedger, 'master-off must not touch the ledger');
    }

    private function refusal(string $clientId, string $packetTier, string $packetId): void
    {
        $this->app->make(AtlasMaestroTierMismatchLedger::class)->record([
            'verdict'                => AtlasMaestroTieredRoutingPolicy::VERDICT_REFUSE,
            'packet_tier'            => $packetTier,
            'packet_fact_basis'      => [],
            'worker_declared_max_tier' => 'easy',
            'client_id'              => $clientId,
            'reason'                 => 'r',
        ], ['packet_id' => $packetId]);
    }

    public function test_scoreboard_groups_by_client_with_mismatch_stats_and_recommendation(): void
    {
        // sonnet: 2 refusals (hardest + hard) → hardest_refused=hardest, recommend=hard
        $this->refusal('sonnet-cli-1', 'hardest', 'p1');
        $this->refusal('sonnet-cli-1', 'hard', 'p2');
        // codex: 1 refusal (hard) → recommend=medium
        $this->refusal('codex-1', 'hard', 'p3');

        $exit = Artisan::call('atlas:task', ['action' => 'maestro:tiering', 'verb' => 'scoreboard', '--json' => true]);
        $output = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exit);
        $this->assertSame('ok', $output['status']);
        $this->assertSame('scoreboard', $output['verb']);
        $this->assertArrayHasKey('scoreboard', $output);

        $byClient = array_column($output['scoreboard'], null, 'client_id');
        $this->assertArrayHasKey('sonnet-cli-1', $byClient);
        $this->assertSame(2, $byClient['sonnet-cli-1']['mismatch_count']);
        $this->assertSame('hardest', $byClient['sonnet-cli-1']['hardest_refused_tier']);
        $this->assertSame('hard', $byClient['sonnet-cli-1']['recommended_max_tier']);
        $this->assertArrayHasKey('codex-1', $byClient);
        $this->assertSame(1, $byClient['codex-1']['mismatch_count']);
        $this->assertSame('hard', $byClient['codex-1']['hardest_refused_tier']);
        $this->assertSame('medium', $byClient['codex-1']['recommended_max_tier']);
    }

    public function test_scoreboard_client_filter_is_read_only_and_scopes_output(): void
    {
        $this->refusal('sonnet-cli-1', 'hard', 'px');
        $this->refusal('other-client', 'easy', 'py');

        $beforeReg = is_file($this->registryPath) ? filesize($this->registryPath) : -1;

        $exit = Artisan::call('atlas:task', [
            'action' => 'maestro:tiering',
            'verb' => 'scoreboard',
            '--client' => 'sonnet-cli-1',
            '--limit' => 5,
            '--json' => true,
        ]);
        $output = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exit);
        $clients = array_column($output['scoreboard'], 'client_id');
        $this->assertContains('sonnet-cli-1', $clients);
        $this->assertNotContains('other-client', $clients, 'client filter must scope scoreboard');
        // Read-only: registry must not change.
        $afterReg = is_file($this->registryPath) ? filesize($this->registryPath) : -1;
        $this->assertSame($beforeReg, $afterReg, 'scoreboard must not write registry');
    }

    public function test_classify_emits_classifier_result(): void
    {
        $this->bindPacketLookup(['p-hardest' => $this->hardestFixture()]);

        $exit = Artisan::call('atlas:task', [
            'action' => 'maestro:tiering',
            'verb' => 'classify',
            '--packet' => 'p-hardest',
            '--json' => true,
        ]);
        $output = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exit);
        $this->assertSame('ok', $output['status']);
        $this->assertSame('hardest', $output['result']['tier']);
    }
}
