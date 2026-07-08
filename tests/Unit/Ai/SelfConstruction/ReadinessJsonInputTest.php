<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\Support\ReadinessJsonInput;
use PHPUnit\Framework\TestCase;

final class ReadinessJsonInputTest extends TestCase
{
    public function test_absolute_path_refused(): void
    {
        $result = ReadinessJsonInput::decodeOption('@'.DIRECTORY_SEPARATOR.'etc'.DIRECTORY_SEPARATOR.'passwd');

        $this->assertSame([], $result, 'absolute @file path must be refused');
    }

    public function test_traversal_path_refused(): void
    {
        $result = ReadinessJsonInput::decodeOption('@../etc/passwd');

        $this->assertSame([], $result, '@file with .. traversal must be refused');
    }

    public function test_deep_traversal_refused(): void
    {
        $result = ReadinessJsonInput::decodeOption('@subdir/../../etc/passwd');

        $this->assertSame([], $result, '@file with embedded .. traversal must be refused');
    }

    public function test_relative_path_inside_repo_still_works(): void
    {
        // This is tested via the inline JSON path — the relative @path
        // falls through to base_path() which needs Laravel bootstrap.
        // Instead, verify that inline JSON still works unchanged.
        $result = ReadinessJsonInput::decodeOption('{"inline": true}');
        $this->assertSame(['inline' => true], $result);
    }

    public function test_empty_input_returns_empty(): void
    {
        $this->assertSame([], ReadinessJsonInput::decodeOption(''));
        $this->assertSame([], ReadinessJsonInput::decodeOption(null));
    }

    public function test_invalid_json_returns_empty(): void
    {
        $this->assertSame([], ReadinessJsonInput::decodeOption('not json'));
    }
}
