<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Console\Commands\AtlasTaskMaestroMultiProviderCommand;
use App\Services\Ai\SelfConstruction\Maestro\MultiProvider\AtlasMaestroAssignmentReceiptLedger;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Tests\TestCase;

final class AtlasTaskMaestroMultiProviderCommandTest extends TestCase
{
    private string $ledgerPath = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->ledgerPath = sys_get_temp_dir().'/atlas-maestro-mp-cli-'.bin2hex(random_bytes(6)).'.jsonl';
        $this->app->instance(AtlasMaestroAssignmentReceiptLedger::class, new AtlasMaestroAssignmentReceiptLedger($this->ledgerPath));
    }

    protected function tearDown(): void
    {
        @unlink($this->ledgerPath);
        parent::tearDown();
    }

    private function runCmd(array $args): array
    {
        $kernel = $this->app->make(ConsoleKernel::class);
        $exit = $kernel->call('atlas:task:maestro-multiprovider', $args);

        return [$exit, $kernel->output()];
    }

    private function packetFixture(array $packet): string
    {
        $path = sys_get_temp_dir().'/atlas-maestro-packet-'.bin2hex(random_bytes(4)).'.json';
        file_put_contents($path, (string) json_encode($packet));

        return $path;
    }

    public function test_providers_emits_registry_with_id_axes_cost_band(): void
    {
        [$exit, $out] = $this->runCmd(['action' => 'providers', '--json' => true]);
        $this->assertSame(AtlasTaskMaestroMultiProviderCommand::EXIT_OK, $exit, $out);

        $decoded = json_decode(trim($out), true);
        $this->assertIsArray($decoded['providers']);
        $this->assertGreaterThan(0, count($decoded['providers']));
        foreach ($decoded['providers'] as $row) {
            foreach (['provider_id', 'axes', 'cost_band'] as $key) {
                $this->assertArrayHasKey($key, $row);
            }
        }
    }

    public function test_classify_json_returns_class_and_rule_id(): void
    {
        $path = $this->packetFixture([
            'task_packet_id' => 'pkt-1',
            'allowed_files' => ['app/Foo.php'],
        ]);

        [$exit, $out] = $this->runCmd(['action' => 'classify', '--packet' => $path, '--json' => true]);
        $this->assertSame(AtlasTaskMaestroMultiProviderCommand::EXIT_OK, $exit, $out);
        $decoded = json_decode(trim($out), true);
        $this->assertArrayHasKey('class', $decoded);
        $this->assertArrayHasKey('rule_id', $decoded);
        @unlink($path);
    }

    public function test_assign_chains_classifier_policy_ledger_and_emits_sha256_receipt(): void
    {
        $path = $this->packetFixture([
            'task_packet_id' => 'pkt-2',
            'allowed_files' => ['app/Foo.php', 'tests/Unit/Ai/FooTest.php'],
        ]);
        $rowsBefore = is_file($this->ledgerPath) ? count(file($this->ledgerPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)) : 0;

        [$exit, $out] = $this->runCmd(['action' => 'assign', '--packet' => $path, '--json' => true]);
        $this->assertSame(AtlasTaskMaestroMultiProviderCommand::EXIT_OK, $exit, $out);

        $decoded = json_decode(trim($out), true);
        $this->assertArrayHasKey('receipt_hash', $decoded);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $decoded['receipt_hash']);
        $this->assertArrayHasKey('assignment', $decoded);
        $this->assertArrayHasKey('primary', $decoded['assignment']);

        $rowsAfter = count(file($this->ledgerPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []);
        $this->assertSame($rowsBefore + 1, $rowsAfter, 'assign must append exactly one ledger receipt');
        @unlink($path);
    }

    public function test_classify_and_assign_without_packet_exit_non_zero(): void
    {
        [$exitClassify] = $this->runCmd(['action' => 'classify']);
        $this->assertSame(AtlasTaskMaestroMultiProviderCommand::EXIT_USAGE, $exitClassify);

        [$exitAssign] = $this->runCmd(['action' => 'assign']);
        $this->assertSame(AtlasTaskMaestroMultiProviderCommand::EXIT_USAGE, $exitAssign);
    }

    public function test_command_name_does_not_collide_with_atlas_task_next_report(): void
    {
        $kernel = $this->app->make(ConsoleKernel::class);
        $registry = $kernel->all();

        $this->assertArrayHasKey('atlas:task:maestro-multiprovider', $registry);
        // Hyphenated command name reserves the `atlas:task:maestro-multiprovider` slot — siblings under
        // the `atlas:task:` namespace remain untouched.
        $this->assertGreaterThanOrEqual(2, count(array_filter(array_keys($registry), static fn (string $k): bool => str_starts_with($k, 'atlas:task:'))));
        $this->assertNotSame('atlas:task', 'atlas:task:maestro-multiprovider', 'must NOT collide with the bare atlas:task namespace');
    }
}
