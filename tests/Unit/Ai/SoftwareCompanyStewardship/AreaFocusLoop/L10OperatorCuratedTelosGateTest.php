<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\L10OperatorCuratedTelosGate;
use PHPUnit\Framework\TestCase;

final class L10OperatorCuratedTelosGateTest extends TestCase
{
    private L10OperatorCuratedTelosGate $gate;

    protected function setUp(): void
    {
        $this->gate = new L10OperatorCuratedTelosGate();
    }

    /**
     * @return array{0: array<string,mixed>, 1: array<string,mixed>}
     */
    private function curatedAdmission(): array
    {
        $proposal = [
            'curated_telos_id' => 'telos_3y_kernel_north_star',
            'scope' => 'engineering_only',
        ];

        $operatorDecision = [
            'operator_receipt_id' => 'receipt_op_2026_06_01',
            'approval_mode' => 'explicit',
            'operator_curated' => true,
            'approved' => true,
        ];

        return [$proposal, $operatorDecision];
    }

    public function testExplicitlyCuratedTelosWithReceiptAndEngineeringScopeIsAdmitted(): void
    {
        [$proposal, $operatorDecision] = $this->curatedAdmission();

        $result = $this->gate->admit($proposal, $operatorDecision);

        $this->assertSame('atlas.aaeos.l10.operator_curated_telos_gate.v1', $result['schema_version']);
        $this->assertTrue($result['admitted']);
        $this->assertSame('receipt_op_2026_06_01', $result['operator_receipt_id']);
        $this->assertSame('telos_3y_kernel_north_star', $result['curated_telos_id']);
        $this->assertSame('engineering_only', $result['scope']);
        $this->assertTrue($result['scope_in_engineering']);
        $this->assertSame('explicit', $result['approval_mode']);
        $this->assertTrue($result['operator_curated']);
        $this->assertSame([], $result['blockers']);
        $this->assertSame('telos_operator_curated_and_admitted', $result['reason']);
    }

    public function testReturnEnvelopeExposesEveryContractField(): void
    {
        [$proposal, $operatorDecision] = $this->curatedAdmission();

        $result = $this->gate->admit($proposal, $operatorDecision);

        $this->assertArrayHasKey('admitted', $result);
        $this->assertArrayHasKey('operator_receipt_id', $result);
        $this->assertArrayHasKey('curated_telos_id', $result);
        $this->assertArrayHasKey('scope', $result);
        $this->assertArrayHasKey('blockers', $result);

        $this->assertIsBool($result['admitted']);
        $this->assertIsString($result['operator_receipt_id']);
        $this->assertIsString($result['curated_telos_id']);
        $this->assertIsString($result['scope']);
        $this->assertIsArray($result['blockers']);
    }

    public function testMissingOperatorReceiptRejects(): void
    {
        [$proposal, $operatorDecision] = $this->curatedAdmission();
        unset($operatorDecision['operator_receipt_id']);

        $result = $this->gate->admit($proposal, $operatorDecision);

        $this->assertFalse($result['admitted']);
        $this->assertSame('', $result['operator_receipt_id']);
        $this->assertContains('operator_receipt_missing', $result['blockers']);
        $this->assertSame('telos_rejected_not_operator_curated', $result['reason']);
    }

    public function testBlankOperatorReceiptRejects(): void
    {
        [$proposal, $operatorDecision] = $this->curatedAdmission();
        $operatorDecision['operator_receipt_id'] = '   ';

        $result = $this->gate->admit($proposal, $operatorDecision);

        $this->assertFalse($result['admitted']);
        $this->assertSame('', $result['operator_receipt_id']);
        $this->assertContains('operator_receipt_missing', $result['blockers']);
    }

    public function testNonEngineeringScopeRejectsAndEchoesDeclaredScope(): void
    {
        [$proposal, $operatorDecision] = $this->curatedAdmission();
        $proposal['scope'] = 'marketing';

        $result = $this->gate->admit($proposal, $operatorDecision);

        $this->assertFalse($result['admitted']);
        $this->assertSame('marketing', $result['scope']);
        $this->assertFalse($result['scope_in_engineering']);
        $this->assertContains('non_engineering_scope', $result['blockers']);
    }

    public function testFinanceScopeAlsoRejectsProvingScopeRuleGeneralises(): void
    {
        [$proposal, $operatorDecision] = $this->curatedAdmission();
        $proposal['scope'] = 'FINANCE';

        $result = $this->gate->admit($proposal, $operatorDecision);

        $this->assertFalse($result['admitted']);
        $this->assertSame('finance', $result['scope']);
        $this->assertFalse($result['scope_in_engineering']);
        $this->assertContains('non_engineering_scope', $result['blockers']);
    }

    public function testImplicitApprovalRejects(): void
    {
        [$proposal, $operatorDecision] = $this->curatedAdmission();
        $operatorDecision['approval_mode'] = 'implicit';

        $result = $this->gate->admit($proposal, $operatorDecision);

        $this->assertFalse($result['admitted']);
        $this->assertSame('implicit', $result['approval_mode']);
        $this->assertFalse($result['operator_curated']);
        $this->assertContains('implicit_approval_not_curation', $result['blockers']);
    }

    public function testAutoGrantedApprovalRejectsAndNormalisesToImplicit(): void
    {
        [$proposal, $operatorDecision] = $this->curatedAdmission();
        $operatorDecision['approval_mode'] = 'auto';

        $result = $this->gate->admit($proposal, $operatorDecision);

        $this->assertFalse($result['admitted']);
        $this->assertSame('implicit', $result['approval_mode']);
        $this->assertFalse($result['operator_curated']);
        $this->assertContains('implicit_approval_not_curation', $result['blockers']);
    }

