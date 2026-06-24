<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\AtlasLoopCrossModelTriangulator;
use App\Services\Ai\AutonomousEvolution\AtlasLoopMasterSwitch;
use Tests\TestCase;

final class AtlasLoopCrossModelTriangulatorTest extends TestCase
{
    private string $envPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->envPath = sys_get_temp_dir().'/atlas-triangulator-master-'.bin2hex(random_bytes(6)).'.env';
        AtlasLoopMasterSwitch::$envPathOverride = $this->envPath;
    }

    protected function tearDown(): void
    {
        AtlasLoopMasterSwitch::$envPathOverride = null;
        @unlink($this->envPath);

        parent::tearDown();
    }

    public function test_three_provider_receipts_emit_dissent_without_numeric_proxy_keys(): void
    {
        $this->master(true);
        config()->set('atlas.loop.cross_model_triangulation_enabled', true);

        $result = (new AtlasLoopCrossModelTriangulator)->triangulate($this->bundle(), [
            $this->receipt('minimax_m3', true),
            $this->receipt('codex', true),
            $this->receipt('glm', false, ['reason' => 'acceptance_failed', 'score' => 0.42]),
        ]);

        $this->assertSame('dissent', $result['verdict']);
        $this->assertCount(3, $result['receipts']);
        $this->assertFalse($result['consensus']['consensus']);
        $this->assertSame(64, strlen($result['frozen_bundle_hash']));
        $this->assertNoGoodhartKeys($result);
    }

    public function test_fewer_than_three_providers_is_insufficient_witnesses_never_default_pass(): void
    {
        $this->master(true);
        config()->set('atlas.loop.cross_model_triangulation_enabled', true);

        $result = (new AtlasLoopCrossModelTriangulator)->triangulate($this->bundle(), [
            $this->receipt('codex', true),
            $this->receipt('glm', true),
        ]);

        $this->assertSame('insufficient_witnesses', $result['verdict']);
        $this->assertStringContainsString('insufficient_witnesses:2', $result['reason']);
        $this->assertFalse($result['consensus']['passes']);
    }

    public function test_master_switch_off_returns_byte_identical_insufficient_witnesses_and_runs_zero_providers(): void
    {
        $this->master(false);
        config()->set('atlas.loop.cross_model_triangulation_enabled', true);
        $calls = 0;
        $runner = function (array $provider, array $bundle) use (&$calls): array {
            $calls++;

            return ['provider_id' => $provider['provider_id'], 'raw_verdict' => ['passes' => true]];
        };
        $triangulator = new AtlasLoopCrossModelTriangulator($runner);

        $first = $triangulator->triangulate($this->bundle());
        $second = $triangulator->triangulate($this->bundle());

        $this->assertSame($first, $second);
        $this->assertSame('insufficient_witnesses', $first['verdict']);
        $this->assertSame('master_switch_off', $first['reason']);
        $this->assertSame(0, $calls);
    }

    public function test_flag_default_off_short_circuits_before_provider_runner(): void
    {
        $this->master(true);
        config()->set('atlas.loop.cross_model_triangulation_enabled', false);
        $calls = 0;
        $triangulator = new AtlasLoopCrossModelTriangulator(function () use (&$calls): array {
            $calls++;

            return ['provider_id' => 'codex', 'raw_verdict' => ['passes' => true]];
        });

        $result = $triangulator->triangulate($this->bundle());

        $this->assertSame('insufficient_witnesses', $result['verdict']);
        $this->assertSame('cross_model_triangulation_disabled', $result['reason']);
        $this->assertSame(0, $calls);
    }

    public function test_all_three_providers_agree_when_raw_verdicts_match(): void
    {
        $this->master(true);
        config()->set('atlas.loop.cross_model_triangulation_enabled', true);

        $result = (new AtlasLoopCrossModelTriangulator)->triangulate($this->bundle(), [
            $this->receipt('minimax_m3', true),
            $this->receipt('codex', true),
            $this->receipt('glm', true),
        ]);

        $this->assertSame('agree', $result['verdict']);
        $this->assertTrue($result['consensus']['consensus']);
    }

    private function master(bool $enabled): void
    {
        file_put_contents($this->envPath, 'ATLAS_LOOP_MASTER_ENABLED='.($enabled ? 'true' : 'false')."\n");
    }

    /**
     * @return array<string,mixed>
     */
    private function bundle(): array
    {
        return [
            'acceptance' => [
                'commands' => ['php tests/subject_test.php'],
                'metric_kind' => 'gate',
            ],
            'frozen_globs' => ['tests/**'],
        ];
    }

    /**
     * @param  array<string,mixed>  $extra
     * @return array<string,mixed>
     */
    private function receipt(string $provider, bool $passes, array $extra = []): array
    {
        return [
            'provider_id' => $provider,
            'judge_id' => 'frozen_acceptance_judge',
            'raw_verdict' => $extra + [
                'passes' => $passes,
                'reason' => $passes ? 'passed' : 'failed',
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $value
     */
    private function assertNoGoodhartKeys(array $value): void
    {
        foreach ($value as $key => $item) {
            $this->assertNotContains($key, ['score', 'confidence', 'average']);
            if (is_array($item)) {
                $this->assertNoGoodhartKeys($item);
            }
        }
    }
}
