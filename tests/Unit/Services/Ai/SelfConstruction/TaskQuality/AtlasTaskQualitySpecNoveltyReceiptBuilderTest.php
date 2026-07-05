<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\TaskQuality;

use App\Services\Ai\SelfConstruction\TaskQuality\AtlasTaskQualitySpecNoveltyReceiptBuilder;
use PHPUnit\Framework\TestCase;

final class AtlasTaskQualitySpecNoveltyReceiptBuilderTest extends TestCase
{
    private AtlasTaskQualitySpecNoveltyReceiptBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new AtlasTaskQualitySpecNoveltyReceiptBuilder;
    }

    public function test_receipt_includes_compared_targets(): void
    {
        $result = $this->builder->build(
            ['target' => 'app/New.php', 'allowed_files' => ['app/New.php'], 'capability_family' => 'new'],
            [['target' => 'app/Old.php', 'allowed_files' => ['app/Old.php'], 'capability_family' => 'old']]
        );

        $this->assertArrayHasKey('compared_targets', $result);
        $this->assertCount(1, $result['compared_targets']);
    }

    public function test_receipt_includes_decision(): void
    {
        $result = $this->builder->build(['target' => 'app/New.php'], []);
        $this->assertArrayHasKey('decision', $result);
        $this->assertSame('novel', $result['decision']);
    }

    public function test_receipt_includes_reason_codes_for_duplicate(): void
    {
        $result = $this->builder->build(
            ['target' => 'app/Foo.php', 'allowed_files' => ['app/Foo.php'], 'capability_family' => 'brain'],
            [['target' => 'app/Foo.php', 'allowed_files' => ['app/Foo.php'], 'capability_family' => 'brain']]
        );

        $this->assertFalse($result['novel']);
        $this->assertSame('duplicate', $result['decision']);
        $this->assertNotEmpty($result['reason_codes']);
    }

    public function test_receipt_has_zero_raw_prompt_material(): void
    {
        $result = $this->builder->build(['target' => 'app/Foo.php'], []);
        $this->assertNull($result['raw_prompt_material']);
    }

    public function test_recent_completion_detected(): void
    {
        $result = $this->builder->build(
            ['target' => 'app/Foo.php'],
            [],
            [['target' => 'app/Foo.php']]
        );

        $this->assertFalse($result['novel']);
        $this->assertContains('recently_completed:app/Foo.php', $result['reason_codes']);
    }

    public function test_schema_present(): void
    {
        $result = $this->builder->build([], []);
        $this->assertSame(AtlasTaskQualitySpecNoveltyReceiptBuilder::SCHEMA, $result['schema']);
    }
}
