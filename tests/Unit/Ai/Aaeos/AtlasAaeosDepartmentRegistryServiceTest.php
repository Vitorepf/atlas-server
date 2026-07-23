<?php

namespace Tests\Unit\Ai\Aaeos;

use App\Services\Ai\AgenticEngineeringOs\Maturity\AtlasDepartmentRegistryService;
use Tests\TestCase;

class AtlasAaeosDepartmentRegistryServiceTest extends TestCase
{
    public function test_complete_department_contract_is_valid(): void
    {
        $result = $this->service()->validateDepartment($this->department('dev', 'review'));

        $this->assertTrue($result['valid']);
        $this->assertSame([], $result['blockers']);
    }

    public function test_missing_field_is_a_blocker(): void
    {
        $contract = $this->department('dev', 'review');
        unset($contract['gates']);

        $result = $this->service()->validateDepartment($contract);

        $this->assertFalse($result['valid']);
        $this->assertContains('missing required field [gates]', $result['blockers']);
    }

    public function test_invalid_maturity_and_self_escalation_are_blockers(): void
    {
        $contract = $this->department('dev', 'dev'); // escalates to itself
        $contract['maturity_level'] = 'L9';

        $result = $this->service()->validateDepartment($contract);

        $this->assertFalse($result['valid']);
        $this->assertContains('maturity_level [L9] is not in L0..L7', $result['blockers']);
        $this->assertContains('escalation_to must not point to the department itself', $result['blockers']);
    }

    public function test_unknown_escalation_target_is_a_blocker(): void
    {
        $result = $this->service()->validateDepartment($this->department('dev', 'marketing'));

        $this->assertFalse($result['valid']);
        $this->assertContains('escalation_to [marketing] is not a known department', $result['blockers']);
    }

    public function test_registry_detects_duplicate_ids(): void
    {
        $result = $this->service()->validateRegistry([
            $this->department('dev', 'review'),
            $this->department('dev', 'architect'),
        ]);

        $this->assertFalse($result['valid']);
        $this->assertContains('dev', $result['duplicate_ids']);
    }

    public function test_registry_detects_escalation_cycle(): void
    {
        $result = $this->service()->validateRegistry([
            $this->department('dev', 'review'),
            $this->department('review', 'dev'),
        ]);

        $this->assertFalse($result['valid']);
        $this->assertNotSame([], $result['escalation_cycles']);
    }

    /**
     * @return array<string,mixed>
     */
    private function department(string $id, string $escalation): array
    {
        return [
            'id' => $id,
            'human_name' => ucfirst($id),
            'scope' => 'scope',
            'triggers' => ['t'],
            'inputs' => ['i'],
            'outputs' => ['o'],
            'gates' => ['g'],
            'allowed_actions' => ['a'],
            'forbidden_actions' => ['f'],
            'escalation_to' => $escalation,
            'evidence_required' => ['e'],
            'maturity_level' => 'L2',
        ];
    }

    private function service(): AtlasDepartmentRegistryService
    {
        return new AtlasDepartmentRegistryService;
    }
}
