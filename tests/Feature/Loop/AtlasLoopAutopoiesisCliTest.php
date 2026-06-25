<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Console\Commands\AtlasLoopAutopoiesisCli;
use App\Services\Ai\AutonomousEvolution\AtlasLoopMasterSwitch;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Tests\TestCase;

final class AtlasLoopAutopoiesisCliTest extends TestCase
{
    private string $ledgerPath = '';

    private ?string $envFile = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ledgerPath = sys_get_temp_dir().'/atlas-autopoiesis-cli-'.bin2hex(random_bytes(6)).'.jsonl';
        $this->app->instance(AtlasLoopAutopoiesisCli::RECEIPT_LEDGER_PATH_KEY, $this->ledgerPath);
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
        $this->envFile = sys_get_temp_dir().'/atlas-autop-master-'.bin2hex(random_bytes(5)).'.env';
        file_put_contents($this->envFile, 'ATLAS_LOOP_MASTER_ENABLED='.($on ? 'true' : 'false')."\n");
        AtlasLoopMasterSwitch::$envPathOverride = $this->envFile;
    }

    private function runCmd(array $args): array
    {
        $kernel = $this->app->make(ConsoleKernel::class);
        $exit = $kernel->call('atlas:loop:autopoiesis', $args);

        return [$exit, $kernel->output()];
    }

    public function test_status_with_master_switch_off_succeeds_read_only(): void
    {
        $this->pinMasterSwitch(false);
        // seed a couple receipts so status has rows to print.
        file_put_contents($this->ledgerPath, json_encode(['verdict' => 'approved', 'actor' => 'operator', 'fact_refs' => [['id' => 'f1']]]).PHP_EOL);

        [$exit, $out] = $this->runCmd(['action' => 'status']);

        $this->assertSame(AtlasLoopAutopoiesisCli::EXIT_OK, $exit, $out);
        $this->assertStringContainsString('verdict=approved', $out);
    }

    public function test_propose_with_master_switch_off_refuses_with_non_zero_exit_and_master_message(): void
    {
        $this->pinMasterSwitch(false);

        [$exit, $out] = $this->runCmd(['action' => 'propose']);

        $this->assertNotSame(AtlasLoopAutopoiesisCli::EXIT_OK, $exit);
        $this->assertStringContainsString('ATLAS_LOOP_MASTER_ENABLED', $out);
    }

    public function test_approve_with_master_switch_off_refuses_with_non_zero_exit_and_master_message(): void
    {
        $this->pinMasterSwitch(false);

        [$exit, $out] = $this->runCmd(['action' => 'approve', '--proposal' => 'unknown']);

        $this->assertNotSame(AtlasLoopAutopoiesisCli::EXIT_OK, $exit);
        $this->assertStringContainsString('ATLAS_LOOP_MASTER_ENABLED', $out);
    }

    public function test_propose_with_no_facts_in_snapshot_abstains_with_non_zero_exit(): void
    {
        $this->pinMasterSwitch(true);

        [$exit, $out] = $this->runCmd(['action' => 'propose']);

        $this->assertNotSame(AtlasLoopAutopoiesisCli::EXIT_OK, $exit, 'must abstain, not fabricate');
        $this->assertStringContainsString('abstain', $out);
        $this->assertStringNotContainsString('proposal=', $out, 'NEVER prints a fabricated proposal');
    }

    public function test_approve_unknown_proposal_exits_non_zero_and_leaves_ledger_byte_identical(): void
    {
        $this->pinMasterSwitch(true);
        file_put_contents($this->ledgerPath, json_encode(['verdict' => 'approved', 'actor' => 'operator', 'fact_refs' => []]).PHP_EOL);
        $beforeHash = sha1((string) file_get_contents($this->ledgerPath));

        [$exit, $out] = $this->runCmd(['action' => 'approve', '--proposal' => 'unknownhash']);

        $this->assertNotSame(AtlasLoopAutopoiesisCli::EXIT_OK, $exit);
        $this->assertStringContainsString('unknown_proposal', $out);
        $afterHash = sha1((string) file_get_contents($this->ledgerPath));
        $this->assertSame($beforeHash, $afterHash, 'ledger must be byte-identical after a failed approve');
    }
}
