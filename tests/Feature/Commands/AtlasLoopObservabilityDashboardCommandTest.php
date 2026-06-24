<?php

declare(strict_types=1);

namespace Tests\Feature\Commands;

use App\Services\Ai\AutonomousEvolution\Observability\AtlasLoopProxyDriftFactDetector;
use App\Services\Ai\AutonomousEvolution\Observability\AtlasLoopQueueDryingAlarmDetector;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the read-only `atlas:loop:observability:dashboard` CLI: --json emits the 5 named sections; exit code
 * is 0 when every detector is clear and 2 when ANY detector alarms (operator notification trigger). The single
 * alarm is induced through the pure-signals proxy-drift detector (bound with a controlled signals dir); the
 * other sections stay clear, so the test pins the exit-code logic without DB migrations.
 */
final class AtlasLoopObservabilityDashboardCommandTest extends TestCase
{
    private const CAMPAIGN = 'camp-dash-1';

    private string $proxyDir;

    private string $queueDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->proxyDir = sys_get_temp_dir().'/atlas-dash-proxy-'.bin2hex(random_bytes(6));
        $this->queueDir = sys_get_temp_dir().'/atlas-dash-queue-'.bin2hex(random_bytes(6));
        mkdir($this->proxyDir, 0o755, true);
        mkdir($this->queueDir, 0o755, true);

        // Bind the two signals-dir detectors at controlled tmp dirs (real instances — both classes are final).
        $this->app->instance(AtlasLoopProxyDriftFactDetector::class, new AtlasLoopProxyDriftFactDetector($this->proxyDir));
        $this->app->instance(AtlasLoopQueueDryingAlarmDetector::class, new AtlasLoopQueueDryingAlarmDetector($this->queueDir));
    }

    protected function tearDown(): void
    {
        foreach ([$this->proxyDir, $this->queueDir] as $dir) {
            array_map('unlink', glob($dir.'/*') ?: []);
            @rmdir($dir);
        }
        parent::tearDown();
    }

    /** Write N proxy-classified DECISION signals for the campaign ⇒ proxy-drift trips (ratio 15/20 ≥ 0.75). */
    private function writeProxyDecisions(int $n): void
    {
        $lines = '';
        for ($i = 0; $i < $n; $i++) {
            $lines .= json_encode([
                'campaign_id' => self::CAMPAIGN,
                'stage' => 'decision',
                'payload' => ['work_type' => 'whitespace'], // a PROXY work type
            ], JSON_UNESCAPED_SLASHES)."\n";
        }
        file_put_contents($this->proxyDir.'/signals.jsonl', $lines);
    }

    public function test_json_emits_five_sections_and_exit_zero_when_all_clear(): void
    {
        // proxy dir empty ⇒ insufficient sample ⇒ drifting=false; queue dir empty + no pipeline table ⇒ false;
        // stagnation indeterminate ⇒ false. All clear.
        $code = Artisan::call('atlas:loop:observability:dashboard', ['campaignId' => self::CAMPAIGN, '--json' => true]);

        $this->assertSame(0, $code, 'all detectors clear ⇒ exit 0');

        $out = json_decode(trim(Artisan::output()), true);
        $this->assertIsArray($out);
        $this->assertSame(
            ['funnel', 'pipeline_digest', 'proxy_drift', 'queue_drying', 'stagnation'],
            collect(array_keys($out))->sort()->values()->all(),
            'exactly the 5 named sections',
        );
        $this->assertFalse((bool) ($out['proxy_drift']['drifting'] ?? true));
    }

    public function test_exit_code_two_when_proxy_drift_alarms(): void
    {
        $this->writeProxyDecisions(15); // 15/20 = 0.75 ≥ DRIFT_THRESHOLD ⇒ drifting=true

        $code = Artisan::call('atlas:loop:observability:dashboard', ['campaignId' => self::CAMPAIGN, '--json' => true]);

        $this->assertSame(2, $code, 'any detector alarm ⇒ exit 2');

        $out = json_decode(trim(Artisan::output()), true);
        $this->assertTrue((bool) ($out['proxy_drift']['drifting'] ?? false), 'proxy-drift is the tripped detector');
        $this->assertFalse((bool) ($out['queue_drying']['drying'] ?? true), 'only ONE detector alarms');
        $this->assertFalse((bool) ($out['stagnation']['stagnated'] ?? true), 'only ONE detector alarms');
    }

    public function test_human_table_prints_pass_and_alarm_headlines(): void
    {
        $this->writeProxyDecisions(15);

        $code = Artisan::call('atlas:loop:observability:dashboard', ['campaignId' => self::CAMPAIGN]);
        $out = Artisan::output();

        $this->assertSame(2, $code);
        $this->assertStringContainsString('PROXY DRIFT', $out);
        $this->assertStringContainsString('ALARM', $out, 'the tripped detector shows ALARM');
        $this->assertStringContainsString('PASS', $out, 'the clear detectors show PASS');
        $this->assertStringContainsString('STAGNATION', $out);
        $this->assertStringContainsString('QUEUE DRYING', $out);
    }
}
