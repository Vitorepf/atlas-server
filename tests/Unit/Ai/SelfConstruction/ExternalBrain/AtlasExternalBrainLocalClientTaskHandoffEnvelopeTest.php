<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainLocalClientTaskHandoffEnvelope;
use Tests\TestCase;

final class AtlasExternalBrainLocalClientTaskHandoffEnvelopeTest extends TestCase
{
    private AtlasExternalBrainLocalClientTaskHandoffEnvelope $envelope;

    protected function setUp(): void
    {
        parent::setUp();
        $this->envelope = new AtlasExternalBrainLocalClientTaskHandoffEnvelope();
    }

    private function input(array $overrides = []): array
    {
        return array_merge([
            'task_packet_id' => 'task-001',
            'objective' => 'Build a feature',
            'allowed_files' => ['app/Foo.php'],
            'acceptance_criteria' => ['feature works'],
            'required_evidence' => ['phpunit exits 0'],
            'workspace_root' => '/workspace',
            'model_hint' => 'gpt-4',
            'local_client_id' => 'cursor',
            'context' => 'Some context',
            'paid_api_required' => false,
            'requires_direct_git_commit_rights' => false,
            'requires_direct_atlas_task_report_rights' => false,
        ], $overrides);
    }

    // ── Schema ───────────────────────────────────────────────────────────────────

    public function test_schema_constant(): void
    {
        $this->assertSame('atlas.external_brain.local_client_task_handoff_envelope.v1', AtlasExternalBrainLocalClientTaskHandoffEnvelope::SCHEMA);
    }

    // ── Happy path: handoff allowed ───────────────────────────────────────────────

    public function test_handoff_allowed_when_no_blockers(): void
    {
        $result = $this->envelope->build($this->input());
        $this->assertTrue($result['handoff_allowed']);
        $this->assertSame([], $result['blockers']);
        $this->assertSame(0, $result['blocker_count']);
    }

    public function test_envelope_contains_all_fields(): void
    {
        $result = $this->envelope->build($this->input());
        $env = $result['envelope'];
        $this->assertSame('task-001', $env['task_packet_id']);
        $this->assertSame('Build a feature', $env['objective']);
        $this->assertSame(['app/Foo.php'], $env['allowed_files']);
        $this->assertSame('/workspace', $env['workspace_root']);
        $this->assertSame('cursor', $env['local_client_id']);
    }

    public function test_stop_conditions_are_default(): void
    {
        $result = $this->envelope->build($this->input());
        $this->assertContains('stop_if_file_outside_allowed_files_modified', $result['envelope']['stop_conditions']);
        $this->assertContains('stop_if_git_command_attempted', $result['envelope']['stop_conditions']);
        $this->assertContains('stop_if_raw_secret_requested_or_emitted', $result['envelope']['stop_conditions']);
        $this->assertContains('stop_if_paid_api_call_attempted', $result['envelope']['stop_conditions']);
        $this->assertContains('stop_on_timeout', $result['envelope']['stop_conditions']);
    }

    // ── Always-on guarantees ─────────────────────────────────────────────────────

    public function test_plan_only_is_always_true(): void
    {
        $result = $this->envelope->build($this->input());
        $this->assertTrue($result['plan_only']);
        $this->assertTrue($result['Atlas_remains_commit_owner']);
        $this->assertFalse($result['provider_call_allowed']);
        $this->assertFalse($result['token_spend_allowed']);
        $this->assertFalse($result['paid_api_allowed']);
    }

    // ── Blocker: raw secret detected ─────────────────────────────────────────────

    public function test_blocker_when_objective_contains_secret(): void
    {
        $result = $this->envelope->build($this->input(['objective' => 'Use key sk-1234567890abcdef to authenticate']));
        $this->assertFalse($result['handoff_allowed']);
        $this->assertContains('raw_secret_detected', $result['blockers']);
    }

