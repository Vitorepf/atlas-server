<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\TaskServing;

use App\Services\Ai\SelfConstruction\TaskServing\AtlasTaskServingCollisionDeltaMeasurer;
use PHPUnit\Framework\TestCase;

final class AtlasTaskServingCollisionDeltaMeasurerTest extends TestCase
{
    private AtlasTaskServingCollisionDeltaMeasurer $measurer;

    protected function setUp(): void
    {
        $this->measurer = new AtlasTaskServingCollisionDeltaMeasurer;
    }

    public function test_baseline_collisions_carried_separately(): void
    {
        $result = $this->measurer->measure(
            ['app/Foo.php', 'app/Bar.php'],
            ['app/Foo.php', 'app/Bar.php'],
            ['app/New.php']
        );

        $this->assertSame(2, $result['baseline_collision_count']);
        $this->assertSame(0, $result['new_collision_count']);
        $this->assertContains('app/Foo.php', $result['carried_baseline_collisions']);
    }

    public function test_emitted_target_collisions_counted(): void
    {
        $result = $this->measurer->measure(
            [],
            ['app/Foo.php'],
            ['app/Foo.php', 'app/Clean.php']
        );

        $this->assertSame(1, $result['emitted_collision_count']);
        $this->assertContains('app/Foo.php', $result['emitted_collisions']);
        $this->assertContains('app/Clean.php', $result['clean_emitted_targets']);
    }

    public function test_unchanged_pre_existing_collisions_do_not_invalidate_unrelated_seeds(): void
    {
        $result = $this->measurer->measure(
            ['app/Old.php'],
            ['app/Old.php'],
            ['app/New.php']
        );

        $this->assertFalse($result['round_caused_collisions']);
        $this->assertFalse($result['unrelated_seeds_invalidated']);
        $this->assertContains('app/New.php', $result['clean_emitted_targets']);
    }

    public function test_new_collisions_detected(): void
    {
        $result = $this->measurer->measure(
            ['app/Old.php'],
            ['app/Old.php', 'app/New.php'],
            ['app/New.php']
        );

        $this->assertTrue($result['round_caused_collisions']);
        $this->assertContains('app/New.php', $result['new_collisions']);
    }

    public function test_schema_present(): void
    {
        $result = $this->measurer->measure([], [], []);
        $this->assertSame(AtlasTaskServingCollisionDeltaMeasurer::SCHEMA, $result['schema']);
    }
}
