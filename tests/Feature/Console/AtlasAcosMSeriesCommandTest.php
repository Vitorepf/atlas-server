<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Console\Commands\AtlasAcosFreezeCommand;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

final class AtlasAcosMSeriesCommandTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = storage_path('framework/testing/elev02-m-series-'.bin2hex(random_bytes(4)));
        @mkdir($this->root, 0775, true);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->root);

        parent::tearDown();
    }

    public function test_elev02_freeze_payload_records_formula_hash_and_independent_judge(): void
    {
        $freezePath = $this->root.'/freeze.jsonl';
        $payload = AtlasAcosFreezeCommand::asiMetricMFreezePayload();

        $output = new BufferedOutput;
        $exit = Artisan::call('atlas:acos:freeze', [
            '--json' => json_encode($payload, JSON_THROW_ON_ERROR),
            '--path' => $freezePath,
        ], $output);

        $this->assertSame(0, $exit);

        $result = json_decode(trim($output->fetch()), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('asi.metric.m.v1', $result['measure_id']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $result['content_hash']);

        $row = json_decode(trim((string) file_get_contents($freezePath)), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('asi_metric_m.v1', $row['formula_version']);
        $this->assertSame('cursor-acos-max-elev-02', $row['author_engine_id']);
        $this->assertSame('codex-independent-measure-judge', $row['judge_engine_id']);
        $this->assertTrue($row['judge_author_distinct']);
        $this->assertSame(8, $row['denominator_min']);
        $this->assertSame($result['content_hash'], $row['content_hash']);

        $seriesPath = $this->root.'/empty-input.jsonl';
        touch($seriesPath);
        $series = $this->callMSeries($seriesPath, $freezePath);
        $this->assertSame($result['content_hash'], $series['freeze']['content_hash']);
    }

    public function test_m_series_reports_ratio_by_window_and_mode_with_raw_denominators(): void
    {
        $seriesPath = $this->root.'/m-input.jsonl';
        $this->appendJsonl($seriesPath, [
            [
                'window' => '2026-W28',
                'mode' => 'interactive',
                'arm' => 'acos',
                'turns' => 10,
                'used_ratio_measured' => 0.8,
                'post_execution_utility' => 75,
                'green_run_pass_rate' => 0.9,
                'rework_avoided' => 2,
            ],
            [
                'window' => '2026-W28',
                'mode' => 'interactive',
                'arm' => 'raw',
                'turns' => 10,
                'used_ratio_measured' => 0.0,
                'post_execution_utility' => 0,
                'green_run_pass_rate' => 0.5,
                'rework_avoided' => 0,
            ],
            [
                'window' => '2026-W28',
                'mode' => 'delegated',
                'arm' => 'acos',
                'turns' => 8,
                'used_ratio_measured' => 0.5,
                'post_execution_utility' => 80,
                'green_run_pass_rate' => 0.75,
                'rework_avoided' => 1,
            ],
            [
                'window' => '2026-W28',
                'mode' => 'delegated',
                'arm' => 'raw',
                'turns' => 8,
                'used_ratio_measured' => 0.0,
                'post_execution_utility' => 0,
                'green_run_pass_rate' => 0.5,
                'rework_avoided' => 0,
            ],
        ]);

        $payload = $this->callMSeries($seriesPath);

        $this->assertSame('ok', $payload['status']);
        $this->assertSame('asi.metric.m.v1', $payload['measure_id']);
        $this->assertSame('asi_metric_m.v1', $payload['formula_version']);

        $interactive = $payload['windows'][0];
        $this->assertSame('2026-W28', $interactive['window']);
        $this->assertSame('interactive', $interactive['mode']);
        $this->assertSame(3.4, $interactive['m_ratio']);
        $this->assertSame(1.7, $interactive['acos']['value_per_turn']);
        $this->assertSame(0.5, $interactive['raw']['value_per_turn']);
        $this->assertSame(10, $interactive['denominators']['acos']['turns']);
        $this->assertSame(10, $interactive['denominators']['raw']['turns']);

        $delegated = $payload['windows'][1];
        $this->assertSame('delegated', $delegated['mode']);
        $this->assertSame(2.55, $delegated['m_ratio']);
    }

    public function test_m_series_is_insufficient_signal_when_any_arm_misses_denominator_min(): void
    {
        $seriesPath = $this->root.'/thin-input.jsonl';
        $this->appendJsonl($seriesPath, [
            [
                'window' => '2026-W28',
                'mode' => 'interactive',
                'arm' => 'acos',
                'turns' => 8,
                'used_ratio_measured' => 0.9,
                'post_execution_utility' => 90,
                'green_run_pass_rate' => 1.0,
                'rework_avoided' => 2,
            ],
            [
                'window' => '2026-W28',
                'mode' => 'interactive',
                'arm' => 'raw',
                'turns' => 7,
                'used_ratio_measured' => 0.0,
                'post_execution_utility' => 0,
                'green_run_pass_rate' => 0.1,
                'rework_avoided' => 0,
            ],
        ]);

        $payload = $this->callMSeries($seriesPath);

        $this->assertSame('insufficient_signal', $payload['status']);
        $this->assertSame('insufficient_signal', $payload['windows'][0]['status']);
        $this->assertSame('denominator_below_min', $payload['windows'][0]['reason']);
        $this->assertArrayNotHasKey('m_ratio', $payload['windows'][0]);
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
    private function callMSeries(string $seriesPath, ?string $freezePath = null): array
    {
        $output = new BufferedOutput;
        $arguments = [
            '--series' => $seriesPath,
            '--json' => true,
        ];
        if ($freezePath !== null) {
            $arguments['--freeze'] = $freezePath;
        }
        $exit = Artisan::call('atlas:acos:m-series', $arguments, $output);

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
