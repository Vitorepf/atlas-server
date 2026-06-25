<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Console\Commands\AtlasLoopFrozenContractsCommand;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Tests\TestCase;

final class AtlasLoopFrozenContractsCommandTest extends TestCase
{
    private function runCmd(array $args): array
    {
        $kernel = $this->app->make(ConsoleKernel::class);
        $exit = $kernel->call('atlas:loop:frozen:contracts', $args);

        return [$exit, $kernel->output()];
    }

    public function test_coverage_json_emits_the_contract_report_keys(): void
    {
        [$exit, $out] = $this->runCmd(['action' => 'coverage', '--json' => true]);

        $this->assertSame(AtlasLoopFrozenContractsCommand::EXIT_OK, $exit, $out);
        $decoded = json_decode(trim($out), true);
        $this->assertIsArray($decoded);
        foreach (['with_contract', 'without_contract', 'with_sentinel', 'orphan_contracts_without_sentinel'] as $key) {
            $this->assertArrayHasKey($key, $decoded, "coverage JSON missing key {$key}");
            $this->assertIsArray($decoded[$key]);
        }
    }

    public function test_retire_without_receipt_exits_non_zero_with_missing_operator_receipt(): void
    {
        config()->set('atlas.loop.frozen_contracts.retirement_gate_enabled', true);

        [$exit, $out] = $this->runCmd(['action' => 'retire', '--class' => 'Foo', '--json' => true]);

        $this->assertNotSame(AtlasLoopFrozenContractsCommand::EXIT_OK, $exit);
        $decoded = json_decode(trim($out), true);
        $this->assertFalse($decoded['allowed']);
        $this->assertSame('missing_operator_receipt', $decoded['reason']);
    }

    public function test_inspect_without_class_returns_usage_error(): void
    {
        [$exit] = $this->runCmd(['action' => 'inspect']);
        $this->assertSame(AtlasLoopFrozenContractsCommand::EXIT_USAGE, $exit);
    }
}
