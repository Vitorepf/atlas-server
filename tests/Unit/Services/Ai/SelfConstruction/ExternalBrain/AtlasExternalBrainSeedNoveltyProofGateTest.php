<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainSeedNoveltyProofGate;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainSeedNoveltyProofGateTest extends TestCase
{
    private AtlasExternalBrainSeedNoveltyProofGate $gate;

    protected function setUp(): void
    {
        $this->gate = new AtlasExternalBrainSeedNoveltyProofGate;
    }

    public function test_duplicate_target_blocked(): void
    {
        $result = $this->gate->evaluate(
            ['target' => 'app/Foo.php', 'allowed_files' => ['app/Foo.php'], 'capability_family' => 'brain'],
            [['target' => 'app/Foo.php', 'allowed_files' => ['app/Bar.php'], 'capability_family' => 'other']]
        );

        $this->assertFalse($result['novel']);
        $this->assertContains(AtlasExternalBrainSeedNoveltyProofGate::BLOCK_DUPLICATE_TARGET, $result['blockers']);
    }

    public function test_duplicate_allowed_files_blocked(): void
    {
        $result = $this->gate->evaluate(
            ['target' => 'app/New.php', 'allowed_files' => ['app/Foo.php', 'tests/FooTest.php'], 'capability_family' => 'brain'],
            [['target' => 'app/Old.php', 'allowed_files' => ['tests/FooTest.php', 'app/Foo.php'], 'capability_family' => 'other']]
        );

        $this->assertFalse($result['novel']);
        $this->assertContains(AtlasExternalBrainSeedNoveltyProofGate::BLOCK_DUPLICATE_ALLOWED_FILES, $result['blockers']);
    }

    public function test_duplicate_capability_family_blocked(): void
    {
        $result = $this->gate->evaluate(
            ['target' => 'app/New.php', 'allowed_files' => ['app/New.php'], 'capability_family' => 'brain'],
            [['target' => 'app/Old.php', 'allowed_files' => ['app/Old.php'], 'capability_family' => 'brain']]
        );

        $this->assertFalse($result['novel']);
        $this->assertContains(AtlasExternalBrainSeedNoveltyProofGate::BLOCK_DUPLICATE_CAPABILITY_FAMILY, $result['blockers']);
    }

    public function test_genuinely_new_implementation_organ_passes(): void
    {
        $result = $this->gate->evaluate(
            ['target' => 'app/NewOrgan.php', 'allowed_files' => ['app/NewOrgan.php'], 'capability_family' => 'new_family'],
            [['target' => 'app/OldOrgan.php', 'allowed_files' => ['app/OldOrgan.php'], 'capability_family' => 'old_family']]
        );

        $this->assertTrue($result['novel']);
        $this->assertEmpty($result['blockers']);
    }

    public function test_empty_live_tasks_is_novel(): void
    {
        $result = $this->gate->evaluate(['target' => 'app/Foo.php'], []);
        $this->assertTrue($result['novel']);
    }

    public function test_schema_present(): void
    {
        $result = $this->gate->evaluate([], []);
        $this->assertSame(AtlasExternalBrainSeedNoveltyProofGate::SCHEMA, $result['schema']);
    }
}
