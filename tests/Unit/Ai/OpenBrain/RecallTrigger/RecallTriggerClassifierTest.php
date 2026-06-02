<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\OpenBrain\RecallTrigger;

use App\Services\Ai\OpenBrain\RecallTrigger\RecallTriggerClassifier;
use PHPUnit\Framework\TestCase;

final class RecallTriggerClassifierTest extends TestCase
{
    private RecallTriggerClassifier $classifier;

    protected function setUp(): void
    {
        $this->classifier = new RecallTriggerClassifier();
    }

    public function testRenameOrTypoSignalReturnsNoneNonMandatory(): void
    {
        $result = $this->classifier->classify([
            'description' => 'rename variable foo to bar',
            'is_rename_or_typo' => true,
            'touches_decision_keywords' => true,
            'touches_multi_module' => true,
            'risk_level' => 'high',
        ]);

        $this->assertSame('none', $result['action']);
        $this->assertFalse($result['mandatory']);
        $this->assertSame([], $result['reasons']);
    }

    public function testMetaQuestionSignalReturnsNone(): void
    {
        $result = $this->classifier->classify([
            'description' => 'how does the Atlas MCP describe itself',
            'is_meta_question' => true,
            'risk_level' => 'secret',
        ]);

        $this->assertSame('none', $result['action']);
        $this->assertFalse($result['mandatory']);
        $this->assertSame([], $result['reasons']);
    }

    public function testMultiModuleReturnsContextPackMandatory(): void
    {
        $result = $this->classifier->classify([
            'description' => 'refactor the gateway across many services',
            'touches_multi_module' => true,
            'risk_level' => 'normal',
        ]);

        $this->assertSame('context_pack', $result['action']);
        $this->assertTrue($result['mandatory']);
        $this->assertContains('touches_multi_module', $result['reasons']);
        $this->assertNotEmpty($result['reasons']);
    }

    public function testHighRiskReturnsContextPackMandatory(): void
    {
        $result = $this->classifier->classify([
            'description' => 'apply a one-line tweak',
            'risk_level' => 'high',
        ]);

        $this->assertSame('context_pack', $result['action']);
        $this->assertTrue($result['mandatory']);
        $this->assertContains('elevated_risk_level:high', $result['reasons']);
    }

    public function testSecretRiskReturnsContextPackMandatory(): void
    {
        $result = $this->classifier->classify([
            'description' => 'touch the cyber secret path',
            'risk_level' => 'secret',
        ]);

        $this->assertSame('context_pack', $result['action']);
        $this->assertTrue($result['mandatory']);
        $this->assertContains('elevated_risk_level:secret', $result['reasons']);
    }

    public function testSensitiveRiskReturnsContextPackMandatory(): void
    {
        $result = $this->classifier->classify([
            'description' => 'edit sensitive local-first data',
            'risk_level' => 'sensitive',
        ]);

        $this->assertSame('context_pack', $result['action']);
        $this->assertTrue($result['mandatory']);
        $this->assertContains('elevated_risk_level:sensitive', $result['reasons']);
    }

    public function testDecisionKeywordsReturnRecallMandatory(): void
    {
        $result = $this->classifier->classify([
            'description' => 'change the placement decision flow',
            'touches_decision_keywords' => true,
            'risk_level' => 'normal',
        ]);

        $this->assertSame('recall', $result['action']);
        $this->assertTrue($result['mandatory']);
        $this->assertContains('touches_decision_keywords', $result['reasons']);
        $this->assertNotEmpty($result['reasons']);
    }

    public function testMeaningfulDescriptionOnlyReturnsRecallNonMandatory(): void
    {
        $result = $this->classifier->classify([
            'description' => 'add a small helper method to format dates',
            'risk_level' => 'normal',
        ]);

        $this->assertSame('recall', $result['action']);
        $this->assertFalse($result['mandatory']);
        $this->assertContains('meaningful_description', $result['reasons']);
        $this->assertNotEmpty($result['reasons']);
    }

    public function testEmptyDescriptionReturnsNone(): void
    {
        $result = $this->classifier->classify([
            'description' => '',
            'risk_level' => 'normal',
        ]);

        $this->assertSame('none', $result['action']);
        $this->assertFalse($result['mandatory']);
        $this->assertSame([], $result['reasons']);
    }

    public function testWhitespaceOnlyDescriptionReturnsNone(): void
    {
        $result = $this->classifier->classify([
            'description' => "   \t  \n ",
        ]);

        $this->assertSame('none', $result['action']);
        $this->assertFalse($result['mandatory']);
        $this->assertSame([], $result['reasons']);
    }

