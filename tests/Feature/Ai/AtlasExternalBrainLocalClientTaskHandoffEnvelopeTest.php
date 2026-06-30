<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainLocalClientTaskHandoffEnvelope;
use Tests\TestCase;

final class AtlasExternalBrainLocalClientTaskHandoffEnvelopeTest extends TestCase
{
    private function validInput(array $overrides = []): array
    {
        return array_merge([
            'task_packet_id' => 'task-1',
            'objective' => 'Implement the Foo service so it does X.',
            'allowed_files' => ['app/Foo.php', 'tests/FooTest.php'],
            'acceptance_criteria' => ['it does X'],
            'required_evidence' => ['php artisan test tests/FooTest.php'],
            'workspace_root' => '/Users/vitorepf/develop/Atlas/atlas-server',
            'model_hint' => 'local-cursor-composer',
            'local_client_id' => 'cursor-local-1',
        ], $overrides);
    }

    public function test_valid_input_builds_handoff_allowed_envelope_with_all_canonical_fields(): void
    {
        $result = (new AtlasExternalBrainLocalClientTaskHandoffEnvelope)->build($this->validInput());

        $this->assertTrue($result['handoff_allowed']);
        $this->assertSame([], $result['blockers']);

        $envelope = $result['envelope'];
        $this->assertSame('task-1', $envelope['task_packet_id']);
        $this->assertSame('Implement the Foo service so it does X.', $envelope['objective']);
        $this->assertSame(['app/Foo.php', 'tests/FooTest.php'], $envelope['allowed_files']);
        $this->assertSame(['it does X'], $envelope['acceptance_criteria']);
        $this->assertSame(['php artisan test tests/FooTest.php'], $envelope['required_evidence']);
        $this->assertSame('/Users/vitorepf/develop/Atlas/atlas-server', $envelope['workspace_root']);
        $this->assertSame('local-cursor-composer', $envelope['model_hint']);
        $this->assertSame('cursor-local-1', $envelope['local_client_id']);
        $this->assertNotEmpty($envelope['stop_conditions']);

        $this->assertTrue($result['plan_only']);
        $this->assertTrue($result['Atlas_remains_commit_owner']);
        $this->assertFalse($result['provider_call_allowed']);
        $this->assertFalse($result['token_spend_allowed']);
        $this->assertFalse($result['paid_api_allowed']);
    }

    public function test_raw_secret_in_objective_blocks_handoff(): void
    {
        $result = (new AtlasExternalBrainLocalClientTaskHandoffEnvelope)->build($this->validInput([
            'objective' => 'Use this key sk-abcdefghijklmnopqrstuvwx to call the API.',
        ]));

        $this->assertFalse($result['handoff_allowed']);
        $this->assertContains('raw_secret_detected', $result['blockers']);
    }

    public function test_raw_secret_in_context_blocks_handoff(): void
    {
        $result = (new AtlasExternalBrainLocalClientTaskHandoffEnvelope)->build($this->validInput([
            'context' => "-----BEGIN RSA PRIVATE KEY-----\nMIIE...\n-----END RSA PRIVATE KEY-----",
        ]));

        $this->assertFalse($result['handoff_allowed']);
        $this->assertContains('raw_secret_detected', $result['blockers']);
    }

    public function test_paid_api_required_blocks_handoff(): void
    {
        $result = (new AtlasExternalBrainLocalClientTaskHandoffEnvelope)->build($this->validInput([
            'paid_api_required' => true,
        ]));

        $this->assertFalse($result['handoff_allowed']);
        $this->assertContains('paid_api_required_true', $result['blockers']);
        $this->assertFalse($result['paid_api_allowed']);
    }

    public function test_missing_allowed_files_blocks_handoff(): void
    {
        $result = (new AtlasExternalBrainLocalClientTaskHandoffEnvelope)->build($this->validInput([
            'allowed_files' => [],
        ]));

        $this->assertFalse($result['handoff_allowed']);
        $this->assertContains('missing_allowed_files', $result['blockers']);
    }

    public function test_missing_runnable_evidence_blocks_handoff(): void
    {
        $result = (new AtlasExternalBrainLocalClientTaskHandoffEnvelope)->build($this->validInput([
            'required_evidence' => ['operator reviewed it'],
        ]));

        $this->assertFalse($result['handoff_allowed']);
        $this->assertContains('missing_runnable_evidence', $result['blockers']);
    }

    public function test_direct_git_commit_rights_request_blocks_handoff(): void
    {
        $result = (new AtlasExternalBrainLocalClientTaskHandoffEnvelope)->build($this->validInput([
            'requires_direct_git_commit_rights' => true,
        ]));

        $this->assertFalse($result['handoff_allowed']);
        $this->assertContains('direct_git_or_report_authority_requested', $result['blockers']);
    }

    public function test_direct_atlas_report_rights_request_blocks_handoff(): void
    {
        $result = (new AtlasExternalBrainLocalClientTaskHandoffEnvelope)->build($this->validInput([
            'requires_direct_atlas_task_report_rights' => true,
        ]));

        $this->assertFalse($result['handoff_allowed']);
        $this->assertContains('direct_git_or_report_authority_requested', $result['blockers']);
    }

    public function test_safety_flags_remain_false_even_when_blocked(): void
    {
        $result = (new AtlasExternalBrainLocalClientTaskHandoffEnvelope)->build($this->validInput([
            'paid_api_required' => true,
            'requires_direct_git_commit_rights' => true,
        ]));

        $this->assertFalse($result['handoff_allowed']);
        $this->assertTrue($result['plan_only']);
        $this->assertTrue($result['Atlas_remains_commit_owner']);
        $this->assertFalse($result['provider_call_allowed']);
        $this->assertFalse($result['token_spend_allowed']);
        $this->assertFalse($result['paid_api_allowed']);
    }
}