    public function test_blocker_when_context_contains_secret(): void
    {
        $result = $this->envelope->build($this->input(['context' => 'Token is ghp_12345678901234567890']));
        $this->assertFalse($result['handoff_allowed']);
        $this->assertContains('raw_secret_detected', $result['blockers']);
    }

    public function test_blocker_when_context_contains_aws_key(): void
    {
        $result = $this->envelope->build($this->input(['context' => 'AKIA12345678901234']));
        $this->assertFalse($result['handoff_allowed']);
        $this->assertContains('raw_secret_detected', $result['blockers']);
    }

    // ── Blocker: paid API required ────────────────────────────────────────────────

    public function test_blocker_when_paid_api_required(): void
    {
        $result = $this->envelope->build($this->input(['paid_api_required' => true]));
        $this->assertFalse($result['handoff_allowed']);
        $this->assertContains('paid_api_required_true', $result['blockers']);
    }

    // ── Blocker: missing allowed files ───────────────────────────────────────────

    public function test_blocker_when_allowed_files_empty(): void
    {
        $result = $this->envelope->build($this->input(['allowed_files' => []]));
        $this->assertFalse($result['handoff_allowed']);
        $this->assertContains('missing_allowed_files', $result['blockers']);
    }

    // ── Blocker: missing runnable evidence ───────────────────────────────────────

    public function test_blocker_when_required_evidence_empty(): void
    {
        $result = $this->envelope->build($this->input(['required_evidence' => []]));
        $this->assertFalse($result['handoff_allowed']);
        $this->assertContains('missing_runnable_evidence', $result['blockers']);
    }

    public function test_blocker_when_evidence_has_no_runnable_marker(): void
    {
        $result = $this->envelope->build($this->input(['required_evidence' => ['visual inspection']]));
        $this->assertFalse($result['handoff_allowed']);
        $this->assertContains('missing_runnable_evidence', $result['blockers']);
    }

    public function test_no_blocker_when_evidence_has_phpunit_marker(): void
    {
        $result = $this->envelope->build($this->input(['required_evidence' => ['phpunit exits 0']]));
        $this->assertTrue($result['handoff_allowed']);
    }

    // ── Blocker: direct git/report authority ─────────────────────────────────────

    public function test_blocker_when_git_rights_requested(): void
    {
        $result = $this->envelope->build($this->input(['requires_direct_git_commit_rights' => true]));
        $this->assertFalse($result['handoff_allowed']);
        $this->assertContains('direct_git_or_report_authority_requested', $result['blockers']);
    }

    public function test_blocker_when_report_rights_requested(): void
    {
        $result = $this->envelope->build($this->input(['requires_direct_atlas_task_report_rights' => true]));
        $this->assertFalse($result['handoff_allowed']);
        $this->assertContains('direct_git_or_report_authority_requested', $result['blockers']);
    }

    // ── Multiple blockers ────────────────────────────────────────────────────────

    public function test_multiple_blockers_accumulate(): void
    {
        $result = $this->envelope->build($this->input([
            'paid_api_required' => true,
            'allowed_files' => [],
            'requires_direct_git_commit_rights' => true,
        ]));
        $this->assertFalse($result['handoff_allowed']);
        $this->assertContains('paid_api_required_true', $result['blockers']);
        $this->assertContains('missing_allowed_files', $result['blockers']);
        $this->assertContains('direct_git_or_report_authority_requested', $result['blockers']);
    }

    // ── Determinism ──────────────────────────────────────────────────────────────

    public function test_result_is_deterministic(): void
    {
        $input = $this->input();
        $this->assertSame($this->envelope->build($input), $this->envelope->build($input));
    }

    // ── Empty input ──────────────────────────────────────────────────────────────

    public function test_empty_input_produces_blockers(): void
    {
        $result = $this->envelope->build([]);
        $this->assertFalse($result['handoff_allowed']);
        $this->assertContains('missing_allowed_files', $result['blockers']);
        $this->assertContains('missing_runnable_evidence', $result['blockers']);
    }
}
