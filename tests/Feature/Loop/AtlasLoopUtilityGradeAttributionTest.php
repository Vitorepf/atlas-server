<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\AtlasLoopUtilityGradeService;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopWiredCallerService;
use Tests\TestCase;

/**
 * F5: the utility grade scores the DOMINANT changed file, not the first-in-diff-order file.
 *
 * The adversarial grade found that primaryTarget() credited a 218-line/7-file bundle commit to
 * a file that changed only 4 lines (first real .php in diff order), so the WIRED/NON_TRIVIAL
 * axes scored the wrong file. The fix threads per-file changed-line counts (from git numstat or
 * a diff parse) into primaryTarget(), which now picks the file with the MOST changed lines.
 */
final class AtlasLoopUtilityGradeAttributionTest extends TestCase
{
    private function service(): AtlasLoopUtilityGradeService
    {
        // Construct directly (NOT via the container) so this unit test of pure private methods is
        // immune to any cross-test container state in the full suite.
        return new AtlasLoopUtilityGradeService(new AtlasLoopWiredCallerService());
    }

    private function invoke(string $method, mixed ...$args): mixed
    {
        $ref = new \ReflectionMethod(AtlasLoopUtilityGradeService::class, $method);
        $ref->setAccessible(true);

        return $ref->invoke($this->service(), ...$args);
    }

    public function test_primary_target_picks_the_dominant_real_file_by_changed_lines(): void
    {
        $changed = ['app/Services/Small.php', 'app/Services/Big.php', 'tests/Unit/BigTest.php'];
        $perFile = ['app/Services/Small.php' => 4, 'app/Services/Big.php' => 200, 'tests/Unit/BigTest.php' => 90];

        $this->assertSame(
            'app/Services/Big.php',
            $this->invoke('primaryTarget', $changed, 'app/Services/Stored.php', $perFile),
            'the dominant real file (200 lines) is graded, not the diff-order-first 4-line file or the test',
        );
    }

    public function test_primary_target_is_stable_when_line_counts_are_unknown(): void
    {
        $changed = ['app/Services/Small.php', 'app/Services/Big.php'];

        $this->assertSame(
            'app/Services/Small.php',
            $this->invoke('primaryTarget', $changed, 'x', []),
            'no per-file data => deterministic first-real fallback (unchanged legacy behavior)',
        );
    }

    public function test_primary_target_ignores_generated_and_test_files(): void
    {
        $changed = ['app/Services/Ai/Aaeos/Generated/Big.php', 'app/Services/Real.php'];
        $perFile = ['app/Services/Ai/Aaeos/Generated/Big.php' => 500, 'app/Services/Real.php' => 10];

        $this->assertSame(
            'app/Services/Real.php',
            $this->invoke('primaryTarget', $changed, 'x', $perFile),
            'a 500-line Generated/ change never out-votes the real production file',
        );
    }

    public function test_per_file_lines_from_diff_counts_body_lines_per_file(): void
    {
        $diff = "diff --git a/app/Services/Small.php b/app/Services/Small.php\n"
            ."--- a/app/Services/Small.php\n"
            ."+++ b/app/Services/Small.php\n"
            ."@@ -1,1 +1,2 @@\n"
            ." keep\n"
            ."+added one\n"
            ."diff --git a/app/Services/Big.php b/app/Services/Big.php\n"
            ."--- a/app/Services/Big.php\n"
            ."+++ b/app/Services/Big.php\n"
            ."@@ -1,2 +1,4 @@\n"
            ."+a\n+b\n+c\n-old\n";

        $perFile = $this->invoke('perFileLinesFromDiff', $diff);

        $this->assertSame(1, $perFile['app/Services/Small.php']);
        $this->assertSame(4, $perFile['app/Services/Big.php']);
        // And the dominant pick agrees with the parsed counts.
        $this->assertSame(
            'app/Services/Big.php',
            $this->invoke('primaryTarget', array_keys($perFile), 'x', $perFile),
        );
    }
}
