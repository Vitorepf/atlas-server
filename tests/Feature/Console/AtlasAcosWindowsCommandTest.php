<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

final class AtlasAcosWindowsCommandTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = storage_path('framework/testing/acos-windows-'.bin2hex(random_bytes(4)));
        @mkdir($this->root, 0775, true);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->root);

        parent::tearDown();
    }

    public function test_windows_command_reports_parallel_windows_critical_path_and_dead_window_watchdog(): void
    {
        $ledger = $this->root.'/flips.jsonl';
        $protocol = $this->root.'/protocol.json';
        $registry = $this->root.'/registry.json';
        $series = $this->root.'/series.jsonl';
        touch($series);

        file_put_contents($protocol, json_encode([
            [
                'id' => 'flag.alpha',
                'family' => 'ALPHA',
                'slice' => 'ALPHA-01',
                'state' => 'off',
                'shadow_minimum_window' => '7d',
                'flip_criterion' => 'fixture',
                'rollback_trigger' => 'fixture',
                'judge_engine_id' => 'fixture-judge',
                'receipt' => 'fixture',
            ],
            [
                'id' => 'flag.beta',
                'family' => 'BETA',
                'slice' => 'BETA-01',
                'state' => 'off',
                'shadow_minimum_window' => '2d',
                'flip_criterion' => 'fixture',
                'rollback_trigger' => 'fixture',
                'judge_engine_id' => 'fixture-judge',
                'receipt' => 'fixture',
            ],
            [
                'id' => 'flag.gamma',
                'family' => 'GAMMA',
                'slice' => 'GAMMA-01',
                'state' => 'off',
                'shadow_minimum_window' => '3d',
                'flip_criterion' => 'fixture',
                'rollback_trigger' => 'fixture',
                'judge_engine_id' => 'fixture-judge',
                'receipt' => 'fixture',
            ],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        $this->appendJsonl($ledger, [
            [
                'schema_version' => 'atlas.acos.promotion_protocol.v1',
                'recorded_at' => '2026-07-01T00:00:00+00:00',
                'action' => 'flip',
                'flag_id' => 'flag.alpha',
                'family' => 'ALPHA',
                'slice' => 'ALPHA-01',
                'from_state' => 'off',
                'to_state' => 'shadow',
                'observation_window_id' => 'alpha-window',
            ],
            [
                'schema_version' => 'atlas.acos.promotion_protocol.v1',
                'recorded_at' => '2026-07-03T00:00:00+00:00',
                'action' => 'flip',
                'flag_id' => 'flag.beta',
                'family' => 'BETA',
                'slice' => 'BETA-01',
                'from_state' => 'off',
                'to_state' => 'shadow',
                'observation_window_id' => 'beta-window',
            ],
        ]);
        file_put_contents($registry, json_encode([
            [
                'slice' => 'ALPHA-01',
                'series' => 'alpha.series.v1',
                'path' => $series,
                'source_type' => 'jsonl',
                'timestamp_field' => 'recorded_at',
                'ttl_days' => 30,
                'ttl_source' => 'fixture',
            ],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        $payload = $this->callWindows($ledger, $protocol, $registry);

        $this->assertSame('atlas.acos.windows.v1', $payload['schema_version']);
        $this->assertSame('ok', $payload['status']);
        $this->assertSame('flag.alpha', data_get($payload, 'critical_path.nodes.0.flag_id'));
        $this->assertSame(3, data_get($payload, 'critical_path.days_remaining'));
        $this->assertSame(['flag.alpha', 'flag.beta'], data_get($payload, 'parallelizable_groups.0'));
        $this->assertSame('dead_window', data_get($payload, 'watchdog.alerts.0.status'));
        $this->assertSame('flag.alpha', data_get($payload, 'watchdog.alerts.0.flag_id'));

        $notStarted = collect($payload['windows'])->firstWhere('flag_id', 'flag.gamma');
        $this->assertSame('not_started', $notStarted['state'] ?? null);
        $this->assertArrayNotHasKey('eta_at', $notStarted);
        $this->assertNull($notStarted['days_remaining'] ?? null);
    }

    /**
     * @param  list<array<string,mixed>>  $rows
     */
    private function appendJsonl(string $path, array $rows): void
    {
        foreach ($rows as $row) {
            file_put_contents(
                $path,
                json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES).PHP_EOL,
                FILE_APPEND,
            );
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function callWindows(string $ledger, string $protocol, string $registry): array
    {
        $output = new BufferedOutput;
        $exit = Artisan::call('atlas:windows', [
            '--promotion-ledger' => $ledger,
            '--protocol' => $protocol,
            '--registry' => $registry,
            '--now' => '2026-07-05T00:00:00+00:00',
            '--dead-after-days' => 2,
            '--json' => true,
        ], $output);

        $this->assertSame(0, $exit);

        return json_decode(trim($output->fetch()), true, flags: JSON_THROW_ON_ERROR);
    }

    private function deleteDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        foreach (array_diff(scandir($dir) ?: [], ['.', '..']) as $entry) {
            $path = $dir.DIRECTORY_SEPARATOR.$entry;
            is_dir($path) ? $this->deleteDirectory($path) : @unlink($path);
        }

        @rmdir($dir);
    }
}
