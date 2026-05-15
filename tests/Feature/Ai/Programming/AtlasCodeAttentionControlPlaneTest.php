<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Programming;

use App\Models\AtlasProject;
use App\Services\Ai\Programming\AtlasCodeAttentionControlPlaneService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Atlas Code Attention Control Plane v1 · feature tests.
 *
 * Auditam:
 *   - schema canon atlas.code.attention_control_plane.v1
 *   - routing serial: itens derivam apenas de estados que precisam de humano
 *   - allowed_actions canonicos por kind
 *   - dismiss_with_reason invalida o item ate o estado mudar
 *   - pause aciona paused_until e some da fila
 *   - acao fora do vocabulario / fora do allowed_actions retorna 422
 *   - read-model nunca chama provider e nunca promove completion claim
 */
class AtlasCodeAttentionControlPlaneTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureSchema();
    }

    public function test_snapshot_returns_canon_schema_and_empty_queue_when_no_obras(): void
    {
        $payload = app(AtlasCodeAttentionControlPlaneService::class)->snapshot();

        $this->assertSame(
            AtlasCodeAttentionControlPlaneService::SCHEMA_VERSION,
            $payload['schema_version'],
        );
        $this->assertNull($payload['active_focus_item']);
        $this->assertSame([], $payload['queue_items']);
        $this->assertFalse($payload['external_provider_call']);
        $this->assertFalse($payload['provider_tokens_spent']);
        $this->assertFalse($payload['completion_claim_promoted']);
        $this->assertTrue($payload['review_gate_preserved']);
        $this->assertSame('external_rivals_certification', $payload['separated_from']);
    }

    public function test_intake_required_obra_produces_intake_needed_item(): void
    {
        $obra = $this->makeObra();
        $payload = app(AtlasCodeAttentionControlPlaneService::class)->snapshot();

        $focus = $payload['active_focus_item'];
        $this->assertNotNull($focus);
        $this->assertSame((string) $obra->id, $focus['obra_id']);
        $this->assertSame('intake_needed', $focus['kind']);
        $this->assertContains('refine_intake', $focus['allowed_actions']);
        $this->assertContains('open_obra', $focus['allowed_actions']);
        $this->assertTrue($focus['receipt_required']);
    }

    public function test_running_obra_produces_no_item(): void
    {
        $obra = $this->makeObraWithMetadata([
            'latest_atlas_code_forge_work_intake' => [
                'status' => 'ready',
                'objective' => 'X',
                'business_rule' => 'Y',
                'acceptance_criteria' => ['ok'],
            ],
            'latest_atlas_code_forge_fast_path' => [
                'status' => 'queued',
                'spec_hash' => 'a',
                'plan_hash' => 'b',
            ],
            'latest_forge_live_execution' => [
                'status' => 'running',
                'queued_at' => now()->subSeconds(5)->toIso8601String(),
            ],
            'latest_forge_live_execution_async' => [
                'status' => 'running',
            ],
        ]);

        $payload = app(AtlasCodeAttentionControlPlaneService::class)->snapshot();

        $this->assertCount(0, $payload['queue_items'], 'running Obras must not surface attention items');
        $this->assertNotNull($obra);
    }

    public function test_blocked_scope_produces_scope_decision_with_allowed_actions(): void
    {
        $this->makeObraWithMetadata([
            'latest_atlas_code_forge_work_intake' => [
                'status' => 'ready',
                'objective' => 'X',
                'business_rule' => 'Y',
                'acceptance_criteria' => ['ok'],
            ],
            'latest_forge_live_execution' => [
                'status' => 'blocked',
                'remaining_blockers' => ['files_outside_task_contract'],
                'issues' => [
                    ['code' => 'out_of_scope_write', 'path' => 'src/forbidden.ts'],
                ],
            ],
        ]);

        $payload = app(AtlasCodeAttentionControlPlaneService::class)->snapshot();
        $focus = $payload['active_focus_item'];

        $this->assertNotNull($focus);
        $this->assertSame('scope_decision', $focus['kind']);
        $this->assertSame('deny_scope_change', $focus['recommended_action']);
        $this->assertContains('approve_scope_change', $focus['allowed_actions']);
        $this->assertContains('deny_scope_change', $focus['allowed_actions']);
    }

    public function test_waiting_review_produces_review_needed_item(): void
    {
        $this->makeObraWithMetadata([
            'latest_atlas_code_forge_work_intake' => [
                'status' => 'ready',
                'objective' => 'X',
                'business_rule' => 'Y',
                'acceptance_criteria' => ['ok'],
            ],
            'latest_atlas_code_forge_fast_path' => [
                'status' => 'prepared',
                'spec_hash' => 'a',
                'plan_hash' => 'b',
            ],
            'latest_atlas_code_forge_fast_path_run' => [
                'fast_path_run_id' => 'run-1',
                'review_gate' => ['review_required' => true],
            ],
            'latest_forge_live_execution' => [
                'status' => 'passed',
            ],
        ]);

        $payload = app(AtlasCodeAttentionControlPlaneService::class)->snapshot();
        $focus = $payload['active_focus_item'];

        $this->assertNotNull($focus);
        $this->assertSame('review_needed', $focus['kind']);
        $this->assertContains('approve', $focus['allowed_actions']);
        $this->assertContains('request_repair', $focus['allowed_actions']);
        $this->assertContains('reject', $focus['allowed_actions']);
    }

    public function test_dismiss_with_reason_hides_item_until_state_changes(): void
    {
        $obra = $this->makeObra();
        $service = app(AtlasCodeAttentionControlPlaneService::class);
        $first = $service->snapshot();
        $this->assertNotNull($first['active_focus_item']);

        $receipt = $service->recordDecision(
            obra: $obra->fresh(),
            itemKey: $first['active_focus_item']['item_key'],
            action: 'dismiss_with_reason',
            context: ['reason' => 'not now, ja sei o que precisa'],
        );

        $this->assertSame('dismiss_with_reason', $receipt['action']);

        $second = $service->snapshot();
        $this->assertNull($second['active_focus_item']);
        $this->assertSame([], $second['queue_items']);
    }

    public function test_action_outside_allowed_actions_is_rejected(): void
    {
        $obra = $this->makeObra();
        $service = app(AtlasCodeAttentionControlPlaneService::class);
        $snapshot = $service->snapshot();
        $itemKey = $snapshot['active_focus_item']['item_key'];

        $this->expectException(\InvalidArgumentException::class);
        $service->recordDecision(
            obra: $obra->fresh(),
            itemKey: $itemKey,
            // `approve` is not allowed on intake_needed.
            action: 'approve',
        );
    }

    public function test_pause_action_sets_paused_until_and_removes_obra_from_queue(): void
    {
        $obra = $this->makeObraWithMetadata([
            'latest_atlas_code_forge_work_intake' => [
                'status' => 'ready',
                'objective' => 'X',
                'business_rule' => 'Y',
                'acceptance_criteria' => ['ok'],
            ],
            'latest_forge_live_execution' => [
                'status' => 'blocked',
                'remaining_blockers' => ['files_outside_task_contract'],
                'issues' => [
                    ['code' => 'out_of_scope_write', 'path' => 'src/forbidden.ts'],
                ],
            ],
        ]);
        $service = app(AtlasCodeAttentionControlPlaneService::class);
        $first = $service->snapshot();
        $this->assertSame('scope_decision', $first['active_focus_item']['kind']);

        $service->recordDecision(
            obra: $obra->fresh(),
            itemKey: $first['active_focus_item']['item_key'],
            action: 'pause',
            context: ['pause_hours' => 4],
        );

        $fresh = $obra->fresh();
        $this->assertNotNull($fresh->paused_until);
        $this->assertTrue($fresh->paused_until->isFuture());

        $second = $service->snapshot();
        $this->assertNull($second['active_focus_item']);
    }

    public function test_endpoint_returns_snapshot_payload(): void
    {
        $this->makeObra();
        $this->getJson('/atlas-code/attention', $this->headers())
            ->assertOk()
            ->assertJsonPath('schema_version', AtlasCodeAttentionControlPlaneService::SCHEMA_VERSION)
            ->assertJsonPath('external_provider_call', false)
            ->assertJsonPath('completion_claim_promoted', false);
    }

    public function test_decision_endpoint_rejects_action_outside_vocabulary(): void
    {
        $obra = $this->makeObra();
        $snapshot = app(AtlasCodeAttentionControlPlaneService::class)->snapshot();
        $itemKey = $snapshot['active_focus_item']['item_key'];

        $this->postJson(
            '/atlas-code/attention/'.$obra->id.'/decision',
            ['item_key' => $itemKey, 'action' => 'nuke_repo'],
            $this->headers(),
        )->assertStatus(422)
            ->assertJsonPath('error', 'action_outside_canonical_vocabulary');
    }

    public function test_decision_endpoint_rejects_action_not_in_item_allowed_actions(): void
    {
        $obra = $this->makeObra();
        $snapshot = app(AtlasCodeAttentionControlPlaneService::class)->snapshot();
        $itemKey = $snapshot['active_focus_item']['item_key'];

        $this->postJson(
            '/atlas-code/attention/'.$obra->id.'/decision',
            // approve is in the canon vocabulary but not allowed for intake_needed.
            ['item_key' => $itemKey, 'action' => 'approve'],
            $this->headers(),
        )->assertStatus(422)
            ->assertJsonPath('error', 'action_not_allowed_for_item');
    }

    private function makeObra(): AtlasProject
    {
        return AtlasProject::create([
            'id' => (string) Str::uuid(),
            'title' => 'attention test obra',
            'status' => 'active',
            'domain' => 'atlas',
            'goal' => 'attention smoke',
            'desired_outcome' => 'attention smoke',
            'priority' => 'normal',
            'metadata' => ['workspace_path' => '/tmp/atlas-att-test'],
        ]);
    }

    /**
     * @param  array<string,mixed>  $metadata
     */
    private function makeObraWithMetadata(array $metadata): AtlasProject
    {
        return AtlasProject::create([
            'id' => (string) Str::uuid(),
            'title' => 'attention test obra',
            'status' => 'active',
            'domain' => 'atlas',
            'goal' => 'attention smoke',
            'desired_outcome' => 'attention smoke',
            'priority' => 'normal',
            'metadata' => array_merge(['workspace_path' => '/tmp/atlas-att-test'], $metadata),
        ]);
    }

    /**
     * @return array<string,string>
     */
    private function headers(): array
    {
        return ['X-Atlas-Token' => 'testing-atlas-token-with-enough-length'];
    }

    private function ensureSchema(): void
    {
        if (! Schema::hasTable('atlas_projects')) {
            Schema::create('atlas_projects', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->string('title')->nullable();
                $t->text('description')->nullable();
                $t->string('status')->default('active');
                $t->string('domain')->default('atlas');
                $t->uuid('source_capture_id')->nullable();
                $t->text('goal')->nullable();
                $t->text('next_action')->nullable();
                $t->string('project_type')->nullable();
                $t->text('desired_outcome')->nullable();
                $t->text('minimum_viable_outcome')->nullable();
                $t->text('definition_of_done')->nullable();
                $t->text('why_now')->nullable();
                $t->timestamp('deadline_at')->nullable();
                $t->string('deadline_kind')->nullable();
                $t->string('priority')->default('normal');
                $t->string('energy_profile')->nullable();
                $t->text('avoidance_reason')->nullable();
                $t->uuid('active_next_task_id')->nullable();
                $t->uuid('current_step_id')->nullable();
                $t->timestamp('last_touched_at')->nullable();
                $t->timestamp('next_review_at')->nullable();
                $t->timestamp('completed_at')->nullable();
                $t->timestamp('paused_until')->nullable();
                $t->json('metadata')->nullable();
                $t->timestamps();
                $t->softDeletes();
            });
        }
    }
}