    public function testContradictionMetaAndDecisionReturnsNoneByOrdering(): void
    {
        $result = $this->classifier->classify([
            'description' => 'is this a decision already catalogued',
            'is_meta_question' => true,
            'touches_decision_keywords' => true,
            'risk_level' => 'normal',
        ]);

        $this->assertSame('none', $result['action']);
        $this->assertFalse($result['mandatory']);
        $this->assertSame([], $result['reasons']);
    }

    public function testKbHealthDescriptionReturnsMaintenanceStatus(): void
    {
        $result = $this->classifier->classify([
            'description' => 'check the kb health and drift',
            'risk_level' => 'normal',
        ]);

        $this->assertSame('maintenance_status', $result['action']);
        $this->assertFalse($result['mandatory']);
        $this->assertContains('maintenance_signal:kb health', $result['reasons']);
        $this->assertNotEmpty($result['reasons']);
    }

    public function testStaleMemoryDescriptionReturnsMaintenanceStatus(): void
    {
        $result = $this->classifier->classify([
            'description' => 'the projection looks stale, verify it',
            'risk_level' => 'normal',
        ]);

        $this->assertSame('maintenance_status', $result['action']);
        $this->assertFalse($result['mandatory']);
        $this->assertContains('maintenance_signal:stale', $result['reasons']);
    }

    public function testMemoryStatusDescriptionReturnsMaintenanceStatus(): void
    {
        $result = $this->classifier->classify([
            'description' => 'show me the memory status report',
        ]);

        $this->assertSame('maintenance_status', $result['action']);
        $this->assertContains('maintenance_signal:memory status', $result['reasons']);
    }

    public function testMaintenanceSignalYieldsToHigherContextPackBranch(): void
    {
        $result = $this->classifier->classify([
            'description' => 'the kb health audit touches many modules',
            'touches_multi_module' => true,
            'risk_level' => 'normal',
        ]);

        $this->assertSame('context_pack', $result['action']);
        $this->assertTrue($result['mandatory']);
        $this->assertContains('touches_multi_module', $result['reasons']);
    }

    public function testMaintenanceSignalYieldsToDecisionKeywordBranch(): void
    {
        $result = $this->classifier->classify([
            'description' => 'review the stale decision record',
            'touches_decision_keywords' => true,
            'risk_level' => 'normal',
        ]);

        $this->assertSame('recall', $result['action']);
        $this->assertTrue($result['mandatory']);
        $this->assertContains('touches_decision_keywords', $result['reasons']);
    }

    public function testReasonsNonEmptyForEveryNonNoneAction(): void
    {
        $contextPack = $this->classifier->classify([
            'description' => 'wide reaching change',
            'touches_multi_module' => true,
        ]);
        $recall = $this->classifier->classify([
            'description' => 'something meaningful here',
        ]);
        $maintenance = $this->classifier->classify([
            'description' => 'is the kb health okay',
        ]);

        $this->assertNotEmpty($contextPack['reasons']);
        $this->assertNotEmpty($recall['reasons']);
        $this->assertNotEmpty($maintenance['reasons']);
    }

    public function testContextPackReportsBothReasonsWhenMultiModuleAndElevatedRisk(): void
    {
        $result = $this->classifier->classify([
            'description' => 'big sensitive multi-module change',
            'touches_multi_module' => true,
            'risk_level' => 'sensitive',
        ]);

        $this->assertSame('context_pack', $result['action']);
        $this->assertSame(['touches_multi_module', 'elevated_risk_level:sensitive'], $result['reasons']);
    }

    public function testLowRiskDoesNotTriggerContextPack(): void
    {
        $result = $this->classifier->classify([
            'description' => 'tiny adjustment with low risk',
            'risk_level' => 'low',
        ]);

        $this->assertSame('recall', $result['action']);
        $this->assertFalse($result['mandatory']);
    }

    public function testRiskLevelIsCaseInsensitive(): void
    {
        $result = $this->classifier->classify([
            'description' => 'edit something',
            'risk_level' => 'HIGH',
        ]);

        $this->assertSame('context_pack', $result['action']);
        $this->assertTrue($result['mandatory']);
        $this->assertContains('elevated_risk_level:high', $result['reasons']);
    }

    public function testMissingSignalsDefaultToEmptyDescriptionNone(): void
    {
        $result = $this->classifier->classify([]);

        $this->assertSame('none', $result['action']);
        $this->assertFalse($result['mandatory']);
        $this->assertSame([], $result['reasons']);
    }

    public function testIdenticalInputIsDeterministic(): void
    {
        $signals = [
            'description' => 'change the decision flow widely',
            'touches_decision_keywords' => true,
            'touches_multi_module' => false,
            'risk_level' => 'normal',
        ];

        $first = $this->classifier->classify($signals);
        $second = $this->classifier->classify($signals);

        $this->assertSame($first, $second);
    }
}
