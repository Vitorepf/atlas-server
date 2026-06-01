<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasAiHarnessGovernanceAndQualityService;
use Tests\TestCase;

/**
 * Pins the documented AI-harness admission rules for one Obra AI action.
 *
 * @see docs/engineering-knowledge-base/obras/ai-harness-governance-and-quality.md
 */
class AtlasAiHarnessGovernanceAndQualityTest extends TestCase
{
    private function service(): AtlasAiHarnessGovernanceAndQualityService
    {
        return new AtlasAiHarnessGovernanceAndQualityService();
    }

    /**
     * A complete envelope with no checkpoint trigger, a single provider, a
     * non-workspace task and a compliant provider is admitted to run.
     *
     * @return array<string,mixed>
     */
    private function compliantEnvelope(): array
    {
        return [
            'obra_id' => 'obra-1',
            'domain' => 'technical',
            'section' => 'node-a',
            'task_type' => 'writing',
            'sources' => ['src-1'],
            'decisions' => [],
            'deadline' => '2026-12-31',
            'quality_gate' => 'definition_of_done',
            'risk' => 'normal',
        ];
    }

    /** Complete envelope, compliant context => admit + mayProceed true. */
    public function test_complete_envelope_is_admitted(): void
    {
        $d = $this->service()->decide([
            'envelope' => $this->compliantEnvelope(),
            'provider' => ['model' => 'remote-model', 'is_local' => false],
            'providers' => ['claude'],
        ]);

        $this->assertSame(AtlasAiHarnessGovernanceAndQualityService::OUTCOME_ADMIT, $d['outcome']);
        $this->assertTrue($d['envelope_complete']);
        $this->assertFalse($d['is_loose_chat']);
        $this->assertSame([], $d['missing_envelope_fields']);
        $this->assertContains('admitted_under_harness', $d['reasons']);
        $this->assertTrue($this->service()->mayProceed([
            'envelope' => $this->compliantEnvelope(),
            'provider' => ['model' => 'remote-model', 'is_local' => false],
            'providers' => ['claude'],
        ]));
    }

    /**
     * Loose-chat guard: a missing required envelope field (the doc: "must not
     * treat ... as loose chat") forces `clarify` and names the missing field.
     */
    public function test_incomplete_envelope_is_loose_chat_and_clarifies(): void
    {
        $envelope = $this->compliantEnvelope();
        unset($envelope['deadline'], $envelope['quality_gate']);

        $d = $this->service()->decide(['envelope' => $envelope]);

        $this->assertSame(AtlasAiHarnessGovernanceAndQualityService::OUTCOME_CLARIFY, $d['outcome']);
        $this->assertTrue($d['is_loose_chat']);
        $this->assertSame(['deadline', 'quality_gate'], $d['missing_envelope_fields']);
        $this->assertContains('envelope_incomplete:deadline,quality_gate', $d['reasons']);
        $this->assertFalse($this->service()->mayProceed(['envelope' => $envelope]));
    }

    /**
     * The doc lists exactly nine required envelope fields. An empty envelope is
     * loose chat and reports all nine as missing in documented order.
     */
    public function test_empty_envelope_reports_all_nine_required_fields(): void
    {
        $d = $this->service()->decide(['envelope' => []]);

        $this->assertSame(
            [
                'obra_id', 'domain', 'section', 'task_type', 'sources',
                'decisions', 'deadline', 'quality_gate', 'risk',
            ],
            $d['missing_envelope_fields'],
        );
        $this->assertCount(9, AtlasAiHarnessGovernanceAndQualityService::ENVELOPE_REQUIRED_FIELDS);
        $this->assertSame(AtlasAiHarnessGovernanceAndQualityService::OUTCOME_CLARIFY, $d['outcome']);
    }

    /**
     * Human checkpoints are required for the documented triggers (e.g. financial
     * action, irreversible change) => `checkpoint`, human_required true.
     */
    public function test_human_checkpoint_trigger_forces_checkpoint(): void
    {
        foreach (['financial_action', 'irreversible_change', 'external_publication', 'scope_change'] as $trigger) {
            $d = $this->service()->decide([
                'envelope' => $this->compliantEnvelope(),
                'checkpoint_flags' => [$trigger],
                'provider' => ['model' => 'remote-model', 'is_local' => false],
            ]);

            $this->assertSame(
                AtlasAiHarnessGovernanceAndQualityService::OUTCOME_CHECKPOINT,
                $d['outcome'],
                "trigger {$trigger} must force a human checkpoint",
            );
            $this->assertTrue($d['human_required']);
            $this->assertContains("human_checkpoint_triggered:{$trigger}", $d['reasons']);
        }
    }