    public function testSelfAuthorizedTelosWithNoCurationFlagsIsRejected(): void
    {
        $proposal = [
            'telos_proposal_id' => 'telos_self_authored',
            'scope' => 'engineering_only',
        ];

        // A receipt exists, but the operator never curated/approved — pure
        // self-authorization. DoD: operator-curated, never self-authorized.
        $operatorDecision = [
            'operator_receipt_id' => 'receipt_x',
        ];

        $result = $this->gate->admit($proposal, $operatorDecision);

        $this->assertFalse($result['admitted']);
        $this->assertSame('implicit', $result['approval_mode']);
        $this->assertFalse($result['operator_curated']);
        $this->assertContains('implicit_approval_not_curation', $result['blockers']);
    }

    public function testCurationDerivedFromBooleansWhenApprovalModeAbsent(): void
    {
        $proposal = [
            'telos_id' => 'telos_from_booleans',
            'scope' => 'engineering_only',
        ];

        $operatorDecision = [
            'operator_receipt_id' => 'receipt_bool',
            'operator_curated' => true,
            'approved' => true,
        ];

        $result = $this->gate->admit($proposal, $operatorDecision);

        $this->assertTrue($result['admitted']);
        $this->assertSame('explicit', $result['approval_mode']);
        $this->assertTrue($result['operator_curated']);
        $this->assertSame('telos_from_booleans', $result['curated_telos_id']);
        $this->assertSame([], $result['blockers']);
    }

    public function testCuratedButNotApprovedDerivesImplicitAndRejects(): void
    {
        $proposal = [
            'telos_id' => 'telos_partial',
            'scope' => 'engineering_only',
        ];

        $operatorDecision = [
            'operator_receipt_id' => 'receipt_partial',
            'operator_curated' => true,
            'approved' => false,
        ];

        $result = $this->gate->admit($proposal, $operatorDecision);

        $this->assertFalse($result['admitted']);
        $this->assertSame('implicit', $result['approval_mode']);
        $this->assertContains('implicit_approval_not_curation', $result['blockers']);
    }

    public function testAbsentScopeDefaultsToEngineeringOnlyAndAdmits(): void
    {
        $proposal = [
            'curated_telos_id' => 'telos_default_scope',
        ];

        $operatorDecision = [
            'receipt_id' => 'receipt_alt_key',
            'approval_mode' => 'explicit',
        ];

        // approval_mode=explicit alone is sufficient curation here.
        $operatorDecision['operator_curated'] = true;
        $operatorDecision['approved'] = true;

        $result = $this->gate->admit($proposal, $operatorDecision);

        $this->assertTrue($result['admitted']);
        $this->assertSame('engineering_only', $result['scope']);
        $this->assertTrue($result['scope_in_engineering']);
        $this->assertSame('receipt_alt_key', $result['operator_receipt_id']);
        $this->assertSame('telos_default_scope', $result['curated_telos_id']);
    }

    public function testAllThreeFailuresAccumulateInDeclaredOrder(): void
    {
        $proposal = [
            'curated_telos_id' => 'telos_broken',
            'scope' => 'trading',
        ];

        // No receipt, non-engineering scope, implicit approval -> all three.
        $operatorDecision = [
            'approval_mode' => 'implicit',
        ];

        $result = $this->gate->admit($proposal, $operatorDecision);

        $this->assertFalse($result['admitted']);
        $this->assertSame(
            [
                'operator_receipt_missing',
                'non_engineering_scope',
                'implicit_approval_not_curation',
            ],
            $result['blockers'],
        );
        $this->assertContainsOnlyString($result['blockers']);
        $this->assertSame(array_values($result['blockers']), $result['blockers']);
        $this->assertSame('', $result['operator_receipt_id']);
        $this->assertSame('trading', $result['scope']);
    }

    public function testDistinctInputsProduceDistinctComputedIdentitiesNotCannedValues(): void
    {
        $first = $this->gate->admit(
            ['curated_telos_id' => 'telos_alpha', 'scope' => 'engineering_only'],
            ['operator_receipt_id' => 'receipt_alpha', 'approval_mode' => 'explicit', 'operator_curated' => true, 'approved' => true],
        );

        $second = $this->gate->admit(
            ['curated_telos_id' => 'telos_beta', 'scope' => 'engineering_only'],
            ['operator_receipt_id' => 'receipt_beta', 'approval_mode' => 'explicit', 'operator_curated' => true, 'approved' => true],
        );

        $this->assertTrue($first['admitted']);
        $this->assertTrue($second['admitted']);
        $this->assertSame('telos_alpha', $first['curated_telos_id']);
        $this->assertSame('receipt_alpha', $first['operator_receipt_id']);
        $this->assertSame('telos_beta', $second['curated_telos_id']);
        $this->assertSame('receipt_beta', $second['operator_receipt_id']);
        $this->assertNotSame($first['curated_telos_id'], $second['curated_telos_id']);
    }

    public function testAdmissionIsDeterministic(): void
    {
        [$proposal, $operatorDecision] = $this->curatedAdmission();

        $firstRun = $this->gate->admit($proposal, $operatorDecision);
        $secondRun = $this->gate->admit($proposal, $operatorDecision);

        $this->assertSame($firstRun, $secondRun);
    }
}
