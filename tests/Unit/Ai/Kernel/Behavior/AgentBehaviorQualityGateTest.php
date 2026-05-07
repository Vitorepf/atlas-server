<?php

namespace Tests\Unit\Ai\Kernel\Behavior;

use App\Services\Ai\Kernel\Behavior\AgentBehaviorQualityGate;
use Tests\TestCase;

class AgentBehaviorQualityGateTest extends TestCase
{
    public function test_emits_verification_missing_finding_for_development_without_verification_signal(): void
    {
        $findings = app(AgentBehaviorQualityGate::class)->evaluate([
            'task_type' => 'dev',
            'response_text' => 'Ajustei o fluxo e deixei a implementacao mais clara.',
        ]);

        $finding = collect($findings)->firstWhere('code', 'agent.verification_missing');

        $this->assertIsArray($finding);
        $this->assertSame('p2', $finding['severity']);
        $this->assertSame('agent_behavior_quality_gate', $finding['source']);
        $this->assertSame('atlas.agent_behavior.finding.v1', data_get($finding, 'metadata.schema_version'));
        $this->assertSame('atlas-ai.agent-behavior.v1', data_get($finding, 'metadata.contract_id'));
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', (string) data_get($finding, 'metadata.contract_hash'));
        $this->assertSame('Verifiable Goal Loop', data_get($finding, 'evidence.required_principle'));
    }

    public function test_does_not_emit_verification_missing_when_verification_is_declared(): void
    {
        $findings = app(AgentBehaviorQualityGate::class)->evaluate([
            'task_type' => 'dev',
            'response_text' => 'Rodei php artisan test e os testes passaram.',
        ]);

        $this->assertNull(collect($findings)->firstWhere('code', 'agent.verification_missing'));
    }

    public function test_emits_unsurgical_diff_finding_when_changed_file_is_outside_allowed_paths(): void
    {
        $findings = app(AgentBehaviorQualityGate::class)->evaluate([
            'task_type' => 'dev',
            'response_text' => 'Rodei php artisan test e os testes passaram.',
            'changed_files' => [
                'app/Services/Ai/Kernel/Provider/AgentBehaviorContract.php',
                'routes/web.php',
            ],
            'allowed_paths' => [
                'app/Services/Ai/Kernel',
                'tests/Unit/Ai',
            ],
        ]);

        $finding = collect($findings)->firstWhere('code', 'agent.unsurgical_diff');

        $this->assertIsArray($finding);
        $this->assertSame('p1', $finding['severity']);
        $this->assertSame(['routes/web.php'], data_get($finding, 'evidence.outside_scope_files'));
        $this->assertSame('Surgical Diff Discipline', data_get($finding, 'evidence.required_principle'));
        $this->assertSame('blocking', data_get($finding, 'metadata.review_signal.status'));
    }
}
