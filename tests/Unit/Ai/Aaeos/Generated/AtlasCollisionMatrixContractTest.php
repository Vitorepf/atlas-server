<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasCollisionMatrixContractService;
use Tests\TestCase;

/**
 * Pins the doc's Pair Schema and the four Collision Rules: a pair is blocked
 * when allowed files overlap, either side is withheld hot work, a dependency
 * relation requires ordering, or either packet allows a Voice/Kernel hot scope.
 * Also pins safe parallel groups and the deterministic matrix hash. Pure, no DB.
 *
 * @see docs/engineering-knowledge-base/self-construction/collision-matrix-contract.md
 */
class AtlasCollisionMatrixContractTest extends TestCase
{
    private function service(): AtlasCollisionMatrixContractService
    {
        return new AtlasCollisionMatrixContractService;
    }

    public function test_disjoint_packets_are_parallel_safe(): void
    {
        $pair = $this->service()->comparePair(
            ['id' => 'AIP-SPLIT-A', 'allowed' => ['app/Services/Foo.php']],
            ['id' => 'AIP-SPLIT-B', 'allowed' => ['app/Services/Bar.php']],
        );

        $this->assertSame([], $pair['overlap']);
        $this->assertFalse($pair['collision']);
        $this->assertFalse($pair['hot_scope_present']);
        $this->assertSame(AtlasCollisionMatrixContractService::DECISION_PARALLEL_SAFE, $pair['decision']);
        $this->assertSame([], $pair['block_reasons']);
    }

    public function test_rule1_allowed_file_overlap_blocks_the_pair(): void
    {
        // Case-insensitive overlap on the same allowed file.
        $pair = $this->service()->comparePair(
            ['id' => 'L', 'allowed' => ['App/Services/Foo.php', 'app/Services/Only.php']],
            ['id' => 'R', 'allowed' => ['app/services/foo.php']],
        );

        $this->assertSame(['app/services/foo.php'], $pair['overlap']);
        $this->assertTrue($pair['collision']);
        $this->assertSame(AtlasCollisionMatrixContractService::DECISION_BLOCKED, $pair['decision']);
        $this->assertContains(AtlasCollisionMatrixContractService::REASON_ALLOWED_FILE_OVERLAP, $pair['block_reasons']);
    }

    public function test_rule2_withheld_hot_work_blocks_even_with_no_overlap(): void
    {
        $pair = $this->service()->comparePair(
            ['id' => 'L', 'allowed' => ['a.php'], 'withheld_hot' => false],
            ['id' => 'R', 'allowed' => ['b.php'], 'withheld_hot' => true],
        );

        $this->assertSame([], $pair['overlap']);
        $this->assertTrue($pair['collision']);
        $this->assertTrue($pair['hot_scope_present']);
        $this->assertContains(AtlasCollisionMatrixContractService::REASON_WITHHELD_HOT_WORK, $pair['block_reasons']);
    }

    public function test_rule3_dependency_relation_requires_ordering_blocks_the_pair(): void
    {
        $pair = $this->service()->comparePair(
            ['id' => 'AIP-A', 'allowed' => ['a.php'], 'depends_on' => ['AIP-B']],
            ['id' => 'AIP-B', 'allowed' => ['b.php'], 'depends_on' => []],
        );

        $this->assertSame([], $pair['overlap']);
        $this->assertTrue($pair['dependency_related']);
        $this->assertTrue($pair['collision']);
        $this->assertContains(AtlasCollisionMatrixContractService::REASON_DEPENDENCY_ORDERING, $pair['block_reasons']);
    }

    public function test_rule4_voice_or_kernel_hot_scope_blocks_the_pair(): void
    {
        $pair = $this->service()->comparePair(
            ['id' => 'L', 'allowed' => ['app/Services/Ai/Voice/Realtime/Runner.php']],
            ['id' => 'R', 'allowed' => ['app/Services/Bar.php']],
        );

        $this->assertTrue($pair['collision']);
        $this->assertTrue($pair['hot_scope_present']);
        $this->assertContains(AtlasCollisionMatrixContractService::REASON_VOICE_KERNEL_HOT_SCOPE, $pair['block_reasons']);
    }

    public function test_matrix_groups_safe_packets_and_isolates_the_collider(): void
    {
        // A & B disjoint -> safe together; C overlaps A on Foo.php -> isolated.
        $matrix = $this->service()->buildMatrix([
            ['id' => 'A', 'allowed' => ['Foo.php']],
            ['id' => 'B', 'allowed' => ['Bar.php']],
            ['id' => 'C', 'allowed' => ['Foo.php']],
        ]);

        $this->assertSame(3, $matrix['pair_count']); // C(3,2) = 3 unordered pairs
        $this->assertSame(1, $matrix['collision_count']); // only A<->C collide
        $this->assertFalse($matrix['all_parallel_safe']);

        // A and B share a group; C is alone because it collides with A.
        $this->assertContains(['A', 'B'], $matrix['safe_parallel_groups']);
        $this->assertContains(['C'], $matrix['safe_parallel_groups']);
    }

    public function test_matrix_hash_is_deterministic_and_changes_with_decisions(): void
    {
        $safeBatch = [
            ['id' => 'A', 'allowed' => ['Foo.php']],
            ['id' => 'B', 'allowed' => ['Bar.php']],
        ];
        $collidingBatch = [
            ['id' => 'A', 'allowed' => ['Foo.php']],
            ['id' => 'B', 'allowed' => ['Foo.php']],
        ];

        $hashSafe1 = $this->service()->buildMatrix($safeBatch)['matrix_hash'];
        $hashSafe2 = $this->service()->buildMatrix($safeBatch)['matrix_hash'];
        $hashColliding = $this->service()->buildMatrix($collidingBatch)['matrix_hash'];

        $this->assertStringStartsWith('sha256:', $hashSafe1);
        $this->assertSame($hashSafe1, $hashSafe2); // deterministic
        $this->assertNotSame($hashSafe1, $hashColliding); // decision flip changes hash
    }
}
