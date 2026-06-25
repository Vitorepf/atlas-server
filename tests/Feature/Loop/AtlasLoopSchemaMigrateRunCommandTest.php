<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Console\Commands\AtlasLoopSchemaMigrateRunCommand;
use App\Services\Ai\AutonomousEvolution\AtlasLoopMasterSwitch;
use App\Services\Ai\AutonomousEvolution\Migration\Runner\AtlasLoopSchemaMigrationExecutableRunner;
use App\Services\Ai\AutonomousEvolution\Migration\Runner\AtlasLoopSchemaMigrationExecutionReceiptLedger;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class AtlasLoopSchemaMigrateRunCommandTest extends TestCase
{
    private string $ledgerPath = '';

    private string $envPath = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->ledgerPath = sys_get_temp_dir().'/atlas_migrate_ledger_'.bin2hex(random_bytes(6)).'.jsonl';
        $this->envPath = sys_get_temp_dir().'/atlas_migrate_env_'.bin2hex(random_bytes(6));
        config(['atlas.loop.migration_ledger_path' => $this->ledgerPath]);
        AtlasLoopMasterSwitch::$envPathOverride = $this->envPath;

        $this->app->singleton(
            AtlasLoopSchemaMigrationExecutionReceiptLedger::class,
            fn () => new AtlasLoopSchemaMigrationExecutionReceiptLedger($this->ledgerPath),
        );
    }

    protected function tearDown(): void
    {
        @unlink($this->ledgerPath);
        @unlink($this->envPath);
        AtlasLoopMasterSwitch::$envPathOverride = null;
        parent::tearDown();
    }

    private function seedReceipt(string $id): void
    {
        $row = [
            'receipt_id' => $id,
            'step_id' => 'step-'.$id,
            'action' => 'apply',
            'checkpoint_id' => 'cp-'.$id,
            'pre_sha256_manifest' => '',
            'post_sha256_manifest' => str_repeat('0', 64),
            'started_at' => '2026-01-01T00:00:00+00:00',
            'finished_at' => '2026-01-01T00:00:01+00:00',
            'status' => 'applied',
        ];
        file_put_contents($this->ledgerPath, json_encode($row)."\n", FILE_APPEND);
    }

    public function test_history_emits_last_3_receipts_in_stable_order(): void
    {
        foreach (['r1', 'r2', 'r3', 'r4', 'r5'] as $id) {
            $this->seedReceipt($id);
        }

        $exit = Artisan::call('atlas:loop:migrate:run', ['action' => 'history', '--limit' => 3]);
        $this->assertSame(0, $exit);

        $payload = json_decode(trim(Artisan::output()), true);
        $this->assertIsArray($payload);
        $this->assertSame('history', $payload['action']);
        $this->assertSame(3, $payload['count']);
        $ids = array_map(static fn (array $r): string => (string) $r['receipt_id'], $payload['receipts']);
        $this->assertSame(['r3', 'r4', 'r5'], $ids);
    }

    public function test_apply_refuses_with_master_off_exit_code_2(): void
    {
        config(['atlas.loop.master_enabled' => false]);

        $invocations = 0;
        $this->app->instance(AtlasLoopSchemaMigrateRunCommand::STEP_RESOLVER_BINDING, function (string $id) use (&$invocations) {
            $invocations++;

            return $this->fakeStep('fake-step');
        });

        $exit = Artisan::call('atlas:loop:migrate:run', ['action' => 'apply', '--step' => 'fake-step']);
        $this->assertSame(2, $exit);
        $this->assertStringContainsString('master_off', Artisan::output());
        $this->assertSame(0, $invocations, 'step resolver must not be touched when master is off');
        $this->assertFileDoesNotExist($this->ledgerPath);
    }

    public function test_apply_invokes_runner_exactly_once_and_writes_one_receipt(): void
    {
        config(['atlas.loop.master_enabled' => true]);
        file_put_contents($this->envPath, 'ATLAS_LOOP_MASTER_ENABLED=true'.PHP_EOL);

        $resolveCount = 0;
        $this->app->instance(AtlasLoopSchemaMigrateRunCommand::STEP_RESOLVER_BINDING, function (string $id) use (&$resolveCount) {
            $resolveCount++;

            return $this->fakeStep('fake-step');
        });

        $exit = Artisan::call('atlas:loop:migrate:run', ['action' => 'apply', '--step' => 'fake-step']);
        $this->assertSame(0, $exit);
        $this->assertSame(1, $resolveCount);

        $this->assertFileExists($this->ledgerPath);
        $lines = array_values(array_filter(explode("\n", (string) file_get_contents($this->ledgerPath))));
        $this->assertCount(1, $lines, 'exactly one apply receipt must be persisted');
        $row = json_decode($lines[0], true);
        $this->assertSame('apply', $row['action']);
        $this->assertSame('fake-step', $row['step_id']);
        $this->assertSame('applied', $row['status']);
    }

    private function fakeStep(string $id): object
    {
        return new class($id)
        {
            public function __construct(public string $id) {}

            public function stepId(): string
            {
                return $this->id;
            }

            public function targets(): array
            {
                return [];
            }

            public function apply(array $checkpoint): array
            {
                return ['status' => 'applied'];
            }
        };
    }
}
