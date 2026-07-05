<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AgentRuntimeRegistryRepository;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class AgentRuntimeRegistryRepositoryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    /**
     * @return array<string, mixed>
     */
    private function validAgent(): array
    {
        return [
            'agent_id' => 'agent-a',
            'kind' => 'dry_run_agent',
            'label' => 'Agent A',
            'status' => 'registered',
            'capabilities' => ['code_edit', 'evidence_collection'],
            'surfaces' => ['atlas-self-construction'],
            'max_parallel_tasks' => 1,
            'current_task_count' => 0,
        ];
    }

    // ── AC2: registered idempotently by stable agent id ────────────────────────

    public function test_register_returns_ok_envelope(): void
    {
        $repo = new AgentRuntimeRegistryRepository;
        $result = $repo->register($this->validAgent());

        $this->assertSame('ok', $result['status']);
        $this->assertSame('registered', $result['event']);
        $this->assertSame('agent-a', $result['agent_id']);
        $this->assertFalse($result['runtime_execution_allowed']);
    }

    public function test_register_is_idempotent_on_identical_payload(): void
    {
        $repo = new AgentRuntimeRegistryRepository;
        $repo->register($this->validAgent());
        $second = $repo->register($this->validAgent());

        $this->assertTrue($second['idempotent']);
        $this->assertSame('idempotent_register', $second['event']);
    }

    public function test_register_treats_capability_change_as_a_real_update_not_idempotent(): void
    {
        $repo = new AgentRuntimeRegistryRepository;
        $repo->register($this->validAgent());

        $changed = $this->validAgent();
        $changed['capabilities'] = ['code_edit', 'docs_writer'];
        $result = $repo->register($changed);

        $this->assertFalse($result['idempotent'] ?? false);
        $this->assertSame(['code_edit', 'docs_writer'], $result['record']['capabilities']);
    }

    // ── AC3: list() filters by status, capability, project_lane, runtime_class ──

    public function test_list_filters_by_project_lane(): void
    {
        $repo = new AgentRuntimeRegistryRepository;
        $repo->register(array_merge($this->validAgent(), ['project_lane' => 'atlas-server']));
        $repo->register(array_merge($this->validAgent(), ['agent_id' => 'agent-b', 'project_lane' => 'other-project']));

        $this->assertCount(1, $repo->list(['project_lane' => 'atlas-server']));
        $this->assertSame('agent-a', $repo->list(['project_lane' => 'atlas-server'])[0]['agent_id']);
    }

    public function test_list_filters_by_runtime_class(): void
    {
        $repo = new AgentRuntimeRegistryRepository;
        $repo->register(array_merge($this->validAgent(), ['runtime_class' => 'native']));
        $repo->register(array_merge($this->validAgent(), ['agent_id' => 'agent-b', 'runtime_class' => 'container']));

        $this->assertCount(1, $repo->list(['runtime_class' => 'native']));
        $this->assertCount(1, $repo->list(['runtime_class' => 'container']));
    }

    public function test_list_filters_by_status_and_capability_still_work(): void
    {
        $repo = new AgentRuntimeRegistryRepository;
        $repo->register($this->validAgent());
        $repo->register(array_merge($this->validAgent(), ['agent_id' => 'agent-b', 'status' => 'available', 'capabilities' => ['docs_writer']]));

        $this->assertCount(1, $repo->list(['status' => 'available']));
        $this->assertCount(1, $repo->list(['capability' => 'docs_writer']));
    }

    public function test_project_lane_and_runtime_class_are_persisted_on_record(): void
    {
        $repo = new AgentRuntimeRegistryRepository;
        $result = $repo->register(array_merge($this->validAgent(), [
            'project_lane' => 'atlas-server',
            'runtime_class' => 'native',
        ]));

        $this->assertSame('atlas-server', $result['record']['project_lane']);
        $this->assertSame('native', $result['record']['runtime_class']);
    }

    // ── AC4: provider-sensitive metadata is redacted, capability facts preserved ──

    public function test_register_redacts_secret_like_metadata_and_reports_redacted_fields(): void
    {
        $repo = new AgentRuntimeRegistryRepository;
        $agent = $this->validAgent();
        $agent['metadata'] = [
            'note' => 'safe value',
            'api_key' => 'sk-secret-value',
            'raw_prompt' => 'the full raw prompt text',
            'auth_token' => 'bearer-xyz',
        ];

        $result = $repo->register($agent);

        $this->assertArrayNotHasKey('api_key', $result['record']['metadata']);
        $this->assertArrayNotHasKey('raw_prompt', $result['record']['metadata']);
        $this->assertArrayNotHasKey('auth_token', $result['record']['metadata']);
        $this->assertSame('safe value', $result['record']['metadata']['note']);
        $this->assertContains('api_key', $result['record']['metadata_redacted_fields']);
        $this->assertContains('raw_prompt', $result['record']['metadata_redacted_fields']);
        $this->assertContains('auth_token', $result['record']['metadata_redacted_fields']);
    }

    public function test_register_preserves_capabilities_and_receipts_while_redacting_metadata(): void
    {
        $repo = new AgentRuntimeRegistryRepository;
        $agent = $this->validAgent();
        $agent['metadata'] = ['secret_key' => 'leaked'];

        $result = $repo->register($agent);

        $this->assertSame(['code_edit', 'evidence_collection'], $result['record']['capabilities']);
        $this->assertSame([], $result['record']['receipts']);
        $this->assertArrayNotHasKey('secret_key', $result['record']['metadata']);
    }

    public function test_register_with_no_sensitive_metadata_reports_empty_redacted_fields(): void
    {
        $repo = new AgentRuntimeRegistryRepository;
        $agent = $this->validAgent();
        $agent['metadata'] = ['note' => 'hello', 'priority' => 'high'];

        $result = $repo->register($agent);

        $this->assertSame([], $result['record']['metadata_redacted_fields']);
        $this->assertSame(['note' => 'hello', 'priority' => 'high'], $result['record']['metadata']);
    }

    public function test_update_status_metadata_merge_also_redacts_sensitive_fields(): void
    {
        $repo = new AgentRuntimeRegistryRepository;
        $repo->register($this->validAgent());

        $result = $repo->updateStatus('agent-a', 'available', ['password' => 'p4ss', 'note' => 'ready']);

        $this->assertArrayNotHasKey('password', $result['record']['metadata']);
        $this->assertSame('ready', $result['record']['metadata']['note']);
        $this->assertContains('password', $result['record']['metadata_redacted_fields']);
    }

    public function test_append_receipt_is_unaffected_by_metadata_redaction(): void
    {
        $repo = new AgentRuntimeRegistryRepository;
        $agent = $this->validAgent();
        $agent['metadata'] = ['api_key' => 'leaked'];
        $repo->register($agent);

        $result = $repo->appendReceipt('agent-a', ['receipt_kind' => 'agent_registered']);

        $this->assertSame('ok', $result['status']);
        $record = $repo->get('agent-a');
        $this->assertCount(1, $record['receipts']);
        $this->assertArrayNotHasKey('api_key', $record['metadata']);
    }

    // ── basic get/unregister sanity (repository correctness) ───────────────────

    public function test_get_returns_registered_record(): void
    {
        $repo = new AgentRuntimeRegistryRepository;
        $repo->register($this->validAgent());

        $record = $repo->get('agent-a');
        $this->assertNotNull($record);
        $this->assertSame('agent-a', $record['agent_id']);
    }

    public function test_unregister_marks_status_unregistered(): void
    {
        $repo = new AgentRuntimeRegistryRepository;
        $repo->register($this->validAgent());
        $result = $repo->unregister('agent-a');

        $this->assertSame('ok', $result['status']);
        $this->assertSame('unregistered', $result['record']['status']);
    }

    // ── AC: appendReceipt updates skill_outcome_profile counts for success, give_back and weak_green outcomes by task_family ──

    public function test_append_receipt_updates_skill_outcome_profile_for_success(): void
    {
        $repo = new AgentRuntimeRegistryRepository;
        $repo->register($this->validAgent());

        $repo->appendReceipt('agent-a', [
            'receipt_kind' => 'task_completed',
            'outcome' => 'success',
            'task_family' => 'implementation',
        ]);

        $record = $repo->get('agent-a');
        $this->assertArrayHasKey('skill_outcome_profile', $record);
        $this->assertArrayHasKey('implementation', $record['skill_outcome_profile']);
        $this->assertSame(1, $record['skill_outcome_profile']['implementation']['success']);
        $this->assertSame(1, $record['skill_outcome_profile']['implementation']['total']);
    }

    public function test_append_receipt_updates_skill_outcome_profile_for_give_back(): void
    {
        $repo = new AgentRuntimeRegistryRepository;
        $repo->register($this->validAgent());

        $repo->appendReceipt('agent-a', [
            'receipt_kind' => 'task_give_back',
            'outcome' => 'give_back',
            'task_family' => 'architecture',
        ]);

        $record = $repo->get('agent-a');
        $this->assertSame(1, $record['skill_outcome_profile']['architecture']['give_back']);
    }

    public function test_append_receipt_updates_skill_outcome_profile_for_weak_green(): void
    {
        $repo = new AgentRuntimeRegistryRepository;
        $repo->register($this->validAgent());

        $repo->appendReceipt('agent-a', [
            'receipt_kind' => 'task_weak_green',
            'outcome' => 'weak_green',
            'task_family' => 'testing',
        ]);

        $record = $repo->get('agent-a');
        $this->assertSame(1, $record['skill_outcome_profile']['testing']['weak_green']);
    }

    // ── AC: the profile is bounded and deterministic when many receipts are appended ──

    public function test_skill_outcome_profile_is_bounded(): void
    {
        $repo = new AgentRuntimeRegistryRepository;
        $repo->register($this->validAgent());

        for ($i = 0; $i < 100; $i++) {
            $repo->appendReceipt('agent-a', [
                'receipt_kind' => 'task_completed',
                'outcome' => 'success',
                'task_family' => "family_{$i}",
            ]);
        }

        $record = $repo->get('agent-a');
        $this->assertLessThanOrEqual(50, count($record['skill_outcome_profile']));
    }

    public function test_skill_outcome_profile_is_deterministic(): void
    {
        $receipts = [
            ['receipt_kind' => 'task_completed', 'outcome' => 'success', 'task_family' => 'impl'],
            ['receipt_kind' => 'task_give_back', 'outcome' => 'give_back', 'task_family' => 'impl'],
            ['receipt_kind' => 'task_completed', 'outcome' => 'success', 'task_family' => 'impl'],
        ];

        $repo1 = new AgentRuntimeRegistryRepository;
        $repo1->register($this->validAgent());
        foreach ($receipts as $r) { $repo1->appendReceipt('agent-a', $r); }
        $record1 = $repo1->get('agent-a');

        Storage::fake('local');
        $repo2 = new AgentRuntimeRegistryRepository;
        $repo2->register($this->validAgent());
        foreach ($receipts as $r) { $repo2->appendReceipt('agent-a', $r); }
        $record2 = $repo2->get('agent-a');

        $this->assertSame(
            $record1['skill_outcome_profile']['impl'],
            $record2['skill_outcome_profile']['impl'],
        );
    }

    // ── AC: registry listings can expose agents by strongest task_family fit without leaking raw receipt payloads ──

    public function test_registry_listing_exposes_skill_outcome_profile_without_raw_receipts(): void
    {
        $repo = new AgentRuntimeRegistryRepository;
        $repo->register($this->validAgent());
        $repo->appendReceipt('agent-a', [
            'receipt_kind' => 'task_completed',
            'outcome' => 'success',
            'task_family' => 'implementation',
            'secret_metadata' => 'should_not_leak',
        ]);

        $record = $repo->get('agent-a');
        $this->assertArrayHasKey('skill_outcome_profile', $record);
        $this->assertArrayHasKey('implementation', $record['skill_outcome_profile']);

        // The profile should only contain counts, not raw receipt data.
        $profile = $record['skill_outcome_profile']['implementation'];
        $this->assertArrayHasKey('success', $profile);
        $this->assertArrayHasKey('give_back', $profile);
        $this->assertArrayHasKey('weak_green', $profile);
        $this->assertArrayHasKey('total', $profile);
        $this->assertArrayNotHasKey('secret_metadata', $profile);
    }
}
