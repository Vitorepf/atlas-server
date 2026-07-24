<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Support;

use App\Services\Ai\Support\ControlPlaneStatusSection;
use PHPUnit\Framework\TestCase;

final class ControlPlaneStatusSectionTest extends TestCase
{
    public function test_missing_model_class_returns_empty_projection(): void
    {
        $out = ControlPlaneStatusSection::project(
            '\\App\\Models\\ThisModelDoesNotExist'.uniqid(),
            'status',
            5,
            ['id', 'status'],
        );

        $this->assertSame(0, $out['count']);
        $this->assertSame([], $out['by_status']);
        $this->assertSame([], $out['recent']);
    }

    public function test_safe_count_missing_model_is_zero(): void
    {
        $this->assertSame(0, ControlPlaneStatusSection::safeCount('\\App\\Models\\Missing'.uniqid()));
    }
}
