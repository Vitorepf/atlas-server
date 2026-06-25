<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\WireFormat\AtlasLoopInterPrimitiveMessageReceiptLedger;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class AtlasLoopWireCommandTest extends TestCase
{
    private function runCmd(array $params): array
    {
        $buf = new BufferedOutput();
        /** @var \Illuminate\Contracts\Console\Kernel $kernel */
        $kernel = app(\Illuminate\Contracts\Console\Kernel::class);
        $exit = $kernel->call('atlas:loop:wire', $params, $buf);

        return ['exit' => $exit, 'output' => $buf->fetch()];
    }

    public function test_schemas_subcommand_lists_all_five_canonical_schema_ids(): void
    {
        $r = $this->runCmd(['action' => 'schemas']);

        self::assertSame(0, $r['exit']);
        foreach ([
            'loop.cortex.snapshot.v1',
            'cortex.maestro.fact.v1',
            'maestro.loop.outcome.v1',
            'loop.cortex.scope_comprehension.v1',
            'cortex.loop.origination_seed.v1',
        ] as $id) {
            self::assertStringContainsString($id, $r['output']);
        }
        // required fields shown per schema id
        self::assertStringContainsString('snapshot_id', $r['output']);
        self::assertStringContainsString('fact_id', $r['output']);
    }

    public function test_schemas_subcommand_json_payload_carries_required_fields(): void
    {
        $r = $this->runCmd(['action' => 'schemas', '--json' => true]);

        self::assertSame(0, $r['exit']);
        $lines = array_values(array_filter(explode("\n", trim($r['output']))));
        $payload = json_decode($lines[0], true);
        self::assertIsArray($payload);
        $ids = array_column($payload['schemas'], 'id');
        self::assertContains('loop.cortex.snapshot.v1', $ids);
        $snapshot = $payload['schemas'][array_search('loop.cortex.snapshot.v1', $ids, true)];
        self::assertContains('snapshot_id', $snapshot['required_fields']);
    }

    public function test_validate_with_valid_payload_exits_zero(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'atlas-wire-payload-');
        file_put_contents($tmp, json_encode([
            'schema_id' => 'loop.cortex.snapshot.v1',
            'snapshot_id' => 'snap-1',
            'scope_root' => 'atlas-server',
            'built_at_unix' => 1700000000,
            'inventory' => ['count' => 10],
            'edges' => [],
            'orphans' => [],
        ]));

        $r = $this->runCmd([
            'action' => 'validate',
            '--schema' => 'loop.cortex.snapshot.v1',
            '--payload' => $tmp,
            '--json' => true,
        ]);

        @unlink($tmp);
        self::assertSame(0, $r['exit'], 'valid payload must exit 0; got output: '.$r['output']);
        $payload = json_decode(trim($r['output']), true);
        self::assertIsArray($payload);
        self::assertTrue($payload['validation_ok']);
    }

    public function test_validate_with_missing_required_field_exits_non_zero_and_names_the_field(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'atlas-wire-payload-');
        file_put_contents($tmp, json_encode([
            'schema_id' => 'loop.cortex.snapshot.v1',
            // snapshot_id intentionally missing
            'scope_root' => 'atlas-server',
            'built_at_unix' => 1700000000,
            'inventory' => ['count' => 10],
            'edges' => [],
            'orphans' => [],
        ]));

        $r = $this->runCmd([
            'action' => 'validate',
            '--schema' => 'loop.cortex.snapshot.v1',
            '--payload' => $tmp,
        ]);

        @unlink($tmp);
        self::assertNotSame(0, $r['exit']);
        self::assertStringContainsString('snapshot_id', $r['output']);
    }

    public function test_history_returns_seeded_receipts_for_source_loop(): void
    {
        /** @var AtlasLoopInterPrimitiveMessageReceiptLedger $ledger */
        $ledger = app(AtlasLoopInterPrimitiveMessageReceiptLedger::class);
        $now = time();
        for ($i = 1; $i <= 7; $i++) {
            $ledger->append([
                'source' => 'loop',
                'target' => 'cortex',
                'schema_id' => 'loop.cortex.snapshot.v1',
                'payload_hash' => 'h_'.$i,
                'validation_ok' => true,
                'observed_at' => $now + $i,
            ]);
        }
        $ledger->append([
            'source' => 'maestro',
            'target' => 'loop',
            'schema_id' => 'maestro.loop.outcome.v1',
            'payload_hash' => 'h_x',
            'validation_ok' => true,
            'observed_at' => $now + 99,
        ]);

        $r = $this->runCmd(['action' => 'history', '--source' => 'loop', '--limit' => 5, '--json' => true]);

        self::assertSame(0, $r['exit']);
        $payload = json_decode(trim($r['output']), true);
        self::assertIsArray($payload);
        self::assertCount(5, $payload['receipts']);
        foreach ($payload['receipts'] as $row) {
            self::assertSame('loop', $row['source']);
        }
    }

    public function test_unknown_action_exits_non_zero_with_usage_message(): void
    {
        $r = $this->runCmd(['action' => 'bogus']);

        self::assertNotSame(0, $r['exit']);
        self::assertStringContainsString('unknown_action', $r['output']);
    }

    protected function setUp(): void
    {
        parent::setUp();
        // Bind a fresh in-memory ledger per test so seeded history is isolated.
        app()->instance(AtlasLoopInterPrimitiveMessageReceiptLedger::class, new AtlasLoopInterPrimitiveMessageReceiptLedger());
    }
}
