<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Console\Commands\AtlasLoopIntentDriftCommand;
use App\Services\Ai\AutonomousEvolution\AtlasLoopMasterSwitch;
use App\Services\Ai\AutonomousEvolution\Quaternity\LoopIntentDrift\AtlasLoopAmbitionFacultyStore;
use App\Services\Ai\AutonomousEvolution\Quaternity\LoopIntentDrift\AtlasLoopAmbitionFacultyTarget;
use App\Services\Ai\AutonomousEvolution\Quaternity\LoopIntentDrift\AtlasLoopIntentDriftReceiptLedger;
use App\Services\Ai\AutonomousEvolution\Quaternity\LoopIntentDrift\AtlasLoopOperatorIntentSource;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Tests\TestCase;

final class AtlasLoopIntentDriftCommandTest extends TestCase
{
    private string $ledgerPath = '';

    private ?string $envFile = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ledgerPath = sys_get_temp_dir().'/atlas-drift-cli-'.bin2hex(random_bytes(6)).'.jsonl';
        $this->app->instance(AtlasLoopIntentDriftReceiptLedger::class, new AtlasLoopIntentDriftReceiptLedger($this->ledgerPath));
    }

    protected function tearDown(): void
    {
        @unlink($this->ledgerPath);
        AtlasLoopMasterSwitch::$envPathOverride = null;
        if ($this->envFile !== null) {
            @unlink($this->envFile);
        }
        parent::tearDown();
    }

    private function pinMasterSwitch(bool $on): void
    {
        $this->envFile = sys_get_temp_dir().'/atlas-drift-master-'.bin2hex(random_bytes(5)).'.env';
        file_put_contents($this->envFile, 'ATLAS_LOOP_MASTER_ENABLED='.($on ? 'true' : 'false')."\n");
        AtlasLoopMasterSwitch::$envPathOverride = $this->envFile;
    }

    private function bindFakeSource(array $intents): void
    {
        $this->app->instance(AtlasLoopOperatorIntentSource::class, new class($intents) implements AtlasLoopOperatorIntentSource
        {
            public function __construct(private readonly array $intents) {}

            public function recent(int $limit): array
            {
                return array_slice($this->intents, -$limit);
            }
        });
    }

    private function bindFakeStore(): object
    {
        $store = new class implements AtlasLoopAmbitionFacultyStore
        {
            public int $saveCalls = 0;

            public function current(): AtlasLoopAmbitionFacultyTarget
            {
                return new AtlasLoopAmbitionFacultyTarget(0.5, []);
            }

            public function save(AtlasLoopAmbitionFacultyTarget $target): void
            {
                $this->saveCalls++;
            }
        };
        $this->app->instance(AtlasLoopAmbitionFacultyStore::class, $store);

        return $store;
    }

    private function intentsWithDrift(): array
    {
        // 8 records with rising ambition values to produce a non-zero drift fact.
        $out = [];
        for ($i = 0; $i < 8; $i++) {
            $out[] = ['ambition' => 0.1 + 0.1 * $i];
        }

        return $out;
    }

    private function runCmd(array $args): array
    {
        $kernel = $this->app->make(ConsoleKernel::class);
        $exit = $kernel->call('atlas:loop:intent:drift', $args);

        return [$exit, $kernel->output()];
    }

    public function test_apply_gated_when_master_switch_off(): void
    {
        $this->pinMasterSwitch(false);
        config()->set('atlas.loop.quaternity.intent_drift.apply_enabled', true);
        $this->bindFakeSource($this->intentsWithDrift());
        $store = $this->bindFakeStore();

        [$exit, $out] = $this->runCmd(['action' => 'apply']);

        $this->assertSame(AtlasLoopIntentDriftCommand::EXIT_GATED, $exit);
        $this->assertStringContainsString(AtlasLoopIntentDriftCommand::GATED_MESSAGE, $out);
        $this->assertSame(0, $store->saveCalls, 'store must NEVER be saved when gated');

        $ledger = $this->app->make(AtlasLoopIntentDriftReceiptLedger::class);
        foreach ($ledger->all() as $row) {
            $this->assertFalse((bool) ($row->recalibration['applied'] ?? false), 'no applied=true receipts can be written when gated');
        }
    }

    public function test_apply_gated_when_apply_enabled_false(): void
    {
        $this->pinMasterSwitch(true);
        config()->set('atlas.loop.quaternity.intent_drift.apply_enabled', false);
        $this->bindFakeSource($this->intentsWithDrift());
        $store = $this->bindFakeStore();

        [$exit, $out] = $this->runCmd(['action' => 'apply']);

        $this->assertSame(AtlasLoopIntentDriftCommand::EXIT_GATED, $exit);
        $this->assertStringContainsString(AtlasLoopIntentDriftCommand::GATED_MESSAGE, $out);
        $this->assertSame(0, $store->saveCalls);
    }

    public function test_inspect_writes_exactly_one_receipt_with_applied_false(): void
    {
        $this->bindFakeSource($this->intentsWithDrift());
        $store = $this->bindFakeStore();

        [$exit, $out] = $this->runCmd(['action' => 'inspect', '--json' => true]);

        $this->assertSame(AtlasLoopIntentDriftCommand::EXIT_OK, $exit, $out);
        $decoded = json_decode(trim($out), true);
        $this->assertArrayHasKey('receipt_id', $decoded);
        $this->assertArrayHasKey('fact', $decoded);
        $this->assertArrayHasKey('recalibration', $decoded);

        $ledger = $this->app->make(AtlasLoopIntentDriftReceiptLedger::class);
        $rows = $ledger->all();
        $this->assertCount(1, $rows);
        $this->assertFalse((bool) ($rows[0]->recalibration['applied'] ?? true));
        $this->assertSame(0, $store->saveCalls, 'inspect must NEVER mutate the store');
    }
}
