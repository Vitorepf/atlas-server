<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\Federation\AtlasLoopFederationFactSyncProtocol;
use App\Services\Ai\AutonomousEvolution\Federation\AtlasLoopFederationIsolationGuard;
use App\Services\Ai\AutonomousEvolution\Federation\AtlasLoopFederationPeerRegistry;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class AtlasLoopFederationCommandTest extends TestCase
{
    private string $base = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->base = sys_get_temp_dir().'/atlas-federation-cli-'.bin2hex(random_bytes(4));
        @mkdir($this->base, 0o755, true);

        $registry = new AtlasLoopFederationPeerRegistry($this->base.'/peers.json');
        $protocol = new AtlasLoopFederationFactSyncProtocol($registry, $this->base.'/outbox.ndjson', $this->base.'/seen.txt');
        $guard = new AtlasLoopFederationIsolationGuard();

        app()->instance(AtlasLoopFederationPeerRegistry::class, $registry);
        app()->instance(AtlasLoopFederationFactSyncProtocol::class, $protocol);
        app()->instance(AtlasLoopFederationIsolationGuard::class, $guard);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->base.'/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->base);
        parent::tearDown();
    }

    private function runCmd(array $params): array
    {
        $buf = new BufferedOutput();
        /** @var \Illuminate\Contracts\Console\Kernel $kernel */
        $kernel = app(\Illuminate\Contracts\Console\Kernel::class);
        $exit = $kernel->call('atlas:loop:federation', $params, $buf);

        return ['exit' => $exit, 'output' => trim($buf->fetch())];
    }

    public function test_peer_register_then_status_includes_the_registered_peer(): void
    {
        $r1 = $this->runCmd([
            'action' => 'peer-register',
            '--peer-id' => 'alpha',
            '--scope' => 'atlas-desktop',
            '--endpoint' => 'file:///tmp/x',
            '--axes' => 'fact.merge_certified,fact.replenisher',
        ]);
        self::assertSame(0, $r1['exit']);

        $r2 = $this->runCmd(['action' => 'status', '--json' => true]);
        self::assertSame(0, $r2['exit']);
        $payload = json_decode($r2['output'], true);
        self::assertIsArray($payload);
        $peers = array_column($payload['peers'], 'peer_id');
        self::assertContains('alpha', $peers);
        $alpha = $payload['peers'][array_search('alpha', $peers, true)];
        self::assertSame('atlas-desktop', $alpha['scope']);
        self::assertSame('file:///tmp/x', $alpha['endpoint']);
        self::assertContains('fact.merge_certified', $alpha['capability_axes']);
        self::assertNotEmpty((string) $alpha['last_seen_utc']);
    }

    public function test_sync_round_trips_good_fact_and_quarantines_malformed_envelope(): void
    {
        $this->runCmd([
            'action' => 'peer-register',
            '--peer-id' => 'alpha',
            '--scope' => 'atlas-desktop',
            '--endpoint' => 'file:///tmp/x',
            '--axes' => 'fact.x',
        ]);

        $fact = json_encode(['fact_id' => 'f1', 'fact_kind' => 'fact.x', 'payload' => ['k' => 'v']]);
        $malformed = json_encode(['peer_id' => 'alpha', 'fact_kind' => 'fact.x']); // missing required fields

        $sync = $this->runCmd([
            'action' => 'sync',
            '--peer-id' => 'alpha',
            '--fact' => $fact,
            '--malformed-envelope' => $malformed,
            '--json' => true,
        ]);
        self::assertSame(0, $sync['exit'], 'sync exit code must be 0 (isolation guard prevents failure propagation): '.$sync['output']);

        $status = $this->runCmd(['action' => 'status', '--json' => true]);
        $payload = json_decode($status['output'], true);
        self::assertNotEmpty($payload['quarantine'], 'malformed envelope must be quarantined');
    }

    public function test_evaluate_scope_rejects_loop_core_overlap(): void
    {
        $proposal = json_encode([
            'scope_id' => 'newscope',
            'namespace' => 'App\\Services\\Ai\\AutonomousEvolution\\NewScope',
            'operator_intent' => ['evolve the loop'],
            'root' => 'app/Services/Ai/AutonomousEvolution/NewScope',
            'operator_receipt' => 'op-receipt-1',
        ]);

        $r = $this->runCmd(['action' => 'evaluate-scope', '--scope-proposal' => $proposal, '--json' => true]);

        self::assertSame(0, $r['exit']);
        $payload = json_decode($r['output'], true);
        self::assertFalse($payload['admitted'], 'a loop-core root is never admissible');
        self::assertContains('ScopeOverlapsLoopCore', $payload['blocking_reasons']);
        self::assertTrue($payload['requires_operator_receipt']);
    }

    public function test_evaluate_scope_admits_complete_non_core_proposal_with_receipt(): void
    {
        $proposal = json_encode([
            'scope_id' => 'marketing',
            'namespace' => 'App\\Services\\Ai\\Marketing',
            'operator_intent' => ['grow the marketing capability'],
            'root' => 'app/Services/Ai/Marketing',
            'operator_receipt' => 'op-receipt-123',
        ]);

        $r = $this->runCmd(['action' => 'evaluate-scope', '--scope-proposal' => $proposal, '--json' => true]);

        self::assertSame(0, $r['exit']);
        $payload = json_decode($r['output'], true);
        self::assertTrue($payload['admitted'], 'complete non-loop-core proposal with a receipt is admitted: '.$r['output']);
        self::assertSame([], $payload['blocking_reasons']);
    }

    public function test_unknown_action_fails_with_usage(): void
    {
        $r = $this->runCmd(['action' => 'bogus']);

        self::assertNotSame(0, $r['exit']);
        self::assertStringContainsString('unknown_action', $r['output']);
    }
}
