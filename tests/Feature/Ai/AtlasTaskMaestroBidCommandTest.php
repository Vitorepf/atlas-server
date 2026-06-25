<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Console\Commands\AtlasTaskMaestroBidCommand;
use App\Services\Ai\SelfConstruction\Maestro\ProviderNegotiation\AtlasMaestroProviderBidProposer;
use App\Services\Ai\SelfConstruction\Maestro\ProviderNegotiation\AtlasMaestroProviderBidReceiptLedger;
use App\Services\Ai\SelfConstruction\Maestro\ProviderNegotiation\ProviderProfile;
use App\Services\Ai\SelfConstruction\Maestro\ProviderNegotiation\TaskEnvelope;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class AtlasTaskMaestroBidCommandTest extends TestCase
{
    private string $envelopePath = '';

    private string $ledgerRoot = '';

    protected function setUp(): void
    {
        parent::setUp();
        $tag = bin2hex(random_bytes(4));
        $this->envelopePath = sys_get_temp_dir().'/atlas-maestro-bid-env-'.$tag.'.json';
        $this->ledgerRoot = sys_get_temp_dir().'/atlas-maestro-bid-ledger-'.$tag;
        AtlasMaestroProviderBidReceiptLedger::setRootForTesting($this->ledgerRoot);

        config()->set('atlas.maestro.provider_negotiation.profiles', [
            [
                'provider_id' => 'fable-local',
                'declared_capabilities' => ['code', 'plan'],
                'observed_cost_per_token_in' => 0.001,
                'observed_cost_per_token_out' => 0.002,
                'observed_p50_latency_ms' => 200,
                'current_load_pct' => 10,
                'locality' => 'local',
                'sensitivity_allowed' => ['unclassified', 'sensitive'],
            ],
            [
                'provider_id' => 'codex-cloud',
                'declared_capabilities' => ['code'],
                'observed_cost_per_token_in' => 0.0005,
                'observed_cost_per_token_out' => 0.001,
                'observed_p50_latency_ms' => 350,
                'current_load_pct' => 20,
                'locality' => 'cloud',
                'sensitivity_allowed' => ['unclassified'],
            ],
        ]);

        file_put_contents($this->envelopePath, json_encode([
            'task_id' => 'task-alpha',
            'kind' => 'maestro',
            'required_capabilities' => ['code'],
            'deadline' => '2026-06-26T00:00:00Z',
            'local_only_bool' => false,
            'sensitivity_class' => 'unclassified',
            'provider_ids' => [],
        ]));
    }

    protected function tearDown(): void
    {
        @unlink($this->envelopePath);
        if (is_dir($this->ledgerRoot)) {
            foreach (glob($this->ledgerRoot.'/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($this->ledgerRoot);
        }
        AtlasMaestroProviderBidReceiptLedger::setRootForTesting(null);
        parent::tearDown();
    }

    private function runCmd(array $params): array
    {
        $buf = new BufferedOutput();
        /** @var \Illuminate\Contracts\Console\Kernel $kernel */
        $kernel = app(\Illuminate\Contracts\Console\Kernel::class);
        $exit = $kernel->call('atlas:task:maestro:bid', $params, $buf);

        return ['exit' => $exit, 'output' => $buf->fetch()];
    }

    public function test_propose_emits_bidset_matching_direct_proposer_call(): void
    {
        $r = $this->runCmd(['action' => 'propose', '--task' => $this->envelopePath, '--json' => true]);
        self::assertSame(0, $r['exit']);
        $cliBids = json_decode(trim($r['output']), true);
        self::assertIsArray($cliBids);

        $envelope = new TaskEnvelope(
            taskId: 'task-alpha',
            kind: 'maestro',
            requiredCapabilities: ['code'],
            deadline: '2026-06-26T00:00:00Z',
            localOnly: false,
            sensitivityClass: 'unclassified',
            providerIds: [],
        );
        $profiles = [
            new ProviderProfile('fable-local', ['code', 'plan'], 0.001, 0.002, 200, 10, 'local', ['unclassified', 'sensitive']),
            new ProviderProfile('codex-cloud', ['code'], 0.0005, 0.001, 350, 20, 'cloud', ['unclassified']),
        ];
        $direct = (new AtlasMaestroProviderBidProposer())->propose($envelope, $profiles);
        $directBids = $direct->toArray();

        self::assertCount(count($directBids), $cliBids);
        foreach ($cliBids as $i => $bid) {
            self::assertSame($directBids[$i]['bid_hash'] ?? null, $bid['bid_hash'] ?? null);
        }
    }

    public function test_arbitrate_appends_one_ledger_entry_and_second_call_fails_immutable(): void
    {
        $r1 = $this->runCmd(['action' => 'arbitrate', '--task' => $this->envelopePath, '--json' => true]);
        self::assertSame(0, $r1['exit']);

        $ledger = new AtlasMaestroProviderBidReceiptLedger();
        $entry = $ledger->recall('task-alpha');
        self::assertNotNull($entry);

        $r2 = $this->runCmd(['action' => 'arbitrate', '--task' => $this->envelopePath, '--json' => true]);
        self::assertSame(AtlasTaskMaestroBidCommand::EXIT_IMMUTABLE, $r2['exit']);
        self::assertStringContainsString('ledger_immutable_violation', $r2['output']);
    }

    public function test_history_returns_seeded_entries_for_provider_only(): void
    {
        // Seed via arbitrate (one task -> one winner).
        $this->runCmd(['action' => 'arbitrate', '--task' => $this->envelopePath, '--json' => true]);

        $ledger = new AtlasMaestroProviderBidReceiptLedger();
        $winnerId = $ledger->recall('task-alpha')?->winnerProviderId ?? '';
        self::assertNotSame('', $winnerId);

        $r = $this->runCmd(['action' => 'history', '--provider' => $winnerId, '--json' => true]);
        self::assertSame(0, $r['exit']);
        $rows = json_decode(trim($r['output']), true);
        self::assertCount(1, $rows);
        self::assertSame($winnerId, $rows[0]['winner_provider_id']);

        // Empty result for a never-recorded provider.
        $r2 = $this->runCmd(['action' => 'history', '--provider' => 'never-existed', '--json' => true]);
        self::assertSame(0, $r2['exit']);
        self::assertSame([], json_decode(trim($r2['output']), true));
    }

    public function test_arbitrate_without_envelope_refuses_no_silent_fallback(): void
    {
        $r = $this->runCmd(['action' => 'arbitrate']);
        self::assertSame(AtlasTaskMaestroBidCommand::EXIT_REFUSED, $r['exit']);
        self::assertStringContainsString('arbitrate_requires_envelope_or_bid_set', $r['output']);
    }

    public function test_history_without_provider_refuses(): void
    {
        $r = $this->runCmd(['action' => 'history']);
        self::assertSame(AtlasTaskMaestroBidCommand::EXIT_REFUSED, $r['exit']);
        self::assertStringContainsString('missing_provider', $r['output']);
    }

    public function test_unknown_action_refuses(): void
    {
        $r = $this->runCmd(['action' => 'bogus']);
        self::assertSame(AtlasTaskMaestroBidCommand::EXIT_REFUSED, $r['exit']);
        self::assertStringContainsString('unknown_action', $r['output']);
    }
}
