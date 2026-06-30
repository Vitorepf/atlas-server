<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\SelfConstruction;

use App\Console\Commands\AtlasExternalBrainControlPlaneCommand;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class AtlasExternalBrainControlPlaneCommandTest extends TestCase
{
    private function callAction(string $action, array $opts = []): array
    {
        Artisan::call('atlas:external-brain:control-plane', array_merge(['action' => $action], $opts));
        $raw = trim(Artisan::output());
        $decoded = json_decode($raw, true);
        $this->assertIsArray($decoded, "Command output is not valid JSON for action '{$action}':\n{$raw}");

        return $decoded;
    }

    public function test_inspect_action_emits_required_keys(): void
    {
        $result = $this->callAction('inspect');

        $this->assertSame('ok', $result['status']);
        $this->assertSame('inspect', $result['action']);
        $this->assertArrayHasKey('maturity_band', $result);
        $this->assertArrayHasKey('maturity_score', $result);
        $this->assertArrayHasKey('blockers', $result);
        $this->assertArrayHasKey('next_actions', $result);
        $this->assertArrayHasKey('evidence_gaps', $result);
        $this->assertArrayHasKey('dimensions', $result);
        $this->assertIsArray($result['blockers']);
        $this->assertIsArray($result['next_actions']);
    }

    public function test_plan_action_emits_required_keys(): void
    {
        $result = $this->callAction('plan');

        $this->assertSame('ok', $result['status']);
        $this->assertSame('plan', $result['action']);
        $this->assertArrayHasKey('maturity_band', $result);
        $this->assertArrayHasKey('next_actions', $result);
        $this->assertArrayHasKey('next_missing_capabilities', $result);
        $this->assertArrayHasKey('blockers', $result);
        $this->assertIsArray($result['next_actions']);
    }

    public function test_audit_action_emits_required_keys(): void
    {
        $result = $this->callAction('audit');

        $this->assertSame('ok', $result['status']);
        $this->assertSame('audit', $result['action']);
        $this->assertArrayHasKey('maturity_band', $result);
        $this->assertArrayHasKey('audit_verdict', $result);
        $this->assertArrayHasKey('blockers', $result);
        $this->assertArrayHasKey('evidence_gaps', $result);
        $this->assertIsArray($result['blockers']);
    }

    public function test_certify_action_emits_required_keys(): void
    {
        $result = $this->callAction('certify');

        $this->assertArrayHasKey('status', $result);
        $this->assertArrayHasKey('certified', $result);
        $this->assertArrayHasKey('maturity_band', $result);
        $this->assertArrayHasKey('blockers', $result);
        $this->assertArrayHasKey('next_actions', $result);
        $this->assertSame('certify', $result['action']);
        $this->assertContains($result['status'], ['ready', 'not_ready']);
        $this->assertIsBool($result['certified']);
    }

    public function test_missing_ledger_appears_as_blocker_in_inspect(): void
    {
        // Default inputs have no ledger → blocker should appear.
        $result = $this->callAction('inspect');

        $blockerDimensions = array_column($result['blockers'], 'dimension');
        $this->assertContains('ledger', $blockerDimensions);
        $this->assertContains('learning_ledger', $result['evidence_gaps']);
    }

    public function test_stalled_queue_lowers_maturity_in_inspect(): void
    {
        $result = $this->callAction('inspect', ['--queue-status' => 'stalled']);

        $allowedBands = ['bootstrapping', 'emerging'];
        $this->assertContains($result['maturity_band'], $allowedBands);

        $blockerDimensions = array_column($result['blockers'], 'dimension');
        $this->assertContains('queue_health', $blockerDimensions);
    }

    public function test_audit_reject_verdict_propagates_to_audit_action(): void
    {
        $result = $this->callAction('audit', ['--audit-verdict' => 'reject']);

        $this->assertSame('reject', $result['audit_verdict']);
        $blockerDimensions = array_column($result['blockers'], 'dimension');
        $this->assertContains('batch_quality_audit', $blockerDimensions);
    }

    public function test_certify_returns_not_ready_with_missing_ledger(): void
    {
        // Default has no ledger → not ready.
        $result = $this->callAction('certify');

        $this->assertSame('not_ready', $result['status']);
        $this->assertFalse($result['certified']);
    }

    public function test_unknown_action_returns_error_payload(): void
    {
        $result = $this->callAction('unknown-action');

        $this->assertSame('error', $result['status']);
        $this->assertArrayHasKey('valid_actions', $result);
        $this->assertContains('inspect', $result['valid_actions']);
        $this->assertContains('certify', $result['valid_actions']);
    }

    public function test_command_exits_success_for_all_valid_actions(): void
    {
        foreach (['inspect', 'plan', 'audit', 'certify'] as $action) {
            $code = Artisan::call('atlas:external-brain:control-plane', ['action' => $action]);
            $this->assertSame(AtlasExternalBrainControlPlaneCommand::SUCCESS, $code, "action '{$action}' should exit 0");
        }
    }

    public function test_inspect_output_is_not_empty_and_has_maturity_band(): void
    {
        $result = $this->callAction('inspect');

        $this->assertNotEmpty($result['maturity_band']);
        $this->assertContains($result['maturity_band'], [
            'bootstrapping', 'emerging', 'functional', 'advanced', 'autonomous',
        ]);
    }
}
