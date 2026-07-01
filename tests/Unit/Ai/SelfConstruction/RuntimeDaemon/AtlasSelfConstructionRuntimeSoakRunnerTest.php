<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\RuntimeDaemon;

use App\Services\Ai\SelfConstruction\RuntimeDaemon\AtlasSelfConstructionRuntimeSoakRunner;
use PHPUnit\Framework\TestCase;

final class AtlasSelfConstructionRuntimeSoakRunnerTest extends TestCase
{
    private AtlasSelfConstructionRuntimeSoakRunner $runner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runner = new AtlasSelfConstructionRuntimeSoakRunner();
    }

    // AC: partial unless minimum diversity met
    public function test_below_green_tick_floor_is_partial(): void
    {
        $result = $this->runner->evaluate([
            'green_ticks' => 5,
            'recovered_cycles' => 2,
            'held_cycles' => 1,
            'min_green_ticks' => 10,
        ]);

        $this->assertSame('partial', $result['status']);
        $this->assertTrue(count(array_filter($result['reasons'], fn ($r) => str_contains($r, 'insufficient_green_ticks'))) > 0);
    }

    public function test_no_recovered_cycles_is_partial(): void
    {
        $result = $this->runner->evaluate([
            'green_ticks' => 15,
            'recovered_cycles' => 0,
            'held_cycles' => 1,
        ]);

        $this->assertSame('partial', $result['status']);
    }

    public function test_no_held_cycles_is_partial(): void
    {
        $result = $this->runner->evaluate([
            'green_ticks' => 15,
            'recovered_cycles' => 2,
            'held_cycles' => 0,
        ]);

        $this->assertSame('partial', $result['status']);
    }

    public function test_forbidden_dependency_hits_is_partial(): void
    {
        $result = $this->runner->evaluate([
            'green_ticks' => 15,
            'recovered_cycles' => 2,
            'held_cycles' => 1,
            'forbidden_dependency_hits' => 1,
        ]);

        $this->assertSame('partial', $result['status']);
        $this->assertTrue(count(array_filter($result['reasons'], fn ($r) => str_contains($r, 'forbidden_dependency_hits'))) > 0);
    }

    public function test_all_floors_met_is_green(): void
    {
        $result = $this->runner->evaluate([
            'green_ticks' => 15,
            'recovered_cycles' => 2,
            'held_cycles' => 1,
            'forbidden_dependency_hits' => 0,
        ]);

        $this->assertSame('green', $result['status']);
    }

    public function test_custom_floors_respected(): void
    {
        $result = $this->runner->evaluate([
            'green_ticks' => 3,
            'recovered_cycles' => 1,
            'held_cycles' => 1,
            'min_green_ticks' => 3,
        ]);

        $this->assertSame('green', $result['status']);
    }
}
