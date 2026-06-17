<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Discovery;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopIdeaTreeAccessor as Tree;
use Tests\TestCase;

/**
 * ARBOR-GRAFT T1 — pure idea-tree logic (no DB / no provider). The DB wrappers delegate to these.
 */
class AtlasLoopIdeaTreeAccessorTest extends TestCase
{
    public function test_path_to_root_is_root_first_chain(): void
    {
        $byId = [
            'r' => ['parent_target_id' => null],
            'a' => ['parent_target_id' => 'r'],
            'b' => ['parent_target_id' => 'a'],
        ];

        $this->assertSame(['r', 'a', 'b'], Tree::pathToRootIds($byId, 'b'));
        $this->assertSame(['r'], Tree::pathToRootIds($byId, 'r'));
    }

    public function test_path_to_root_is_cycle_safe(): void
    {
        // A corrupt cycle must terminate, never infinite-loop.
        $byId = [
            'x' => ['parent_target_id' => 'y'],
            'y' => ['parent_target_id' => 'x'],
        ];

        $path = Tree::pathToRootIds($byId, 'x');
        $this->assertCount(2, $path);
    }

    public function test_pending_leaf_ids_excludes_parents_root_and_flat_ledger(): void
    {
        $nodes = [
            ['id' => 'r', 'parent_target_id' => null, 'depth' => 0, 'tree_status' => Tree::STATUS_PENDING], // root depth 0 -> excluded
            ['id' => 'a', 'parent_target_id' => 'r', 'depth' => 1, 'tree_status' => Tree::STATUS_PENDING],  // has child -> excluded
            ['id' => 'b', 'parent_target_id' => 'a', 'depth' => 2, 'tree_status' => Tree::STATUS_PENDING],  // pending leaf -> INCLUDED
            ['id' => 'c', 'parent_target_id' => 'r', 'depth' => 1, 'tree_status' => Tree::STATUS_DONE],     // not pending -> excluded
            ['id' => 'f', 'parent_target_id' => null, 'depth' => 0, 'tree_status' => null],                 // flat ledger -> excluded
        ];

        $this->assertSame(['b'], Tree::pendingLeafIds($nodes));
    }

    public function test_flat_ledger_yields_no_tree_nodes(): void
    {
        // Byte-identical-OFF: when no producer writes tree edges, every row is flat (null tree_status).
        $nodes = [
            ['id' => '1', 'parent_target_id' => null, 'depth' => 0, 'tree_status' => null],
            ['id' => '2', 'parent_target_id' => null, 'depth' => 0, 'tree_status' => null],
        ];

        $this->assertSame([], Tree::pendingLeafIds($nodes));
    }

    public function test_subtree_ids_is_preorder_inclusive(): void
    {
        $byId = [
            'r' => ['parent_target_id' => null],
            'a' => ['parent_target_id' => 'r'],
            'b' => ['parent_target_id' => 'a'],
            'c' => ['parent_target_id' => 'r'],
            'z' => ['parent_target_id' => null], // unrelated
        ];

        $sub = Tree::subtreeIds($byId, 'r');
        $this->assertContains('r', $sub);
        $this->assertContains('a', $sub);
        $this->assertContains('b', $sub);
        $this->assertContains('c', $sub);
        $this->assertNotContains('z', $sub);
        $this->assertSame('r', $sub[0], 'root is first (pre-order)');
        $this->assertCount(4, $sub);
    }

    public function test_child_depth_is_parent_plus_one(): void
    {
        $byId = ['r' => ['depth' => 0], 'a' => ['depth' => 1]];
        $this->assertSame(1, Tree::childDepth($byId, null));   // root child
        $this->assertSame(1, Tree::childDepth($byId, 'r'));    // under depth-0 root
        $this->assertSame(2, Tree::childDepth($byId, 'a'));    // under depth-1
    }

    public function test_prune_lesson_appends_advisory_narrative(): void
    {
        $out = Tree::withPruneLesson(null, 'token-stuffing detected');
        $this->assertSame(['[Pruned: token-stuffing detected]'], $out['pruned_lessons']);

        $out2 = Tree::withPruneLesson($out, 'second reason');
        $this->assertCount(2, $out2['pruned_lessons']);

        $empty = Tree::withPruneLesson(null, '   ');
        $this->assertSame(['pruned'], $empty['pruned_lessons']);
    }
}
