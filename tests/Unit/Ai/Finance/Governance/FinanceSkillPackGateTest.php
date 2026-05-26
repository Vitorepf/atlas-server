<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Finance\Governance;

use App\Services\Ai\Finance\Governance\FinanceSkillPackGate;
use Tests\TestCase;

final class FinanceSkillPackGateTest extends TestCase
{
    private FinanceSkillPackGate $gate;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gate = new FinanceSkillPackGate;
    }

    private function compliantPaymentsPack(): array
    {
        return [
            'pack_id' => 'finance.payments_v1',
            'audit_trail_contract' => 'atlas.finance.audit_trail.v1',
            'skills' => [
                [
                    'skill_id' => 'payments.send_wire',
                    'operator_authority_required' => true,
                    'authorisers_required' => ['operator', 'cfo'],
                    'value_cap_currency_units' => 10000_00, // R$10,000 in cents
                    'daily_aggregate_cap' => 100000_00,
                ],
            ],
        ];
    }

    private function compliantTaxPack(): array
    {
        return [
            'pack_id' => 'finance.tax_v1',
            'audit_trail_contract' => 'atlas.finance.audit_trail.v1',
            'skills' => [
                [
                    'skill_id' => 'tax.generate_quarterly_report',
                    'jurisdiction' => 'BR',
                    'non_clinical_disclaimer_present' => true,
                ],
            ],
        ];
    }

    public function test_approves_compliant_payments_pack(): void
    {
        $r = $this->gate->evaluate($this->compliantPaymentsPack());
        $this->assertSame('approved_for_finance', $r['gate_decision']);
        $this->assertSame([], $r['failed_checks']);
    }

    public function test_approves_compliant_tax_pack(): void
    {
        $r = $this->gate->evaluate($this->compliantTaxPack());
        $this->assertSame('approved_for_finance', $r['gate_decision']);
    }

    public function test_blocks_missing_audit_trail(): void
    {
        $p = $this->compliantPaymentsPack();
        unset($p['audit_trail_contract']);
        $r = $this->gate->evaluate($p);
        $this->assertArrayHasKey('audit_trail_contract', $r['failed_checks']);
    }

    public function test_blocks_payment_skill_without_operator_authority(): void
    {
        $p = $this->compliantPaymentsPack();
        $p['skills'][0]['operator_authority_required'] = false;
        $r = $this->gate->evaluate($p);
        $this->assertArrayHasKey('skills[0].operator_authority_required', $r['failed_checks']);
    }

    public function test_blocks_payment_skill_with_single_authoriser(): void
    {
        $p = $this->compliantPaymentsPack();
        $p['skills'][0]['authorisers_required'] = ['operator'];
        $r = $this->gate->evaluate($p);
        $this->assertArrayHasKey('skills[0].authorisers_required', $r['failed_checks']);
    }

    public function test_blocks_payment_skill_without_value_cap(): void
    {
        $p = $this->compliantPaymentsPack();
        unset($p['skills'][0]['value_cap_currency_units']);
        $r = $this->gate->evaluate($p);
        $this->assertArrayHasKey('skills[0].value_cap_currency_units', $r['failed_checks']);
    }

    public function test_blocks_payment_skill_without_daily_cap(): void
    {
        $p = $this->compliantPaymentsPack();
        unset($p['skills'][0]['daily_aggregate_cap']);
        $r = $this->gate->evaluate($p);
        $this->assertArrayHasKey('skills[0].daily_aggregate_cap', $r['failed_checks']);
    }

    public function test_blocks_tax_skill_without_jurisdiction(): void
    {
        $p = $this->compliantTaxPack();
        unset($p['skills'][0]['jurisdiction']);
        $r = $this->gate->evaluate($p);
        $this->assertArrayHasKey('skills[0].jurisdiction', $r['failed_checks']);
    }

    public function test_blocks_tax_skill_without_disclaimer(): void
    {
        $p = $this->compliantTaxPack();
        $p['skills'][0]['non_clinical_disclaimer_present'] = false;
        $r = $this->gate->evaluate($p);
        $this->assertArrayHasKey('skills[0].non_clinical_disclaimer_present', $r['failed_checks']);
    }

    public function test_envelope_shape_is_stable(): void
    {
        $r = $this->gate->evaluate($this->compliantPaymentsPack());
        $this->assertSame([
            'schema_version', 'pack_id', 'gate_decision', 'passed_checks',
            'failed_checks', 'detail', 'evaluated_at',
        ], array_keys($r));
    }
}