    /** An unrecognised (non-documented) checkpoint flag is ignored, not honoured. */
    public function test_unknown_checkpoint_flag_is_ignored(): void
    {
        $d = $this->service()->decide([
            'envelope' => $this->compliantEnvelope(),
            'checkpoint_flags' => ['make_it_pretty'],
            'provider' => ['model' => 'remote-model', 'is_local' => false],
        ]);

        $this->assertSame(AtlasAiHarnessGovernanceAndQualityService::OUTCOME_ADMIT, $d['outcome']);
        $this->assertSame([], $d['checkpoint_flags']);
    }

    /**
     * Provider policy: sensitive data that may NOT leave local on a non-local
     * model is rejected to a human checkpoint; the same data on a local model
     * is compliant.
     */
    public function test_sensitive_data_requires_local_model(): void
    {
        $envelope = $this->compliantEnvelope();
        $envelope['risk'] = 'sensitive';
        $envelope['data_may_leave_local'] = false;

        $remote = $this->service()->decide([
            'envelope' => $envelope,
            'provider' => ['model' => 'remote-cloud-model', 'is_local' => false],
        ]);
        $this->assertSame(AtlasAiHarnessGovernanceAndQualityService::OUTCOME_CHECKPOINT, $remote['outcome']);
        $this->assertTrue($remote['local_model_required']);
        $this->assertTrue($remote['provider_policy_violation']);
        $this->assertContains('provider_policy_requires_local_model', $remote['reasons']);

        $local = $this->service()->decide([
            'envelope' => $envelope,
            'provider' => ['model' => 'local-7b', 'is_local' => true],
        ]);
        $this->assertSame(AtlasAiHarnessGovernanceAndQualityService::OUTCOME_ADMIT, $local['outcome']);
        $this->assertTrue($local['local_model_required']);
        $this->assertFalse($local['provider_policy_violation']);
    }

    /**
     * Shared Workspace Rule: multi-provider work (and programming/long work) not
     * yet workspace-bound must route through the Obras Shared Workspace; once
     * bound it is admitted.
     */
    public function test_multi_provider_and_code_work_route_through_workspace(): void
    {
        $multi = $this->service()->decide([
            'envelope' => $this->compliantEnvelope(),
            'provider' => ['model' => 'm', 'is_local' => true],
            'providers' => ['claude', 'codex'],
            'workspace_bound' => false,
        ]);
        $this->assertSame(AtlasAiHarnessGovernanceAndQualityService::OUTCOME_ROUTE_WORKSPACE, $multi['outcome']);
        $this->assertTrue($multi['is_multi_provider']);
        $this->assertContains('multi_provider_requires_shared_workspace', $multi['reasons']);

        $codeEnvelope = $this->compliantEnvelope();
        $codeEnvelope['task_type'] = 'code';
        $code = $this->service()->decide([
            'envelope' => $codeEnvelope,
            'provider' => ['model' => 'm', 'is_local' => true],
            'providers' => ['codex'],
            'workspace_bound' => false,
        ]);
        $this->assertSame(AtlasAiHarnessGovernanceAndQualityService::OUTCOME_ROUTE_WORKSPACE, $code['outcome']);
        $this->assertContains('task_type_requires_shared_workspace:code', $code['reasons']);

        $bound = $this->service()->decide([
            'envelope' => $codeEnvelope,
            'provider' => ['model' => 'm', 'is_local' => true],
            'providers' => ['claude', 'codex'],
            'workspace_bound' => true,
        ]);
        $this->assertSame(AtlasAiHarnessGovernanceAndQualityService::OUTCOME_ADMIT, $bound['outcome']);
    }

    /**
     * Precedence: the loose-chat guard outranks a checkpoint trigger — an
     * incomplete envelope can never be trusted enough to even evaluate the rest,
     * so it clarifies first. And every decision is auditable with a trace.
     */
    public function test_loose_chat_outranks_checkpoint_and_decision_is_auditable(): void
    {
        $envelope = $this->compliantEnvelope();
        unset($envelope['obra_id']);

        $d = $this->service()->decide([
            'envelope' => $envelope,
            'checkpoint_flags' => ['financial_action'],
        ]);

        $this->assertSame(AtlasAiHarnessGovernanceAndQualityService::OUTCOME_CLARIFY, $d['outcome']);
        $this->assertSame('atlas.obras.ai_harness_admission.v1', $d['schema']);
        $this->assertTrue($d['auditable']);
        $this->assertArrayHasKey('trace', $d);
        $this->assertArrayHasKey('governance_reasons', $d['trace']);
    }
}
