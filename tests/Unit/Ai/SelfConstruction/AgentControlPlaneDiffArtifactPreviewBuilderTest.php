<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AgentControlPlaneDiffArtifactPreviewBuilder;
use PHPUnit\Framework\TestCase;

final class AgentControlPlaneDiffArtifactPreviewBuilderTest extends TestCase
{
    private function builder(): AgentControlPlaneDiffArtifactPreviewBuilder
    {
        return new AgentControlPlaneDiffArtifactPreviewBuilder;
    }

    public function test_duplicate_output_paths_are_deduplicated_and_surfaced(): void
    {
        $result = $this->builder()->preview([], [
            'output_paths' => ['app/Foo.php', 'app/Bar.php', 'app/Foo.php'],
        ]);

        $this->assertSame(2, $result['artifact_count']);
        $this->assertSame(1, $result['duplicate_path_count']);
        $this->assertCount(2, $result['artifacts']);
        $this->assertSame(['app/Bar.php', 'app/Foo.php'], array_column($result['artifacts'], 'path'));
    }

    public function test_blank_or_non_string_paths_are_counted_as_invalid_and_do_not_create_artifacts(): void
    {
        $result = $this->builder()->preview([], [
            'output_paths' => ['app/Foo.php', '', '   ', null, 42, ['nested']],
        ]);

        $this->assertSame(1, $result['artifact_count']);
        $this->assertSame(5, $result['invalid_path_count']);
        $this->assertSame(0, $result['duplicate_path_count']);
    }

    public function test_same_path_set_in_different_order_yields_same_hash_and_sorted_artifacts(): void
    {
        $first = $this->builder()->preview([], ['output_paths' => ['app/Zeta.php', 'app/Alpha.php', 'app/Mid.php']]);
        $second = $this->builder()->preview([], ['output_paths' => ['app/Mid.php', 'app/Zeta.php', 'app/Alpha.php']]);

        $this->assertSame($first['diff_preview_hash'], $second['diff_preview_hash']);
        $this->assertSame(['app/Alpha.php', 'app/Mid.php', 'app/Zeta.php'], array_column($first['artifacts'], 'path'));
        $this->assertSame(array_column($first['artifacts'], 'path'), array_column($second['artifacts'], 'path'));
    }

    public function test_empty_paths_yield_diff_preview_empty_status(): void
    {
        $result = $this->builder()->preview([], ['output_paths' => []]);

        $this->assertSame('diff_preview_empty', $result['status']);
        $this->assertSame(0, $result['artifact_count']);
        $this->assertSame(0, $result['duplicate_path_count']);
        $this->assertSame(0, $result['invalid_path_count']);
    }
}
