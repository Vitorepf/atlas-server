<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Antifragile;

use App\Services\Ai\AutonomousEvolution\Antifragile\AtlasLoopChaosSignalLabeler;
use PHPUnit\Framework\TestCase;

final class AtlasLoopChaosSignalLabelerTest extends TestCase
{
    public function test_judge_rejected_with_shape_token_yields_attributable_shape_keyed_lesson(): void
    {
        $result = (new AtlasLoopChaosSignalLabeler)->label([
            'kind' => 'judge_rejected',
            'shape_token' => 'shape:extract-service',
            'provider' => 'codex',
            'reason' => 'missing consumer proof',
        ]);

        $this->assertSame(AtlasLoopChaosSignalLabeler::SCHEMA_VERSION, $result['schema']);
        $this->assertTrue($result['attributable']);
        $this->assertSame('shape:extract-service', $result['shape_token']);
        $this->assertSame('judge_rejected:shape:extract-service', $result['label']);
        $this->assertIsString($result['lesson']);
        $this->assertStringContainsString('shape_token=shape:extract-service', $result['lesson']);
        $this->assertStringContainsString('kind=judge_rejected', $result['lesson']);
    }

    public function test_missing_shape_token_is_unattributable_and_fabricates_no_lesson(): void
    {
        $result = (new AtlasLoopChaosSignalLabeler)->label([
            'kind' => 'given_back',
            'provider' => 'claude',
            'reason' => 'scope impossible',
        ]);

        $this->assertFalse($result['attributable']);
        $this->assertSame('unattributable_given_back', $result['label']);
        $this->assertNull($result['shape_token']);
        $this->assertNull($result['lesson']);
    }

    public function test_label_is_deterministic_and_emits_no_score(): void
    {
        $event = [
            'kind' => 'provider_degraded',
            'shape_token' => 'shape/provider-timeout',
            'provider' => 'hermes',
            'reason' => 'timeout',
        ];

        $first = (new AtlasLoopChaosSignalLabeler)->label($event);
        $second = (new AtlasLoopChaosSignalLabeler)->label($event);

        $this->assertSame($first, $second);
        $this->assertSame(['schema', 'label', 'attributable', 'shape_token', 'lesson'], array_keys($first));
        $this->assertArrayNotHasKey('score', $first);
    }
}
