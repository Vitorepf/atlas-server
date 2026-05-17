<?php

namespace Tests\Feature\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AtlasSelfConstructionRealProviderSmokeOperatorChecklistService;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class AtlasSelfConstructionRealProviderSmokeOperatorChecklistTest extends TestCase
{
    public function test_checklist_has_all_expected_phases_in_order(): void
    {
        $result = $this->checklist()->build();

        $this->assertSame('available', $result['status']);
        $this->assertSame([
            'before_provider_call',
            'during_provider_call',
            'after_provider_call',
            'evidence_collection',
            'verifier_submission',
            'persistence',
            'audit_rerun',
        ], $result['phase_ids']);
    }

    public function test_each_phase_has_items_with_rule_and_stop_condition(): void
    {
        $result = $this->checklist()->build();

        foreach ((array) $result['phases'] as $phase) {
            $this->assertNotEmpty((array) $phase['items'], "phase {$phase['id']} has no items");
            foreach ((array) $phase['items'] as $item) {
                $this->assertNotSame('', (string) ($item['rule'] ?? ''));
                $this->assertNotSame('', (string) ($item['stop_condition'] ?? ''));
                $this->assertArrayHasKey('id', $item);
                $this->assertArrayHasKey('evidence_field', $item);
            }
        }
    }

    public function test_persistence_phase_demands_explicit_flag(): void
    {
        $result = $this->checklist()->build();
        $persistence = $this->phase($result, 'persistence');

        $stopConditions = array_column((array) $persistence['items'], 'stop_condition');
        $this->assertContains('never_persist_without_explicit_flag', $stopConditions);
        $this->assertArrayHasKey('persist_real_provider_smoke', (array) $persistence['commands']);
        $this->assertStringContainsString('--persist-completion-evidence', (string) $persistence['commands']['persist_real_provider_smoke']);
    }

    public function test_audit_rerun_phase_includes_audit_command(): void
    {
        $result = $this->checklist()->build();
        $auditRerun = $this->phase($result, 'audit_rerun');

        $this->assertArrayHasKey('rerun_completion_audit', (array) $auditRerun['commands']);
        $this->assertStringContainsString('atlas-self-construction-os-completion-audit-status', (string) $auditRerun['commands']['rerun_completion_audit']);
        $this->assertArrayHasKey('refresh_terminal_loop_operational_proof', (array) $auditRerun['commands']);
        $this->assertArrayHasKey('rerun_completion_audit_with_terminal_loop_operational_proof', (array) $auditRerun['commands']);
        $this->assertStringContainsString('terminal-loop-operational-proof-status', (string) $auditRerun['commands']['refresh_terminal_loop_operational_proof']);
        $this->assertStringContainsString('--agent-control-plane-terminal-loop-operational-proof-json=', (string) $auditRerun['commands']['rerun_completion_audit_with_terminal_loop_operational_proof']);
        $this->assertContains('refresh_terminal_loop_operational_proof', array_column((array) $auditRerun['items'], 'id'));
    }

    public function test_checklist_does_not_persist_anything(): void
    {
        Storage::fake('local');

        $this->checklist()->build();

        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_checklist_hash_is_64_hex(): void
    {
        $result = $this->checklist()->build();

        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $result['checklist_hash']);
        $this->assertFalse((bool) $result['persistence_allowed_here']);
    }

    private function checklist(): AtlasSelfConstructionRealProviderSmokeOperatorChecklistService
    {
        return new AtlasSelfConstructionRealProviderSmokeOperatorChecklistService;
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function phase(array $result, string $id): array
    {
        foreach ((array) $result['phases'] as $phase) {
            if (($phase['id'] ?? '') === $id) {
                return (array) $phase;
            }
        }
        $this->fail("Missing phase: {$id}");
    }
}
