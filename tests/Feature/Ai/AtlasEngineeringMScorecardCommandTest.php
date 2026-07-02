<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class AtlasEngineeringMScorecardCommandTest extends TestCase
{
    private string $tmpStorage = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpStorage = sys_get_temp_dir().'/atlas-mscore-'.bin2hex(random_bytes(4));
        mkdir($this->tmpStorage.'/atlas-dev/receipts/dev-1/', 0o755, true);
        mkdir($this->tmpStorage.'/atlas-dev/receipts/dev-2/', 0o755, true);
        file_put_contents($this->tmpStorage.'/atlas-dev/receipts/dev-1/senior_engineer_loop_execution.json', json_encode([
            'status' => 'passed',
            'debug_loop' => ['attempts_executed' => 1, 'recovered' => true],
        ]));
        file_put_contents($this->tmpStorage.'/atlas-dev/receipts/dev-2/senior_engineer_loop_execution.json', json_encode([
            'status' => 'failed',
            'debug_loop' => ['attempts_executed' => 2, 'recovered' => false],
        ]));
        $this->app->useStoragePath($this->tmpStorage);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpStorage.'/atlas-dev/receipts/*/*') ?: [] as $f) {
            @unlink($f);
        }
        parent::tearDown();
    }

    public function test_scorecard_counts_real_receipts_and_repair_conversion(): void
    {
        $out = new \Symfony\Component\Console\Output\BufferedOutput;
        $exit = Artisan::call('atlas:engineering:m-scorecard', ['--json' => true, '--no-record' => true], $out);
        $data = json_decode($out->fetch(), true);

        self::assertSame(0, $exit);
        self::assertSame('atlas.engineering.m_scorecard.v1', $data['schema']);
        self::assertSame(2, $data['dev_runs']['total']);
        self::assertSame(1, $data['dev_runs']['passed']);
        self::assertSame(0.5, $data['dev_runs']['pass_rate']);
        self::assertSame(2, $data['dev_runs']['repair_attempted']);
        self::assertSame(1, $data['dev_runs']['repair_recovered']);
        self::assertSame(0.5, $data['dev_runs']['repair_conversion']);
        self::assertArrayHasKey('learning_liveness', $data);
    }

    public function test_no_receipts_yields_null_rates_never_fabricated(): void
    {
        $this->app->useStoragePath($this->tmpStorage.'/empty');

        $out = new \Symfony\Component\Console\Output\BufferedOutput;
        Artisan::call('atlas:engineering:m-scorecard', ['--json' => true, '--no-record' => true], $out);
        $data = json_decode($out->fetch(), true);

        self::assertSame(0, $data['dev_runs']['total']);
        self::assertNull($data['dev_runs']['pass_rate']);
        self::assertNull($data['dev_runs']['repair_conversion']);
    }
}
