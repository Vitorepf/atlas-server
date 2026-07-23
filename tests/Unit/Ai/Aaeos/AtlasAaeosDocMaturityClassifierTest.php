<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos;

use App\Services\Ai\AgenticEngineeringOs\Maturity\AtlasDocMaturityClassifier;
use PHPUnit\Framework\TestCase;

final class AtlasAaeosDocMaturityClassifierTest extends TestCase
{
    private AtlasDocMaturityClassifier $classifier;

    protected function setUp(): void
    {
        $this->classifier = new AtlasDocMaturityClassifier();
    }

    public function testSchemaVersionIsStable(): void
    {
        $result = $this->classifier->classify(['mother_doc' => true]);

        $this->assertSame('atlas.aaeos.doc_maturity.v1', $result['schema_version']);
    }

    public function testRuleOneNoMotherNoContractsIsL0(): void
    {
        $result = $this->classifier->classify([
            'mother_doc' => false,
            'contracts' => false,
        ]);

        $this->assertSame('DOC L0', $result['level']);
        $this->assertSame(0, $result['level_ordinal']);
        $this->assertSame(['mother_doc'], $result['missing_for_next']);
        $this->assertSame([], $result['satisfied']);
    }

    public function testRuleTwoMotherOnlyIsL1(): void
    {
        $result = $this->classifier->classify([
            'mother_doc' => true,
        ]);

        $this->assertSame('DOC L1', $result['level']);
        $this->assertSame(1, $result['level_ordinal']);
        $this->assertSame(['contracts'], $result['missing_for_next']);
        $this->assertSame(['mother_doc'], $result['satisfied']);
    }

    public function testRuleThreeMotherContractsRunbookNoneIsL2(): void
    {
        $result = $this->classifier->classify([
            'mother_doc' => true,
            'contracts' => true,
            'runbook' => 'none',
        ]);

        $this->assertSame('DOC L2', $result['level']);
        $this->assertSame(2, $result['level_ordinal']);
        $this->assertSame(['runbook'], $result['missing_for_next']);
        $this->assertSame(['mother_doc', 'contracts'], $result['satisfied']);
    }

    public function testRuleFourMotherContractsStrongRunbookPartialGatesIsL3(): void
    {
        $result = $this->classifier->classify([
            'mother_doc' => true,
            'contracts' => true,
            'runbook' => 'strong',
            'gates' => 'partial',
        ]);

        $this->assertSame('DOC L3', $result['level']);
        $this->assertSame(3, $result['level_ordinal']);
    }

    public function testRuleFiveAllStrongIsL4WithNoMissingForNext(): void
    {
        $result = $this->classifier->classify([
            'mother_doc' => true,
            'contracts' => true,
            'runbook' => 'strong',
            'matrix' => 'strong',
            'quality_bar' => 'strong',
            'evidence' => 'strong',
            'gates' => 'strong',
        ]);

        $this->assertSame('DOC L4', $result['level']);
        $this->assertSame(4, $result['level_ordinal']);
        $this->assertSame([], $result['missing_for_next']);
        $this->assertSame(
            ['mother_doc', 'contracts', 'runbook', 'matrix', 'quality_bar', 'evidence', 'gates'],
            $result['satisfied'],
        );
    }

    public function testRuleSixMotherOnlyOrdinalIsBelowTwo(): void
    {
        $result = $this->classifier->classify([
            'mother_doc' => true,
        ]);

        $this->assertLessThan(2, $result['level_ordinal']);
    }

    public function testRuleSevenFullyStrongL4IsNeverRuntimeReady(): void
    {
        $result = $this->classifier->classify([
            'mother_doc' => true,
            'contracts' => true,
            'runbook' => 'strong',
            'matrix' => 'strong',
            'quality_bar' => 'strong',
            'evidence' => 'strong',
            'gates' => 'strong',
        ]);

        $this->assertFalse($result['runtime_ready']);
    }

