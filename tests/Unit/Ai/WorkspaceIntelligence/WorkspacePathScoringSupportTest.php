<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\WorkspaceIntelligence;

use App\Services\Ai\WorkspaceIntelligence\Support\WorkspacePathScoringSupport;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class WorkspacePathScoringSupportTest extends TestCase
{
    #[Test]
    public function area_key_uses_first_two_path_segments(): void
    {
        $this->assertSame('app/Services', WorkspacePathScoringSupport::areaKey('app/Services/Ai/Foo.php'));
        $this->assertNull(WorkspacePathScoringSupport::areaKey('../etc/passwd'));
        $this->assertNull(WorkspacePathScoringSupport::areaKey(null));
    }

    #[Test]
    public function command_area_score_sums_affinity_matches(): void
    {
        $score = WorkspacePathScoringSupport::commandAreaScore([
            'area_affinity' => [
                'app/Services' => 2,
                'app/Services/Ai' => 3,
            ],
        ], ['app/Services']);
        $this->assertSame(5, $score);
    }
}
