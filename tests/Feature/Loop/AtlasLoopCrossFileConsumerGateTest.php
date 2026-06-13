<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class AtlasLoopCrossFileConsumerGateTest extends TestCase
{
    private ?string $workspace = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dropCodeGraphTables();
        (require database_path('migrations/2026_05_02_010000_create_atlas_engineering_code_intelligence_tables.php'))->up();
        (require database_path('migrations/2026_05_21_211200_create_atlas_engineering_code_file_snapshots_table.php'))->up();
        (require database_path('migrations/2026_06_08_233000_add_workspace_id_to_code_intelligence_tables.php'))->up();

        config([
            'atlas.loop.cross_file_consumer_gate.enabled' => true,
            'atlas.loop.cross_file_consumer_gate.schedule_enabled' => true,
            'atlas.loop.cross_file_consumer_gate.timeout_seconds' => 30,
            'atlas.loop.mutation_adequacy_gate.enabled' => false,
        ]);
    }

    protected function tearDown(): void
    {
        if ($this->workspace !== null && is_dir($this->workspace)) {
            (new Process(['rm', '-rf', $this->workspace], null, null, null, 30.0))->run();
        }
        $this->dropCodeGraphTables();

        parent::tearDown();
    }

    public function test_command_rejects_consumer_break_fixture_discovered_from_code_graph(): void
    {
        $exit = Artisan::call('atlas:loop:cross-file-consumer-gate', [
            '--fixture' => 'consumer-break',
            '--json' => true,
            '--strict' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exit, Artisan::output());
        $this->assertSame('atlas.loop.cross_file_consumer_gate.v1', $payload['schema_version']);
        $this->assertSame('consumer_contract_failed', $payload['status']);
        $this->assertFalse($payload['certified']);
        $this->assertTrue(data_get($payload, 'local_acceptance.passed'));
        $this->assertSame('atlas_engineering_code_file_snapshots', data_get($payload, 'code_graph.source'));
        $this->assertSame(1, $payload['consumer_contract_count']);
        $this->assertSame(1, $payload['consumer_contracts_failed']);
        $this->assertSame(['consumer_contract_failed'], $payload['blockers']);
        $this->assertSame(0, DB::table('atlas_engineering_code_symbols')->where('workspace_id', data_get($payload, 'code_graph.workspace_id'))->count());
    }

    public function test_command_accepts_safe_fixture_with_consumer_contract_green(): void
    {
        $exit = Artisan::call('atlas:loop:cross-file-consumer-gate', [
            '--fixture' => 'safe',
            '--json' => true,
            '--strict' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit, Artisan::output());
        $this->assertSame('consumer_contracts_passed', $payload['status']);
        $this->assertTrue($payload['certified']);
        $this->assertSame('atlas_engineering_code_file_snapshots', data_get($payload, 'code_graph.source'));
        $this->assertSame(1, $payload['consumer_contract_count']);
        $this->assertSame(1, $payload['consumer_contracts_passed']);
    }

    public function test_semantic_certifier_rejects_local_green_that_breaks_code_graph_consumer(): void
    {
        $workspaceId = 'l6-6-certifier-'.bin2hex(random_bytes(4));
        $this->workspace = $this->workspaceWithConsumerBreak();
        $this->seedCodeGraph($workspaceId);

        $acceptance = [
            'commands' => ['php tests/ProducerTest.php'],
            'allowed_globs' => ['src/**'],
            'frozen_globs' => ['tests/**'],
            'metric_kind' => 'gate',
            'revert_recheck' => true,
            'timeout_seconds' => 30,
        ];

        $exit = Artisan::call('atlas:loop:certify-implementation', [
            '--workspace' => $this->workspace,
            '--acceptance' => json_encode($acceptance, JSON_THROW_ON_ERROR),
            '--objective' => 'prove local green cannot break a code graph consumer',
            '--allowed-file' => ['src/Producer.php'],
            '--code-graph-workspace' => $workspaceId,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exit, Artisan::output());
        $this->assertFalse($payload['certified']);
        $this->assertTrue(data_get($payload, 'deterministic_gate.certified'), 'old local gate should pass so L6-6 proves the additional cross-file protection');
        $this->assertSame('consumer_contract_failed', data_get($payload, 'cross_file_consumer_gate.status'));
        $this->assertContains('cross_file_consumer_gate:consumer_contract_failed', $payload['reasons']);
        $this->assertSame(1, data_get($payload, 'evidence.cross_file_consumer_failures'));
        $this->assertSame('atlas_engineering_code_file_snapshots', data_get($payload, 'cross_file_consumer_gate.code_graph.source'));
    }

    public function test_schedule_contains_daily_cross_file_consumer_gate_fixture_proof(): void
    {
        Artisan::call('schedule:list');

        $this->assertStringContainsString('atlas:loop:cross-file-consumer-gate --fixture=safe --write-receipt --json', Artisan::output());
    }

    private function workspaceWithConsumerBreak(): string
    {
        $dir = sys_get_temp_dir().'/atlas-loop-cross-file-certifier-'.bin2hex(random_bytes(5));
        mkdir($dir.'/src', 0o755, true);
        mkdir($dir.'/tests', 0o755, true);
        file_put_contents($dir.'/src/Producer.php', $this->producer('old'));
        file_put_contents($dir.'/src/Consumer.php', $this->consumer());
        file_put_contents($dir.'/tests/ProducerTest.php', $this->producerTest('new'));
        file_put_contents($dir.'/tests/ConsumerContractTest.php', $this->consumerTest('old'));
        $this->runProcess(['git', 'init', '-q'], $dir);
        $this->runProcess(['git', 'config', 'user.email', 'atlas-loop@local'], $dir);
        $this->runProcess(['git', 'config', 'user.name', 'Atlas Loop'], $dir);
        $this->runProcess(['git', 'add', '-A'], $dir);
        $this->runProcess(['git', 'commit', '-q', '-m', 'baseline'], $dir);
        file_put_contents($dir.'/src/Producer.php', $this->producer('new'));

        return $dir;
    }

    private function seedCodeGraph(string $workspaceId): void
    {
        $now = now();
        DB::table('atlas_engineering_code_symbols')->insert([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspaceId,
            'module_id' => null,
            'symbol_type' => 'class',
            'symbol_name' => 'Producer',
            'file_path' => 'src/Producer.php',
            'line_start' => 3,
            'line_end' => 9,
            'language' => 'php',
            'signature' => 'final class Producer',
            'namespace' => null,
            'parent_symbol' => null,
            'visibility' => null,
            'status' => 'active',
            'docs_status' => 'fixture',
            'source_hash' => hash('sha256', $workspaceId.'|Producer'),
            'related_doc_ids_json' => json_encode([], JSON_THROW_ON_ERROR),
            'metadata' => json_encode(['fixture' => 'l6-6-cross-file-consumer'], JSON_THROW_ON_ERROR),
            'indexed_at' => $now,
            'archived_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('atlas_engineering_code_file_snapshots')->insert([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspaceId,
            'file_path' => 'tests/ConsumerContractTest.php',
            'module_slug' => 'tests',
            'language' => 'php',
            'source_hash' => hash('sha256', $workspaceId.'|consumer-contract'),
            'file_size' => 1,
            'symbols_json' => json_encode([], JSON_THROW_ON_ERROR),
            'relations_json' => json_encode([
                'test_targets' => [[
                    'kind' => 'test_symbol_reference_fixture',
                    'symbol' => 'Producer',
                    'target_module' => 'src',
                    'test_path' => 'tests/ConsumerContractTest.php',
                    'line' => 4,
                    'contract_command' => 'php tests/ConsumerContractTest.php',
                ]],
            ], JSON_THROW_ON_ERROR),
            'status' => 'active',
            'indexed_at' => $now,
            'archived_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function producer(string $value): string
    {
        return "<?php\n\nfinal class Producer\n{\n    public function value(): string\n    {\n        return '".$value."';\n    }\n}\n";
    }

    private function consumer(): string
    {
        return <<<'PHP'
<?php

require_once __DIR__.'/Producer.php';

final class Consumer
{
    public function label(): string
    {
        return (new Producer())->value();
    }
}
PHP;
    }

    private function producerTest(string $expected): string
    {
        return <<<PHP
<?php
require __DIR__.'/../src/Producer.php';
\$producer = new Producer();
exit(\$producer->value() === '{$expected}' ? 0 : 1);
PHP;
    }

    private function consumerTest(string $expected): string
    {
        return <<<PHP
<?php
require __DIR__.'/../src/Consumer.php';
\$consumer = new Consumer();
exit(\$consumer->label() === '{$expected}' ? 0 : 1);
PHP;
    }

    /**
     * @param  list<string>  $argv
     */
    private function runProcess(array $argv, string $cwd): void
    {
        $process = new Process($argv, $cwd, null, null, 30.0);
        $process->run();
        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput() ?: $process->getOutput());
    }

    private function dropCodeGraphTables(): void
    {
        foreach ([
            'atlas_engineering_doc_links',
            'atlas_engineering_code_symbols',
            'atlas_engineering_code_modules',
            'atlas_engineering_code_file_snapshots',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
}
