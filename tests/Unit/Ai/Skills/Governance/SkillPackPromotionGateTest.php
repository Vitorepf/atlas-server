<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Skills\Governance;

use App\Services\Ai\Skills\Governance\SkillPackPromotionGate;
use Tests\TestCase;

final class SkillPackPromotionGateTest extends TestCase
{
    private SkillPackPromotionGate $gate;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gate = new SkillPackPromotionGate;
    }

    private function compliantPack(): array
    {
        return [
            'pack_id' => 'finance.accounts_payable',
            'schema_version' => 'atlas.skills.pack.v1',
            'domain' => 'finance',
            'version' => '1.0.0',
            'quality_gates' => ['contract_test', 'security_scan', 'regression_suite', 'review_gate'],
            'evidence_contracts' => ['atlas.skills.invocation_receipt.v1'],
            'skills' => [
                [
                    'skill_id' => 'finance.list_invoices',
                    'policy_class' => 'read',
                ],
                [
                    'skill_id' => 'finance.approve_payment',
                    'policy_class' => 'danger',
                    'operator_authority_required' => true,
                ],
            ],
            'canon_docs' => ['docs/engineering-knowledge-base/atlas-domain-finance.md'],
        ];
    }

    public function test_approves_compliant_pack(): void
    {
        $r = $this->gate->evaluate($this->compliantPack());
        $this->assertSame('promotion_approved', $r['promotion_decision']);
        $this->assertSame([], $r['failed_checks']);
        $this->assertSame('atlas.skills.pack_promotion.v1', $r['schema_version']);
    }

    public function test_blocks_when_required_field_missing(): void
    {
        $p = $this->compliantPack();
        unset($p['canon_docs']);
        $r = $this->gate->evaluate($p);
        $this->assertArrayHasKey('canon_docs', $r['failed_checks']);
    }

    public function test_blocks_when_quality_gates_below_minimum(): void
    {
        $p = $this->compliantPack();
        $p['quality_gates'] = ['only_one'];
        $r = $this->gate->evaluate($p);
        $this->assertArrayHasKey('quality_gates', $r['failed_checks']);
    }

    public function test_blocks_invalid_skill_policy_class(): void
    {
        $p = $this->compliantPack();
        $p['skills'][0]['policy_class'] = 'wibble';
        $r = $this->gate->evaluate($p);
        $this->assertArrayHasKey('skills[0].policy_class', $r['failed_checks']);
    }

    public function test_blocks_danger_skill_without_operator_authority(): void
    {
        $p = $this->compliantPack();
        $p['skills'][1]['operator_authority_required'] = false;
        $r = $this->gate->evaluate($p);
        $this->assertArrayHasKey('skills[1].operator_authority_required', $r['failed_checks']);
    }

    public function test_blocks_skill_missing_id(): void
    {
        $p = $this->compliantPack();
        unset($p['skills'][0]['skill_id']);
        $r = $this->gate->evaluate($p);
        $this->assertArrayHasKey('skills[0].skill_id', $r['failed_checks']);
    }

    public function test_blocks_non_semver_version(): void
    {
        $p = $this->compliantPack();
        $p['version'] = '1.0';
        $r = $this->gate->evaluate($p);
        $this->assertArrayHasKey('version', $r['failed_checks']);
    }

    public function test_envelope_shape_is_stable(): void
    {
        $r = $this->gate->evaluate($this->compliantPack());
        $this->assertSame([
            'schema_version', 'pack_id', 'domain', 'promotion_decision',
            'passed_checks', 'failed_checks', 'detail', 'evaluated_at',
        ], array_keys($r));
    }
}
