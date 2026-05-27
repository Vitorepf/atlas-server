<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\ProductMode;

use App\Services\Ai\SoftwareCompanyStewardship\ProductMode\ProductModeOperationalInboxReadModelService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * AP-786 visibility: the Operational Inbox must surface real autonomous-evolution
 * cycles (completed → reviewable, blocked-fake → alert) with branch, sandbox,
 * owner-flow stages, changed files, validation, merge governance, rollback,
 * next action and anti-fake proof.
 */
final class ProductModeOperationalInboxReadModelAp786Test extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir().'/atlas_op_inbox_ap786_'.uniqid('', true);
        File::ensureDirectoryExists($this->tmp);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tmp);
        parent::tearDown();
    }

    private function project(array $sessions): array
    {
        $service = app(ProductModeOperationalInboxReadModelService::class);
        $service->setStorageRootForTesting($this->tmp);

        return $service->project('agentic_engineering_os', 'atlas_software_company', [
            'repo_root' => base_path(),
            'autonomous_evolution_sessions' => $sessions,
        ]);
    }

    /**
     * @param  list<array<string,mixed>>  $items
     * @return array<string,mixed>|null
     */
    private function firstOfKind(array $items, string $kind): ?array
    {
        foreach ($items as $item) {
            if ((string) ($item['kind'] ?? '') === $kind) {
                return $item;
            }
        }

        return null;
    }

    private function completedSession(): array
    {
        return [
            'schema_version' => 'atlas.software_company_stewardship.autonomous_evolution_session_record.v1',
            'session_id' => 'aes_completed_1',
            'area_id' => 'agentic_engineering_os',
            'status' => 'partial',
            'claim_policy' => [
                'direct_provider_driver_allowed' => false,
                'requires_robust_obra_forge_quality_flow' => true,
            ],
            'cycles' => [[
                'cycle_id' => 'aesc_done_1',
                'cycle_index' => 0,
                'final_status' => 'cycle_completed_waiting_review_or_merge',
                'selected_finding' => [
                    'finding_id' => 'aff_001',
                    'title' => 'Harden AreaFocus dev/forge release gate',
                    'kind' => 'bugfix',
                    'severity' => 'high',
                    'why_it_matters' => 'A weak release gate lets unproven changes reach main.',
                ],
                'owner' => 'atlas_dev',
                'sandbox_id' => 'sbx_abc123',
                'branch_ref' => 'atlas/ae/aesc_done_1',
                'worktree_path' => '/tmp/wt/aesc_done_1',
                'changed_files' => ['app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/X.php'],
                'validation' => ['status' => 'passed', 'passed' => true, 'commands' => ['php artisan test tests/Unit/.../AutonomousEvolutionSessionServiceTest.php']],
                'result_bridge_id' => 'rb_001',
                'inbox_item_id' => 'inbox_001',
                'inbox_emitted_before_merge_attempt' => true,
                'merge_governance' => ['status' => 'waiting_operator_review'],
                'merge_performed' => false,
                'branch_created' => true,
                'worktree_created' => true,
                'blockers' => ['merge_not_performed'],
            ]],
        ];
    }

    private function blockedFakeSession(): array
    {
        return [
            'schema_version' => 'atlas.software_company_stewardship.autonomous_evolution_session_record.v1',
            'session_id' => 'aes_blocked_1',
            'area_id' => 'agentic_engineering_os',
            'status' => 'blocked',
            'claim_policy' => [
                'direct_provider_driver_allowed' => false,
                'requires_robust_obra_forge_quality_flow' => true,
            ],
            'cycles' => [[
                'cycle_id' => 'aesc_blocked_1',
                'cycle_index' => 0,
                'final_status' => 'blocked',
                'selected_finding' => [
                    'finding_id' => 'aff_002',
                    'title' => 'Improve Forge queue throughput',
                    'kind' => 'code',
                    'severity' => 'medium',
                    'why_it_matters' => 'Slow queue throttles the factory.',
                ],
                'owner' => 'forge',
                'flow_integrity_gate' => [
                    'schema_version' => 'atlas.software_company_stewardship.ap786_flow_integrity_gate.v1',
                    'ok' => false,
                    'blocked_reason' => 'full_atlas_forge_flow_required',
                    'direct_provider_driver_allowed' => false,
                    'required_chain' => ['AP-747', 'AP-756', 'AP-757', 'AP-749', 'AP-758', 'AP-759', 'AP-750'],
                ],
                'blockers' => ['full_atlas_forge_flow_required'],
            ]],
        ];
    }

    public function test_completed_cycle_surfaces_as_reviewable_item_with_full_fields(): void
    {
        $payload = $this->project([$this->completedSession()]);

        $this->assertContains('AP-786', $payload['source_ap_contracts']);
        $item = $this->firstOfKind($payload['items'], 'autonomous_cycle_review_required');
        $this->assertNotNull($item, 'completed cycle must surface as a reviewable inbox item');
        $this->assertSame('approval', $item['bucket']);
        $this->assertSame('executed', $item['cycle_state']);
        $this->assertSame('AP-786', $item['source_ap_contract']);

        $c = $item['payload']['ap786_cycle'];
        $this->assertSame('Harden AreaFocus dev/forge release gate', $c['selected_finding']['title']);
        $this->assertSame('A weak release gate lets unproven changes reach main.', $c['why_this_matters']);
        $this->assertSame('atlas/ae/aesc_done_1', $c['branch_ref']);
        $this->assertSame('sbx_abc123', $c['sandbox_id']);
        $this->assertNotSame('', $c['worktree_hash']);
        $this->assertCount(7, $c['owner_flow_stages']);
        $this->assertSame('owned', $c['owner_flow_stages'][0]['status']);
        $this->assertSame('AP-747', $c['owner_flow_stages'][0]['ap']);
        $this->assertContains('app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/X.php', $c['changed_files']);
        $this->assertTrue($c['tests_validation']['passed']);
        $this->assertSame('waiting_operator_review', $c['merge_governance_status']);
        $this->assertFalse($c['merged_to_main']);
        $this->assertStringContainsString('git branch -D atlas/ae/aesc_done_1', $c['rollback_instruction']);
        $this->assertStringContainsString('approve', $c['next_operator_action']);
        $this->assertSame('rb_001', $c['evidence']['result_bridge_id']);

        $this->assertFalse($c['anti_fake_proof']['direct_provider_driver_allowed']);
        $this->assertTrue($c['anti_fake_proof']['full_owner_flow']);
        $this->assertTrue($c['anti_fake_proof']['robust_contract']);
    }

    public function test_blocked_fake_cycle_surfaces_as_alert(): void
    {
        $payload = $this->project([$this->blockedFakeSession()]);

        $item = $this->firstOfKind($payload['items'], 'autonomous_cycle_blocked_fake_flow');
        $this->assertNotNull($item, 'blocked fake cycle must surface as an alert');
        $this->assertSame('alert', $item['bucket']);
        $this->assertSame('blocked', $item['cycle_state']);

        $c = $item['payload']['ap786_cycle'];
        $this->assertContains('full_atlas_forge_flow_required', $c['blockers']);
        $this->assertSame('not_evaluated_blocked', $c['merge_governance_status']);
        $this->assertFalse($c['merged_to_main']);
        $this->assertStringContainsString('Nothing to roll back', $c['rollback_instruction']);
        $this->assertStringContainsString('--allow-direct-provider-driver', $c['next_operator_action']);

        // Owner-flow stages are present but not satisfied.
        $this->assertCount(7, $c['owner_flow_stages']);
        $this->assertSame('required_not_satisfied', $c['owner_flow_stages'][0]['status']);

        // Anti-fake proof: the cycle did NOT have full owner flow.
        $this->assertFalse($c['anti_fake_proof']['full_owner_flow']);
        $this->assertFalse($c['anti_fake_proof']['robust_contract']);
        $this->assertFalse($c['anti_fake_proof']['direct_provider_driver_allowed']);

        // It must count as an alert in the inbox counters.
        $this->assertGreaterThanOrEqual(1, $payload['counters']['alerts']);
        $this->assertGreaterThanOrEqual(1, $payload['counters']['blocked']);
    }

    public function test_dry_run_cycles_are_not_surfaced(): void
    {
        $session = $this->completedSession();
        $session['cycles'][0]['final_status'] = 'dry_run_planned';

        $payload = $this->project([$session]);

        $this->assertNull($this->firstOfKind($payload['items'], 'autonomous_cycle_review_required'));
        $this->assertNull($this->firstOfKind($payload['items'], 'autonomous_cycle_merged'));
    }
}
