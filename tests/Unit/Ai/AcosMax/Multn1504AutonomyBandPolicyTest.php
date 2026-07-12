<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AcosMax;

use App\Services\Ai\Operator\TargetClassAutonomyBandPolicy;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class Multn1504AutonomyBandPolicyTest extends TestCase
{
    #[Test]
    public function docs_with_clean_history_get_high_autonomy_rule(): void
    {
        $out = TargetClassAutonomyBandPolicy::compile('docs', ['n' => 20, 'accepts' => 20, 'reverts' => 0]);

        $this->assertSame('autonomy_limit', $out['effect']);
        $this->assertSame('high', $out['band']);
        $this->assertNotNull($out['reverse_handle']);
    }

    #[Test]
    public function migration_never_exceeds_floor_even_with_clean_history(): void
    {
        $out = TargetClassAutonomyBandPolicy::compile('migrations', ['n' => 50, 'accepts' => 50, 'reverts' => 0]);

        $this->assertSame('medium', $out['band']);
        $this->assertSame('migration_safety_floor', $out['basis']);
    }

    #[Test]
    public function small_sample_compiles_no_rule(): void
    {
        $out = TargetClassAutonomyBandPolicy::compile('docs', ['n' => 4, 'accepts' => 4, 'reverts' => 0]);

        $this->assertSame('insufficient_n', $out['basis']);
        $this->assertNull($out['effect']);
    }
}
