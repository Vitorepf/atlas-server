<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\MergeConflictSurfaceClassifier;
use PHPUnit\Framework\TestCase;

final class MergeConflictSurfaceClassifierTest extends TestCase
{
    private MergeConflictSurfaceClassifier $classifier;

    protected function setUp(): void
    {
        $this->classifier = new MergeConflictSurfaceClassifier();
    }

    public function testMultiConflictExcerptDominatesBySeverityWithSortedPaths(): void
    {
        $output = "Auto-merging f.txt\n"
            ."CONFLICT (content): Merge conflict in f.txt\n"
            ."CONFLICT (add/add): Merge conflict in added.txt\n"
            ."CONFLICT (modify/delete): keep.txt deleted in HEAD and modified in feat.  Version feat of keep.txt left in tree.\n";

        $result = $this->classifier->classify(['merge_tree_output' => $output]);

        $this->assertSame('atlas.loop.merge_conflict_surface.v1', $result['schema_version']);
        $this->assertSame(3, $result['conflict_file_count']);
        $this->assertSame(['added.txt', 'f.txt', 'keep.txt'], $result['conflict_paths']);
        $this->assertSame('modify_delete', $result['conflict_kind']);
        $this->assertSame('high', $result['worst_severity']);
        $this->assertFalse($result['governance_path_in_conflict']);
    }

    public function testContentOnlyExcerptIsLowSeverityContentKind(): void
    {
        $output = "Auto-merging src/service.php\n"
            ."CONFLICT (content): Merge conflict in src/service.php\n";

        $result = $this->classifier->classify(['merge_tree_output' => $output]);

        $this->assertSame(1, $result['conflict_file_count']);
        $this->assertSame(['src/service.php'], $result['conflict_paths']);
        $this->assertSame('content', $result['conflict_kind']);
        $this->assertSame('low', $result['worst_severity']);
        $this->assertFalse($result['governance_path_in_conflict']);
    }

    public function testCleanEmptyOutputHasNoConflictsAndUnknownKind(): void
    {
        $result = $this->classifier->classify(['merge_tree_output' => '']);

        $this->assertSame(0, $result['conflict_file_count']);
        $this->assertSame([], $result['conflict_paths']);
        $this->assertSame('unknown', $result['conflict_kind']);
        $this->assertSame('low', $result['worst_severity']);
        $this->assertFalse($result['governance_path_in_conflict']);
    }

    public function testGovernancePathAddAddExcerptIsHighSeverity(): void
    {
        $output = "CONFLICT (add/add): Merge conflict in .atlas/loop/receipt.json\n";

        $result = $this->classifier->classify(['merge_tree_output' => $output]);

        $this->assertSame(1, $result['conflict_file_count']);
        $this->assertSame(['.atlas/loop/receipt.json'], $result['conflict_paths']);
        $this->assertSame('add_add', $result['conflict_kind']);
        $this->assertTrue($result['governance_path_in_conflict']);
        $this->assertSame('high', $result['worst_severity']);
    }

    public function testAgreedKindIsKeptWhenAllConflictsShareIt(): void
    {
        $output = "CONFLICT (add/add): Merge conflict in b.txt\n"
            ."CONFLICT (add/add): Merge conflict in a.txt\n";

        $result = $this->classifier->classify(['merge_tree_output' => $output]);

        $this->assertSame('add_add', $result['conflict_kind']);
        $this->assertSame('medium', $result['worst_severity']);
        $this->assertSame(['a.txt', 'b.txt'], $result['conflict_paths']);
    }

    public function testRepeatedConflictPathIsDedupedAndCounted(): void
    {
        $output = "CONFLICT (content): Merge conflict in z.txt\n"
            ."CONFLICT (content): Merge conflict in z.txt\n"
            ."CONFLICT (content): Merge conflict in a.txt\n";

        $result = $this->classifier->classify(['merge_tree_output' => $output]);

        $this->assertSame(2, $result['conflict_file_count']);
        $this->assertSame(['a.txt', 'z.txt'], $result['conflict_paths']);
    }

    public function testRenameConflictExtractsOriginalPathAndMediumSeverity(): void
    {
        $output = "CONFLICT (rename/rename): ren.txt renamed to renamed_main.txt in HEAD and to renamed_feat.txt in feat.\n";

        $result = $this->classifier->classify(['merge_tree_output' => $output]);

        $this->assertSame(['ren.txt'], $result['conflict_paths']);
        $this->assertSame('rename', $result['conflict_kind']);
        $this->assertSame('medium', $result['worst_severity']);
    }

    public function testGovernanceForcesHighSeverityEvenWithContentOnlyKind(): void
    {
        $output = "CONFLICT (content): Merge conflict in .atlas/state.json\n";

        $result = $this->classifier->classify(['merge_tree_output' => $output]);

        $this->assertSame('content', $result['conflict_kind']);
        $this->assertTrue($result['governance_path_in_conflict']);
        $this->assertSame('high', $result['worst_severity']);
    }

    public function testIdenticalInputProducesDeterministicResult(): void
    {
        $input = [
            'merge_tree_output' => "CONFLICT (content): Merge conflict in f.txt\n"
                ."CONFLICT (rename/rename): ren.txt renamed to a.txt in HEAD and to b.txt in feat.\n",
        ];

        $first = $this->classifier->classify($input);
        $second = $this->classifier->classify($input);

        $this->assertSame($first, $second);
    }
}
