<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\Programming\ForgeTopology;

use App\Services\Ai\Programming\ForgeTopology\ForgeTopologyFallbackChainCoherenceValidator;
use PHPUnit\Framework\TestCase;

final class ForgeTopologyFallbackChainCoherenceValidatorTest extends TestCase
{
    private ForgeTopologyFallbackChainCoherenceValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new ForgeTopologyFallbackChainCoherenceValidator();
    }

    public function testGapInOrderSequenceIsNonContiguousAndNamesTheGap(): void
    {
        $result = $this->validator->inspect([
            ['order' => 1, 'role' => 'primary', 'capable' => true],
            ['order' => 2, 'role' => 'secondary', 'capable' => true],
            ['order' => 4, 'role' => 'tertiary', 'capable' => true],
        ]);

        $this->assertSame('atlas.aaeos.forge_fallback_coherence.v1', $result['schema_version']);
        $this->assertFalse($result['coherent']);

        $codes = array_map(static fn (array $defect): string => $defect['code'], $result['defects']);
        $this->assertContains('non_contiguous_order', $codes);

        $nonContiguous = $this->defectByCode($result['defects'], 'non_contiguous_order');
        $this->assertStringContainsString('3', $nonContiguous['detail']);

        $this->assertSame(['primary', 'secondary', 'tertiary'], $result['ordered_roles']);
    }

    public function testDuplicateOrderValueAlsoFlagsNonContiguous(): void
    {
        $result = $this->validator->inspect([
            ['order' => 2, 'role' => 'primary', 'capable' => true],
            ['order' => 2, 'role' => 'secondary', 'capable' => true],
        ]);

        $codes = array_map(static fn (array $defect): string => $defect['code'], $result['defects']);
        $this->assertContains('duplicate_order_value', $codes);
        $this->assertContains('non_contiguous_order', $codes);
        $this->assertFalse($result['coherent']);
    }

    public function testEmptyChainHasExactlyOneEmptyDefectAndNoOrderedRoles(): void
    {
        $result = $this->validator->inspect([]);

        $this->assertFalse($result['coherent']);
        $this->assertFalse($result['has_capable_entry']);
        $this->assertSame([], $result['ordered_roles']);
        $this->assertCount(1, $result['defects']);
        $this->assertSame('empty_fallback_chain', $result['defects'][0]['code']);
    }

    public function testContiguousChainWithNoCapableEntryFlagsNoCapableFallback(): void
    {
        $result = $this->validator->inspect([
            ['order' => 1, 'role' => 'primary', 'capable' => false],
            ['order' => 2, 'role' => 'secondary', 'capable' => false],
        ]);

        $this->assertFalse($result['has_capable_entry']);

        $codes = array_map(static fn (array $defect): string => $defect['code'], $result['defects']);
        $this->assertContains('no_capable_fallback', $codes);
        $this->assertNotContains('non_contiguous_order', $codes);
        $this->assertFalse($result['coherent']);
    }

    public function testContiguousDistinctRolesWithCapableEntryIsCoherent(): void
    {
        $result = $this->validator->inspect([
            ['order' => 2, 'role' => 'secondary', 'capable' => false],
            ['order' => 1, 'role' => 'primary', 'capable' => true],
            ['order' => 3, 'role' => 'tertiary', 'capable' => false],
        ]);

        $this->assertTrue($result['coherent']);
        $this->assertSame([], $result['defects']);
        $this->assertTrue($result['has_capable_entry']);
        $this->assertSame(['primary', 'secondary', 'tertiary'], $result['ordered_roles']);
    }

    public function testDuplicateRoleIsFlaggedDistinctlyFromOrderDefects(): void
    {
        $result = $this->validator->inspect([
            ['order' => 1, 'role' => 'primary', 'capable' => true],
            ['order' => 2, 'role' => 'primary', 'capable' => true],
        ]);

        $codes = array_map(static fn (array $defect): string => $defect['code'], $result['defects']);
        $this->assertContains('duplicate_role_in_chain', $codes);
        $this->assertNotContains('non_contiguous_order', $codes);
        $this->assertNotContains('duplicate_order_value', $codes);
        $this->assertFalse($result['coherent']);
    }

    public function testIdenticalInputIsDeterministic(): void
    {
        $chain = [
            ['order' => 1, 'role' => 'primary', 'capable' => true],
            ['order' => 2, 'role' => 'secondary', 'capable' => false],
            ['order' => 4, 'role' => 'tertiary', 'capable' => true],
        ];

        $first = $this->validator->inspect($chain);
        $second = $this->validator->inspect($chain);

        $this->assertSame($first, $second);
    }

    /**
     * @param  list<array{code:string,detail:string}>  $defects
     * @return array{code:string,detail:string}
     */
    private function defectByCode(array $defects, string $code): array
    {
        foreach ($defects as $defect) {
            if ($defect['code'] === $code) {
                return $defect;
            }
        }

        $this->fail('Expected defect with code '.$code.' was not present.');
    }
}
