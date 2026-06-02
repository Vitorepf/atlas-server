<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\HumanSurface;

use App\Services\Ai\HumanSurface\EngineeringGoalCompletenessValidator;
use PHPUnit\Framework\TestCase;

final class EngineeringGoalCompletenessValidatorTest extends TestCase
{
    private EngineeringGoalCompletenessValidator $validator;

    /**
     * @var list<string>
     */
    private const CANONICAL_SLOTS = [
        'target_artifact',
        'change_type',
        'verification',
        'scope_bound',
    ];

    protected function setUp(): void
    {
        $this->validator = new EngineeringGoalCompletenessValidator();
    }

    public function testFullyDetailedGoalIsCompleteWithExpectedPresentSlots(): void
    {
        $result = $this->validator->validate('adiciona um metodo em app/Foo.php que retorna X, com teste');

        $this->assertSame('atlas.human_surface.engineering_goal_completeness.v1', $result['schema_version']);
        $this->assertTrue($result['complete']);
        $this->assertContains('target_artifact', $result['present_slots']);
        $this->assertContains('change_type', $result['present_slots']);
        $this->assertContains('verification', $result['present_slots']);
    }

    public function testVagueGoalIsIncompleteAndMissesTargetArtifact(): void
    {
        $result = $this->validator->validate('arruma o sistema');

        $this->assertFalse($result['complete']);
        $this->assertContains('target_artifact', $result['missing_slots']);
    }

    public function testBugFixWithoutTestsIsCompleteButMissesVerification(): void
    {
        $result = $this->validator->validate('corrige o bug em app/Bar.php');

        $this->assertTrue($result['complete']);
        $this->assertContains('verification', $result['missing_slots']);
    }

    public function testPresentAndMissingSlotsAreDisjointAndUnionIsTheFourCanonicalSlots(): void
    {
        $goals = [
            'adiciona um metodo em app/Foo.php que retorna X, com teste',
            'arruma o sistema',
            'corrige o bug em app/Bar.php',
            '',
            'refator do componente apenas, com assert que deve passar',
            'cria class Widget',
        ];

        foreach ($goals as $goal) {
            $result = $this->validator->validate($goal);

            $present = $result['present_slots'];
            $missing = $result['missing_slots'];

            $this->assertSame([], array_intersect($present, $missing), "present/missing overlap for goal: {$goal}");

            $union = array_merge($present, $missing);
            sort($union);
            $expected = self::CANONICAL_SLOTS;
            sort($expected);

            $this->assertSame($expected, $union, "union is not the four canonical slots for goal: {$goal}");
            $this->assertCount(4, $union, "union size is not 4 for goal: {$goal}");
        }
    }

    public function testMissingSlotsFollowsCanonicalSlotOrder(): void
    {
        $goals = [
            'adiciona um metodo em app/Foo.php que retorna X, com teste',
            'arruma o sistema',
            'corrige o bug em app/Bar.php',
            'refator do componente apenas, com assert que deve passar',
            'cria class Widget',
            'somente leitura',
        ];

        foreach ($goals as $goal) {
            $result = $this->validator->validate($goal);

            $missing = $result['missing_slots'];
            $canonicalFiltered = array_values(array_filter(
                self::CANONICAL_SLOTS,
                static fn (string $slot): bool => in_array($slot, $missing, true),
            ));

            $this->assertSame($canonicalFiltered, $missing, "missing_slots not in canonical order for goal: {$goal}");
        }
    }

    public function testEmptyAndWhitespaceGoalIsIncompleteWithNoPresentSlots(): void
    {
        foreach (['', '   ', "\t\n  "] as $goal) {
            $result = $this->validator->validate($goal);

            $this->assertFalse($result['complete']);
            $this->assertSame([], $result['present_slots']);
            $this->assertSame(self::CANONICAL_SLOTS, $result['missing_slots']);
        }
    }
}