    public function testRuleEightOrdinalMatchesIntegerSuffixAcrossFiveFixtures(): void
    {
        $fixtures = [
            ['mother_doc' => false, 'contracts' => false],
            ['mother_doc' => true],
            ['mother_doc' => true, 'contracts' => true, 'runbook' => 'none'],
            ['mother_doc' => true, 'contracts' => true, 'runbook' => 'strong', 'gates' => 'partial'],
            [
                'mother_doc' => true,
                'contracts' => true,
                'runbook' => 'strong',
                'matrix' => 'strong',
                'quality_bar' => 'strong',
                'evidence' => 'strong',
                'gates' => 'strong',
            ],
        ];

        foreach ($fixtures as $fixture) {
            $result = $this->classifier->classify($fixture);
            $suffix = (int) substr($result['level'], -1);

            $this->assertSame($suffix, $result['level_ordinal']);
        }
    }

    public function testRuleNineMissingForNextAtL3NamesGatesOrEvidence(): void
    {
        $result = $this->classifier->classify([
            'mother_doc' => true,
            'contracts' => true,
            'runbook' => 'strong',
            'gates' => 'partial',
        ]);

        $this->assertSame('DOC L3', $result['level']);
        $this->assertTrue(
            in_array('gates', $result['missing_for_next'], true)
            || in_array('evidence', $result['missing_for_next'], true),
        );
    }

    public function testRuleTenUppercaseStrongIsNormalised(): void
    {
        $result = $this->classifier->classify([
            'mother_doc' => true,
            'contracts' => true,
            'runbook' => 'STRONG',
            'matrix' => 'STRONG',
            'quality_bar' => 'STRONG',
            'evidence' => 'STRONG',
            'gates' => 'STRONG',
        ]);

        $this->assertSame('DOC L4', $result['level']);
        $this->assertSame(4, $result['level_ordinal']);
    }

    public function testMixedCaseAndWhitespaceStrengthNormalisesToL3(): void
    {
        // Generalisation guard: values not present in any other fixture.
        $result = $this->classifier->classify([
            'mother_doc' => true,
            'contracts' => true,
            'runbook' => '  StRoNg  ',
            'matrix' => 'Partial',
            'quality_bar' => 'STRONG',
            'evidence' => 'none',
            'gates' => 'unknown-token',
        ]);

        $this->assertSame('DOC L3', $result['level']);
        $this->assertSame(3, $result['level_ordinal']);
        // matrix(partial), evidence(none), gates(unknown->none) remain weak; quality_bar is strong.
        $this->assertSame(['matrix', 'evidence', 'gates'], $result['missing_for_next']);
        $this->assertSame(['mother_doc', 'contracts', 'runbook', 'quality_bar'], $result['satisfied']);
    }

    public function testStrongSignalsWithoutStrongRunbookStaysL2(): void
    {
        // Generalisation guard: strong signals cannot leapfrog a missing runbook.
        $result = $this->classifier->classify([
            'mother_doc' => true,
            'contracts' => true,
            'runbook' => 'partial',
            'matrix' => 'strong',
            'quality_bar' => 'strong',
            'evidence' => 'strong',
            'gates' => 'strong',
        ]);

        $this->assertSame('DOC L2', $result['level']);
        $this->assertSame(2, $result['level_ordinal']);
        $this->assertSame(['runbook'], $result['missing_for_next']);
    }

    public function testContractsWithoutMotherCannotReachL2(): void
    {
        // Generalisation guard: contracts alone (no mother) stays at L0.
        $result = $this->classifier->classify([
            'contracts' => true,
            'runbook' => 'strong',
        ]);

        $this->assertSame('DOC L0', $result['level']);
        $this->assertSame(0, $result['level_ordinal']);
        $this->assertSame(['mother_doc'], $result['missing_for_next']);
    }

    public function testClassificationIsDeterministic(): void
    {
        $sections = [
            'mother_doc' => true,
            'contracts' => true,
            'runbook' => 'strong',
            'gates' => 'partial',
        ];

        $first = $this->classifier->classify($sections);
        $second = $this->classifier->classify($sections);

        $this->assertSame($first, $second);
    }
}
