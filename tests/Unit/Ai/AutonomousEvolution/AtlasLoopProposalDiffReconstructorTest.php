<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\AtlasLoopProposalDiffReconstructor;
use Tests\TestCase;

final class AtlasLoopProposalDiffReconstructorTest extends TestCase
{
    public function test_reconstructs_content_from_clean_unified_diff(): void
    {
        $original = "<?php\nreturn 'old';\n";
        $diff = <<<'PATCH'
--- target.php
+++ target.php
@@ -1,2 +1,2 @@
 <?php
-return 'old';
+return 'new';

PATCH;

        $result = (new AtlasLoopProposalDiffReconstructor)->reconstruct($original, $diff, 'target.php');

        $this->assertSame([
            'ok' => true,
            'content' => "<?php\nreturn 'new';\n",
            'reason' => null,
        ], $result);
    }
}
