<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Skills\Hermes;

use App\Services\Ai\Skills\Governance\HermesSkillProvisionGate;
use Tests\TestCase;

final class HermesSkillProvisionGateTest extends TestCase
{
    private HermesSkillProvisionGate $gate;

    private string $atlasDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gate = new HermesSkillProvisionGate;
        $this->atlasDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'atlas-hermes-skills-gate-'.bin2hex(random_bytes(6));
    }

    private function approvedVerdict(): array
    {
        return ['promotion_decision' => 'promotion_approved'];
    }

    private function record(array $overrides = []): array
    {
        return array_merge([
            'skill_id' => 'programming.repair_orchestrator',
            'name' => 'Repair Orchestrator',
            'promotion_allowed' => true,
            'risk_level' => 'medium',
        ], $overrides);
    }

    public function test_approves_when_verdict_approved_and_promotion_allowed_and_atlas_owned(): void
    {
        $r = $this->gate->evaluate($this->record(), $this->approvedVerdict(), $this->atlasDir);

        $this->assertSame('provision_approved', $r['provision_decision']);
        $this->assertTrue($r['provision_allowed_now']);
        $this->assertSame([], $r['failed_checks']);
        $this->assertContains('promotion_gate_approved', $r['passed_checks']);
        $this->assertContains('record_promotion_allowed', $r['passed_checks']);
        $this->assertContains('external_dir_atlas_owned', $r['passed_checks']);
    }

    public function test_schema_version_is_correct(): void
    {
        $r = $this->gate->evaluate($this->record(), $this->approvedVerdict(), $this->atlasDir);

        $this->assertSame('atlas.hermes.skill_provision_gate.v1', $r['schema_version']);
        $this->assertSame(HermesSkillProvisionGate::SCHEMA_VERSION, $r['schema_version']);
    }

    public function test_blocks_when_promotion_verdict_not_approved(): void
    {
        $r = $this->gate->evaluate($this->record(), ['promotion_decision' => 'promotion_blocked'], $this->atlasDir);

        $this->assertSame('provision_blocked', $r['provision_decision']);
        $this->assertFalse($r['provision_allowed_now']);
        $this->assertArrayHasKey('promotion_gate', $r['failed_checks']);
    }

    public function test_blocks_when_record_promotion_not_allowed(): void
    {
        $r = $this->gate->evaluate($this->record(['promotion_allowed' => false]), $this->approvedVerdict(), $this->atlasDir);

        $this->assertSame('provision_blocked', $r['provision_decision']);
        $this->assertArrayHasKey('promotion_allowed', $r['failed_checks']);
    }

    public function test_blocks_danger_skill_without_operator_authority(): void
    {
        $r = $this->gate->evaluate(
            $this->record(['risk_level' => 'danger']),
            $this->approvedVerdict(),
            $this->atlasDir,
        );

        $this->assertSame('provision_blocked', $r['provision_decision']);
        $this->assertArrayHasKey('operator_authority_required', $r['failed_checks']);
    }

    public function test_approves_danger_skill_with_operator_authority(): void
    {
        $r = $this->gate->evaluate(
            $this->record(['risk_level' => 'danger', 'operator_authority_required' => true]),
            $this->approvedVerdict(),
            $this->atlasDir,
        );

        $this->assertSame('provision_approved', $r['provision_decision']);
        $this->assertContains('operator_authority_present', $r['passed_checks']);
    }

    public function test_blocks_when_external_dir_not_atlas_owned(): void
    {
        $hermesManaged = rtrim((string) getenv('HOME'), '/').'/.hermes/skills/programming/repair-orchestrator';

        $r = $this->gate->evaluate($this->record(), $this->approvedVerdict(), $hermesManaged);

        $this->assertSame('provision_blocked', $r['provision_decision']);
        $this->assertArrayHasKey('external_dir', $r['failed_checks']);
    }

    public function test_hermes_managed_registry_is_never_atlas_owned(): void
    {
        $this->assertFalse($this->gate->isAtlasOwned('/Users/operator/.hermes/skills'));
        $this->assertFalse($this->gate->isAtlasOwned('/Users/operator/.hermes/skills/programming/x'));
        $this->assertFalse($this->gate->isAtlasOwned(''));
        $this->assertTrue($this->gate->isAtlasOwned($this->atlasDir));
    }
}
